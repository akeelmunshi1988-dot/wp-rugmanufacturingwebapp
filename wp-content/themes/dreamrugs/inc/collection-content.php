<?php
/**
 * Collection-page copy (weave, space, mood and material pages), read from
 * data/collectionContent.json — the same file the original app ships in
 * frontend/src/data/, copied here unchanged so the two stay in sync. Each
 * entry has name, seoTitle, seoDescription, eyebrow, intro, story, bestFor,
 * character, making, care and an optional faq list. Aliases borrow another
 * page's copy (and are kept out of search results); values with no copy get
 * the generic block the original renders, also noindex.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dreamrugs_collection_content_data(): array {
	static $data = null;
	if ( null === $data ) {
		$raw  = file_get_contents( get_theme_file_path( 'data/collectionContent.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled theme data file.
		$data = $raw ? (array) json_decode( $raw, true ) : array();
	}
	return $data;
}

/** slugKey() from collectionContent.ts: "Hand-Knotted" / "living_room" → "hand-knotted" / "living-room". */
function dreamrugs_collection_slug_key( string $value ): string {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $value ) ), '-' );
}

/**
 * findCollectionContent() + WeaveTypePage.tsx's fallback.
 *
 * @param string $facet 'weave' | 'space' | 'mood' | 'material'
 * @return array{name:string,seoTitle:string,seoDescription:string,eyebrow:string,intro:string,story:string,bestFor:string,character:string,making:string,care:string,faq:array,indexable:bool}
 */
function dreamrugs_collection_details( string $facet, string $value ): array {
	$data   = dreamrugs_collection_content_data();
	$pages  = (array) ( $data['pages'] ?? array() );
	$alias  = (array) ( $data['aliases'] ?? array() );
	$key    = $facet . '/' . dreamrugs_collection_slug_key( $value );
	$pretty = ucwords( str_replace( array( '-', '_' ), ' ', $value ) );

	if ( isset( $pages[ $key ] ) ) {
		return $pages[ $key ] + array( 'faq' => array(), 'indexable' => true );
	}
	if ( isset( $alias[ $key ], $pages[ $alias[ $key ] ] ) ) {
		return array_merge( $pages[ $alias[ $key ] ], array( 'name' => $pretty, 'seoTitle' => $pretty . ' Rugs', 'indexable' => false ) ) + array( 'faq' => array() );
	}
	return array(
		'name'           => $pretty,
		'seoTitle'       => $pretty . ' Rugs',
		'seoDescription' => 'Explore our ' . strtolower( $pretty ) . ' rugs, handmade to order in custom sizes.',
		'eyebrow'        => 'Discover the collection',
		'intro'          => 'Explore the ' . strtolower( $pretty ) . ' collection, in sizes and finishes to suit your space.',
		'story'          => 'Browse the designs below for material details, available sizes, and finishes. Each product page includes the details you need to choose your rug.',
		'bestFor'        => 'See individual rugs for room suggestions',
		'character'      => 'Discover the texture and finish of each design',
		'making'         => 'See individual product details',
		'care'           => 'Follow the care instructions for your rug',
		'faq'            => array(),
		'indexable'      => false,
	);
}

/** Kept for the weave page template: details for /weaves/{slug}. */
function dreamrugs_get_weave_story( string $weave ): array {
	return dreamrugs_collection_details( 'weave', $weave );
}

/**
 * Editorial 3-image header banner (CollectionImageGrid.tsx) for a
 * collection page — same per-slot fallback as the original's
 * /customer/catalog-display:
 *   1. the vendor's curated image (DreamRugs → Catalog Masters),
 *   2. photos of rugs in this collection, gallery shots preferred over the
 *      flat product cutout, shuffled,
 *   3. the 'default' curated set,
 *   4. bundled craft photos (workshop photos), so the grid is never broken.
 *
 * @param string $facet 'default' | 'weave' | 'material' | 'space' | 'mood'
 * @param string $value the slug/value within that facet
 * @return array<int,array{image_url:string,caption:string}> exactly 3 images (or none on an empty site)
 */
function dreamrugs_collection_display_images( string $facet, string $value = '' ): array {
	$images = class_exists( 'DRC_Catalog_Masters' ) ? DRC_Catalog_Masters::display_images( $facet, $value ) : array_fill( 0, 3, array( 'image_url' => '', 'caption' => '' ) );
	$missing = function () use ( &$images ) { return array_filter( $images, function ( $i ) { return '' === $i['image_url']; } ); };
	$fill    = function ( array $pool ) use ( &$images ) {
		$used = array_filter( wp_list_pluck( $images, 'image_url' ) );
		foreach ( $images as &$image ) {
			if ( '' !== $image['image_url'] ) {
				continue;
			}
			foreach ( $pool as $i => $candidate ) {
				$url = is_array( $candidate ) ? $candidate['image_url'] : $candidate;
				if ( $url && ! in_array( $url, $used, true ) ) {
					$image['image_url'] = $url;
					if ( is_array( $candidate ) && '' === $image['caption'] ) {
						$image['caption'] = (string) ( $candidate['caption'] ?? '' );
					}
					$used[] = $url;
					unset( $pool[ $i ] );
					break;
				}
			}
		}
		unset( $image );
	};

	if ( $missing() ) {
		$args = array( 'post_type' => 'product', 'post_status' => 'publish', 'posts_per_page' => 30, 'fields' => 'ids', 'no_found_rows' => true );
		$tax  = array( 'space' => 'room_type', 'mood' => 'mood_tag', 'material' => 'material_type' );
		if ( isset( $tax[ $facet ] ) ) {
			$args['tax_query'] = array( array( 'taxonomy' => $tax[ $facet ], 'field' => 'slug', 'terms' => $value ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		} elseif ( 'weave' === $facet ) {
			$names = array( $value );
			if ( class_exists( 'DRC_Catalog_Masters' ) ) {
				foreach ( DRC_Catalog_Masters::get( 'weaves' ) as $w ) {
					if ( $w['slug'] === sanitize_title( $value ) ) {
						$names[] = $w['name'];
					}
				}
			}
			$args['meta_query'] = array( array( 'key' => '_drc_weave_type', 'value' => array_unique( $names ), 'compare' => 'IN' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}
		$photos = array();
		foreach ( get_posts( $args ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$gallery = array_filter( array_map( function ( $id ) { return wp_get_attachment_image_url( $id, 'large' ); }, $product->get_gallery_image_ids() ) );
			$photos  = array_merge( $photos, $gallery ?: array_filter( array( wp_get_attachment_image_url( $product->get_image_id(), 'large' ) ) ) );
		}
		shuffle( $photos );
		$fill( $photos );
	}
	if ( $missing() && 'default' !== $facet && class_exists( 'DRC_Catalog_Masters' ) ) {
		$fill( array_filter( DRC_Catalog_Masters::display_images( 'default' ), function ( $i ) { return '' !== $i['image_url']; } ) );
	}
	if ( $missing() && class_exists( 'DRC_Content_Types' ) ) {
		$fill( wp_list_pluck( DRC_Content_Types::get_workshop_photos(), 'image_url' ) );
	}
	return $missing() ? array() : $images;
}

/** Details for a space/mood/material collection page (null for other facets). */
function dreamrugs_get_collection_story( string $facet, string $value ): ?array {
	return in_array( $facet, array( 'space', 'mood', 'material' ), true ) ? dreamrugs_collection_details( $facet, $value ) : null;
}
