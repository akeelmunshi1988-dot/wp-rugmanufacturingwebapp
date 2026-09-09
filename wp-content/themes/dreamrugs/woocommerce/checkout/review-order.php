<?php
/**
 * WooCommerce checkout template-part override (wc_get_template
 * ('checkout/review-order.php')) — a content fragment invoked by
 * woocommerce_order_review(), called directly from this theme's
 * form-checkout.php (left column only — payment.php is called separately
 * in the right column, splitting what WooCommerce normally renders as one
 * combined block). Ported from CustomerCheckout.tsx's Order Summary panel:
 * line items, subtotal/tax/shipping breakdown, promo code, grand total.
 * Reads WC()->cart directly (same as woocommerce/cart/cart.php) rather than
 * the original's client-side checkout-state totals math, since the cart is
 * this template's actual source of truth at this point in the flow.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_review_order_before_cart_contents' );
?>

<div class="space-y-4">
	<?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
		$product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
		$visible = apply_filters( 'woocommerce_checkout_cart_item_visible', true, $cart_item, $cart_item_key );
		if ( ! ( $product instanceof WC_Product && $product->exists() && $cart_item['quantity'] > 0 && $visible ) ) {
			continue;
		}
		$ft    = $product instanceof WC_Product_Variation ? $product->get_meta( '_drc_size_ft' ) : '';
		$rush  = ! empty( $cart_item['drc_rush_order'] );
		$color = $cart_item['drc_selected_color'] ?? '';
	?>
		<div class="flex items-start justify-between gap-2 pb-4 border-b border-stone-100 last:border-0 last:pb-0">
			<div class="min-w-0">
				<p class="font-serif text-lg font-light text-stone-900 truncate"><?php echo wp_kses_post( apply_filters( 'woocommerce_cart_item_name', $product->get_name(), $cart_item, $cart_item_key ) ); ?></p>
				<p class="text-stone-400 text-sm mt-0.5">
					<?php echo esc_html( $ft ? "{$ft} ft" : '' ); ?><?php echo $color ? ' · ' . esc_html( $color ) : ''; ?> · <?php echo (int) $cart_item['quantity']; ?> piece<?php echo 1 === (int) $cart_item['quantity'] ? '' : 's'; ?><?php echo $rush ? ' · Rush' : ''; ?>
				</p>
			</div>
			<p class="text-stone-900 text-sm font-medium flex-shrink-0"><?php echo wp_kses_post( dreamrugs_price_span( (float) $product->get_price() * (int) $cart_item['quantity'], WC()->cart->get_product_subtotal( $product, $cart_item['quantity'] ) ) ); ?></p>
		</div>
	<?php endforeach; ?>
</div>

<?php do_action( 'woocommerce_review_order_after_cart_contents' ); ?>

<div class="space-y-1.5 text-sm border-t border-stone-200 pt-4 mt-4">
	<div class="flex justify-between">
		<span class="text-stone-400">Subtotal</span>
		<span class="text-stone-600"><?php echo wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_subtotal(), wc_price( WC()->cart->get_subtotal() ) ) ); ?></span>
	</div>

	<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) :
		$coupon_discount = (float) WC()->cart->get_coupon_discount_amount( $code );
	?>
		<div class="flex justify-between">
			<span class="text-green-600"><?php wc_cart_totals_coupon_label( $coupon ); ?></span>
			<span class="text-green-600">−<?php echo wp_kses_post( dreamrugs_price_span( $coupon_discount, wc_price( $coupon_discount ) ) ); ?></span>
		</div>
	<?php endforeach; ?>

	<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
		<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>
		<div class="flex justify-between">
			<span class="text-stone-400">Shipping</span>
			<span class="text-stone-600"><?php echo WC()->cart->get_shipping_total() > 0 ? wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_shipping_total(), wc_price( WC()->cart->get_shipping_total() ) ) ) : 'Free'; ?></span>
		</div>
		<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>
	<?php endif; ?>

	<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
		<div class="flex justify-between">
			<span class="text-stone-400"><?php echo esc_html( $fee->name ); ?></span>
			<span class="text-stone-600"><?php echo wp_kses_post( dreamrugs_price_span( (float) $fee->amount, wc_price( $fee->amount ) ) ); ?></span>
		</div>
	<?php endforeach; ?>

	<?php if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) : ?>
		<?php if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) : ?>
			<?php foreach ( WC()->cart->get_tax_totals() as $code => $tax ) : // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited ?>
				<div class="flex justify-between">
					<span class="text-stone-400"><?php echo esc_html( $tax->label ); ?></span>
					<span class="text-stone-600"><?php echo wp_kses_post( dreamrugs_price_span( (float) $tax->amount, $tax->formatted_amount ) ); ?></span>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="flex justify-between">
				<span class="text-stone-400"><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></span>
				<span class="text-stone-600"><?php echo wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_taxes_total(), wc_price( WC()->cart->get_taxes_total() ) ) ); ?></span>
			</div>
		<?php endif; ?>
	<?php endif; ?>
</div>

<?php
// Promo code — always-visible input (matches this theme's convention in
// woocommerce/cart/cart.php, not WooCommerce's default click-to-reveal
// coupon toggle) rather than a separate form-coupon.php override, since
// this is the only checkout location that needs it.
//
// Deliberately a <div>, not a real nested <form> — this whole block
// already renders inside WooCommerce's outer <form name="checkout"> (this
// file is included via #order_review, itself inside that form; see
// form-checkout.php). HTML forbids nested forms: a <form
// class="checkout_coupon"> here would be silently dropped by the browser's
// parser, leaving the "Apply" button owned by the OUTER checkout form —
// confirmed live, this is exactly what was happening: clicking Apply
// actually attempted to place the order instead of validating the coupon.
// WooCommerce's own checkout.js only wires its AJAX apply-coupon handler
// to a real form.checkout_coupon's submit event, so a nested form isn't
// salvageable here; assets/checkout-coupon.js instead calls the same
// wc-ajax=apply_coupon endpoint directly (reusing WooCommerce's real
// coupon validation, not reimplementing it) and re-triggers WC's own
// update_checkout refresh on success, exactly as checkout.js itself does.
if ( wc_coupons_enabled() && ! WC()->cart->get_coupons() ) :
?>
	<div id="drc-coupon-form" class="flex gap-1.5 pt-2">
		<div class="relative flex-1 min-w-0">
			<span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-stone-300"><?php dreamrugs_icon( 'tag', 12 ); ?></span>
			<input type="text" name="coupon_code" placeholder="Promo code" id="coupon_code" class="w-full border border-stone-200 focus:border-stone-400 pl-8 pr-3 py-2 text-stone-900 placeholder-stone-300 text-xs uppercase focus:outline-none transition-colors" />
		</div>
		<button type="button" id="drc-apply-coupon" class="px-3 border border-stone-300 hover:border-stone-900 text-stone-700 text-xs font-medium uppercase tracking-wider transition-colors flex-shrink-0">Apply</button>
	</div>
<?php elseif ( wc_coupons_enabled() ) : ?>
	<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
		<div class="flex items-center justify-between gap-2 bg-green-50 border border-green-200 px-3 py-2.5 mt-2">
			<div class="flex items-center gap-2 min-w-0">
				<span class="text-green-600 flex-shrink-0"><?php dreamrugs_icon( 'check', 13 ); ?></span>
				<span class="text-green-800 text-xs font-medium truncate"><?php echo esc_html( $code ); ?> applied</span>
			</div>
			<?php
			// wc_get_cart_remove_coupon_url() is not a real WooCommerce
			// function — confirmed live, it fataled the moment a coupon was
			// ever successfully applied here (a pre-existing bug this
			// branch never reached until the "Apply" button itself was
			// fixed to actually call WooCommerce's coupon-apply AJAX; see
			// this file's header comment). The real mechanism, matching
			// wc_cart_totals_coupon_label()'s own "[Remove]" link in
			// wc-cart-functions.php, is a plain ?remove_coupon=<code> query
			// arg on the current page — WC_Form_Handler::update_cart_action()
			// (hooked to wp_loaded) reads it server-side and calls
			// WC()->cart->remove_coupon(), no AJAX needed.
			$remove_coupon_url = add_query_arg( 'remove_coupon', rawurlencode( $code ), wc_get_checkout_url() );
			?>
			<a href="<?php echo esc_url( $remove_coupon_url ); ?>" class="text-green-700 hover:text-green-900 flex-shrink-0"><?php dreamrugs_icon( 'x', 13 ); ?></a>
		</div>
	<?php endforeach; ?>
<?php endif; ?>

<?php foreach ( wc_get_notices( 'error' ) as $notice ) : ?>
	<p class="text-red-500 text-xs flex items-center gap-1 mt-2"><?php dreamrugs_icon( 'alert-triangle', 11 ); ?> <?php echo wp_kses_post( $notice['notice'] ); ?></p>
<?php endforeach; wc_clear_notices(); ?>

<?php do_action( 'woocommerce_review_order_before_order_total' ); ?>

<div class="flex justify-between items-center border-t border-stone-200 pt-4 mt-4">
	<span class="text-stone-900 font-medium text-sm">Total</span>
	<span id="drc-order-total" class="text-stone-900 font-medium text-xl"><?php echo wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_total( 'edit' ), wc_price( WC()->cart->get_total( 'edit' ) ) ) ); ?></span>
</div>

<?php do_action( 'woocommerce_review_order_after_order_total' ); ?>

<p id="drc-currency-disclaimer" class="text-stone-400 text-xs" style="display:none;"></p>
<p class="text-stone-400 text-xs leading-relaxed mt-3">Final price confirmed after production review.</p>
