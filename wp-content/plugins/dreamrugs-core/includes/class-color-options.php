<?php
/**
 * Customer-selectable rug colorways — mirrors the original app's
 * RugCatalog.color_options ({name, hex, image_url} list; see the migration
 * plan and frontend/src/pages/Catalog.tsx's admin editor for this field).
 * Stored as JSON on product postmeta (_drc_color_options) rather than a
 * custom table: it's a small, product-scoped list with no independent
 * querying need, unlike wp_drc_quotes — postmeta is the right tool here.
 *
 * Threaded through to the storefront via single-product.php's color-swatch
 * picker, archive-product.php's catalog-grid swatches, and — at
 * add-to-cart/quote-request time — DRC_Cart_Pricing's cart-item-data hooks
 * and DRC_Rest_Routes::create_quote()'s selected_color validation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Color_Options {

	const META_KEY = '_drc_color_options';

	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'maybe_enqueue_media' ) );
	}

	/** @return array<int, array{name: string, hex: string, image_url: ?string}> */
	public static function get( int $product_id ): array {
		$raw = get_post_meta( $product_id, self::META_KEY, true );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		return array_values( array_filter( array_map( function ( $row ) {
			if ( ! is_array( $row ) || empty( $row['name'] ) || empty( $row['hex'] ) ) {
				return null;
			}
			return array(
				'name'      => (string) $row['name'],
				'hex'       => (string) $row['hex'],
				'image_url' => ! empty( $row['image_url'] ) ? (string) $row['image_url'] : null,
			);
		}, $decoded ) ) );
	}

	/**
	 * Storefront variant of get(): a colour photo that lives in the media
	 * library is swapped for its "large" copy (WebP, see DRC_Webp_Images)
	 * instead of the multi-MB original. get() itself stays untouched — the
	 * admin meta box saves what it reads back.
	 *
	 * @return array<int, array{name: string, hex: string, image_url: ?string}>
	 */
	public static function for_display( int $product_id ): array {
		return array_map( function ( $color ) {
			if ( $color['image_url'] ) {
				$attachment_id = attachment_url_to_postid( $color['image_url'] );
				if ( $attachment_id ) {
					$color['image_url'] = wp_get_attachment_image_url( $attachment_id, 'large' ) ?: $color['image_url'];
				}
			}
			return $color;
		}, self::get( $product_id ) );
	}

	public static function add_meta_box( $post ) {
		if ( ! wc_get_product( $post )?->is_type( 'variable' ) ) {
			return; // Colorways only make sense on the variable (size-picker) rugs DRC prices.
		}
		add_meta_box(
			'drc-color-options',
			'Color Options',
			array( __CLASS__, 'render_meta_box' ),
			'product',
			'normal',
			'default'
		);
	}

	public static function maybe_enqueue_media( $hook ) {
		global $post;
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && $post && 'product' === $post->post_type ) {
			wp_enqueue_media();
		}
	}

	public static function render_meta_box( $post ) {
		$colors = self::get( $post->ID );
		wp_nonce_field( 'drc_save_color_options', 'drc_color_options_nonce' );
		?>
		<p class="description">Customers pick one colorway before adding this rug to their cart or requesting a quote. Leave empty if this rug doesn't offer color choices.</p>
		<table class="widefat" id="drc-color-options-table" style="max-width:900px;">
			<thead>
				<tr>
					<th style="width:60px;">Swatch</th>
					<th>Name</th>
					<th style="width:180px;">Image (optional)</th>
					<th style="width:40px;"></th>
				</tr>
			</thead>
			<tbody id="drc-color-options-rows">
				<?php foreach ( $colors as $i => $color ) : ?>
					<?php self::render_row( $i, $color ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" id="drc-color-options-add">+ Add Color</button></p>

		<template id="drc-color-option-row-template">
			<?php self::render_row( '__INDEX__', array( 'name' => '', 'hex' => '#8B5E3C', 'image_url' => null ) ); ?>
		</template>

		<script>
		( function () {
			var tbody = document.getElementById( 'drc-color-options-rows' );
			var addBtn = document.getElementById( 'drc-color-options-add' );
			var template = document.getElementById( 'drc-color-option-row-template' );
			var nextIndex = <?php echo (int) count( $colors ); ?>;

			function wireRow( row ) {
				var imageInput  = row.querySelector( '[data-role="image-url"]' );
				var preview     = row.querySelector( '[data-role="image-preview"]' );
				var chooseBtn   = row.querySelector( '[data-role="image-choose"]' );
				var removeImgBtn = row.querySelector( '[data-role="image-remove"]' );
				var removeBtn   = row.querySelector( '[data-role="remove-row"]' );

				chooseBtn.addEventListener( 'click', function () {
					var frame = wp.media( { title: 'Select Color Image', button: { text: 'Use this image' }, multiple: false } );
					frame.on( 'select', function () {
						var attachment = frame.state().get( 'selection' ).first().toJSON();
						imageInput.value = attachment.url;
						preview.src = attachment.url;
						preview.style.display = '';
						removeImgBtn.style.display = '';
					} );
					frame.open();
				} );
				removeImgBtn.addEventListener( 'click', function () {
					imageInput.value = '';
					preview.style.display = 'none';
					removeImgBtn.style.display = 'none';
				} );
				removeBtn.addEventListener( 'click', function () {
					row.remove();
				} );
			}

			tbody.querySelectorAll( 'tr' ).forEach( wireRow );

			addBtn.addEventListener( 'click', function () {
				var html = template.innerHTML.replace( /__INDEX__/g, String( nextIndex++ ) );
				var wrapper = document.createElement( 'tbody' );
				wrapper.innerHTML = html;
				var row = wrapper.firstElementChild;
				tbody.appendChild( row );
				wireRow( row );
			} );
		} )();
		</script>
		<?php
	}

	private static function render_row( $index, array $color ) {
		$hex = preg_match( '/^#[0-9A-Fa-f]{6}$/', $color['hex'] ?? '' ) ? $color['hex'] : '#8B5E3C';
		?>
		<tr>
			<td><input type="color" name="drc_color_options[<?php echo esc_attr( $index ); ?>][hex]" value="<?php echo esc_attr( $hex ); ?>" style="width:44px;height:36px;padding:2px;cursor:pointer;"></td>
			<td><input type="text" name="drc_color_options[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $color['name'] ?? '' ); ?>" placeholder="e.g. Rosewood" style="width:100%;"></td>
			<td>
				<input type="hidden" data-role="image-url" name="drc_color_options[<?php echo esc_attr( $index ); ?>][image_url]" value="<?php echo esc_attr( $color['image_url'] ?? '' ); ?>">
				<img data-role="image-preview" src="<?php echo esc_url( $color['image_url'] ?? '' ); ?>" style="max-width:60px;max-height:60px;display:<?php echo empty( $color['image_url'] ) ? 'none' : ''; ?>;vertical-align:middle;margin-right:6px;">
				<button type="button" class="button button-small" data-role="image-choose">Choose</button>
				<button type="button" class="button-link-delete" data-role="image-remove" style="display:<?php echo empty( $color['image_url'] ) ? 'none' : ''; ?>;margin-left:6px;">Remove</button>
			</td>
			<td><button type="button" class="button-link-delete" data-role="remove-row" aria-label="Remove color">&times;</button></td>
		</tr>
		<?php
	}

	public static function save( int $post_id ) {
		if ( ! isset( $_POST['drc_color_options_nonce'] ) || ! wp_verify_nonce( $_POST['drc_color_options_nonce'], 'drc_save_color_options' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) ) {
			return;
		}

		$rows = $_POST['drc_color_options'] ?? array();
		$colors = array();
		$seen_names = array();
		foreach ( (array) $rows as $row ) {
			$name = sanitize_text_field( wp_unslash( $row['name'] ?? '' ) );
			if ( '' === trim( $name ) ) {
				continue; // A blank row (never filled in, or emptied via "remove") is silently dropped rather than saved as an invalid colorway.
			}
			$hex = strtoupper( sanitize_text_field( wp_unslash( $row['hex'] ?? '' ) ) );
			if ( ! preg_match( '/^#[0-9A-F]{6}$/', $hex ) ) {
				continue;
			}
			$key = strtolower( $name );
			if ( isset( $seen_names[ $key ] ) ) {
				continue; // Names must be unique — mirrors the admin validation in the original app's Catalog.tsx editor.
			}
			$seen_names[ $key ] = true;
			$image_url = trim( wp_unslash( $row['image_url'] ?? '' ) );
			$colors[] = array(
				'name'      => $name,
				'hex'       => $hex,
				'image_url' => '' !== $image_url ? esc_url_raw( $image_url ) : null,
			);
		}

		if ( empty( $colors ) ) {
			delete_post_meta( $post_id, self::META_KEY );
		} else {
			update_post_meta( $post_id, self::META_KEY, wp_json_encode( $colors ) );
		}
	}
}
