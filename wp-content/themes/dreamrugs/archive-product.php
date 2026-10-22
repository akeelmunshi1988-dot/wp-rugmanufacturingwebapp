<?php
/**
 * Ported from frontend/src/pages/CustomerCatalog.tsx. WooCommerce's own
 * catalog archive template hook — handles /shop/ (catalog root) and the
 * room_type/mood_tag taxonomy archives (space/mood facets); the /material/
 * facet and the pile/sort/search filters ride as query args on top, same
 * facet-priority idea as the original (a taxonomy archive owns the path,
 * everything else is a query string).
 *
 * Filters navigate to clean URLs (/collections/{facet}/{value}/ owns the
 * path, other filters ride as query args — buildCatalogUrl()). More rugs
 * load as you scroll (assets/catalog.js fetches the next page and appends
 * its cards, like the original's IntersectionObserver), with the normal
 * page links kept for no-JS visitors and crawlers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sort_options = array(
	'default'    => 'Featured',
	'price-asc'  => 'Price: Low → High',
	'price-desc' => 'Price: High → Low',
	'lead-asc'   => 'Fastest delivery',
);
// Spaces/moods from the catalog's own terms; weaves/piles from the
// Catalog Masters lists (DreamRugs → Catalog Masters).
$drc_terms         = function ( string $tax ) { $t = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ); return is_wp_error( $t ) ? array() : wp_list_pluck( $t, 'slug' ); };
$room_type_options = $drc_terms( 'room_type' );
$mood_tag_options  = $drc_terms( 'mood_tag' );
$tag_label         = fn( string $v ) => implode( ' ', array_map( 'ucfirst', explode( '_', $v ) ) );

// Material comes from the real material_type taxonomy (only terms actually
// assigned to a product, via hide_empty); pile/weave have no taxonomy of
// their own (plain postmeta — see dreamrugs_distinct_product_meta_values()),
// so their options are whatever values are actually in use right now. None
// of these three are a fixed guess baked into the theme any more.
$material_terms = get_terms( array( 'taxonomy' => 'material_type', 'hide_empty' => true ) );
$material_terms = is_wp_error( $material_terms ) ? array() : $material_terms;
$pile_options    = class_exists( 'DRC_Catalog_Masters' ) ? wp_list_pluck( DRC_Catalog_Masters::get( 'piles', true ), 'name' ) : dreamrugs_distinct_product_meta_values( '_drc_pile_height' );
$weave_options   = class_exists( 'DRC_Catalog_Masters' ) ? wp_list_pluck( DRC_Catalog_Masters::get( 'weaves', true ), 'name' ) : dreamrugs_distinct_product_meta_values( '_drc_weave_type' );

$search   = sanitize_text_field( $_GET['s'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state from a GET form, no state-changing action.
$sort     = sanitize_text_field( $_GET['sort'] ?? 'default' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$material = is_tax( 'material_type' ) ? get_queried_object()->slug : sanitize_text_field( $_GET['material'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$pile     = sanitize_text_field( $_GET['pile'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$weave    = sanitize_text_field( $_GET['weave'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$room     = is_tax( 'room_type' ) ? get_queried_object()->slug : sanitize_text_field( $_GET['room_type'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$mood     = is_tax( 'mood_tag' ) ? get_queried_object()->slug : sanitize_text_field( $_GET['mood'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$filters_open = ( 'all' !== $material || 'all' !== $pile || 'all' !== $weave || 'all' !== $room || 'all' !== $mood );
$has_active_filters = $filters_open;

$paged = max( 1, get_query_var( 'paged' ) ?: ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page_size = 12;

$tax_query  = array();
$meta_query = array();
if ( 'all' !== $room ) {
	$tax_query[] = array( 'taxonomy' => 'room_type', 'field' => 'slug', 'terms' => $room );
}
if ( 'all' !== $mood ) {
	$tax_query[] = array( 'taxonomy' => 'mood_tag', 'field' => 'slug', 'terms' => $mood );
}
if ( 'all' !== $material ) {
	$tax_query[] = array( 'taxonomy' => 'material_type', 'field' => 'slug', 'terms' => $material );
}
if ( 'all' !== $pile ) {
	$meta_query[] = array( 'key' => '_drc_pile_height', 'value' => $pile );
}
if ( 'all' !== $weave ) {
	$meta_query[] = array( 'key' => '_drc_weave_type', 'value' => $weave );
}

$orderby = array( 'menu_order' => 'ASC', 'date' => 'DESC' );
$meta_key = '';
if ( 'price-asc' === $sort ) { $orderby = 'meta_value_num'; $meta_key = '_price'; $order = 'ASC'; }
elseif ( 'price-desc' === $sort ) { $orderby = 'meta_value_num'; $meta_key = '_price'; $order = 'DESC'; }
elseif ( 'lead-asc' === $sort ) { $orderby = 'meta_value_num'; $meta_key = '_drc_lead_time_days'; $order = 'ASC'; }
else { $order = 'DESC'; }

$query_args = array(
	'post_type'      => 'product',
	'post_status'    => 'publish',
	'posts_per_page' => $page_size,
	'paged'          => $paged,
	'orderby'        => $orderby,
	'order'          => $order,
);
if ( $meta_key ) { $query_args['meta_key'] = $meta_key; } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
if ( $search ) { $query_args['s'] = $search; }
if ( ! empty( $tax_query ) ) { $query_args['tax_query'] = $tax_query; } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
if ( ! empty( $meta_query ) ) { $query_args['meta_query'] = $meta_query; } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query

$products_query = new WP_Query( $query_args );

/** Rebuilds the catalog URL for a filter change — mirrors buildCatalogUrl()'s
 * facet-priority logic (room_type > mood > material owns the path segment,
 * the rest ride as query params). */
