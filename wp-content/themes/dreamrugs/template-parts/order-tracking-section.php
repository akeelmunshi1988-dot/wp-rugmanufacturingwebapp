<?php
/**
 * Shared with page-order-tracking.php (standalone=true, full page) and
 * footer.php (standalone=false, the condensed teaser shown above the footer
 * on every OTHER page) — ported from OrderTrackingSection.tsx, which
 * React's CustomerLayout.tsx renders unconditionally on every page the same
 * way, only swapping the heading tag and (implicitly, via CSS) surrounding
 * page chrome depending on whether the visitor is actually on /order-tracking.
 *
 * Expects one variable from the including template:
 * @var bool $standalone
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_ots_settings = dreamrugs_settings();
$drc_ots_eyebrow  = $drc_ots_settings['order_tracking_eyebrow'] ?: 'Order visibility';
$drc_ots_heading  = $drc_ots_settings['order_tracking_heading'] ?: 'Follow your rug after dispatch.';
$drc_ots_body     = $drc_ots_settings['order_tracking_body'] ?: 'Once your finished rug has passed final inspection and is collected for shipment, we send the tracking number and tracking link by email or WhatsApp. You can then follow the order until delivery.';
$drc_ots_carriers = $drc_ots_settings['order_tracking_carriers'] ?: array();
$drc_ots_steps    = $drc_ots_settings['order_tracking_steps'] ?: array(
	array( 'title' => 'Final inspection', 'description' => 'Your rug is checked, rolled and securely packed.' ),
	array( 'title' => 'Carrier collection', 'description' => 'The carrier collects the completed shipment.' ),
	array( 'title' => 'Tracking sent', 'description' => 'We share the tracking number and live tracking link with you.' ),
);
$drc_ots_contact_email = $drc_ots_settings['contact_emails'][0] ?? '';
$drc_ots_heading_tag   = $standalone ? 'h1' : 'h2';
?>
<section class="bg-[#20221c] px-[5vw] py-20 text-[#f7f5ef] lg:py-28">
	<div class="grid items-start gap-14 lg:grid-cols-2 lg:gap-[8vw]">
		<div>
			<p class="mb-6 text-[10px] uppercase tracking-[0.24em] text-[#c3a27c]"><?php echo esc_html( $drc_ots_eyebrow ); ?></p>
			<?php printf( '<%1$s class="font-serif text-4xl leading-tight md:text-5xl">%2$s</%1$s>', esc_attr( $drc_ots_heading_tag ), esc_html( $drc_ots_heading ) ); ?>
			<div class="mt-8 max-w-lg space-y-6 text-sm leading-7 text-white/80">
				<p><?php echo esc_html( $drc_ots_body ); ?></p>
				<?php if ( ! empty( $drc_ots_settings['order_tracking_shipping_policy_url'] ) ) : ?>
					<a href="<?php echo esc_url( $drc_ots_settings['order_tracking_shipping_policy_url'] ); ?>" target="_blank" rel="noreferrer" class="inline-flex border-b border-white pb-2 text-xs uppercase tracking-wider">Read Our Shipping Policy ↗</a>
				<?php endif; ?>
				<a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="inline-flex border-b border-white pb-2 text-xs uppercase tracking-wider">View my orders ↗</a>
				<p class="text-xs">Need help locating a shipment? Include your order number when contacting the studio.</p>
				<?php if ( $drc_ots_contact_email ) : ?>
					<a href="mailto:<?php echo esc_attr( $drc_ots_contact_email ); ?>?subject=Order%20tracking%20help" class="inline-block border-b border-white text-sm">Contact the studio ↗</a>
				<?php endif; ?>
				<?php if ( ! empty( $drc_ots_settings['order_tracking_media_url'] ) ) : ?>
					<?php if ( 'video' === $drc_ots_settings['order_tracking_media_type'] ) : ?>
						<video src="<?php echo esc_url( $drc_ots_settings['order_tracking_media_url'] ); ?>" class="mt-4 aspect-video w-full max-w-md object-cover" controls></video>
					<?php else : ?>
						<img src="<?php echo esc_url( $drc_ots_settings['order_tracking_media_url'] ); ?>" alt="" class="mt-4 aspect-video w-full max-w-md object-cover" />
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<div class="bg-[#f0ece3] p-8 text-[#20221c] md:p-12">
			<?php if ( ! empty( $drc_ots_carriers ) ) : ?>
				<div class="flex flex-wrap items-center justify-center gap-6 pb-8 border-b border-stone-300">
					<?php foreach ( $drc_ots_carriers as $i => $carrier ) : ?>
						<?php if ( $i > 0 ) : ?><span class="text-xs text-stone-400">or</span><?php endif; ?>
						<img src="<?php echo esc_url( $carrier['image_url'] ); ?>" alt="<?php echo esc_attr( $carrier['label'] ); ?>" class="h-20 w-auto max-w-[220px] object-contain" />
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<?php foreach ( $drc_ots_steps as $i => $step ) : ?>
				<div class="flex gap-5 border-t border-stone-300 py-6 first:border-t-0">
					<span class="storefront-eyebrow pt-1">0<?php echo esc_html( $i + 1 ); ?></span>
					<div>
						<h2 class="storefront-heading text-xl"><?php echo esc_html( $step['title'] ); ?></h2>
						<p class="mt-2 text-xs leading-6 text-stone-600"><?php echo esc_html( $step['description'] ); ?></p>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
