<?php
/**
 * WooCommerce "Pay for order" template-part override
 * (wc_get_template('checkout/form-pay.php')) — reached via
 * /checkout/order-pay/{id}/?pay_for_order=true&key=..., the link a customer
 * lands on after accepting a quote (DRC_Order_Creator's
 * get_checkout_payment_url() — see class-rest-routes.php's transition_quote()).
 * This is a genuinely separate WooCommerce template from
 * checkout/form-checkout.php (a different endpoint, backed by an existing
 * $order rather than WC()->cart), so overriding form-checkout.php alone
 * left this one on WooCommerce's bare unstyled default — confirmed live,
 * this was the actual gap. Same two-column layout/visual language as
 * form-checkout.php's override, adapted to read from $order instead of the
 * cart.
 */

defined( 'ABSPATH' ) || exit;

$totals = $order->get_order_item_totals(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
?>

<form id="order_review" method="post" class="grid grid-cols-1 lg:grid-cols-5 gap-10">

	<div class="lg:col-span-2 space-y-4">
		<div class="border border-stone-200">
			<div class="px-5 py-4 border-b border-stone-100">
				<p class="storefront-eyebrow">Order Summary</p>
			</div>
			<div class="p-5">
				<div class="space-y-4">
					<?php foreach ( $order->get_items() as $item_id => $item ) :
						if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
							continue;
						}
					?>
						<div class="flex items-start justify-between gap-2 pb-4 border-b border-stone-100 last:border-0 last:pb-0">
							<div class="min-w-0">
								<p class="font-serif text-lg font-light text-stone-900 truncate">
									<?php echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) ); ?>
								</p>
								<p class="text-stone-400 text-sm mt-0.5">
									<?php echo (int) $item->get_quantity(); ?> piece<?php echo 1 === (int) $item->get_quantity() ? '' : 's'; ?>
								</p>
							</div>
							<p class="text-stone-900 text-sm font-medium flex-shrink-0">
								<?php echo wp_kses_post( dreamrugs_price_span( (float) $order->get_line_subtotal( $item ), $order->get_formatted_line_subtotal( $item ) ) ); ?>
							</p>
						</div>
					<?php endforeach; ?>
				</div>

				<?php
				// get_order_item_totals() only returns each row as
				// {label, value: <already-formatted HTML>} — no raw number —
				// so a currency-converted row needs its amount looked up
				// separately by the row's own key. 'fee' is skipped
				// deliberately: WooCommerce sums potentially multiple fee
				// line items into that one row with no single getter for the
				// combined raw amount, so it's left showing the store's real
				// currency rather than a wrong conversion.
				$drc_raw_amount_by_key = array(
					'cart_subtotal' => (float) $order->get_subtotal(),
					'discount'      => (float) $order->get_total_discount(),
					'shipping'      => (float) $order->get_shipping_total(),
					'tax'           => (float) $order->get_total_tax(),
				);
				?>
				<?php if ( $totals ) : ?>
					<div class="space-y-1.5 text-sm border-t border-stone-200 pt-4 mt-4">
						<?php foreach ( $totals as $key => $total ) :
							if ( 'order_total' === $key ) {
								continue; // rendered separately below, matching checkout's highlighted total row.
							}
							$value = isset( $drc_raw_amount_by_key[ $key ] )
								? dreamrugs_price_span( $drc_raw_amount_by_key[ $key ], $total['value'] )
								: $total['value'];
						?>
							<div class="flex justify-between">
								<span class="text-stone-400"><?php echo wp_kses_post( $total['label'] ); ?></span>
								<span class="text-stone-600"><?php echo wp_kses_post( $value ); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( isset( $totals['order_total'] ) ) : ?>
					<div class="flex justify-between items-center border-t border-stone-200 pt-4 mt-4">
						<span class="text-stone-900 font-medium text-sm"><?php echo wp_kses_post( $totals['order_total']['label'] ); ?></span>
						<span id="drc-order-total" class="text-stone-900 font-medium text-xl"><?php echo wp_kses_post( dreamrugs_price_span( (float) $order->get_total(), $totals['order_total']['value'] ) ); ?></span>
					</div>
				<?php endif; ?>

				<p id="drc-currency-disclaimer" class="text-stone-400 text-xs mt-3" style="display:none;"></p>
				<p class="text-stone-400 text-xs leading-relaxed mt-3">Final price confirmed after production review.</p>
			</div>
		</div>
	</div>

	<div class="lg:col-span-3">
		<div class="border border-stone-200">
			<?php
			/**
			 * Fires immediately before the payment section — kept so any
			 * hooked callbacks (this store's own or a payment-gateway
			 * plugin's) still run exactly where WooCommerce expects.
			 *
			 * @since 8.2.0
			 */
			do_action( 'woocommerce_pay_order_before_payment' );
			?>
			<div id="payment" class="p-5">
				<?php if ( $order->needs_payment() ) : ?>
					<p class="storefront-eyebrow mb-3">Payment Method</p>
					<ul class="wc_payment_methods payment_methods methods space-y-2" aria-label="<?php esc_attr_e( 'Payment methods', 'woocommerce' ); ?>">
						<?php
						if ( ! empty( $available_gateways ) ) {
							foreach ( $available_gateways as $gateway ) {
								wc_get_template( 'checkout/payment-method.php', array( 'gateway' => $gateway ) );
							}
						} else {
							echo '<li>';
							wc_print_notice( apply_filters( 'woocommerce_no_available_payment_methods_message', esc_html__( 'Sorry, it seems that there are no available payment methods for your location. Please contact us if you require assistance or wish to make alternate arrangements.', 'woocommerce' ) ), 'notice' ); // phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment
							echo '</li>';
						}
						?>
					</ul>
				<?php endif; ?>
				<div class="form-row mt-4 space-y-3">
					<input type="hidden" name="woocommerce_pay" value="1" />

					<div class="text-stone-400 text-xs leading-relaxed">
						<?php wc_get_template( 'checkout/terms.php' ); ?>
					</div>

					<?php do_action( 'woocommerce_pay_order_before_submit' ); ?>

					<?php echo apply_filters( 'woocommerce_pay_order_button_html', '<button type="submit" class="w-full storefront-cta-solid py-4 transition-colors" id="place_order" value="' . esc_attr( $order_button_text ) . '" data-value="' . esc_attr( $order_button_text ) . '">' . esc_html( $order_button_text ) . '</button>' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup with escaped dynamic attribute/text values only. ?>

					<?php do_action( 'woocommerce_pay_order_after_submit' ); ?>

					<p class="text-stone-400 text-xs text-center leading-relaxed">By placing this order you agree to our production and delivery terms.</p>

					<?php wp_nonce_field( 'woocommerce-pay', 'woocommerce-pay-nonce' ); ?>
				</div>
			</div>
		</div>
	</div>
</form>
