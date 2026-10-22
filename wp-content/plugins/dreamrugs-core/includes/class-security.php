<?php
/**
 * Abuse protection for public forms and sign-in — ports of
 * backend/app/core/rate_limit.py and core/bot_protection.py:
 *
 *  - Per-IP rate limits (same buckets and windows as the original), kept
 *    in transients so they hold across PHP workers.
 *  - A hidden "website" honeypot (X-Form-Trap header / field): filled in
 *    only by bots → rejected.
 *  - Cloudflare Turnstile when keys are set in DreamRugs → Settings
 *    (turnstile_site_key / turnstile_secret_key); unset = honeypot only,
 *    the original's safe default. Cloudflare unreachable → let through,
 *    rate limits still apply.
 *  - Sign-in, registration and lost-password are limited too (wp-login,
 *    WooCommerce My Account and the theme's /account page all go through
 *    WordPress's own hooks).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Security {

	const SITEVERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	public static function init() {
		add_filter( 'authenticate', array( __CLASS__, 'limit_login' ), 5, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'count_failed_login' ) );
		add_filter( 'registration_errors', array( __CLASS__, 'limit_registration' ), 5 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'limit_registration' ), 5 );
		add_action( 'lostpassword_post', array( __CLASS__, 'limit_lost_password' ), 5 );
	}

	/** Visitor IP: REMOTE_ADDR, or the proxy's forwarded address when the
	 * direct peer is local (the request really came through nginx), so a
	 * client can't spoof its way past a limit — rate_limit.client_ip(). */
	public static function client_ip(): string {
		$peer = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$local = in_array( $peer, array( '127.0.0.1', '::1', '' ), true ) || 0 === strpos( $peer, '172.' ) || 0 === strpos( $peer, '10.' ) || 0 === strpos( $peer, '192.168.' );
		if ( $local ) {
			foreach ( array( 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR' ) as $header ) {
				if ( ! empty( $_SERVER[ $header ] ) ) {
					$value = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) )[0] );
					if ( filter_var( $value, FILTER_VALIDATE_IP ) ) {
						return $value;
					}
				}
			}
		}
		return $peer ?: 'unknown';
	}

	/**
	 * Counts one hit in each window; WP_Error once any window is full.
	 *
	 * @param array $limits list of [max_hits, seconds]
	 * @return true|WP_Error
	 */
	public static function rate_limit( string $bucket, array $limits ) {
		$ip = self::client_ip();
		foreach ( $limits as $limit ) {
			list( $max, $window ) = $limit;
			$key   = 'drc_rl_' . md5( $bucket . '|' . $window . '|' . $ip );
			$state = get_transient( $key );
			$state = is_array( $state ) ? $state : array( 'n' => 0, 'start' => time() );
			if ( $state['n'] >= $max ) {
				$retry = max( 1, $state['start'] + $window - time() );
				return new WP_Error( 'drc_rate_limited', 'Too many requests — please wait a little and try again.', array( 'status' => 429, 'retry_after' => $retry ) );
			}
		}
		foreach ( $limits as $limit ) {
			list( , $window ) = $limit;
			$key   = 'drc_rl_' . md5( $bucket . '|' . $window . '|' . $ip );
			$state = get_transient( $key );
			$state = is_array( $state ) ? $state : array( 'n' => 0, 'start' => time() );
			++$state['n'];
			set_transient( $key, $state, max( 1, $state['start'] + $window - time() ) );
		}
		return true;
	}

	/** verify_human(): honeypot, then Turnstile when configured. @return true|WP_Error */
	public static function verify_human( ?WP_REST_Request $request = null ) {
		$trap = $request ? (string) ( $request->get_header( 'x_form_trap' ) ?: $request->get_param( 'website' ) ) : ( isset( $_POST['website'] ) ? sanitize_text_field( wp_unslash( $_POST['website'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- honeypot check only.
		if ( '' !== trim( $trap ) ) {
			return new WP_Error( 'drc_rejected', 'Submission rejected.', array( 'status' => 400 ) );
		}
		$secret = (string) DRC_Settings::get( 'turnstile_secret_key' );
		if ( '' === $secret ) {
			return true;
		}
		$token = $request ? (string) ( $request->get_header( 'x_turnstile_token' ) ?: $request->get_param( 'cf-turnstile-response' ) ) : ( isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( '' === trim( $token ) ) {
			return new WP_Error( 'drc_human_check', 'Please complete the verification check and try again.', array( 'status' => 400 ) );
		}
		$response = wp_remote_post( self::SITEVERIFY, array(
			'timeout' => 5,
			'body'    => array( 'secret' => $secret, 'response' => $token, 'remoteip' => self::client_ip() ),
		) );
		if ( is_wp_error( $response ) ) {
			return true; // Cloudflare unreachable — rate limits still apply.
		}
		$result = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $result['success'] ) ) {
			return new WP_Error( 'drc_human_check', 'Verification failed — please refresh the page and try again.', array( 'status' => 400 ) );
		}
		return true;
	}

	/** Rate limit + human check for a public REST route. @return true|WP_Error */
	public static function guard( WP_REST_Request $request, string $bucket, array $limits, bool $human = true ) {
		$limited = self::rate_limit( $bucket, $limits );
		if ( is_wp_error( $limited ) ) {
			return $limited;
		}
		return $human ? self::verify_human( $request ) : true;
	}

	// ── WordPress auth hooks ────────────────────────────────────────────

	/** Blocks sign-in attempts once the IP has had too many failures
	 * (customer_login / staff_login: 10 per 5 min, 50 per day). */
	public static function limit_login( $user, $username, $password ) {
		if ( '' === (string) $username && '' === (string) $password ) {
			return $user;
		}
		foreach ( array( array( 10, 300 ), array( 50, DAY_IN_SECONDS ) ) as $limit ) {
			$state = get_transient( 'drc_rl_' . md5( 'login_fail|' . $limit[1] . '|' . self::client_ip() ) );
			if ( is_array( $state ) && $state['n'] >= $limit[0] ) {
				return new WP_Error( 'drc_rate_limited', '<strong>Too many sign-in attempts.</strong> Please wait a few minutes and try again.' );
			}
		}
		return $user;
	}

	public static function count_failed_login() {
		foreach ( array( 300, DAY_IN_SECONDS ) as $window ) {
			$key   = 'drc_rl_' . md5( 'login_fail|' . $window . '|' . self::client_ip() );
			$state = get_transient( $key );
			$state = is_array( $state ) ? $state : array( 'n' => 0, 'start' => time() );
			++$state['n'];
			set_transient( $key, $state, max( 1, $state['start'] + $window - time() ) );
		}
	}

	public static function limit_registration( $errors ) {
		$limited = self::rate_limit( 'customer_register', array( array( 5, HOUR_IN_SECONDS ), array( 20, DAY_IN_SECONDS ) ) );
		if ( is_wp_error( $limited ) ) {
			$errors->add( 'drc_rate_limited', $limited->get_error_message() );
			return $errors;
		}
		$human = self::verify_human();
		if ( is_wp_error( $human ) ) {
			$errors->add( 'drc_human_check', $human->get_error_message() );
		}
		return $errors;
	}

	public static function limit_lost_password( $errors ) {
		$limited = self::rate_limit( 'forgot_password', array( array( 5, 900 ), array( 20, DAY_IN_SECONDS ) ) );
		if ( is_wp_error( $limited ) && $errors instanceof WP_Error ) {
			$errors->add( 'drc_rate_limited', $limited->get_error_message() );
		}
	}
}
