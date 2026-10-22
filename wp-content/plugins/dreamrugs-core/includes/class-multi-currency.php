<?php
/**
 * Charging in the shopper's chosen currency — the original app froze cart
 * prices in the display currency and charged Razorpay in it
 * (customer.py _convert_priced_items + services/payment_currency.py).
 *
 * Here the storefront's currency switcher (theme assets/currency.js) also
 * writes a `drc_display_currency` cookie. On the cart and checkout (and
 * their AJAX/Store API refreshes) that currency becomes WooCommerce's
 * active currency and every amount the cart produces — DRC-priced lines,
 * plain products, shipping, fixed coupons — is converted with the stored
 * rate (target units per 1 base unit, same as the original). The order is
 * created in that currency, woo-razorpay charges it, and the rate is kept
 * on the order (_drc_fx_rate) so revenue/quote records stay in base.
 *
 * Everywhere else (product pages, admin, existing orders' pay pages) the
 * store stays in its base currency; display conversion there is still
 * currency.js's job.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Multi_Currency {

	const COOKIE = 'drc_display_currency';

	// Same set as payment_currency.py SUPPORTED_PAYMENT_CURRENCIES.
	const SUPPORTED = array( 'INR', 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'AED', 'SGD', 'CHF', 'SAR', 'QAR', 'JPY', 'NZD', 'ZAR' );

	private static $base = null;
	private static $in_filter = false;

	public static function init() {
		add_filter( 'woocommerce_currency', array( __CLASS__, 'filter_currency' ), 999 );
		add_filter( 'woocommerce_coupon_get_amount', array( __CLASS__, 'filter_coupon_amount' ), 10, 2 );
		add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_package_rates' ), 100, 1 );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( __CLASS__, 'filter_amount' ) );
		add_filter( 'woocommerce_coupon_get_maximum_amount', array( __CLASS__, 'filter_amount' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'stamp_order' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( __CLASS__, 'stamp_order' ), 10, 1 );
	}

	/** The store's real currency, unaffected by this class's filter. */
	public static function base(): string {
		if ( null === self::$base ) {
			self::$in_filter = true;
			self::$base      = get_woocommerce_currency();
			self::$in_filter = false;
		}
		return self::$base;
	}

	/** Currency the shopper chose, if it's chargeable and has a rate. */
	public static function selected(): ?string {
		$code = isset( $_COOKIE[ self::COOKIE ] ) ? strtoupper( sanitize_key( wp_unslash( $_COOKIE[ self::COOKIE ] ) ) ) : '';
		if ( ! $code || $code === self::base() || ! in_array( $code, self::SUPPORTED, true ) ) {
			return null;
		}
		$enabled = (array) DRC_Settings::get( 'enabled_currencies' );
		if ( $enabled && ! in_array( $code, $enabled, true ) ) {
			return null;
		}
		return self::rate_for( $code ) > 0 ? $code : null;
	}

	public static function rate_for( string $code ): float {
		if ( $code === self::base() ) {
			return 1.0;
		}
		$rates = (array) DRC_Settings::get( 'exchange_rates' );
		return isset( $rates[ $code ] ) ? (float) $rates[ $code ] : 0.0;
	}

	/** True only while the cart/checkout is being priced for the shopper. */
	public static function active(): bool {
		// WooCommerce's storefront AJAX (cart fragments, update_order_review,
		// checkout) goes through ?wc-ajax=; wp-admin AJAX never converts.
		if ( isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- routing check only.
			return (bool) self::selected();
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! self::selected() ) {
			return false;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false !== strpos( $uri, '/wc/store/' ) ) {
			return true;
		}
		if ( false !== strpos( $uri, '/order-pay/' ) || isset( $_GET['pay_for_order'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return false; // an existing order keeps the currency it was created in.
		}
		if ( ! did_action( 'wp' ) ) {
			// Before the main query is resolved we can't ask is_cart(); the
			// cart/checkout page slugs are the reliable signal this early.
			$cart     = (int) wc_get_page_id( 'cart' );
			$checkout = (int) wc_get_page_id( 'checkout' );
			foreach ( array( $cart, $checkout ) as $page_id ) {
				$path = $page_id > 0 ? (string) wp_parse_url( get_permalink( $page_id ), PHP_URL_PATH ) : '';
				if ( $path && 0 === strpos( wp_parse_url( $uri, PHP_URL_PATH ) ?: '', $path ) ) {
					return true;
				}
			}
			return false;
		}
		return ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) );
	}

	public static function rate(): float {
		return self::active() ? self::rate_for( self::selected() ) : 1.0;
	}

	/** Base-currency amount → the amount to charge right now. */
	public static function convert( float $amount ): float {
		$rate = self::rate();
		if ( 1.0 === $rate ) {
			return $amount;
		}
		$decimals = 'JPY' === self::selected() ? 0 : 2;
		return round( $amount * $rate, $decimals );
	}

	public static function filter_currency( $currency ) {
		if ( self::$in_filter ) {
			return $currency;
		}
		self::$base = self::$base ?? $currency;
		self::$in_filter = true;
		$active = self::active();
		$code   = $active ? self::selected() : null;
		self::$in_filter = false;
		return $code ?: $currency;
	}

	public static function filter_coupon_amount( $amount, $coupon ) {
		if ( $coupon instanceof WC_Coupon && in_array( $coupon->get_discount_type(), array( 'fixed_cart', 'fixed_product' ), true ) ) {
			return self::convert( (float) $amount );
		}
		return $amount;
	}

	/** Every shipping rate the vendor configured in WooCommerce (flat rate
	 * per zone, etc.) is entered in base currency — convert its cost and
	 * taxes. DreamRugs flat shipping converts itself, so it's skipped. */
	public static function filter_package_rates( $rates ) {
		if ( 1.0 === self::rate() ) {
			return $rates;
		}
		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof WC_Shipping_Rate || DRC_Shipping::METHOD_ID === $rate->get_method_id() ) {
				continue;
			}
			$rate->set_cost( self::convert( (float) $rate->get_cost() ) );
			$rate->set_taxes( array_map( function ( $t ) { return self::convert( (float) $t ); }, (array) $rate->get_taxes() ) );
		}
		return $rates;
	}

	public static function filter_amount( $amount ) {
		return '' === $amount || null === $amount ? $amount : self::convert( (float) $amount );
	}

	public static function stamp_order( $order ) {
		if ( ! $order instanceof WC_Order || ! self::active() ) {
			return;
		}
		$order->update_meta_data( '_drc_fx_rate', self::rate() );
		$order->update_meta_data( '_drc_base_currency', self::base() );
	}
}
