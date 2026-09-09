<?php
/**
 * Ported from frontend/src/pages/WeaveTypePage.tsx's isWeavePage branch
 * (`/weaves/:weave`) — craft-story content (dreamrugs_get_weave_story(),
 * inc/collection-content.php) above a Material/Pile/Sort-filterable grid of
 * products tagged with this weave type. Loaded via DRC_Weave_Routes'
 * `template_include` filter (wp-content/plugins/dreamrugs-core/includes/class-weave-routes.php)
 * when the `drc_weave` query var is set.
 *
 * Deliberate simplification from the original: the product cards below use
 * the same simple image/title/caption card archive-product.php already uses
 * elsewhere in the theme, not WeaveTypePage.tsx's fancier hover-expanding
 * card — kept consistent with the rest of this build-step-free theme rather
 * than adding a one-off interaction used nowhere else.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$weave     = sanitize_title( get_query_var( 'drc_weave' ) );
$drc_story = dreamrugs_get_weave_story( $weave );

$sort_options  = array(
	'default'    => 'Featured',
	'price-asc'  => 'Price: Low → High',
	'price-desc' => 'Price: High → Low',
	'lead-asc'   => 'Fastest delivery',
);
$material_terms = get_terms( array( 'taxonomy' => 'material_type', 'hide_empty' => true ) );
$material_terms = is_wp_error( $material_terms ) ? array() : $material_terms;
$pile_options    = dreamrugs_distinct_product_meta_values( '_drc_pile_height' );

$search   = sanitize_text_field( $_GET['s'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter state from a GET form, no state-changing action.
$sort     = sanitize_text_field( $_GET['sort'] ?? 'default' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$material = sanitize_text_field( $_GET['material'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$pile     = sanitize_text_field( $_GET['pile'] ?? 'all' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$has_active_filters = ( $search || 'all' !== $material || 'all' !== $pile || 'default' !== $sort );

$paged     = max( 1, get_query_var( 'paged' ) ?: ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$page_size = 12;

$tax_query  = array();
$meta_query = array( array( 'key' => '_drc_weave_type', 'value' => $weave ) );
if ( 'all' !== $material ) {
	$tax_query[] = array( 'taxonomy' => 'material_type', 'field' => 'slug', 'terms' => $material );
}
if ( 'all' !== $pile ) {
	$meta_query[] = array( 'key' => '_drc_pile_height', 'value' => $pile );
}

$orderby  = array( 'menu_order' => 'ASC', 'date' => 'DESC' );
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
	'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
);
if ( $meta_key ) { $query_args['meta_key'] = $meta_key; } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
if ( $search ) { $query_args['s'] = $search; }
if ( ! empty( $tax_query ) ) { $query_args['tax_query'] = $tax_query; } // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query

$products_query = new WP_Query( $query_args );
$base_url       = home_url( "/weaves/{$weave}/" );

get_header();
$drc_grid_images  = dreamrugs_collection_display_images( 'weave', $weave );
$drc_grid_title   = $drc_story['name'] . ' Rugs';
$drc_grid_eyebrow = 'Discover your next favourite';
require get_theme_file_path( 'template-parts/collection-image-grid.php' );
?>

<?php require get_theme_file_path( 'template-parts/collection-story-block.php' ); ?>

<section id="collection-rugs" class="w-[94vw] mx-auto px-4 py-20 md:py-28 scroll-mt-24">
	<div class="flex flex-wrap items-end justify-between gap-5 mb-12">
		<div>
			<p class="text-[10px] uppercase tracking-[0.25em] text-stone-400 mb-3">Explore the collection</p>
			<h2 class="font-serif text-4xl md:text-5xl text-stone-900">Shop <?php echo esc_html( $drc_story['name'] ); ?> Rugs</h2>
		</div>
		<p class="text-xs text-stone-400 uppercase tracking-wider"><?php echo (int) $products_query->found_posts; ?> <?php echo 1 === (int) $products_query->found_posts ? 'rug' : 'rugs'; ?></p>
	</div>

	<div class="mb-12 border-y border-stone-200 py-5">
		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="flex flex-wrap items-end gap-4">
			<div class="relative flex-1 min-w-56 max-w-sm">
				<label for="weave-search" class="block text-[10px] uppercase tracking-[0.18em] text-stone-400 mb-2">Search this collection</label>
				<input id="weave-search" type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="Search rugs…"
					class="w-full border border-stone-200 bg-white py-2.5 px-3 text-sm text-stone-900 placeholder:text-stone-400 focus:outline-none focus:border-stone-500" />
			</div>
			<label class="block">
				<span class="block text-[10px] uppercase tracking-[0.18em] text-stone-400 mb-2">Material</span>
				<select name="material" onchange="this.form.submit()" class="min-w-36 border border-stone-200 bg-white px-3 py-2.5 text-sm text-stone-600 capitalize focus:outline-none focus:border-stone-500">
					<option value="all" <?php selected( $material, 'all' ); ?>>All materials</option>
					<?php foreach ( $material_terms as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $material, $term->slug ); ?> class="capitalize"><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="block">
				<span class="block text-[10px] uppercase tracking-[0.18em] text-stone-400 mb-2">Pile height</span>
				<select name="pile" onchange="this.form.submit()" class="min-w-36 border border-stone-200 bg-white px-3 py-2.5 text-sm text-stone-600 capitalize focus:outline-none focus:border-stone-500">
					<option value="all" <?php selected( $pile, 'all' ); ?>>All pile heights</option>
					<?php foreach ( $pile_options as $p ) : ?>
						<option value="<?php echo esc_attr( $p ); ?>" <?php selected( $pile, $p ); ?> class="capitalize"><?php echo esc_html( $p ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<label class="block md:ml-auto">
				<span class="block text-[10px] uppercase tracking-[0.18em] text-stone-400 mb-2">Sort by</span>
				<select name="sort" onchange="this.form.submit()" class="min-w-44 border border-stone-200 bg-white px-3 py-2.5 text-sm text-stone-600 focus:outline-none focus:border-stone-500">
					<?php foreach ( $sort_options as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sort, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php if ( $has_active_filters ) : ?>
				<a href="<?php echo esc_url( $base_url ); ?>" class="h-[42px] inline-flex items-center gap-1.5 text-xs text-stone-500 hover:text-stone-900 transition-colors"><?php dreamrugs_icon( 'x', 13 ); ?> Clear filters</a>
			<?php endif; ?>
		</form>
	</div>

	<?php if ( ! $products_query->have_posts() ) : ?>
		<div class="bg-stone-50 py-24 text-center">
			<?php dreamrugs_icon( 'layers', 30, 'mx-auto text-stone-300 mb-4' ); ?>
			<p class="text-stone-500">No <?php echo esc_html( strtolower( $drc_story['name'] ) ); ?> rugs are currently published.</p>
			<a href="<?php echo esc_url( home_url( '/catalog' ) ); ?>" class="inline-flex items-center gap-2 mt-5 text-xs uppercase tracking-wider text-stone-900 border-b border-stone-400 pb-1">Browse all rugs <?php dreamrugs_icon( 'arrow-right', 13 ); ?></a>
		</div>
	<?php else : ?>
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-12">
			<?php $loop_index = 0; ?>
			<?php while ( $products_query->have_posts() ) : $products_query->the_post();
				global $product;
				$image_url = wp_get_attachment_image_url( $product->get_image_id(), 'large' );
				$column_position    = $loop_index % 3;
				$expansion_position = 0 === $column_position ? 'lg:left-0 lg:origin-left' : ( 1 === $column_position ? 'lg:left-1/2 lg:-translate-x-1/2 lg:origin-center' : 'lg:right-0 lg:origin-right' );
				$loop_index++;
				?>
				<a href="<?php the_permalink(); ?>" class="group relative block hover:z-30 focus-within:z-30">
					<div class="relative aspect-[4/5]">
					<div class="absolute inset-y-0 w-full overflow-hidden bg-stone-100 transition-[width,left,right,transform,box-shadow] duration-500 ease-out lg:group-hover:w-[calc(200%+1.5rem)] lg:group-focus-within:w-[calc(200%+1.5rem)] lg:group-hover:shadow-2xl lg:group-focus-within:shadow-2xl <?php echo esc_attr( $expansion_position ); ?>">
						<?php if ( $image_url ) : ?>
							<img src="<?php echo esc_url( $image_url ); ?>" alt="<?php the_title_attribute(); ?>" loading="lazy" class="w-full h-full object-contain bg-white" />
						<?php else : ?>
							<div class="w-full h-full flex items-center justify-center"><?php dreamrugs_icon( 'layers', 28, 'text-stone-300' ); ?></div>
						<?php endif; ?>
						<?php if ( ! $product->is_in_stock() ) : ?>
							<span class="absolute top-4 left-4 bg-white/90 px-3 py-1 text-[10px] uppercase tracking-wider text-stone-600">Out of stock</span>
						<?php endif; ?>
					</div>
					</div>
					<div class="pt-4 text-center space-y-1">
						<p class="text-[10px] uppercase tracking-[0.18em] text-stone-400"><?php echo esc_html( $product->get_meta( '_drc_material_name' ) ); ?> · <?php echo esc_html( $product->get_meta( '_drc_weave_type' ) ); ?></p>
						<h3 class="font-serif text-lg text-stone-900"><?php the_title(); ?></h3>
					</div>
				</a>
			<?php endwhile; ?>
		</div>

		<?php if ( $products_query->max_num_pages > 1 ) : ?>
			<div class="flex justify-center pt-12">
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
</section>

<?php get_footer(); ?>
