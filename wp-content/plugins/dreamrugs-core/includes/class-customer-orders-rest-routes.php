<?php
/**
 * Customer-facing order routes under /wp-json/dreamrugs/v1/orders... —
 * ported from backend/app/api/routes/customer.py's get_customer_orders /
 * get_customer_order_breakdown / get_customer_order_timeline /
 * get_customer_cancel_eligibility / cancel_customer_order. WooCommerce has
 * no native "My Orders" screen rich enough to show the quote-engine price
 * breakdown, a production-stage timeline, or a refund-preview cancel flow,
 * so this — like the quote lifecycle in class-rest-routes.php — is genuine
 * dreamrugs-core territory rather than something to lean on WC's REST API
 * for.
 *
 * List/breakdown/timeline accept either a logged-in session OR a plain
 * ?email= guest lookup — intentionally as weak as the original app's
 * (email-only) guest lookup, not tightened or loosened here. Cancellation
 * (money-moving) requires a real login, matching the original's stricter
 * `Depends(get_current_customer)` guard on those two routes specifically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Customer_Orders_Rest_Routes {

	const NS = 'dreamrugs/v1';

	// Refund % by order status at time of cancellation — production is
	// already committed once in_production, so only a partial refund
	// applies from there; anything past that is no longer cancellable
	// through this flow. Ports orders.py's CANCELLATION_REFUND_POLICY, but
	// keyed on WooCommerce's statuses: the original's "pending" meant paid
	// and awaiting production, which is WooCommerce's "processing". WC's
	// own "pending"/"on-hold" mean not yet paid — still cancellable, and
	// cancel() only refunds when a payment transaction exists.
	const CANCELLATION_REFUND_POLICY = array(
		'pending'       => 1.0,
		'on-hold'       => 1.0,
		'processing'    => 1.0,
		'in_production' => 0.5,
	);

	public static function register() {
		register_rest_route( self::NS, '/orders', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_orders' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/orders/(?P<id>\d+)/breakdown', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'breakdown' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/orders/(?P<id>\d+)/timeline', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'timeline' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/orders/(?P<id>\d+)/cancel-eligibility', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'cancel_eligibility' ),
			'permission_callback' => array( 'DRC_Rest_Routes', 'require_login' ),
		) );
		register_rest_route( self::NS, '/orders/(?P<id>\d+)/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'cancel' ),
			'permission_callback' => array( 'DRC_Rest_Routes', 'require_login' ),
		) );
		// GST invoice PDF for the customer — logged-in owner, or a guest
		// holding the order key from their confirmation page/email.
		register_rest_route( self::NS, '/orders/(?P<id>\d+)/invoice', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'download_invoice' ),
			'permission_callback' => '__return_true',
		) );

		$admin = array( 'DRC_Rest_Routes', 'require_manage_woocommerce' );
		register_rest_route( self::NS, '/admin/orders/(?P<id>\d+)/cancel', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_cancel' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/admin/orders/(?P<id>\d+)/invoice/email', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_email_invoice' ),
			'permission_callback' => $admin,
		) );
		register_rest_route( self::NS, '/admin/orders/combine', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'admin_combine' ),
			'permission_callback' => $admin,
		) );
	}

	/** The email identifying "whose orders are these" for list/breakdown/
	 * timeline — the logged-in user's own email takes priority over a
	 * client-supplied one (so a session can't be tricked into showing
	 * someone else's orders by a query param), falling back to the
	 * ?email= guest-lookup param when logged out. */
	private static function resolve_identity_email( WP_REST_Request $request ): ?string {
		if ( is_user_logged_in() ) {
			$user = wp_get_current_user();
			return $user->user_email;
		}
		$email = $request->get_param( 'email' );
		return $email && is_email( $email ) ? sanitize_email( $email ) : null;
	}

	private static function bare_status( WC_Order $order ): string {
		return str_replace( 'wc-', '', $order->get_status() );
	}

	/** Reverse-lookup: wp_drc_quotes row that this order was created from
	 * (DRC_Order_Creator stamps wc_order_id on accept), if any — a
	 * WooCommerce order created via direct cart checkout on a catalog
	 * variation (bypassing the quote flow entirely) has no matching row,
	 * and callers fall back to the order's own line items in that case. */
	private static function linked_quote( int $order_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_quotes WHERE wc_order_id = %d", $order_id ), ARRAY_A );
		return $row ?: null;
	}

	private static function format_order( WC_Order $order ): array {
		$quote = self::linked_quote( $order->get_id() );
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'quote_id'       => $quote['id'] ?? null,
				'rug_name'       => $item->get_name(),
				'qty'            => (int) $item->get_quantity(),
				'final_price'    => (float) $item->get_total(),
				'price_currency' => $order->get_currency(),
			);
		}
		$rug_name = $items ? $items[0]['rug_name'] : 'Custom Order';

		$size_w = $size_h = $qty = $shape = $base_price = $price_per_piece = null;
		$material_name = $weave_type = null;
		$rush_order = false;
		$manual_discount_pct = $gst_pct = $pre_gst = $gst_amount = null;
		$gst_inclusive = (bool) DRC_Settings::get( 'gst_inclusive' );

		if ( $quote ) {
			$size_w  = null !== $quote['custom_size_w'] ? (float) $quote['custom_size_w'] : null;
			$size_h  = null !== $quote['custom_size_h'] ? (float) $quote['custom_size_h'] : null;
			$qty     = (int) $quote['qty'];
			$shape   = $quote['rug_shape'] ?: 'rect';
			$base_price = null !== $quote['base_price'] ? (float) $quote['base_price'] : null;
			$rush_order = (bool) $quote['rush_order'];
			$manual_discount_pct = null !== $quote['manual_discount_pct'] ? (float) $quote['manual_discount_pct'] : null;
			$fp = null !== $quote['final_price'] ? (float) $quote['final_price'] : null;
			$gst_pct = null !== $quote['gst_pct'] ? (float) $quote['gst_pct'] : null;
			if ( $fp && $gst_pct ) {
				$pre_gst    = round( $fp / ( 1 + $gst_pct / 100 ), 2 );
				$gst_amount = round( $fp - $pre_gst, 2 );
			}
			$price_per_piece = ( $fp && $qty ) ? round( $fp / $qty, 2 ) : null;
			if ( $quote['material_id'] ) {
				$material = DRC_Quote_Engine::get_material( (int) $quote['material_id'] );
				$material_name = $material['name'] ?? null;
			}
			if ( $quote['product_id'] ) {
				$product = wc_get_product( $quote['product_id'] );
				if ( $product ) {
					$weave_type = $product->get_meta( '_drc_weave_type' ) ?: null;
				}
			}
		}

		if ( $size_w && $size_h ) {
			$size_display = 'circle' === $shape
				? sprintf( '⌀ %sm', rtrim( rtrim( number_format( $size_w, 2 ), '0' ), '.' ) )
				: sprintf( '%sm × %sm%s', rtrim( rtrim( number_format( $size_w, 2 ), '0' ), '.' ), rtrim( rtrim( number_format( $size_h, 2 ), '0' ), '.' ), 'oval' === $shape ? ' (oval)' : '' );
		} else {
			$size_display = '—';
		}

		return array(
			'order_id'             => $order->get_id(),
			'quote_id'             => $quote['id'] ?? null,
			'status'               => self::bare_status( $order ),
			'rug_name'             => $rug_name,
			'material_name'        => $material_name,
			'weave_type'           => $weave_type,
			'rug_shape'            => $shape ?: 'rect',
			'size'                 => $size_display,
			'size_w'               => $size_w,
			'size_h'               => $size_h,
			'qty'                  => $qty ?? array_sum( array_column( $items, 'qty' ) ) ?: 1,
			'base_price'           => $base_price,
			'price_per_piece'      => $price_per_piece,
			'final_price'          => $quote['final_price'] ?? null,
			'pre_gst_price'        => $pre_gst,
			'gst_pct'              => $gst_pct,
			'gst_amount'           => $gst_amount,
			'gst_inclusive'        => $gst_inclusive,
			'price_currency'       => $order->get_currency(),
			'customer_country'     => $order->get_billing_country() ?: null,
			'rush_order'           => $rush_order,
			'manual_discount_pct'  => $manual_discount_pct,
			'shipping_address'     => trim( $order->get_formatted_shipping_address() ?: '' ) ?: null,
			'estimated_delivery'   => $order->get_meta( '_drc_estimated_delivery' ) ?: null,
			'created_at'           => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : null,
			'item_count'           => max( 1, count( $items ) ),
			'items'                => $items,
			'order_total'          => (float) $order->get_total(),
			// GST invoice PDF (tax once paid, proforma before) — the order key
			// authorises it, so the link works for guests and account holders.
			'invoice_url'          => $quote ? add_query_arg( 'key', $order->get_order_key(), rest_url( self::NS . '/orders/' . $order->get_id() . '/invoice' ) ) : null,
		);
	}

	public static function list_orders( WP_REST_Request $request ) {
		$identity = self::resolve_identity_email( $request );
		if ( ! $identity ) {
			return new WP_REST_Response( array( 'total' => 0, 'page' => 1, 'page_size' => 10, 'pages' => 0, 'items' => array() ), 200 );
		}

		$page      = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$page_size = min( 100, max( 1, (int) ( $request->get_param( 'page_size' ) ?: 10 ) ) );
		$status    = $request->get_param( 'status' );
		$sort_by   = $request->get_param( 'sort_by' ) ?: 'date_desc';
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );

		$args = array(
			'customer' => $identity,
			'limit'    => $page_size,
			'page'     => $page,
			'paginate' => true,
		);
		if ( $status && 'all' !== $status ) {
			$args['status'] = 'wc-' . str_replace( 'wc-', '', $status );
		}
		if ( $date_from || $date_to ) {
			$args['date_created'] = ( $date_from ?: '' ) . '...' . ( $date_to ?: '' );
		}
		switch ( $sort_by ) {
			case 'price_asc':
				$args['orderby'] = 'total';
				$args['order']   = 'ASC';
				break;
			case 'price_desc':
				$args['orderby'] = 'total';
				$args['order']   = 'DESC';
				break;
			case 'date_asc':
				$args['orderby'] = 'date';
				$args['order']   = 'ASC';
				break;
			default:
				$args['orderby'] = 'date';
				$args['order']   = 'DESC';
		}

		$result = wc_get_orders( $args );
		$items  = array_map( array( __CLASS__, 'format_order' ), $result->orders );

		return new WP_REST_Response( array(
			'total'     => (int) $result->total,
			'page'      => $page,
			'page_size' => $page_size,
			'pages'     => (int) $result->max_num_pages,
			'items'     => $items,
		), 200 );
	}

	/** Resolves and ownership-checks an order for the loose (email-or-login)
	 * identity model used by list/breakdown/timeline. Returns null on any
	 * mismatch — callers 404 rather than 403, so a wrong id/email combo
	 * doesn't confirm the order's existence to someone who doesn't own it. */
	private static function find_owned_order_loose( int $order_id, WP_REST_Request $request ): ?WC_Order {
		$identity = self::resolve_identity_email( $request );
		if ( ! $identity ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		if ( strtolower( $order->get_billing_email() ) !== strtolower( $identity ) ) {
			return null;
		}
		return $order;
	}

	/** Same idea, but for the strict (must-be-logged-in) identity model used
	 * by cancel-eligibility/cancel — matches by WP user id first (covers a
	 * registered customer whose billing email differs from their account
	 * email), falling back to billing-email match. */
	private static function find_owned_order_strict( int $order_id ): ?WC_Order {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return null;
		}
		$user = wp_get_current_user();
		if ( (int) $order->get_customer_id() === (int) $user->ID ) {
			return $order;
		}
		if ( $user->user_email && strtolower( $order->get_billing_email() ) === strtolower( $user->user_email ) ) {
			return $order;
		}
		return null;
	}

	/** Strips vendor-only fields (margin/cost/MOQ) from a quote-engine
	 * result before it reaches the customer — mirrors
	 * customer.py's _customer_safe_breakdown() exactly, including
	 * relabeling the first breakdown line to a generic "Rug subtotal". */
	private static function customer_safe_breakdown( array $result ): array {
		foreach ( array( 'size_sqm', 'total_sqm', 'base_price_per_sqm', 'material_cost_per_sqm', 'profit_margin_pct', 'moq_met', 'moq_message' ) as $k ) {
			unset( $result[ $k ] );
		}
		if ( ! empty( $result['breakdown'][0]['label'] ) ) {
			$result['breakdown'][0]['label'] = 'Rug subtotal';
		}
		return $result;
	}

	/** Same same-state/inter-state GST split logic as the invoice generator
	 * and customer.py's _gst_split(): identical state codes on both sides
	 * -> CGST+SGST split evenly, otherwise IGST at the full rate. */
	private static function gst_split( ?string $tenant_state, ?string $customer_state, ?float $gst_pct, ?string $country = null ): array {
		if ( ! $gst_pct || ! DRC_Quote_Context::is_india( $country ) ) {
			return array( 'type' => null );
		}
		// Settings hold the 2-digit GST code ("09"); WooCommerce billing
		// state is its own code ("UP") — compare them in one form.
		$tenant_state   = DRC_Quote_Context::gst_state_code( $tenant_state );
		$customer_state = DRC_Quote_Context::gst_state_code( $customer_state );
		if ( $tenant_state && $customer_state && $tenant_state === $customer_state ) {
			$half = round( $gst_pct / 2, 2 );
			return array( 'type' => 'cgst_sgst', 'cgst_pct' => $half, 'sgst_pct' => $half );
		}
		return array( 'type' => 'igst', 'igst_pct' => $gst_pct );
	}

	public static function breakdown( WP_REST_Request $request ) {
		$order = self::find_owned_order_loose( (int) $request->get_param( 'id' ), $request );
		if ( ! $order ) {
			return new WP_Error( 'drc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$quote = self::linked_quote( $order->get_id() );
		if ( ! $quote ) {
			return new WP_Error( 'drc_no_quote', 'Order has no associated quote.', array( 'status' => 422 ) );
		}

		$engine = new DRC_Quote_Engine();
		if ( $quote['product_id'] && $quote['material_id'] ) {
			if ( ! $quote['custom_size_w'] || ! $quote['custom_size_h'] ) {
				return new WP_Error( 'drc_missing_fields', 'Order is missing required fields for calculation.', array( 'status' => 422 ) );
			}
			$rug = DRC_Quote_Engine::get_rug_for_pricing( (int) $quote['product_id'] );
			if ( ! $rug ) {
				return new WP_Error( 'drc_not_found', 'Rug product not found.', array( 'status' => 422 ) );
			}
			$result = $engine->calculate_quote(
				$rug,
				(float) $quote['custom_size_w'],
				(float) $quote['custom_size_h'],
				(int) $quote['material_id'],
				(int) $quote['qty'] ?: 1,
				(bool) $quote['rush_order'],
				null !== $quote['margin_pct'] ? (float) $quote['margin_pct'] : null,
				null !== $quote['gst_pct'] ? (float) $quote['gst_pct'] : null,
				null !== $quote['manual_discount_pct'] ? (float) $quote['manual_discount_pct'] : null,
				$quote['rug_shape'] ?: 'rect'
			);
		} elseif ( $quote['material_id'] ) {
			$result = $engine->calculate_custom_quote( $quote, (int) $quote['material_id'], null !== $quote['margin_pct'] ? (float) $quote['margin_pct'] : null );
		} else {
			$result = DRC_Quote_Engine::build_manual_price_result( $quote );
		}
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'drc_calc_failed', $result['error'], array( 'status' => 422 ) );
		}

		$material_name = null;
		if ( $quote['material_id'] ) {
			$material = DRC_Quote_Engine::get_material( (int) $quote['material_id'] );
			$material_name = $material['name'] ?? null;
		}
		$rug_name = 'Custom Order';
		$weave_type = null;
		if ( $quote['product_id'] ) {
			$product = wc_get_product( $quote['product_id'] );
			if ( $product ) {
				$rug_name   = $product->get_name();
				$weave_type = $product->get_meta( '_drc_weave_type' ) ?: null;
			}
		}

		$settings = DRC_Settings::all();
		return new WP_REST_Response( array_merge(
			self::customer_safe_breakdown( $result ),
			array(
				'stored_final_price' => null !== $quote['final_price'] ? (float) $quote['final_price'] : null,
				'price_currency'     => $quote['price_currency'] ?: ( $result['price_currency'] ?? $settings['currency'] ),
				'customer_country'   => $order->get_billing_country() ?: null,
				'material_name'      => $material_name,
				'rug_name'           => $rug_name,
				'weave_type'         => $weave_type,
				'gst_split'          => self::gst_split( $settings['state_code'] ?: null, $order->get_billing_state() ?: null, $result['gst_pct'] ?? null, $order->get_billing_country() ),
			)
		), 200 );
	}

	/** Reads the timeline off WooCommerce's own order notes rather than a
	 * separate history table — WC_Order::status_transition() already writes
	 * an automatic "Order status changed from X to Y." note on every
	 * transition (including ones made from the native wp-admin Edit Order
	 * screen, not just through this plugin's REST routes), so this is a
	 * complete history for free rather than something this plugin has to
	 * maintain in parallel. */
	public static function timeline( WP_REST_Request $request ) {
		$order = self::find_owned_order_loose( (int) $request->get_param( 'id' ), $request );
		if ( ! $order ) {
			return new WP_Error( 'drc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		$status_labels = wc_get_order_statuses();
		$label_to_slug = array();
		foreach ( $status_labels as $slug => $label ) {
			$label_to_slug[ wp_strip_all_tags( $label ) ] = str_replace( 'wc-', '', $slug );
		}

		$notes = wc_get_order_notes( array( 'order_id' => $order->get_id(), 'order_by' => 'date_created', 'order' => 'ASC' ) );
		$timeline = array( array( 'status' => 'pending', 'at' => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null ) );
		foreach ( $notes as $note ) {
			if ( preg_match( '/changed from .* to (.+)\.$/i', trim( wp_strip_all_tags( $note->content ) ), $m ) ) {
				$new_label = trim( $m[1] );
				$slug      = $label_to_slug[ $new_label ] ?? sanitize_title( $new_label );
				$timeline[] = array( 'status' => $slug, 'at' => $note->date_created ? $note->date_created->date( 'c' ) : null );
			}
		}
		return new WP_REST_Response( $timeline, 200 );
	}

	/** Public view of the policy for the admin order screen. */
	public static function policy_terms( WC_Order $order ): array {
		return self::cancellation_terms( $order );
	}

	private static function cancellation_terms( WC_Order $order ): array {
		$status = self::bare_status( $order );
		if ( 'cancelled' === $status ) {
			return array( 'eligible' => false, 'refund_pct' => 0.0, 'reason' => 'This order is already cancelled.' );
		}
		if ( ! isset( self::CANCELLATION_REFUND_POLICY[ $status ] ) ) {
			return array(
				'eligible'   => false,
				'refund_pct' => 0.0,
				'reason'     => sprintf( 'Orders that have reached "%s" are no longer eligible for cancellation.', str_replace( '_', ' ', $status ) ),
			);
		}
		return array( 'eligible' => true, 'refund_pct' => self::CANCELLATION_REFUND_POLICY[ $status ], 'reason' => null );
	}

	private static function eligibility_payload( WC_Order $order ): array {
		$terms       = self::cancellation_terms( $order );
		$has_payment = (bool) $order->get_transaction_id();
		$paid        = $has_payment ? (float) $order->get_total() : 0.0;
		return array(
			'order_id'         => $order->get_id(),
			'status'           => self::bare_status( $order ),
			'eligible'         => $terms['eligible'],
			'already_cancelled' => 'cancelled' === self::bare_status( $order ),
			'refund_pct'       => $terms['refund_pct'],
			'has_payment'      => $has_payment,
			'refund_amount'    => round( $paid * $terms['refund_pct'], 2 ),
			'price_currency'   => $order->get_currency(),
			'reason'           => $terms['reason'],
		);
	}

	public static function cancel_eligibility( WP_REST_Request $request ) {
		$order = self::find_owned_order_strict( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_Error( 'drc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::eligibility_payload( $order ), 200 );
	}

	/** Cancels the order and — if it was paid online and the gateway
	 * supports it (woo-razorpay declares 'refunds' support) — issues a
	 * partial/full refund via WooCommerce's native wc_create_refund(),
	 * which calls the gateway's process_refund() itself using the stored
	 * transaction id. Re-derives eligibility server-side rather than
	 * trusting whatever the cancel-eligibility call showed the client. */
	public static function cancel( WP_REST_Request $request ) {
		$order = self::find_owned_order_strict( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_Error( 'drc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		return self::do_cancel( $order, 'Cancelled by customer via My Orders.' );
	}

	/** Staff cancellation — same policy and refund as the customer path
	 * (orders.py cancel_order shares _cancel_order_and_refund the same way). */
	public static function admin_cancel( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_Error( 'drc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$user = wp_get_current_user();
		return self::do_cancel( $order, sprintf( 'Cancelled by %s with policy refund.', $user->display_name ) );
	}

	private static function do_cancel( WC_Order $order, string $note ) {
		$terms = self::cancellation_terms( $order );
		if ( ! $terms['eligible'] ) {
			return new WP_Error( 'drc_not_eligible', $terms['reason'], array( 'status' => 400 ) );
		}

		$refund_amount = null;
		$refund_status = null;
		if ( $order->get_transaction_id() ) {
			$amount = round( (float) $order->get_total() * $terms['refund_pct'], 2 );
			if ( $amount > 0 ) {
				$refund = wc_create_refund( array(
					'order_id'       => $order->get_id(),
					'amount'         => $amount,
					'reason'         => sprintf( 'Order cancelled while "%s" — %d%% refund per policy', self::bare_status( $order ), $terms['refund_pct'] * 100 ),
					'refund_payment' => true,
				) );
				if ( is_wp_error( $refund ) ) {
					return new WP_Error( 'drc_refund_failed', 'Refund could not be processed: ' . $refund->get_error_message(), array( 'status' => 502 ) );
				}
				$refund_amount = $amount;
				$refund_status = 'processed';
			}
		}

		$order->update_status( 'cancelled', $note );

		return new WP_REST_Response( array(
			'order_id'       => $order->get_id(),
			'status'         => self::bare_status( $order ),
			'refund_amount'  => $refund_amount,
			'refund_status'  => $refund_status,
			'price_currency' => $order->get_currency(),
		), 200 );
	}

	public static function download_invoice( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		$key   = (string) $request->get_param( 'key' );
		$owned = $order && ( ( is_user_logged_in() && self::find_owned_order_strict( $order->get_id() ) ) || ( '' !== $key && hash_equals( $order->get_order_key(), $key ) ) );
		if ( ! $owned ) {
			return new WP_Error( 'drc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		// A tax/export invoice only once the order is paid; before that the
		// customer gets the proforma, same as the original's quote PDFs.
		$type   = $order->is_paid() || in_array( self::bare_status( $order ), array( 'in_production', 'quality_check', 'shipped', 'delivered' ), true ) ? 'tax' : 'proforma';
		$result = DRC_Invoice_PDF::for_order( $order, $type );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		DRC_Invoice_PDF::stream( $result );
	}

	public static function admin_email_invoice( WP_REST_Request $request ) {
		$order = wc_get_order( (int) $request->get_param( 'id' ) );
		if ( ! $order ) {
			return new WP_Error( 'drc_not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$result = DRC_Invoice_PDF::for_order( $order, sanitize_key( $request->get_param( 'type' ) ?: 'tax' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$quote = DRC_Quote_Context::quote_row( (int) $order->get_meta( '_drc_quote_id' ) ) ?: array();
		$to    = $request->get_param( 'recipient_email' ) ?: $order->get_billing_email();
		$sent  = DRC_Invoice_PDF::email( $result, $quote, $to, $order );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		return new WP_REST_Response( array( 'message' => "Email sent to {$to}", 'recipient' => $to ), 200 );
	}

	public static function admin_combine( WP_REST_Request $request ) {
		$result = DRC_Order_Hooks::combine( array_map( 'absint', (array) $request->get_param( 'order_ids' ) ) );
		if ( is_wp_error( $result ) ) {
			return new WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return new WP_REST_Response( array( 'order_id' => $result->get_id(), 'total' => (float) $result->get_total() ), 200 );
	}
}
