<?php
/**
 * Runs the ported PHP engine
 * (wp-content/plugins/dreamrugs-core/includes/class-quote-engine.php) over the
 * shared cases.json matrix and writes php_out.json next to it.
 *
 * Runs INSIDE the wordpress container (it boots WP for $wpdb + DRC_Settings +
 * the plugin classes). run.sh copies this file and cases.json into the
 * container's /tmp and invokes:  php /tmp/run_php.php /tmp/cases.json /tmp/php_out.json
 *
 * Seeds the wp_drc_* config tables and the drc_settings option from the
 * fixtures, then calls DRC_Quote_Engine directly. Catalog cases build the
 * $rug array inline in the exact shape get_rug_for_pricing() returns, so no
 * WooCommerce product needs to exist — the pricing math (the thing under
 * test) is identical either way.
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: php run_php.php <cases.json> <out.json>\n" );
	exit( 2 );
}
$cases_path = $argv[1];
$out_path   = $argv[2];

require '/var/www/html/wp-load.php';

if ( ! class_exists( 'DRC_Quote_Engine' ) ) {
	fwrite( STDERR, "DRC_Quote_Engine not loaded — is dreamrugs-core active?\n" );
	exit( 1 );
}

$cases = json_decode( file_get_contents( $cases_path ), true );
global $wpdb;

/* ---- seed config tables ------------------------------------------------ */
$wpdb->query( "TRUNCATE {$wpdb->prefix}drc_materials" );
$wpdb->query( "TRUNCATE {$wpdb->prefix}drc_pricing_rules" );
$wpdb->query( "TRUNCATE {$wpdb->prefix}drc_moq_rules" );
$wpdb->query( "TRUNCATE {$wpdb->prefix}drc_production_timelines" );

$material_id_map = array();
foreach ( $cases['materials'] as $m ) {
	$wpdb->insert( "{$wpdb->prefix}drc_materials", array(
		'name'          => $m['name'],
		'type'          => $m['type'],
		'color'         => $m['color'],
		'stock_meters'  => $m['stock_meters'],
		'cost_per_sqm'  => $m['cost_per_sqm'],
		'cost_currency' => $m['cost_currency'],
		'is_available'  => $m['is_available'] ? 1 : 0,
	) );
	$material_id_map[ $m['id'] ] = (int) $wpdb->insert_id;
}
foreach ( $cases['pricing_rules'] as $r ) {
	$wpdb->insert( "{$wpdb->prefix}drc_pricing_rules", array(
		'name'        => $r['name'],
		'rule_type'   => $r['rule_type'],
		'min_qty'     => $r['min_qty'],
		'max_qty'     => $r['max_qty'],
		'multiplier'  => $r['multiplier'],
		'description' => $r['description'],
	) );
}
foreach ( $cases['moq_rules'] as $r ) {
	$wpdb->insert( "{$wpdb->prefix}drc_moq_rules", array(
		'rug_type'       => $r['rug_type'],
		'minimum_sqm'    => $r['minimum_sqm'],
		'minimum_pieces' => $r['minimum_pieces'],
		'notes'          => $r['notes'],
	) );
}
foreach ( $cases['production_timelines'] as $t ) {
	$wpdb->insert( "{$wpdb->prefix}drc_production_timelines", array(
		'order_type'                    => $t['order_type'],
		'base_days'                     => $t['base_days'],
		'complexity_multiplier_per_sqm' => $t['complexity_multiplier_per_sqm'],
		'notes'                         => $t['notes'],
	) );
}

/* ---- helpers --------------------------------------------------------------*/
function apply_settings( array $s, bool $gst_inclusive ): void {
	DRC_Settings::update( array(
		'currency'                   => $s['currency'],
		'default_profit_margin_pct'  => $s['default_profit_margin_pct'],
		'rush_surcharge_pct'         => $s['rush_surcharge_pct'],
		'default_gst_pct'            => $s['default_gst_pct'],
		'gst_inclusive'              => $gst_inclusive,
		'large_format_threshold_sqm' => $s['large_format_threshold_sqm'],
		'large_format_surcharge_pct' => $s['large_format_surcharge_pct'],
	) );
}

function rug_by_id( array $cases, int $id ): array {
	foreach ( $cases['rugs'] as $r ) {
		if ( $r['id'] === $id ) {
			return array(
				'id'                  => $id,
				'sizes'               => $r['sizes'],
				'base_price_currency' => $r['base_price_currency'],
				'weave_type'          => $r['weave_type'],
				'lead_time_days'      => $r['lead_time_days'],
			);
		}
	}
	throw new RuntimeException( "no rug $id" );
}

function ft_to_m( string $label ): array {
	list( $w, $h ) = preg_split( '/x/i', $label );
	return array( (float) $w * 0.3048, (float) $h * 0.3048 );
}

$FIELDS = $cases['compare_fields'];
$out = array();

foreach ( $cases['gst_modes'] as $gst ) {
	apply_settings( $cases['settings'], (bool) $gst );
	$engine = new DRC_Quote_Engine();
	$suffix = $gst ? 'gst_on' : 'gst_off';

	foreach ( $cases['cases'] as $c ) {
		$key = "{$c['id']}::{$suffix}";
		if ( 'catalog' === $c['kind'] ) {
			$rug = rug_by_id( $cases, $c['rug_id'] );
			list( $w, $h ) = ft_to_m( $c['size'] );
			$res = $engine->calculate_quote(
				$rug, $w, $h, $material_id_map[ $c['material_id'] ],
				$c['qty'] ?? 1, $c['rush'] ?? false, null, null,
				$c['manual_discount_pct'] ?? null, $c['shape'] ?? 'rect'
			);
		} elseif ( 'custom' === $c['kind'] ) {
			$quote = array(
				'custom_size_w'          => $c['size_w'],
				'custom_size_h'          => $c['size_h'],
				'qty'                    => $c['qty'] ?? 1,
				'rug_shape'              => $c['shape'] ?? 'rect',
				'manual_discount_pct'    => $c['discount_pct'] ?? null,
				'shipping_cost'          => $c['shipping_cost'] ?? 0.0,
				'rush_order'             => $c['rush'] ?? false,
				'expected_delivery_days' => $c['expected_delivery_days'] ?? null,
				'price_currency'         => $cases['settings']['currency'],
			);
			$res = $engine->calculate_custom_quote( $quote, $material_id_map[ $c['material_id'] ] );
		} elseif ( 'manual' === $c['kind'] ) {
			$quote = array(
				'final_price'            => $c['final_price'],
				'gst_pct'               => $c['gst_pct'] ?? null,
				'custom_size_w'          => $c['size_w'] ?? null,
				'custom_size_h'          => $c['size_h'] ?? null,
				'qty'                    => $c['qty'] ?? 1,
				'rug_shape'              => $c['shape'] ?? 'rect',
				'price_currency'         => $cases['settings']['currency'],
				'expected_delivery_days' => $c['expected_delivery_days'] ?? null,
			);
			$res = DRC_Quote_Engine::build_manual_price_result( $quote );
		} else {
			continue;
		}

		$row = array();
		foreach ( $FIELDS as $f ) {
			if ( array_key_exists( $f, $res ) ) {
				$row[ $f ] = $res[ $f ];
			}
		}
		if ( isset( $res['error'] ) ) {
			$row['error'] = $res['error'];
		}
		$out[ $key ] = $row;
	}
}

file_put_contents( $out_path, json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
fwrite( STDERR, 'php_out.json written — ' . count( $out ) . " rows\n" );
