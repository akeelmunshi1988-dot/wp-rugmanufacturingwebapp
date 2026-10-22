<?php
/**
 * Admin fields for the three per-variation meta keys DRC_Quote_Engine and
 * single-product.php already read (_drc_size_ft, _drc_size_cm,
 * _drc_lead_time_days) — see class-quote-engine.php's get_rug_for_pricing()
 * comment ("registered by the catalog admin UI ... not yet implemented
 * here") and single-product.php:53's convention comment. Until this class
 * existed, a vendor had no in-admin way to set these beyond raw Custom
 * Fields, and no default lead-time assist — the equivalent of the original
 * app's SizesEditor.tsx (frontend/src/components/SizesEditor.tsx), whose
 * DEFAULT_LEAD_TIME_DAYS = 21 auto-fill this mirrors.
 *
 * Hooked into WooCommerce's native per-variation rendering/saving
 * (woocommerce_product_after_variable_attributes /
 * woocommerce_save_product_variation) rather than a product-level meta box
 * like DRC_Color_Options — these three fields are properties of one
 * variation, not the parent product, so they belong inside WooCommerce's own
 * per-variation panel alongside price/SKU/stock. WooCommerce's variation
 * save request (WC_AJAX::save_variations()) already verifies its own nonce
 * and edit_product capability before this hook ever fires, so no separate
 * nonce/capability check is needed here — every other WooCommerce
 * variation-field extension follows the same convention.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Variation_Fields {

	const DEFAULT_LEAD_TIME_DAYS = 21;

	public static function init() {
		add_action( 'woocommerce_product_after_variable_attributes', array( __CLASS__, 'render_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_fields' ), 10, 2 );
		add_action( 'admin_footer', array( __CLASS__, 'size_suggestions' ) );
	}

	/** Common sizes (DreamRugs → Catalog Masters) offered as suggestions in
	 * every variation's ft/cm field — typing anything else still works. */
	public static function size_suggestions() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'product' !== $screen->id || ! class_exists( 'DRC_Catalog_Masters' ) ) {
			return;
		}
		$sizes = DRC_Catalog_Masters::get( 'sizes', true );
		echo '<datalist id="drc-size-ft-options">';
		foreach ( array_unique( array_filter( wp_list_pluck( $sizes, 'ft' ) ) ) as $ft ) {
			echo '<option value="' . esc_attr( $ft ) . '"></option>';
		}
		echo '</datalist><datalist id="drc-size-cm-options">';
		foreach ( array_unique( array_filter( wp_list_pluck( $sizes, 'cm' ) ) ) as $cm ) {
			echo '<option value="' . esc_attr( $cm ) . '"></option>';
		}
		echo '</datalist>';
	}

	/**
	 * @param int     $loop           Row index within the variations panel.
	 * @param array   $variation_data Unused — kept for the hook's signature.
	 * @param WP_Post $variation      The variation post.
	 */
	public static function render_fields( $loop, $variation_data, $variation ) {
		$variation_id = $variation->ID;
		$ft            = get_post_meta( $variation_id, '_drc_size_ft', true );
		$cm            = get_post_meta( $variation_id, '_drc_size_cm', true );
		$lead_time     = get_post_meta( $variation_id, '_drc_lead_time_days', true );
		?>
		<div class="drc-variation-fields" style="clear:both;padding-top:8px;border-top:1px solid #eee;">
			<p class="form-row form-row-first">
				<label for="drc_size_ft_<?php echo esc_attr( $loop ); ?>">
					Size label (ft) <span class="description">(e.g. "6x9", "3 round ft" — shown to customers)</span>
				</label>
				<input
					type="text"
					id="drc_size_ft_<?php echo esc_attr( $loop ); ?>"
					name="drc_size_ft[<?php echo esc_attr( $loop ); ?>]"
					list="drc-size-ft-options"
					value="<?php echo esc_attr( $ft ); ?>"
					style="width:100%;"
				>
			</p>
			<p class="form-row form-row-last">
				<label for="drc_size_cm_<?php echo esc_attr( $loop ); ?>">
					Size label (cm) <span class="description">(optional — never auto-converted from ft)</span>
				</label>
				<input
					type="text"
					id="drc_size_cm_<?php echo esc_attr( $loop ); ?>"
					name="drc_size_cm[<?php echo esc_attr( $loop ); ?>]"
					list="drc-size-cm-options"
					value="<?php echo esc_attr( $cm ); ?>"
					style="width:100%;"
				>
			</p>
			<p class="form-row form-row-first">
				<label for="drc_lead_time_days_<?php echo esc_attr( $loop ); ?>">
					Lead time (days)
				</label>
				<input
					type="number"
					min="0"
					step="1"
					id="drc_lead_time_days_<?php echo esc_attr( $loop ); ?>"
					name="drc_lead_time_days[<?php echo esc_attr( $loop ); ?>]"
					value="<?php echo esc_attr( $lead_time ?: self::DEFAULT_LEAD_TIME_DAYS ); ?>"
					style="width:100%;"
				>
			</p>
		</div>
		<?php
	}

	public static function save_fields( int $variation_id, int $i ) {
		if ( isset( $_POST['drc_size_ft'][ $i ] ) ) {
			$ft = sanitize_text_field( wp_unslash( $_POST['drc_size_ft'][ $i ] ) );
			if ( '' !== $ft ) {
				update_post_meta( $variation_id, '_drc_size_ft', $ft );
			} else {
				delete_post_meta( $variation_id, '_drc_size_ft' );
			}
		}
		if ( isset( $_POST['drc_size_cm'][ $i ] ) ) {
			$cm = sanitize_text_field( wp_unslash( $_POST['drc_size_cm'][ $i ] ) );
			if ( '' !== $cm ) {
				update_post_meta( $variation_id, '_drc_size_cm', $cm );
			} else {
				delete_post_meta( $variation_id, '_drc_size_cm' );
			}
		}
		if ( isset( $_POST['drc_lead_time_days'][ $i ] ) ) {
			$raw = trim( wp_unslash( $_POST['drc_lead_time_days'][ $i ] ) );
			// Blank or non-numeric submissions fall back to the same 21-day
			// default the front end already assumes when this meta is unset,
			// rather than saving an empty/zero value.
			$lead_time = ( '' !== $raw && is_numeric( $raw ) ) ? max( 0, (int) $raw ) : self::DEFAULT_LEAD_TIME_DAYS;
			update_post_meta( $variation_id, '_drc_lead_time_days', $lead_time );
		}
	}
}