$build_catalog_url = function ( array $overrides ) use ( $room, $mood, $material, $pile, $weave, $sort, $search ) {
	$current = array_merge( array( 'room_type' => $room, 'mood' => $mood, 'material' => $material, 'pile' => $pile, 'weave' => $weave ), $overrides );
	$facet = null;
	$facet_value = '';
	if ( 'all' !== $current['room_type'] ) { $facet = 'space'; $facet_value = $current['room_type']; }
	elseif ( 'all' !== $current['mood'] ) { $facet = 'mood'; $facet_value = $current['mood']; }
	elseif ( 'all' !== $current['material'] ) { $facet = 'material'; $facet_value = $current['material']; }

	$params = array();
	if ( 'space' !== $facet && 'all' !== $current['room_type'] ) { $params['room_type'] = $current['room_type']; }
	if ( 'mood' !== $facet && 'all' !== $current['mood'] ) { $params['mood'] = $current['mood']; }
	if ( 'material' !== $facet && 'all' !== $current['material'] ) { $params['material'] = $current['material']; }
	if ( 'all' !== $current['pile'] ) { $params['pile'] = $current['pile']; }
	if ( 'all' !== $current['weave'] ) { $params['weave'] = $current['weave']; }
	if ( $sort && 'default' !== $sort ) { $params['sort'] = $sort; }
	if ( $search ) { $params['s'] = $search; }

	$path = $facet ? home_url( "/collections/{$facet}/{$facet_value}/" ) : home_url( '/catalog/' );
	return $params ? $path . '?' . http_build_query( $params ) : $path;
};

// A single material/space/mood term archive (not the plain /catalog root)
// gets the same craft/lifestyle story block the weave-type pages use —
// ported from WeaveTypePage.tsx's `/collections/:facet/:value` branch,
// which this taxonomy archive is the WP-idiomatic equivalent URL for (see
// the ThemeForest-prep plan's Phase A2 note: reusing the existing
// /catalog/{facet}/{value} archive here instead of a second, competing URL
// scheme for the same product grid).
$drc_facet_key = is_tax( 'material_type' ) ? 'material' : ( is_tax( 'room_type' ) ? 'space' : ( is_tax( 'mood_tag' ) ? 'mood' : null ) );
$drc_story     = $drc_facet_key ? dreamrugs_get_collection_story( $drc_facet_key, get_queried_object()->slug ) : null;

