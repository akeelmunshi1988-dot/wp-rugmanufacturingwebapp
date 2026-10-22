<?php
/**
 * Per-page SEO the original set with its <SEO> component (title,
 * description, noindex, JSON-LD). A template calls dreamrugs_seo() before
 * get_header(); the values reach the page through Rank Math's filters when
 * Rank Math is active (so its OG/Twitter tags pick them up too), or as
 * plain <title>/<meta> tags when it isn't. JSON-LD blocks are always
 * printed here — Rank Math doesn't know about these page types.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** @param array{title?:string,description?:string,noindex?:bool,jsonld?:array,canonical?:string} $args */
function dreamrugs_seo( array $args ): void {
	$GLOBALS['dreamrugs_seo'] = array_merge( dreamrugs_seo_state(), $args );
}

function dreamrugs_seo_state(): array {
	return isset( $GLOBALS['dreamrugs_seo'] ) && is_array( $GLOBALS['dreamrugs_seo'] ) ? $GLOBALS['dreamrugs_seo'] : array();
}

/** Adds JSON-LD blocks without replacing ones set earlier. */
function dreamrugs_seo_jsonld( array $block ): void {
	$state             = dreamrugs_seo_state();
	$state['jsonld'][] = $block;
	$GLOBALS['dreamrugs_seo'] = $state;
}

function dreamrugs_seo_full_title( string $title ): string {
	return $title . ' | ' . get_bloginfo( 'name' );
}

add_filter( 'pre_get_document_title', function ( $title ) {
	$s = dreamrugs_seo_state();
	return ! empty( $s['title'] ) ? dreamrugs_seo_full_title( $s['title'] ) : $title;
}, 20 );
add_filter( 'rank_math/frontend/title', function ( $title ) {
	$s = dreamrugs_seo_state();
	return ! empty( $s['title'] ) ? dreamrugs_seo_full_title( $s['title'] ) : $title;
} );
add_filter( 'rank_math/frontend/description', function ( $desc ) {
	$s = dreamrugs_seo_state();
	return ! empty( $s['description'] ) ? $s['description'] : $desc;
} );
add_filter( 'rank_math/frontend/robots', function ( $robots ) {
	$s = dreamrugs_seo_state();
	if ( ! empty( $s['noindex'] ) ) {
		$robots['index'] = 'noindex';
	}
	return $robots;
} );
add_filter( 'rank_math/frontend/canonical', function ( $url ) {
	$s = dreamrugs_seo_state();
	return ! empty( $s['canonical'] ) ? $s['canonical'] : $url;
} );

// noindex goes into WordPress's own robots tag (one tag, not two).
add_filter( 'wp_robots', function ( $robots ) {
	if ( ! empty( dreamrugs_seo_state()['noindex'] ) ) {
		$robots['noindex'] = true;
		$robots['follow']  = true;
		unset( $robots['max-image-preview'] );
	}
	return $robots;
} );

add_action( 'wp_head', function () {
	$s = dreamrugs_seo_state();
	// Rank Math prints its head on wp_head priority 1 via 'rank_math/head';
	// if it didn't (inactive, or its setup wizard was never finished),
	// these pages still get their description/robots/canonical.
	if ( ! did_action( 'rank_math/head' ) ) {
		if ( ! empty( $s['description'] ) ) {
			echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $s['description'] ) ) . '">' . "\n";
		}
		if ( ! empty( $s['canonical'] ) ) {
			echo '<link rel="canonical" href="' . esc_url( $s['canonical'] ) . '">' . "\n";
		}
	}
	foreach ( (array) ( $s['jsonld'] ?? array() ) as $block ) {
		echo '<script type="application/ld+json">' . wp_json_encode( $block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}, 5 );

/** BreadcrumbList JSON-LD from [[name, url], ...]. */
function dreamrugs_breadcrumb_jsonld( array $crumbs ): array {
	$items = array();
	foreach ( array_values( $crumbs ) as $i => $c ) {
		$items[] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c[0], 'item' => $c[1] );
	}
	return array( '@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items );
}

/** FAQPage JSON-LD from [{q, a}, ...] (null when empty). */
function dreamrugs_faq_jsonld( array $faq ): ?array {
	if ( ! $faq ) {
		return null;
	}
	return array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map( function ( $f ) {
			return array( '@type' => 'Question', 'name' => $f['q'], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f['a'] ) );
		}, $faq ),
	);
}

