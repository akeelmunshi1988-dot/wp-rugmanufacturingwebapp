<?php
/**
 * WooCommerce coupons have an expiry date but no start date; the original
 * promo codes had both (PromoCode.starts_at, enforced in promo_engine.py).
 * Adds a "Starts on" field to the coupon's General tab and rejects the
 * coupon before that date — everywhere WooCommerce validates coupons,
 * including the quote-acceptance path (WC_Order::apply_coupon).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Coupon_Start_Date {

	const META = '_drc_starts_at';

	public static function init() {
		add_action( 'woocommerce_coupon_options', array( __CLASS__, 'field' ), 10, 2 );
		add_action( 'woocommerce_coupon_options_save', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate' ), 10, 2 );
	}

	public static function field( $coupon_id, $coupon ) {
		woocommerce_wp_text_input( array(
			'id'                => 'drc_starts_at',
			'value'             => esc_attr( get_post_meta( $coupon_id, self::META, true ) ),
			'label'             => 'Coupon starts on',
			'placeholder'       => 'YYYY-MM-DD',
			'description'       => 'The coupon can\'t be used before this date (store time). Leave empty to start immediately.',
			'desc_tip'          => true,
			'type'              => 'date',
			'class'             => 'date-picker',
			'custom_attributes' => array( 'pattern' => '[0-9]{4}-[0-9]{2}-[0-9]{2}' ),
		) );
	}

	public static function save( $post_id, $coupon ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the coupon edit nonce before this hook.
		$value = isset( $_POST['drc_starts_at'] ) ? sanitize_text_field( wp_unslash( $_POST['drc_starts_at'] ) ) : '';
		if ( '' === $value || preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			update_post_meta( $post_id, self::META, $value );
		}
	}

	public static function validate( $valid, $coupon ) {
		if ( ! $valid || ! $coupon instanceof WC_Coupon ) {
			return $valid;
		}
		$starts = (string) $coupon->get_meta( self::META );
		if ( $starts && current_time( 'Y-m-d' ) < $starts ) {
			throw new Exception( sprintf( 'Coupon "%s" is not active until %s.', esc_html( $coupon->get_code() ), esc_html( date_i18n( 'j M Y', strtotime( $starts ) ) ) ), 107 );
		}
		return $valid;
	}
}
