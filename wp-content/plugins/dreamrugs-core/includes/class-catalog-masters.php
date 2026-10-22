<?php
/**
 * Catalog masters and product detail fields — the parts of the original
 * admin's Catalog / CatalogSizes / CatalogAttributes / CatalogDisplayImages
 * screens that WooCommerce has no home for:
 *
 *  - Master lists (wp_options): weave types, pile heights and common sizes,
 *    each ordered with an active flag (WeaveTypeMaster, PileHeightMaster,
 *    CatalogSizeMaster). Spaces, moods and materials are the room_type /
 *    mood_tag / material_type taxonomies (Products → …).
 *  - A "Rug details" box on the product screen: inventory material, weave,
 *    pile height, lead time, HSN code, per-rug About and Additional
 *    Information HTML — replacing raw Custom Fields.
 *  - Curated collection images (CatalogDisplayImages): up to 3 images with
 *    captions per collection page; empty slots fall back like the original
 *    (matching rugs' photos → the 'default' set → bundled craft photos).
 *  - menu_options(): what the storefront mega menu lists (GET
 *    /customer/menu-options in the original).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Catalog_Masters {

	const OPT_WEAVES   = 'drc_master_weaves';
	const OPT_PILES    = 'drc_master_piles';
	const OPT_SIZES    = 'drc_master_sizes';
	const OPT_DISPLAYS = 'drc_collection_displays';

	// Original seed lists (WeaveTypeMaster / PileHeightMaster defaults).
	const DEFAULT_WEAVES = array( 'Hand Knotted', 'Hand Tufted', 'Flatweave', 'Machine Woven' );
	const DEFAULT_PILES  = array( 'Low', 'Medium', 'High' );

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_product_box' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_box' ) );
	}

	// ── Master lists ────────────────────────────────────────────────────

	private static function normalise( array $rows, string $type ): array {
		$out = array();
		foreach ( $rows as $i => $row ) {
			$name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
			if ( 'sizes' === $type ) {
				$ft = sanitize_text_field( (string) ( $row['ft'] ?? '' ) );
				$cm = sanitize_text_field( (string) ( $row['cm'] ?? '' ) );
				if ( '' === $ft && '' === $cm ) {
					continue;
				}
				$out[] = array( 'ft' => $ft, 'cm' => $cm, 'active' => ! isset( $row['active'] ) || (bool) $row['active'], 'sort' => $i );
				continue;
			}
			if ( '' === $name ) {
				continue;
			}
			$out[] = array( 'name' => $name, 'slug' => sanitize_title( $name ), 'active' => ! isset( $row['active'] ) || (bool) $row['active'], 'sort' => $i );
		}
		return $out;
	}

	/** Seeds a list the first time it's read: the original defaults plus
	 * any value products already use, so nothing in use goes missing. */
	private static function seed( string $type ): array {
		global $wpdb;
		if ( 'sizes' === $type ) {
			$rows = $wpdb->get_results(
				"SELECT DISTINCT ft.meta_value AS ft, cm.meta_value AS cm FROM {$wpdb->postmeta} ft
				 LEFT JOIN {$wpdb->postmeta} cm ON cm.post_id = ft.post_id AND cm.meta_key = '_drc_size_cm'
				 WHERE ft.meta_key = '_drc_size_ft' AND ft.meta_value <> '' ORDER BY ft.meta_value",
				ARRAY_A
			);
			return self::normalise( $rows, 'sizes' );
		}
		$key     = 'weaves' === $type ? '_drc_weave_type' : '_drc_pile_height';
		$in_use  = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> ''", $key ) );
		$names   = 'weaves' === $type ? self::DEFAULT_WEAVES : self::DEFAULT_PILES;
		$known   = array_map( 'sanitize_title', $names );
		foreach ( $in_use as $value ) {
			if ( ! in_array( sanitize_title( $value ), $known, true ) ) {
				$names[] = ucwords( str_replace( array( '-', '_' ), ' ', $value ) );
				$known[] = sanitize_title( $value );
			}
		}
		return self::normalise( array_map( function ( $n ) { return array( 'name' => $n ); }, $names ), $type );
	}

	private static function option_for( string $type ): string {
		return array( 'weaves' => self::OPT_WEAVES, 'piles' => self::OPT_PILES, 'sizes' => self::OPT_SIZES )[ $type ];
	}

	public static function get( string $type, bool $active_only = false ): array {
		$rows = get_option( self::option_for( $type ), null );
		if ( ! is_array( $rows ) ) {
			$rows = self::seed( $type );
			update_option( self::option_for( $type ), $rows, false );
		}
		if ( $active_only ) {
			$rows = array_values( array_filter( $rows, function ( $r ) { return ! empty( $r['active'] ); } ) );
		}
		return $rows;
	}

	public static function save( string $type, array $rows ): array {
		$rows = self::normalise( $rows, $type );
		update_option( self::option_for( $type ), $rows, false );
		return $rows;
	}

	/** Storefront mega-menu data (customer.py get_menu_options): every
	 * space/mood/material term in use, and active weave types. */
	public static function menu_options(): array {
		$terms = function ( string $taxonomy ) {
			$list = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'term_order' ) );
			return is_wp_error( $list ) ? array() : array_map( function ( $t ) { return array( 'slug' => $t->slug, 'name' => $t->name ); }, $list );
		};
		return array(
			'spaces'    => $terms( 'room_type' ),
			'moods'     => $terms( 'mood_tag' ),
			'materials' => $terms( 'material_type' ),
			'weaves'    => array_map( function ( $w ) { return array( 'slug' => $w['slug'], 'name' => $w['name'] ); }, self::get( 'weaves', true ) ),
			'piles'     => array_map( function ( $p ) { return array( 'slug' => $p['slug'], 'name' => $p['name'] ); }, self::get( 'piles', true ) ),
		);
	}

	// ── Collection display images ───────────────────────────────────────

	public static function displays(): array {
		$all = get_option( self::OPT_DISPLAYS, array() );
		return is_array( $all ) ? $all : array();
	}

	/** The vendor's curated slots for a collection page ('default' = the
	 * main catalog page): always 3 entries, empty image_url where unset. */
	public static function display_images( string $facet, string $value = '' ): array {
		$all    = self::displays();
		$key    = 'default' === $facet ? 'default' : $facet . '/' . sanitize_title( $value );
		$custom = array_values( (array) ( $all[ $key ] ?? array() ) );
		$out    = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$out[] = array(
				'image_url' => (string) ( $custom[ $i ]['image_url'] ?? '' ),
				'caption'   => (string) ( $custom[ $i ]['caption'] ?? '' ),
			);
		}
		return $out;
	}

	// ── REST ────────────────────────────────────────────────────────────

	public static function register_routes() {
		register_rest_route( 'dreamrugs/v1', '/customer/menu-options', array(
			'methods'             => 'GET',
			'callback'            => function () { return new WP_REST_Response( self::menu_options(), 200 ); },
			'permission_callback' => '__return_true',
		) );
		$auth = array( 'DRC_Rest_Routes', 'require_manage_woocommerce' );
		register_rest_route( 'dreamrugs/v1', '/admin/masters/(?P<type>weaves|piles|sizes)', array(
			array(
				'methods'             => 'GET',
				'callback'            => function ( WP_REST_Request $r ) { return new WP_REST_Response( self::get( $r['type'] ), 200 ); },
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => function ( WP_REST_Request $r ) { return new WP_REST_Response( self::save( $r['type'], (array) $r->get_param( 'rows' ) ), 200 ); },
				'permission_callback' => $auth,
			),
		) );
		register_rest_route( 'dreamrugs/v1', '/admin/collection-displays', array(
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_displays' ),
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'PUT',
				'callback'            => array( __CLASS__, 'save_display' ),
				'permission_callback' => $auth,
			),
		) );
	}

	/** Every collection page that exists (each space/mood/material term and
	 * active weave) with its curated images. */
	public static function list_displays() {
		$opts   = self::menu_options();
		$saved  = self::displays();
		$pages  = array( array( 'key' => 'default', 'facet' => 'catalog', 'value' => '', 'label' => 'All rugs (main catalog page)', 'images' => array_values( (array) ( $saved['default'] ?? array() ) ) ) );
		foreach ( array( 'space' => 'spaces', 'mood' => 'moods', 'material' => 'materials', 'weave' => 'weaves' ) as $facet => $key ) {
			foreach ( $opts[ $key ] as $item ) {
				$k       = $facet . '/' . sanitize_title( $item['slug'] );
				$pages[] = array( 'key' => $k, 'facet' => $facet, 'value' => $item['slug'], 'label' => $item['name'], 'images' => array_values( (array) ( $saved[ $k ] ?? array() ) ) );
			}
		}
		return new WP_REST_Response( $pages, 200 );
	}

	public static function save_display( WP_REST_Request $request ) {
		$key = sanitize_text_field( (string) $request->get_param( 'key' ) );
		if ( 'default' !== $key && ! preg_match( '#^(space|mood|material|weave)/[a-z0-9_\-]+$#', $key ) ) {
			return new WP_Error( 'drc_bad_key', 'Unknown collection page.', array( 'status' => 400 ) );
		}
		$images = array();
		foreach ( array_slice( (array) $request->get_param( 'images' ), 0, 3 ) as $img ) {
			$images[] = array(
				'image_url' => esc_url_raw( (string) ( $img['image_url'] ?? '' ) ),
				'caption'   => sanitize_text_field( (string) ( $img['caption'] ?? '' ) ),
			);
		}
		$all = self::displays();
		if ( array_filter( wp_list_pluck( $images, 'image_url' ) ) ) {
			$all[ $key ] = $images;
		} else {
			unset( $all[ $key ] );
		}
		update_option( self::OPT_DISPLAYS, $all, false );
		return new WP_REST_Response( array( 'key' => $key, 'images' => $images ), 200 );
	}

	// ── Product "Rug details" box ───────────────────────────────────────

	public static function add_product_box() {
		add_meta_box( 'drc-rug-details', 'Rug details (DreamRugs)', array( __CLASS__, 'render_product_box' ), 'product', 'normal', 'high' );
	}

	public static function render_product_box( $post ) {
		global $wpdb;
		wp_nonce_field( 'drc_rug_details', 'drc_rug_details_nonce' );
		$get       = function ( $k ) use ( $post ) { return (string) get_post_meta( $post->ID, $k, true ); };
		$materials = $wpdb->get_results( "SELECT id, name, type, color, is_available FROM {$wpdb->prefix}drc_materials ORDER BY name ASC", ARRAY_A );
		$select    = function ( string $name, array $options, string $current, string $empty = '— None —' ) {
			echo '<select name="' . esc_attr( $name ) . '" style="min-width:260px;"><option value="">' . esc_html( $empty ) . '</option>';
			$found = false;
			foreach ( $options as $value => $label ) {
				$sel   = (string) $value === $current;
				$found = $found || $sel;
				echo '<option value="' . esc_attr( $value ) . '"' . selected( $sel, true, false ) . '>' . esc_html( $label ) . '</option>';
			}
			if ( '' !== $current && ! $found ) {
				echo '<option value="' . esc_attr( $current ) . '" selected>' . esc_html( $current ) . ' (not in master list)</option>';
			}
			echo '</select>';
		};
		$weaves = array();
		foreach ( self::get( 'weaves' ) as $w ) {
			$weaves[ $w['name'] ] = $w['name'] . ( $w['active'] ? '' : ' (inactive)' );
		}
		$piles = array();
		foreach ( self::get( 'piles' ) as $p ) {
			$piles[ $p['name'] ] = $p['name'] . ( $p['active'] ? '' : ' (inactive)' );
		}
		$mat_opts = array();
		foreach ( $materials as $m ) {
			$mat_opts[ $m['id'] ] = sprintf( '%s (%s, %s)%s', $m['name'], $m['type'], $m['color'], $m['is_available'] ? '' : ' — unavailable' );
		}
		?>
		<table class="form-table"><tbody>
			<tr><th>Inventory material</th><td><?php $select( 'drc_material_id', $mat_opts, $get( '_drc_material_id' ) ); ?><p class="description">Prices the rug and is deducted from stock when an order is paid (DreamRugs → Inventory).</p></td></tr>
			<tr><th>Weave type</th><td><?php $select( 'drc_weave_type', $weaves, $get( '_drc_weave_type' ) ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=dreamrugs-catalog-masters' ) ); ?>">Edit list</a></td></tr>
			<tr><th>Pile height</th><td><?php $select( 'drc_pile_height', $piles, $get( '_drc_pile_height' ) ); ?></td></tr>
			<tr><th>Default lead time (days)</th><td><input type="number" min="1" name="drc_lead_time_days" value="<?php echo esc_attr( $get( '_drc_lead_time_days' ) ?: '21' ); ?>" style="width:100px;"></td></tr>
			<tr><th>HSN code</th><td><input type="text" name="drc_hsn_code" value="<?php echo esc_attr( $get( '_drc_hsn_code' ) ?: '5703' ); ?>" style="width:100px;" maxlength="10"><p class="description">Printed on GST invoices (rugs: 5701–5705).</p></td></tr>
		</tbody></table>
		<p><strong>About this rug</strong> — shown in the product page's Description when the short description is empty.</p>
		<?php
		wp_editor( $get( '_drc_about_content_html' ), 'drc_about_content_html', array( 'textarea_rows' => 6, 'media_buttons' => false, 'teeny' => true ) );
		?>
		<p style="margin-top:16px;"><strong>Additional information</strong> — this rug's own "Additional Information" accordion text. Leave empty to use the default from DreamRugs → Settings.</p>
		<?php
		wp_editor( $get( '_drc_additional_information_html' ), 'drc_additional_information_html', array( 'textarea_rows' => 6, 'media_buttons' => false, 'teeny' => true ) );
	}

	public static function save_product_box( $post_id ) {
		if ( ! isset( $_POST['drc_rug_details_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['drc_rug_details_nonce'] ) ), 'drc_rug_details' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		$material_id = isset( $_POST['drc_material_id'] ) ? absint( $_POST['drc_material_id'] ) : 0;
		update_post_meta( $post_id, '_drc_material_id', $material_id ?: '' );
		if ( $material_id ) {
			$m = DRC_Quote_Engine::get_material( $material_id );
			if ( $m ) {
				// Denormalised for the product page and quote form labels.
				update_post_meta( $post_id, '_drc_material_name', $m['name'] );
				update_post_meta( $post_id, '_drc_material_type', sanitize_title( $m['type'] ) );
			}
		}
		foreach ( array( 'drc_weave_type' => '_drc_weave_type', 'drc_pile_height' => '_drc_pile_height', 'drc_hsn_code' => '_drc_hsn_code' ) as $field => $meta ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		if ( isset( $_POST['drc_lead_time_days'] ) ) {
			update_post_meta( $post_id, '_drc_lead_time_days', max( 1, absint( $_POST['drc_lead_time_days'] ) ) );
		}
		foreach ( array( 'drc_about_content_html' => '_drc_about_content_html', 'drc_additional_information_html' => '_drc_additional_information_html' ) as $field => $meta ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, $meta, wp_kses_post( wp_unslash( $_POST[ $field ] ) ) );
			}
		}
		// phpcs:enable
	}
}
