<?php
/**
 * Ported from CustomerLayout.tsx (footer portion). Newsletter submit posts
 * to a small REST route (registered below) instead of the original app's
 * /api/customer/newsletter-subscribe — same NewsletterSubscriber-shaped
 * data, just landing in a WP custom table via dreamrugs-core (Phase 7).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_settings = dreamrugs_settings();
?>
</main>

<?php if ( ! is_page( 'trade-enquiry' ) ) : ?>
	<section class="mt-24 bg-[#e8e2d6] px-[5vw] py-14 text-[#20221c] md:py-20" aria-labelledby="footer-trade-heading">
		<div class="grid items-center gap-8 lg:grid-cols-[1.3fr_1fr] lg:gap-16">
			<div>
				<p class="mb-4 text-[10px] uppercase tracking-[0.24em] text-[#935d43]">For design and trade professionals</p>
				<h2 id="footer-trade-heading" class="font-serif text-4xl leading-tight md:text-5xl">Your next project starts here.</h2>
				<p class="mt-5 max-w-2xl text-sm leading-7 text-stone-600">Planning rugs for an interior, a retail collection or a hospitality space? Tell us about your practice, quantities, materials and timeline, and discuss your brief with our studio.</p>
			</div>
			<div class="lg:justify-self-end">
				<a href="<?php echo esc_url( home_url( '/trade-enquiry' ) ); ?>" class="inline-flex items-center justify-center bg-[#20221c] px-7 py-4 text-xs font-semibold uppercase tracking-[0.15em] text-white hover:bg-[#414436] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4">Trade enquiry ↗</a>
			</div>
		</div>
	</section>
<?php endif; ?>

<?php if ( ! is_page( 'order-tracking' ) ) : ?>
	<?php $standalone = false; require get_theme_file_path( 'template-parts/order-tracking-section.php' ); ?>
<?php endif; ?>

<footer class="bg-stone-50 border-t border-stone-200 mt-24">
	<div class="w-[94vw] max-w-none mx-auto px-4 py-16 grid grid-cols-1 md:grid-cols-[1.2fr_0.7fr_0.7fr_0.75fr_1.45fr] gap-10">
		<div class="space-y-4">
			<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/footer-logo.png' ); ?>" alt="<?php bloginfo( 'name' ); ?>" class="w-[200px] h-auto" />
			<p class="text-stone-500 text-sm leading-relaxed max-w-xs">
				Handcrafted custom rugs made to order by skilled artisans. Every rug is unique, every size custom.
			</p>
			<p class="text-stone-400 text-xs">Made with care, start to finish</p>
			<?php if ( ! empty( $drc_settings['catalog_pdf_url'] ) ) : ?>
				<a href="<?php echo esc_url( $drc_settings['catalog_pdf_url'] ); ?>" target="_blank" rel="noreferrer"
					class="inline-flex items-center gap-2 text-stone-700 hover:text-stone-900 text-sm border-b border-stone-300 hover:border-stone-900 pb-0.5 transition-colors">
					<?php dreamrugs_icon( 'download', 14 ); ?> Download Lookbook
				</a>
			<?php endif; ?>
		</div>

		<div class="space-y-4">
			<p class="text-stone-900 text-xs font-semibold uppercase tracking-widest">Shop</p>
			<div class="space-y-2.5">
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">All Rugs</a>
				<?php
				// Every material_type term in use, not a fixed Wool/Silk/Cotton
				// guess — mirrors CustomerLayout.tsx's footer fetching from the
				// Material master table rather than hardcoding a short list.
				$footer_material_terms = get_terms( array( 'taxonomy' => 'material_type', 'hide_empty' => true ) );
				foreach ( ( is_wp_error( $footer_material_terms ) ? array() : $footer_material_terms ) as $term ) :
					?>
					<a href="<?php echo esc_url( home_url( '/catalog/material/' . $term->slug ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors"><?php echo esc_html( $term->name ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>

		<div class="space-y-4">
			<p class="text-stone-900 text-xs font-semibold uppercase tracking-widest">Account</p>
			<div class="space-y-2.5">
				<a href="<?php echo esc_url( home_url( '/about' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">About Us</a>
				<a href="<?php echo esc_url( wp_login_url() ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Sign In</a>
				<a href="<?php echo esc_url( home_url( '/my-orders' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">My Orders</a>
				<a href="<?php echo esc_url( home_url( '/my-quotes' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">My Quotes</a>
				<a href="<?php echo esc_url( home_url( '/refund-cancellation-policy' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Refund &amp; Cancellation</a>
				<a href="<?php echo esc_url( home_url( '/privacy-policy' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Privacy Policy</a>
				<a href="<?php echo esc_url( admin_url() ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Staff Portal</a>
			</div>
		</div>

		<div class="space-y-4">
			<p class="text-stone-900 text-xs font-semibold uppercase tracking-widest">Services</p>
			<div class="space-y-2.5">
				<a href="<?php echo esc_url( home_url( '/colour-matching' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Colour Matching</a>
				<a href="<?php echo esc_url( home_url( '/rug-size-guide' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Rug Size Guide</a>
				<a href="<?php echo esc_url( home_url( '/trade-enquiry' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Trade Enquiry</a>
				<a href="<?php echo esc_url( home_url( '/order-tracking' ) ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors">Order Tracking</a>
			</div>
		</div>

		<div class="space-y-4" x-data="{ email: '', status: 'idle' }">
			<p class="storefront-heading text-2xl">Let us inspire you</p>
			<p class="text-stone-500 text-sm leading-relaxed">New collections, workshop stories, and offers — straight to your inbox.</p>
			<template x-if="status === 'done'">
				<p class="flex items-center gap-1.5 text-stone-600 text-sm"><?php dreamrugs_icon( 'check', 14 ); ?> Thanks — you're subscribed.</p>
			</template>
			<form x-show="status !== 'done'" class="flex gap-0" @submit.prevent="
				status = 'submitting';
				fetch('<?php echo esc_url( rest_url( 'dreamrugs/v1/newsletter-subscribe' ) ); ?>', {
					method: 'POST', headers: {'Content-Type':'application/json'},
					body: JSON.stringify({ email, source: 'homepage_footer' })
				}).then(r => r.ok ? status = 'done' : status = 'error').catch(() => status = 'error');
			">
				<input type="email" required x-model="email" placeholder="Your email"
					class="flex-1 min-w-0 bg-white border border-stone-300 focus:border-stone-500 px-3 py-2.5 text-stone-900 placeholder-stone-400 text-sm focus:outline-none transition-colors" />
				<button type="submit" :disabled="status === 'submitting'" class="storefront-cta-solid px-4 flex-shrink-0"><?php dreamrugs_icon( 'send', 14 ); ?></button>
			</form>
			<p x-show="status === 'error'" x-cloak class="text-red-500 text-xs mt-1.5">Something went wrong — please try again.</p>
		</div>
	</div>

	<?php if ( ! empty( $drc_settings['contact_emails'] ) || ! empty( $drc_settings['contact_phones'] ) || ! empty( $drc_settings['contact_address'] ) ) : ?>
	<div class="border-t border-stone-200 py-8">
		<div class="w-[94vw] max-w-none mx-auto px-4 flex flex-wrap items-center justify-center gap-x-10 gap-y-3 text-sm">
			<?php foreach ( (array) $drc_settings['contact_emails'] as $email ) : ?>
				<a href="mailto:<?php echo esc_attr( $email ); ?>" class="flex items-center gap-2 text-stone-500 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'mail', 14 ); ?> <?php echo esc_html( $email ); ?></a>
			<?php endforeach; ?>
			<?php foreach ( (array) $drc_settings['contact_phones'] as $phone ) : ?>
				<div class="flex items-center gap-3">
					<a href="tel:<?php echo esc_attr( $phone ); ?>" class="flex items-center gap-2 text-stone-500 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'phone', 14 ); ?> <?php echo esc_html( $phone ); ?></a>
					<a href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D/', '', $phone ) ); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-green-600 hover:text-green-700 transition-colors"><?php dreamrugs_icon( 'message-circle', 14 ); ?> Chat</a>
				</div>
			<?php endforeach; ?>
			<?php if ( ! empty( $drc_settings['contact_address'] ) ) : ?>
				<p class="flex items-center gap-2 text-stone-500"><?php dreamrugs_icon( 'map-pin', 14 ); ?> <?php echo esc_html( $drc_settings['contact_address'] ); ?></p>
			<?php endif; ?>
		</div>
	</div>
	<?php endif; ?>

	<?php if ( ! empty( $drc_settings['certifications'] ) ) : ?>
	<div class="border-t border-stone-200 py-8">
		<div class="w-[94vw] max-w-none mx-auto px-4 flex flex-wrap items-center justify-center gap-x-10 gap-y-4">
			<?php foreach ( (array) $drc_settings['certifications'] as $c ) : ?>
				<div class="flex items-center gap-2">
					<img src="<?php echo esc_url( $c['image_url'] ); ?>" alt="<?php echo esc_attr( $c['label'] ); ?>" class="w-8 h-8 object-contain" />
					<span class="text-stone-500 text-xs uppercase tracking-wider"><?php echo esc_html( $c['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>

	<div class="border-t border-stone-200 py-5 text-center">
		<p class="text-stone-400 text-xs tracking-wide">© <?php echo esc_html( gmdate( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?> · UPI · Cards · Net Banking</p>
	</div>
</footer>

<div x-show="showCookieBanner" x-cloak class="fixed inset-x-0 bottom-0 z-50 bg-stone-900 border-t border-stone-700">
	<div class="w-[94vw] max-w-none mx-auto px-4 py-4 flex flex-col sm:flex-row items-center gap-4">
		<p class="flex-1 text-stone-300 text-sm text-center sm:text-left">We use cookies to keep you signed in, remember your cart, and understand how the site is used.</p>
		<div class="flex items-center gap-3 flex-shrink-0">
			<button @click="localStorage.setItem('cookie_consent','declined'); showCookieBanner = false" class="text-stone-400 hover:text-white text-sm transition-colors">Decline</button>
			<button @click="localStorage.setItem('cookie_consent','accepted'); showCookieBanner = false" class="bg-white hover:bg-stone-200 text-stone-900 text-sm font-medium px-5 py-2 transition-colors">Accept</button>
		</div>
	</div>
</div>

<?php wp_footer(); ?>
</body>
</html>
