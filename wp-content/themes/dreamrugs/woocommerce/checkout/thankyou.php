<?php
/**
 * WooCommerce "Thank you" / order-received template override
 * (wc_get_template('checkout/thankyou.php')) — reached right after a
 * successful payment. Rendered through page-checkout.php like every other
 * checkout-family endpoint (see that file's $is_order_pay-style detection,
 * extended here for order-received), so this file only needs the actual
 * confirmation content, not page chrome.
 *
 * Ported from CustomerOrderConfirm.tsx: success banner, order details
 * (items/total/delivery), a 5-step order-progress tracker, and two action
 * links. Reads the real $order object WooCommerce already resolved rather
 * than the original's router-state payload, since there's no equivalent
 * client-side navigation state here.
 *
 * @var WC_Order|false $order
 */

defined( 'ABSPATH' ) || exit;

if ( ! $order ) {
	?>
	<div class="max-w-xl mx-auto px-4 py-24 text-center space-y-4">
		<?php dreamrugs_icon( 'package', 36, 'text-stone-300 mx-auto' ); ?>
		<h2 class="storefront-heading text-2xl">Order Not Found</h2>
		<p class="text-stone-500 text-sm">Use your email to look up your orders and track status.</p>
		<a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="inline-flex items-center gap-2 storefront-cta-solid px-6 py-3 transition-colors">
			<?php dreamrugs_icon( 'search', 13 ); ?> Track My Orders
		</a>
	</div>
	<?php
	return;
}

if ( $order->has_status( 'failed' ) ) {
	?>
	<div class="max-w-xl mx-auto px-4 py-24 text-center space-y-4">
		<span class="text-red-500 flex justify-center"><?php dreamrugs_icon( 'alert-triangle', 36 ); ?></span>
		<h2 class="storefront-heading text-2xl">Payment Failed</h2>
		<p class="text-stone-500 text-sm">Unfortunately your order cannot be processed as the originating bank/merchant has declined your transaction. Please attempt your purchase again.</p>
		<div class="flex items-center justify-center gap-3">
			<a href="<?php echo esc_url( $order->get_checkout_payment_url() ); ?>" class="storefront-cta-solid px-6 py-3 transition-colors">Retry Payment</a>
			<?php if ( is_user_logged_in() ) : ?>
				<a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="storefront-cta-outline px-6 py-3 transition-colors">My Orders</a>
			<?php endif; ?>
		</div>
	</div>
	<?php
	return;
}

do_action( 'woocommerce_before_thankyou', $order->get_id() );

$status_steps = array(
	array( 'key' => 'pending', 'label' => 'Order Placed', 'desc' => 'Awaiting production confirmation' ),
	array( 'key' => 'processing', 'label' => 'Confirmed', 'desc' => 'Production scheduled' ),
	array( 'key' => 'in_production', 'label' => 'In Production', 'desc' => 'Craftsmen at work' ),
	array( 'key' => 'shipped', 'label' => 'Shipped', 'desc' => 'On its way to you' ),
	array( 'key' => 'delivered', 'label' => 'Delivered', 'desc' => 'Enjoy your rug!' ),
);
$order_status = $order->get_status();
$active_idx   = 0;
foreach ( $status_steps as $i => $step ) {
	if ( $step['key'] === $order_status ) {
		$active_idx = $i;
		break;
	}
}

$quote_row = null;
global $wpdb;
$quote_row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_quotes WHERE wc_order_id = %d", $order->get_id() ), ARRAY_A );
?>

<div class="border border-green-200 bg-green-50 p-10 text-center space-y-3">
	<span class="text-green-600 flex justify-center"><?php dreamrugs_icon( 'check-circle', 44 ); ?></span>
	<h1 class="storefront-heading text-4xl">Order Placed</h1>
	<p class="text-stone-500 text-sm leading-relaxed max-w-sm mx-auto">
		Order <span class="text-stone-900 font-medium">#<?php echo esc_html( $order->get_order_number() ); ?></span> has been received.
		Our team will call / WhatsApp you to confirm production details and payment.
	</p>
</div>