// Collection SEO (WeaveTypePage.tsx <SEO>): the page's own title and
// description, noindex for aliases/uncurated values, FAQ + breadcrumb data.
if ( $drc_story ) {
	$drc_url = home_url( '/collections/' . $drc_facet_key . '/' . get_queried_object()->slug . '/' );
	dreamrugs_seo( array(
		'title'       => $drc_story['seoTitle'],
		'description' => $drc_story['seoDescription'],
		'noindex'     => empty( $drc_story['indexable'] ) || $search || $paged > 1,
		'canonical'   => $drc_url,
		'jsonld'      => array_values( array_filter( array(
			dreamrugs_breadcrumb_jsonld( array( array( 'Home', home_url( '/' ) ), array( 'Collection', get_permalink( wc_get_page_id( 'shop' ) ) ), array( $drc_story['name'] . ' Rugs', $drc_url ) ) ),
			dreamrugs_faq_jsonld( $drc_story['faq'] ?? array() ),
		) ) ),
	) );
} elseif ( ! $drc_facet_key ) {
	dreamrugs_seo( array(
		'title'       => 'Handmade Rugs & Carpets — The Collection',
		'description' => 'Browse handmade rugs and carpets — hand-knotted, hand-tufted and flatweave — made to order in any size by artisans in Bhadohi, India.',
		'canonical'   => get_permalink( wc_get_page_id( 'shop' ) ),
		'noindex'     => (bool) $search,
		'jsonld'      => array( dreamrugs_breadcrumb_jsonld( array( array( 'Home', home_url( '/' ) ), array( 'Collection', get_permalink( wc_get_page_id( 'shop' ) ) ) ) ) ),
	) );
}

get_header();
?>

<?php if ( $drc_story ) :
	$drc_grid_images  = dreamrugs_collection_display_images( $drc_facet_key, get_queried_object()->slug );
	$drc_grid_title   = $drc_story['name'] . ' Rugs';
	$drc_grid_eyebrow = 'Discover your next favourite';
	require get_theme_file_path( 'template-parts/collection-image-grid.php' );
	require get_theme_file_path( 'template-parts/collection-story-block.php' );
elseif ( ! $drc_facet_key ) :
	// Main catalog: "The Rug Collection" grid from the 'default' curated set.
	$drc_grid_images  = dreamrugs_collection_display_images( 'default' );
	$drc_grid_title   = 'The Rug Collection';
	$drc_grid_eyebrow = 'Discover your next favourite';
	require get_theme_file_path( 'template-parts/collection-image-grid.php' );
endif; ?>

