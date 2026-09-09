<?php
/**
 * Ported from frontend/src/pages/AboutUs.tsx. Every section's copy/images
 * now come from DRC_Settings::get('about_page') (merged over
 * dreamrugs_about_page_defaults() — see inc/about-page-content.php), edited
 * via the Settings screen's existing "About Page: Structured Content"
 * JSON field — this replaces an earlier version of this template that
 * hardcoded every section directly in PHP with no admin control at all.
 * The "About Us Content" rich-text field (about_us_content_html) still
 * separately drives the Story section's body copy, matching the original.
 * Only the workshop photo rotation and contact details are additionally
 * data-driven (DRC_Content_Types::get_workshop_photos() / DRC_Settings).
 * No interactivity needed, so no Alpine on this template.
 *
 * WordPress picks this template automatically for a Page with the slug
 * "about" (page-about.php in the template hierarchy).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_settings    = dreamrugs_settings();
$workshop_photos = DRC_Content_Types::get_workshop_photos();
$business_name   = get_bloginfo( 'name' ) ?: 'Our Workshop';
$about           = dreamrugs_get_about_page( is_array( $drc_settings['about_page'] ?? null ) ? $drc_settings['about_page'] : array() );
$sub             = fn( string $text ) => dreamrugs_about_sub( $text, $business_name );

$fallback_photos = array(
	get_stylesheet_directory_uri() . '/assets/workshop/workshop-hand-weaving.jpg',
	get_stylesheet_directory_uri() . '/assets/workshop/workshop-warping-the-loom.jpg',
	get_stylesheet_directory_uri() . '/assets/workshop/workshop-raw-fibre-loom.jpg',
	get_stylesheet_directory_uri() . '/assets/workshop/workshop-hand-knotting-detail.jpg',
	get_stylesheet_directory_uri() . '/assets/workshop/workshop-braiding-by-hand.jpg',
	get_stylesheet_directory_uri() . '/assets/workshop/workshop-finished-piece.jpg',
);
$photo = function ( int $index ) use ( $workshop_photos, $fallback_photos ) {
	if ( ! empty( $workshop_photos ) ) {
		return $workshop_photos[ $index % count( $workshop_photos ) ]['image_url'];
	}
	return $fallback_photos[ $index % count( $fallback_photos ) ];
};
$caption = function ( int $index, string $fallback ) use ( $workshop_photos ) {
	if ( ! empty( $workshop_photos ) ) {
		return $workshop_photos[ $index % count( $workshop_photos ) ]['caption'] ?: $fallback;
	}
	return $fallback;
};

// Story images: admin-set `story.images` (up to 10) take priority; otherwise
// primary/secondary + workshop photos + stock fallbacks, deduped by src and
// capped at 4 — mirrors AboutUs.tsx's storyImages computation exactly.
$story = $about['story'];
if ( ! empty( $story['images'] ) && is_array( $story['images'] ) ) {
	$story_images = array_map( fn( $img ) => array( 'src' => $img['image_url'], 'alt' => $img['image_alt'] ), array_slice( $story['images'], 0, 10 ) );
} else {
	$story_images = array_merge(
		array(
			array( 'src' => $story['primary_image_url'] ?: $photo( 1 ), 'alt' => $story['primary_image_alt'] ?: $caption( 1, 'The rug-making workshop' ) ),
			array( 'src' => $story['secondary_image_url'] ?: $photo( 2 ), 'alt' => $story['secondary_image_alt'] ?: $caption( 2, 'Natural fibres prepared for weaving' ) ),
		),
		array_map( fn( $p ) => array( 'src' => $p['image_url'], 'alt' => $p['caption'] ?: 'Inside the rug workshop' ), $workshop_photos ),
		array_map( fn( $src ) => array( 'src' => $src, 'alt' => 'Rug craftsmanship and materials' ), $fallback_photos )
	);
	$seen = array();
	$story_images = array_values( array_filter( $story_images, function ( $img ) use ( &$seen ) {
		if ( isset( $seen[ $img['src'] ] ) ) {
			return false;
		}
		$seen[ $img['src'] ] = true;
		return true;
	} ) );
	$story_images = array_slice( $story_images, 0, 4 );
}
$story_height = max( 900, count( $story_images ) * 220 );

get_header();
?>

<?php if ( $about['hero']['enabled'] ) : ?>
<section class="relative min-h-[560px] md:min-h-[640px] overflow-hidden flex items-end">
	<img src="<?php echo esc_url( $about['hero']['image_url'] ?: ( get_stylesheet_directory_uri() . '/assets/about-rug-living-room.png' ) ); ?>" alt="<?php echo esc_attr( $sub( $about['hero']['image_alt'] ) ); ?>" class="absolute inset-0 w-full h-full object-cover" />
	<div class="absolute inset-0 bg-gradient-to-r from-stone-950/50 via-stone-950/20 to-transparent"></div>
	<div class="absolute inset-0 bg-gradient-to-t from-stone-950/40 via-transparent to-transparent"></div>
	<div class="relative w-[94vw] max-w-none mx-auto px-4 pb-14 sm:pb-20">
		<div class="max-w-3xl">
			<div class="flex items-center gap-3 mb-5">
				<span class="block w-10 h-px bg-white/50"></span>
				<p class="text-[11px] tracking-[0.3em] uppercase text-stone-200"><?php echo esc_html( $sub( $about['hero']['eyebrow'] ) ); ?></p>
			</div>
			<h1 class="font-serif font-light text-white text-4xl md:text-5xl lg:text-6xl leading-[1.05] tracking-tight whitespace-pre-line"><?php echo esc_html( $sub( $about['hero']['heading'] ) ); ?></h1>
			<p class="mt-7 text-stone-200 text-base sm:text-lg leading-relaxed max-w-2xl"><?php echo esc_html( $sub( $about['hero']['body'] ) ); ?></p>
			<?php if ( $about['hero']['cta_label'] ) : ?>
				<a href="<?php echo esc_url( home_url( '/custom-rug-request' ) ); ?>" class="mt-9 inline-flex items-center gap-3 border border-white/50 px-6 py-3.5 text-white text-xs tracking-[0.18em] uppercase hover:bg-white hover:text-stone-900 transition-colors">
					<?php echo esc_html( $sub( $about['hero']['cta_label'] ) ); ?> <?php dreamrugs_icon( 'arrow-right', 14 ); ?>
				</a>
			<?php endif; ?>
		</div>
	</div>
	<div class="hidden lg:flex absolute right-8 top-1/2 -translate-y-1/2 rotate-90 origin-center items-center gap-3 text-white/60">
		<span class="text-[10px] tracking-[0.35em] uppercase">From loom to living space</span>
		<span class="w-12 h-px bg-white/40"></span>
	</div>
</section>
<?php endif; ?>

<?php if ( $about['credentials']['enabled'] && ! empty( $about['credentials']['items'] ) ) : ?>
<section class="border-b border-stone-200 bg-white">
	<div class="w-[94vw] max-w-none mx-auto px-4 grid grid-cols-1 sm:grid-cols-3 divide-y sm:divide-y-0 sm:divide-x divide-stone-200">
		<?php foreach ( $about['credentials']['items'] as $row ) : ?>
			<div class="px-4 sm:px-6 lg:px-8 py-6">
				<p class="font-serif text-lg text-stone-900"><?php echo esc_html( $sub( $row['title'] ) ); ?></p>
				<p class="text-stone-400 text-xs mt-1"><?php echo esc_html( $sub( $row['subtitle'] ) ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( $story['enabled'] ) : ?>
<section class="w-[94vw] max-w-none mx-auto px-4 py-20 lg:py-24">
	<div class="grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-16 items-stretch">
		<div class="lg:col-span-6 relative" style="--story-height:<?php echo (int) $story_height; ?>px;">
			<div class="grid grid-cols-2 gap-5 lg:absolute lg:inset-0 lg:grid-cols-1 lg:auto-rows-fr lg:min-h-[var(--story-height)]">
				<?php foreach ( $story_images as $image ) : ?>
					<figure class="min-h-0 overflow-hidden bg-stone-100">
						<img src="<?php echo esc_url( $image['src'] ); ?>" alt="<?php echo esc_attr( $image['alt'] ); ?>" class="aspect-[4/3] h-full w-full object-cover lg:aspect-auto" loading="lazy" />
					</figure>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="lg:col-span-6 lg:col-start-7 lg:py-12">
			<p class="storefront-eyebrow mb-4"><?php echo esc_html( $sub( $story['eyebrow'] ) ); ?></p>
			<h2 class="storefront-heading text-4xl lg:text-5xl whitespace-pre-line"><?php echo esc_html( $sub( $story['heading'] ) ); ?></h2>
			<?php if ( ! empty( $drc_settings['about_us_content_html'] ) ) : ?>
				<div class="prose-content mt-8 text-stone-500 leading-relaxed"><?php echo wp_kses_post( $drc_settings['about_us_content_html'] ); ?></div>
			<?php else : ?>
				<div class="mt-8 space-y-5 text-stone-500 leading-relaxed">
					<p>We believe a rug should do more than complete a room. It should settle the architecture, soften the way a space feels and become more personal with time.</p>
					<p>Our workshop brings together inherited technique and a contemporary eye. Working directly with skilled makers allows us to protect the integrity of the craft while offering the freedom of custom scale, colour and material.</p>
				</div>
			<?php endif; ?>
			<?php if ( $story['quote'] ) : ?>
				<div class="mt-9 pt-7 border-t border-stone-200 flex gap-4">
					<?php dreamrugs_icon( 'gem', 20, 'text-stone-400 mt-1 flex-shrink-0' ); ?>
					<p class="font-serif text-xl sm:text-2xl text-stone-800 leading-snug">&ldquo;<?php echo esc_html( $sub( $story['quote'] ) ); ?>&rdquo;</p>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $about['process']['enabled'] && ! empty( $about['process']['steps'] ) ) : ?>
<section class="bg-cream-200 text-stone-900 py-20 lg:py-24 overflow-hidden">
	<div class="w-[94vw] max-w-none mx-auto px-4">
		<div class="grid grid-cols-1 lg:grid-cols-12 gap-10 items-end mb-14 lg:mb-16">
			<div class="lg:col-span-7">
				<p class="text-[11px] tracking-[0.3em] uppercase text-stone-500 mb-4"><?php echo esc_html( $sub( $about['process']['eyebrow'] ) ); ?></p>
				<h2 class="font-serif font-light text-4xl lg:text-5xl leading-[1.08] whitespace-pre-line"><?php echo esc_html( $sub( $about['process']['heading'] ) ); ?></h2>
			</div>
			<p class="lg:col-span-4 lg:col-start-9 text-stone-600 leading-relaxed"><?php echo esc_html( $sub( $about['process']['intro'] ) ); ?></p>
		</div>
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-10 lg:gap-0 border-t border-stone-300">
			<?php foreach ( $about['process']['steps'] as $index => $step ) : ?>
				<article class="group pt-8 pb-4 lg:pb-8 lg:px-7 lg:border-l first:border-l-0 first:pl-0 border-stone-300">
					<div class="aspect-[4/3] overflow-hidden mb-7 bg-stone-900">
						<img src="<?php echo esc_url( $step['image_url'] ?: $photo( $index + 2 ) ); ?>" alt="<?php echo esc_attr( $step['image_alt'] ?: $caption( $index + 2, $step['title'] ) ); ?>" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 group-hover:scale-[1.03] transition-all duration-700" loading="lazy" />
					</div>
					<p class="text-[10px] tracking-[0.25em] text-stone-500 mb-3"><?php echo esc_html( $step['number'] ); ?></p>
					<h3 class="font-serif text-2xl font-light mb-3"><?php echo esc_html( $sub( $step['title'] ) ); ?></h3>
					<p class="text-stone-600 text-sm leading-relaxed"><?php echo esc_html( $sub( $step['text'] ) ); ?></p>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $about['principles']['enabled'] && ! empty( $about['principles']['items'] ) ) : ?>
<section class="bg-stone-50 border-b border-stone-200 py-20 lg:py-24">
	<div class="w-[94vw] max-w-none mx-auto px-4">
		<div class="max-w-2xl mb-14 lg:mb-16">
			<p class="storefront-eyebrow mb-4"><?php echo esc_html( $sub( $about['principles']['eyebrow'] ) ); ?></p>
			<h2 class="storefront-heading text-4xl lg:text-5xl"><?php echo esc_html( $sub( $about['principles']['heading'] ) ); ?></h2>
			<p class="text-stone-500 leading-relaxed mt-5"><?php echo esc_html( $sub( $about['principles']['intro'] ) ); ?></p>
		</div>
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-px bg-stone-200 border border-stone-200">
			<?php foreach ( $about['principles']['items'] as $p ) : ?>
				<article class="bg-white p-7 lg:p-8 min-h-[250px] flex flex-col">
					<span class="w-11 h-11 rounded-full border border-stone-200 flex items-center justify-center text-stone-500"><?php dreamrugs_icon( $p['icon'] ?: 'hand', 18 ); ?></span>
					<div class="mt-auto pt-10">
						<h3 class="font-serif text-xl text-stone-900"><?php echo esc_html( $sub( $p['title'] ) ); ?></h3>
						<p class="text-stone-500 text-sm leading-relaxed mt-3"><?php echo esc_html( $sub( $p['text'] ) ); ?></p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $about['founder']['enabled'] ) : ?>
<section class="w-[94vw] max-w-none mx-auto px-4 py-20 lg:py-24">
	<div class="grid grid-cols-1 lg:grid-cols-2 gap-0 border border-stone-200">
		<div class="relative min-h-[460px] lg:min-h-[660px] overflow-hidden">
			<img src="<?php echo esc_url( $about['founder']['image_url'] ?: $photo( 5 ) ); ?>" alt="<?php echo esc_attr( $sub( $about['founder']['image_alt'] ) ); ?>" class="absolute inset-0 w-full h-full object-cover" loading="lazy" />
			<div class="absolute inset-0 bg-gradient-to-t from-stone-950/65 via-transparent to-transparent"></div>
			<div class="absolute left-7 bottom-7 text-white">
				<p class="text-[10px] tracking-[0.25em] uppercase text-white/70">Inside the workshop</p>
				<?php if ( $about['founder']['caption'] ) : ?><p class="font-serif text-2xl mt-1"><?php echo esc_html( $sub( $about['founder']['caption'] ) ); ?></p><?php endif; ?>
			</div>
		</div>
		<div class="bg-white p-8 sm:p-12 lg:p-16 xl:p-20 flex flex-col justify-center">
			<?php dreamrugs_icon( 'sparkles', 22, 'text-stone-400 mb-8' ); ?>
			<p class="storefront-eyebrow mb-4"><?php echo esc_html( $sub( $about['founder']['eyebrow'] ) ); ?></p>
			<h2 class="storefront-heading text-4xl lg:text-5xl"><?php echo esc_html( $sub( $about['founder']['heading'] ) ); ?></h2>
			<div class="mt-7 space-y-5 text-stone-500 leading-relaxed">
				<?php foreach ( dreamrugs_about_paragraphs( $about['founder']['body'], $business_name ) as $p ) : ?>
					<p><?php echo esc_html( $p ); ?></p>
				<?php endforeach; ?>
			</div>
			<?php if ( $about['founder']['name'] || $about['founder']['role'] ) : ?>
				<div class="mt-10 pt-7 border-t border-stone-200">
					<?php if ( $about['founder']['name'] ) : ?><p class="font-serif text-2xl text-stone-900"><?php echo esc_html( $sub( $about['founder']['name'] ) ); ?></p><?php endif; ?>
					<?php if ( $about['founder']['role'] ) : ?><p class="text-[10px] tracking-[0.25em] uppercase text-stone-400 mt-1"><?php echo esc_html( $sub( $about['founder']['role'] ) ); ?></p><?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $about['cta']['enabled'] ) : ?>
<section class="bg-cream-200 text-stone-900 py-20 lg:py-24">
	<div class="w-[94vw] max-w-none mx-auto px-4 grid grid-cols-1 lg:grid-cols-12 gap-12 lg:gap-0 items-center">
		<div class="lg:col-span-7 lg:pr-16">
			<div class="flex items-center gap-3 mb-4">
				<span class="w-8 h-px bg-stone-400 flex-shrink-0"></span>
				<p class="text-[10px] sm:text-[11px] leading-none tracking-[0.24em] sm:tracking-[0.3em] uppercase text-stone-500"><?php echo esc_html( $sub( $about['cta']['eyebrow'] ) ); ?></p>
			</div>
			<h2 class="font-serif font-light text-3xl sm:text-4xl lg:text-5xl leading-[1.08] tracking-tight max-w-2xl"><?php echo esc_html( $sub( $about['cta']['heading'] ) ); ?></h2>
			<p class="text-stone-600 leading-relaxed mt-6 max-w-xl"><?php echo esc_html( $sub( $about['cta']['body'] ) ); ?></p>
			<div class="flex flex-col sm:flex-row sm:flex-wrap gap-3 mt-9">
				<?php if ( $about['cta']['primary_label'] ) : ?>
					<a href="<?php echo esc_url( home_url( '/custom-rug-request' ) ); ?>" class="inline-flex justify-center items-center gap-3 bg-stone-900 text-white px-6 py-3.5 text-xs tracking-[0.18em] uppercase hover:bg-stone-800 transition-colors">
						<?php echo esc_html( $sub( $about['cta']['primary_label'] ) ); ?> <?php dreamrugs_icon( 'arrow-right', 14 ); ?>
					</a>
				<?php endif; ?>
				<?php if ( $about['cta']['secondary_label'] ) : ?>
					<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="inline-flex justify-center items-center gap-3 border border-stone-400 px-6 py-3.5 text-xs tracking-[0.18em] uppercase hover:border-stone-700 transition-colors">
						<?php echo esc_html( $sub( $about['cta']['secondary_label'] ) ); ?>
					</a>
				<?php endif; ?>
			</div>
		</div>

		<div class="lg:col-span-4 lg:col-start-9 border-t border-stone-300 pt-10 lg:border-t-0 lg:border-l lg:pt-0 lg:pl-12">
			<p class="font-serif text-2xl mb-8">Visit or speak with us</p>
			<div class="space-y-6">
				<div class="flex gap-4">
					<span class="w-9 h-9 rounded-full border border-stone-300 flex items-center justify-center text-stone-500 flex-shrink-0"><?php dreamrugs_icon( 'mail', 15 ); ?></span>
					<div class="min-w-0">
						<p class="text-[10px] tracking-[0.22em] uppercase text-stone-500 mb-1.5">Email</p>
						<div class="text-stone-600 text-sm leading-relaxed">
							<?php if ( ! empty( $drc_settings['contact_emails'] ) ) : ?>
								<?php foreach ( (array) $drc_settings['contact_emails'] as $email ) : ?>
									<a href="mailto:<?php echo esc_attr( $email ); ?>" class="block hover:text-stone-900 transition-colors break-all"><?php echo esc_html( $email ); ?></a>
								<?php endforeach; ?>
							<?php else : ?>
								<span>Details coming soon</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<div class="flex gap-4">
					<span class="w-9 h-9 rounded-full border border-stone-300 flex items-center justify-center text-stone-500 flex-shrink-0"><?php dreamrugs_icon( 'phone', 15 ); ?></span>
					<div class="min-w-0">
						<p class="text-[10px] tracking-[0.22em] uppercase text-stone-500 mb-1.5">Telephone &amp; WhatsApp</p>
						<div class="text-stone-600 text-sm leading-relaxed">
							<?php if ( ! empty( $drc_settings['contact_phones'] ) ) : ?>
								<?php foreach ( (array) $drc_settings['contact_phones'] as $phone ) : ?>
									<div class="flex flex-wrap items-center gap-x-3 gap-y-1">
										<a href="tel:<?php echo esc_attr( $phone ); ?>" class="hover:text-stone-900 transition-colors"><?php echo esc_html( $phone ); ?></a>
										<a href="https://wa.me/<?php echo esc_attr( preg_replace( '/\D/', '', $phone ) ); ?>" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1 text-xs text-stone-500 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'message-circle', 12 ); ?> WhatsApp</a>
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								<span>Details coming soon</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
				<div class="flex gap-4">
					<span class="w-9 h-9 rounded-full border border-stone-300 flex items-center justify-center text-stone-500 flex-shrink-0"><?php dreamrugs_icon( 'map-pin', 15 ); ?></span>
					<div class="min-w-0">
						<p class="text-[10px] tracking-[0.22em] uppercase text-stone-500 mb-1.5">Workshop</p>
						<div class="text-stone-600 text-sm leading-relaxed"><span class="whitespace-pre-line"><?php echo esc_html( $drc_settings['contact_address'] ?: 'Address coming soon' ); ?></span></div>
					</div>
				</div>
				<div class="flex gap-4">
					<span class="w-9 h-9 rounded-full border border-stone-300 flex items-center justify-center text-stone-500 flex-shrink-0"><?php dreamrugs_icon( 'clock', 15 ); ?></span>
					<div class="min-w-0">
						<p class="text-[10px] tracking-[0.22em] uppercase text-stone-500 mb-1.5">Hours</p>
						<div class="text-stone-600 text-sm leading-relaxed"><span><?php echo esc_html( $drc_settings['contact_hours'] ?: 'By appointment' ); ?></span></div>
					</div>
				</div>
			</div>
		</div>
	</div>
</section>
<?php endif; ?>

<?php get_footer(); ?>