<div class="border border-stone-200 mt-8">
	<div class="px-5 py-4 border-b border-stone-100 flex items-center gap-2">
		<?php dreamrugs_icon( 'package', 14, 'text-stone-400' ); ?>
		<p class="storefront-eyebrow">Order Details</p>
		<span class="ml-auto text-xs border border-stone-200 text-stone-500 px-2 py-0.5 capitalize"><?php echo esc_html( str_replace( '_', ' ', $order_status ) ); ?></span>
	</div>
	<div class="p-5 space-y-4">
		<div class="space-y-3">
			<?php foreach ( $order->get_items() as $item ) :
				if ( ! apply_filters( 'woocommerce_order_item_visible', true, $item ) ) {
					continue;
				}
			?>
				<div class="flex items-start justify-between gap-2 text-sm">
					<div>
						<p class="text-stone-900 font-medium"><?php echo wp_kses_post( apply_filters( 'woocommerce_order_item_name', $item->get_name(), $item, false ) ); ?></p>
						<p class="text-stone-400 text-xs mt-0.5"><?php echo (int) $item->get_quantity(); ?> piece<?php echo 1 === (int) $item->get_quantity() ? '' : 's'; ?></p>
					</div>
					<p class="text-stone-700 flex-shrink-0"><?php echo wp_kses_post( dreamrugs_price_span( (float) $order->get_line_subtotal( $item ), $order->get_formatted_line_subtotal( $item ) ) ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="grid grid-cols-2 sm:grid-cols-3 gap-5 text-sm border-t border-stone-100 pt-4">
			<div>
				<p class="text-xs tracking-[0.15em] uppercase text-stone-400 mb-1">Total</p>
				<p id="drc-order-total" class="text-stone-900 font-medium text-base"><?php echo wp_kses_post( dreamrugs_price_span( (float) $order->get_total(), $order->get_formatted_order_total() ) ); ?></p>
			</div>
			<?php if ( $quote_row && $quote_row['expected_delivery_days'] ) : ?>
				<div>
					<p class="text-xs tracking-[0.15em] uppercase text-stone-400 mb-1">Expected Delivery</p>
					<p class="text-stone-700 flex items-center gap-1"><?php dreamrugs_icon( 'clock', 12, 'text-stone-400' ); ?> ~<?php echo (int) $quote_row['expected_delivery_days']; ?> days</p>
				</div>
			<?php endif; ?>
			<?php if ( $order->get_payment_method_title() ) : ?>
				<div>
					<p class="text-xs tracking-[0.15em] uppercase text-stone-400 mb-1">Payment Method</p>
					<p class="text-stone-700"><?php echo wp_kses_post( $order->get_payment_method_title() ); ?></p>
				</div>
			<?php endif; ?>
		</div>
	</div>
	<?php $shipping_address = trim( $order->get_formatted_shipping_address() ?: '' ); ?>
	<?php if ( $shipping_address ) : ?>
		<div class="px-5 pb-5 border-t border-stone-100 pt-4">
			<p class="text-xs tracking-[0.15em] uppercase text-stone-400 mb-1.5 flex items-center gap-1"><?php dreamrugs_icon( 'map-pin', 11 ); ?> Deliver To</p>
			<p class="text-stone-600 text-sm leading-relaxed"><?php echo wp_kses_post( $shipping_address ); ?></p>
		</div>
	<?php endif; ?>
</div>

<div class="border border-stone-200 mt-8">
	<div class="px-5 py-4 border-b border-stone-100">
		<p class="storefront-eyebrow">Order Progress</p>
	</div>
	<div class="p-5">
		<ol class="relative border-l border-stone-200 ml-3 space-y-5">
			<?php foreach ( $status_steps as $i => $step ) :
				$done   = $i < $active_idx;
				$active = $i === $active_idx;
			?>
				<li class="ml-5 relative">
					<span class="absolute -left-[29px] w-4 h-4 rounded-full border-2 flex items-center justify-center <?php echo $done ? 'bg-green-500 border-green-500' : ( $active ? 'bg-stone-900 border-stone-900' : 'bg-white border-stone-300' ); ?>"></span>
					<p class="text-sm font-medium <?php echo $active ? 'text-stone-900' : ( $done ? 'text-green-600' : 'text-stone-400' ); ?>"><?php echo esc_html( $step['label'] ); ?></p>
					<p class="text-stone-400 text-xs mt-0.5"><?php echo esc_html( $step['desc'] ); ?></p>
				</li>
			<?php endforeach; ?>
		</ol>
	</div>
</div>

<div class="flex flex-col sm:flex-row gap-3 mt-8">
	<a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="flex-1 flex items-center justify-center gap-2 border border-stone-200 hover:border-stone-400 text-stone-600 hover:text-stone-900 text-xs font-medium tracking-widest uppercase py-4 transition-colors">
		<?php dreamrugs_icon( 'search', 13 ); ?> Track Orders
	</a>
	<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="flex-1 flex items-center justify-center gap-2 storefront-cta-solid py-4 transition-colors">
		Continue Shopping <?php dreamrugs_icon( 'arrow-right', 13 ); ?>
	</a>
</div>

<?php
do_action( 'woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id() );
do_action( 'woocommerce_thankyou', $order->get_id() );
