<?php
/**
 * The rest of the one-time data import (migration/migrate_to_woocommerce.py)
 * — the tables the first importer phases didn't cover:
 *
 *   POST /admin/import/promo-codes            PromoCode       → WooCommerce coupon
 *   POST /admin/import/reviews                RugReview       → product review (comment)
 *   POST /admin/import/newsletter             NewsletterSubscriber (bulk) → drc_newsletter_subscribers
 *   POST /admin/import/content                WorkshopPhoto, CustomRugPageImage, ShowcaseVideo,
 *                                             Testimonial, ProjectGalleryItem, RugJourneyStep,
 *                                             AnnouncementMessage, FAQ, TradeEnquiry,
 *                                             HomepageEnquiry → the DRC_Content_Types posts
 *   POST /admin/import/inventory-transactions InventoryTransaction → wp_drc_inventory_transactions
 *   POST /admin/import/settings               Tenant (business settings, name, logo, store currency) → drc_settings
 *   POST /admin/import/staff                  StaffUser → WordPress user (Administrator / Shop Manager)
 *   POST /admin/import/masters                Size / weave / pile-height / space / mood masters
 *   POST /admin/import/collection-displays    CollectionDisplay → drc_collection_displays
 *   POST /admin/import/email-templates        EmailTemplate (vendor-edited) → drc_email_templates
 *   POST /admin/import/rules                  PricingRule / MOQRule / ProductionTimeline (replaces the table)
 *   POST /admin/import/order-history          OrderStatusHistory → order notes
 *   POST /admin/import/promo-redemptions      PromoRedemption → the coupon's "used by" list
 *   POST /admin/import/stories                RugStory → drc_story post
 *
 * Same contract as the existing import routes: manage_woocommerce via an
 * Application Password, idempotent on a legacy-id back-reference (409 with
 * the existing id on a re-run). Images are copied into the media library
 * (the old server goes away); a file that can't be fetched keeps its
 * original URL / is reported, never fails the row.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Import_Routes {

	const NS = 'dreamrugs/v1';

	/** Source content type → [post type, title field, image field, meta fields]. */
	const CONTENT = array(
		'workshop_photo'        => array( 'drc_workshop_photo', 'caption', 'image_url', array( 'description', 'sort_order', 'is_active' ) ),
		'custom_rug_page_image' => array( 'drc_rug_step_image', 'title', 'image_url', array( 'sort_order', 'is_active' ) ),
		'showcase_video'        => array( 'drc_showcase_video', 'title', null, array( 'description', 'video_url', 'poster_url', 'sort_order', 'is_active', 'is_intro', 'tab_name' ) ),
		'testimonial'           => array( 'drc_testimonial', 'author_name', 'photo_url', array( 'author_title', 'country', 'quote', 'rating', 'sort_order', 'is_active' ) ),
		'project_gallery'       => array( 'drc_gallery_item', 'caption', 'image_url', array( 'link_url', 'description', 'owner_name', 'owner_message', 'rating', 'sort_order', 'is_active' ) ),
		'journey_step'          => array( 'drc_journey_step', 'title', null, array( 'description', 'sort_order', 'is_active' ) ),
		'announcement'          => array( 'drc_announcement', 'text', null, array( 'link_url', 'sort_order', 'is_active' ) ),
		'faq'                   => array( 'drc_faq', 'question', null, array( 'answer', 'product_id', 'sort_order', 'is_active' ) ),
		'trade_enquiry'         => array( 'drc_trade_enquiry', null, null, array( 'first_name', 'last_name', 'email', 'phone', 'city', 'country', 'profession', 'company', 'website', 'project_type', 'project_brief', 'is_read' ) ),
		'homepage_enquiry'      => array( 'drc_homepage_enquiry', null, null, array( 'name', 'email', 'subject', 'message', 'consent', 'is_read' ) ),
	);

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		foreach ( array(
			'/admin/import/promo-codes'            => 'import_promo_code',
			'/admin/import/reviews'                => 'import_review',
			'/admin/import/newsletter'             => 'import_newsletter',
			'/admin/import/content'                => 'import_content',
			'/admin/import/inventory-transactions' => 'import_inventory_transaction',
			'/admin/import/settings'               => 'import_settings',
			'/admin/import/staff'                  => 'import_staff',
			'/admin/import/masters'                => 'import_masters',
			'/admin/import/collection-displays'    => 'import_collection_display',
			'/admin/import/email-templates'        => 'import_email_template',
			'/admin/import/rules'                  => 'import_rules',
			'/admin/import/order-history'          => 'import_order_history',
			'/admin/import/promo-redemptions'      => 'import_promo_redemptions',
			'/admin/import/stories'                => 'import_story',
		) as $route => $callback ) {
			register_rest_route( self::NS, $route, array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, $callback ),
				'permission_callback' => array( 'DRC_Rest_Routes', 'require_manage_woocommerce' ),
			) );
		}
	}

	private static function conflict( string $what, string $key, int $id ): WP_Error {
		return new WP_Error( 'drc_already_imported', $what . ' already imported.', array( 'status' => 409, $key => $id ) );
	}

	private static function local_time( $iso ): ?string {
		return $iso ? get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( (string) $iso ) ) ) : null;
	}

	/** Copies a remote file into the media library. @return int attachment id, 0 on failure */
	public static function sideload( string $url, int $parent = 0, string $desc = '' ): int {
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		// The same source file (e.g. a hero image also used on a gallery item)
		// is copied once and reused.
		$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_drc_source_url', 'meta_value' => $url, 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		if ( $existing ) {
			return (int) $existing[0];
		}
		$tmp = download_url( $url, 120 );
		if ( is_wp_error( $tmp ) ) {
			return 0;
		}
		$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ?: 'import';
		$id   = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $parent, $desc );
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			return 0;
		}
		update_post_meta( $id, '_drc_source_url', $url );
		return (int) $id;
	}

	/** Copies a remote media URL and returns the new local URL (or the
	 * original when it can't be copied). Local URLs pass through. */
	private static function local_media_url( string $url, array &$warnings ): string {
		if ( '' === $url || 0 === strpos( $url, home_url() ) || ! preg_match( '#^https?://#', $url ) ) {
			return $url;
		}
		$att = self::sideload( $url );
		if ( ! $att ) {
			$warnings[] = 'Could not copy ' . $url . '; kept the original URL.';
			return esc_url_raw( $url );
		}
		return (string) wp_get_attachment_url( $att );
	}

	/** Walks a settings value and copies every media URL it holds (keys like
	 * image_url, photo_url, *_url pointing at images/video/PDF). */
	private static function localise_media( $value, string $key, array &$warnings ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::localise_media( $v, is_string( $k ) ? $k : $key, $warnings );
			}
			return $value;
		}
		if ( is_string( $value ) && preg_match( '/(^|_)(image_url|photo_url|media_url|logo_url|poster_url|pdf_url|url)$/', $key )
			&& preg_match( '#\.(jpe?g|png|gif|webp|avif|svg|mp4|webm|mov|pdf)(\?.*)?$#i', $value ) ) {
			return self::local_media_url( $value, $warnings );
		}
		return $value;
	}

	// ── Promo codes → coupons ───────────────────────────────────────────

	public static function import_promo_code( WP_REST_Request $request ) {
		$legacy_id = (int) $request->get_param( 'legacy_promo_id' );
		$code      = wc_format_coupon_code( (string) $request->get_param( 'code' ) );
		if ( ! $legacy_id || '' === $code ) {
			return new WP_Error( 'drc_bad_request', 'legacy_promo_id and code are required.', array( 'status' => 400 ) );
		}
		$existing = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'meta_key' => '_legacy_promo_id', 'meta_value' => $legacy_id, 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		if ( $existing ) {
			return self::conflict( 'Promo code', 'coupon_id', (int) $existing[0] );
		}
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return new WP_Error( 'drc_code_taken', 'A coupon with this code already exists.', array( 'status' => 409, 'coupon_id' => wc_get_coupon_id_by_code( $code ) ) );
		}
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		// promo_engine.py: "percentage" | "flat" (a fixed amount off the order).
		$coupon->set_discount_type( 'percentage' === $request->get_param( 'discount_type' ) ? 'percent' : 'fixed_cart' );
		$coupon->set_amount( (float) $request->get_param( 'discount_value' ) );
		if ( $request->get_param( 'min_order_value' ) ) {
			$coupon->set_minimum_amount( (float) $request->get_param( 'min_order_value' ) );
		}
		if ( $request->get_param( 'max_uses' ) ) {
			$coupon->set_usage_limit( (int) $request->get_param( 'max_uses' ) );
		}
		if ( $request->get_param( 'one_per_customer' ) ) {
			$coupon->set_usage_limit_per_user( 1 );
		}
		if ( $request->get_param( 'expires_at' ) ) {
			$coupon->set_date_expires( strtotime( (string) $request->get_param( 'expires_at' ) ) );
		}
		if ( $request->get_param( 'used_count' ) ) {
			$coupon->set_usage_count( (int) $request->get_param( 'used_count' ) );
		}
		$coupon->set_status( $request->get_param( 'is_active' ) ? 'publish' : 'draft' );
		$coupon->update_meta_data( '_legacy_promo_id', $legacy_id );
		if ( $request->get_param( 'starts_at' ) ) {
			$coupon->update_meta_data( DRC_Coupon_Start_Date::META, gmdate( 'Y-m-d', strtotime( (string) $request->get_param( 'starts_at' ) ) ) );
		}
		$id = $coupon->save();
		return new WP_REST_Response( array( 'coupon_id' => $id ), 201 );
	}

	// ── Reviews ─────────────────────────────────────────────────────────

	public static function import_review( WP_REST_Request $request ) {
		$legacy_id  = (int) $request->get_param( 'legacy_review_id' );
		$product_id = (int) $request->get_param( 'product_id' );
		$rating     = (int) $request->get_param( 'rating' );
		if ( ! $legacy_id || ! $product_id || ! wc_get_product( $product_id ) || $rating < 1 || $rating > 5 ) {
			return new WP_Error( 'drc_bad_request', 'legacy_review_id, a valid product_id and a 1–5 rating are required.', array( 'status' => 400 ) );
		}
		$existing = get_comments( array( 'type' => 'review', 'meta_key' => '_legacy_review_id', 'meta_value' => $legacy_id, 'status' => 'all', 'number' => 1, 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		if ( $existing ) {
			return self::conflict( 'Review', 'comment_id', (int) $existing[0] );
		}
		$approved = array( 'approved' => 1, 'pending' => 0, 'rejected' => 'trash' )[ (string) $request->get_param( 'status' ) ] ?? 0;
		$created  = self::local_time( $request->get_param( 'created_at' ) );
		$comment_id = wp_insert_comment( array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => sanitize_text_field( (string) $request->get_param( 'name' ) ),
			'comment_author_email' => sanitize_email( (string) $request->get_param( 'email' ) ),
			'comment_content'      => sanitize_textarea_field( (string) $request->get_param( 'body' ) ),
			'comment_type'         => 'review',
			'comment_approved'     => $approved,
			'user_id'              => (int) $request->get_param( 'customer_user_id' ),
			'comment_date'         => $created ?: current_time( 'mysql' ),
			'comment_date_gmt'     => $created ? get_gmt_from_date( $created ) : current_time( 'mysql', true ),
			'comment_meta'         => array(
				'rating'            => $rating,
				'verified'          => $request->get_param( 'is_verified_buyer' ) ? 1 : 0,
				'drc_title'         => sanitize_text_field( (string) $request->get_param( 'title' ) ),
				'_legacy_review_id' => $legacy_id,
			),
		) );
		if ( ! $comment_id ) {
			return new WP_Error( 'drc_insert_failed', 'Could not insert review.', array( 'status' => 500 ) );
		}
		// WooCommerce caches the average/count on the product.
		if ( class_exists( 'WC_Comments' ) ) {
			WC_Comments::clear_transients( $product_id );
		}
		return new WP_REST_Response( array( 'comment_id' => (int) $comment_id ), 201 );
	}

	// ── Newsletter (bulk) ───────────────────────────────────────────────

	public static function import_newsletter( WP_REST_Request $request ) {
		$rows = $request->get_param( 'subscribers' );
		if ( ! is_array( $rows ) ) {
			return new WP_Error( 'drc_bad_request', 'subscribers[] is required.', array( 'status' => 400 ) );
		}
		$stored  = get_option( 'drc_newsletter_subscribers', array() );
		$stored  = is_array( $stored ) ? $stored : array();
		$added   = 0;
		foreach ( $rows as $row ) {
			$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
			if ( ! is_email( $email ) || isset( $stored[ $email ] ) ) {
				continue;
			}
			$stored[ $email ] = array(
				'source'        => sanitize_text_field( (string) ( $row['source'] ?? '' ) ),
				'subscribed_at' => self::local_time( $row['subscribed_at'] ?? null ) ?: current_time( 'mysql' ),
			);
			++$added;
		}
		update_option( 'drc_newsletter_subscribers', $stored, false );
		return new WP_REST_Response( array( 'added' => $added, 'skipped' => count( $rows ) - $added ), 201 );
	}

	// ── Content items ───────────────────────────────────────────────────

	public static function import_content( WP_REST_Request $request ) {
		$type      = (string) $request->get_param( 'type' );
		$legacy_id = (int) $request->get_param( 'legacy_id' );
		$fields    = (array) $request->get_param( 'fields' );
		if ( ! isset( self::CONTENT[ $type ] ) || ! $legacy_id ) {
			return new WP_Error( 'drc_bad_request', 'A known type and legacy_id are required.', array( 'status' => 400 ) );
		}
		list( $post_type, $title_field, $image_field, $meta_fields ) = self::CONTENT[ $type ];
		$existing = get_posts( array( 'post_type' => $post_type, 'post_status' => 'any', 'meta_key' => '_legacy_id', 'meta_value' => $legacy_id, 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		if ( $existing ) {
			return self::conflict( 'Item', 'post_id', (int) $existing[0] );
		}

		if ( 'trade_enquiry' === $type ) {
			$title = trim( ( $fields['first_name'] ?? '' ) . ' ' . ( $fields['last_name'] ?? '' ) );
		} elseif ( 'homepage_enquiry' === $type ) {
			$title = ( $fields['name'] ?? '' ) . ' — ' . ( $fields['subject'] ?? '' );
		} else {
			$title = (string) ( $fields[ $title_field ] ?? '' );
		}
		$created = self::local_time( $fields['created_at'] ?? null );
		$post_id = wp_insert_post( array(
			'post_type'   => $post_type,
			'post_status' => 'publish',
			'post_title'  => sanitize_text_field( $title ) ?: ucwords( str_replace( '_', ' ', $type ) ) . ' #' . $legacy_id,
			'post_date'   => $created ?: current_time( 'mysql' ),
		), true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'drc_insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}
		update_post_meta( $post_id, '_legacy_id', $legacy_id );

		$warnings = array();
		foreach ( $meta_fields as $key ) {
			if ( ! array_key_exists( $key, $fields ) ) {
				continue;
			}
			$value = $fields[ $key ];
			if ( in_array( $key, array( 'is_active', 'is_intro', 'is_read', 'consent' ), true ) ) {
				$value = (bool) $value;
			} elseif ( in_array( $key, array( 'sort_order', 'rating', 'product_id' ), true ) ) {
				$value = null === $value ? '' : (int) $value;
			} elseif ( in_array( $key, array( 'video_url', 'poster_url' ), true ) && $value ) {
				// Videos and posters live on the old server — copy them over.
				$att = self::sideload( (string) $value, $post_id );
				if ( $att ) {
					$value = wp_get_attachment_url( $att );
				} else {
					$warnings[] = "Could not copy {$key}; kept the original URL.";
					$value      = esc_url_raw( (string) $value );
				}
			} elseif ( in_array( $key, array( 'link_url', 'website' ), true ) ) {
				$value = esc_url_raw( (string) $value );
			} else {
				$value = sanitize_textarea_field( (string) $value );
			}
			update_post_meta( $post_id, $key, $value );
		}

		if ( $image_field && ! empty( $fields[ $image_field ] ) ) {
			$att = self::sideload( (string) $fields[ $image_field ], $post_id, $title );
			if ( $att ) {
				set_post_thumbnail( $post_id, $att );
			} else {
				$warnings[] = 'Could not copy the image.';
			}
		}
		// ProjectGalleryItem's extra photos (project_gallery_images).
		if ( 'project_gallery' === $type && ! empty( $fields['extra_image_urls'] ) && is_array( $fields['extra_image_urls'] ) ) {
			$ids = array();
			foreach ( $fields['extra_image_urls'] as $url ) {
				$att = self::sideload( (string) $url, $post_id, $title );
				if ( $att ) {
					$ids[] = $att;
				}
			}
			update_post_meta( $post_id, 'extra_image_ids', implode( ',', $ids ) );
		}
		return new WP_REST_Response( array( 'post_id' => (int) $post_id, 'warnings' => $warnings ), 201 );
	}

	// ── Inventory history ───────────────────────────────────────────────

	public static function import_inventory_transaction( WP_REST_Request $request ) {
		global $wpdb;
		$table       = $wpdb->prefix . 'drc_inventory_transactions';
		$legacy_id   = (int) $request->get_param( 'legacy_id' );
		$material_id = (int) $request->get_param( 'material_id' );
		if ( ! $legacy_id || ! $material_id ) {
			return new WP_Error( 'drc_bad_request', 'legacy_id and material_id are required.', array( 'status' => 400 ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE legacy_id = %d", $legacy_id ) );
		if ( $existing ) {
			return self::conflict( 'Transaction', 'id', $existing );
		}
		// History only: stock_meters was imported as the current balance with
		// the material, so these rows must not be re-applied to it.
		$ok = $wpdb->insert( $table, array( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'legacy_id'        => $legacy_id,
			'material_id'      => $material_id,
			'qty_change'       => (float) $request->get_param( 'qty_change' ),
			'transaction_type' => sanitize_key( (string) $request->get_param( 'transaction_type' ) ),
			'notes'            => sanitize_textarea_field( (string) $request->get_param( 'notes' ) ),
			'created_at'       => self::local_time( $request->get_param( 'created_at' ) ) ?: current_time( 'mysql' ),
		) );
		if ( ! $ok ) {
			return new WP_Error( 'drc_insert_failed', $wpdb->last_error ?: 'Could not insert.', array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'id' => (int) $wpdb->insert_id ), 201 );
	}

	// ── Business settings ───────────────────────────────────────────────

	public static function import_settings( WP_REST_Request $request ) {
		$incoming = (array) $request->get_param( 'settings' );
		$known    = DRC_Settings::defaults();
		$current  = DRC_Settings::all();
		$warnings = array();
		$applied  = array();
		foreach ( $incoming as $key => $value ) {
			if ( ! array_key_exists( $key, $known ) || null === $value ) {
				continue;
			}
			// Settings the vendor already changed on the new site win.
			if ( $request->get_param( 'keep_existing' ) && $current[ $key ] !== $known[ $key ] ) {
				continue;
			}
			$current[ $key ] = self::localise_media( $value, (string) $key, $warnings );
			$applied[]       = $key;
		}
		// Single-tenant: the store currency is WooCommerce's (DRC_Settings forces it).
		$base = strtoupper( sanitize_text_field( (string) $request->get_param( 'base_currency' ) ) );
		if ( preg_match( '/^[A-Z]{3}$/', $base ) ) {
			update_option( 'woocommerce_currency', $base );
			$applied[] = 'base_currency';
		}
		$trending = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'trending_product_ids' ) ) ) );
		if ( $trending ) {
			$current['trending_product_ids'] = $trending;
			$applied[]                       = 'trending_product_ids';
		}
		update_option( DRC_Settings::OPTION_KEY, $current );

		$name = sanitize_text_field( (string) $request->get_param( 'business_name' ) );
		if ( '' !== $name ) {
			update_option( 'blogname', $name );
			$applied[] = 'business_name';
		}
		$logo = (string) $request->get_param( 'logo_url' );
		if ( '' !== $logo ) {
			$att = self::sideload( $logo );
			if ( $att ) {
				set_theme_mod( 'custom_logo', $att );
				$applied[] = 'logo';
			} else {
				$warnings[] = 'Could not copy the logo.';
			}
		}
		return new WP_REST_Response( array( 'applied' => $applied, 'warnings' => $warnings ), 201 );
	}

	// ── Staff ───────────────────────────────────────────────────────────

	public static function import_staff( WP_REST_Request $request ) {
		global $wpdb;
		$legacy_id = (int) $request->get_param( 'legacy_staff_id' );
		$email     = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( ! $legacy_id || ! is_email( $email ) ) {
			return new WP_Error( 'drc_bad_request', 'legacy_staff_id and a valid email are required.', array( 'status' => 400 ) );
		}
		$already = get_users( array( 'meta_key' => '_legacy_staff_id', 'meta_value' => $legacy_id, 'number' => 1, 'fields' => 'ID' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		if ( $already ) {
			return self::conflict( 'Staff user', 'user_id', (int) $already[0] );
		}
		$role    = 'admin' === $request->get_param( 'role' ) ? 'administrator' : 'shop_manager';
		$user_id = email_exists( $email );
		if ( $user_id ) {
			// An existing account (e.g. the site owner's) is linked, and only
			// ever gains the staff role — never loses a higher one.
			$user = new WP_User( $user_id );
			if ( ! user_can( $user, 'manage_woocommerce' ) ) {
				$user->set_role( $role );
			}
		} else {
			$user_id = wp_insert_user( array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ),
				'display_name' => sanitize_text_field( (string) $request->get_param( 'full_name' ) ) ?: $email,
				'role'         => $request->get_param( 'is_active' ) ? $role : '',
			) );
			if ( is_wp_error( $user_id ) ) {
				return new WP_Error( 'drc_insert_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
			}
			// Same bcrypt pass-through as customers (DRC_Legacy_Password).
			$hash = (string) $request->get_param( 'hashed_password' );
			if ( preg_match( '/^\$2[aby]\$/', $hash ) ) {
				$wpdb->update( $wpdb->users, array( 'user_pass' => $hash ), array( 'ID' => $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_user_cache( $user_id );
			}
		}
		update_user_meta( $user_id, '_legacy_staff_id', $legacy_id );
		return new WP_REST_Response( array( 'user_id' => (int) $user_id, 'role' => $role ), 201 );
	}

	// ── Catalog masters & collection displays ───────────────────────────

	public static function import_masters( WP_REST_Request $request ) {
		$type = (string) $request->get_param( 'type' );
		$rows = (array) $request->get_param( 'rows' ); // already in sort order
		if ( in_array( $type, array( 'sizes', 'weaves', 'piles' ), true ) ) {
			$saved = DRC_Catalog_Masters::save( $type, array_map( function ( $r ) {
				return array( 'name' => $r['name'] ?? '', 'ft' => $r['ft'] ?? '', 'cm' => $r['cm'] ?? '', 'active' => ! empty( $r['is_active'] ) );
			}, $rows ) );
			return new WP_REST_Response( array( 'saved' => count( $saved ) ), 201 );
		}
		$taxonomy = array( 'spaces' => 'room_type', 'moods' => 'mood_tag' )[ $type ] ?? null;
		if ( ! $taxonomy ) {
			return new WP_Error( 'drc_bad_request', 'Unknown master type.', array( 'status' => 400 ) );
		}
		// Spaces and moods are taxonomies here; active entries become terms
		// (so the mega menu lists them even before a rug uses them).
		$created = 0;
		foreach ( $rows as $r ) {
			$name = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
			if ( '' === $name || empty( $r['is_active'] ) || term_exists( sanitize_title( $name ), $taxonomy ) ) {
				continue;
			}
			$term = wp_insert_term( $name, $taxonomy, array( 'slug' => sanitize_title( $name ) ) );
			if ( ! is_wp_error( $term ) ) {
				++$created;
			}
		}
		return new WP_REST_Response( array( 'created' => $created ), 201 );
	}

	public static function import_collection_display( WP_REST_Request $request ) {
		$key = sanitize_text_field( (string) $request->get_param( 'category' ) );
		if ( 'default' !== $key && ! preg_match( '#^(space|mood|material|weave)/[a-z0-9_\-]+$#', $key ) ) {
			return new WP_Error( 'drc_bad_key', 'Unknown collection page.', array( 'status' => 400 ) );
		}
		$warnings = array();
		$images   = array();
		foreach ( array_slice( (array) $request->get_param( 'images' ), 0, 3 ) as $img ) {
			$images[] = array(
				'image_url' => self::local_media_url( (string) ( $img['image_url'] ?? '' ), $warnings ),
				'caption'   => sanitize_text_field( (string) ( $img['caption'] ?? '' ) ),
			);
		}
		$all = DRC_Catalog_Masters::displays();
		if ( $request->get_param( 'enabled' ) && array_filter( wp_list_pluck( $images, 'image_url' ) ) ) {
			$all[ $key ] = $images;
		} else {
			unset( $all[ $key ] );
		}
		update_option( DRC_Catalog_Masters::OPT_DISPLAYS, $all, false );
		return new WP_REST_Response( array( 'key' => $key, 'warnings' => $warnings ), 201 );
	}

	// ── Email templates, pricing rules ──────────────────────────────────

	public static function import_email_template( WP_REST_Request $request ) {
		$key = sanitize_key( (string) $request->get_param( 'key' ) );
		if ( ! isset( DRC_Email_Templates::defaults()[ $key ] ) ) {
			return new WP_Error( 'drc_bad_request', 'Unknown template.', array( 'status' => 400 ) );
		}
		DRC_Email_Templates::save( $key, array(
			'subject'   => (string) $request->get_param( 'subject' ),
			'body_html' => (string) $request->get_param( 'body_html' ),
			'body_text' => (string) $request->get_param( 'body_text' ),
			'is_active' => (bool) $request->get_param( 'is_active' ),
		) );
		return new WP_REST_Response( array( 'key' => $key ), 201 );
	}

	/** Replaces a whole rules table with the source rows (idempotent). */
	public static function import_rules( WP_REST_Request $request ) {
		global $wpdb;
		$columns = array(
			'pricing'  => array( 'drc_pricing_rules', array( 'name', 'rule_type', 'min_qty', 'max_qty', 'multiplier', 'flat_fee', 'description' ) ),
			'moq'      => array( 'drc_moq_rules', array( 'rug_type', 'minimum_sqm', 'minimum_pieces', 'notes' ) ),
			'timeline' => array( 'drc_production_timelines', array( 'order_type', 'base_days', 'complexity_multiplier_per_sqm', 'notes' ) ),
		)[ (string) $request->get_param( 'kind' ) ] ?? null;
		if ( ! $columns ) {
			return new WP_Error( 'drc_bad_request', 'kind must be pricing, moq or timeline.', array( 'status' => 400 ) );
		}
		list( $table, $cols ) = $columns;
		$table = $wpdb->prefix . $table;
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = 0;
		foreach ( (array) $request->get_param( 'rows' ) as $row ) {
			$data = array_intersect_key( (array) $row, array_flip( $cols ) );
			$data = array_map( function ( $v ) { return is_string( $v ) ? sanitize_textarea_field( $v ) : $v; }, $data );
			if ( $data && $wpdb->insert( $table, $data ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				++$count;
			}
		}
		return new WP_REST_Response( array( 'rows' => $count ), 201 );
	}

	// ── Order history, promo redemptions ────────────────────────────────

	private static function order_by_legacy( int $legacy_id ): ?WC_Order {
		$ids = wc_get_orders( array( 'limit' => 1, 'return' => 'ids', 'meta_query' => array( array( 'key' => '_legacy_order_id', 'value' => $legacy_id ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		return $ids ? wc_get_order( $ids[0] ) : null;
	}

	public static function import_order_history( WP_REST_Request $request ) {
		$order = self::order_by_legacy( (int) $request->get_param( 'legacy_order_id' ) );
		if ( ! $order ) {
			return new WP_Error( 'drc_not_found', 'Import the order first.', array( 'status' => 404 ) );
		}
		if ( $order->get_meta( '_drc_history_imported' ) ) {
			return self::conflict( 'Order history', 'order_id', $order->get_id() );
		}
		$labels = wc_get_order_statuses();
		foreach ( (array) $request->get_param( 'entries' ) as $entry ) {
			$status = sanitize_key( (string) ( $entry['status'] ?? '' ) );
			if ( '' === $status ) {
				continue;
			}
			$label   = $labels[ 'wc-' . $status ] ?? ucwords( str_replace( '_', ' ', $status ) );
			$note_id = $order->add_order_note( 'Status: ' . $label . ' (imported from the previous system)' );
			if ( $note_id && ! empty( $entry['changed_at'] ) ) {
				wp_update_comment( array(
					'comment_ID'       => $note_id,
					'comment_date'     => self::local_time( $entry['changed_at'] ),
					'comment_date_gmt' => gmdate( 'Y-m-d H:i:s', strtotime( (string) $entry['changed_at'] ) ),
				) );
			}
		}
		$order->update_meta_data( '_drc_history_imported', 1 );
		$order->save();
		return new WP_REST_Response( array( 'order_id' => $order->get_id() ), 201 );
	}

	/** Sets a coupon's "used by" list so one-per-customer still holds. */
	public static function import_promo_redemptions( WP_REST_Request $request ) {
		$ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'any', 'meta_key' => '_legacy_promo_id', 'meta_value' => (int) $request->get_param( 'legacy_promo_id' ), 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		if ( ! $ids ) {
			return new WP_Error( 'drc_not_found', 'Import the promo code first.', array( 'status' => 404 ) );
		}
		delete_post_meta( $ids[0], '_used_by' );
		$count = 0;
		foreach ( (array) $request->get_param( 'used_by' ) as $who ) {
			$who = is_numeric( $who ) ? (string) absint( $who ) : sanitize_email( (string) $who );
			if ( '' !== $who && '0' !== $who ) {
				add_post_meta( $ids[0], '_used_by', $who );
				++$count;
			}
		}
		return new WP_REST_Response( array( 'coupon_id' => $ids[0], 'used_by' => $count ), 201 );
	}

	// ── Design stories ──────────────────────────────────────────────────

	public static function import_story( WP_REST_Request $request ) {
		$legacy_id = (int) $request->get_param( 'legacy_story_id' );
		$title     = sanitize_text_field( (string) $request->get_param( 'title' ) );
		if ( ! $legacy_id || '' === $title ) {
			return new WP_Error( 'drc_bad_request', 'legacy_story_id and title are required.', array( 'status' => 400 ) );
		}
		$existing = get_posts( array( 'post_type' => DRC_Design_Stories::POST_TYPE, 'post_status' => 'any', 'meta_key' => '_legacy_story_id', 'meta_value' => $legacy_id, 'fields' => 'ids', 'numberposts' => 1 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery -- one-time importer.
		if ( $existing ) {
			return self::conflict( 'Story', 'post_id', (int) $existing[0] );
		}
		$created = self::local_time( $request->get_param( 'created_at' ) );
		$post_id = wp_insert_post( array(
			'post_type'    => DRC_Design_Stories::POST_TYPE,
			'post_status'  => $request->get_param( 'is_published' ) ? 'publish' : 'draft',
			'post_title'   => $title,
			'post_name'    => sanitize_title( (string) $request->get_param( 'slug' ) ?: $title ),
			'post_content' => wp_kses_post( (string) $request->get_param( 'body_html' ) ),
			'menu_order'   => (int) $request->get_param( 'sort_order' ),
			'post_date'    => $created ?: current_time( 'mysql' ),
		), true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'drc_insert_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
		}
		update_post_meta( $post_id, '_legacy_story_id', $legacy_id );
		$warnings = array();
		$inspiration = sanitize_text_field( (string) $request->get_param( 'inspiration' ) );
		if ( '' !== $inspiration ) {
			update_post_meta( $post_id, DRC_Design_Stories::META_INSPIRATION, $inspiration );
		}
		if ( $request->get_param( 'product_id' ) ) {
			update_post_meta( $post_id, DRC_Design_Stories::META_PRODUCT, absint( $request->get_param( 'product_id' ) ) );
		}
		$cover = (string) $request->get_param( 'cover_image_url' );
		if ( '' !== $cover ) {
			$att = self::sideload( $cover, $post_id, $title );
			if ( $att ) {
				set_post_thumbnail( $post_id, $att );
			} else {
				$warnings[] = 'Could not copy the cover image.';
			}
		}
		$media = array();
		foreach ( array_slice( (array) $request->get_param( 'media' ), 0, DRC_Design_Stories::MAX_MEDIA ) as $item ) {
			$type = in_array( $item['type'] ?? '', array( 'image', 'video' ), true ) ? $item['type'] : null;
			if ( ! $type || empty( $item['url'] ) ) {
				continue;
			}
			$att    = self::sideload( (string) $item['url'], $post_id );
			$poster = ! empty( $item['poster_url'] ) ? self::sideload( (string) $item['poster_url'], $post_id ) : 0;
			if ( ! $att ) {
				$warnings[] = 'Could not copy ' . $item['url'] . '; kept the original URL.';
			}
			$media[] = array(
				'type'          => $type,
				'attachment_id' => $att,
				'url'           => $att ? (string) wp_get_attachment_url( $att ) : esc_url_raw( (string) $item['url'] ),
				'poster_id'     => $poster,
				'poster_url'    => $poster ? (string) wp_get_attachment_url( $poster ) : esc_url_raw( (string) ( $item['poster_url'] ?? '' ) ),
				'caption'       => sanitize_text_field( (string) ( $item['caption'] ?? '' ) ),
			);
		}
		if ( $media ) {
			update_post_meta( $post_id, DRC_Design_Stories::META_MEDIA, wp_slash( wp_json_encode( $media ) ) );
		}
		return new WP_REST_Response( array( 'post_id' => (int) $post_id, 'warnings' => $warnings ), 201 );
	}
}
