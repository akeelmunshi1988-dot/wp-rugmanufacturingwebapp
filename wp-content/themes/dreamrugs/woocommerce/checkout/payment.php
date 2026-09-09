<?php
/**
 * WooCommerce checkout template-part override (wc_get_template
 * ('checkout/payment.php')) — a content fragment invoked by
 * woocommerce_checkout_payment(), called directly from this theme's
 * form-checkout.php's right column (separately from review-order.php, so
 * the payment method list + place-order button sit under the address form
 * rather than under the order summary — see form-checkout.php's header
 * comment). The actual gateway list/nonce/AJAX submit handling is
 * untouched — WooCommerce's own checkout.js still drives all of it — this
 * only restyles the wrapper and button to match storefront-cta-solid.
 */

defined( 'ABSPATH' ) || exit;

if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_before_payment' );
}
?>
<div id="payment" class="woocommerce-checkout-payment border-t border-stone-100 pt-5">
	<?php if ( WC()->cart && WC()->cart->needs_payment() ) : ?>
		<p class="storefront-eyebrow mb-3">Payment Method</p>
		<ul class="wc_payment_methods payment_methods methods space-y-2" aria-label="<?php esc_attr_e( 'Payment methods', 'woocommerce' ); ?>">
			<?php
			if ( ! empty( $available_gateways ) ) {
				foreach ( $available_gateways as $gateway ) {
					wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
				}
			} else {
				echo '<li>';
				wc_print_notice( apply_filters( 'woocommerce_no_available_payment_methods_message', WC()->customer->get_billing_country() ? esc_html__( 'Sorry, it seems that there are no available payment methods. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) : esc_html__( 'Please fill in your details above to see available payment methods.', 'woocommerce' ) ), 'notice' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
				echo '</li>';
			}
			?>
		</ul>
	<?php endif; ?>
	<div class="form-row place-order mt-4 space-y-3">
		<noscript>
			<?php
			/* translators: $1 and $2 opening and closing emphasis tags respectively */
			printf( esc_html__( 'Since your browser does not support JavaScript, or it is disabled, please ensure you click the %1$sUpdate Totals%2$s button before placing your order. You may be charged more than the amount stated above if you fail to do so.', 'woocommerce' ), '<em>', '</em>' );
			?>
			<br/><button type="submit" class="button alt" name="woocommerce_checkout_update_totals" value="<?php esc_attr_e( 'Update totals', 'woocommerce' ); ?>"><?php esc_html_e( 'Update totals', 'woocommerce' ); ?></button>
		</noscript>

		<div class="text-stone-400 text-xs leading-relaxed">
			<?php wc_get_template( 'checkout/terms.php' ); ?>
		</div>

		<?php do_action( 'woocommerce_review_order_before_submit' ); ?>

		<?php echo apply_filters( 'woocommerce_order_button_html', '<button type="submit" class="w-full storefront-cta-solid py-4 transition-colors" name="woocommerce_checkout_place_order" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup with escaped dynamic attribute/text values only. ?>

		<?php do_action( 'woocommerce_review_order_after_submit' ); ?>

		<p class="text-stone-400 text-xs text-center leading-relaxed">By placing this order you agree to our production and delivery terms.</p>

		<?php wp_nonce_field( 'woocommerce-process_checkout', 'woocommerce-process-checkout-nonce' ); ?>
	</div>
</div>
<?php
if ( ! wp_doing_ajax() ) {
	do_action( 'woocommerce_review_order_after_payment' );
}
