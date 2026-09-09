<?php
/**
 * Ported from frontend/src/pages/PrivacyPolicy.tsx — a single admin-editable
 * HTML block (DRC_Settings::get('privacy_policy_html'), edited via the
 * Settings screen's HTML_SETTINGS wp_kses_post() field) with the same
 * generic fallback copy React falls back to when the setting is empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_settings = dreamrugs_settings();
$content      = $drc_settings['privacy_policy_html'] ?: '<h2>Information we collect</h2><p>We collect information you provide when requesting a quote, placing an order, creating an account, or contacting us.</p><h2>How we use information</h2><p>We use this information to fulfil orders, provide customer support, improve our services, and meet legal obligations.</p><h2>Payments and service providers</h2><p>Payments and certain operational services may be handled by trusted providers under their own privacy practices. We do not sell your personal information.</p><h2>Your choices</h2><p>You may contact us to ask about, correct, or request deletion of your personal information, subject to applicable legal and record-keeping requirements.</p>';

get_header();
?>

<main class="w-[94vw] max-w-4xl mx-auto px-4 py-16">
	<p class="storefront-eyebrow text-stone-500">Your Privacy</p>
	<h1 class="storefront-heading text-4xl text-stone-900 mt-2 mb-10">Privacy Policy</h1>
	<div class="prose-content text-stone-600"><?php echo wp_kses_post( $content ); ?></div>
</main>

<?php get_footer(); ?>
