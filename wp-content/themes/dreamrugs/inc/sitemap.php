<?php
/**
 * The storefront URLs that aren't WordPress posts or pages, added to the
 * sitemap — the same list as the original's /sitemap.xml (backend main.py):
 * collection pages that have written copy (the rest are noindex), weave
 * pages, buying guides, landing pages and project-gallery detail pages.
 * Rugs, pages and design stories are already in the post-type sitemaps.
 *
 * Feeds both Rank Math's sitemap (a "drc-pages" provider) and WordPress
 * core's wp-sitemap.xml (used whenever Rank Math's sitemap module is off).
 * Account / cart / checkout pages are kept out of both.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @return string[] absolute URLs */
function dreamrugs_sitemap_extra_urls(): array {
	$urls = array();
	if ( class_exists( 'DRC_Catalog_Masters' ) ) {
		$menu = DRC_Catalog_Masters::menu_options();
		foreach ( array( 'space' => 'spaces', 'mood' => 'moods', 'material' => 'materials', 'weave' => 'weaves' ) as $facet => $key ) {
			foreach ( (array) ( $menu[ $key ] ?? array() ) as $item ) {
				if ( dreamrugs_collection_details( $facet, $item['slug'] )['indexable'] ) {
					$urls[] = home_url( ( 'weave' === $facet ? '/weaves/' : '/collections/' . $facet . '/' ) . $item['slug'] . '/' );
				}
			}
		}
	}
	$urls[] = home_url( '/guides/' );
	foreach ( dreamrugs_guides() as $guide ) {
		$urls[] = home_url( '/guides/' . $guide['slug'] . '/' );
	}
	foreach ( dreamrugs_landing_pages() as $page ) {
		$urls[] = dreamrugs_app_url( $page['path'] );
	}
	$urls[] = home_url( '/project-gallery/' );
	if ( class_exists( 'DRC_Content_Types' ) ) {
		foreach ( DRC_Content_Types::get_gallery_items() as $item ) {
			$urls[] = home_url( '/project-gallery/' . (int) $item['id'] . '/' );
		}
	}
	return array_values( array_unique( $urls ) );
}

/** Pages that are noindex (sign-in, account, cart…) — kept out of sitemaps. */
function dreamrugs_sitemap_excluded_page_ids(): array {
	$ids = array();
	foreach ( array( 'account', 'my-quotes', 'my-orders', 'order-tracking' ) as $slug ) {
		$page = get_page_by_path( $slug );
		if ( $page ) {
			$ids[] = $page->ID;
		}
	}
	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $wc_page ) {
			if ( wc_get_page_id( $wc_page ) > 0 ) {
				$ids[] = wc_get_page_id( $wc_page );
			}
		}
	}
	return $ids;
}

// ── WordPress core sitemap ──────────────────────────────────────────────
add_action( 'init', function () {
	if ( ! function_exists( 'wp_register_sitemap_provider' ) || ! class_exists( 'WP_Sitemaps_Provider' ) ) {
		return;
	}
	require_once get_theme_file_path( 'inc/class-dreamrugs-sitemap-provider.php' );
	wp_register_sitemap_provider( 'drcpages', new Dreamrugs_Sitemap_Provider() );
} );

add_filter( 'wp_sitemaps_posts_query_args', function ( $args, $post_type ) {
	if ( 'page' === $post_type ) {
		$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), dreamrugs_sitemap_excluded_page_ids() );
	}
	return $args;
}, 10, 2 );

// ── Rank Math sitemap ───────────────────────────────────────────────────
add_filter( 'rank_math/sitemap/providers', function ( $providers ) {
	if ( interface_exists( '\RankMath\Sitemap\Providers\Provider' ) ) {
		require_once get_theme_file_path( 'inc/class-dreamrugs-rank-math-sitemap.php' );
		$providers[] = new Dreamrugs_Rank_Math_Sitemap();
	}
	return $providers;
} );

add_filter( 'rank_math/sitemap/entry', function ( $url, $type, $post ) {
	return ( 'post' === $type && $post && in_array( (int) $post->ID, dreamrugs_sitemap_excluded_page_ids(), true ) ) ? false : $url;
}, 10, 3 );

// Core sitemap: the collection taxonomies are listed above (only the pages
// with written copy — the rest are noindex), and user archives aren't part
// of the storefront.
add_filter( 'wp_sitemaps_taxonomies', function ( $taxonomies ) {
	unset( $taxonomies['room_type'], $taxonomies['mood_tag'], $taxonomies['material_type'] );
	return $taxonomies;
} );
add_filter( 'wp_sitemaps_add_provider', function ( $provider, $name ) {
	return 'users' === $name ? false : $provider;
}, 10, 2 );
