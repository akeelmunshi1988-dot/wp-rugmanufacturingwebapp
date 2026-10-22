<?php
/**
 * "You May Also Love" on the product page — port of the original app's
 * frontend/src/components/rug-detail/RelatedRugs.tsx: same weave first,
 * topped up with the same material, never the current rug, never a
 * duplicate. Renders nothing when the catalog has no related rugs.
 *
 * @var array $args { product: WC_Product }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$drc_product = $args['product'] ?? null;
if ( ! $drc_product instanceof WC_Product ) {
	return;
}

$drc_max     = 4;
$drc_picked  = array();
$drc_queries = array_filter( array(
	'_drc_weave_type'    => $drc_product->get_meta( '_drc_weave_type' ),
	'_drc_material_name' => $drc_product->get_meta( '_drc_material_name' ),
) );
foreach ( $drc_queries as $drc_key => $drc_value ) {
	if ( count( $drc_picked ) >= $drc_max ) {
		break;
	}
	$drc_ids = wc_get_products( array(
		'status'     => 'publish',
		'limit'      => $drc_max + 1,
		'return'     => 'ids',
		'exclude'    => array_merge( array( $drc_product->get_id() ), $drc_picked ),
		'orderby'    => 'ID',
		'order'      => 'DESC',
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small catalog, cached by WP object cache.
		'meta_query' => array( array( 'key' => $drc_key, 'value' => $drc_value ) ),
	) );
	foreach ( $drc_ids as $drc_id ) {
		if ( count( $drc_picked ) < $drc_max ) {
			$drc_picked[] = (int) $drc_id;
		}
	}
}
if ( ! $drc_picked ) {
	return;
}
?>
<section aria-labelledby="related-rugs-heading" class="border-t border-showroom-border pt-16">
	<div class="mb-8 flex items-end justify-between gap-6">
		<div>
			<p class="text-[11px] font-medium uppercase tracking-[0.25em] text-showroom-gold">Curated for you</p>
			<h2 id="related-rugs-heading" class="mt-2 font-serif text-3xl font-light text-showroom-charcoal sm:text-4xl">You May Also Love</h2>
		</div>
		<a href="<?php echo esc_url( get_permalink( wc_get_page_id( 'shop' ) ) ); ?>" class="storefront-link-arrow hidden whitespace-nowrap sm:inline-flex">View collection <?php dreamrugs_icon( 'arrow-right', 13 ); ?></a>
	</div>
	<ul class="grid grid-cols-2 gap-x-4 gap-y-10 lg:grid-cols-4 lg:gap-x-8">
		<?php
		foreach ( $drc_picked as $drc_id ) :
			$drc_rug = wc_get_product( $drc_id );
			if ( ! $drc_rug ) {
				continue;
			}
			$drc_img = wp_get_attachment_image_url( $drc_rug->get_image_id(), 'large' );
			$drc_sub = implode( ' · ', array_filter( array( $drc_rug->get_meta( '_drc_weave_type' ), $drc_rug->get_meta( '_drc_material_name' ) ) ) );
			?>
			<li>
				<a href="<?php echo esc_url( get_permalink( $drc_id ) ); ?>" class="group block">
					<div class="aspect-[4/5] overflow-hidden bg-showroom-ivory">
						<?php if ( $drc_img ) : ?>
							<img src="<?php echo esc_url( $drc_img ); ?>" alt="<?php echo esc_attr( $drc_rug->get_name() ); ?>" loading="lazy" decoding="async" class="h-full w-full object-contain p-4 transition-transform duration-700 ease-out motion-safe:group-hover:scale-[1.03]" />
						<?php endif; ?>
					</div>
					<p class="mt-4 font-serif text-lg leading-snug text-showroom-charcoal transition-colors group-hover:text-showroom-gold"><?php echo esc_html( $drc_rug->get_name() ); ?></p>
					<?php if ( $drc_sub ) : ?>
						<p class="mt-1 text-xs uppercase tracking-[0.16em] text-showroom-grey"><?php echo esc_html( $drc_sub ); ?></p>
					<?php endif; ?>
				</a>
			</li>
		<?php endforeach; ?>
	</ul>
</section>
