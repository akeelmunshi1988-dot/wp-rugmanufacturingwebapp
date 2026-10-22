<?php
/**
 * The storefront menu in one place — top nav, the "Collection" mega menu
 * (built from the catalog's own spaces/moods/materials/weaves, like
 * storefrontMenu.ts collectionMenu()), and the vendor's label overrides
 * and show/hide choices (storefront_menu_labels / _visibility). Keys match
 * the original app: "nav:/catalog", "heading:space", "link:/collections/space/bedroom".
 *
 * Also owns the /collections/{space|mood|material}/{slug} URLs (the
 * original's canonical collection pages); /catalog/{facet}/{slug} keeps
 * working for old links.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Storefront_Menu {

	const FACETS = array( 'space' => 'room_type', 'mood' => 'mood_tag', 'material' => 'material_type' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ), 20 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/** The taxonomies' own permastructs are /collections/{facet}/; these
	 * keep the earlier /catalog/{facet}/ paths resolving too. */
	public static function add_rewrite_rules() {
		foreach ( self::FACETS as $facet => $taxonomy ) {
			add_rewrite_rule( '^catalog/' . $facet . '/([^/]+)/page/([0-9]+)/?$', 'index.php?' . $taxonomy . '=$matches[1]&paged=$matches[2]', 'top' );
			add_rewrite_rule( '^catalog/' . $facet . '/([^/]+)/?$', 'index.php?' . $taxonomy . '=$matches[1]', 'top' );
		}
	}

	public static function labels(): array {
		return (array) DRC_Settings::get( 'storefront_menu_labels' );
	}

	public static function label( string $key, string $fallback ): string {
		$labels = self::labels();
		return isset( $labels[ $key ] ) && '' !== trim( (string) $labels[ $key ] ) ? trim( (string) $labels[ $key ] ) : $fallback;
	}

	public static function visible( string $key ): bool {
		$vis = (array) DRC_Settings::get( 'storefront_menu_visibility' );
		return ! ( isset( $vis[ $key ] ) && false === $vis[ $key ] );
	}

	public static function shop_url(): string {
		return function_exists( 'wc_get_page_id' ) ? get_permalink( wc_get_page_id( 'shop' ) ) : home_url( '/catalog/' );
	}

	/** @return array<int,array{key:string,url:string,label:string,default:string}> */
	public static function nav( bool $include_hidden = false ): array {
		$items = array(
			array( 'key' => 'nav:/', 'url' => home_url( '/' ), 'default' => 'Home' ),
			array( 'key' => 'nav:/catalog', 'url' => self::shop_url(), 'default' => 'Collection' ),
			array( 'key' => 'nav:/custom-rug-request', 'url' => home_url( '/custom-rug-request/' ), 'default' => 'Create Your Bespoke Rug' ),
			array( 'key' => 'nav:/about', 'url' => home_url( '/about/' ), 'default' => 'About Us' ),
		);
		$out = array();
		foreach ( $items as $item ) {
			if ( $include_hidden || self::visible( $item['key'] ) ) {
				$item['label'] = self::label( $item['key'], $item['default'] );
				$out[]         = $item;
			}
		}
		return $out;
	}

	private static function humanise( string $slug ): string {
		return ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );
	}

	/** Mega-menu groups, data-driven (collectionMenu()). Empty groups and
	 * hidden headings/links are dropped unless $include_hidden. */
	public static function mega( bool $include_hidden = false ): array {
		$opts   = DRC_Catalog_Masters::menu_options();
		$groups = array();
		foreach ( array( 'space' => 'spaces', 'mood' => 'moods', 'material' => 'materials' ) as $facet => $key ) {
			$groups[ $facet ] = array(
				'default' => array( 'space' => 'Shop by Space', 'mood' => 'Shop by Mood', 'material' => 'Shop by Material' )[ $facet ],
				'links'   => array_map( function ( $t ) use ( $facet ) {
					$path = '/collections/' . $facet . '/' . $t['slug'];
					return array( 'key' => 'link:' . $path, 'url' => home_url( $path . '/' ), 'default' => 'material' === $facet ? $t['name'] : self::humanise( $t['name'] ) );
				}, $opts[ $key ] ),
			);
		}
		$groups['weave'] = array(
			'default' => 'Shop by Weave Type',
			'links'   => array_map( function ( $w ) {
				$path = '/weaves/' . $w['slug'];
				return array( 'key' => 'link:' . $path, 'url' => home_url( $path . '/' ), 'default' => $w['name'] );
			}, $opts['weaves'] ),
		);
		$groups['quick'] = array(
			'default' => 'Quick Links',
			'links'   => array(
				array( 'key' => 'link:/catalog', 'url' => self::shop_url(), 'default' => 'All Rugs' ),
				array( 'key' => 'link:/custom-rug-request', 'url' => home_url( '/custom-rug-request/' ), 'default' => 'Create Your Bespoke Rug' ),
			),
		);

		$out = array();
		foreach ( $groups as $key => $group ) {
			$heading_key = 'heading:' . $key;
			if ( ! $include_hidden && ! self::visible( $heading_key ) ) {
				continue;
			}
			$links = array();
			foreach ( $group['links'] as $link ) {
				if ( $include_hidden || self::visible( $link['key'] ) ) {
					$link['label'] = self::label( $link['key'], $link['default'] );
					$links[]       = $link;
				}
			}
			if ( ! $links && ! $include_hidden ) {
				continue;
			}
			$out[ $key ] = array(
				'key'     => $heading_key,
				'default' => $group['default'],
				'heading' => self::label( $heading_key, $group['default'] ),
				'links'   => $links,
			);
		}
		return $out;
	}

	public static function register_routes() {
		register_rest_route( 'dreamrugs/v1', '/admin/menu-structure', array(
			'methods'             => 'GET',
			'callback'            => function () {
				return new WP_REST_Response( array(
					'nav'        => self::nav( true ),
					'mega'       => self::mega( true ),
					'labels'     => (object) self::labels(),
					'visibility' => (object) (array) DRC_Settings::get( 'storefront_menu_visibility' ),
				), 200 );
			},
			'permission_callback' => array( 'DRC_Rest_Routes', 'require_manage_woocommerce' ),
		) );
	}
}
