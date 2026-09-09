<?php
/**
 * Ported from frontend/src/pages/RefundCancellationPolicy.tsx — a single
 * admin-editable HTML block (DRC_Settings::get('refund_cancellation_policy_html'),
 * edited via the Settings screen's HTML_SETTINGS wp_kses_post() field) with
 * the same generic fallback copy React falls back to when the setting is
 * empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_settings = dreamrugs_settings();
$content      = $drc_settings['refund_cancellation_policy_html'] ?: '<h2>Cancellation</h2><p>Please contact us as soon as possible if you need to cancel an order. Eligibility and any refund depend on the order\'s production status.</p><h2>Refunds</h2><p>Approved refunds are returned to the original payment method. Custom-made rugs may not be refundable after production has started.</p>';

get_header();
?>

<main class="w-[94vw] max-w-4xl mx-auto px-4 py-16">
	<p class="storefront-eyebrow text-stone-500">Customer Care</p>
	<h1 class="storefront-heading text-4xl text-stone-900 mt-2 mb-10">Refund &amp; Cancellation Policy</h1>
	<div class="prose-content text-stone-600"><?php echo wp_kses_post( $content ); ?></div>
</main>

<?php get_footer(); ?>
