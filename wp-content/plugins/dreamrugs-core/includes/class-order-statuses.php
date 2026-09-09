<?php
/**
 * Registers the production-pipeline order statuses the original app tracked
 * on Order.status (pending → in_production → quality_check → shipped →
 * delivered, plus cancelled) as real WooCommerce order statuses, rather than
 * a parallel custom field. This was a genuine gap: orders created by
 * DRC_Order_Creator started at 'pending' and had no way to move forward —
 * dashboard "Orders In Production" always read 0 because nothing ever wrote
 * that state anywhere.
 *
 * Registering these as real order statuses (not custom postmeta) means the
 * native wp-admin Edit Order screen's status dropdown can set them directly,
 * wc_get_orders(['status' => 'in_production']) works with no custom query,
 * and — the detail this whole design leans on — WooCommerce's own
 * WC_Order::status_transition() already writes an automatic order note
 * ("Order status changed from X to Y.") on every transition. That native
 * note history is what DRC_Customer_Orders_Rest_Routes::timeline() reads
 * instead of maintaining a separate status-history table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Order_Statuses {

	const STATUSES = array(
		'wc-in_production' => 'In Production',
		'wc-quality_check'  => 'Quality Check',
		'wc-shipped'        => 'Shipped',
		'wc-delivered'      => 'Delivered',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_status_list' ) );
	}

	/** register_post_status() keeps these valid under WooCommerce's legacy
	 * post-based order storage; the wc_order_statuses filter below is what
	 * actually matters under HPOS (confirmed live on this install — see
	 * class-admin-rest-routes.php's dashboard_stats() comment) since that's
	 * what wc_get_order_statuses()/wc_is_order_status() read from. */
	public static function register_statuses() {
		foreach ( self::STATUSES as $slug => $label ) {
			register_post_status( $slug, array(
				'label'                     => $label,
				'public'                    => true,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'dreamrugs-core' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralSingle,WordPress.WP.I18n.NonSingularStringLiteralPlural
			) );
		}
	}

	public static function add_to_status_list( array $order_statuses ): array {
		$new = array();
		foreach ( $order_statuses as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'wc-processing' === $key ) {
				foreach ( self::STATUSES as $slug => $custom_label ) {
					$new[ $slug ] = $custom_label;
				}
			}
		}
		return $new;
	}

	/** Bare slug ('in_production'), not the wc- prefixed post-status form —
	 * matches the shape the customer-facing REST layer and JS work with,
	 * same as the original Order.status column's plain values. */
	public static function bare_slugs(): array {
		return array_map( fn( $s ) => substr( $s, 3 ), array_keys( self::STATUSES ) );
	}
}
