<?php
/**
 * Keeps DRC_Settings' exchange_rates map (used by assets/currency.js's
 * display-only conversion — see that file's header comment) fresh against
 * a live source, instead of staying frozen at whatever an admin last typed
 * in wp-admin. This is a genuinely new capability, not a port: the
 * original app's tenant.exchange_rates was admin-maintained only, with no
 * live-sync equivalent to copy.
 *
 * Source: exchangerate-api.com's "Open Access" endpoint
 * (https://www.exchangerate-api.com/docs/free) — https://open.er-api.com/v6/latest/{base}.
 * Chosen over Frankfurter (ECB rates, free, no key) because Frankfurter's
 * ECB-sourced currency list doesn't include AED/SAR/QAR, three of this
 * storefront's fourteen offered currencies; confirmed live that
 * open.er-api.com's response includes all fourteen. No API key or account
 * required. The provider refreshes once every 24 hours and asks callers
 * not to poll more often than that, which is why this runs on a daily
 * wp-cron schedule rather than per-page-load — a display-currency
 * estimate has no need for finer-grained freshness than that anyway.
 *
 * This is display-only, matching CLAUDE.md/currency.js's existing
 * "customer is always actually charged in the store's real currency"
 * rule — nothing here touches payment amounts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Currency_Sync {

	const CRON_HOOK = 'drc_sync_exchange_rates';

	// Mirrors admin.js's RATE_CURRENCIES / class-rest-routes.php's
	// currency_config() currency list, minus the base currency itself
	// (whose rate against itself is always 1 and isn't stored).
	const SUPPORTED = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SGD', 'CHF', 'SAR', 'QAR', 'JPY', 'NZD', 'ZAR', 'INR' );

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'sync' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Fetches live rates and writes them into DRC_Settings. Safe to call
	 * directly (the "Sync Now" admin button does) as well as from cron.
	 *
	 * @return true|WP_Error
	 */
	public static function sync() {
		$base = get_woocommerce_currency();

		$response = wp_remote_get(
			'https://open.er-api.com/v6/latest/' . rawurlencode( $base ),
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			self::record_failure( $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['rates'] ) || ! is_array( $body['rates'] ) || 'success' !== ( $body['result'] ?? '' ) ) {
			$error = new WP_Error( 'drc_rate_fetch_failed', sprintf( 'Unexpected response from exchange rate provider (HTTP %d).', $code ) );
			self::record_failure( $error->get_error_message() );
			return $error;
		}

		$rates = array();
		foreach ( self::SUPPORTED as $currency ) {
			if ( $currency === $base ) {
				continue; // A currency's rate against itself is always 1 and isn't stored — matches the admin UI's own convention.
			}
			if ( isset( $body['rates'][ $currency ] ) ) {
				$rates[ $currency ] = (float) $body['rates'][ $currency ];
			}
		}

		DRC_Settings::update( array( 'exchange_rates' => $rates ) );
		update_option( 'drc_exchange_rates_synced_at', current_time( 'mysql', true ) );
		delete_option( 'drc_exchange_rates_last_error' );

		return true;
	}

	private static function record_failure( string $message ): void {
		// Deliberately doesn't touch the existing exchange_rates — a failed
		// sync should leave yesterday's (still reasonable) rates in place
		// rather than blank them out.
		update_option( 'drc_exchange_rates_last_error', $message );
	}
}
