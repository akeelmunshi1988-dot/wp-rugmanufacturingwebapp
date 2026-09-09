<?php
/**
 * Direct PHP port of the original app's backend/app/services/quote_engine.py.
 * Every formula, rounding call, and conditional below is intentionally kept
 * in the same order and shape as the Python source so the two can be
 * diffed input-for-input during QA (see the migration plan's "Verification"
 * section) — do not "clean up" the structure without re-verifying against
 * the original file.
 *
 * Deliberately WordPress/WooCommerce-agnostic beyond the small adapter
 * methods at the bottom (get_rug_size_offer, get_material, etc.) — those
 * are the only places that touch $wpdb/wc_get_product(), so the pricing
 * math itself stays independently testable.
 *
 * Known fidelity caveat: PHP's round() rounds half-away-from-zero; Python
 * 3's round() rounds half-to-even (banker's rounding). These only disagree
 * on values landing exactly on a .xx5 boundary at the rounded digit, which
 * is rare for computed currency figures — flagged here rather than
 * "fixed" with a custom banker's-rounding shim, per the plan's guidance to
 * budget QA time on this file rather than over-engineer around an unlikely
 * edge case.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Quote_Engine {

	/** Mirrors _parse_catalog_dimensions() — parses a "6x9" / "6×9" string
	 * into metres using the given metres-per-unit constant. Returns null
	 * (not a fallback guess) on anything that doesn't parse, same as the
	 * original. */
	public static function parse_catalog_dimensions( $value, float $metres_per_unit ): ?array {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$parts = preg_split( '/\s*[x×]\s*/', strtolower( trim( $value ) ) );
		if ( count( $parts ) !== 2 || ! is_numeric( $parts[0] ) || ! is_numeric( $parts[1] ) ) {
			return null;
		}
		return array( (float) $parts[0] * $metres_per_unit, (float) $parts[1] * $metres_per_unit );
	}

	/** Mirrors _catalog_size_offer() — fuzzy size match within a 2.5cm
	 * tolerance, including width/height-swapped (rotated) matches.
	 * $sizes is an array of ['ft' => string|null, 'cm' => string|null,
	 * 'price' => float|null, 'lead_time_days' => int|null] — the product's
	 * variations, read by the WooCommerce adapter below. */
	public static function catalog_size_offer( array $sizes, float $size_w, float $size_h ): ?array {
		$tolerance_m = 0.025;
		foreach ( $sizes as $size ) {
			if ( ! isset( $size['price'] ) || $size['price'] === null ) {
				continue;
			}
			$dimensions = self::parse_catalog_dimensions( $size['ft'] ?? null, 0.3048 );
			if ( $dimensions === null ) {
				$dimensions = self::parse_catalog_dimensions( $size['cm'] ?? null, 0.01 );
			}
			if ( $dimensions === null ) {
				continue;
			}
			list( $width, $height ) = $dimensions;
			$direct  = abs( $width - $size_w ) <= $tolerance_m && abs( $height - $size_h ) <= $tolerance_m;
			$rotated = abs( $width - $size_h ) <= $tolerance_m && abs( $height - $size_w ) <= $tolerance_m;
			if ( $direct || $rotated ) {
				return $size;
			}
		}
		return null;
	}

	/**
	 * Mirrors QuoteEngine.calculate_quote(). $rug is the adapter array
	 * shape from get_rug_for_pricing(); $material from get_material().
	 * Returns ['error' => string] on failure, same as the Python version,
	 * so callers can check `isset($result['error'])` the same way.
	 */
	public function calculate_quote(
		array $rug,
		float $size_w,
		float $size_h,
		int $material_id,
		int $qty = 1,
		bool $rush_order = false,
		?float $margin_override = null,
		?float $gst_override = null,
		?float $manual_discount_pct = null,
		string $shape = 'rect'
	): array {
		$material = self::get_material( $material_id );
		if ( ! $material ) {
			return array( 'error' => "Material ID {$material_id} not found" );
		}

		$settings              = DRC_Settings::all();
		$tenant_rush_pct       = (float) $settings['rush_surcharge_pct'];
		$large_format_threshold = (float) $settings['large_format_threshold_sqm'];
		$large_format_pct      = (float) $settings['large_format_surcharge_pct'];

		// Base calculations — size_sqm is actual rug area (shape-aware).
		if ( $shape === 'circle' ) {
			$size_sqm = round( M_PI * ( $size_w / 2 ) ** 2, 4 );
		} elseif ( $shape === 'oval' ) {
			$size_sqm = round( M_PI * ( $size_w / 2 ) * ( $size_h / 2 ), 4 );
		} else {
			$size_sqm = round( $size_w * $size_h, 4 );
		}
		$total_sqm = round( $size_sqm * $qty, 4 );
		// Material requirement uses bounding box (fabric is always cut from rectangles).
		$bounding_sqm = round( $size_w * $size_h * $qty, 4 );
		$waste_factor = 1.10; // 10% weaving/trim waste on bounding box
		$required_sqm = round( $bounding_sqm * $waste_factor, 4 );

		$mat_cost_base = self::to_base( (float) $material['cost_per_sqm'], $material['cost_currency'] ?: $settings['currency'] );

		// Catalog price is the authoritative selling rate for catalog rugs.
		// Material cost/margin remain available for stock/cost reporting only.
		$size_offer = self::catalog_size_offer( $rug['sizes'], $size_w, $size_h );
		if ( $size_offer === null ) {
			return array( 'error' => 'This rug does not have a total price configured for the requested size' );
		}
		$size_price               = (float) $size_offer['price'];
		$catalog_price_per_piece  = round( self::to_base( $size_price, $rug['base_price_currency'] ?: $settings['currency'] ), 4 );

		$estimated_material_cost_per_piece = $mat_cost_base * ( $bounding_sqm / $qty ) * $waste_factor;
		$margin_pct = $estimated_material_cost_per_piece > 0
			? round( ( ( $catalog_price_per_piece / $estimated_material_cost_per_piece ) - 1 ) * 100, 2 )
			: 0.0;
		$base_price_per_sqm = $size_sqm > 0 ? round( $catalog_price_per_piece / $size_sqm, 2 ) : 0.0;
		$subtotal = round( $catalog_price_per_piece * $qty, 2 );

		// Material availability.
		$material_available = $material['is_available'] && $material['stock_meters'] >= $required_sqm;
		if ( ! $material['is_available'] ) {
			$material_message = "{$material['name']} is currently unavailable.";
		} elseif ( $material['stock_meters'] < $required_sqm ) {
			$material_message = sprintf(
				'Insufficient stock: need %.1f sqm (incl. 10%% waste), only %.1f sqm available.',
				$required_sqm,
				$material['stock_meters']
			);
		} else {
			$material_message = sprintf(
				'Stock sufficient: %.1f sqm available, need %.1f sqm.',
				$material['stock_meters'],
				$required_sqm
			);
		}

		// MOQ check. weave_type may be null (no vendor value set) — the
		// original Python left this to SQLAlchemy's `WHERE rug_type = NULL`,
		// which simply never matches; PHP's strict `string` type hint would
		// throw on null instead, so coalesce to '' first (get_moq_rule's own
		// query then correctly finds nothing, same net behavior).
		$moq_rule = self::get_moq_rule( $rug['weave_type'] ?? '' ) ?? self::get_moq_rule( 'catalog' );
		$moq_met     = true;
		$moq_message = 'MOQ requirements met.';
		if ( $moq_rule ) {
			if ( ! empty( $moq_rule['minimum_sqm'] ) && $total_sqm < $moq_rule['minimum_sqm'] ) {
				$moq_met     = false;
				$moq_message = sprintf(
					'MOQ not met: minimum %s sqm required, you ordered %.2f sqm.',
					$moq_rule['minimum_sqm'],
					$total_sqm
				);
			} elseif ( ! empty( $moq_rule['minimum_pieces'] ) && $qty < $moq_rule['minimum_pieces'] ) {
				$moq_met     = false;
				$moq_message = "MOQ not met: minimum {$moq_rule['minimum_pieces']} pieces required, you ordered {$qty}.";
			}
		}

		$bulk_discount         = 0.0;
		$manual_discount       = 0.0;
		$rush_surcharge        = 0.0;
		$size_surcharge        = 0.0;
		$pricing_rules_applied = array();

		// Determine if rush actually saves delivery time.
		$configured_days     = $size_offer['lead_time_days'] ?? $rug['lead_time_days'];
		// rush_order=false here, same as the Python source's sole call site —
		// this is the *standard* (un-rushed) estimate; the rushed figure is
		// $rush_candidate_days below. Passing false keeps estimate_days() from
		// applying its rush shrink to the standard timeline.
		$standard_days       = $configured_days ? (int) $configured_days : self::estimate_days( $rug['weave_type'] ?: 'standard', $size_sqm, $qty, false );
		$rush_candidate_days = max( (int) ceil( $standard_days * 0.7 ), 7 );
		$rush_saves_time     = $rush_candidate_days < $standard_days; // false when the 7-day floor kicks in
		$rush_effective      = $rush_order && $rush_saves_time;

		if ( $rush_effective ) {
			$rush_surcharge          = round( $subtotal * ( $tenant_rush_pct / 100 ), 2 );
			$pricing_rules_applied[] = array(
				'rule'        => sprintf( 'Rush surcharge (%.0f%%)', $tenant_rush_pct ),
				'type'        => 'rush_fee',
				'amount'      => $rush_surcharge,
				'description' => sprintf( 'Priority production: +%.0f%% on subtotal', $tenant_rush_pct ),
			);
		}

		foreach ( self::get_pricing_rules() as $rule ) {
			if ( $rule['rule_type'] === 'bulk_discount' ) {
				$min_q = $rule['min_qty'] ?? 0;
				$max_q = $rule['max_qty'] ?? INF;
				if ( $qty >= $min_q && $qty <= $max_q && ! empty( $rule['multiplier'] ) ) {
					$discount_amount         = round( $subtotal * ( 1 - $rule['multiplier'] ), 2 );
					$bulk_discount           = $discount_amount;
					$pricing_rules_applied[] = array(
						'rule'        => $rule['name'],
						'type'        => 'bulk_discount',
						'amount'      => -$discount_amount,
						'description' => $rule['description'],
					);
				}
			}
			// 'size_multiplier' rule_type: large-format surcharge is tenant-configured below, not rule-driven — same no-op as the Python version.
		}

		if ( $manual_discount_pct && $manual_discount_pct > 0 ) {
			$manual_discount         = round( $subtotal * ( $manual_discount_pct / 100 ), 2 );
			$pricing_rules_applied[] = array(
				'rule'        => sprintf( 'Manual discount (%.1f%%)', $manual_discount_pct ),
				'type'        => 'manual_discount',
				'amount'      => -$manual_discount,
				'description' => sprintf( 'Vendor applied %.1f%% discount', $manual_discount_pct ),
			);
		}

		if ( $large_format_pct > 0 && $size_sqm > $large_format_threshold ) {
			$surcharge               = round( $subtotal * ( $large_format_pct / 100 ), 2 );
			$size_surcharge          = $surcharge;
			$pricing_rules_applied[] = array(
				'rule'        => sprintf( 'Large format surcharge (%.0f%%)', $large_format_pct ),
				'type'        => 'size_multiplier',
				'amount'      => $surcharge,
				'description' => sprintf( 'Rugs exceeding %.0f sqm per piece: +%.0f%% on subtotal', $large_format_threshold, $large_format_pct ),
			);
		}

		$computed_price = round( $subtotal - $bulk_discount - $manual_discount + $rush_surcharge + $size_surcharge, 2 );

		// GST — settings['gst_inclusive'] is the single on/off switch. Off: no
		// GST at all. On: computed_price already includes GST — back it out
		// for the breakdown rather than adding it on top.
		$gst_inclusive = (bool) $settings['gst_inclusive'];
		if ( ! $gst_inclusive ) {
			$gst_pct        = 0.0;
			$pre_gst_price  = $computed_price;
			$gst_amount     = 0.0;
			$final_price    = $computed_price;
		} else {
			$gst_pct       = $gst_override ?? (float) $settings['default_gst_pct'];
			$final_price   = $computed_price;
			$pre_gst_price = $gst_pct ? round( $final_price / ( 1 + $gst_pct / 100 ), 2 ) : $final_price;
			$gst_amount    = round( $final_price - $pre_gst_price, 2 );
		}
		$price_per_piece = $qty > 0 ? round( $final_price / $qty, 2 ) : 0.0;

		// Production timeline — use rush days only when rush is effective.
		$estimated_days = $rush_effective ? $rush_candidate_days : $standard_days;

		$breakdown = array(
			array(
				'label'  => sprintf( 'Catalog price (%.2f × %d piece%s)', $catalog_price_per_piece, $qty, $qty !== 1 ? 's' : '' ),
				'amount' => $subtotal,
			),
		);
		$breakdown = array_merge( $breakdown, $pricing_rules_applied );
		if ( $gst_inclusive ) {
			$breakdown[] = array(
				'label'       => sprintf( 'GST (%.0f%%)', $gst_pct ),
				'amount'      => 0.0,
				'description' => sprintf( 'Included in the price above — GST portion is %.2f', $gst_amount ),
			);
		}

		return array(
			'shape'                  => $shape,
			'size_sqm'               => $size_sqm,
			'total_sqm'              => $total_sqm,
			'catalog_price_per_piece' => $catalog_price_per_piece,
			'base_price_per_sqm'     => $base_price_per_sqm,
			'material_cost_per_sqm'  => $mat_cost_base,
			'profit_margin_pct'      => $margin_pct,
			'subtotal'               => $subtotal,
			'bulk_discount'          => $bulk_discount,
			'manual_discount'        => $manual_discount,
			'rush_surcharge'         => $rush_surcharge,
			'size_surcharge'         => $size_surcharge,
			'pre_gst_price'          => $pre_gst_price,
			'gst_pct'                => $gst_pct,
			'gst_amount'             => $gst_amount,
			'gst_inclusive'          => $gst_inclusive,
			'final_price'            => $final_price,
			'price_per_piece'        => $price_per_piece,
			'price_currency'         => $settings['currency'],
			'moq_met'                => $moq_met,
			'moq_message'            => $moq_message,
			'material_available'     => $material_available,
			'material_message'       => $material_message,
			'estimated_days'         => $estimated_days,
			'standard_days'          => $standard_days,
			'rush_days'              => $rush_candidate_days,
			'rush_available'         => $rush_saves_time,
			'breakdown'              => $breakdown,
		);
	}

	/**
	 * Mirrors QuoteEngine.calculate_custom_quote() — genuine cost-plus-margin
	 * pricing for a bespoke (no catalog match) request. $quote is the
	 * wp_drc_quotes row as an associative array.
	 */
	public function calculate_custom_quote(
		array $quote,
		int $material_id,
		?float $margin_override = null,
		?float $size_w = null,
		?float $size_h = null,
		?float $discount_pct = null,
		?string $shape = null,
		?float $shipping_cost = null
	): array {
		$material = self::get_material( $material_id );
		if ( ! $material ) {
			return array( 'error' => "Material ID {$material_id} not found" );
		}

		$size_w = $size_w ?? $quote['custom_size_w'];
		$size_h = $size_h ?? $quote['custom_size_h'];
		if ( ! $size_w || ! $size_h ) {
			return array( 'error' => 'Quote is missing size information' );
		}
		$qty           = $quote['qty'] ?: 1;
		$shape         = $shape ?: ( $quote['rug_shape'] ?: 'rect' );
		$discount_pct  = $discount_pct ?? $quote['manual_discount_pct'];
		$shipping_cost = $shipping_cost ?? ( $quote['shipping_cost'] ?: 0.0 );

		$settings        = DRC_Settings::all();
		$default_margin  = (float) $settings['default_profit_margin_pct'];
		$tenant_rush_pct = (float) $settings['rush_surcharge_pct'];
		$margin_pct      = $margin_override ?? $default_margin;

		if ( $shape === 'circle' ) {
			$size_sqm = round( M_PI * ( $size_w / 2 ) ** 2, 4 );
		} elseif ( $shape === 'oval' ) {
			$size_sqm = round( M_PI * ( $size_w / 2 ) * ( $size_h / 2 ), 4 );
		} else {
			$size_sqm = round( $size_w * $size_h, 4 );
		}
		$total_sqm    = round( $size_sqm * $qty, 4 );
		$bounding_sqm = round( $size_w * $size_h * $qty, 4 );
		$required_sqm = round( $bounding_sqm * 1.10, 4 );

		$mat_cost_base          = self::to_base( (float) $material['cost_per_sqm'], $material['cost_currency'] ?: $settings['currency'] );
		$effective_price_per_sqm = round( $mat_cost_base * ( 1 + $margin_pct / 100 ), 4 );
		$base_price_per_sqm     = round( $effective_price_per_sqm, 2 );
		$subtotal               = round( $effective_price_per_sqm * $total_sqm, 2 );

		$material_available = $material['is_available'] && $material['stock_meters'] >= $required_sqm;
		if ( ! $material['is_available'] ) {
			$material_message = "{$material['name']} is currently unavailable.";
		} elseif ( $material['stock_meters'] < $required_sqm ) {
			$material_message = sprintf( 'Insufficient stock: need %.1f sqm (incl. 10%% waste), only %.1f sqm available.', $required_sqm, $material['stock_meters'] );
		} else {
			$material_message = sprintf( 'Stock sufficient: %.1f sqm available, need %.1f sqm.', $material['stock_meters'], $required_sqm );
		}

		$pricing_rules_applied = array();
		$rush_surcharge        = 0.0;
		if ( ! empty( $quote['rush_order'] ) ) {
			$rush_surcharge          = round( $subtotal * ( $tenant_rush_pct / 100 ), 2 );
			$pricing_rules_applied[] = array(
				'rule'        => sprintf( 'Rush surcharge (%.0f%%)', $tenant_rush_pct ),
				'type'        => 'rush_fee',
				'amount'      => $rush_surcharge,
				'description' => sprintf( 'Priority production: +%.0f%% on subtotal', $tenant_rush_pct ),
			);
		}

		$manual_discount = 0.0;
		if ( $discount_pct ) {
			$manual_discount         = round( $subtotal * ( $discount_pct / 100 ), 2 );
			$pricing_rules_applied[] = array(
				'rule'        => sprintf( 'Manual discount (%.1f%%)', $discount_pct ),
				'type'        => 'manual_discount',
				'amount'      => -$manual_discount,
				'description' => sprintf( 'Vendor applied %.1f%% discount', $discount_pct ),
			);
		}

		$computed_price = round( $subtotal - $manual_discount + $rush_surcharge, 2 );

		$gst_inclusive = (bool) $settings['gst_inclusive'];
		if ( ! $gst_inclusive ) {
			$gst_pct       = 0.0;
			$pre_gst_price = $computed_price;
			$gst_amount    = 0.0;
			$final_price   = $computed_price;
		} else {
			$gst_pct       = (float) $settings['default_gst_pct'];
			$final_price   = $computed_price;
			$pre_gst_price = $gst_pct ? round( $final_price / ( 1 + $gst_pct / 100 ), 2 ) : $final_price;
			$gst_amount    = round( $final_price - $pre_gst_price, 2 );
		}

		// Shipping — flat pass-through, added AFTER GST, never marked up or
		// taxed. Order of operations matters here — do not fold this into
		// the margin-priced subtotal above.
		$final_price = round( $final_price + $shipping_cost, 2 );

		$breakdown = array(
			array(
				'label'  => sprintf(
					'Selling rate (%.2f/sqm × %.2f sqm %s area) [%.0f%% margin on %.2f/sqm material]',
					$base_price_per_sqm,
					$total_sqm,
					$shape,
					$margin_pct,
					(float) $material['cost_per_sqm']
				),
				'amount' => $subtotal,
			),
		);
		$breakdown = array_merge( $breakdown, $pricing_rules_applied );
		if ( $gst_inclusive ) {
			$breakdown[] = array(
				'label'       => sprintf( 'GST (%.0f%%)', $gst_pct ),
				'amount'      => 0.0,
				'description' => sprintf( 'Included in the price above — GST portion is %.2f', $gst_amount ),
			);
		}
		if ( $shipping_cost ) {
			$breakdown[] = array(
				'rule'        => 'Shipping',
				'type'        => 'shipping',
				'amount'      => $shipping_cost,
				'description' => 'Flat shipping charge added to the quoted price',
			);
		}

		return array(
			'shape'                 => $shape,
			'size_sqm'              => $size_sqm,
			'total_sqm'             => $total_sqm,
			'base_price_per_sqm'    => $base_price_per_sqm,
			'material_cost_per_sqm' => $mat_cost_base,
			'profit_margin_pct'     => $margin_pct,
			'subtotal'              => $subtotal,
			'bulk_discount'         => 0.0,
			'manual_discount'       => $manual_discount,
			'rush_surcharge'        => $rush_surcharge,
			'size_surcharge'        => 0.0,
			'shipping_cost'         => $shipping_cost,
			'pre_gst_price'         => $pre_gst_price,
			'gst_pct'               => $gst_pct,
			'gst_amount'            => $gst_amount,
			'gst_inclusive'         => $gst_inclusive,
			'final_price'           => $final_price,
			'price_per_piece'       => $qty > 0 ? round( $final_price / $qty, 2 ) : $final_price,
			'price_currency'        => $quote['price_currency'] ?: $settings['currency'],
			'moq_met'               => true,
			'moq_message'           => "Custom rug request — MOQ rules don't apply.",
			'material_available'    => $material_available,
			'material_message'      => $material_message,
			'estimated_days'        => $quote['expected_delivery_days'] ?: 21,
			'breakdown'             => $breakdown,
		);
	}

	/**
	 * Mirrors build_manual_price_result() — for quotes with no catalog
	 * rug/material (vendor typed a flat final_price directly). No cost
	 * basis exists, so this only backs GST out of the stored price.
	 */
	public static function build_manual_price_result( array $quote ): array {
		$settings      = DRC_Settings::all();
		$final_price   = $quote['final_price'] ?: 0.0;
		$gst_inclusive = (bool) $settings['gst_inclusive'];

		if ( ! $gst_inclusive ) {
			$gst_pct       = 0.0;
			$pre_gst_price = $final_price;
			$gst_amount    = 0.0;
		} else {
			$gst_pct       = $quote['gst_pct'] ?? (float) $settings['default_gst_pct'];
			$pre_gst_price = $gst_pct ? round( $final_price / ( 1 + $gst_pct / 100 ), 2 ) : $final_price;
			$gst_amount    = round( $final_price - $pre_gst_price, 2 );
		}

		$size_w   = $quote['custom_size_w'] ?: 0.0;
		$size_h   = $quote['custom_size_h'] ?: 0.0;
		$qty      = $quote['qty'] ?: 1;
		$size_sqm = ( $size_w && $size_h ) ? round( $size_w * $size_h, 4 ) : 0.0;
		$total_sqm = round( $size_sqm * $qty, 4 );
		$base_price_per_sqm = $total_sqm > 0 ? round( $pre_gst_price / $total_sqm, 2 ) : 0.0;

		$breakdown = array(
			array(
				'label'       => 'Custom Rug Request — Agreed Price',
				'amount'      => $pre_gst_price,
				'description' => 'Manually priced by the vendor — no catalog rug/material to itemize.',
			),
		);
		if ( $gst_inclusive ) {
			$breakdown[] = array(
				'label'       => sprintf( 'GST (%.0f%%)', $gst_pct ),
				'amount'      => 0.0,
				'description' => sprintf( 'Included in the price above — GST portion is %.2f', $gst_amount ),
			);
		}

		return array(
			'shape'                 => $quote['rug_shape'] ?: 'rect',
			'size_sqm'              => $size_sqm,
			'total_sqm'             => $total_sqm,
			'base_price_per_sqm'    => $base_price_per_sqm,
			'material_cost_per_sqm' => 0.0,
			'profit_margin_pct'     => 0.0,
			'subtotal'              => $pre_gst_price,
			'bulk_discount'         => 0.0,
			'manual_discount'       => 0.0,
			'rush_surcharge'        => 0.0,
			'size_surcharge'        => 0.0,
			'pre_gst_price'         => $pre_gst_price,
			'gst_pct'               => $gst_pct,
			'gst_amount'            => $gst_amount,
			'gst_inclusive'         => $gst_inclusive,
			'final_price'           => $final_price,
			'price_per_piece'       => $qty > 0 ? round( $final_price / $qty, 2 ) : $final_price,
			'price_currency'        => $quote['price_currency'] ?: $settings['currency'],
			'moq_met'               => true,
			'moq_message'           => "Custom rug request — MOQ rules don't apply.",
			'material_available'    => true,
			'material_message'      => 'Custom rug request — no catalog material to check stock for.',
			'estimated_days'        => $quote['expected_delivery_days'] ?: 21,
			'breakdown'             => $breakdown,
		);
	}

	/** Mirrors _to_base(). Multi-currency conversion is likely being
	 * dropped for this single-tenant, India-domestic storefront (see the
	 * migration plan) — kept as a passthrough stub (no conversion applied)
	 * rather than removed outright, so callers don't need to change if the
	 * decision goes the other way later. */
	private static function to_base( float $amount, string $from_currency ): float {
		$settings = DRC_Settings::all();
		if ( ! $from_currency || $from_currency === $settings['currency'] ) {
			return $amount;
		}
		// No stored exchange-rate table in the single-tenant settings model —
		// same "unknown rate, use raw value" fallback as the original.
		return $amount;
	}

	/** Mirrors _estimate_days(). The $rush_order guard around the rush-timeline
	 * shrink must stay: the Python source only multiplies by the rush
	 * multiplier `if rush_order:`, and its one caller passes rush_order=False,
	 * so the standard estimate is base_days + complexity, never shrunk. An
	 * earlier port dropped this guard and applied the 0.7 shrink
	 * unconditionally, under-quoting the standard timeline by ~30% for any
	 * rug/size with no explicit lead time. */
	private static function estimate_days( string $order_type, float $size_sqm, int $qty, bool $rush_order = false ): int {
		$timeline = self::get_production_timeline( $order_type ) ?? self::get_production_timeline( 'standard' );
		if ( ! $timeline ) {
			return 21;
		}

		$base             = (int) $timeline['base_days'];
		$complexity_extra = (int) ceil( $timeline['complexity_multiplier_per_sqm'] * $size_sqm * $qty );
		$total            = $base + $complexity_extra;

		if ( $rush_order ) {
			$rush_timeline = self::get_production_timeline( 'rush' );
			if ( $rush_timeline ) {
				$multiplier = $rush_timeline['complexity_multiplier_per_sqm'] ?: 0.7;
				$total      = (int) ceil( $total * $multiplier );
			} else {
				$total = (int) ceil( $total * 0.7 );
			}
		}

		return max( $total, 7 ); // minimum 7 days
	}

	/** Mirrors check_material_stock(). */
	public static function check_material_stock( int $material_id, float $required_sqm ): array {
		$material = self::get_material( $material_id );
		if ( ! $material ) {
			return array(
				'available' => false,
				'message'   => "Material ID {$material_id} not found",
			);
		}

		$buffer_sqm = round( $required_sqm * 1.10, 2 );
		$available  = $material['is_available'] && $material['stock_meters'] >= $buffer_sqm;

		return array(
			'material_id'        => $material['id'],
			'material_name'      => $material['name'],
			'stock_meters'       => $material['stock_meters'],
			'required_sqm'       => $required_sqm,
			'required_with_waste' => $buffer_sqm,
			'available'          => $available,
			'message'            => $available
				? sprintf( 'Sufficient stock available (%.1f sqm on hand).', $material['stock_meters'] )
				: sprintf( 'Insufficient stock: %.1f sqm available, %.1f sqm needed.', $material['stock_meters'], $buffer_sqm ),
		);
	}

	// ── $wpdb adapters ──────────────────────────────────────────────────

	public static function get_material( int $material_id ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_materials';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $material_id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['is_available'] = (bool) $row['is_available'];
		$row['stock_meters'] = (float) $row['stock_meters'];
		$row['cost_per_sqm'] = (float) $row['cost_per_sqm'];
		return $row;
	}

	private static function get_moq_rule( string $rug_type ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_moq_rules';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE rug_type = %s LIMIT 1", $rug_type ), ARRAY_A );
		return $row ?: null;
	}

	private static function get_production_timeline( string $order_type ): ?array {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_production_timelines';
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_type = %s LIMIT 1", $order_type ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['base_days'] = (int) $row['base_days'];
		$row['complexity_multiplier_per_sqm'] = (float) $row['complexity_multiplier_per_sqm'];
		return $row;
	}

	private static function get_pricing_rules(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_pricing_rules';
		return $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A ) ?: array();
	}

	/**
	 * Reads a WooCommerce variable product's variations into the
	 * ['ft'=>..,'cm'=>..,'price'=>..,'lead_time_days'=>..] shape
	 * catalog_size_offer() expects — the WooCommerce-facing equivalent of
	 * reading RugCatalog.sizes JSON in the original app. Variation meta
	 * keys (_drc_size_ft, _drc_size_cm, _drc_lead_time_days) are registered
	 * by the catalog admin UI (Phase 3 of the migration plan), not yet
	 * implemented here.
	 */
	public static function get_rug_for_pricing( int $product_id ): ?array {
		$product = wc_get_product( $product_id );
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return null;
		}
		$sizes = array();
		foreach ( $product->get_children() as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation ) {
				continue;
			}
			$sizes[] = array(
				'ft'             => $variation->get_meta( '_drc_size_ft' ) ?: null,
				'cm'             => $variation->get_meta( '_drc_size_cm' ) ?: null,
				'price'          => $variation->get_price() !== '' ? (float) $variation->get_price() : null,
				'lead_time_days' => $variation->get_meta( '_drc_lead_time_days' ) ?: null,
			);
		}
		return array(
			'id'                  => $product_id,
			'sizes'               => $sizes,
			'base_price_currency' => get_woocommerce_currency(),
			'weave_type'          => $product->get_meta( '_drc_weave_type' ) ?: null,
			'lead_time_days'      => $product->get_meta( '_drc_lead_time_days' ) ?: null,
		);
	}
}
