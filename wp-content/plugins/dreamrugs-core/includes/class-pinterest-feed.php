<?php
/**
 * /pinterest-feed.xml — product feed for Pinterest Catalogs (Business
 * account > Catalogs > Data source > "Provide a URL link"; Pinterest
 * re-reads it daily). Port of the original app's pinterest_feed() in
 * backend/app/main.py: RSS 2.0 with the g: product namespace, one item per
 * published rug, priced at the default size through the quote engine
 * (DRC_Product_Data::display_price — the same price the product page and
 * its structured data show). Rugs with no calculable price or no image are
 * left out, since Pinterest requires both.
 *
 * Matched on the request path in template_redirect rather than a rewrite
 * rule, so it works without a permalink flush after deploy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Pinterest_Feed {

	const PATH          = '/pinterest-feed.xml';
	const CACHE_KEY     = 'drc_pinterest_feed';
	const RUG_CATEGORY  = 'Home & Garden > Decor > Rugs'; // Google product taxonomy 598
	const MAX_EXTRA_IMG = 10;

	public static function init() {
		// Priority 0: before redirect_canonical() can try to "fix" the URL.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		add_action( 'save_post_product', array( __CLASS__, 'flush' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'flush' ) );
	}

	public static function flush() {
		delete_transient( self::CACHE_KEY );
	}

	public static function maybe_serve() {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- compared only.
		$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( untrailingslashit( $home ) . self::PATH !== $path ) {
			return;
		}
		$xml = get_transient( self::CACHE_KEY );
		if ( false === $xml ) {
			$xml = self::build();
			set_transient( self::CACHE_KEY, $xml, 10 * MINUTE_IN_SECONDS );
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with esc_xml() below.
		exit;
	}

	public static function build(): string {
		$business = get_bloginfo( 'name' ) ?: 'DreamRugsCreation';
		$currency = DRC_Multi_Currency::base();
		$x        = function ( $value ) { return esc_xml( (string) $value ); };
		$items    = array();

		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'ID', 'order' => 'DESC' ) );
		foreach ( $products as $product ) {
			$sizes = DRC_Product_Data::size_variations( $product );
			$price = DRC_Product_Data::display_price( $product, $sizes );
			$cover = wp_get_attachment_image_url( $product->get_image_id(), 'full' );
			if ( null === $price || ! $cover ) {
				continue;
			}
			$name     = $product->get_name();
			$material = $product->get_meta( '_drc_material_name' ) ?: '';
			$weave    = $product->get_meta( '_drc_weave_type' ) ?: '';
			// Title and fallback description match single-product.php's SEO block.
			$title       = preg_match( '/\b(rug|carpet|dhurrie|runner)s?\b/i', $name ) ? $name : $name . ' Rug';
			$description = wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() );
			$description = $description ?: sprintf( '%s: a %s rug%s, handmade in India and custom-made to your exact size.', $name, strtolower( $material ?: 'handmade' ), $weave ? ', ' . $weave : '' );
			$extra       = array_slice( array_filter( array_map( fn( $id ) => wp_get_attachment_image_url( $id, 'full' ), $product->get_gallery_image_ids() ) ), 0, self::MAX_EXTRA_IMG );
			$default     = $sizes['variations'][ $sizes['default_index'] ] ?? null;

			$fields = array(
				'<g:id>' . (int) $product->get_id() . '</g:id>',
				'<title>' . $x( mb_substr( $title, 0, 500 ) ) . '</title>',
				'<description>' . $x( mb_substr( $description, 0, 10000 ) ) . '</description>',
				'<link>' . $x( get_permalink( $product->get_id() ) ) . '</link>',
				'<g:image_link>' . $x( $cover ) . '</g:image_link>',
			);
			foreach ( $extra as $url ) {
				$fields[] = '<g:additional_image_link>' . $x( $url ) . '</g:additional_image_link>';
			}
			$fields[] = '<g:price>' . number_format( $price, 2, '.', '' ) . ' ' . $x( $currency ) . '</g:price>';
			$fields[] = '<g:availability>' . ( $product->is_in_stock() ? 'in stock' : 'out of stock' ) . '</g:availability>';
			$fields[] = '<g:condition>new</g:condition>';
			$fields[] = '<g:brand>' . $x( $business ) . '</g:brand>';
			$fields[] = '<g:google_product_category>' . $x( self::RUG_CATEGORY ) . '</g:google_product_category>';
			$fields[] = '<g:product_type>' . $x( implode( ' > ', array_filter( array( 'Rugs', $weave ) ) ) ) . '</g:product_type>';
			if ( $material ) {
				$fields[] = '<g:material>' . $x( $material ) . '</g:material>';
			}
			if ( $default ) {
				$fields[] = '<g:size>' . $x( $default['ft'] . ' ft' ) . '</g:size>';
			}
			$items[] = "    <item>\n      " . implode( "\n      ", $fields ) . "\n    </item>";
		}

		return '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n"
			. "  <channel>\n"
			. '    <title>' . $x( $business . ' Rugs' ) . "</title>\n"
			. '    <link>' . $x( home_url( '/' ) ) . "</link>\n"
			. "    <description>Handmade rugs, made to order in Bhadohi, India.</description>\n"
			. implode( "\n", $items ) . "\n"
			. "  </channel>\n</rss>";
	}
}