<div id="collection-rugs" class="w-[94vw] max-w-none mx-auto px-4 scroll-mt-24" x-data="{ filtersOpen: <?php echo $filters_open ? 'true' : 'false'; ?>, sizeUnit: (localStorage.getItem('dreamrugs_measurement_unit') === 'cm' ? 'cm' : 'ft') }" x-init="$watch('sizeUnit', (v) => localStorage.setItem('dreamrugs_measurement_unit', v))">
	<?php if ( ! $drc_story && empty( $drc_grid_images ) ) : ?>
	<div class="py-14 border-b border-stone-100 text-center">
		<h1 class="storefront-heading text-4xl">The Rug Collection</h1>
	</div>
	<?php endif; ?>

	<div class="py-5 border-b border-stone-100">
		<form method="get" action="<?php echo esc_url( strtok( $build_catalog_url( array() ), '?' ) ); ?>" class="flex flex-wrap items-center gap-4">
			<div class="relative flex-1 min-w-52 max-w-xs">
				<span class="absolute left-3 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'search', 13 ); ?></span>
				<input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search rugs…"
					class="w-full border border-stone-200 focus:border-stone-400 pl-8 pr-8 py-2 text-stone-900 text-sm placeholder-stone-400 focus:outline-none transition-colors" />
				<?php if ( $search ) : ?>
					<a href="<?php echo esc_url( remove_query_arg( 's', $build_catalog_url( array() ) ) ); ?>" aria-label="Clear search" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-stone-400 hover:text-stone-900"><?php dreamrugs_icon( 'x', 13 ); ?></a>
				<?php endif; ?>
			</div>

			<button type="button" @click="filtersOpen = !filtersOpen"
				class="relative flex items-center gap-2 text-xs font-medium uppercase tracking-wider px-3 py-2 border transition-colors"
				:class="filtersOpen ? 'bg-stone-900 text-white border-stone-900' : 'text-stone-600 border-stone-200 hover:border-stone-400 hover:text-stone-900'">
				<span x-show="!filtersOpen"><?php dreamrugs_icon( 'plus', 13 ); ?></span>
				<span x-show="filtersOpen" x-cloak><?php dreamrugs_icon( 'minus', 13 ); ?></span>
				Filters
				<?php if ( $has_active_filters ) : ?><span x-show="!filtersOpen" class="w-1.5 h-1.5 rounded-full bg-stone-900"></span><?php endif; ?>
			</button>

			<div class="flex items-center gap-3 ml-auto">
				<?php if ( $has_active_filters ) : ?>
					<a href="<?php echo esc_url( home_url( '/catalog' ) ); ?>" class="text-xs text-stone-400 hover:text-stone-900 flex items-center gap-1 transition-colors"><?php dreamrugs_icon( 'x', 11 ); ?> Clear</a>
				<?php endif; ?>
				<div class="flex items-center border border-stone-200 p-0.5" aria-label="Measurement unit">
					<template x-for="unit in ['ft', 'cm']" :key="unit">
						<button type="button" @click="sizeUnit = unit" class="px-2 py-1 text-[10px] uppercase tracking-wider transition-colors" :class="sizeUnit === unit ? 'bg-stone-900 text-white' : 'text-stone-400 hover:text-stone-900'" x-text="unit"></button>
					</template>
				</div>

				<div class="relative">
					<select name="sort" onchange="this.form.submit()" class="appearance-none text-xs border border-stone-200 pl-3 pr-7 py-2 text-stone-600 focus:outline-none focus:border-stone-400 transition-colors">
						<?php foreach ( $sort_options as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sort, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'chevron-down', 12 ); ?></span>
				</div>
				<p class="text-stone-400 text-xs whitespace-nowrap"><?php echo (int) $products_query->found_posts; ?> <?php echo 1 === (int) $products_query->found_posts ? 'rug' : 'rugs'; ?></p>
			</div>

			<div x-show="filtersOpen" x-cloak class="w-full flex flex-wrap items-center gap-3 mt-5 pt-5 border-t border-stone-100">
				<div class="space-y-1">
					<label class="block text-xs text-stone-400 uppercase tracking-wider">Material</label>
					<div class="relative">
						<select name="material" onchange="window.location.href = this.options[this.selectedIndex].dataset.url" class="appearance-none text-xs border border-stone-200 pl-3 pr-7 py-2 text-stone-700 capitalize focus:outline-none focus:border-stone-400 transition-colors min-w-32">
							<option value="all" data-url="<?php echo esc_url( $build_catalog_url( array( 'material' => 'all' ) ) ); ?>" <?php selected( $material, 'all' ); ?>>All</option>
							<?php foreach ( $material_terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term->slug ); ?>" data-url="<?php echo esc_url( $build_catalog_url( array( 'material' => $term->slug ) ) ); ?>" <?php selected( $material, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'chevron-down', 12 ); ?></span>
					</div>
				</div>
				<div class="space-y-1">
					<label class="block text-xs text-stone-400 uppercase tracking-wider">Weave</label>
					<div class="relative">
						<select name="weave" onchange="window.location.href = this.options[this.selectedIndex].dataset.url" class="appearance-none text-xs border border-stone-200 pl-3 pr-7 py-2 text-stone-700 capitalize focus:outline-none focus:border-stone-400 transition-colors min-w-32">
							<?php foreach ( array_merge( array( 'all' ), $weave_options ) as $w ) : ?>
								<option value="<?php echo esc_attr( $w ); ?>" data-url="<?php echo esc_url( $build_catalog_url( array( 'weave' => $w ) ) ); ?>" <?php selected( $weave, $w ); ?>><?php echo esc_html( 'all' === $w ? 'All' : $w ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'chevron-down', 12 ); ?></span>
					</div>
				</div>
				<div class="space-y-1">
					<label class="block text-xs text-stone-400 uppercase tracking-wider">Pile</label>
					<div class="relative">
						<select name="pile" onchange="window.location.href = this.options[this.selectedIndex].dataset.url" class="appearance-none text-xs border border-stone-200 pl-3 pr-7 py-2 text-stone-700 capitalize focus:outline-none focus:border-stone-400 transition-colors min-w-32">
							<?php foreach ( array_merge( array( 'all' ), $pile_options ) as $p ) : ?>
								<option value="<?php echo esc_attr( $p ); ?>" data-url="<?php echo esc_url( $build_catalog_url( array( 'pile' => $p ) ) ); ?>" <?php selected( $pile, $p ); ?>><?php echo esc_html( 'all' === $p ? 'All' : $p ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'chevron-down', 12 ); ?></span>
					</div>
				</div>
				<div class="space-y-1">
					<label class="block text-xs text-stone-400 uppercase tracking-wider">Space</label>
					<div class="relative">
						<select name="room_type" onchange="window.location.href = this.options[this.selectedIndex].dataset.url" class="appearance-none text-xs border border-stone-200 pl-3 pr-7 py-2 text-stone-700 focus:outline-none focus:border-stone-400 transition-colors min-w-36">
							<?php foreach ( array_merge( array( 'all' ), $room_type_options ) as $r ) : ?>
								<option value="<?php echo esc_attr( $r ); ?>" data-url="<?php echo esc_url( $build_catalog_url( array( 'room_type' => $r ) ) ); ?>" <?php selected( $room, $r ); ?>><?php echo esc_html( 'all' === $r ? 'All' : $tag_label( $r ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'chevron-down', 12 ); ?></span>
					</div>
				</div>
				<div class="space-y-1">
					<label class="block text-xs text-stone-400 uppercase tracking-wider">Mood</label>
					<div class="relative">
						<select name="mood" onchange="window.location.href = this.options[this.selectedIndex].dataset.url" class="appearance-none text-xs border border-stone-200 pl-3 pr-7 py-2 text-stone-700 focus:outline-none focus:border-stone-400 transition-colors min-w-40">
							<?php foreach ( array_merge( array( 'all' ), $mood_tag_options ) as $m ) : ?>
								<option value="<?php echo esc_attr( $m ); ?>" data-url="<?php echo esc_url( $build_catalog_url( array( 'mood' => $m ) ) ); ?>" <?php selected( $mood, $m ); ?>><?php echo esc_html( 'all' === $m ? 'All' : $tag_label( $m ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-stone-400"><?php dreamrugs_icon( 'chevron-down', 12 ); ?></span>
					</div>
				</div>
			</div>
		</form>
	</div>

	<div class="py-12">
		<?php if ( ! $products_query->have_posts() ) : ?>
			<div class="text-center py-32 space-y-3">
				<?php dreamrugs_icon( 'layers', 32, 'mx-auto text-stone-300' ); ?>
				<p class="text-stone-400 text-sm">No rugs match your filters.</p>
				<a href="<?php echo esc_url( home_url( '/catalog' ) ); ?>" class="text-xs text-stone-400 hover:text-stone-900 underline underline-offset-4 transition-colors">Clear all filters</a>
			</div>
		<?php else : ?>
			<div id="drc-catalog-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-12">
				<?php $loop_index = ( $paged - 1 ) * $page_size; // keeps hover-expansion columns right across loaded pages ?>
				<?php while ( $products_query->have_posts() ) : $products_query->the_post();
					global $product;
					$gallery_ids   = $product->get_gallery_image_ids();
					$hover_url     = ! empty( $gallery_ids ) ? wp_get_attachment_image_url( $gallery_ids[0], 'large' ) : null;
					$image_url     = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
					$color_options = DRC_Color_Options::get( $product->get_id() );
				?>
					<?php
					$column_position   = $loop_index % 3;
					$expansion_position = 0 === $column_position ? 'lg:left-0 lg:origin-left' : ( 1 === $column_position ? 'lg:left-1/2 lg:-translate-x-1/2 lg:origin-center' : 'lg:right-0 lg:origin-right' );
					$loop_index++;
				?>
				<a href="<?php the_permalink(); ?>" class="group relative block hover:z-30 focus-within:z-30">
						<div class="relative aspect-[4/5]">
						<div class="absolute inset-y-0 w-full overflow-hidden bg-stone-100 transition-[width,left,right,transform,box-shadow] duration-500 ease-out lg:group-hover:w-[calc(200%+1.5rem)] lg:group-focus-within:w-[calc(200%+1.5rem)] lg:group-hover:shadow-2xl lg:group-focus-within:shadow-2xl <?php echo esc_attr( $expansion_position ); ?>">
							<?php if ( $image_url ) : ?>
								<img src="<?php echo esc_url( $image_url ); ?>" data-drc-cover="<?php echo esc_url( $image_url ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" class="w-full h-full object-contain bg-white transition-opacity duration-500 <?php echo $hover_url ? 'group-hover:opacity-0' : ''; ?>" />
								<?php if ( $hover_url ) : ?>
									<img src="<?php echo esc_url( $hover_url ); ?>" alt="" loading="lazy" aria-hidden="true" class="absolute inset-0 w-full h-full object-contain bg-white opacity-0 group-hover:opacity-100 transition-opacity duration-500" />
								<?php endif; ?>
							<?php else : ?>
								<div class="w-full h-full flex items-center justify-center"><?php dreamrugs_icon( 'layers', 28, 'text-stone-300' ); ?></div>
							<?php endif; ?>
							<?php if ( ! $product->is_in_stock() ) : ?>
								<div class="absolute inset-0 bg-white/70 flex items-center justify-center"><span class="text-stone-600 text-xs tracking-widest uppercase">Out of stock</span></div>
							<?php endif; ?>
						</div>
						</div>
						<div class="pt-4 space-y-1 text-center">
							<h3 class="font-serif text-base font-light text-stone-900 leading-snug"><?php the_title(); ?></h3>
							<?php if ( ! empty( $color_options ) ) : ?>
								<div class="flex items-center justify-center gap-1.5 py-1" aria-label="<?php echo esc_attr( count( $color_options ) . ' color options' ); ?>">
									<?php foreach ( array_slice( $color_options, 0, 6 ) as $color ) : ?>
										<span title="<?php echo esc_attr( $color['name'] . ( ! empty( $color['image_url'] ) ? ' — preview' : '' ) ); ?>" <?php if ( ! empty( $color['image_url'] ) ) : ?>tabindex="0" data-drc-swatch="<?php echo esc_url( $color['image_url'] ); ?>"<?php endif; ?> class="w-3.5 h-3.5 rounded-full border border-black/10 <?php echo ! empty( $color['image_url'] ) ? 'cursor-pointer ring-offset-1 focus:ring-1 focus:ring-stone-500 outline-none' : ''; ?>" style="background-color: <?php echo esc_attr( $color['hex'] ); ?>"></span>
									<?php endforeach; ?>
									<?php if ( count( $color_options ) > 6 ) : ?>
										<span class="text-stone-400 text-[10px]">+<?php echo (int) ( count( $color_options ) - 6 ); ?></span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</div>
					</a>
				<?php endwhile; ?>
			</div>

			<div id="drc-catalog-sentinel" class="flex justify-center py-12" data-next="<?php echo $paged < $products_query->max_num_pages ? esc_url( get_pagenum_link( $paged + 1 ) ) : ''; ?>">
				<div class="drc-loading hidden w-5 h-5 border border-stone-400 border-t-transparent rounded-full animate-spin"></div>
				<p class="drc-end text-stone-400 text-xs uppercase tracking-wider <?php echo $paged < $products_query->max_num_pages ? 'hidden' : ''; ?>">You've reached the end of the collection</p>
			</div>
			<?php if ( $products_query->max_num_pages > 1 ) : ?>
				<div id="drc-catalog-pager" class="flex justify-center pt-12">
					<?php
					echo paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() output is already escaped.
						'total'     => $products_query->max_num_pages,
						'current'   => $paged,
						'prev_text' => '&larr; Previous',
						'next_text' => 'Next &rarr;',
						'type'      => 'list',
					) );
					?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
		<?php wp_reset_postdata(); ?>
	</div>
</div>

<?php get_footer(); ?>
