<?php
/**
 * Ported from frontend/src/components/CollectionImageGrid.tsx — the dark,
 * oversized-title banner shown atop a specific collection page, above the
 * craft-story block. Expects three variables from the including template:
 *
 * @var array{0: array{image_url:string,caption:string}, 1: ..., 2: ...} $drc_grid_images exactly 3 images
 * @var string $drc_grid_title
 * @var string $drc_grid_eyebrow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
// Only a complete, vendor-curated set renders — never a partial grid.
if ( empty( $drc_grid_images ) || count( $drc_grid_images ) < 3 ) {
	return;
}
?>
<section class="overflow-hidden bg-[#0b1217] text-white">
	<div class="mx-auto w-[94vw] px-4 pt-10 pb-10 md:pt-16 md:pb-16">
		<p class="mb-8 text-[10px] uppercase tracking-[0.3em] text-[#d8b880]"><?php echo esc_html( $drc_grid_eyebrow ); ?></p>
		<div class="grid grid-cols-2 gap-6 md:grid-cols-[minmax(0,1fr)_minmax(0,3.2fr)_minmax(0,1fr)] md:gap-[3vw] items-start">
			<figure class="min-w-0 md:pr-[1vw]">
				<img src="<?php echo esc_url( $drc_grid_images[0]['image_url'] ); ?>" alt="<?php echo esc_attr( $drc_grid_images[0]['caption'] ?: ( $drc_grid_title . ' detail' ) ); ?>" class="aspect-[4/5] w-full object-cover" />
				<?php if ( $drc_grid_images[0]['caption'] ) : ?><figcaption class="mt-5 text-sm md:text-base leading-relaxed text-white/80"><?php echo esc_html( $drc_grid_images[0]['caption'] ); ?></figcaption><?php endif; ?>
			</figure>
			<figure class="order-first col-span-2 min-w-0 md:order-none md:col-span-1">
				<img src="<?php echo esc_url( $drc_grid_images[1]['image_url'] ); ?>" alt="<?php echo esc_attr( $drc_grid_images[1]['caption'] ?: ( $drc_grid_title . ' collection' ) ); ?>" fetchpriority="high" class="aspect-[16/10] w-full object-cover" />
				<?php if ( $drc_grid_images[1]['caption'] ) : ?><figcaption class="mt-4 text-sm leading-relaxed text-white/70"><?php echo esc_html( $drc_grid_images[1]['caption'] ); ?></figcaption><?php endif; ?>
			</figure>
			<figure class="min-w-0">
				<img src="<?php echo esc_url( $drc_grid_images[2]['image_url'] ); ?>" alt="<?php echo esc_attr( $drc_grid_images[2]['caption'] ?: ( $drc_grid_title . ' texture' ) ); ?>" class="aspect-[4/5] w-full object-cover" />
				<?php if ( $drc_grid_images[2]['caption'] ) : ?><figcaption class="mt-5 text-sm md:text-base leading-relaxed text-white/80"><?php echo esc_html( $drc_grid_images[2]['caption'] ); ?></figcaption><?php endif; ?>
				<a href="#collection-rugs" class="mt-6 inline-block border-b border-white/70 pb-1 text-sm hover:text-[#d8b880] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4">Explore the collection ↓</a>
			</figure>
		</div>
		<h1 class="mt-14 md:mt-24 uppercase font-black leading-[0.95] tracking-[-0.045em] text-[clamp(2.5rem,10vw,12rem)] [overflow-wrap:anywhere]" style="font-family:'Arial Narrow','Roboto Condensed',Impact,sans-serif;"><?php echo esc_html( $drc_grid_title ); ?></h1>
	</div>
</section>
