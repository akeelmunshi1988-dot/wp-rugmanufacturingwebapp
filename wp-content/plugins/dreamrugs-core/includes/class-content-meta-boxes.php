<?php
/**
 * Proper edit forms for the CMS content types (DRC_Content_Types) in place
 * of WordPress's raw Custom Fields box — the fields the original app's
 * admin forms had: media pickers for videos/posters/extra gallery images,
 * a rug picker for FAQs, the homepage video tab / intro flag, and an
 * Active switch + display order on every type (also shown as list
 * columns). Meta keys are unchanged, so the storefront and REST are too.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Content_Meta_Boxes {

	/** post type => [ meta key => [ label, type, help? ] ] */
	private static function fields(): array {
		$common = array(
			'sort_order' => array( 'Display order', 'number', 'Lower numbers show first.' ),
			'is_active'  => array( 'Active', 'checkbox', 'Show this on the website.' ),
		);
		return array(
			'drc_workshop_photo' => array(
				'description' => array( 'Description', 'textarea' ),
			) + $common,
			'drc_rug_step_image' => $common,
			'drc_showcase_video' => array(
				'description' => array( 'Description', 'textarea' ),
				'video_url'   => array( 'Video', 'video', 'Upload or pick an MP4, or paste a video URL.' ),
				'poster_url'  => array( 'Poster image', 'image_url', 'Shown before the video plays.' ),
				'tab_name'    => array( 'Homepage tab', 'text', 'Groups videos into tabs in "Behind the Craft" — e.g. Craftsmanship, Materials. Blank = Craftsmanship.' ),
				'is_intro'    => array( 'Intro video', 'checkbox', 'Use as the large intro video instead of a grid tile.' ),
			) + $common,
			'drc_testimonial' => array(
				'author_title' => array( 'Author title', 'text', 'e.g. Interior Designer' ),
				'country'      => array( 'Country', 'text' ),
				'quote'        => array( 'Quote', 'textarea' ),
				'rating'       => array( 'Rating', 'rating' ),
			) + $common,
			'drc_gallery_item' => array(
				'description'     => array( 'Project description', 'textarea' ),
				'owner_name'      => array( 'Owner name', 'text' ),
				'owner_message'   => array( 'Owner message', 'textarea' ),
				'rating'          => array( 'Owner rating', 'rating' ),
				'link_url'        => array( 'Link', 'url', 'Optional — where the homepage tile links.' ),
				'extra_image_ids' => array( 'More photos', 'images', 'Shown on the project page after the featured image.' ),
			) + $common,
			'drc_journey_step' => array(
				'description' => array( 'Description', 'textarea' ),
			) + $common,
			'drc_announcement' => array(
				'link_url' => array( 'Link', 'url', 'Optional — makes the announcement bar clickable.' ),
			) + $common,
			'drc_faq' => array(
				'answer'     => array( 'Answer', 'textarea' ),
				'product_id' => array( 'Rug', 'product', 'Leave empty to show this FAQ on every rug.' ),
			) + $common,
		);
	}

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'save_post', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array_keys( self::fields() ) as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
			add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		}
	}

	public static function add() {
		foreach ( self::fields() as $type => $fields ) {
			remove_meta_box( 'postcustom', $type, 'normal' );
			add_meta_box( 'drc_content_fields', 'Details', array( __CLASS__, 'render' ), $type, 'normal', 'high' );
		}
	}

	public static function assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || ! isset( self::fields()[ $screen->post_type ] ) || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_media();
		if ( 'drc_faq' === $screen->post_type ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
		wp_add_inline_script( 'media-editor', self::script() );
	}

	public static function render( WP_Post $post ) {
		$fields = self::fields()[ $post->post_type ];
		$is_new = 'auto-draft' === $post->post_status;
		wp_nonce_field( 'drc_content_fields', 'drc_content_fields_nonce' );
		echo '<table class="form-table" role="presentation">';
		foreach ( $fields as $key => $def ) {
			list( $label, $type ) = $def;
			$help  = $def[2] ?? '';
			$value = get_post_meta( $post->ID, $key, true );
			if ( $is_new && 'is_active' === $key ) {
				$value = '1';
			}
			$id = 'drc_f_' . $key;
			echo '<tr><th><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
			switch ( $type ) {
				case 'textarea':
					echo '<textarea id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']" rows="4" class="large-text">' . esc_textarea( (string) $value ) . '</textarea>';
					break;
				case 'checkbox':
					echo '<label><input type="checkbox" id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']" value="1"' . checked( (bool) $value, true, false ) . '> ' . esc_html( $help ) . '</label>';
					$help = '';
					break;
				case 'number':
					echo '<input type="number" id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']" value="' . esc_attr( '' === $value ? '0' : (string) $value ) . '" class="small-text">';
					break;
				case 'rating':
					echo '<select id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']"><option value="">—</option>';
					for ( $i = 5; $i >= 1; $i-- ) {
						echo '<option value="' . (int) $i . '"' . selected( (int) $value, $i, false ) . '>' . esc_html( str_repeat( '★', $i ) ) . '</option>';
					}
					echo '</select>';
					break;
				case 'product':
					$product = $value ? wc_get_product( (int) $value ) : null;
					echo '<select id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']" class="wc-product-search" style="width:100%;max-width:420px" data-action="woocommerce_json_search_products" data-allow_clear="true" data-placeholder="All rugs">';
					if ( $product ) {
						echo '<option value="' . (int) $product->get_id() . '" selected>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
					}
					echo '</select>';
					break;
				case 'video':
				case 'image_url':
					$media = 'video' === $type ? 'video' : 'image';
					echo '<input type="url" id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" class="large-text drc-media-url"> ';
					echo '<p><button type="button" class="button drc-pick-url" data-target="' . esc_attr( $id ) . '" data-type="' . esc_attr( $media ) . '">Choose ' . esc_html( $media ) . '</button></p>';
					if ( $value ) {
						echo 'video' === $type
							? '<video src="' . esc_url( (string) $value ) . '" style="max-width:320px;margin-top:6px" controls muted></video>'
							: '<img src="' . esc_url( (string) $value ) . '" style="max-width:200px;margin-top:6px" alt="">';
					}
					break;
				case 'images':
					$ids = array_filter( array_map( 'absint', explode( ',', (string) $value ) ) );
					echo '<input type="hidden" id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']" value="' . esc_attr( implode( ',', $ids ) ) . '">';
					echo '<div class="drc-images" data-target="' . esc_attr( $id ) . '" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px">';
					foreach ( $ids as $aid ) {
						$src = wp_get_attachment_image_url( $aid, 'thumbnail' );
						if ( $src ) {
							echo '<span class="drc-image" data-id="' . (int) $aid . '" style="position:relative"><img src="' . esc_url( $src ) . '" style="width:80px;height:80px;object-fit:cover;display:block" alt=""><button type="button" class="drc-remove-image" aria-label="Remove" style="position:absolute;top:2px;right:2px;border:0;background:#fff;cursor:pointer;line-height:1">×</button></span>';
						}
					}
					echo '</div><button type="button" class="button drc-pick-images" data-target="' . esc_attr( $id ) . '">Add photos</button>';
					break;
				default:
					echo '<input type="' . ( 'url' === $type ? 'url' : 'text' ) . '" id="' . esc_attr( $id ) . '" name="drc_f[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $value ) . '" class="regular-text">';
			}
			if ( $help ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
	}

	public static function save( $post_id, $post ) {
		$all = self::fields();
		if ( ! isset( $all[ $post->post_type ] ) || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['drc_content_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['drc_content_fields_nonce'] ) ), 'drc_content_fields' ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$input = isset( $_POST['drc_f'] ) && is_array( $_POST['drc_f'] ) ? wp_unslash( $_POST['drc_f'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per type below.
		foreach ( $all[ $post->post_type ] as $key => $def ) {
			$raw = $input[ $key ] ?? '';
			switch ( $def[1] ) {
				case 'checkbox':
					$value = ! empty( $raw );
					break;
				case 'number':
					$value = (int) $raw;
					break;
				case 'rating':
				case 'product':
					$value = $raw ? absint( $raw ) : 0;
					break;
				case 'textarea':
					$value = sanitize_textarea_field( (string) $raw );
					break;
				case 'url':
				case 'video':
				case 'image_url':
					$value = esc_url_raw( (string) $raw );
					break;
				case 'images':
					$value = implode( ',', array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) ) );
					break;
				default:
					$value = sanitize_text_field( (string) $raw );
			}
			update_post_meta( $post_id, $key, $value );
		}
	}

	public static function columns( $columns ) {
		$date = $columns['date'] ?? null;
		unset( $columns['date'] );
		$columns['drc_active'] = 'Active';
		$columns['drc_order']  = 'Order';
		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	public static function column( $column, $post_id ) {
		if ( 'drc_active' === $column ) {
			echo get_post_meta( $post_id, 'is_active', true ) ? '<span style="color:#00a32a">● Active</span>' : '<span style="color:#999">○ Hidden</span>';
		} elseif ( 'drc_order' === $column ) {
			echo (int) get_post_meta( $post_id, 'sort_order', true );
		}
	}

	private static function script(): string {
		return <<<'JS'
( function () {
	document.addEventListener( 'click', function ( e ) {
		var pickUrl = e.target.closest( '.drc-pick-url' );
		if ( pickUrl ) {
			e.preventDefault();
			var input = document.getElementById( pickUrl.dataset.target );
			var frame = wp.media( { title: 'Choose ' + pickUrl.dataset.type, library: { type: pickUrl.dataset.type }, button: { text: 'Use this' }, multiple: false } );
			frame.on( 'select', function () { input.value = frame.state().get( 'selection' ).first().toJSON().url; } );
			frame.open();
			return;
		}
		var pickImages = e.target.closest( '.drc-pick-images' );
		if ( pickImages ) {
			e.preventDefault();
			var hidden = document.getElementById( pickImages.dataset.target );
			var list = document.querySelector( '.drc-images[data-target="' + pickImages.dataset.target + '"]' );
			var frame2 = wp.media( { title: 'Add photos', library: { type: 'image' }, button: { text: 'Add' }, multiple: true } );
			frame2.on( 'select', function () {
				var ids = hidden.value ? hidden.value.split( ',' ) : [];
				frame2.state().get( 'selection' ).each( function ( att ) {
					var a = att.toJSON();
					if ( ids.indexOf( String( a.id ) ) !== -1 ) return;
					ids.push( String( a.id ) );
					var src = ( a.sizes && a.sizes.thumbnail ) ? a.sizes.thumbnail.url : a.url;
					var span = document.createElement( 'span' );
					span.className = 'drc-image'; span.dataset.id = a.id; span.style.position = 'relative';
					span.innerHTML = '<img src="" style="width:80px;height:80px;object-fit:cover;display:block" alt=""><button type="button" class="drc-remove-image" aria-label="Remove" style="position:absolute;top:2px;right:2px;border:0;background:#fff;cursor:pointer;line-height:1">×</button>';
					span.querySelector( 'img' ).src = src;
					list.appendChild( span );
				} );
				hidden.value = ids.join( ',' );
			} );
			frame2.open();
			return;
		}
		var remove = e.target.closest( '.drc-remove-image' );
		if ( remove ) {
			e.preventDefault();
			var item = remove.closest( '.drc-image' );
			var wrap = item.closest( '.drc-images' );
			var field = document.getElementById( wrap.dataset.target );
			field.value = field.value.split( ',' ).filter( function ( id ) { return id !== String( item.dataset.id ); } ).join( ',' );
			item.remove();
		}
	} );
} )();
JS;
	}
}
