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

<?php
$drc_menu_label = function ( string $key, string $fallback ) {
	return class_exists( 'DRC_Storefront_Menu' ) ? DRC_Storefront_Menu::label( $key, $fallback ) : $fallback;
};
$drc_menu_shown = function ( string $key ) {
	return ! class_exists( 'DRC_Storefront_Menu' ) || DRC_Storefront_Menu::visible( $key );
};
$drc_footer_cols = array(
	'Shop'     => array_merge(
		array(
			array( '/catalog', 'All Rugs', get_permalink( wc_get_page_id( 'shop' ) ) ),
			array( '/moroccan-rugs', 'Moroccan Style Rugs', home_url( '/moroccan-rugs/' ) ),
		),
		array_map( function ( $t ) {
			return array( '/collections/material/' . $t->slug, $t->name, home_url( '/collections/material/' . $t->slug . '/' ) );
		}, ( function () { $t = get_terms( array( 'taxonomy' => 'material_type', 'hide_empty' => true ) ); return is_wp_error( $t ) ? array() : $t; } )() )
	),
	'Account'  => array(
		array( '/about', 'About Us', home_url( '/about/' ) ),
		array( '/stories', 'Design Stories', home_url( '/stories/' ) ),
		array( '/login', 'Sign In', dreamrugs_login_url( home_url( '/my-orders/' ) ) ),
		array( '/my-orders', 'My Orders', home_url( '/my-orders/' ) ),
		array( '/my-quotes', 'My Quotes', home_url( '/my-quotes/' ) ),
		array( '/refund-cancellation-policy', 'Refund & Cancellation', home_url( '/refund-cancellation-policy/' ) ),
		array( '/privacy-policy', 'Privacy Policy', home_url( '/privacy-policy/' ) ),
		array( '/admin/login', 'Staff Portal', admin_url() ),
	),
	'Services' => array(
		array( '/colour-matching', 'Colour Matching', home_url( '/colour-matching/' ) ),
		array( '/rug-size-guide', 'Rug Size Guide', home_url( '/rug-size-guide/' ) ),
		array( '/guides', 'Rug Buying Guides', home_url( '/guides/' ) ),
		array( '/trade-enquiry', 'Trade Enquiry', home_url( '/trade-enquiry/' ) ),
		array( '/rug-manufacturer-india', 'Trade & Wholesale', home_url( '/rug-manufacturer-india/' ) ),
		array( '/order-tracking', 'Order Tracking', home_url( '/order-tracking/' ) ),
	),
);
$drc_social     = dreamrugs_active_social_links();
$drc_ig_handle  = dreamrugs_instagram_handle();
$drc_brand_name = preg_replace( '/([a-z])([A-Z])/', '$1 $2', get_bloginfo( 'name' ) );
?>
<footer class="bg-stone-50 border-t border-stone-200">
	<div class="w-[94vw] max-w-none mx-auto px-4 py-16 grid grid-cols-1 md:grid-cols-3 xl:grid-cols-[1.2fr_0.7fr_0.7fr_0.75fr_1.45fr] gap-10">
		<div class="space-y-4">
			<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/footer-logo.png' ); ?>" alt="<?php bloginfo( 'name' ); ?>" class="w-[200px] h-auto" />
			<p class="font-serif text-stone-700 text-base leading-relaxed max-w-xs">
				Handcrafted custom rugs made to order from India's finest workshops. Every rug is unique, every size custom.
			</p>
			<p class="font-serif text-stone-500 text-sm">Made with care in India</p>
			<?php if ( ! empty( $drc_settings['catalog_pdf_url'] ) ) : ?>
				<a href="<?php echo esc_url( $drc_settings['catalog_pdf_url'] ); ?>" target="_blank" rel="noreferrer"
					class="inline-flex items-center gap-2 text-stone-700 hover:text-stone-900 text-sm border-b border-stone-300 hover:border-stone-900 pb-0.5 transition-colors">
					<?php dreamrugs_icon( 'download', 14 ); ?> Download Lookbook
				</a>
			<?php endif; ?>
		</div>

		<?php foreach ( $drc_footer_cols as $heading => $links ) : ?>
		<div class="space-y-4">
			<p class="text-stone-900 text-xs font-semibold uppercase tracking-widest"><?php echo esc_html( $heading ); ?></p>
			<div class="space-y-2.5">
				<?php foreach ( $links as $l ) : if ( ! $drc_menu_shown( 'link:' . $l[0] ) ) { continue; } ?>
					<a href="<?php echo esc_url( $l[2] ); ?>" class="block text-stone-500 hover:text-stone-900 text-sm transition-colors"><?php echo esc_html( $drc_menu_label( 'link:' . $l[0], $l[1] ) ); ?></a>
				<?php endforeach; ?>
			</div>
		</div>
		<?php endforeach; ?>

		<div class="space-y-4 md:col-span-2 xl:col-span-1 md:border-l md:border-stone-300 md:pl-10" x-data="{ email: '', status: 'idle' }">
			<p class="storefront-heading text-2xl">Let us inspire you</p>
			<p class="text-stone-500 text-sm leading-relaxed">New collections, workshop stories, and offers — straight to your inbox.</p>
			<template x-if="status === 'done'">
				<p class="flex items-center gap-1.5 text-stone-600 text-sm"><?php dreamrugs_icon( 'check', 14 ); ?> Thanks — you're subscribed.</p>
			</template>
			<form x-show="status !== 'done'" class="flex gap-0" data-drc-bot-form @submit.prevent="
				status = 'submitting';
				fetch('<?php echo esc_url( rest_url( 'dreamrugs/v1/newsletter-subscribe' ) ); ?>', {
					method: 'POST', headers: Object.assign({'Content-Type':'application/json'}, window.drcBot ? window.drcBot.headers($el) : {}),
					body: JSON.stringify(Object.assign({ email, source: 'homepage_footer' }, window.drcBot ? window.drcBot.fields($el) : {}))
				}).then(r => r.ok ? status = 'done' : status = 'error').catch(() => status = 'error');
			">
				<input type="email" required x-model="email" placeholder="Your email"
					class="flex-1 min-w-0 bg-white border border-stone-300 focus:border-stone-500 px-3 py-2.5 text-stone-900 placeholder-stone-400 text-sm focus:outline-none transition-colors" />
				<button type="submit" :disabled="status === 'submitting'" aria-label="Subscribe" class="px-5 flex-shrink-0 bg-[#85501b] hover:bg-[#6f4216] disabled:bg-stone-200 disabled:text-stone-400 text-white transition-colors"><?php dreamrugs_icon( 'send', 14 ); ?></button>
				<?php if ( function_exists( 'dreamrugs_bot_fields' ) ) { dreamrugs_bot_fields(); } ?>
			</form>
			<p x-show="status === 'error'" x-cloak class="text-red-500 text-xs mt-1.5">Something went wrong — please try again.</p>

			<?php if ( $drc_social ) : ?>
				<div class="border-t border-stone-300 pt-6 mt-6">
					<p class="text-stone-900 text-xs font-semibold uppercase tracking-[0.2em]">Follow <?php echo esc_html( $drc_brand_name ); ?></p>
					<p class="text-stone-500 text-sm mt-1.5">Behind the craft. New collections. Finished spaces.</p>
					<div class="mt-5 grid max-w-md grid-cols-4 gap-y-5">
						<?php foreach ( $drc_social as $platform ) : ?>
							<a href="<?php echo esc_url( $platform['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="group/social flex flex-col items-center gap-2">
								<span class="flex h-14 w-14 items-center justify-center rounded-full bg-[#efe4d6] text-[#85501b] transition-colors group-hover/social:bg-[#85501b] group-hover/social:text-white"><?php dreamrugs_social_svg( $platform['path'], 24 ); ?></span>
								<span class="font-serif text-sm text-stone-700"><?php echo esc_html( $platform['label'] ); ?></span>
							</a>
						<?php endforeach; ?>
					</div>
					<?php if ( $drc_ig_handle ) : ?>
						<div class="mt-5 flex items-center gap-4">
							<span class="h-px flex-1 bg-stone-300"></span>
							<a href="<?php echo esc_url( $drc_social['instagram']['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="font-serif text-lg tracking-wide text-[#85501b] hover:text-[#6f4216]"><?php echo esc_html( $drc_ig_handle ); ?></a>
							<span class="h-px flex-1 bg-stone-300"></span>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( ! empty( $drc_settings['contact_emails'] ) || ! empty( $drc_settings['contact_phones'] ) || ! empty( $drc_settings['contact_address'] ) ) : ?>
	<div class="border-t border-stone-200 py-6">
		<div class="w-[94vw] max-w-none mx-auto px-4 flex flex-col md:flex-row md:flex-wrap items-center justify-center gap-y-3 md:divide-x md:divide-stone-300 text-[15px]">
			<?php foreach ( (array) $drc_settings['contact_emails'] as $email ) : ?>
				<a href="mailto:<?php echo esc_attr( $email ); ?>" class="flex items-center gap-3 md:px-8 text-stone-700 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'mail', 18, 'flex-shrink-0 text-[#85501b]' ); ?> <?php echo esc_html( $email ); ?></a>
			<?php endforeach; ?>
			<?php foreach ( (array) $drc_settings['contact_phones'] as $phone ) : ?>
				<a href="tel:<?php echo esc_attr( $phone ); ?>" class="flex items-center gap-3 md:px-8 text-stone-700 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'phone', 18, 'flex-shrink-0 text-[#85501b]' ); ?> <?php echo esc_html( $phone ); ?></a>
				<a href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D/', '', $phone ) ); ?>" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 md:px-8 text-green-700 hover:text-green-800 transition-colors"><?php dreamrugs_social_svg( dreamrugs_whatsapp_icon_path(), 18, 'flex-shrink-0 text-green-600' ); ?> Chat</a>
			<?php endforeach; ?>
			<?php if ( ! empty( $drc_settings['contact_address'] ) ) : ?>
				<p class="flex items-center gap-3 md:px-8 text-stone-700"><?php dreamrugs_icon( 'map-pin', 18, 'flex-shrink-0 text-[#85501b]' ); ?> <?php echo esc_html( $drc_settings['contact_address'] ); ?></p>
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

<?php get_template_part( 'template-parts/scroll-to-top-rug' ); ?>

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
