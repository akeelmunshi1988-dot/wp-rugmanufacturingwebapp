<?php
/**
 * Visitor country — ports of backend geo_ip.py, /customer/detect-country
 * and main.py's access_check():
 *
 *  - country_code(): Cloudflare's CF-IPCountry header when present (for the
 *    gate only if DRC_TRUST_CF_IPCOUNTRY is defined), else
 *    ip-api.com (same free service as the original), cached per IP for a
 *    day. A failed lookup returns '' and never blocks anyone.
 *  - GET /customer/detect-country: what currency.js uses to pick a guest's
 *    default display currency.
 *  - India access gate: when india_access_keys is set, visitors from India
 *    need one of those personal tokens — ?key=<token> once, then a 30-day
 *    cookie. Everyone else, staff who are signed in, and the sign-in / admin
 *    screens pass untouched; no keys = no gate. Verified search crawlers
 *    (is_verified_crawler()) pass too — see main.py's _is_verified_crawler_ip.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Geo {

	const COOKIE = 'drc_access';

	// Search crawlers sometimes fetch from IPs that geolocate to India; gating
	// them gets real pages reported as blocked/noindexed in Search Console.
	// Exempted only after each engine's documented reverse+forward DNS check,
	// so a spoofed User-Agent alone can't bypass the gate.
	const CRAWLER_UA = '/googlebot|google-inspectiontool|googleother|adsbot-google|bingbot|bingpreview|duckduckbot|applebot|yandex/i';
	const CRAWLER_HOST_SUFFIXES = array(
		'.googlebot.com', '.google.com', '.googleusercontent.com',
		'.search.msn.com', '.duckduckgo.com', '.applebot.apple.com',
		'.yandex.ru', '.yandex.net', '.yandex.com',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'access_gate' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * @param bool $for_access_control The India gate passes $for_access_control
	 *   = true: CF-IPCountry is then only trusted when the site says it sits
	 *   behind Cloudflare (DRC_TRUST_CF_IPCOUNTRY / the drc_trust_cf_ipcountry
	 *   filter) — otherwise any visitor could send the header themselves.
	 */
	public static function country_code( ?string $ip = null, bool $for_access_control = false ): string {
		$trust_header = ! $for_access_control || (bool) apply_filters( 'drc_trust_cf_ipcountry', defined( 'DRC_TRUST_CF_IPCOUNTRY' ) && DRC_TRUST_CF_IPCOUNTRY );
		if ( $trust_header && ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
			$cf = strtoupper( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) );
			if ( preg_match( '/^[A-Z]{2}$/', $cf ) && 'XX' !== $cf ) {
				return $cf;
			}
		}
		$ip = $ip ?? DRC_Security::client_ip();
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return '';
		}
		$key    = 'drc_geo_' . md5( $ip );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return (string) $cached;
		}
		$code     = '';
		$response = wp_remote_get( 'http://ip-api.com/json/' . rawurlencode( $ip ) . '?fields=status,countryCode', array( 'timeout' => 3 ) );
		if ( ! is_wp_error( $response ) ) {
			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( 'success' === ( $data['status'] ?? '' ) && preg_match( '/^[A-Z]{2}$/', (string) ( $data['countryCode'] ?? '' ) ) ) {
				$code = $data['countryCode'];
			}
		}
		// Failures are cached briefly too, so a flaky API isn't hammered.
		set_transient( $key, $code, $code ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
		return $code;
	}

	public static function register_routes() {
		register_rest_route( 'dreamrugs/v1', '/customer/detect-country', array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$limited = DRC_Security::rate_limit( 'detect_country', array( array( 30, MINUTE_IN_SECONDS ) ) );
				if ( is_wp_error( $limited ) ) {
					return $limited;
				}
				$code      = self::country_code();
				$countries = function_exists( 'WC' ) ? WC()->countries->get_countries() : array();
				return new WP_REST_Response( array(
					'country_code' => $code ?: null,
					'country'      => $code ? html_entity_decode( $countries[ $code ] ?? $code ) : null,
				), 200 );
			},
		) );
	}

	private static function keys(): array {
		return array_values( array_filter( array_map( 'trim', explode( ',', (string) DRC_Settings::get( 'india_access_keys' ) ) ) ) );
	}

	public static function is_verified_crawler( string $ip ): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- regex-tested only.
		if ( ! preg_match( self::CRAWLER_UA, $ua ) || ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return false;
		}
		$key    = 'drc_crawler_' . md5( $ip );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return '1' === $cached;
		}
		$verified = false;
		$host     = strtolower( rtrim( (string) gethostbyaddr( $ip ), '.' ) );
		if ( $host && $host !== $ip ) {
			foreach ( self::CRAWLER_HOST_SUFFIXES as $suffix ) {
				if ( str_ends_with( $host, $suffix ) ) {
					$forward  = filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 )
						? array_column( (array) dns_get_record( $host, DNS_AAAA ), 'ipv6' )
						: (array) gethostbynamel( $host );
					$verified = in_array( $ip, array_map( 'strtolower', $forward ), true );
					break;
				}
			}
		}
		set_transient( $key, $verified ? '1' : '0', DAY_IN_SECONDS );
		return $verified;
	}

	public static function access_gate() {
		$keys = self::keys();
		if ( ! $keys || is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- path compared only.
		if ( false !== strpos( $path, 'wp-login.php' ) || current_user_can( 'edit_posts' ) ) {
			return;
		}
		$cookie = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( '' !== $cookie && in_array( $cookie, $keys, true ) ) {
			return;
		}
		$param = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an access token, not a form.
		if ( '' !== $param && in_array( $param, $keys, true ) ) {
			setcookie( self::COOKIE, $param, array( 'expires' => time() + 30 * DAY_IN_SECONDS, 'path' => COOKIEPATH ?: '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax' ) );
			return;
		}
		if ( 'IN' !== self::country_code( null, true ) ) {
			return;
		}
		if ( self::is_verified_crawler( DRC_Security::client_ip() ) ) {
			return;
		}
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>' . esc_html( get_bloginfo( 'name' ) ) . '</title></head>'
			. '<body style="font-family:system-ui,sans-serif;background:#f7f5f0;color:#20221c;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center">'
			. '<div><h1 style="font-weight:400;font-size:28px">' . esc_html( get_bloginfo( 'name' ) ) . '</h1><p style="opacity:.7">This site isn’t available in your region.</p></div></body></html>';
		exit;
	}
}
