<?php
/**
 * Per-rug size variations and the default size's engine price — shared by
 * the product page (single-product.php) and the Pinterest feed
 * (DRC_Pinterest_Feed), so both always show the same sizes and price.
 * Mirrors the original app's _public_catalog_sizes() / _public_catalog_offer()
 * in backend/app/api/routes/customer.py.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Product_Data {

	/**
	 * Size variations, smallest first (utils/size.ts compareSizes: numeric ft
	 * dimensions ascending, free-text sizes after), plus the index of the
	 * vendor's default size (WooCommerce "Default Form Values", else the
	 * first) and the variation attribute name.
	 *
	 * Every DRC-priced variable product is expected to have exactly one
	 * variation attribute, conventionally "Size" (slug "size"), whose value
	 * equals the variation's own _drc_size_ft meta — WooCommerce's non-AJAX
	 * add-to-cart handler needs that attribute_{name} field submitted.
	 *
	 * @return array{variations: array<int, array>, default_index: int, attribute_name: string}
	 */
	public static function size_variations( WC_Product $product ): array {
		$lead_time_days = (int) ( $product->get_meta( '_drc_lead_time_days' ) ?: 21 );
		$variations     = array();
		$attribute_name = 'size';
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $variation_id ) {
				$variation = wc_get_product( $variation_id );
				if ( ! $variation ) {
					continue;
				}
				$ft = $variation->get_meta( '_drc_size_ft' );
				if ( ! $ft ) {
					continue;
				}
				$variation_attrs = $variation->get_attributes();
				if ( ! empty( $variation_attrs ) ) {
					$attribute_name = array_key_first( $variation_attrs );
				}
				$variations[] = array(
					'variation_id'    => $variation_id,
					'ft'              => $ft,
					'cm'              => $variation->get_meta( '_drc_size_cm' ) ?: null, // vendor-typed only, never computed from ft — see DRC_Size_Format's header comment
					'attribute_value' => $variation_attrs[ $attribute_name ] ?? $ft,
					'price'           => (float) $variation->get_price(),
					'lead_time_days'  => (int) ( $variation->get_meta( '_drc_lead_time_days' ) ?: $lead_time_days ),
				);
			}
		}

		usort( $variations, function ( $a, $b ) {
			$dims = function ( $ft ) { return preg_match( "/^(\d+(?:\.\d+)?)\s*(?:ft|feet|')?\s*[x×X]\s*(\d+(?:\.\d+)?)/u", trim( (string) $ft ), $m ) ? array( (float) $m[1], (float) $m[2] ) : null; };
			$l    = $dims( $a['ft'] );
			$r    = $dims( $b['ft'] );
			if ( $l && $r ) {
				return ( $l[0] <=> $r[0] ) ?: ( $l[1] <=> $r[1] );
			}
			if ( $l || $r ) {
				return $l ? -1 : 1;
			}
			return strnatcmp( $a['ft'], $b['ft'] );
		} );

		$default_attrs = $product->is_type( 'variable' ) ? $product->get_default_attributes() : array();
		$default_index = 0;
		foreach ( $variations as $i => $v ) {
			if ( isset( $default_attrs[ $attribute_name ] ) && (string) $default_attrs[ $attribute_name ] === (string) $v['attribute_value'] ) {
				$default_index = $i;
				break;
			}
		}

		return array( 'variations' => $variations, 'default_index' => $default_index, 'attribute_name' => $attribute_name );
	}

	/**
	 * The default size's price through the quote engine (base currency), or
	 * null when it can't be calculated — no material, no parsable size, or
	 * an engine error. Same rule as the original's _public_catalog_offer().
	 */
	public static function display_price( WC_Product $product, ?array $sizes = null ): ?float {
		$sizes       = $sizes ?? self::size_variations( $product );
		$material_id = (int) $product->get_meta( '_drc_material_id' );
		if ( ! $sizes['variations'] || ! $material_id ) {
			return null;
		}
		$default = $sizes['variations'][ $sizes['default_index'] ] ?? $sizes['variations'][0];
		$dims    = DRC_Quote_Engine::parse_catalog_dimensions( $default['ft'], 0.3048 );
		$rug     = $dims ? DRC_Quote_Engine::get_rug_for_pricing( $product->get_id() ) : null;
		if ( ! $rug ) {
			return null;
		}
		$calc = ( new DRC_Quote_Engine() )->calculate_quote( $rug, $dims[0], $dims[1], $material_id, 1, false );
		return isset( $calc['error'] ) ? null : (float) $calc['final_price'];
	}
}
