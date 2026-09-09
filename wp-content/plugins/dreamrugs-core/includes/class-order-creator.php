<?php
/**
 * Creates a WooCommerce order from an accepted quote. This is the point
 * where the migration plan's "quote-negotiation lifecycle doesn't fit
 * WooCommerce's cart→checkout model" collides with reality: a quote's
 * final_price was computed once (by the vendor, or by the live estimate
 * for a straight product-page purchase) and is the agreed, final, already-
 * inclusive number — CLAUDE.md is explicit that this must never be
 * recomputed or have shipping/discount added on top again, since that's a
 * real historical double-charging bug in the original app. So this
 * deliberately does NOT let WooCommerce (re)calculate anything: it creates
 * the order and calls set_total() directly from the quote's own
 * final_price, the same way the migration plan's data importer is
 * specified to do for historical orders.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Order_Creator {

	/**
	 * $quote is a wp_drc_quotes row (associative array). Returns the new
	 * WC_Order on success, or a WP_Error (never throws) so the REST
	 * handler can turn it into a clean HTTP error response.
	 *
	 * @return WC_Order|WP_Error
	 */
	public static function create_from_quote( array $quote ) {
		if ( ! empty( $quote['wc_order_id'] ) ) {
			return new WP_Error( 'drc_already_ordered', 'An order already exists for this quote.' );
		}
		if ( empty( $quote['final_price'] ) || (float) $quote['final_price'] <= 0 ) {
			return new WP_Error( 'drc_not_priced', 'This quote has no final price set yet.' );
		}

		$order = wc_create_order( array(
			'customer_id' => (int) $quote['customer_user_id'] ?: 0,
			'status'      => 'pending',
		) );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		self::add_line_item( $order, $quote );

		if ( $quote['customer_user_id'] ) {
			$user = get_userdata( (int) $quote['customer_user_id'] );
			if ( $user ) {
				$order->set_billing_email( $user->user_email );
				$order->set_billing_first_name( $user->display_name );
			}
		} elseif ( ! empty( $quote['guest_email'] ) ) {
			$order->set_billing_email( $quote['guest_email'] );
			$order->set_billing_first_name( $quote['guest_name'] ?: '' );
		}

		$order->update_meta_data( '_drc_quote_id', $quote['id'] );
		// Canonical total, set directly on the order (not derived by
		// summing line items, and WooCommerce's calculate_totals() is
		// deliberately never called) — see this file's header comment.
		$order->set_total( (float) $quote['final_price'] );
		$order->save();

		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'drc_quotes',
			array( 'wc_order_id' => $order->get_id() ),
			array( 'id' => $quote['id'] )
		);

		return $order;
	}

	private static function add_line_item( WC_Order $order, array $quote ): void {
		$item = new WC_Order_Item_Product();

		if ( ! empty( $quote['product_id'] ) ) {
			$product = $quote['variation_id']
				? wc_get_product( $quote['variation_id'] )
				: wc_get_product( $quote['product_id'] );
			if ( $product ) {
				$item->set_product( $product );
			}
		}

		if ( ! $item->get_product_id() ) {
			// Bespoke/custom-rug quote with no catalog product — WooCommerce
			// order items still need a name and subtotal even with no
			// underlying WC_Product; this is the "virtual line item" case
			// the migration plan calls out for custom-rug requests.
			$size = $quote['custom_size_w'] && $quote['custom_size_h']
				? sprintf( ' (%.1fm × %.1fm)', $quote['custom_size_w'], $quote['custom_size_h'] )
				: '';
			$item->set_name( 'Custom Rug Request' . $size );
		}

		$item->set_quantity( max( 1, (int) $quote['qty'] ) );
		$item->set_subtotal( (float) $quote['final_price'] );
		$item->set_total( (float) $quote['final_price'] );
		$order->add_item( $item );
	}
}
