<?php
/**
 * Design Stories — the story behind a unique rug design (e.g. what inspired
 * the World Map rug), ported from the original app's RugStory model
 * (backend/app/models/models.py), backend/app/api/routes/stories.py and the
 * admin screen frontend/src/pages/RugStories.tsx.
 *
 * Storage: a custom post type, like the other admin-curated content types in
 * class-content-types.php — but unlike those (`public => false` + their own
 * rewrite rules, see class-project-gallery-routes.php), a story is a real
 * public page with a slug, so this one is a *public* CPT and rides
 * WordPress's native machinery for everything the original had to hand-roll:
 *
 *   original (RugStory column / route)          here
 *   ------------------------------------------  ----------------------------------------------
 *   title                                       post_title
 *   slug + unique_story_slug() "-2, -3" dedup   post_name (wp_unique_post_slug() does the same)
 *   body_html (RichTextEditor, sanitized on     post_content (classic TinyMCE editor), sanitized
 *     render with PROSE_ALLOWED_TAGS)             on render by sanitize_body_html() below
 *   cover_image_url                             featured image ("Cover image")
 *   inspiration                                 `_drc_story_inspiration` meta
 *   media [{type,url,poster_url,caption}]       `_drc_story_media` JSON meta (media-library
 *                                                 attachment ids, or https URLs)
 *   rug_id (must belong to the tenant)          `_drc_story_product_id` meta (must be a WC product)
 *   is_published                                post_status (publish / draft)
 *   sort_order                                  menu_order ("Order" in Page Attributes)
 *   /stories, /stories/<slug> React routes      has_archive + rewrite slug `stories` → the theme's
 *                                                 archive-drc_story.php / single-drc_story.php
 *   sitemap.xml /stories + /stories/<slug>      Rank Math's post-type sitemap (archive link
 *                                                 + every published story), enabled by default
 *                                                 below
 *
 * The media/linked-rug/inspiration fields live in one meta box with a
 * wp.media()-backed repeater (assets/story-admin.js) — same nonce +
 * capability-checked save_post pattern as DRC_Color_Options' meta box.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Design_Stories {

	const POST_TYPE        = 'drc_story';
	const META_INSPIRATION = '_drc_story_inspiration';
	const META_MEDIA       = '_drc_story_media';
	const META_PRODUCT     = '_drc_story_product_id';
	const MAX_MEDIA        = 40;   // RugStoryBase.media max_length
	const MAX_TEXT         = 300;  // inspiration / caption / URL max_length

	/** Ported from frontend/src/utils/richTextSanitize.ts (PROSE_ALLOWED_TAGS /
	 * PROSE_ALLOWED_ATTR) — the same tight allowlist the React story page
	 * passed to DOMPurify, so admin-authored body HTML renders no wider than
	 * it did in the original. */
	const PROSE_ALLOWED = array(
		'p'          => array(),
		'h2'         => array(),
		'h3'         => array(),
		'strong'     => array(),
		'b'          => array(),
		'em'         => array(),
		'i'          => array(),
		'ul'         => array(),
		'ol'         => array(),
		'li'         => array(),
		'blockquote' => array(),
		'a'          => array( 'href' => true, 'target' => true, 'rel' => true ),
		'br'         => array(),
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'use_block_editor_for_post_type', array( __CLASS__, 'disable_block_editor' ), 10, 2 );
		add_filter( 'tiny_mce_before_init', array( __CLASS__, 'limit_editor_formats' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_column' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'order_queries' ) );

		// Rank Math defaults for this post type — see rank_math_* below.
		add_filter( 'option_rank-math-options-sitemap', array( __CLASS__, 'rank_math_sitemap_defaults' ) );
		add_filter( 'option_rank-math-options-titles', array( __CLASS__, 'rank_math_title_defaults' ) );
		add_filter( 'rank_math/frontend/description', array( __CLASS__, 'rank_math_description' ) );
	}

	public static function register() {
		register_post_type( self::POST_TYPE, array(
			'labels'              => array(
				'name'                  => 'Design Stories',
				'singular_name'         => 'Design Story',
				'menu_name'             => 'Design Stories',
				'add_new'               => 'Add Story',
				'add_new_item'          => 'Add New Design Story',
				'edit_item'             => 'Edit Design Story',
				'new_item'              => 'New Design Story',
				'view_item'             => 'View Story',
				'view_items'            => 'View Stories',
				'search_items'          => 'Search Stories',
				'not_found'             => 'No stories yet.',
				'not_found_in_trash'    => 'No stories in Trash.',
				'all_items'             => 'All Stories',
				'archives'              => 'Design Stories',
				'featured_image'        => 'Cover image',
				'set_featured_image'    => 'Set cover image',
				'remove_featured_image' => 'Remove cover image',
				'use_featured_image'    => 'Use as cover image',
			),
			'description'         => 'The stories behind our unique handmade rug designs.',
			'public'              => true,
			'show_ui'             => true,
			'show_in_rest'        => true,
			'exclude_from_search' => false,
			'has_archive'         => 'stories',
			'rewrite'             => array( 'slug' => 'stories', 'with_front' => false ),
			'menu_icon'           => 'dashicons-media-text',
			// title = story title, editor = rich-text body, thumbnail = cover
			// image, page-attributes = the "Order" (sort_order) field,
			// revisions = free undo for long-form copy.
			'supports'            => array( 'title', 'editor', 'thumbnail', 'page-attributes', 'revisions' ),
			'map_meta_cap'        => true,
		) );
	}

	/** The classic editor (TinyMCE) is the closest match to the original's
	 * RichTextEditor and keeps the "Story Details" meta box in the normal
	 * post-edit flow — the same editor WooCommerce products already use. */
	public static function disable_block_editor( $use, $post_type ) {
		return self::POST_TYPE === $post_type ? false : $use;
	}

	/** Paragraph / H2 / H3 only in the format dropdown — what the render-time
	 * allowlist keeps, so an admin never authors something that silently
	 * disappears on the public page. */
	public static function limit_editor_formats( array $settings ): array {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && self::POST_TYPE === $screen->post_type ) {
			$settings['block_formats'] = 'Paragraph=p;Heading 2=h2;Heading 3=h3';
		}
		return $settings;
	}

	// ── Public data ─────────────────────────────────────────────────────────

	/** Public listing (GET /customer/stories) and the archive's main query:
	 * published only, sort_order then newest first. */
	public static function order_queries( \WP_Query $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( self::POST_TYPE ) ) {
			return;
		}
		$query->set( 'posts_per_page', -1 ); // The React listing showed every story on one page.
		$query->set( 'orderby', array( 'menu_order' => 'ASC', 'ID' => 'DESC' ) );
	}

	/** @return \WP_Post[] Published stories in display order. */
	public static function get_published_posts( array $extra_args = array() ): array {
		$query = new WP_Query( array_merge( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'DESC' ),
			'no_found_rows'  => true,
		), $extra_args ) );
		return $query->posts;
	}

	/** A linked product, only if it still exists and is published — the
	 * original's ON DELETE SET NULL equivalent, evaluated at read time. */
	private static function linked_product( int $post_id ): ?\WC_Product {
		$product_id = (int) get_post_meta( $post_id, self::META_PRODUCT, true );
		if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}
		$product = wc_get_product( $product_id );
		return ( $product && 'publish' === $product->get_status() ) ? $product : null;
	}

	private static function linked_rug( int $post_id ): ?array {
		$product = self::linked_product( $post_id );
		if ( ! $product ) {
			return null;
		}
		return array(
			'id'        => $product->get_id(),
			'name'      => $product->get_name(),
			'url'       => $product->get_permalink(),
			'image_url' => wp_get_attachment_image_url( $product->get_image_id(), 'large' ) ?: null,
		);
	}

	/** Card data for the listing / product-page teaser — cover falls back to
	 * the linked rug's photo, same as get_public_stories(). */
	public static function format_summary( \WP_Post $post ): array {
		$rug   = self::linked_rug( $post->ID );
		$cover = get_the_post_thumbnail_url( $post, 'large' ) ?: ( $rug['image_url'] ?? null );
		return array(
			'id'              => $post->ID,
			'slug'            => $post->post_name,
			'url'             => get_permalink( $post ),
			'title'           => get_the_title( $post ),
			'inspiration'     => (string) get_post_meta( $post->ID, self::META_INSPIRATION, true ),
			'cover_image_url' => $cover ?: null,
			'product_id'      => $rug['id'] ?? null,
		);
	}

	/** Full single-story data (GET /customer/stories/{slug}). */
	public static function format_detail( \WP_Post $post ): array {
		return array_merge( self::format_summary( $post ), array(
			// 2048px copy (WebP, see DRC_Webp_Images) rather than the multi-MB original; falls back to the original when smaller.
			'cover_image_url' => get_the_post_thumbnail_url( $post, '2048x2048' ) ?: null,
			'body_html'       => self::sanitize_body_html( $post->post_content ),
			'media'           => self::get_media( $post->ID ),
			'rug'             => self::linked_rug( $post->ID ),
			'updated_at'      => get_post_modified_time( 'c', true, $post ),
		) );
	}

	/** The newest-sorted published story linked to a product, for the
	 * "The Story Behind This Design" teaser on that rug's page. */
	public static function get_for_product( int $product_id ): ?array {
		if ( $product_id <= 0 ) {
			return null;
		}
		$posts = self::get_published_posts( array(
			'posts_per_page' => 1,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- small, admin-managed content type.
				array( 'key' => self::META_PRODUCT, 'value' => $product_id, 'compare' => '=', 'type' => 'NUMERIC' ),
			),
		) );
		return $posts ? self::format_summary( $posts[0] ) : null;
	}

	/** Render-time XSS boundary for the story body: classic-editor content
	 * gets its paragraphs from wpautop(), then wp_kses() strips everything
	 * outside PROSE_ALLOWED (and any non-http(s)/mailto href). */
	public static function sanitize_body_html( string $content ): string {
		if ( '' === trim( $content ) ) {
			return '';
		}
		// Drop <script>/<style> *contents* too (as DOMPurify did) — wp_kses()
		// alone strips the tags but would leave their inner text behind.
		$content = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $content );
		return trim( wp_kses( wpautop( $content ), self::PROSE_ALLOWED, array( 'http', 'https', 'mailto' ) ) );
	}

	/** Stored media list resolved to URLs, in display order.
	 * @return array<int, array{type: string, url: string, poster_url: ?string, caption: ?string}> */
	public static function get_media( int $post_id ): array {
		$out = array();
		foreach ( self::get_raw_media( $post_id ) as $item ) {
			$url = $item['attachment_id'] ? wp_get_attachment_url( $item['attachment_id'] ) : $item['url'];
			if ( ! $url ) {
				continue; // Attachment deleted from the media library since.
			}
			$poster = $item['poster_id'] ? wp_get_attachment_image_url( $item['poster_id'], 'large' ) : $item['poster_url'];
			$out[]  = array(
				'type'       => $item['type'],
				'url'        => $url,
				'poster_url' => $poster ?: null,
				'caption'    => '' !== $item['caption'] ? $item['caption'] : null,
			);
		}
		return $out;
	}

	private static function get_raw_media( int $post_id ): array {
		$decoded = json_decode( (string) get_post_meta( $post_id, self::META_MEDIA, true ), true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$out = array();
		foreach ( $decoded as $row ) {
			if ( ! is_array( $row ) || ! in_array( $row['type'] ?? '', array( 'image', 'video' ), true ) ) {
				continue;
			}
			$out[] = array(
				'type'          => $row['type'],
				'attachment_id' => (int) ( $row['attachment_id'] ?? 0 ),
				'url'           => (string) ( $row['url'] ?? '' ),
				'poster_id'     => (int) ( $row['poster_id'] ?? 0 ),
				'poster_url'    => (string) ( $row['poster_url'] ?? '' ),
				'caption'       => (string) ( $row['caption'] ?? '' ),
			);
		}
		return $out;
	}

	// ── Admin ───────────────────────────────────────────────────────────────

	public static function add_meta_box() {
		add_meta_box( 'drc-story-details', 'Story Details', array( __CLASS__, 'render_meta_box' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function enqueue_admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'dreamrugs-story-admin', DRC_PLUGIN_URL . 'assets/story-admin.js', array(), DRC_DB_VERSION, true );
	}

	/** Same product source as the Settings screen's trending-rugs picker
	 * (published WooCommerce products by name). */
	private static function product_options(): array {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$options  = array();
		foreach ( $products as $product ) {
			$options[ $product->get_id() ] = $product->get_name();
		}
		return $options;
	}

	public static function render_meta_box( \WP_Post $post ) {
		wp_nonce_field( 'drc_save_story', 'drc_story_nonce' );
		$inspiration = (string) get_post_meta( $post->ID, self::META_INSPIRATION, true );
		$product_id  = (int) get_post_meta( $post->ID, self::META_PRODUCT, true );
		$media       = self::get_raw_media( $post->ID );
		$products    = self::product_options();
		?>
		<p class="description">The title, story text and cover image are edited above / in the sidebar. Save as <strong>Draft</strong> to hide a story from the public site; the <strong>Order</strong> field under Page Attributes sets its position on /stories (lowest first).</p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="drc-story-inspiration">Inspiration</label></th>
				<td>
					<input type="text" id="drc-story-inspiration" name="drc_story_inspiration" class="large-text" maxlength="<?php echo (int) self::MAX_TEXT; ?>" value="<?php echo esc_attr( $inspiration ); ?>" placeholder="e.g. A 1920s school atlas found in a Jaipur bazaar">
					<p class="description">One line shown under the title and on the /stories listing card.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="drc-story-product">Linked rug</label></th>
				<td>
					<select id="drc-story-product" name="drc_story_product_id" style="min-width:280px;">
						<option value="0">— None —</option>
						<?php foreach ( $products as $id => $name ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( $product_id, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Optional. Shows a "This Design" card on the story and a "The Story Behind This Design" teaser on the rug's page. With no cover image set, the rug's photo is used.</p>
				</td>
			</tr>
		</table>

		<h3 style="margin:1.5em 0 .5em;">Photos &amp; Videos <span style="font-weight:normal;color:#646970;">(up to <?php echo (int) self::MAX_MEDIA; ?>, shown in this order under "From Sketch to Loom")</span></h3>
		<table class="widefat striped" id="drc-story-media-table" style="max-width:1000px;" data-max="<?php echo (int) self::MAX_MEDIA; ?>">
			<thead>
				<tr>
					<th style="width:96px;">Preview</th>
					<th style="width:70px;">Type</th>
					<th>File / https URL</th>
					<th>Caption</th>
					<th style="width:120px;"></th>
				</tr>
			</thead>
			<tbody id="drc-story-media-rows">
				<?php foreach ( $media as $i => $item ) : ?>
					<?php self::render_media_row( $i, $item ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="drc-story-add-images">+ Add photos</button>
			<button type="button" class="button" id="drc-story-add-video">+ Add video</button>
		</p>
		<template id="drc-story-media-row-template">
			<?php self::render_media_row( '__INDEX__', array( 'type' => '__TYPE__', 'attachment_id' => 0, 'url' => '', 'poster_id' => 0, 'poster_url' => '', 'caption' => '' ) ); ?>
		</template>
		<?php
	}

	private static function render_media_row( $index, array $item ) {
		$name     = 'drc_story_media[' . $index . ']';
		$is_video = 'video' === $item['type'];
		$url      = $item['attachment_id'] ? (string) wp_get_attachment_url( $item['attachment_id'] ) : $item['url'];
		$poster   = $item['poster_id'] ? (string) wp_get_attachment_image_url( $item['poster_id'], 'thumbnail' ) : $item['poster_url'];
		$preview  = $is_video ? $poster : ( $item['attachment_id'] ? (string) wp_get_attachment_image_url( $item['attachment_id'], 'thumbnail' ) : $url );
		?>
		<tr data-type="<?php echo esc_attr( $item['type'] ); ?>">
			<td>
				<img data-role="preview" src="<?php echo esc_url( $preview ); ?>" alt="" style="width:80px;height:80px;object-fit:cover;background:#f0f0f1;<?php echo $preview ? '' : 'display:none;'; ?>">
				<span data-role="preview-empty" class="dashicons <?php echo $is_video ? 'dashicons-video-alt3' : 'dashicons-format-image'; ?>" style="font-size:40px;width:40px;height:40px;color:#c3c4c7;<?php echo $preview ? 'display:none;' : ''; ?>"></span>
			</td>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[type]" value="<?php echo esc_attr( $item['type'] ); ?>">
				<span data-role="type-label"><?php echo $is_video ? 'Video' : 'Photo'; ?></span>
			</td>
			<td>
				<input type="hidden" data-role="attachment-id" name="<?php echo esc_attr( $name ); ?>[attachment_id]" value="<?php echo (int) $item['attachment_id']; ?>">
				<input type="url" data-role="url" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $url ); ?>" maxlength="<?php echo (int) self::MAX_TEXT; ?>" placeholder="https://…" style="width:100%;">
				<button type="button" class="button button-small" data-role="choose" style="margin-top:4px;">Choose from library</button>
				<div data-role="poster-field" style="margin-top:8px;<?php echo $is_video ? '' : 'display:none;'; ?>">
					<input type="hidden" data-role="poster-id" name="<?php echo esc_attr( $name ); ?>[poster_id]" value="<?php echo (int) $item['poster_id']; ?>">
					<label style="display:block;font-size:12px;color:#646970;">Poster image (optional)</label>
					<input type="url" data-role="poster-url" name="<?php echo esc_attr( $name ); ?>[poster_url]" value="<?php echo esc_attr( $poster ); ?>" maxlength="<?php echo (int) self::MAX_TEXT; ?>" placeholder="https://…" style="width:100%;">
					<button type="button" class="button button-small" data-role="choose-poster" style="margin-top:4px;">Choose poster</button>
					<button type="button" class="button-link-delete" data-role="remove-poster" style="margin-left:6px;">Clear</button>
				</div>
			</td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[caption]" value="<?php echo esc_attr( $item['caption'] ); ?>" maxlength="<?php echo (int) self::MAX_TEXT; ?>" style="width:100%;"></td>
			<td style="white-space:nowrap;">
				<button type="button" class="button button-small" data-role="up" title="Move up" aria-label="Move up">&uarr;</button>
				<button type="button" class="button button-small" data-role="down" title="Move down" aria-label="Move down">&darr;</button>
				<button type="button" class="button-link-delete" data-role="remove" aria-label="Remove item">&times;</button>
			</td>
		</tr>
		<?php
	}

	/** Mirrors _check_media_url(): media is rendered straight into
	 * <img>/<video> src on the public page, so only media-library files or
	 * https:// links are accepted. Returns '' for anything else. */
	private static function clean_https_url( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > self::MAX_TEXT || 0 !== stripos( $raw, 'https://' ) ) {
			return '';
		}
		return esc_url_raw( $raw, array( 'https' ) );
	}

	/** An attachment id is kept only if it really is an attachment of the
	 * expected kind (image/* for photos and posters, video/* for videos). */
	private static function valid_attachment( int $id, string $kind ): int {
		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			return 0;
		}
		return 0 === strpos( (string) get_post_mime_type( $id ), $kind . '/' ) ? $id : 0;
	}

	public static function save( int $post_id, \WP_Post $post ) {
		if ( ! isset( $_POST['drc_story_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['drc_story_nonce'] ) ), 'drc_save_story' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Inspiration — single line, max 300 chars.
		$inspiration = sanitize_text_field( wp_unslash( $_POST['drc_story_inspiration'] ?? '' ) );
		$inspiration = mb_substr( $inspiration, 0, self::MAX_TEXT );
		if ( '' === $inspiration ) {
			delete_post_meta( $post_id, self::META_INSPIRATION );
		} else {
			update_post_meta( $post_id, self::META_INSPIRATION, $inspiration );
		}

		// Linked rug — must be an existing WooCommerce product (_check_rug()).
		$product_id = absint( $_POST['drc_story_product_id'] ?? 0 );
		if ( $product_id && 'product' === get_post_type( $product_id ) ) {
			update_post_meta( $post_id, self::META_PRODUCT, $product_id );
		} else {
			delete_post_meta( $post_id, self::META_PRODUCT );
		}

		// Media — ordered as submitted (DOM order), invalid rows dropped, max 40.
		$rows  = isset( $_POST['drc_story_media'] ) && is_array( $_POST['drc_story_media'] ) ? wp_unslash( $_POST['drc_story_media'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitized individually below.
		$media = array();
		foreach ( $rows as $row ) {
			if ( count( $media ) >= self::MAX_MEDIA ) {
				break;
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type = sanitize_key( $row['type'] ?? '' );
			if ( ! in_array( $type, array( 'image', 'video' ), true ) ) {
				continue;
			}
			$attachment_id = self::valid_attachment( absint( $row['attachment_id'] ?? 0 ), $type );
			$url           = $attachment_id ? '' : self::clean_https_url( (string) ( $row['url'] ?? '' ) );
			if ( ! $attachment_id && '' === $url ) {
				continue; // Empty row, or a non-https external URL.
			}
			$poster_id  = 'video' === $type ? self::valid_attachment( absint( $row['poster_id'] ?? 0 ), 'image' ) : 0;
			$poster_url = ( 'video' === $type && ! $poster_id ) ? self::clean_https_url( (string) ( $row['poster_url'] ?? '' ) ) : '';
			$caption    = mb_substr( sanitize_text_field( (string) ( $row['caption'] ?? '' ) ), 0, self::MAX_TEXT );
			$media[]    = array(
				'type'          => $type,
				'attachment_id' => $attachment_id,
				'url'           => $url,
				'poster_id'     => $poster_id,
				'poster_url'    => $poster_url,
				'caption'       => $caption,
			);
		}
		if ( empty( $media ) ) {
			delete_post_meta( $post_id, self::META_MEDIA );
		} else {
			// wp_slash(): update_post_meta() unslashes its value, which would
			// otherwise eat the JSON's own escape backslashes.
			update_post_meta( $post_id, self::META_MEDIA, wp_slash( wp_json_encode( $media ) ) );
		}
	}

	public static function admin_columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['drc_story_rug']   = 'Linked Rug';
				$out['drc_story_media'] = 'Media';
				$out['drc_story_order'] = 'Order';
			}
		}
		return $out;
	}

	public static function render_admin_column( string $column, int $post_id ) {
		if ( 'drc_story_rug' === $column ) {
			$product = self::linked_product( $post_id );
			echo $product ? esc_html( $product->get_name() ) : '&mdash;';
		} elseif ( 'drc_story_media' === $column ) {
			echo (int) count( self::get_raw_media( $post_id ) );
		} elseif ( 'drc_story_order' === $column ) {
			echo (int) get_post_field( 'menu_order', $post_id );
		}
	}

	// ── Rank Math (SEO + sitemap) ───────────────────────────────────────────
	//
	// Rank Math only puts a post type in sitemap.xml / gives it titles and
	// schema once its settings have a `pt_{type}_*` entry, which a brand-new
	// CPT doesn't have until someone saves Rank Math's settings screens. These
	// option filters supply defaults *only for keys that are missing*, so an
	// admin's own later choice in Rank Math → Sitemap / Titles & Meta always
	// wins. Nothing is written to the database.

	public static function rank_math_sitemap_defaults( $options ) {
		if ( is_array( $options ) && ! array_key_exists( 'pt_' . self::POST_TYPE . '_sitemap', $options ) ) {
			$options[ 'pt_' . self::POST_TYPE . '_sitemap' ] = 'on'; // Includes the /stories archive link + every published story.
		}
		return $options;
	}

	public static function rank_math_title_defaults( $options ) {
		if ( ! is_array( $options ) ) {
			return $options;
		}
		$pt       = 'pt_' . self::POST_TYPE . '_';
		$defaults = array(
			// StoryDetail.tsx: `${story.title} — The Story Behind the Design`.
			'title'                => '%title% — The Story Behind the Design %sep% %sitename%',
			'description'          => '%excerpt%',
			'robots'               => array( 'index' ),
			'custom_robots'        => 'off',
			// StoryDetail.tsx emitted schema.org Article JSON-LD.
			'default_rich_snippet' => 'article',
			'default_article_type' => 'Article',
			'default_snippet_name' => '%seo_title%',
			'default_snippet_desc' => '%seo_description%',
			// CustomerStories.tsx's <SEO>.
			'archive_title'        => 'Design Stories %sep% %sitename%',
			'archive_description'  => 'The stories behind our unique handmade rug designs — what inspired each one, and how our artisans brought it to life on the loom.',
			'add_meta_box'         => 'on',
		);
		foreach ( $defaults as $key => $value ) {
			if ( ! array_key_exists( $pt . $key, $options ) ) {
				$options[ $pt . $key ] = $value;
			}
		}
		return $options;
	}

	/** StoryDetail.tsx's meta description: inspiration line, else the
	 * story's plain text — used only when no description was entered in
	 * Rank Math's own per-post box. */
	public static function rank_math_description( $description ) {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return $description;
		}
		$post_id = get_queried_object_id();
		if ( '' !== (string) get_post_meta( $post_id, 'rank_math_description', true ) ) {
			return $description;
		}
		$inspiration = (string) get_post_meta( $post_id, self::META_INSPIRATION, true );
		if ( '' !== $inspiration ) {
			return $inspiration;
		}
		$plain = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( self::sanitize_body_html( (string) get_post_field( 'post_content', $post_id ) ) ) ) );
		return '' !== $plain ? wp_html_excerpt( $plain, 160, '…' ) : $description;
	}
}
