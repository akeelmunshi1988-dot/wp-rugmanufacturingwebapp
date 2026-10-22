<?php
/**
 * Customer accounts — the original's auth.py customer flows on top of
 * WordPress users:
 *
 *  - Password policy (validate_password_strength): 8+ chars with upper,
 *    lower, number and symbol, wherever a customer sets a password.
 *  - Email verification: new customer accounts (from /account, WooCommerce
 *    My Account, wp-login or checkout) get a 24-hour link
 *    (customer_verification template) and can't sign in until they use it.
 *    Accounts that existed before this was added count as verified.
 *  - Password reset emails use the editable customer_password_reset /
 *    staff_password_reset templates and land on the branded /account page.
 *  - The /account page's own form posts (sign in, register, forgot, reset,
 *    resend verification) are handled here; page-account.php renders them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Customer_Accounts {

	const POLICY = 'At least 8 characters, with an uppercase letter, a lowercase letter, a number, and a symbol.';
	const META_VERIFIED = 'drc_email_verified';
	const META_TOKEN    = 'drc_email_verify_token';

	public static function init() {
		add_action( 'user_register', array( __CLASS__, 'on_register' ), 20 );
		add_filter( 'authenticate', array( __CLASS__, 'block_unverified' ), 40 );
		add_filter( 'registration_errors', array( __CLASS__, 'policy_on_wp_register' ), 10, 3 );
		add_filter( 'woocommerce_process_registration_errors', array( __CLASS__, 'policy_on_wc_register' ), 10, 4 );
		add_action( 'validate_password_reset', array( __CLASS__, 'policy_on_reset' ), 10, 2 );
		add_filter( 'retrieve_password_notification_email', array( __CLASS__, 'reset_email' ), 10, 4 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_account_page' ), 1 );
		// Logged-out WooCommerce My Account → the branded page.
		add_action( 'template_redirect', array( __CLASS__, 'redirect_my_account' ), 2 );
	}

	public static function policy_error( string $password ): ?string {
		$ok = strlen( $password ) >= 8 && preg_match( '/[A-Z]/', $password ) && preg_match( '/[a-z]/', $password ) && preg_match( '/[0-9]/', $password ) && preg_match( '/[^A-Za-z0-9]/', $password );
		return $ok ? null : self::POLICY;
	}

	public static function policy_on_wp_register( $errors, $login, $email ) {
		// wp-login registration emails a password-set link; nothing to check yet.
		return $errors;
	}

	public static function policy_on_wc_register( $errors, $username, $password, $email ) {
		if ( $password && ( $msg = self::policy_error( (string) $password ) ) ) {
			$errors->add( 'drc_weak_password', $msg );
		}
		return $errors;
	}

	public static function policy_on_reset( $errors, $user ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- core's reset form verifies its key.
		$pass = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
		if ( $pass && $user instanceof WP_User && ! user_can( $user, 'edit_posts' ) && ( $msg = self::policy_error( $pass ) ) ) {
			$errors->add( 'drc_weak_password', $msg );
		}
	}

	private static function requires_verification( WP_User $user ): bool {
		return (bool) DRC_Settings::get( 'require_email_verification' ) && ! user_can( $user, 'edit_posts' );
	}

	public static function on_register( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user || ! self::requires_verification( $user ) ) {
			return;
		}
		update_user_meta( $user_id, self::META_VERIFIED, '0' );
		self::send_verification( $user );
	}

	public static function send_verification( WP_User $user ): void {
		$token = wp_generate_password( 32, false );
		update_user_meta( $user->ID, self::META_TOKEN, array( 'hash' => wp_hash_password( $token ), 'expires' => time() + DAY_IN_SECONDS ) );
		DRC_Email_Templates::send( 'customer_verification', $user->user_email, array(
			'customer_name'     => $user->display_name ?: $user->user_email,
			'verification_link' => add_query_arg( array( 'verify' => $token, 'uid' => $user->ID ), self::account_url() ),
		) );
	}

	public static function is_verified( WP_User $user ): bool {
		return '0' !== (string) get_user_meta( $user->ID, self::META_VERIFIED, true );
	}

	/** Runs after the password check (priority 40). */
	public static function block_unverified( $user ) {
		if ( $user instanceof WP_User && self::requires_verification( $user ) && ! self::is_verified( $user ) ) {
			return new WP_Error( 'drc_unverified', sprintf(
				'Please confirm your email first — we sent a link to %1$s. <a href="%2$s">Send it again</a>.',
				esc_html( $user->user_email ),
				esc_url( add_query_arg( array( 'resend' => $user->ID, '_drcnonce' => wp_create_nonce( 'drc_resend_' . $user->ID ) ), self::account_url() ) )
			) );
		}
		return $user;
	}

	/** Branded reset email (customer_password_reset / staff_password_reset). */
	public static function reset_email( $defaults, $key, $user_login, $user_data ) {
		if ( ! $user_data instanceof WP_User ) {
			return $defaults;
		}
		$staff = user_can( $user_data, 'edit_posts' );
		$link  = $staff
			? network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user_login ), 'login' )
			: add_query_arg( array( 'action' => 'rp', 'key' => $key, 'login' => rawurlencode( $user_login ) ), self::account_url() );
		list( $subject, $text, $html ) = DRC_Email_Templates::render( $staff ? 'staff_password_reset' : 'customer_password_reset', array(
			'customer_name' => $user_data->display_name,
			'staff_name'    => $user_data->display_name,
			'reset_link'    => $link,
		) );
		$defaults['subject'] = $subject;
		$defaults['message'] = '' !== trim( $html ) ? $html : $text;
		if ( '' !== trim( $html ) ) {
			$defaults['headers'] = array( 'Content-Type: text/html; charset=UTF-8' );
		}
		return $defaults;
	}

	public static function account_url(): string {
		$page = get_page_by_path( 'account' );
		return $page ? get_permalink( $page ) : wp_login_url();
	}

	private static function safe_redirect_target( string $fallback ): string {
		// phpcs:ignore WordPress.Security.NonceVerification -- redirect target only, validated below.
		$to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : '';
		// wp_validate_redirect('') returns '' (not the fallback), which would
		// make wp_safe_redirect() a no-op and leave a blank page.
		return '' === $to ? $fallback : wp_validate_redirect( $to, $fallback );
	}

	public static function redirect_my_account() {
		if ( is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! is_account_page() || is_wc_endpoint_url( 'lost-password' ) ) {
			return;
		}
		wp_safe_redirect( self::account_url() );
		exit;
	}

	/** Form posts and links handled on /account. Results go back to the
	 * template via $GLOBALS['drc_account_state']. */
	public static function handle_account_page() {
		if ( ! is_page( 'account' ) ) {
			return;
		}
		$state = array( 'mode' => 'login', 'error' => '', 'notice' => '', 'email' => '' );

		// phpcs:disable WordPress.Security.NonceVerification -- links carry their own token / nonce; posts are checked below.
		if ( isset( $_GET['verify'], $_GET['uid'] ) ) {
			$user  = get_userdata( absint( $_GET['uid'] ) );
			$data  = $user ? get_user_meta( $user->ID, self::META_TOKEN, true ) : null;
			$token = sanitize_text_field( wp_unslash( $_GET['verify'] ) );
			if ( $user && is_array( $data ) && $data['expires'] > time() && wp_check_password( $token, $data['hash'] ) ) {
				update_user_meta( $user->ID, self::META_VERIFIED, '1' );
				delete_user_meta( $user->ID, self::META_TOKEN );
				wp_set_current_user( $user->ID );
				wp_set_auth_cookie( $user->ID, true );
				wp_safe_redirect( add_query_arg( 'verified', '1', home_url( '/my-quotes/' ) ) );
				exit;
			}
			$state['error'] = 'This verification link is invalid or has expired. Sign in to get a new one.';
		} elseif ( isset( $_GET['resend'], $_GET['_drcnonce'] ) ) {
			$user = get_userdata( absint( $_GET['resend'] ) );
			if ( $user && wp_verify_nonce( sanitize_key( $_GET['_drcnonce'] ), 'drc_resend_' . $user->ID ) && ! self::is_verified( $user ) && ! is_wp_error( DRC_Security::rate_limit( 'customer_verify_email', array( array( 10, 900 ) ) ) ) ) {
				self::send_verification( $user );
				$state['notice'] = 'A new verification link is on its way to ' . $user->user_email . '.';
			}
		} elseif ( isset( $_GET['action'] ) && 'rp' === $_GET['action'] ) {
			$state['mode']  = 'reset';
			$state['key']   = sanitize_text_field( wp_unslash( $_GET['key'] ?? '' ) );
			$state['login'] = sanitize_text_field( rawurldecode( wp_unslash( $_GET['login'] ?? '' ) ) );
			$check          = check_password_reset_key( $state['key'], $state['login'] );
			if ( is_wp_error( $check ) ) {
				$state['mode']  = 'forgot';
				$state['error'] = 'This reset link is invalid or has expired — request a new one below.';
			}
		} elseif ( isset( $_GET['mode'] ) && in_array( $_GET['mode'], array( 'register', 'forgot' ), true ) ) {
			$state['mode'] = sanitize_key( $_GET['mode'] );
		}
		// phpcs:enable

		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['drc_account_action'] ) ) {
			$action = sanitize_key( wp_unslash( $_POST['drc_account_action'] ) );
			if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_wpnonce'] ) ), 'drc_account_' . $action ) ) {
				$state['error'] = 'Your session expired — please try again.';
			} else {
				$state = self::process( $action, $state );
			}
		}
		$GLOBALS['drc_account_state'] = $state;
	}

	private static function process( string $action, array $state ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_account_page().
		$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$state['email'] = $email;
		$state['mode']  = in_array( $action, array( 'register', 'forgot', 'reset' ), true ) ? $action : 'login';

		if ( 'login' === $action ) {
			$user = wp_signon( array(
				'user_login'    => $email,
				'user_password' => (string) wp_unslash( $_POST['password'] ?? '' ),
				'remember'      => true,
			), is_ssl() );
			if ( is_wp_error( $user ) ) {
				$state['error'] = 'drc_unverified' === $user->get_error_code() || 'drc_rate_limited' === $user->get_error_code()
					? $user->get_error_message()
					: 'Incorrect email or password.';
				return $state;
			}
			wp_safe_redirect( self::safe_redirect_target( user_can( $user, 'manage_woocommerce' ) ? admin_url() : home_url( '/my-quotes/' ) ) );
			exit;
		}

		if ( 'register' === $action ) {
			$name     = sanitize_text_field( wp_unslash( $_POST['full_name'] ?? '' ) /* 'name' is a WP query var */ );
			$password = (string) wp_unslash( $_POST['password'] ?? '' );
			$country  = sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) );
			$limited  = DRC_Security::rate_limit( 'customer_register', array( array( 5, HOUR_IN_SECONDS ), array( 20, DAY_IN_SECONDS ) ) );
			$human    = DRC_Security::verify_human();
			if ( is_wp_error( $limited ) || is_wp_error( $human ) ) {
				$state['error'] = is_wp_error( $limited ) ? $limited->get_error_message() : $human->get_error_message();
				return $state;
			}
			if ( '' === $name ) {
				$state['error'] = 'Please enter your name.';
			} elseif ( ! is_email( $email ) ) {
				$state['error'] = 'Please enter a valid email address.';
			} elseif ( '' === $country ) {
				$state['error'] = 'Please select your country.';
			} elseif ( $msg = self::policy_error( $password ) ) {
				$state['error'] = $msg;
			} elseif ( email_exists( $email ) ) {
				$state['error'] = 'An account with this email already exists — sign in instead.';
			}
			if ( $state['error'] ) {
				return $state;
			}
			$parts   = preg_split( '/\s+/', $name, 2 );
			$user_id = wp_insert_user( array(
				'user_login'   => function_exists( 'wc_create_new_customer_username' ) ? wc_create_new_customer_username( $email ) : sanitize_user( strstr( $email, '@', true ), true ) . wp_rand( 100, 999 ),
				'user_email'   => $email,
				'user_pass'    => $password,
				'display_name' => $name,
				'first_name'   => $parts[0],
				'last_name'    => $parts[1] ?? '',
				'role'         => 'customer',
			) );
			if ( is_wp_error( $user_id ) ) {
				$state['error'] = $user_id->get_error_message();
				return $state;
			}
			$countries = function_exists( 'WC' ) ? array_flip( WC()->countries->get_countries() ) : array();
			foreach ( array(
				'billing_first_name' => $parts[0],
				'billing_last_name'  => $parts[1] ?? '',
				'billing_email'      => $email,
				'billing_phone'      => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
				'billing_company'    => sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ),
				'billing_country'    => $countries[ $country ] ?? $country,
			) as $k => $v ) {
				update_user_meta( $user_id, $k, $v );
			}
			if ( ! DRC_Settings::get( 'require_email_verification' ) ) {
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id, true );
				wp_safe_redirect( home_url( '/my-quotes/' ) );
				exit;
			}
			$state['mode'] = 'check-email';
			return $state;
		}

		if ( 'forgot' === $action ) {
			// Same reply whether or not the account exists, so the form
			// can't be used to discover registered emails.
			$limited = DRC_Security::rate_limit( 'customer_forgot_password', array( array( 5, 900 ), array( 20, DAY_IN_SECONDS ) ) );
			if ( is_wp_error( $limited ) ) {
				$state['error'] = $limited->get_error_message();
				return $state;
			}
			if ( is_email( $email ) && email_exists( $email ) ) {
				retrieve_password( $email );
			}
			$state['notice'] = 'If an account exists for that email, a reset link is on its way. It expires in 1 hour.';
			return $state;
		}

		if ( 'reset' === $action ) {
			$key   = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
			$login = sanitize_text_field( wp_unslash( $_POST['login'] ?? '' ) );
			$pass  = (string) wp_unslash( $_POST['password'] ?? '' );
			$state['key']   = $key;
			$state['login'] = $login;
			$user  = check_password_reset_key( $key, $login );
			if ( is_wp_error( $user ) ) {
				$state['mode']  = 'forgot';
				$state['error'] = 'This reset link is invalid or has expired — request a new one below.';
			} elseif ( $msg = self::policy_error( $pass ) ) {
				$state['error'] = $msg;
			} elseif ( $pass !== (string) wp_unslash( $_POST['password2'] ?? '' ) ) {
				$state['error'] = 'The two passwords don\'t match.';
			} else {
				reset_password( $user, $pass );
				// A reset proves the inbox, so it also confirms the email.
				update_user_meta( $user->ID, self::META_VERIFIED, '1' );
				$state['mode']   = 'login';
				$state['email']  = $user->user_email;
				$state['notice'] = 'Your password has been changed. Sign in with your new password.';
			}
			return $state;
		}
		// phpcs:enable
		return $state;
	}
}
