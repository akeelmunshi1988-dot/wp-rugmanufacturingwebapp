<?php
/**
 * Ported from frontend/src/pages/CustomerProjectGallery.tsx — the public
 * /project-gallery listing (every published drc_gallery_item, linking to
 * its own /project-gallery/{id} detail page). Routed via
 * DRC_Project_Gallery_Routes' `template_include` filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$gallery_items = DRC_Content_Types::get_gallery_items();

get_header();
?>

<div class="w-[94vw] max-w-none mx-auto px-4 py-10 space-y-10">
	<div>
		<p class="storefront-eyebrow mb-2">Project Gallery</p>
		<h1 class="storefront-heading text-4xl">Rugs in Their New Homes</h1>
	</div>

	<?php if ( empty( $gallery_items ) ) : ?>
		<div class="text-center py-24 space-y-3">
			<?php dreamrugs_icon( 'layers', 32, 'mx-auto text-stone-300' ); ?>
			<p class="text-stone-400 text-sm">No projects published yet — check back soon.</p>
		</div>
	<?php else : ?>
		<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-12">
			<?php foreach ( $gallery_items as $g ) : ?>
				<a href="<?php echo esc_url( home_url( '/project-gallery/' . $g['id'] ) ); ?>" class="group block">
					<div class="relative overflow-hidden bg-stone-100 aspect-[4/5]">
						<img src="<?php echo esc_url( $g['image_url'] ); ?>" alt="<?php echo esc_attr( $g['caption'] ?? '' ); ?>" loading="lazy" class="w-full h-full object-cover group-hover:scale-[1.04] transition-transform duration-700" />
					</div>
					<div class="pt-4 space-y-1">
						<?php if ( $g['caption'] ) : ?><h3 class="font-serif text-base font-light text-stone-900 leading-snug"><?php echo esc_html( $g['caption'] ); ?></h3><?php endif; ?>
						<?php if ( null !== $g['rating'] ) : ?>
							<div class="flex items-center gap-0.5">
								<?php for ( $i = 0; $i < 5; $i++ ) : ?>
									<?php dreamrugs_icon( 'star', 11, $i < $g['rating'] ? 'text-amber-500 fill-amber-500' : 'text-stone-200' ); ?>
								<?php endfor; ?>
							</div>
						<?php endif; ?>
					</div>
				</a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>

<?php get_footer(); ?>