// Rug pages print their own Product schema (above); drop Rank Math's so
// search engines don't see two different Product entities.
add_filter( 'rank_math/json_ld', function ( $data ) {
	if ( function_exists( 'is_product' ) && is_product() && is_array( $data ) ) {
		foreach ( $data as $key => $entity ) {
			if ( isset( $entity['@type'] ) && ( 'Product' === $entity['@type'] || ( is_array( $entity['@type'] ) && in_array( 'Product', $entity['@type'], true ) ) ) ) {
				unset( $data[ $key ] );
			}
		}
	}
	return $data;
}, 99 );

/**
 * Titles, descriptions and noindex for the plain pages, taken from each
 * original page's <SEO /> (AboutUs.tsx, RugServices.tsx, PrivacyPolicy.tsx,
 * CustomerMyOrders.tsx…) — without this they fell back to WordPress's
 * "Page – Site" titles with no description. Templates that call
 * dreamrugs_seo() themselves (rug, catalog, guides…) aren't affected.
 */
add_action( 'template_redirect', function () {
	if ( ! empty( dreamrugs_seo_state()['title'] ) ) {
		return;
	}
	$business = get_bloginfo( 'name' );
	$pages    = array(
		'about'                      => array( 'About ' . $business, 'Discover ' . $business . "'s artisan rug workshop, considered materials, and made-to-measure craftsmanship." ),
		'refund-cancellation-policy' => array( 'Refund & Cancellation Policy', 'Read our refund and order cancellation policy.' ),
		'privacy-policy'             => array( 'Privacy Policy', 'Learn how we collect, use, store, and protect customer information.' ),
		'colour-matching'            => array( 'Colour Matching', 'Plan your bespoke rug colour with reference codes, swatches and yarn samples.' ),
		'rug-size-guide'             => array( 'Rug Size Guide', 'Explore rug placement for living rooms, dining rooms and bedrooms.' ),
		'trade-enquiry'              => array( 'Trade Enquiry', 'Discuss a rug project with our studio: design, materials, sizes and production requirements.' ),
		'order-tracking'             => array( 'Order Tracking', 'Find your rug order and follow the dispatch information shared with you.' ),
		'custom-rug-request'         => array( 'Request a Custom Rug', 'Tell us about the rug you have in mind — size, material, style, and budget — and our team will send you a personalized quote.' ),
		'my-orders'                  => array( 'My Orders', 'View and track your custom rug orders.', true ),
		'my-quotes'                  => array( 'My Quotes', 'View and respond to quotes sent to you.', true ),
		'account'                    => array( 'Sign In', 'Sign in to your account to track orders and manage quotes.', true ),
	);
	$seo = null;
	foreach ( $pages as $slug => $meta ) {
		if ( is_page( $slug ) ) {
			$seo = $meta;
			break;
		}
	}
	if ( ! $seo && function_exists( 'is_cart' ) ) {
		if ( is_cart() ) {
			$seo = array( 'Your Cart', 'Review the rugs in your cart before checkout.', true );
		} elseif ( is_wc_endpoint_url( 'order-received' ) ) {
			$seo = array( 'Order Confirmed', 'Your custom rug order has been placed.', true );
		} elseif ( is_checkout() ) {
			$seo = array( 'Checkout', 'Complete your custom rug order.', true );
		}
	}
	if ( ! $seo && is_post_type_archive( 'drc_story' ) ) {
		$seo = array( 'Design Stories', 'The stories behind our unique handmade rug designs — what inspired each one, and how our artisans brought it to life on the loom.' );
	}
	if ( ! $seo && class_exists( 'DRC_Project_Gallery_Routes' ) && get_query_var( DRC_Project_Gallery_Routes::LIST_QUERY_VAR ) ) {
		$seo = array( 'Rugs in Their New Homes', "A gallery of real DreamRugsCreation rugs, installed in customers' own homes — from living rooms to bedrooms, every project in its finished setting." );
	}
	if ( $seo ) {
		dreamrugs_seo( array( 'title' => $seo[0], 'description' => $seo[1], 'noindex' => ! empty( $seo[2] ) ) );
	}
}, 20 );
