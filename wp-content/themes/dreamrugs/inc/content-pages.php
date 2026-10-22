<?php
/**
 * Buying guides (/guides, /guides/{slug}) and keyword landing pages
 * (/moroccan-rugs, /rug-manufacturer-india, …) — GuidesIndex.tsx,
 * GuidePage.tsx and LandingPage.tsx. Copy lives in data/guides.json and
 * data/landingPages.json, the original app's own files copied unchanged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dreamrugs_json_data( string $file ): array {
	static $cache = array();
	if ( ! isset( $cache[ $file ] ) ) {
		$raw            = file_get_contents( get_theme_file_path( 'data/' . $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bundled theme data file.
		$cache[ $file ] = $raw ? (array) json_decode( $raw, true ) : array();
	}
	return $cache[ $file ];
}

function dreamrugs_guides(): array {
	return (array) ( dreamrugs_json_data( 'guides.json' )['guides'] ?? array() );
}

function dreamrugs_landing_pages(): array {
	return (array) ( dreamrugs_json_data( 'landingPages.json' )['pages'] ?? array() );
}

/** A React-app path ("/catalog", "/collections/space/x") → this site's URL. */
function dreamrugs_app_url( string $path ): string {
	if ( '/catalog' === rtrim( $path, '/' ) && function_exists( 'wc_get_page_permalink' ) ) {
		return wc_get_page_permalink( 'shop' );
	}
	return home_url( trailingslashit( $path ) );
}

add_action( 'init', function () {
	add_rewrite_rule( '^guides/?$', 'index.php?drc_guide=__index', 'top' );
	add_rewrite_rule( '^guides/([^/]+)/?$', 'index.php?drc_guide=$matches[1]', 'top' );
	foreach ( dreamrugs_landing_pages() as $page ) {
		add_rewrite_rule( '^' . preg_quote( trim( $page['path'], '/' ), '#' ) . '/?$', 'index.php?drc_landing=' . rawurlencode( trim( $page['path'], '/' ) ), 'top' );
	}
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'drc_guide';
	$vars[] = 'drc_landing';
	return $vars;
} );

add_action( 'template_redirect', function () {
	$guide = get_query_var( 'drc_guide' );
	// An unknown guide slug goes to the index, like GuidePage.tsx's <Navigate>.
	if ( $guide && '__index' !== $guide && ! dreamrugs_find_guide( $guide ) ) {
		wp_safe_redirect( home_url( '/guides/' ), 302 );
		exit;
	}
} );

add_filter( 'template_include', function ( $template ) {
	$guide   = get_query_var( 'drc_guide' );
	$landing = get_query_var( 'drc_landing' );
	if ( $guide || $landing ) {
		// These aren't WP posts; stop the main query from reporting a 404.
		global $wp_query;
		$wp_query->is_404  = false;
		$wp_query->is_page = true;
		status_header( 200 );
	}
	if ( '__index' === $guide ) {
		return get_theme_file_path( 'page-templates/guides-index.php' );
	}
	if ( $guide ) {
		return get_theme_file_path( 'page-templates/guide.php' );
	}
	if ( $landing ) {
		return get_theme_file_path( 'page-templates/landing.php' );
	}
	return $template;
} );

function dreamrugs_find_guide( string $slug ): ?array {
	foreach ( dreamrugs_guides() as $g ) {
		if ( $g['slug'] === $slug ) {
			return $g;
		}
	}
	return null;
}

function dreamrugs_find_landing( string $path ): ?array {
	foreach ( dreamrugs_landing_pages() as $p ) {
		if ( trim( $p['path'], '/' ) === trim( rawurldecode( $path ), '/' ) ) {
			return $p;
		}
	}
	return null;
}
