<?php
/**
 * The five simple CMS content types from the original app's models.py that
 * are just "an image/video + a few fields + manual ordering" — exactly
 * what WordPress custom post types are for (see the migration plan's
 * "Use native WordPress/WooCommerce... CMS content" row). Registered here
 * rather than as separate plugins; ACF is not required since the field
 * count per type is small enough for register_post_meta() + a plain
 * meta-box, kept in Phase 5's admin work.
 *
 * Each type stores its primary image as the post's featured image
 * (post thumbnail) rather than a URL field — that's the native WordPress
 * way and gives free media-library management — except ShowcaseVideo,
 * whose video_url/poster_url stay as meta since videos aren't a natural
 * "featured image" fit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Content_Types {

	public static function register() {
		register_post_type( 'drc_workshop_photo', array(
			'label'        => 'Workshop Photos',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-camera',
			// 'custom-fields' unlocks WordPress's built-in Custom Fields meta
			// box (Screen Options in Classic Editor; a small always-visible
			// panel in the block editor) — without it there was no admin UI
			// at all for description/sort_order/is_active on this CPT.
			'supports'     => array( 'title', 'thumbnail', 'custom-fields' ),
		) );
		self::register_meta( 'drc_workshop_photo', array(
			'description' => 'string',
			'sort_order'  => 'integer',
			'is_active'   => 'boolean',
		) );

		// "Custom Rug Journey" step-tile images shown beside the
		// custom-rug-request form (page-custom-rug-request.php) — ported
		// from CustomerCustomRugPageImages admin CPT / GET
		// /customer/custom-rug-page-images. Same shape as drc_workshop_photo
		// (title = step title, thumbnail = the image).
		register_post_type( 'drc_rug_step_image', array(
			'label'        => 'Custom Rug Request Images',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-format-image',
			'supports'     => array( 'title', 'thumbnail', 'custom-fields' ),
		) );
		self::register_meta( 'drc_rug_step_image', array(
			'sort_order' => 'integer',
			'is_active'  => 'boolean',
		) );

		register_post_type( 'drc_showcase_video', array(
			'label'        => 'Showcase Videos',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-video-alt3',
			'supports'     => array( 'title', 'custom-fields' ),
		) );
		self::register_meta( 'drc_showcase_video', array(
			'description' => 'string',
			'video_url'   => 'string',
			'poster_url'  => 'string',
			'sort_order'  => 'integer',
			'is_active'   => 'boolean',
			'is_intro'    => 'boolean',
			// Groups "Behind the Craft" grid videos into tabs on the homepage
			// (CustomerHome.tsx's videoTabNames) — defaults to "Craftsmanship"
			// when unset, same as the original.
			'tab_name'    => 'string',
		) );

		register_post_type( 'drc_testimonial', array(
			'label'        => 'Testimonials',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-format-quote',
			'supports'     => array( 'title', 'thumbnail', 'custom-fields' ), // title = author_name, thumbnail = photo
		) );
		self::register_meta( 'drc_testimonial', array(
			'author_title' => 'string',
			'country'      => 'string',
			'quote'        => 'string',
			'rating'       => 'integer',
			'sort_order'   => 'integer',
			'is_active'    => 'boolean',
		) );

		register_post_type( 'drc_gallery_item', array(
			'label'        => 'Project Gallery',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-format-gallery',
			'supports'     => array( 'title', 'thumbnail', 'custom-fields' ), // title used as caption
		) );
		self::register_meta( 'drc_gallery_item', array(
			'link_url'      => 'string',
			'sort_order'    => 'integer',
			'is_active'     => 'boolean',
			// Full project-detail fields (frontend/src/types/index.ts's
			// ProjectGalleryItem) — description/owner testimonial/rating shown
			// on the /project-gallery/{id} detail page. `extra_image_ids` is a
			// comma-separated attachment-ID list (edited via the same
			// wp.media()-backed repeater UI pattern as color options), giving
			// the detail page's own multi-image gallery beyond the single
			// featured image the homepage mosaic/listing use.
			'description'     => 'string',
			'owner_name'      => 'string',
			'owner_message'   => 'string',
			'rating'          => 'integer',
			'extra_image_ids' => 'string',
		) );

		register_post_type( 'drc_journey_step', array(
			'label'        => 'Rug Journey',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-networking',
			'supports'     => array( 'title', 'custom-fields' ), // title = step title, e.g. "CAD Approval"
		) );
		self::register_meta( 'drc_journey_step', array(
			'description' => 'string',
			'sort_order'  => 'integer',
			'is_active'   => 'boolean',
		) );

		register_post_type( 'drc_announcement', array(
			'label'        => 'Announcements',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-megaphone',
			'supports'     => array( 'title', 'custom-fields' ), // title = the announcement text
		) );
		self::register_meta( 'drc_announcement', array(
			'link_url'   => 'string',
			'sort_order' => 'integer',
			'is_active'  => 'boolean',
		) );

		// Trade partner enquiries submitted from page-trade-enquiry.php via
		// DRC_Rest_Routes::create_trade_enquiry() — an inbox, not curated
		// content, so it's `public => false` with no `is_active`/`sort_order`
		// meta; newest-first by post date instead (see get_trade_enquiries()).
		register_post_type( 'drc_trade_enquiry', array(
			'label'        => 'Trade Enquiries',
			'public'       => false,
			'show_ui'      => false, // listed on its own admin screen (DRC_Admin_Menu), not the default post-list UI
			'show_in_rest' => false, // created only via the public REST route below, with its own validation — not general post-creation
			'supports'     => array( 'title' ),
		) );
		self::register_meta( 'drc_trade_enquiry', array(
			'first_name'    => 'string',
			'last_name'     => 'string',
			'email'         => 'string',
			'phone'         => 'string',
			'city'          => 'string',
			'country'       => 'string',
			'profession'    => 'string',
			'company'       => 'string',
			'website'       => 'string',
			'project_type'  => 'string',
			'project_brief' => 'string',
			'is_read'       => 'boolean',
		) );

		// Homepage contact-form submissions (CustomerHome.tsx's "Have
		// Questions? Get in Touch!" section, POST /homepage-enquiry) — same
		// inbox shape/rationale as drc_trade_enquiry above, just a shorter
		// field set (name/email/subject/message).
		register_post_type( 'drc_homepage_enquiry', array(
			'label'        => 'Homepage Enquiries',
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'supports'     => array( 'title' ),
		) );
		self::register_meta( 'drc_homepage_enquiry', array(
			'name'    => 'string',
			'email'   => 'string',
			'subject' => 'string',
			'message' => 'string',
			'is_read' => 'boolean',
		) );

		// Rug detail page FAQs (ported from the original app's FAQ model,
		// backend/app/models/models.py) — title = question, `answer` meta
		// holds the answer text. `product_id` of 0/empty means a *global*
		// FAQ shown on every rug's page; a specific WC product ID scopes it
		// to just that rug — mirrors the original's nullable rug_catalog_id
		// and its "global ∪ per-rug" merge query (see get_faqs_for_product()
		// below).
		register_post_type( 'drc_faq', array(
			'label'        => 'Rug FAQs',
			'public'       => false,
			'show_ui'      => true,
			'show_in_rest' => true,
			'menu_icon'    => 'dashicons-editor-help',
			'supports'     => array( 'title', 'custom-fields' ),
		) );
		self::register_meta( 'drc_faq', array(
			'answer'     => 'string',
			'product_id' => 'integer',
			'sort_order' => 'integer',
			'is_active'  => 'boolean',
		) );
	}

	private static function register_meta( string $post_type, array $fields ) {
		foreach ( $fields as $key => $type ) {
			register_post_meta( $post_type, $key, array(
				'type'         => $type,
				'single'       => true,
				'show_in_rest' => true,
				'auth_callback' => function () {
					return current_user_can( 'manage_woocommerce' );
				},
			) );
		}
	}

	/** Shared query helper — active items for a post type, sorted by the
	 * sort_order meta (mirrors every one of the original app's public GET
	 * endpoints for these types, e.g. get_public_workshop_photos()). */
	private static function get_active( string $post_type, int $limit = -1 ): array {
		$query = new WP_Query( array(
			'post_type'      => $post_type,
			'posts_per_page' => $limit,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed content types, not high-volume.
				array( 'key' => 'is_active', 'value' => '1', 'compare' => '=' ),
			),
			'meta_key'       => 'sort_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
		) );
		return $query->posts;
	}

	public static function get_workshop_photos( int $limit = -1 ): array {
		return array_map( function ( $post ) {
			return array(
				'id'          => $post->ID,
				'caption'     => get_the_title( $post ),
				'description' => get_post_meta( $post->ID, 'description', true ),
				'image_url'   => get_the_post_thumbnail_url( $post, 'large' ),
			);
		}, self::get_active( 'drc_workshop_photo', $limit ) );
	}

	public static function get_custom_rug_step_images( int $limit = -1 ): array {
		return array_map( function ( $post ) {
			return array(
				'id'        => $post->ID,
				'title'     => get_the_title( $post ),
				'image_url' => get_the_post_thumbnail_url( $post, 'large' ),
			);
		}, self::get_active( 'drc_rug_step_image', $limit ) );
	}

	public static function get_showcase_videos( int $limit = -1 ): array {
		return array_map( function ( $post ) {
			return array(
				'id'          => $post->ID,
				'title'       => get_the_title( $post ),
				'description' => get_post_meta( $post->ID, 'description', true ),
				'video_url'   => get_post_meta( $post->ID, 'video_url', true ),
				'poster_url'  => get_post_meta( $post->ID, 'poster_url', true ),
				'is_intro'    => (bool) get_post_meta( $post->ID, 'is_intro', true ),
				'tab_name'    => get_post_meta( $post->ID, 'tab_name', true ) ?: 'Craftsmanship',
			);
		}, self::get_active( 'drc_showcase_video', $limit ) );
	}

	public static function get_testimonials( int $limit = -1 ): array {
		return array_map( function ( $post ) {
			$rating = get_post_meta( $post->ID, 'rating', true );
			return array(
				'id'           => $post->ID,
				'author_name'  => get_the_title( $post ),
				'author_title' => get_post_meta( $post->ID, 'author_title', true ),
				'country'      => get_post_meta( $post->ID, 'country', true ),
				'quote'        => get_post_meta( $post->ID, 'quote', true ),
				'photo_url'    => get_the_post_thumbnail_url( $post, 'thumbnail' ),
				'rating'       => $rating !== '' ? (int) $rating : null,
			);
		}, self::get_active( 'drc_testimonial', $limit ) );
	}

	private static function format_gallery_item( \WP_Post $post ): array {
		$extra_ids = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $post->ID, 'extra_image_ids', true ) ) ) );
		$images    = array_values( array_filter( array_map( function ( $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			return $url ? array( 'id' => $id, 'image_url' => $url, 'sort_order' => 0 ) : null;
		}, $extra_ids ) ) );
		$rating = get_post_meta( $post->ID, 'rating', true );
		return array(
			'id'            => $post->ID,
			'image_url'     => get_the_post_thumbnail_url( $post, 'large' ),
			'caption'       => get_the_title( $post ),
			'link_url'      => get_post_meta( $post->ID, 'link_url', true ) ?: null,
			'description'   => get_post_meta( $post->ID, 'description', true ) ?: null,
			'owner_name'    => get_post_meta( $post->ID, 'owner_name', true ) ?: null,
			'owner_message' => get_post_meta( $post->ID, 'owner_message', true ) ?: null,
			'rating'        => '' !== $rating ? (int) $rating : null,
			'images'        => $images,
		);
	}

	public static function get_gallery_items( int $limit = -1 ): array {
		return array_map( array( __CLASS__, 'format_gallery_item' ), self::get_active( 'drc_gallery_item', $limit ) );
	}

	public static function get_gallery_item( int $id ): ?array {
		$post = get_post( $id );
		return ( $post && 'drc_gallery_item' === $post->post_type ) ? self::format_gallery_item( $post ) : null;
	}

	/** Mirrors GET /customer/faqs?rug_id=: global FAQs (no product_id set)
	 * plus any FAQ scoped to this specific product, active-only, ordered by
	 * sort_order then id — same "global ∪ per-rug" shape as the original
	 * app's public_faqs(). $product_id = 0/null returns only the globals. */
	public static function get_faqs_for_product( int $product_id = 0 ): array {
		$query = new WP_Query( array(
			'post_type'      => 'drc_faq',
			'posts_per_page' => -1,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed content type.
				'relation' => 'AND',
				array( 'key' => 'is_active', 'value' => '1', 'compare' => '=' ),
				array(
					'relation' => 'OR',
					array( 'key' => 'product_id', 'value' => 0, 'compare' => '<=' ),
					array( 'key' => 'product_id', 'compare' => 'NOT EXISTS' ),
					array( 'key' => 'product_id', 'value' => (string) $product_id, 'compare' => '=' ),
				),
			),
			'meta_key'       => 'sort_order', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'orderby'        => array( 'meta_value_num' => 'ASC', 'ID' => 'ASC' ),
		) );
		return array_map( function ( $post ) {
			return array(
				'id'       => $post->ID,
				'question' => get_the_title( $post ),
				'answer'   => get_post_meta( $post->ID, 'answer', true ),
			);
		}, $query->posts );
	}

	public static function get_journey_steps( int $limit = -1 ): array {
		return array_map( function ( $post ) {
			return array(
				'id'          => $post->ID,
				'title'       => get_the_title( $post ),
				'description' => get_post_meta( $post->ID, 'description', true ),
			);
		}, self::get_active( 'drc_journey_step', $limit ) );
	}

	public static function get_announcements( int $limit = -1 ): array {
		return array_map( function ( $post ) {
			return array(
				'id'       => $post->ID,
				'text'     => get_the_title( $post ),
				'link_url' => get_post_meta( $post->ID, 'link_url', true ),
			);
		}, self::get_active( 'drc_announcement', $limit ) );
	}

	private static function format_trade_enquiry( \WP_Post $post ): array {
		return array(
			'id'            => $post->ID,
			'first_name'    => get_post_meta( $post->ID, 'first_name', true ),
			'last_name'     => get_post_meta( $post->ID, 'last_name', true ),
			'email'         => get_post_meta( $post->ID, 'email', true ),
			'phone'         => get_post_meta( $post->ID, 'phone', true ),
			'city'          => get_post_meta( $post->ID, 'city', true ) ?: null,
			'country'       => get_post_meta( $post->ID, 'country', true ) ?: null,
			'profession'    => get_post_meta( $post->ID, 'profession', true ),
			'company'       => get_post_meta( $post->ID, 'company', true ),
			'website'       => get_post_meta( $post->ID, 'website', true ) ?: null,
			'project_type'  => get_post_meta( $post->ID, 'project_type', true ) ?: null,
			'project_brief' => get_post_meta( $post->ID, 'project_brief', true ),
			'is_read'       => (bool) get_post_meta( $post->ID, 'is_read', true ),
			'created_at'    => get_the_date( 'c', $post ),
		);
	}

	/** Newest-first, unlike get_active() (curated content sorted by
	 * sort_order) — enquiries are an inbox, not editorial content. */
	public static function get_trade_enquiries(): array {
		$query = new WP_Query( array(
			'post_type'      => 'drc_trade_enquiry',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		return array_map( array( __CLASS__, 'format_trade_enquiry' ), $query->posts );
	}

	public static function get_trade_enquiry( int $id ): ?array {
		$post = get_post( $id );
		return ( $post && 'drc_trade_enquiry' === $post->post_type ) ? self::format_trade_enquiry( $post ) : null;
	}

	private static function format_homepage_enquiry( \WP_Post $post ): array {
		return array(
			'id'         => $post->ID,
			'name'       => get_post_meta( $post->ID, 'name', true ),
			'email'      => get_post_meta( $post->ID, 'email', true ),
			'subject'    => get_post_meta( $post->ID, 'subject', true ),
			'message'    => get_post_meta( $post->ID, 'message', true ),
			'is_read'    => (bool) get_post_meta( $post->ID, 'is_read', true ),
			'created_at' => get_the_date( 'c', $post ),
		);
	}

	public static function get_homepage_enquiries(): array {
		$query = new WP_Query( array(
			'post_type'      => 'drc_homepage_enquiry',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
		return array_map( array( __CLASS__, 'format_homepage_enquiry' ), $query->posts );
	}

	public static function get_homepage_enquiry( int $id ): ?array {
		$post = get_post( $id );
		return ( $post && 'drc_homepage_enquiry' === $post->post_type ) ? self::format_homepage_enquiry( $post ) : null;
	}
}
