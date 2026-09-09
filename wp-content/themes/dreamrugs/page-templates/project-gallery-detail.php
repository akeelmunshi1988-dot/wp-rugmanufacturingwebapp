<?php
/**
 * Ported from frontend/src/pages/ProjectDetail.tsx — single project detail:
 * cover + gallery images (click-to-expand lightbox), description, and the
 * homeowner's own message/rating. Routed via DRC_Project_Gallery_Routes'
 * `template_include` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$project_id = (int) get_query_var( DRC_Project_Gallery_Routes::QUERY_VAR );
$project    = DRC_Content_Types::get_gallery_item( $project_id );

get_header();

if ( ! $project ) :
	?>
	<div class="max-w-xl mx-auto px-4 py-24 text-center space-y-4">
		<?php dreamrugs_icon( 'layers', 36, 'text-stone-300 mx-auto' ); ?>
		<h2 class="storefront-heading text-2xl">Project Not Found</h2>
		<a href="<?php echo esc_url( home_url( '/project-gallery' ) ); ?>" class="storefront-link-arrow justify-center">Back to Gallery <?php dreamrugs_icon( 'chevron-right', 14 ); ?></a>
	</div>
	<?php
	get_footer();
	return;
endif;

$gallery_images = array_merge(
	array( array( 'id' => 'cover', 'image_url' => $project['image_url'] ) ),
	$project['images']
);
?>

<div class="w-[94vw] max-w-none mx-auto px-4 py-10 space-y-8" x-data="{ expandedSrc: null, expandedAlt: '' }">
	<div class="flex items-center gap-2 text-xs text-stone-400">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="hover:text-stone-900 transition-colors">Home</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<a href="<?php echo esc_url( home_url( '/project-gallery' ) ); ?>" class="hover:text-stone-900 transition-colors">Project Gallery</a>
		<?php dreamrugs_icon( 'chevron-right', 11 ); ?>
		<span class="text-stone-600 truncate"><?php echo esc_html( $project['caption'] ?: ( 'Project #' . $project['id'] ) ); ?></span>
	</div>

	<?php if ( $project['caption'] ) : ?>
		<div class="pb-2">
			<p class="storefront-eyebrow mb-2">Project Gallery</p>
			<h1 class="storefront-heading text-4xl"><?php echo esc_html( $project['caption'] ); ?></h1>
		</div>
	<?php endif; ?>

	<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
		<?php foreach ( $gallery_images as $i => $img ) :
			$alt = $project['caption'] ?: ( 'Project photo ' . ( $i + 1 ) );
			?>
			<button type="button" @click="expandedSrc = '<?php echo esc_js( $img['image_url'] ); ?>'; expandedAlt = '<?php echo esc_js( $alt ); ?>'"
				class="relative bg-stone-100 overflow-hidden aspect-[4/3] <?php echo 0 === $i ? 'sm:col-span-2 sm:aspect-[21/9]' : ''; ?> cursor-zoom-in group">
				<img src="<?php echo esc_url( $img['image_url'] ); ?>" alt="<?php echo esc_attr( $alt ); ?>" class="w-full h-full object-cover group-hover:scale-[1.03] transition-transform duration-700" loading="<?php echo 0 === $i ? 'eager' : 'lazy'; ?>" />
			</button>
		<?php endforeach; ?>
	</div>

	<div class="grid grid-cols-1 lg:grid-cols-3 gap-10 pt-4">
		<?php if ( $project['description'] ) : ?>
			<div class="lg:col-span-2 space-y-3">
				<h2 class="font-serif text-2xl font-light text-stone-900">About This Project</h2>
				<p class="text-stone-600 leading-relaxed whitespace-pre-line"><?php echo esc_html( $project['description'] ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $project['owner_message'] || $project['owner_name'] || null !== $project['rating'] ) : ?>
			<div class="<?php echo $project['description'] ? '' : 'lg:col-span-3'; ?> border border-stone-200 bg-stone-50 p-6 space-y-4 h-fit">
				<p class="storefront-eyebrow">From the Homeowner</p>
				<?php if ( null !== $project['rating'] ) : ?>
					<div class="flex items-center gap-1">
						<?php for ( $i = 0; $i < 5; $i++ ) : ?>
							<?php dreamrugs_icon( 'star', 15, $i < $project['rating'] ? 'text-amber-500 fill-amber-500' : 'text-stone-200' ); ?>
						<?php endfor; ?>
					</div>
				<?php endif; ?>
				<?php if ( $project['owner_message'] ) : ?>
					<p class="font-serif text-lg font-light text-stone-800 leading-relaxed">&ldquo;<?php echo esc_html( $project['owner_message'] ); ?>&rdquo;</p>
				<?php endif; ?>
				<?php if ( $project['owner_name'] ) : ?>
					<p class="text-stone-500 text-sm">&mdash; <?php echo esc_html( $project['owner_name'] ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>

	<div class="pt-6 border-t border-stone-100">
		<a href="<?php echo esc_url( home_url( '/project-gallery' ) ); ?>" class="storefront-link-arrow">
			<?php dreamrugs_icon( 'chevron-left', 13 ); ?> Back to Gallery
		</a>
	</div>

	<div x-show="expandedSrc" x-cloak role="dialog" aria-modal="true" aria-label="Expanded project photo"
		class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/90 p-4 sm:p-8" @click="expandedSrc = null">
		<button type="button" @click="expandedSrc = null" aria-label="Close expanded image"
			class="absolute right-4 top-4 sm:right-6 sm:top-6 w-10 h-10 flex items-center justify-center bg-white text-stone-900 hover:bg-stone-100 transition-colors">
			<?php dreamrugs_icon( 'x', 20 ); ?>
		</button>
		<img :src="expandedSrc" :alt="expandedAlt" class="w-full h-full object-contain" @click.stop="" />
	</div>
</div>

<?php get_footer(); ?>
