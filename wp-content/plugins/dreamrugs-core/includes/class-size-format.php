<?php
/**
 * Direct PHP port of backend/app/services/size_format.py (itself a Python
 * port of frontend/src/utils/size.ts's fmtDim/fmtDims). This becomes the
 * SINGLE source of truth for size display in the new stack — the original
 * app had this logic duplicated in Python and TypeScript, which the
 * migration plan calls out as worth not repeating.
 *
 * Rounding is intentionally asymmetric and must stay that way: cm rounds
 * to the nearest whole integer, ft formats to exactly one decimal place.
 * Catalog-preset cm values are NEVER computed from ft — only genuinely
 * custom/bespoke sizes (no catalog-preset match) get a computed ft<->cm
 * conversion. Getting this backwards shows numbers that don't match what
 * the vendor intentionally set on the catalog page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Size_Format {

	const FT_PER_M = 1 / 0.3048;

	public static function fmt_dim( float $value_m, string $unit ): string {
		if ( $unit === 'cm' ) {
			return (string) round( $value_m * 100 );
		}
		return number_format( $value_m * self::FT_PER_M, 1, '.', '' );
	}

	public static function fmt_dims( float $w_m, float $h_m, string $unit, string $shape = 'rect' ): string {
		if ( $unit === 'both' ) {
			return self::fmt_dims( $w_m, $h_m, 'ft', $shape ) . ' (' . self::fmt_dims( $w_m, $h_m, 'cm', $shape ) . ')';
		}
		$w = self::fmt_dim( $w_m, $unit );
		if ( $shape === 'circle' ) {
			return "⌀ {$w} {$unit}";
		}
		$h = self::fmt_dim( $h_m, $unit );
		if ( $shape === 'oval' ) {
			return "{$w}x{$h} {$unit} (oval)";
		}
		return "{$w}x{$h} {$unit}";
	}

	private static function parse_ft_pair( string $ft ): ?array {
		$parts = explode( 'x', $ft );
		if ( count( $parts ) !== 2 || ! is_numeric( trim( $parts[0] ) ) || ! is_numeric( trim( $parts[1] ) ) ) {
			return null;
		}
		return array( (float) trim( $parts[0] ), (float) trim( $parts[1] ) );
	}

	/**
	 * Size display for outbound emails/invoices — always shows both units.
	 * $sizes is the product's variation list in the same
	 * [['ft'=>..,'cm'=>..], ...] shape used by DRC_Quote_Engine — pass null
	 * for a genuinely custom/bespoke quote with no catalog product.
	 */
	public static function email_dims_display( ?float $w_m, ?float $h_m, string $shape = 'rect', ?array $sizes = null ): string {
		if ( $w_m === null ) {
			return 'custom size';
		}

		if ( $sizes !== null && $shape === 'rect' && $h_m !== null ) {
			foreach ( $sizes as $size ) {
				$ft = $size['ft'] ?? null;
				if ( ! $ft ) {
					continue;
				}
				$pair = self::parse_ft_pair( $ft );
				if ( ! $pair ) {
					continue;
				}
				list( $ft_w, $ft_h ) = $pair;
				$ft_w_m = $ft_w * 0.3048;
				$ft_h_m = $ft_h * 0.3048;
				if ( abs( $ft_w_m - $w_m ) < 0.005 && abs( $ft_h_m - $h_m ) < 0.005 ) {
					$cm = $size['cm'] ?? null;
					return $cm ? "{$ft} ft ({$cm} cm)" : "{$ft} ft";
				}
			}
		}

		return self::fmt_dims( $w_m, $h_m ?? $w_m, 'both', $shape );
	}
}
