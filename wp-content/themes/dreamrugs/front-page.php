<?php
/**
 * Ported from frontend/src/pages/CustomerHome.tsx. Section-by-section
 * mapping:
 * - Hero/stat-strip/trust-bar/why-us/journey/shop-tabs: static markup,
 *   direct port, settings come from DRC_Settings instead of a client fetch.
 * - Our Craft, Behind the Craft, Testimonials, Project Gallery, Inside the
 *   Workshop: now backed by the drc_showcase_video / drc_testimonial /
 *   drc_gallery_item / drc_workshop_photo CPTs (DRC_Content_Types) instead
 *   of the original REST endpoints.
 * - Latest Trending Rug Designs: WooCommerce products via wc_get_products().
 * - SHOW_FEATURED_RUGS was `false` in the original (dead code path) — that
 *   section is omitted here entirely, same effective behavior.
 * - The "AI Consultant" section is dropped per the migration plan's AI
 *   feature scope decision (customer-facing chat widget).
 * - Slider/tab interactivity (testimonial pager, gallery pager, shop-by
 *   tabs, craft-video hover-to-play, intro-video auto-advance) uses Alpine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_settings   = dreamrugs_settings();
$hero_image     = ! empty( $drc_settings['hero_image_url'] )
	? $drc_settings['hero_image_url']
	: 'https://images.pexels.com/photos/28379848/pexels-photo-28379848.jpeg?auto=compress&cs=tinysrgb&w=1920';

$showcase_videos = DRC_Content_Types::get_showcase_videos();
$intro_videos    = array_values( array_filter( $showcase_videos, fn( $v ) => $v['is_intro'] ) );
if ( empty( $intro_videos ) && ! empty( $showcase_videos ) ) {
	$intro_videos = array( $showcase_videos[0] );
}
$intro_video_ids = array_column( $intro_videos, 'id' );
$grid_videos     = array_values( array_filter( $showcase_videos, fn( $v ) => ! in_array( $v['id'], $intro_video_ids, true ) ) );
// Groups grid_videos into named tabs (defaulting to "Craftsmanship") —
// mirrors CustomerHome.tsx's videoTabNames/visibleGridVideos.
$video_tab_names = array_values( array_unique( array_column( $grid_videos, 'tab_name' ) ) );

$workshop_photos = DRC_Content_Types::get_workshop_photos();
$testimonials    = DRC_Content_Types::get_testimonials();
$gallery_items   = DRC_Content_Types::get_gallery_items();

$craft_image_url    = ( $intro_videos[0]['poster_url'] ?? null ) ?: ( $workshop_photos[0]['image_url'] ?? null );
$show_craft_section = ! empty( $intro_videos ) && $drc_settings['homepage_intro_enabled'];

// "Trusted by" rating badges (Introduction section) — mirrors
// CustomerHome.tsx's ratedProjects/averageProjectRating/ratingCustomers.
$rated_projects          = array_values( array_filter( $gallery_items, fn( $p ) => null !== $p['rating'] ) );
$average_project_rating  = ! empty( $rated_projects ) ? array_sum( array_column( $rated_projects, 'rating' ) ) / count( $rated_projects ) : null;
$rating_customers        = array_slice( array_values( array_filter( $rated_projects, fn( $p ) => ! empty( trim( (string) $p['owner_name'] ) ) ) ), 0, 4 );
$rating_customer_colors  = array( 'bg-[#496a73]', 'bg-[#a15f46]', 'bg-[#947555]', 'bg-[#48567c]' );

$catalog_total    = wp_count_posts( 'product' )->publish ?? 0;
global $wpdb;
$materials_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}drc_materials WHERE is_available = 1" );

// Hero carousel — admin-set hero_images (each optionally overriding the
// global eyebrow/heading/CTA) take priority; otherwise a single slide from
// hero_image_url, matching CustomerHome.tsx's `slides` computation.
$hero_slides = ! empty( $drc_settings['hero_images'] ) ? $drc_settings['hero_images'] : array(
	array( 'image_url' => $hero_image, 'alt_text' => 'Handcrafted rug' ),
);
$trending_ids     = array_map( 'intval', $drc_settings['trending_product_ids'] ?? array() );
if ( ! empty( $trending_ids ) && function_exists( 'wc_get_products' ) ) {
	// Admin-curated order (Settings → Trending Rugs) — 'post__in' is the only
	// orderby that respects the include array's own ordering rather than
	// re-sorting by date/title.
	$trending = wc_get_products( array( 'include' => $trending_ids, 'orderby' => 'post__in', 'status' => 'publish' ) );
} else {
	// No curation set — same newest-first fallback as before.
	$trending = function_exists( 'wc_get_products' ) ? wc_get_products( array( 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC', 'status' => 'publish' ) ) : array();
}

// Admin-editable via the "Rug Journey" post type (wp-admin); falls back to
// this default 8-step content until an admin adds steps of their own —
// mirrors the original app's CustomerHome.tsx HOW-array fallback behavior.
$journey_steps_raw = DRC_Content_Types::get_journey_steps();
if ( empty( $journey_steps_raw ) ) {
	$journey_steps_raw = array(
		array( 'title' => 'Buyer Request', 'description' => 'Share your vision, room dimensions, and style — our team scopes your custom rug request.' ),
		array( 'title' => 'CAD Approval', 'description' => 'A CAD rendering of your design is prepared and shared for sign-off before any material is touched.' ),
		array( 'title' => 'Material Dyeing', 'description' => 'Wool, silk, cotton, or synthetic fibres are dyed in-house to your approved colourway.' ),
		array( 'title' => 'Color Check', 'description' => 'Dyed yarn is matched against the approved palette for consistency before weaving begins.' ),
		array( 'title' => 'Weaving', 'description' => 'Master artisans hand-knot every rug on traditional looms, weeks or months in the making.' ),
		array( 'title' => 'Finishing, Washing & Stretching', 'description' => 'Each rug is trimmed, washed, and stretched to its final shape and pile.' ),
		array( 'title' => 'Quality Check', 'description' => 'Every piece is checked for weave density, accurate sizing, and dye consistency before it ships.' ),
		array( 'title' => 'Packaging & Delivery', 'description' => 'Packed and shipped worldwide, with export documentation handled for you door to door.' ),
	);
}
$how_steps = array_values( array_map( function ( $step, $i ) {
	return array(
		'n'    => str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ),
		'title' => $step['title'],
		'desc'  => $step['description'] ?? '',
	);
}, $journey_steps_raw, array_keys( $journey_steps_raw ) ) );
$trust_bar = array( 'Handmade', 'Custom Sizes', 'Worldwide Shipping', 'Family Workshop', 'Sustainable Materials' );
$room_types = array(
	array( 'v' => 'living_room', 'label' => 'Living Room', 'image' => 'shop-by-space/living_room.jpg' ),
	array( 'v' => 'bedroom', 'label' => 'Bedroom', 'image' => 'shop-by-space/bedroom.jpg' ),
	array( 'v' => 'dining_room', 'label' => 'Dining Room', 'image' => 'shop-by-space/dining_room.jpg' ),
	array( 'v' => 'entryway', 'label' => 'Entryway', 'image' => 'shop-by-space/entryway.jpg' ),
);
$material_textures = array(
	array( 'id' => 'wool', 'label' => 'Wool', 'desc' => 'Warm, durable, naturally stain-resistant', 'image' => 'materials/wool.jpg' ),
	array( 'id' => 'silk', 'label' => 'Silk', 'desc' => 'Lustrous, formal spaces, exceptional sheen', 'image' => 'materials/silk.jpg' ),
	array( 'id' => 'cotton', 'label' => 'Cotton', 'desc' => 'Casual, easy-care, vibrant colours', 'image' => 'materials/cotton.jpg' ),
	array( 'id' => 'synthetic', 'label' => 'Synthetic', 'desc' => 'Stain-proof, outdoor, budget-friendly', 'image' => 'materials/synthetic.jpg' ),
);
$mood_tags = array(
	array( 'v' => 'warm_earthy', 'label' => 'Warm & Earthy' ),
	array( 'v' => 'quiet_luxury', 'label' => 'Quiet Luxury' ),
	array( 'v' => 'modern_minimal', 'label' => 'Modern Minimal' ),
	array( 'v' => 'bohemian', 'label' => 'Bohemian' ),
	array( 'v' => 'bold_artistic', 'label' => 'Bold & Artistic' ),
	array( 'v' => 'timeless_traditional', 'label' => 'Timeless Traditional' ),
);

get_header();
?>

<section class="relative w-full overflow-hidden h-[calc(100svh-8rem)] min-h-[520px] md:h-[calc(100svh-9rem)] md:min-h-[640px] flex items-center justify-center"
	<?php if ( count( $hero_slides ) > 1 ) : ?>
	x-data="{ heroIndex: 0, total: <?php echo count( $hero_slides ); ?> }"
	x-init="setInterval(() => { heroIndex = (heroIndex + 1) % total }, 6000)"
	<?php endif; ?>>
	<div class="absolute inset-0">
		<?php foreach ( $hero_slides as $i => $slide ) : ?>
			<img src="<?php echo esc_url( $slide['image_url'] ); ?>" alt="<?php echo esc_attr( $slide['alt_text'] ?: 'Handcrafted rug' ); ?>"
				<?php if ( count( $hero_slides ) > 1 ) : ?>
					:aria-hidden="heroIndex !== <?php echo (int) $i; ?>"
					:class="heroIndex === <?php echo (int) $i; ?> ? 'opacity-100' : 'opacity-0'"
					class="absolute inset-0 w-full h-full object-cover transition-opacity duration-[900ms] ease-in-out motion-reduce:transition-none"
				<?php else : ?>
					class="absolute inset-0 w-full h-full object-cover"
				<?php endif; ?>
				fetchpriority="<?php echo 0 === $i ? 'high' : 'low'; ?>" loading="eager" decoding="async" />
		<?php endforeach; ?>
	</div>

	<div class="relative max-w-xl px-6 text-center space-y-5">
		<?php foreach ( $hero_slides as $i => $slide ) : ?>
			<div <?php echo count( $hero_slides ) > 1 ? 'x-show="heroIndex === ' . (int) $i . '"' : ''; ?> <?php echo $i > 0 ? 'x-cloak' : ''; ?> class="space-y-5">
				<p class="storefront-eyebrow text-white/80"><?php echo esc_html( ( $slide['eyebrow'] ?? '' ) ?: ( $drc_settings['hero_eyebrow'] ?: '20+ Years in the Making' ) ); ?></p>
				<h1 class="storefront-heading text-4xl md:text-5xl text-white [text-shadow:0_2px_24px_rgba(0,0,0,0.4)]">
					<?php
					if ( ! empty( $slide['headline'] ) ) {
						echo esc_html( $slide['headline'] );
					} elseif ( $drc_settings['hero_heading'] ) {
						echo esc_html( $drc_settings['hero_heading'] );
					} else {
						echo 'Made for <em class="font-normal not-italic">Timeless</em> Spaces.'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed fallback markup only.
					}
					?>
				</h1>
				<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="storefront-link-arrow text-white justify-center">
					<?php echo esc_html( ( $slide['button_text'] ?? '' ) ?: ( $drc_settings['hero_cta_label'] ?: 'Explore Collection' ) ); ?> <?php dreamrugs_icon( 'arrow-right', 14 ); ?>
				</a>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( count( $hero_slides ) > 1 ) : ?>
		<div class="absolute bottom-6 left-4 z-10 flex gap-2 md:left-8">
			<button type="button" aria-label="Previous hero image" @click="heroIndex = (heroIndex - 1 + total) % total" class="rounded-full bg-black/25 p-3 text-white hover:bg-black/45"><?php dreamrugs_icon( 'chevron-left', 22 ); ?></button>
			<button type="button" aria-label="Next hero image" @click="heroIndex = (heroIndex + 1) % total" class="rounded-full bg-black/25 p-3 text-white hover:bg-black/45"><?php dreamrugs_icon( 'chevron-right', 22 ); ?></button>
		</div>
		<div class="absolute bottom-6 inset-x-0 z-10 flex justify-center gap-2">
			<?php foreach ( $hero_slides as $i => $slide ) : ?>
				<button type="button" aria-label="Show hero image <?php echo (int) $i + 1; ?>" @click="heroIndex = <?php echo (int) $i; ?>"
					class="h-1.5 rounded-full transition-all" :class="heroIndex === <?php echo (int) $i; ?> ? 'w-8 bg-white' : 'w-2 bg-white/55'"></button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</section>

<div class="border-b border-stone-100">
	<div class="w-[94vw] max-w-none mx-auto px-4 py-6 flex flex-wrap gap-10 justify-center sm:justify-start">
		<?php foreach ( array( array( ( (int) $catalog_total ?: 8 ) . '+', 'Designs' ), array( (string) ( $materials_count ?: 4 ), 'Materials' ), array( '7–60', 'Day Delivery' ) ) as $s ) : ?>
			<div>
				<p class="font-serif text-2xl text-stone-900 font-light"><?php echo esc_html( $s[0] ); ?></p>
				<p class="text-stone-400 text-xs uppercase tracking-wider mt-0.5"><?php echo esc_html( $s[1] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>
</div>

<section class="border-y border-stone-100 bg-stone-50">
	<div class="w-[94vw] max-w-none mx-auto px-4 py-4 flex flex-wrap items-center justify-center gap-x-8 gap-y-2">
		<?php foreach ( $trust_bar as $i => $t ) : ?>
			<span class="text-stone-500 text-xs uppercase tracking-widest"><?php echo esc_html( $t ); ?></span>
			<?php if ( $i < count( $trust_bar ) - 1 ) : ?><span class="hidden sm:inline text-stone-300">·</span><?php endif; ?>
		<?php endforeach; ?>
	</div>
</section>

<?php if ( $show_craft_section ) : ?>
<section id="introduction" class="overflow-hidden bg-[#f3f1e8]" x-data="{ introIndex: 0 }">
	<div class="relative mx-auto flex w-[90vw] flex-col gap-10 py-16 lg:block lg:h-[min(920px,calc(100svh-80px))] lg:min-h-[800px] lg:py-0">

		<div class="relative order-1 aspect-[3/2] w-full overflow-hidden bg-stone-200 lg:absolute lg:left-0 lg:top-[6%] lg:h-[54%] lg:w-[54%]">
			<?php foreach ( $intro_videos as $i => $v ) : ?>
				<template x-if="introIndex === <?php echo (int) $i; ?>">
					<div class="absolute inset-0">
						<?php if ( $v['poster_url'] ) : ?>
							<img src="<?php echo esc_url( $v['poster_url'] ); ?>" alt="<?php echo esc_attr( $v['title'] ); ?>" class="absolute inset-0 w-full h-full object-cover" loading="lazy" />
						<?php endif; ?>
						<video class="absolute inset-0 w-full h-full object-cover" src="<?php echo esc_url( $v['video_url'] ); ?>"
							poster="<?php echo esc_url( $v['poster_url'] ); ?>" autoplay muted <?php echo count( $intro_videos ) <= 1 ? 'loop' : ''; ?> playsinline
							@ended="introIndex = (introIndex + 1) % <?php echo count( $intro_videos ); ?>"></video>
					</div>
				</template>
			<?php endforeach; ?>
		</div>

		<div class="order-2 lg:absolute lg:right-[2%] lg:top-[17%] lg:w-[36%]">
			<p class="text-xs font-semibold uppercase tracking-[0.2em] text-stone-500"><?php echo esc_html( $drc_settings['homepage_intro_label'] ?: 'Final Product' ); ?></p>
			<h2 class="mt-5 max-w-2xl font-serif text-[clamp(2rem,2.35vw,3rem)] font-light leading-[1.16] tracking-[-0.02em] text-[#262522]">
				<?php echo esc_html( $drc_settings['homepage_intro_description'] ?: 'Every rug that leaves our workshop passes through the hands of master weavers who have spent years perfecting their craft — hand-knotting, natural dyeing, and meticulous finishing, checked for weave density, accurate sizing, and colourfast dyes before anything ships.' ); ?>
			</h2>
			<?php $drc_intro_cta_label = $drc_settings['homepage_intro_cta_label'] ?: 'Explore Collection'; ?>
			<?php if ( $drc_intro_cta_label ) : ?>
				<a href="<?php echo esc_url( $drc_settings['homepage_intro_cta_url'] ? home_url( $drc_settings['homepage_intro_cta_url'] ) : get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="mt-8 inline-flex items-center gap-3 text-xs font-semibold uppercase tracking-[0.18em] text-[#262522] transition-colors hover:text-rug-700">
					<?php echo esc_html( $drc_intro_cta_label ); ?> <?php dreamrugs_icon( 'arrow-right', 16 ); ?>
				</a>
			<?php endif; ?>
		</div>

		<div class="order-3 mt-6 lg:absolute lg:bottom-[2%] lg:left-0 lg:mt-0 lg:w-[53%]">
			<p class="font-condensed text-[clamp(4.2rem,8.2vw,9.5rem)] font-medium uppercase leading-[0.88] tracking-[-0.045em] text-[#191d27]">
				<?php echo esc_html( $drc_settings['homepage_intro_title_line_one'] ?: 'Rug Making' ); ?><br /><?php echo esc_html( $drc_settings['homepage_intro_title_line_two'] ?: '& Weaving' ); ?>
			</p>
		</div>

		<?php if ( null !== $average_project_rating || $drc_settings['homepage_intro_trusted_by_text'] ) : ?>
			<div class="order-4 flex flex-col items-start lg:absolute lg:bottom-[5%] lg:right-[2%] lg:items-center">
				<?php if ( ! empty( $rating_customers ) ) : ?>
					<div class="flex items-center">
						<?php foreach ( $rating_customers as $index => $project ) :
							$initials = implode( '', array_map( fn( $part ) => mb_strtoupper( mb_substr( $part, 0, 1 ) ), array_slice( preg_split( '/\s+/', trim( $project['owner_name'] ) ), 0, 2 ) ) );
							$matching_photo = null;
							foreach ( $testimonials as $t ) {
								if ( $t['photo_url'] && mb_strtolower( trim( $t['author_name'] ) ) === mb_strtolower( trim( $project['owner_name'] ) ) ) {
									$matching_photo = $t['photo_url'];
									break;
								}
							}
							?>
							<div title="<?php echo esc_attr( $project['owner_name'] ); ?>" class="relative flex h-14 w-14 items-center justify-center rounded-full border-[3px] border-[#f3f1e8] text-sm font-semibold tracking-wide text-white <?php echo $index > 0 ? '-ml-3' : ''; ?> <?php echo esc_attr( $rating_customer_colors[ $index % 4 ] ); ?>">
								<?php if ( $matching_photo ) : ?>
									<img src="<?php echo esc_url( $matching_photo ); ?>" alt="<?php echo esc_attr( $project['owner_name'] ); ?>" class="h-full w-full rounded-full object-cover" loading="lazy" />
								<?php else : ?>
									<?php echo esc_html( $initials ); ?>
								<?php endif; ?>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<p class="<?php echo ! empty( $rating_customers ) ? 'mt-5' : ''; ?> text-lg text-stone-500">
					Trusted by <span class="font-medium text-[#191d27]"><?php echo esc_html( $drc_settings['homepage_intro_trusted_by_text'] ?: ( count( $rated_projects ) . '+ customers' ) ); ?></span>
				</p>
				<?php if ( null !== $average_project_rating ) : ?>
					<div class="mt-3 flex items-center gap-2" aria-label="<?php echo esc_attr( number_format( $average_project_rating, 1 ) . ' out of 5 stars' ); ?>">
						<div class="flex gap-0.5" aria-hidden="true">
							<?php for ( $star = 1; $star <= 5; $star++ ) : ?>
								<?php dreamrugs_icon( 'star', 20, $star <= round( $average_project_rating ) ? 'fill-rug-700 text-rug-700' : 'fill-stone-300 text-stone-300' ); ?>
							<?php endfor; ?>
						</div>
						<span class="font-condensed text-base font-medium text-[#191d27]">/ <?php echo esc_html( number_format( $average_project_rating, 1 ) ); ?></span>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<a href="#introduction-end" class="order-5 hidden items-center gap-3 font-condensed text-base font-medium uppercase text-[#191d27] lg:absolute lg:bottom-[5%] lg:left-[65%] lg:flex">
			Scroll <span class="text-2xl font-light">↓</span>
		</a>
		<span id="introduction-end" class="absolute bottom-0" aria-hidden="true"></span>
	</div>
</section>
<?php endif; ?>

<?php if ( ! empty( $drc_settings['homepage_full_bleed_enabled'] ) && ! empty( $drc_settings['homepage_full_bleed_image_url'] ) ) : ?>
<section class="h-[clamp(360px,28vw,620px)] w-full overflow-hidden bg-stone-200" aria-label="Featured interior">
	<img src="<?php echo esc_url( $drc_settings['homepage_full_bleed_image_url'] ); ?>"
		alt="<?php echo esc_attr( $drc_settings['homepage_full_bleed_alt_text'] ?: 'Featured handcrafted rug interior' ); ?>"
		class="h-full w-full object-cover" loading="lazy" />
</section>
<?php endif; ?>

<?php
// Company Values — fully admin-managed (Settings screen), ported from
// CustomerHome.tsx's COMPANY VALUES section. Maps the DB-stored icon key
// (matching React's HomepageValueIcon switch) onto this theme's Lucide icon
// set — 'package'/'shield' are remapped since those two keys already point
// at a different (non-"check") icon elsewhere in this theme.
$drc_values_items = ! empty( $drc_settings['homepage_values_items'] ) ? $drc_settings['homepage_values_items'] : array(
	array( 'icon' => 'pencil-ruler', 'title' => 'Bespoke Design', 'description' => 'Every rug is developed around your dimensions, palette, pattern, and intended space.' ),
	array( 'icon' => 'scissors', 'title' => 'Master Craftsmanship', 'description' => 'Experienced artisans shape every detail by hand using time-honoured weaving techniques.' ),
	array( 'icon' => 'gem', 'title' => 'Premium Materials', 'description' => 'Responsibly selected wool, silk, cotton, and performance fibres deliver beauty that lasts.' ),
	array( 'icon' => 'globe', 'title' => 'Export Quality', 'description' => 'Careful inspection, secure packaging, and worldwide delivery support every finished rug.' ),
);
$drc_value_icon_map = array( 'package' => 'package-check', 'shield' => 'shield-check' );
?>
<?php if ( $drc_settings['homepage_values_enabled'] && ! empty( $drc_values_items ) ) : ?>
<section class="bg-[#f3f1e8] py-20 md:py-28">
	<div class="mx-auto w-[90vw]">
		<p class="mb-8 text-xs font-semibold uppercase tracking-[0.22em] text-stone-500"><?php echo esc_html( $drc_settings['homepage_values_eyebrow'] ?: ( 'Why ' . ( get_bloginfo( 'name' ) ?: 'Choose Us' ) ) ); ?></p>
		<div class="grid gap-10 md:grid-cols-12 md:items-end">
			<h2 class="font-condensed text-[clamp(3rem,5.1vw,6.2rem)] font-medium uppercase leading-[0.98] tracking-[-0.035em] text-[#191d27] md:col-span-10">
				<?php echo esc_html( $drc_settings['homepage_values_headline'] ?: 'Rugs designed with purpose.' ); ?>
				<span class="text-[#9b9a93]"><?php echo esc_html( $drc_settings['homepage_values_headline_accent'] ?: 'Crafted to last for generations.' ); ?></span>
			</h2>
			<?php $drc_values_description = $drc_settings['homepage_values_description'] ?: 'From the first design conversation to final delivery, every decision is guided by skilled hands, dependable materials, and exacting quality standards.'; ?>
			<?php if ( $drc_values_description ) : ?>
				<p class="text-base leading-relaxed text-stone-500 md:col-span-6 md:col-start-7 md:text-lg"><?php echo esc_html( $drc_values_description ); ?></p>
			<?php endif; ?>
		</div>

		<div class="mt-16 grid grid-cols-1 border-l border-t border-stone-300 sm:grid-cols-2 lg:grid-cols-4">
			<?php foreach ( $drc_values_items as $drc_value ) : ?>
				<article class="flex min-h-[330px] flex-col border-b border-r border-stone-300 p-8 md:p-10">
					<div class="text-rug-700"><?php dreamrugs_icon( $drc_value_icon_map[ $drc_value['icon'] ] ?? ( $drc_value['icon'] ?: 'scissors' ), 48, 'stroke-[1.4]' ); ?></div>
					<div class="mt-auto pt-16">
						<h3 class="font-condensed text-2xl font-medium uppercase leading-tight text-[#191d27]"><?php echo esc_html( $drc_value['title'] ); ?></h3>
						<?php if ( ! empty( $drc_value['description'] ) ) : ?><p class="mt-4 text-base leading-relaxed text-stone-500"><?php echo esc_html( $drc_value['description'] ); ?></p><?php endif; ?>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( ! empty( $grid_videos ) ) : ?>
<section class="bg-stone-50 border-y border-stone-100 py-20" x-data="{ tab: '<?php echo esc_js( $video_tab_names[0] ?? 'Craftsmanship' ); ?>' }">
	<div class="w-[94vw] max-w-none mx-auto px-4">
		<div class="mb-12">
			<p class="text-xs tracking-[0.2em] uppercase text-stone-400 mb-2">See It Made</p>
			<h2 class="font-serif text-4xl font-light text-stone-900">Behind the Craft</h2>
			<?php if ( count( $video_tab_names ) > 1 ) : ?>
				<div class="flex flex-wrap gap-x-7 gap-y-3 mt-8 border-b border-stone-200" role="tablist" aria-label="Craft video categories">
					<?php foreach ( $video_tab_names as $name ) : ?>
						<button type="button" role="tab" @click="tab = '<?php echo esc_js( $name ); ?>'" :aria-selected="tab === '<?php echo esc_js( $name ); ?>'"
							class="pb-3 text-xs font-medium uppercase tracking-[0.14em] border-b-2 -mb-px transition-colors"
							:class="tab === '<?php echo esc_js( $name ); ?>' ? 'border-stone-900 text-stone-900' : 'border-transparent text-stone-400 hover:text-stone-700'">
							<?php echo esc_html( $name ); ?>
						</button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
			<?php foreach ( $grid_videos as $index => $v ) :
				$column_position = $index % 4;
				$expansion_position = 0 === $column_position ? 'lg:left-0 lg:origin-left' : ( 3 === $column_position ? 'lg:right-0 lg:origin-right' : 'lg:left-1/2 lg:-translate-x-1/2 lg:origin-center' );
			?>
				<div x-show="tab === '<?php echo esc_js( $v['tab_name'] ); ?>'" <?php echo $index > 0 ? 'x-cloak' : ''; ?>
					class="group relative aspect-[3/4] cursor-pointer hover:z-30 focus-within:z-30" x-data="{ playing: false }"
					@mouseenter="playing = true; $refs.vid.play().catch(()=>{})"
					@mouseleave="playing = false; $refs.vid.pause(); $refs.vid.currentTime = 0"
					@click="playing = !playing; playing ? $refs.vid.play().catch(()=>{}) : $refs.vid.pause()">
					<div class="absolute inset-y-0 w-full overflow-hidden bg-stone-100 transition-[width,left,right,transform,box-shadow] duration-500 ease-out lg:group-hover:w-[calc(200%+1rem)] lg:group-focus-within:w-[calc(200%+1rem)] lg:group-hover:shadow-2xl lg:group-focus-within:shadow-2xl <?php echo esc_attr( $expansion_position ); ?>">
						<?php if ( $v['poster_url'] ) : ?>
							<img src="<?php echo esc_url( $v['poster_url'] ); ?>" alt="<?php echo esc_attr( $v['title'] ); ?>" loading="lazy"
								class="absolute inset-0 w-full h-full object-cover transition-opacity duration-300" :class="playing ? 'opacity-0' : 'opacity-100'" />
						<?php else : ?>
							<div class="absolute inset-0 flex items-center justify-center transition-opacity duration-300" :class="playing ? 'opacity-0' : 'opacity-100'"><?php dreamrugs_icon( 'layers', 28, 'text-stone-300' ); ?></div>
						<?php endif; ?>
						<video x-ref="vid" class="absolute inset-0 w-full h-full object-cover transition-opacity duration-300" :class="playing ? 'opacity-100' : 'opacity-0'"
							src="<?php echo esc_url( $v['video_url'] ); ?>" muted loop playsinline preload="none"></video>
						<div class="absolute inset-0 bg-stone-900/0 group-hover:bg-stone-900/10 transition-colors duration-300"></div>
						<div x-show="!playing" class="absolute top-3 right-3 w-8 h-8 rounded-full bg-white/90 flex items-center justify-center"><?php dreamrugs_icon( 'play', 13, 'text-stone-900' ); ?></div>
						<div class="absolute bottom-0 left-0 right-0 p-4 bg-gradient-to-t from-stone-900/70 to-transparent">
							<p class="text-white text-sm font-medium"><?php echo esc_html( $v['title'] ); ?></p>
							<?php if ( $v['description'] ) : ?><p class="text-stone-300 text-xs mt-0.5 line-clamp-1"><?php echo esc_html( $v['description'] ); ?></p><?php endif; ?>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( ! empty( $workshop_photos ) ) : ?>
<section class="overflow-hidden bg-[#071016] py-16 text-white md:py-24" aria-labelledby="workshop-heading">
	<div class="mx-auto grid w-[92vw] grid-cols-1 items-start gap-8 sm:grid-cols-2 lg:grid-cols-12 lg:gap-x-8 lg:gap-y-20">
		<div class="sm:col-span-2 lg:col-span-4 lg:pr-8">
			<p class="mb-5 text-[10px] uppercase tracking-[0.25em] text-[#c3a27c]">The hands behind every rug</p>
			<h2 id="workshop-heading" class="font-condensed text-[clamp(3rem,4.4vw,5.5rem)] font-medium uppercase leading-[0.98] tracking-[-0.035em]">Inside the Workshop</h2>
			<p class="mt-7 max-w-lg text-base leading-relaxed text-white/65">Where every thread becomes a story. Step inside the hands-on process behind every rug, from raw fibre and colour preparation to patient weaving and meticulous finishing by our master artisans.</p>
		</div>
		<?php
		$workshop_layouts = array( 'lg:col-span-7 lg:pl-[4vw]', 'lg:col-span-5 lg:pt-28', 'lg:col-span-4 lg:pt-16', 'lg:col-span-8' );
		foreach ( $workshop_photos as $index => $p ) :
			if ( 0 === $index ) {
				$layout = 'lg:col-span-3';
			} elseif ( 1 === $index ) {
				$layout = 'lg:col-span-5';
			} else {
				$layout = $workshop_layouts[ ( $index - 2 ) % 4 ];
			}
			if ( 1 === $index ) {
				$ratio = 'aspect-[4/5]';
			} elseif ( 0 === $index ) {
				$ratio = 'aspect-[5/4]';
			} else {
				$ratio = 0 === $index % 2 ? 'aspect-[4/3]' : 'aspect-[5/4]';
			}
			?>
			<figure class="min-w-0 <?php echo esc_attr( $layout ); ?>">
				<div class="overflow-hidden bg-white/5 <?php echo esc_attr( $ratio ); ?>">
					<img src="<?php echo esc_url( $p['image_url'] ); ?>" alt="<?php echo esc_attr( $p['caption'] ?: 'Artisans at work in our rug workshop' ); ?>" class="h-full w-full object-cover" loading="lazy" />
				</div>
				<?php if ( $p['caption'] || $p['description'] ) : ?>
					<figcaption class="mt-4 space-y-2 text-sm leading-relaxed">
						<?php if ( $p['caption'] ) : ?><p class="font-medium text-white/85"><?php echo esc_html( $p['caption'] ); ?></p><?php endif; ?>
						<?php if ( $p['description'] ) : ?><p class="whitespace-pre-wrap break-words text-white/65"><?php echo esc_html( $p['description'] ); ?></p><?php endif; ?>
					</figcaption>
				<?php endif; ?>
			</figure>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<section class="relative overflow-hidden bg-gradient-to-b from-cream-100 via-white to-cream-100 py-20">
	<div class="absolute inset-0 opacity-60 pointer-events-none" style="background-image:radial-gradient(circle, #ddbf9155 1px, transparent 1px);background-size:26px 26px;"></div>
	<div class="absolute -top-28 -left-20 w-80 h-80 bg-cream-400/30 rounded-full blur-3xl pointer-events-none"></div>
	<div class="absolute -bottom-28 -right-20 w-80 h-80 bg-stone-300/30 rounded-full blur-3xl pointer-events-none"></div>

	<div class="relative w-[94vw] max-w-none mx-auto px-4">
		<div class="text-center mb-20">
			<p class="storefront-eyebrow mb-2">The Process</p>
			<h2 class="storefront-heading text-4xl">Custom Rug Journey</h2>
		</div>

		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-10 gap-y-16">
			<?php
			$how_count = count( $how_steps );
			foreach ( $how_steps as $i => $step ) :
				$is_last    = $i === $how_count - 1;
				$is_row_end = ( $i + 1 ) % 4 === 0;
				?>
				<div class="group relative <?php echo $i % 2 === 1 ? 'lg:translate-y-6' : ''; ?>">
					<div class="relative bg-white rounded-2xl border border-stone-100 shadow-sm hover:shadow-xl hover:-translate-y-1 transition-all duration-300 px-6 pt-9 pb-6 overflow-hidden">
						<span class="absolute -top-4 right-2 font-serif text-7xl text-stone-100 group-hover:text-cream-300 transition-colors duration-300 select-none leading-none"><?php echo esc_html( $step['n'] ); ?></span>
						<div class="relative w-11 h-11 rounded-full bg-stone-900 text-white flex items-center justify-center font-serif text-sm mb-4"><?php echo esc_html( $step['n'] ); ?></div>
						<h3 class="relative text-stone-900 font-medium text-base"><?php echo esc_html( $step['title'] ); ?></h3>
						<?php if ( $step['desc'] ) : ?>
							<p class="relative text-stone-500 text-sm leading-relaxed mt-2"><?php echo esc_html( $step['desc'] ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( ! $is_last && ! $is_row_end ) : ?>
						<div class="hidden lg:flex absolute top-1/2 -right-8 -translate-y-1/2 items-center justify-center text-cream-600 z-10"><?php dreamrugs_icon( 'arrow-right', 20, '' ); ?></div>
					<?php elseif ( ! $is_last && $is_row_end ) : ?>
						<div class="hidden lg:flex absolute -bottom-11 right-4 items-center justify-center text-cream-600 z-10"><?php dreamrugs_icon( 'corner-down-left', 20, '' ); ?></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<section class="bg-stone-50 border-y border-stone-100 py-20" x-data="{ tab: 'space' }">
	<div class="w-[94vw] max-w-none mx-auto px-4">
		<div class="text-center mb-10">
			<p class="storefront-eyebrow mb-2">Find Your Fit</p>
			<h2 class="storefront-heading text-4xl mb-8">Shop the Way You Like</h2>
			<div class="inline-flex items-center gap-1 p-1.5 bg-white border border-stone-200 rounded-full shadow-sm">
				<?php foreach ( array( 'space' => 'Shop by Space', 'mood' => 'Shop by Mood', 'material' => 'Shop by Material' ) as $key => $label ) : ?>
					<button type="button" @click="tab = '<?php echo esc_js( $key ); ?>'"
						class="px-6 py-2.5 rounded-full text-xs font-medium tracking-[0.15em] uppercase transition-all duration-300"
						:class="tab === '<?php echo esc_js( $key ); ?>' ? 'bg-stone-900 text-white shadow-md' : 'text-stone-500 hover:text-stone-900'">
						<?php echo esc_html( $label ); ?>
					</button>
				<?php endforeach; ?>
			</div>
		</div>

		<div x-show="tab === 'space'" class="grid grid-cols-2 lg:grid-cols-4 gap-4">
			<?php foreach ( $room_types as $s ) : ?>
				<a href="<?php echo esc_url( home_url( '/catalog/space/' . $s['v'] ) ); ?>" class="group relative overflow-hidden bg-cream-200 aspect-square flex items-center justify-center text-center p-6">
					<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/' . $s['image'] ); ?>" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover scale-100 group-hover:scale-105 transition-transform duration-500" />
					<div class="absolute inset-0 bg-stone-900/35 group-hover:bg-stone-900/45 transition-colors duration-500"></div>
					<span class="relative font-serif text-xl font-light text-white"><?php echo esc_html( $s['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<div x-show="tab === 'mood'" x-cloak class="flex flex-wrap items-center justify-center gap-3">
			<?php foreach ( $mood_tags as $m ) : ?>
				<a href="<?php echo esc_url( home_url( '/catalog/mood/' . $m['v'] ) ); ?>" class="storefront-cta-outline bg-white px-5 py-2.5"><?php echo esc_html( $m['label'] ); ?></a>
			<?php endforeach; ?>
		</div>

		<div x-show="tab === 'material'" x-cloak class="grid grid-cols-2 lg:grid-cols-4 gap-4">
			<?php foreach ( $material_textures as $mat ) : ?>
				<a href="<?php echo esc_url( home_url( '/catalog/material/' . $mat['id'] ) ); ?>" class="group relative overflow-hidden bg-cream-200 aspect-square flex items-center justify-center text-center p-6">
					<img src="<?php echo esc_url( get_stylesheet_directory_uri() . '/assets/' . $mat['image'] ); ?>" alt="" loading="lazy" class="absolute inset-0 w-full h-full object-cover scale-100 group-hover:scale-105 transition-transform duration-500" />
					<div class="absolute inset-0 bg-stone-900/35 group-hover:bg-stone-900/45 transition-colors duration-500"></div>
					<div class="relative space-y-2">
						<p class="font-serif text-xl font-light text-white"><?php echo esc_html( $mat['label'] ); ?></p>
						<p class="text-stone-200 text-xs leading-relaxed"><?php echo esc_html( $mat['desc'] ); ?></p>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
</section>

<?php if ( ! empty( $trending ) ) : ?>
<section class="w-[94vw] max-w-none mx-auto px-4 py-20">
	<h2 class="storefront-heading text-4xl text-center mb-12">Latest Trending Rug Designs</h2>
	<div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-x-6 gap-y-10">
		<?php foreach ( $trending as $rug ) :
			$gallery_ids = $rug->get_gallery_image_ids();
			$hover_url   = ! empty( $gallery_ids ) ? wp_get_attachment_image_url( $gallery_ids[0], 'large' ) : null;
			$image_url   = wp_get_attachment_image_url( $rug->get_image_id(), 'large' );
		?>
			<a href="<?php echo esc_url( $rug->get_permalink() ); ?>" class="group block">
				<div class="relative overflow-hidden bg-transparent aspect-[3/4.5]">
					<?php if ( $image_url ) : ?>
						<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php echo esc_attr( $rug->get_name() ); ?>" loading="lazy"
							class="w-full h-full object-contain transition-opacity duration-500 <?php echo $hover_url ? 'group-hover:opacity-0' : ''; ?>" />
						<?php if ( $hover_url ) : ?>
							<img src="<?php echo esc_url( $hover_url ); ?>" alt="" aria-hidden="true" loading="lazy" class="absolute inset-0 w-full h-full object-contain opacity-0 group-hover:opacity-100 transition-opacity duration-500" />
						<?php endif; ?>
					<?php else : ?>
						<div class="w-full h-full flex items-center justify-center"><?php dreamrugs_icon( 'layers', 28, 'text-stone-300' ); ?></div>
					<?php endif; ?>
				</div>
				<div class="pt-4 space-y-1 text-center">
					<h3 class="font-serif text-base font-light text-stone-900 leading-snug"><?php echo esc_html( $rug->get_name() ); ?></h3>
				</div>
			</a>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( ! empty( $testimonials ) ) :
	$avatar_colors = array( 'bg-[#536b70]', 'bg-[#9b634b]', 'bg-[#847052]', 'bg-[#4f5b78]' );
?>
<section class="border-y border-stone-200 bg-[#f7f5ef] py-24 md:py-36">
	<div class="mx-auto w-[90vw]">
		<header class="mx-auto mb-16 max-w-4xl text-center md:mb-24">
			<p class="font-condensed text-sm font-medium uppercase tracking-[0.18em] text-[#191d27]">Testimonials</p>
			<h2 class="mt-8 font-condensed text-[clamp(3.2rem,5vw,6.25rem)] font-medium uppercase leading-[0.95] tracking-[-0.035em] text-[#191d27]">
				Client feedback that<br class="hidden sm:block" /> drives our work
			</h2>
		</header>

		<div class="columns-1 gap-6 sm:columns-2 xl:columns-4 xl:gap-8">
			<?php foreach ( $testimonials as $index => $t ) :
				$initials = implode( '', array_map( fn( $part ) => mb_strtoupper( mb_substr( $part, 0, 1 ) ), array_slice( preg_split( '/\s+/', trim( $t['author_name'] ) ), 0, 2 ) ) );
			?>
				<article class="mb-6 break-inside-avoid border border-[#ddd9cb] bg-[#eeece2] p-8 md:p-10 xl:mb-8">
					<?php if ( $t['photo_url'] ) : ?>
						<img src="<?php echo esc_url( $t['photo_url'] ); ?>" alt="<?php echo esc_attr( $t['author_name'] ); ?>" width="72" height="72" loading="lazy" class="h-[72px] w-[72px] rounded-full object-cover" />
					<?php else : ?>
						<div class="flex h-[72px] w-[72px] items-center justify-center rounded-full text-base font-semibold tracking-wide text-white <?php echo esc_attr( $avatar_colors[ $index % 4 ] ); ?>"><?php echo esc_html( $initials ); ?></div>
					<?php endif; ?>

					<h3 class="mt-7 font-condensed text-2xl font-medium uppercase leading-none text-[#191d27]"><?php echo esc_html( $t['author_name'] ); ?></h3>
					<?php if ( $t['author_title'] || $t['country'] ) : ?>
						<p class="mt-2 text-base text-stone-500"><?php echo esc_html( implode( ', ', array_filter( array( $t['author_title'], $t['country'] ) ) ) ); ?></p>
					<?php endif; ?>
					<p class="mt-9 text-lg leading-[1.45] text-[#242833] md:text-xl"><?php echo esc_html( $t['quote'] ); ?></p>

					<?php if ( null !== $t['rating'] ) : ?>
						<div class="mt-8 flex gap-1" aria-label="<?php echo esc_attr( $t['rating'] . ' out of 5 stars' ); ?>">
							<?php for ( $i = 0; $i < 5; $i++ ) : ?>
								<?php dreamrugs_icon( 'star', 14, $i < $t['rating'] ? 'fill-rug-700 text-rug-700' : 'fill-stone-300 text-stone-300' ); ?>
							<?php endfor; ?>
						</div>
					<?php endif; ?>
				</article>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( ! empty( $gallery_items ) ) :
	// Deterministic mosaic composition — mirrors CustomerHome.tsx's
	// GALLERY_MOSAIC_LAYOUTS (repeats this 5-tile art-directed canvas per
	// group so every CMS image stays visible without layout shift).
	$gallery_mosaic_layouts = array(
		'col-span-2 aspect-[4/3] md:aspect-auto md:col-start-1 md:col-span-5 md:row-start-1 md:row-span-4',
		'col-span-1 aspect-square md:aspect-auto md:col-start-10 md:col-span-3 md:row-start-1 md:row-span-3',
		'col-span-1 aspect-square md:aspect-auto md:col-start-1 md:col-span-3 md:row-start-6 md:row-span-3',
		'col-span-1 aspect-[3/4] md:aspect-auto md:col-start-4 md:col-span-3 md:row-start-7 md:row-span-4',
		'col-span-1 aspect-[3/4] md:aspect-auto md:col-start-7 md:col-span-5 md:row-start-5 md:row-span-6',
	);
	$gallery_groups = array_chunk( $gallery_items, count( $gallery_mosaic_layouts ) );
	?>
<section class="relative overflow-hidden bg-[#0b1217] py-24 md:py-32" style="background-image:radial-gradient(circle at 18% 22%, rgba(255,255,255,.035), transparent 25%), linear-gradient(115deg, transparent 35%, rgba(255,255,255,.025) 35.1%, transparent 35.3%);">
	<div class="w-[94vw] max-w-none mx-auto px-4 mb-16 md:mb-24">
		<p class="text-xs tracking-[0.3em] uppercase text-rug-400 mb-4">Project Gallery</p>
		<h2 class="font-black uppercase text-[#c53d16] leading-[0.82] tracking-[-0.045em] text-[13vw] xl:text-[9.5vw] 2xl:text-[148px]" style="font-family:'Arial Narrow','Roboto Condensed',Impact,sans-serif;font-stretch:condensed;">
			Rugs in Their<br />New Homes
		</h2>
		<a href="<?php echo esc_url( home_url( '/project-gallery' ) ); ?>" class="inline-flex items-center gap-2 mt-8 text-xs font-medium tracking-[0.15em] uppercase text-cream-100 hover:text-rug-400 transition-colors pb-1 border-b border-cream-100/30 hover:border-rug-400">
			View Full Gallery <?php dreamrugs_icon( 'arrow-right', 14 ); ?>
		</a>
	</div>

	<div class="w-[94vw] max-w-none mx-auto px-2 md:px-4">
		<?php foreach ( $gallery_groups as $group ) : ?>
			<div class="grid grid-cols-2 gap-4 mb-4 md:mb-16 md:grid-cols-12 md:grid-rows-[repeat(10,minmax(0,92px))] lg:grid-rows-[repeat(10,minmax(0,110px))] md:gap-0">
				<?php foreach ( $group as $i => $g ) :
					$layout = $gallery_mosaic_layouts[ $i ];
					$href   = $g['link_url'] ?: home_url( '/project-gallery/' . $g['id'] );
					?>
					<a href="<?php echo esc_url( $href ); ?>" <?php echo $g['link_url'] ? 'target="_blank" rel="noreferrer"' : ''; ?> class="<?php echo esc_attr( $layout ); ?> md:p-3">
						<div class="group relative w-full h-full overflow-hidden bg-dark-900">
							<img src="<?php echo esc_url( $g['image_url'] ); ?>" alt="<?php echo esc_attr( $g['caption'] ?? '' ); ?>" class="w-full h-full object-cover scale-100 group-hover:scale-[1.045] transition-transform duration-[1200ms] ease-out" loading="lazy" />
							<?php if ( $g['caption'] ) : ?>
								<div class="absolute inset-0 bg-gradient-to-t from-dark-950/85 via-transparent to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-500 flex items-end">
									<p class="text-cream-100 font-serif text-lg font-light px-5 pb-4"><?php echo esc_html( $g['caption'] ); ?></p>
								</div>
							<?php endif; ?>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endforeach; ?>
	</div>
</section>
<?php endif; ?>

<?php if ( $drc_settings['homepage_contact_enabled'] && ! empty( $drc_settings['homepage_contact_image_url'] ) ) : ?>
<section id="contact" class="grid min-h-[760px] bg-[#f4f2eb] lg:grid-cols-[59%_41%]"
	x-data="homepageContact({ restUrl: '<?php echo esc_js( rest_url( 'dreamrugs/v1' ) ); ?>' })">
	<div class="relative min-h-[420px] overflow-hidden lg:min-h-[760px]">
		<img src="<?php echo esc_url( $drc_settings['homepage_contact_image_url'] ); ?>"
			alt="<?php echo esc_attr( $drc_settings['homepage_contact_image_alt'] ?: 'A rug artisan at work' ); ?>"
			loading="lazy" class="absolute inset-0 h-full w-full object-cover" />
	</div>

	<div class="flex bg-[#f8f7f2] px-6 py-14 sm:px-10 lg:px-[clamp(2.5rem,4vw,5rem)] lg:py-16">
		<div class="my-auto w-full">
			<h2 class="whitespace-pre-line font-condensed text-[clamp(3rem,4.3vw,5.25rem)] font-medium uppercase leading-[0.92] tracking-[-0.025em] text-[#191d27]"><?php echo esc_html( $drc_settings['homepage_contact_heading'] ?: "Have Questions?\nGet in Touch!" ); ?></h2>

			<template x-if="submitted">
				<div class="mt-10 flex min-h-[330px] flex-col items-center justify-center border border-[#ded9c9] bg-white/40 px-8 text-center">
					<?php dreamrugs_icon( 'check-circle', 46, 'text-rug-700 stroke-[1.3]' ); ?>
					<p class="mt-5 max-w-md font-serif text-2xl leading-snug text-[#262522]"><?php echo esc_html( $drc_settings['homepage_contact_success_message'] ?: 'Thank you. Your enquiry has been sent successfully.' ); ?></p>
					<button type="button" @click="sendAnother()" class="mt-7 text-xs font-semibold uppercase tracking-[0.18em] text-rug-700 hover:text-rug-800">Send another message</button>
				</div>
			</template>
			<template x-if="!submitted">
				<form @submit.prevent="submit()" class="mt-10 space-y-5">
					<input required maxlength="150" x-model="form.name" placeholder="Name" autocomplete="name" class="h-[68px] w-full border border-[#ded9c9] bg-transparent px-5 text-base text-stone-900 outline-none placeholder:text-[#aea789] focus:border-rug-600" />
					<input required type="email" maxlength="200" x-model="form.email" placeholder="Email" autocomplete="email" class="h-[68px] w-full border border-[#ded9c9] bg-transparent px-5 text-base text-stone-900 outline-none placeholder:text-[#aea789] focus:border-rug-600" />
					<input required maxlength="250" x-model="form.subject" placeholder="Subject" class="h-[68px] w-full border border-[#ded9c9] bg-transparent px-5 text-base text-stone-900 outline-none placeholder:text-[#aea789] focus:border-rug-600" />
					<textarea required maxlength="5000" rows="6" x-model="form.message" placeholder="Message" class="min-h-[190px] w-full resize-y border border-[#ded9c9] bg-transparent px-5 py-5 text-base text-stone-900 outline-none placeholder:text-[#aea789] focus:border-rug-600"></textarea>
					<label class="flex cursor-pointer items-start gap-3 text-sm leading-relaxed text-[#8d876f]">
						<input required type="checkbox" x-model="form.consent" class="mt-0.5 h-5 w-5 shrink-0 accent-[#c53d16]" />
						<span><?php echo esc_html( $drc_settings['homepage_contact_consent_text'] ?: 'I agree that my submitted data is being collected and stored.' ); ?></span>
					</label>
					<template x-if="error"><p role="alert" class="text-sm text-red-700" x-text="error"></p></template>
					<button type="submit" :disabled="submitting" class="flex h-[72px] w-full items-center justify-center bg-[#c73d14] font-condensed text-xl font-medium uppercase tracking-[0.04em] text-white transition-colors hover:bg-[#a83212] disabled:cursor-wait disabled:opacity-65">
						<span x-text="submitting ? 'Sending…' : '<?php echo esc_js( $drc_settings['homepage_contact_button_label'] ?: 'Send Message' ); ?>'"></span>
					</button>
				</form>
			</template>
		</div>
	</div>
</section>
<?php endif; ?>

<?php get_footer(); ?>
