<?php
/**
 * Branded chrome around WooCommerce's native [woocommerce_checkout]
 * shortcode. Ported from CustomerCheckout.tsx's page shell (full-viewport-
 * width layout, breadcrumb, heading) — the actual form fields/order review/
 * payment section are WooCommerce template-part overrides in
 * woocommerce/checkout/*.php (same "restyle the real thing, don't
 * reimplement it" approach as woocommerce/cart/cart.php), so the verified-
 * working Razorpay gateway integration, nonces, and AJAX totals refresh
 * stay exactly as WooCommerce provides them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The cart-based checkout, "pay for an already-created order" (the link a
// customer lands on after accepting a quote — see woocommerce/checkout/
// form-pay.php's header comment), and the post-payment thank-you page (see
// woocommerce/checkout/thankyou.php) all render through this same
// WordPress page; only the chrome above the WooCommerce content needs to
// tell them apart.
$is_order_pay      = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-pay' );
$is_order_received = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );

get_header();
?>

<div class="w-[94vw] max-w-none mx-auto px-4 py-10 space-y-8">
	<?php if ( ! $is_order_received ) : ?>
		<div class="flex items-center gap-2 text-xs text-stone-400">
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-900 transition-colors">Home</a>
			<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
			<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="hover:text-stone-900 transition-colors">Collection</a>
			<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
			<span class="text-stone-600"><?php echo $is_order_pay ? 'Payment' : 'Checkout'; ?></span>
		</div>
		<div class="pb-6 border-b border-stone-100">
			<p class="storefront-eyebrow mb-2"><?php echo $is_order_pay ? 'Almost There' : 'Order'; ?></p>
			<h1 class="storefront-heading text-4xl"><?php echo $is_order_pay ? 'Complete Your Payment' : 'Checkout'; ?></h1>
		</div>
	<?php endif; ?>

	<?php while ( have_posts() ) : the_post(); ?>
		<?php the_content(); ?>
	<?php endwhile; ?>
</div>

<?php get_footer(); ?>
