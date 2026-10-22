<?php
/**
 * Makes WooCommerce's cart/checkout price match the quote engine's output
 * exactly for the direct "Add to Cart" / "Buy Now" path (a size picked on
 * the product page with an instant estimate, as opposed to a
 * vendor-reviewed quote request). Without this, WooCommerce would just
 * charge the variation's flat base price — silently dropping the rush
 * surcharge and any future bulk-discount/large-format rules the engine
 * applies, which is exactly the class of pricing-fidelity bug the
 * migration plan calls out as the highest risk in this whole port.
 *
 * GST: the engine's final price is already GST-inclusive when
 * gst_inclusive is on, so the line price is correct either way and
 * WooCommerce's own tax engine stays off. Export buyers (billing country
 * outside India) are zero-rated on the quote rows recorded at checkout
 * (DRC_Order_Hooks::record_checkout_quotes) and on their invoices — the
 * charged total is the same, only the tax breakdown differs, exactly as
 * in the original _price_cart_items(is_export=True).
 *
 * Amounts are converted into the shopper's charge currency by
 * DRC_Multi_Currency (a no-op in the store's base currency).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Cart_Pricing {

	public static function init() {
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'add_cart_item_data' ), 10, 3 );
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'recalculate_cart_item_price' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_rush_order' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'display_selected_color' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'persist_rush_order_on_order_item' ), 10, 4 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'persist_selected_color_on_order_item' ), 10, 4 );
	}

	/** Stashes the rush-order flag and/or chosen colorway (see
	 * single-product.php's hidden drc_rush_order/drc_selected_color fields)
	 * from the add-to-cart form onto the cart item, so both survive through
	 * to recalculate_cart_item_price()/display and checkout.
	 *
	 * Deliberately does NOT add an artificial 'unique_key' (an earlier
	 * version of this method did, seeded from microtime()+wp_rand()) — that
	 * was a real bug, not a safety net: WC_Cart::generate_cart_id() already
	 * folds every key WooCommerce's own add-to-cart handler adds here
	 * (including drc_rush_order/drc_selected_color's actual values) into
	 * the cart item's hash, which is exactly what makes two adds of the
	 * *same* size+color+rush combo merge into one line with combined
	 * quantity, and two adds that differ in color/rush stay separate lines
	 * — both already correct with no extra key. A random 'unique_key'
	 * defeated that: every add got a different hash regardless of whether
	 * the configuration matched, so the *same* rug/size/color/rush combo
	 * always duplicated into a new line (with its own qty=1 price) instead
	 * of combining — confirmed live as the reported "add to cart
	 * duplication" bug. */
	public static function add_cart_item_data( array $cart_item_data, int $product_id, int $variation_id ): array {
		$has_rush = ! empty( $_POST['drc_rush_order'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's own add-to-cart handler already validates this request; no separate state-changing action here.
		$color    = isset( $_POST['drc_selected_color'] ) ? sanitize_text_field( wp_unslash( $_POST['drc_selected_color'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( $has_rush ) {
			$cart_item_data['drc_rush_order'] = true;
		}
		if ( '' !== $color ) {
			$cart_item_data['drc_selected_color'] = $color;
		}
		return $cart_item_data;
	}

	/** Re-derives each cart line's price from the quote engine — same
	 * formulas as the product-page live estimate — instead of trusting the
	 * variation's flat base price. Runs on every cart/checkout total
	 * calculation (WooCommerce's own hook, not just once at add-to-cart) so
	 * a quantity change in the cart re-applies bulk-discount rules too. */
	public static function recalculate_cart_item_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		// Safe to run on every firing of this hook within a request (cart
		// fragment refreshes call it repeatedly) — set_price() just
		// overwrites the in-memory line price for this totals pass, it
		// doesn't trigger another recalculation itself, so there's no
		// re-entrancy to guard against.
		foreach ( $cart->get_cart() as $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product instanceof WC_Product_Variation ) {
				self::convert_plain_price( $product );
				continue;
			}
			$parent_id   = $product->get_parent_id();
			$material_id = (int) get_post_meta( $parent_id, '_drc_material_id', true );
			$ft          = $product->get_meta( '_drc_size_ft' );
			$cm          = $product->get_meta( '_drc_size_cm' );
			// ft first, cm as the fallback — same order as the quote
			// engine's catalog_size_offer(); a cm-only size is still priced.
			$dimensions = DRC_Quote_Engine::parse_catalog_dimensions( $ft ?: null, 0.3048 )
				?: DRC_Quote_Engine::parse_catalog_dimensions( $cm ?: null, 0.01 );
			if ( ! $material_id || ! $dimensions ) {
				self::convert_plain_price( $product ); // not DRC-priced — keep WooCommerce's own price (in the charge currency).
				continue;
			}

			$rug = array(
				// get_price() from a fresh object — the in-memory product may
				// already carry this request's earlier set_price(). Respects a
				// sale price, unlike get_regular_price().
				'sizes'               => array( array( 'ft' => $ft ?: null, 'cm' => $cm ?: null, 'price' => (float) wc_get_product( $product->get_id() )->get_price(), 'lead_time_days' => (int) $product->get_meta( '_drc_lead_time_days' ) ) ),
				'base_price_currency' => DRC_Multi_Currency::base(),
				'weave_type'          => get_post_meta( $parent_id, '_drc_weave_type', true ) ?: null,
				'lead_time_days'      => (int) get_post_meta( $parent_id, '_drc_lead_time_days', true ) ?: null,
			);

			$engine = new DRC_Quote_Engine();
			$result = $engine->calculate_quote(
				$rug,
				$dimensions[0],
				$dimensions[1],
				$material_id,
				$cart_item['quantity'],
				! empty( $cart_item['drc_rush_order'] ),
				null,
				null,
				null,
				'rect'
			);

			if ( isset( $result['error'] ) ) {
				continue; // leave WooCommerce's default price rather than break checkout on a pricing-engine error.
			}

			// price_per_piece already reflects rush surcharge + any bulk
			// discount for this quantity — set_price() is WooCommerce's
			// supported per-line override point, applied before totals sum.
			$product->set_price( DRC_Multi_Currency::convert( (float) $result['price_per_piece'] ) );
		}
	}

	/** Non-DRC lines keep WooCommerce's own price, converted into the
	 * currency being charged (a no-op in the base currency). Reads a fresh
	 * product so repeated totals passes in one request never compound. */
	private static function convert_plain_price( $product ) {
		if ( ! $product instanceof WC_Product || 1.0 === DRC_Multi_Currency::rate() ) {
			return;
		}
		$fresh = wc_get_product( $product->get_id() );
		if ( $fresh && '' !== $fresh->get_price() ) {
			$product->set_price( DRC_Multi_Currency::convert( (float) $fresh->get_price() ) );
		}
	}

	/** Shows "Rush order (+25%)" under the cart-line item name, purely for
	 * display — recalculate_cart_item_price() has already folded the
	 * surcharge into the line price itself. */
	public static function display_rush_order( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item['drc_rush_order'] ) ) {
			$settings  = DRC_Settings::all();
			$item_data[] = array(
				'name'  => 'Production',
				'value' => sprintf( 'Rush (+%.0f%% fee)', (float) $settings['rush_surcharge_pct'] ),
			);
		}
		return $item_data;
	}

	/** Carries the rush flag from the cart line onto the resulting order
	 * line item, so it's visible on the order and in admin after checkout
	 * completes (cart item data doesn't survive past checkout on its own). */
	public static function persist_rush_order_on_order_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['drc_rush_order'] ) ) {
			$item->add_meta_data( 'Rush Order', 'Yes', true );
		}
	}

	/** Shows the chosen colorway under the cart-line item name — purely
	 * display, same convention as display_rush_order() above. */
	public static function display_selected_color( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item['drc_selected_color'] ) ) {
			$item_data[] = array(
				'name'  => 'Color',
				'value' => $cart_item['drc_selected_color'],
			);
		}
		return $item_data;
	}

	/** Carries the selected color from the cart line onto the resulting
	 * order line item, same convention as persist_rush_order_on_order_item()
	 * above — order item meta is what thankyou.php/review-order.php's
	 * WooCommerce-native item display already renders automatically. */
	public static function persist_selected_color_on_order_item( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['drc_selected_color'] ) ) {
			$item->add_meta_data( 'Color', $values['drc_selected_color'], true );
		}
	}
}
