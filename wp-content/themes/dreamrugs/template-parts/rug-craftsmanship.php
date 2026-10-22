<?php
/**
 * "The Details Make the Difference" band on the product page — port of the
 * original app's frontend/src/components/rug-detail/CraftsmanshipSection.tsx.
 * The first three workshop photos (drc_workshop_photo, the same ones the
 * About page uses) plus brand trust points. Only facts the site already
 * states elsewhere — no invented heritage claims.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_photos = class_exists( 'DRC_Content_Types' ) ? array_values( array_filter( DRC_Content_Types::get_workshop_photos( 3 ), fn( $p ) => ! empty( $p['image_url'] ) ) ) : array();
$drc_trust  = array(
	array( 'Handmade in Bhadohi, India', 'Woven by master artisans in India’s historic carpet-weaving region.' ),
	array( 'Made to Order', 'Every rug begins on the loom only once it is commissioned for you.' ),
	array( 'Custom Dimensions', 'Any size, from a compact runner to a room-sized statement piece.' ),
);
?>
<section aria-labelledby="craft-heading" class="-mx-4 bg-showroom-ivory px-4 py-16 sm:-mx-6 sm:px-6 lg:-mx-8 lg:px-8 lg:py-24">
	<div class="grid gap-10 lg:grid-cols-12 lg:gap-16">
		<div class="lg:col-span-4">
			<p class="text-[11px] font-medium uppercase tracking-[0.25em] text-showroom-gold">Materials &amp; Craftsmanship</p>
			<h2 id="craft-heading" class="mt-3 font-serif text-3xl font-light leading-tight text-showroom-charcoal sm:text-4xl">The Details Make the Difference</h2>
			<p class="mt-5 text-[15px] leading-relaxed text-showroom-grey">From hand-dyed yarn to the final wash and finish, each rug passes through many skilled hands before it reaches your home.</p>
			<dl class="mt-8 divide-y divide-showroom-border border-y border-showroom-border">
				<?php foreach ( $drc_trust as list( $drc_title, $drc_body ) ) : ?>
					<div class="py-4">
						<dt class="font-serif text-lg text-showroom-charcoal"><?php echo esc_html( $drc_title ); ?></dt>
						<dd class="mt-1 text-sm leading-relaxed text-showroom-grey"><?php echo esc_html( $drc_body ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
			<a href="<?php echo esc_url( home_url( '/about/' ) ); ?>" class="storefront-link-arrow mt-8">Our workshop <?php dreamrugs_icon( 'arrow-right', 13 ); ?></a>
		</div>

		<?php if ( $drc_photos ) : ?>
			<ul class="grid gap-6 lg:col-span-8 <?php echo count( $drc_photos ) < 3 ? 'sm:grid-cols-2' : 'sm:grid-cols-3'; ?>">
				<?php foreach ( $drc_photos as $drc_i => $drc_photo ) : ?>
					<li class="<?php echo 1 === $drc_i ? 'sm:mt-12' : ''; ?>">
						<div class="aspect-[4/5] overflow-hidden bg-white">
							<img src="<?php echo esc_url( $drc_photo['image_url'] ); ?>" alt="<?php echo esc_attr( $drc_photo['caption'] ); ?>" loading="lazy" decoding="async" class="h-full w-full object-cover" />
						</div>
						<?php // Admin captions are often numbered for /about ("2. The Art of…"). ?>
						<p class="mt-4 font-serif text-lg leading-snug text-showroom-charcoal"><?php echo esc_html( preg_replace( '/^\s*\d+\.\s*/', '', (string) $drc_photo['caption'] ) ); ?></p>
						<?php if ( ! empty( $drc_photo['description'] ) ) : ?>
							<p class="mt-1.5 text-sm leading-relaxed text-showroom-grey"><?php echo esc_html( $drc_photo['description'] ); ?></p>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</section>
