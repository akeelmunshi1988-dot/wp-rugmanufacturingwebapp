<?php
/**
 * WooCommerce checkout template-part override (wc_get_template
 * ('checkout/form-checkout.php')) — a CONTENT FRAGMENT invoked by the
 * [woocommerce_checkout] shortcode, same "no get_header()/get_footer(),
 * page-checkout.php already provides that chrome" rule woocommerce/cart/
 * cart.php's header comment documents (that template-hierarchy mistake has
 * bitten more than once in this build).
 *
 * Ported from CustomerCheckout.tsx's two-column grid: order summary left
 * (review-order.php content only — no payment section), contact + address
 * form + payment methods + place-order button right. WooCommerce's default
 * form-checkout.php stacks customer-details above order-review instead and
 * bundles payment into the same order-review block; splitting
 * woocommerce_order_review() and woocommerce_checkout_payment() apart here
 * (both plain functions, safe to call directly outside their usual combined
 * woocommerce_checkout_order_review hook) is what makes the side-by-side
 * layout possible without touching WooCommerce's actual field rendering,
 * nonce, or AJAX totals-refresh logic — same principle as the cart
 * override: restyle the real thing, don't reimplement it.
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_checkout_form', $checkout );

if ( ! $checkout->is_registration_enabled() && $checkout->is_registration_required() && ! is_user_logged_in() ) {
	echo esc_html( apply_filters( 'woocommerce_checkout_must_be_logged_in_message', __( 'You must be logged in to checkout.', 'woocommerce' ) ) );
	return;
}
?>

<form name="checkout" method="post" class="checkout woocommerce-checkout grid grid-cols-1 lg:grid-cols-5 gap-10" action="<?php echo esc_url( wc_get_checkout_url() ); ?>" enctype="multipart/form-data" aria-label="<?php echo esc_attr__( 'Checkout', 'woocommerce' ); ?>">

	<div class="lg:col-span-2 space-y-4">
		<div class="border border-stone-200">
			<div class="px-5 py-4 border-b border-stone-100">
				<p class="storefront-eyebrow">Order Summary</p>
			</div>
			<div class="p-5">
				<div id="order_review" class="woocommerce-checkout-review-order">
					<?php woocommerce_order_review(); ?>
				</div>
			</div>
		</div>
	</div>

	<div class="lg:col-span-3">
		<div class="border border-stone-200">
			<?php if ( $checkout->get_checkout_fields() ) : ?>
				<?php do_action( 'woocommerce_checkout_before_customer_details' ); ?>
				<div id="customer_details">
					<?php do_action( 'woocommerce_checkout_billing' ); ?>
				</div>
				<?php do_action( 'woocommerce_checkout_after_customer_details' ); ?>
			<?php endif; ?>

			<div class="px-5 pb-5">
				<?php woocommerce_checkout_payment(); ?>
			</div>
		</div>
	</div>
</form>

<?php do_action( 'woocommerce_after_checkout_form', $checkout ); ?>
