<?php
/**
 * WooCommerce cart template-part override (wc_get_template('cart/cart.php'),
 * invoked by the [woocommerce_cart] shortcode's output — which is what
 * page-cart.php's the_content() call renders). This is a CONTENT FRAGMENT,
 * not a page template: no get_header()/get_footer(), no breadcrumb or
 * heading — page-cart.php already provides that chrome. An earlier version
 * of this file wrongly included full page chrome, which doubled up every
 * element (two headers, two breadcrumbs) since it was rendered *inside*
 * page-cart.php's own already-complete page — the lesson matches the one
 * page-cart.php's own header comment already recorded about theme-root
 * cart.php not applying: this template hierarchy has more than one trap.
 *
 * Ported from frontend/src/pages/CustomerCart.tsx's line-item/sidebar
 * design, including its +/- quantity stepper (no separate "Update cart"
 * submit button — CustomerCart.tsx's Minus/Plus buttons call updateItem()
 * directly). Quantity changes and item removal go through the WooCommerce
 * Store API (/wc/store/v1/cart/*, nonce action 'wc_store_api') via
 * assets/cart.js rather than a full form postback, then the whole
 * #drc-cart-root block is refreshed from a background fetch of this same
 * page — reusing WC()->cart's own PHP-rendered totals/tax/coupon math
 * instead of re-implementing pricing rules in JS. WC()->cart already holds
 * repriced line totals via class-cart-pricing.php's
 * recalculate_cart_item_price() hook (rush surcharge etc. already
 * applied). Promo codes use WooCommerce's native coupon form fields
 * (coupon_code / apply_coupon), matching the migration plan's decision to
 * migrate PromoCode rows into native shop_coupon posts rather than a
 * custom promo system.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );
?>

<div id="drc-cart-root">
<?php if ( WC()->cart->is_empty() ) : ?>
	<div class="text-center py-24 space-y-4">
		<?php dreamrugs_icon( 'shopping-bag', 36, 'mx-auto text-stone-300' ); ?>
		<p class="text-stone-500 text-sm">Your cart is empty.</p>
		<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="inline-block text-sm text-stone-700 hover:text-stone-900 border-b border-stone-300 pb-0.5 transition-colors">
			Browse the Collection
		</a>
	</div>
<?php else : ?>
<form class="woocommerce-cart-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
	<?php do_action( 'woocommerce_before_cart_table' ); ?>
	<div class="grid grid-cols-1 lg:grid-cols-3 gap-10">
		<div class="lg:col-span-2 space-y-4">
			<?php foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) :
				$product = $cart_item['data'];
				if ( ! $product || ! $product->exists() || $cart_item['quantity'] <= 0 ) {
					continue;
				}
				$ft    = $product instanceof WC_Product_Variation ? $product->get_meta( '_drc_size_ft' ) : '';
				$rush  = ! empty( $cart_item['drc_rush_order'] );
				$color = $cart_item['drc_selected_color'] ?? '';
				$parent_id = $product instanceof WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
			?>
				<div class="cart_item flex gap-4 border border-stone-200 p-4" data-drc-cart-item-key="<?php echo esc_attr( $cart_item_key ); ?>">
					<div class="w-20 h-20 flex-shrink-0 bg-stone-100 overflow-hidden">
						<?php $image = $product->get_image_id() ? wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' ) : wp_get_attachment_image_url( get_post_thumbnail_id( $parent_id ), 'thumbnail' ); ?>
						<?php if ( $image ) : ?>
							<img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" class="w-full h-full object-cover" />
						<?php else : ?>
							<div class="w-full h-full flex items-center justify-center"><?php dreamrugs_icon( 'layers', 20, 'text-stone-300' ); ?></div>
						<?php endif; ?>
					</div>
					<div class="flex-1 min-w-0 space-y-1.5">
						<div class="flex items-start justify-between gap-2">
							<a href="<?php echo esc_url( get_permalink( $parent_id ) ); ?>" class="font-serif text-base text-stone-900 hover:text-stone-600 transition-colors truncate"><?php echo esc_html( $product->get_name() ); ?></a>
							<button type="button" data-drc-remove-item class="text-stone-300 hover:text-red-500 transition-colors flex-shrink-0"><?php dreamrugs_icon( 'trash-2', 14 ); ?></button>
						</div>
						<p class="text-stone-400 text-xs"><?php echo esc_html( $ft ? "{$ft} ft" : '' ); ?><?php echo $color ? ' · ' . esc_html( $color ) : ''; ?><?php echo $rush ? ' · Rush' : ''; ?></p>
						<div class="flex items-center justify-between pt-1">
							<div class="flex items-center border border-stone-200">
								<button type="button" data-drc-qty-minus class="px-2 py-1 text-stone-500 hover:text-stone-900 transition-colors disabled:opacity-30" <?php disabled( (int) $cart_item['quantity'] <= 1 ); ?>><?php dreamrugs_icon( 'minus', 12 ); ?></button>
								<span class="px-3 text-sm text-stone-700" data-drc-qty-value><?php echo (int) $cart_item['quantity']; ?></span>
								<button type="button" data-drc-qty-plus class="px-2 py-1 text-stone-500 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'plus', 12 ); ?></button>
							</div>
							<p class="text-stone-900 text-sm font-medium"><?php echo wp_kses_post( dreamrugs_price_span( (float) $product->get_price() * (int) $cart_item['quantity'], wc_price( $product->get_price() * $cart_item['quantity'] ) ) ); ?></p>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="lg:col-span-1">
			<div class="border border-stone-200 p-5 space-y-4 sticky top-[102px]">
				<p class="storefront-eyebrow">Order Total</p>

				<div class="space-y-2">
					<?php if ( WC()->cart->get_coupons() ) : ?>
						<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
							<div class="flex items-center justify-between gap-2 bg-green-50 border border-green-200 px-3 py-2.5">
								<div class="flex items-center gap-2 min-w-0">
									<span class="text-green-600 flex-shrink-0"><?php dreamrugs_icon( 'check', 13 ); ?></span>
									<span class="text-green-800 text-xs font-medium truncate"><?php echo esc_html( $code ); ?> applied</span>
								</div>
								<?php
								// wc_get_cart_remove_coupon_url() is not a real
								// WooCommerce function — see the identical fix
								// (and full explanation) in woocommerce/checkout/
								// review-order.php. Cart-page equivalent of the
								// same ?remove_coupon=<code> query-arg mechanism,
								// pointed at the cart URL instead of checkout.
								$remove_coupon_url = add_query_arg( 'remove_coupon', rawurlencode( $code ), wc_get_cart_url() );
								?>
								<a href="<?php echo esc_url( $remove_coupon_url ); ?>" class="text-green-700 hover:text-green-900 flex-shrink-0"><?php dreamrugs_icon( 'x', 13 ); ?></a>
							</div>
						<?php endforeach; ?>
					<?php else : ?>
						<div class="flex gap-1.5">
							<div class="relative flex-1 min-w-0">
								<span class="absolute left-2.5 top-1/2 -translate-y-1/2 text-stone-300"><?php dreamrugs_icon( 'tag', 12 ); ?></span>
								<input type="text" name="coupon_code" placeholder="Promo code" class="w-full border border-stone-200 focus:border-stone-400 pl-8 pr-3 py-2 text-stone-900 placeholder-stone-300 text-xs uppercase focus:outline-none transition-colors" />
							</div>
							<button type="submit" name="apply_coupon" value="Apply" class="px-3 border border-stone-300 hover:border-stone-900 text-stone-700 text-xs font-medium uppercase tracking-wider transition-colors flex-shrink-0">Apply</button>
						</div>
					<?php endif; ?>
					<?php foreach ( wc_get_notices( 'error' ) as $notice ) : ?>
						<p class="text-red-500 text-xs flex items-center gap-1"><?php dreamrugs_icon( 'alert-triangle', 11 ); ?> <?php echo wp_kses_post( $notice['notice'] ); ?></p>
					<?php endforeach; wc_clear_notices(); ?>
				</div>

				<div class="space-y-1.5 text-sm border-t border-stone-100 pt-4">
					<div class="flex justify-between">
						<span class="text-stone-400"><?php echo (int) WC()->cart->get_cart_contents_count(); ?> item<?php echo 1 === WC()->cart->get_cart_contents_count() ? '' : 's'; ?></span>
						<span class="text-stone-600"><?php echo wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_subtotal(), wc_price( WC()->cart->get_subtotal() ) ) ); ?></span>
					</div>
					<?php if ( WC()->cart->get_discount_total() > 0 ) : ?>
						<div class="flex justify-between">
							<span class="text-green-600">Promo discount</span>
							<span class="text-green-600">−<?php echo wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_discount_total(), wc_price( WC()->cart->get_discount_total() ) ) ); ?></span>
						</div>
					<?php endif; ?>
					<div class="flex justify-between">
						<span class="text-stone-400">Shipping</span>
						<span class="text-stone-600"><?php echo WC()->cart->get_shipping_total() > 0 ? '+' . wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_shipping_total(), wc_price( WC()->cart->get_shipping_total() ) ) ) : 'Free'; ?></span>
					</div>
				</div>

				<div class="flex justify-between items-center border-t border-stone-200 pt-4">
					<span class="text-stone-900 font-medium text-sm">Total</span>
					<span class="text-stone-900 font-medium text-xl"><?php echo wp_kses_post( dreamrugs_price_span( (float) WC()->cart->get_total( 'edit' ), wc_price( WC()->cart->get_total( 'edit' ) ) ) ); ?></span>
				</div>
				<p class="text-stone-400 text-xs leading-relaxed">Final price confirmed after production review.</p>
				<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="block text-center storefront-cta-solid py-4 transition-colors">Proceed to Checkout</a>
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="block text-center text-stone-400 hover:text-stone-900 text-xs transition-colors">Continue browsing</a>
			</div>
		</div>
	</div>
	<?php do_action( 'woocommerce_after_cart_table' ); ?>
</form>
<?php endif; ?>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>
