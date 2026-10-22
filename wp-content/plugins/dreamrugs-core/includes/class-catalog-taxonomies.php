<?php
/**
 * room_type / mood_tag / material_type as WooCommerce product taxonomies —
 * the "Shop by Space / Mood / Material" facets from the original app's
 * RugCatalog.room_types / mood_tags JSON columns (plus material_type,
 * treated the same way here for a consistent facet URL scheme). Real
 * taxonomies give free archive pages, term-based filtering, and clean
 * rewrite URLs matching the original /catalog/space/<slug>,
 * /catalog/mood/<slug>, /catalog/material/<slug> paths — see
 * archive-product.php, which is used for all of these via WordPress's
 * normal taxonomy-archive template hierarchy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Catalog_Taxonomies {

	public static function register() {
		register_taxonomy( 'room_type', 'product', array(
			'label'             => 'Room Types',
			'hierarchical'      => false,
			'public'            => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'collections/space' ),
		) );

		register_taxonomy( 'mood_tag', 'product', array(
			'label'             => 'Mood Tags',
			'hierarchical'      => false,
			'public'            => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'collections/mood' ),
		) );

		register_taxonomy( 'material_type', 'product', array(
			'label'             => 'Material',
			'hierarchical'      => false,
			'public'            => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array( 'slug' => 'collections/material' ),
		) );
	}
}
