<?php
/**
 * The original app added the tenant's flat default_shipping_rate to every
 * direct checkout (customer.py customer_checkout). Here that's a real
 * WooCommerce shipping method — "DreamRugs flat shipping" — reading the
 * same DRC_Settings value, so it shows as the shipping line at checkout,
 * honours free-shipping coupons, and lands on the order like any other
 * method. It's placed in the "Locations not covered by your other zones"
 * zone automatically, so checkout always has a shipping option.
 *
 * Accepted-quote orders are untouched: a quote's final_price already
 * includes any shipping the vendor set (CLAUDE.md money rule).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Shipping {

	const METHOD_ID = 'drc_flat_shipping';

	public static function init() {
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'define_method' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_method' ) );
	}

	public static function define_method() {
		if ( class_exists( 'DRC_Flat_Shipping_Method' ) ) {
			return;
		}

		require_once __DIR__ . '/class-flat-shipping-method.php';
	}

	public static function register_method( array $methods ): array {
		$methods[ self::METHOD_ID ] = 'DRC_Flat_Shipping_Method';
		return $methods;
	}

	/** Adds the method to the catch-all zone (id 0) once, unless the
	 * vendor has already configured that zone with methods of their own. */
	public static function ensure_zone_method() {
		if ( ! class_exists( 'WC_Shipping_Zone' ) ) {
			return;
		}
		$zone    = new WC_Shipping_Zone( 0 );
		$methods = $zone->get_shipping_methods( false );
		foreach ( $methods as $method ) {
			if ( self::METHOD_ID === $method->id ) {
				return;
			}
		}
		if ( ! $methods ) {
			$zone->add_shipping_method( self::METHOD_ID );
		}
	}
}
