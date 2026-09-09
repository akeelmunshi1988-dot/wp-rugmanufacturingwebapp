<?php
/**
 * Vendor/admin REST routes under /wp-json/dreamrugs/v1/admin/... — the
 * Phase 5 work the customer-facing class-rest-routes.php left stubbed.
 * Ported from backend/app/api/routes/{quotes,dashboard,inventory}.py's
 * admin-only endpoints. All require manage_woocommerce (see
 * DRC_Rest_Routes::require_manage_woocommerce) — this is single-tenant
 * (see CLAUDE.md/the migration plan), so there is no tenant_id filtering
 * to port; every query here reads the whole table, matching the original
 * app's per-tenant queries scoped to the one tenant that would exist.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Admin_Rest_Routes {

	const NS = 'dreamrugs/v1';

	public static function register() {
		$auth = array( 'DRC_Rest_Routes', 'require_manage_woocommerce' );

		// ── Quotes ──────────────────────────────────────────────────────
		register_rest_route( self::NS, '/admin/quotes', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_quotes' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_quote' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)/send', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'send_quote' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)/accept', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'accept_quote' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)/reject', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'reject_quote' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)/revise', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'revise_quote' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)/adjust/preview', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'preview_adjust_quote' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)/adjust', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'adjust_quote' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)/sample-images', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'set_sample_images' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/quotes/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_quote' ),
			'permission_callback' => $auth,
		) );

		// ── Dashboard ───────────────────────────────────────────────────
		register_rest_route( self::NS, '/admin/dashboard-stats', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'dashboard_stats' ),
			'permission_callback' => $auth,
		) );

		// ── Inventory (materials) ───────────────────────────────────────
		register_rest_route( self::NS, '/admin/materials', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'list_materials' ), 'permission_callback' => $auth ),
			array( 'methods' => 'POST', 'callback' => array( __CLASS__, 'create_material' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( self::NS, '/admin/materials/low-stock', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'low_stock_materials' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/materials/(?P<id>\d+)', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_material' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'update_material' ), 'permission_callback' => $auth ),
			array( 'methods' => 'DELETE', 'callback' => array( __CLASS__, 'delete_material' ), 'permission_callback' => $auth ),
		) );
		register_rest_route( self::NS, '/admin/materials/(?P<id>\d+)/restock', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'restock_material' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/materials/(?P<id>\d+)/transactions', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'material_transactions' ),
			'permission_callback' => $auth,
		) );

		// ── Pricing / MOQ / production-timeline rules ──────────────────
		self::register_rule_crud( 'pricing-rules', 'drc_pricing_rules', $auth );
		self::register_rule_crud( 'moq-rules', 'drc_moq_rules', $auth );
		self::register_rule_crud( 'production-timelines', 'drc_production_timelines', $auth );

		// ── Settings ────────────────────────────────────────────────────
		register_rest_route( self::NS, '/admin/settings', array(
			array( 'methods' => 'GET', 'callback' => array( __CLASS__, 'get_settings' ), 'permission_callback' => $auth ),
			array( 'methods' => 'PUT', 'callback' => array( __CLASS__, 'update_settings' ), 'permission_callback' => $auth ),
		) );
		// Simple id/name product list for the Trending Rugs picker on the
		// Settings screen — deliberately not the full wc_get_products() shape
		// used elsewhere, just enough for a <select> option list.
		register_rest_route( self::NS, '/admin/products/simple', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_products_simple' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/currency/sync', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'sync_currency' ),
			'permission_callback' => $auth,
		) );

		// ── Trade enquiries ─────────────────────────────────────────────
		register_rest_route( self::NS, '/admin/trade-enquiries', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_trade_enquiries' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/trade-enquiries/(?P<id>\d+)/read', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'mark_trade_enquiry_read' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/trade-enquiries/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_trade_enquiry' ),
			'permission_callback' => $auth,
		) );

		// ── Homepage enquiries ──────────────────────────────────────────
		register_rest_route( self::NS, '/admin/homepage-enquiries', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_homepage_enquiries' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/homepage-enquiries/(?P<id>\d+)/read', array(
			'methods'             => 'PATCH',
			'callback'            => array( __CLASS__, 'mark_homepage_enquiry_read' ),
			'permission_callback' => $auth,
		) );
		register_rest_route( self::NS, '/admin/homepage-enquiries/(?P<id>\d+)', array(
			'methods'             => 'DELETE',
			'callback'            => array( __CLASS__, 'delete_homepage_enquiry' ),
			'permission_callback' => $auth,
		) );
	}

	/** Registers plain GET-list/POST-create/PUT-update/DELETE routes for one
	 * of the three simple config tables — all three share the same shape
	 * (no state machine, no relations, admin-only), so this avoids writing
	 * near-identical CRUD three times. */
	private static function register_rule_crud( string $slug, string $table_suffix, $auth ) {
		register_rest_route( self::NS, "/admin/{$slug}", array(
			array(
				'methods'             => 'GET',
				'callback'            => function () use ( $table_suffix ) {
					global $wpdb;
					$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}{$table_suffix} ORDER BY id DESC", ARRAY_A );
					return new WP_REST_Response( array_map( array( __CLASS__, 'cast_rule_row' ), $rows ), 200 );
				},
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'POST',
				'callback'            => function ( WP_REST_Request $request ) use ( $table_suffix ) {
					global $wpdb;
					$data = self::sanitize_rule_params( $request, $table_suffix );
					if ( ! $wpdb->insert( $wpdb->prefix . $table_suffix, $data ) ) {
						return new WP_Error( 'drc_insert_failed', 'Could not create the row.', array( 'status' => 500 ) );
					}
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}{$table_suffix} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
					return new WP_REST_Response( self::cast_rule_row( $row ), 201 );
				},
				'permission_callback' => $auth,
			),
		) );
		register_rest_route( self::NS, "/admin/{$slug}/(?P<id>\\d+)", array(
			array(
				'methods'             => 'PUT',
				'callback'            => function ( WP_REST_Request $request ) use ( $table_suffix ) {
					global $wpdb;
					$id = (int) $request->get_param( 'id' );
					$table = $wpdb->prefix . $table_suffix;
					if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ) ) {
						return new WP_Error( 'drc_not_found', 'Row not found.', array( 'status' => 404 ) );
					}
					$data = self::sanitize_rule_params( $request, $table_suffix, true );
					$wpdb->update( $table, $data, array( 'id' => $id ) );
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
					return new WP_REST_Response( self::cast_rule_row( $row ), 200 );
				},
				'permission_callback' => $auth,
			),
			array(
				'methods'             => 'DELETE',
				'callback'            => function ( WP_REST_Request $request ) use ( $table_suffix ) {
					global $wpdb;
					$id = (int) $request->get_param( 'id' );
					$table = $wpdb->prefix . $table_suffix;
					if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ) ) {
						return new WP_Error( 'drc_not_found', 'Row not found.', array( 'status' => 404 ) );
					}
					$wpdb->delete( $table, array( 'id' => $id ) );
					return new WP_REST_Response( array( 'message' => 'Deleted.' ), 200 );
				},
				'permission_callback' => $auth,
			),
		) );
	}

	/** Column allowlist per config table — request params outside this list
	 * are silently ignored rather than erroring, matching Pydantic's
	 * exclude_unset semantics for a PUT that only touches some fields. */
	private static function sanitize_rule_params( WP_REST_Request $request, string $table_suffix, bool $partial = false ): array {
		$columns = array(
			'drc_pricing_rules'        => array( 'name' => 'text', 'rule_type' => 'text', 'min_qty' => 'float', 'max_qty' => 'float', 'multiplier' => 'float', 'flat_fee' => 'float', 'description' => 'textarea' ),
			'drc_moq_rules'            => array( 'rug_type' => 'text', 'minimum_sqm' => 'float', 'minimum_pieces' => 'int', 'notes' => 'textarea' ),
			'drc_production_timelines' => array( 'order_type' => 'text', 'base_days' => 'int', 'complexity_multiplier_per_sqm' => 'float', 'notes' => 'textarea' ),
		);
		$data = array();
		foreach ( $columns[ $table_suffix ] as $col => $type ) {
			if ( $partial && null === $request->get_param( $col ) ) {
				continue;
			}
			$value = $request->get_param( $col );
			$data[ $col ] = match ( $type ) {
				'float'    => null !== $value ? (float) $value : null,
				'int'      => null !== $value ? (int) $value : null,
				'textarea' => sanitize_textarea_field( $value ?? '' ),
				default    => sanitize_text_field( $value ?? '' ),
			};
		}
		return $data;
	}

	private static function cast_rule_row( array $row ): array {
		$row['id'] = (int) $row['id'];
		foreach ( array( 'min_qty', 'max_qty', 'multiplier', 'flat_fee', 'minimum_sqm', 'complexity_multiplier_per_sqm' ) as $f ) {
			if ( array_key_exists( $f, $row ) ) {
				$row[ $f ] = null !== $row[ $f ] ? (float) $row[ $f ] : null;
			}
		}
		foreach ( array( 'minimum_pieces', 'base_days' ) as $f ) {
			if ( array_key_exists( $f, $row ) ) {
				$row[ $f ] = null !== $row[ $f ] ? (int) $row[ $f ] : null;
			}
		}
		return $row;
	}

	// ── Quotes ──────────────────────────────────────────────────────────

	/** Shapes a wp_drc_quotes row for the admin list/detail views — unlike
	 * format_quote_for_customer() in class-rest-routes.php, this exposes the
	 * raw editable fields (material_id, custom_size_w/h, rug_shape,
	 * guest_name/email) rather than a display-only shape. */
	private static function format_quote_for_admin( array $q ): array {
		$rug_name  = 'Custom Rug Request';
		$rug_image = null;
		if ( ! empty( $q['product_id'] ) ) {
			$product = wc_get_product( $q['product_id'] );
			if ( $product ) {
				$rug_name  = $product->get_name();
				$image_id  = $product->get_image_id();
				$rug_image = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : null;
			}
		}
		$customer_name  = $q['guest_name'];
		$customer_email = $q['guest_email'];
		if ( $q['customer_user_id'] ) {
			$user = get_userdata( $q['customer_user_id'] );
			if ( $user ) {
				$customer_name  = $user->display_name;
				$customer_email = $user->user_email;
			}
		}

		$row              = $q;
		$row['id']        = (int) $q['id'];
		$row['rug_name']  = $rug_name;
		$row['rug_image_url'] = $rug_image;
		$row['customer_name']  = $customer_name;
		$row['customer_email'] = $customer_email;
		foreach ( array( 'custom_size_w', 'custom_size_h', 'base_price', 'final_price', 'margin_pct', 'gst_pct', 'manual_discount_pct', 'shipping_cost' ) as $f ) {
			$row[ $f ] = null !== $q[ $f ] ? (float) $q[ $f ] : null;
		}
		foreach ( array( 'qty', 'review_request_count', 'expected_delivery_days' ) as $f ) {
			$row[ $f ] = null !== $q[ $f ] ? (int) $q[ $f ] : null;
		}
		$row['rush_order']         = (bool) $q['rush_order'];
		$row['is_custom_request']  = (bool) $q['is_custom_request'];
		$row['reference_image_urls']     = $q['reference_image_urls'] ? json_decode( $q['reference_image_urls'], true ) : null;
		$row['vendor_sample_image_urls'] = $q['vendor_sample_image_urls'] ? json_decode( $q['vendor_sample_image_urls'], true ) : null;
		return $row;
	}

	/** Mirrors GET /quotes (quotes.py) — pagination, per-status counts over
	 * the same filtered base query, and a name/email/id search. */
	public static function list_quotes( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';

		$page      = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$page_size = min( 200, max( 1, (int) ( $request->get_param( 'page_size' ) ?: 20 ) ) );
		$status    = $request->get_param( 'status' );
		$search    = $request->get_param( 'search' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );

		$where = array( '1=1' );
		if ( null !== $request->get_param( 'rush_order' ) ) {
			$where[] = $wpdb->prepare( 'rush_order = %d', $request->get_param( 'rush_order' ) ? 1 : 0 );
		}
		if ( null !== $request->get_param( 'is_custom_request' ) ) {
			$where[] = $wpdb->prepare( 'is_custom_request = %d', $request->get_param( 'is_custom_request' ) ? 1 : 0 );
		}
		if ( $request->get_param( 'product_id' ) ) {
			$where[] = $wpdb->prepare( 'product_id = %d', (int) $request->get_param( 'product_id' ) );
		}
		if ( $search ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$conditions = array( $wpdb->prepare( '(guest_name LIKE %s OR guest_email LIKE %s)', $like, $like ) );
			if ( ctype_digit( trim( $search ) ) ) {
				$conditions[] = $wpdb->prepare( 'id = %d', (int) trim( $search ) );
			}
			$where[] = '(' . implode( ' OR ', $conditions ) . ')';
		}
		if ( $date_from ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $date_from . ' 00:00:00' );
		}
		if ( $date_to ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $date_to . ' 23:59:59' );
		}
		$base_where = implode( ' AND ', $where );

		$status_counts = array( 'draft' => 0, 'sent' => 0, 'accepted' => 0, 'rejected' => 0 );
		$counts = $wpdb->get_results( "SELECT status, COUNT(*) as cnt FROM {$table} WHERE {$base_where} GROUP BY status", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $base_where built entirely from wpdb->prepare()'d fragments above.
		$all_count = 0;
		foreach ( $counts as $row ) {
			if ( isset( $status_counts[ $row['status'] ] ) ) {
				$status_counts[ $row['status'] ] = (int) $row['cnt'];
			}
			$all_count += (int) $row['cnt'];
		}
		$status_counts['all'] = $all_count;

		$final_where = $base_where;
		if ( $status && 'all' !== $status ) {
			$final_where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$final_where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$offset = ( $page - 1 ) * $page_size;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$final_where} ORDER BY created_at DESC LIMIT %d OFFSET %d", $page_size, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return new WP_REST_Response( array(
			'items'         => array_map( array( __CLASS__, 'format_quote_for_admin' ), $rows ),
			'total'         => $total,
			'page'          => $page,
			'page_size'     => $page_size,
			'pages'         => $total > 0 ? (int) ceil( $total / $page_size ) : 0,
			'status_counts' => $status_counts,
		), 200 );
	}

	private static function find_quote_or_404( int $id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $quote ?: null;
	}

	public static function get_quote( WP_REST_Request $request ) {
		$quote = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::format_quote_for_admin( $quote ), 200 );
	}

	/** Fire-and-forget customer notification — a plain, functional email
	 * rather than a port of the original's full templated email system
	 * (backend/app/services/email_templates.py, a separate large area of
	 * its own). Never blocks or fails the request if mail sending fails. */
	private static function notify_customer( array $quote, string $subject, string $body ) {
		$email = $quote['guest_email'];
		if ( $quote['customer_user_id'] ) {
			$user  = get_userdata( $quote['customer_user_id'] );
			$email = $user ? $user->user_email : $email;
		}
		if ( $email && is_email( $email ) ) {
			wp_mail( $email, $subject, $body );
		}
	}

	public static function send_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$quote = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		if ( ! in_array( $quote['status'], array( 'draft', 'sent' ), true ) ) {
			return new WP_Error( 'drc_invalid_state', "Cannot send a quote with status '{$quote['status']}'.", array( 'status' => 400 ) );
		}
		$update = array( 'status' => 'sent' );
		$vendor_notes = $request->get_param( 'vendor_notes' );
		if ( null !== $vendor_notes ) {
			$update['vendor_notes'] = sanitize_textarea_field( $vendor_notes );
		}
		$wpdb->update( $table, $update, array( 'id' => $quote['id'] ) );
		$updated = self::find_quote_or_404( $quote['id'] );
		self::notify_customer( $updated, 'Your DreamRugsCreation quote is ready', "Your quote #{$updated['id']} is ready for review at " . home_url( '/my-quotes/' ) );
		return new WP_REST_Response( self::format_quote_for_admin( $updated ), 200 );
	}

	/**
	 * Vendor-initiated "Accept - Create Order" — mirrors update_quote()'s
	 * became_accepted branch in the original app's quotes.py (the customer
	 * self-accept path is DRC_Rest_Routes::transition_quote()'s 'accept'
	 * case; this is the same order-creation call, just reachable by a
	 * vendor instead of the quote's own customer). Same terminal-state
	 * guard as reject_quote()/adjust_quote() — a vendor can convert a
	 * draft (e.g. an over-the-phone sale) or sent quote, but not one
	 * that's already been accepted or rejected.
	 *
	 * Unlike the original app (confirmed via research: neither the FastAPI
	 * backend nor the WP customer-accept path sends any email here), this
	 * also emails the customer a payment-pending notice with the pay link
	 * — a genuinely new addition, not a port, since the resulting order
	 * has no payment on it yet and nothing else tells the customer to go
	 * pay for it.
	 */
	public static function accept_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$quote = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		if ( in_array( $quote['status'], array( 'accepted', 'rejected' ), true ) ) {
			return new WP_Error( 'drc_invalid_state', "Cannot accept a quote that is already '{$quote['status']}'.", array( 'status' => 400 ) );
		}

		$order = DRC_Order_Creator::create_from_quote( $quote );
		if ( is_wp_error( $order ) ) {
			return new WP_Error( 'drc_order_creation_failed', $order->get_error_message(), array( 'status' => 400 ) );
		}
		$wpdb->update( $table, array( 'status' => 'accepted' ), array( 'id' => $quote['id'] ) );
		$updated = self::find_quote_or_404( $quote['id'] );

		$pay_url = $order->get_checkout_payment_url();
		self::notify_customer(
			$updated,
			'Your DreamRugsCreation order is ready for payment',
			sprintf(
				"Good news — your order #%d (from quote #%d) has been confirmed and is awaiting payment.\n\nTotal due: %s\n\nComplete your payment here:\n%s",
				$order->get_id(),
				$updated['id'],
				wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ),
				$pay_url
			)
		);

		$response              = self::format_quote_for_admin( $updated );
		$response['order_id']  = $order->get_id();
		$response['pay_url']   = $pay_url;
		return new WP_REST_Response( $response, 200 );
	}

	public static function reject_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$quote = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		if ( in_array( $quote['status'], array( 'accepted', 'rejected' ), true ) ) {
			return new WP_Error( 'drc_invalid_state', "Cannot reject a quote that is already '{$quote['status']}'.", array( 'status' => 400 ) );
		}
		$reason = sanitize_textarea_field( $request->get_param( 'reason' ) ?: '' );
		$update = array( 'status' => 'rejected' );
		if ( $reason ) {
			$update['vendor_notes'] = $reason;
		}
		$wpdb->update( $table, $update, array( 'id' => $quote['id'] ) );
		$updated = self::find_quote_or_404( $quote['id'] );
		self::notify_customer( $updated, 'Update on your DreamRugsCreation quote', "Quote #{$updated['id']} could not be fulfilled." . ( $reason ? " Reason: {$reason}" : '' ) );
		return new WP_REST_Response( self::format_quote_for_admin( $updated ), 200 );
	}

	/** Clones a rejected quote into a new draft — preserves the rejection as
	 * an immutable record rather than reopening it in place, exactly as
	 * revise_quote() in the original quotes.py does. */
	public static function revise_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$original = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $original ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		if ( 'rejected' !== $original['status'] ) {
			return new WP_Error( 'drc_invalid_state', "Only a rejected quote can be revised — this quote is currently '{$original['status']}'.", array( 'status' => 400 ) );
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE revised_from_quote_id = %d", $original['id'] ) );
		if ( $existing ) {
			return new WP_Error( 'drc_already_revised', "This quote was already revised into Quote #{$existing}.", array( 'status' => 400 ) );
		}

		$wpdb->insert( $table, array(
			'customer_user_id'         => $original['customer_user_id'],
			'guest_name'               => $original['guest_name'],
			'guest_email'              => $original['guest_email'],
			'product_id'               => $original['product_id'],
			'variation_id'             => $original['variation_id'],
			'custom_size_w'            => $original['custom_size_w'],
			'custom_size_h'            => $original['custom_size_h'],
			'rug_shape'                => $original['rug_shape'],
			'material_id'              => $original['material_id'],
			'qty'                      => $original['qty'],
			'rush_order'               => $original['rush_order'],
			'status'                   => 'draft',
			'notes'                    => $original['notes'],
			'is_custom_request'        => $original['is_custom_request'],
			'room_type'                => $original['room_type'],
			'material_preference'      => $original['material_preference'],
			'budget_range'             => $original['budget_range'],
			'reference_image_urls'     => $original['reference_image_urls'],
			'vendor_sample_image_urls' => $original['vendor_sample_image_urls'],
			'revised_from_quote_id'    => $original['id'],
			// Deliberately not copied: final_price/base_price/margin_pct/
			// gst_pct/price_currency/manual_discount_pct (fresh pricing),
			// vendor_notes/customer_response_notes (belong to the rejected
			// quote's own history) — matches revise_quote()'s comment.
		) );
		$revised = self::find_quote_or_404( $wpdb->insert_id );
		return new WP_REST_Response( self::format_quote_for_admin( $revised ), 201 );
	}

	/** Computes the material-cost-based price using in-progress form values
	 * without saving anything — lets the vendor preview the breakdown
	 * before committing to Send, exactly as preview_quote_adjustment() does. */
	public static function preview_adjust_quote( WP_REST_Request $request ) {
		$quote = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		$material_id = $request->get_param( 'material_id' );
		if ( null === $material_id ) {
			return new WP_Error( 'drc_missing_material', 'material_id is required to preview a calculation.', array( 'status' => 400 ) );
		}
		$engine = new DRC_Quote_Engine();
		$result = $engine->calculate_custom_quote(
			$quote,
			(int) $material_id,
			null !== $request->get_param( 'margin_pct' ) ? (float) $request->get_param( 'margin_pct' ) : null,
			null !== $request->get_param( 'custom_size_w' ) ? (float) $request->get_param( 'custom_size_w' ) : null,
			null !== $request->get_param( 'custom_size_h' ) ? (float) $request->get_param( 'custom_size_h' ) : null,
			null !== $request->get_param( 'manual_discount_pct' ) ? (float) $request->get_param( 'manual_discount_pct' ) : null,
			$request->get_param( 'rug_shape' ) ?: null,
			null !== $request->get_param( 'shipping_cost' ) ? (float) $request->get_param( 'shipping_cost' ) : null
		);
		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'drc_preview_failed', $result['error'], array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/** Applies a price (material-based recalculation, or a vendor-typed flat
	 * final_price) and re-sends the quote — mirrors adjust_quote_price(). */
	public static function adjust_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$quote = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		if ( in_array( $quote['status'], array( 'accepted', 'rejected' ), true ) ) {
			return new WP_Error( 'drc_invalid_state', "Cannot adjust a quote that is already '{$quote['status']}'.", array( 'status' => 400 ) );
		}
		$material_id = $request->get_param( 'material_id' );
		$final_price = $request->get_param( 'final_price' );
		if ( null === $material_id && null === $final_price ) {
			return new WP_Error( 'drc_missing_price', 'Provide either final_price or material_id.', array( 'status' => 400 ) );
		}

		$update = array();
		if ( null !== $request->get_param( 'manual_discount_pct' ) ) {
			$update['manual_discount_pct'] = (float) $request->get_param( 'manual_discount_pct' );
		}
		if ( null !== $request->get_param( 'shipping_cost' ) ) {
			$update['shipping_cost'] = (float) $request->get_param( 'shipping_cost' );
		}
		if ( null !== $request->get_param( 'custom_size_w' ) ) {
			$update['custom_size_w'] = (float) $request->get_param( 'custom_size_w' );
		}
		if ( null !== $request->get_param( 'custom_size_h' ) ) {
			$update['custom_size_h'] = (float) $request->get_param( 'custom_size_h' );
		}
		if ( null !== $request->get_param( 'rug_shape' ) ) {
			$update['rug_shape'] = sanitize_text_field( $request->get_param( 'rug_shape' ) );
		}
		// Apply the field updates above to the working copy before pricing,
		// so a material-based calculation below reflects them — same
		// ordering requirement called out in adjust_quote_price()'s comment.
		$quote = array_merge( $quote, $update );

		if ( null !== $material_id ) {
			$engine = new DRC_Quote_Engine();
			$result = $engine->calculate_custom_quote(
				$quote,
				(int) $material_id,
				null !== $request->get_param( 'margin_pct' ) ? (float) $request->get_param( 'margin_pct' ) : null
			);
			if ( isset( $result['error'] ) ) {
				return new WP_Error( 'drc_adjust_failed', $result['error'], array( 'status' => 400 ) );
			}
			$update['material_id']    = (int) $material_id;
			$update['margin_pct']     = $result['profit_margin_pct'];
			$update['base_price']     = $result['subtotal'];
			$update['final_price']    = $result['final_price'];
			$update['gst_pct']        = $result['gst_pct'];
			$update['price_currency'] = $result['price_currency'];
		} else {
			$update['final_price'] = (float) $final_price;
		}

		$vendor_notes = $request->get_param( 'vendor_notes' );
		if ( null !== $vendor_notes ) {
			$update['vendor_notes'] = sanitize_textarea_field( $vendor_notes );
		}
		$update['status'] = 'sent';

		$wpdb->update( $table, $update, array( 'id' => $quote['id'] ) );
		$updated = self::find_quote_or_404( $quote['id'] );
		self::notify_customer( $updated, 'Your DreamRugsCreation quote is ready', "Your quote #{$updated['id']} is ready for review at " . home_url( '/my-quotes/' ) );
		return new WP_REST_Response( self::format_quote_for_admin( $updated ), 200 );
	}

	public static function set_sample_images( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$quote = self::find_quote_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		$urls = array_map( 'esc_url_raw', (array) $request->get_param( 'image_urls' ) );
		$wpdb->update( $table, array( 'vendor_sample_image_urls' => wp_json_encode( $urls ) ), array( 'id' => $quote['id'] ) );
		$updated = self::find_quote_or_404( $quote['id'] );
		return new WP_REST_Response( self::format_quote_for_admin( $updated ), 200 );
	}

	public static function delete_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		$id    = (int) $request->get_param( 'id' );
		if ( ! self::find_quote_or_404( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		$wpdb->delete( $table, array( 'id' => $id ) );
		return new WP_REST_Response( array( 'message' => 'Quote deleted successfully' ), 200 );
	}

	// ── Dashboard ───────────────────────────────────────────────────────

	/** Ports dashboard.py's get_dashboard_stats(). Single-tenant, so the
	 * original's per-tenant filter and multi-currency _to_base() conversion
	 * both drop out — there is exactly one store here, one currency. */
	public static function dashboard_stats() {
		global $wpdb;
		$quotes_table    = $wpdb->prefix . 'drc_quotes';
		$materials_table = $wpdb->prefix . 'drc_materials';
		$low_stock_threshold = 50.0;

		// wc_get_orders()/WC_Order_Query, not raw wp_posts SQL: this store
		// runs WooCommerce HPOS (orders live in wp_wc_orders, confirmed live
		// — wp_posts has zero 'shop_order' rows), and wc_get_orders() is the
		// one API that works correctly under either storage backend.
		$total_orders = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids' ) ) );

		$total_revenue = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(final_price),0) FROM {$quotes_table} WHERE status = %s", 'accepted' ) );

		$active_quotes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$quotes_table} WHERE status IN ('draft','sent')" );

		$low_stock_materials = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$materials_table} WHERE stock_meters < %f", $low_stock_threshold ) );

		// 'in_production' is a real registered order status (see
		// class-order-statuses.php) as of this dashboard's last fix — an
		// earlier version of this query filtered on a '_drc_fulfillment_status'
		// postmeta key that nothing ever wrote, so this count was always 0.
		$orders_in_production = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => 'wc-in_production' ) ) );
		$orders_pending       = count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'status' => 'pending' ) ) );

		$recent_orders_raw = wc_get_orders( array( 'limit' => 5, 'orderby' => 'date', 'order' => 'DESC' ) );
		$recent_orders = array();
		foreach ( $recent_orders_raw as $order ) {
			$quote_row = $wpdb->get_row( $wpdb->prepare( "SELECT final_price, price_currency FROM {$quotes_table} WHERE wc_order_id = %d", $order->get_id() ), ARRAY_A );
			$recent_orders[] = array(
				'id'                  => $order->get_id(),
				'status'              => $order->get_status(),
				'created_at'          => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
				'customer_name'       => trim( $order->get_formatted_billing_full_name() ) ?: null,
				'final_price'         => $quote_row ? (float) $quote_row['final_price'] : (float) $order->get_total(),
				'price_currency'      => $quote_row['price_currency'] ?? get_woocommerce_currency(),
			);
		}

		$recent_quotes_raw = $wpdb->get_results( "SELECT * FROM {$quotes_table} ORDER BY created_at DESC LIMIT 5", ARRAY_A );
		$recent_quotes = array_map( function ( $q ) {
			$name = $q['guest_name'];
			if ( $q['customer_user_id'] ) {
				$user = get_userdata( $q['customer_user_id'] );
				$name = $user ? $user->display_name : $name;
			}
			$rug_name = null;
			if ( $q['product_id'] ) {
				$p = wc_get_product( $q['product_id'] );
				$rug_name = $p ? $p->get_name() : null;
			}
			return array(
				'id'             => (int) $q['id'],
				'status'         => $q['status'],
				'created_at'     => $q['created_at'] ? gmdate( 'c', strtotime( $q['created_at'] ) ) : null,
				'customer_name'  => $name,
				'rug_name'       => $rug_name,
				'final_price'    => null !== $q['final_price'] ? (float) $q['final_price'] : null,
				'price_currency' => $q['price_currency'],
				'qty'            => (int) $q['qty'],
			);
		}, $recent_quotes_raw );

		$monthly_revenue = array();
		for ( $i = 5; $i >= 0; $i-- ) {
			$month_start = gmdate( 'Y-m-01 00:00:00', strtotime( "-{$i} months" ) );
			$month_end   = gmdate( 'Y-m-01 00:00:00', strtotime( "-{$i} months +1 month" ) );
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT COALESCE(SUM(final_price),0) AS revenue, COUNT(*) AS orders FROM {$quotes_table} WHERE status = 'accepted' AND created_at >= %s AND created_at < %s",
				$month_start,
				$month_end
			), ARRAY_A );
			$monthly_revenue[] = array(
				'month'   => gmdate( 'M Y', strtotime( $month_start ) ),
				'revenue' => round( (float) $row['revenue'], 2 ),
				'orders'  => (int) $row['orders'],
			);
		}

		return new WP_REST_Response( array(
			'total_orders'          => $total_orders,
			'total_revenue'         => round( $total_revenue, 2 ),
			'active_quotes'         => $active_quotes,
			'low_stock_materials'   => $low_stock_materials,
			'orders_in_production'  => $orders_in_production,
			'orders_pending'        => $orders_pending,
			'recent_orders'         => $recent_orders,
			'recent_quotes'         => $recent_quotes,
			'monthly_revenue'       => $monthly_revenue,
		), 200 );
	}

	// ── Inventory (materials) ───────────────────────────────────────────

	private static function format_material( array $m ): array {
		$m['id']            = (int) $m['id'];
		$m['stock_meters']  = (float) $m['stock_meters'];
		$m['cost_per_sqm']  = (float) $m['cost_per_sqm'];
		$m['is_available']  = (bool) $m['is_available'];
		$m['legacy_id']     = $m['legacy_id'] ? (int) $m['legacy_id'] : null;
		return $m;
	}

	public static function list_materials() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}drc_materials ORDER BY name ASC", ARRAY_A );
		return new WP_REST_Response( array_map( array( __CLASS__, 'format_material' ), $rows ), 200 );
	}

	public static function low_stock_materials() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_materials WHERE stock_meters < %f ORDER BY stock_meters ASC", 50.0 ), ARRAY_A );
		return new WP_REST_Response( array_map( array( __CLASS__, 'format_material' ), $rows ), 200 );
	}

	private static function find_material_or_404( int $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_materials WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	public static function get_material( WP_REST_Request $request ) {
		$m = self::find_material_or_404( (int) $request->get_param( 'id' ) );
		if ( ! $m ) {
			return new WP_Error( 'drc_not_found', 'Material not found.', array( 'status' => 404 ) );
		}
		return new WP_REST_Response( self::format_material( $m ), 200 );
	}

	public static function create_material( WP_REST_Request $request ) {
		global $wpdb;
		$settings = DRC_Settings::all();
		$data = array(
			'name'          => sanitize_text_field( $request->get_param( 'name' ) ),
			'type'          => sanitize_text_field( $request->get_param( 'type' ) ),
			'color'         => sanitize_text_field( $request->get_param( 'color' ) ),
			'stock_meters'  => (float) ( $request->get_param( 'stock_meters' ) ?: 0 ),
			'cost_per_sqm'  => (float) $request->get_param( 'cost_per_sqm' ),
			'cost_currency' => sanitize_text_field( $request->get_param( 'cost_currency' ) ?: $settings['currency'] ),
			'is_available'  => $request->get_param( 'is_available' ) === false ? 0 : 1,
		);
		if ( ! $data['name'] || ! $data['type'] || ! $data['color'] ) {
			return new WP_Error( 'drc_missing_fields', 'name, type, and color are required.', array( 'status' => 400 ) );
		}
		if ( ! $wpdb->insert( $wpdb->prefix . 'drc_materials', $data ) ) {
			return new WP_Error( 'drc_insert_failed', 'Could not create material.', array( 'status' => 500 ) );
		}
		return new WP_REST_Response( self::format_material( self::find_material_or_404( $wpdb->insert_id ) ), 201 );
	}

	public static function update_material( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		if ( ! self::find_material_or_404( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Material not found.', array( 'status' => 404 ) );
		}
		$fields = array( 'name' => 'text', 'type' => 'text', 'color' => 'text', 'stock_meters' => 'float', 'cost_per_sqm' => 'float', 'cost_currency' => 'text', 'is_available' => 'bool' );
		$data = array();
		foreach ( $fields as $col => $type ) {
			$value = $request->get_param( $col );
			if ( null === $value ) {
				continue;
			}
			$data[ $col ] = match ( $type ) {
				'float' => (float) $value,
				'bool'  => $value ? 1 : 0,
				default => sanitize_text_field( $value ),
			};
		}
		$wpdb->update( $wpdb->prefix . 'drc_materials', $data, array( 'id' => $id ) );
		return new WP_REST_Response( self::format_material( self::find_material_or_404( $id ) ), 200 );
	}

	public static function delete_material( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		if ( ! self::find_material_or_404( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Material not found.', array( 'status' => 404 ) );
		}
		$wpdb->delete( $wpdb->prefix . 'drc_materials', array( 'id' => $id ) );
		return new WP_REST_Response( null, 204 );
	}

	public static function restock_material( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		$material = self::find_material_or_404( $id );
		if ( ! $material ) {
			return new WP_Error( 'drc_not_found', 'Material not found.', array( 'status' => 404 ) );
		}
		$qty_meters = (float) $request->get_param( 'qty_meters' );
		if ( $qty_meters <= 0 ) {
			return new WP_Error( 'drc_invalid_qty', 'Quantity must be positive.', array( 'status' => 400 ) );
		}
		$notes = sanitize_textarea_field( $request->get_param( 'notes' ) ?: '' ) ?: "Restocked {$qty_meters} meters";

		$wpdb->update( $wpdb->prefix . 'drc_materials', array(
			'stock_meters' => (float) $material['stock_meters'] + $qty_meters,
			'is_available' => 1,
		), array( 'id' => $id ) );
		$wpdb->insert( $wpdb->prefix . 'drc_inventory_transactions', array(
			'material_id'      => $id,
			'qty_change'       => $qty_meters,
			'transaction_type' => 'restock',
			'notes'            => $notes,
		) );
		return new WP_REST_Response( self::format_material( self::find_material_or_404( $id ) ), 200 );
	}

	public static function material_transactions( WP_REST_Request $request ) {
		global $wpdb;
		$id = (int) $request->get_param( 'id' );
		if ( ! self::find_material_or_404( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Material not found.', array( 'status' => 404 ) );
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_inventory_transactions WHERE material_id = %d ORDER BY created_at DESC", $id ), ARRAY_A );
		$rows = array_map( function ( $r ) {
			$r['id']          = (int) $r['id'];
			$r['material_id'] = (int) $r['material_id'];
			$r['qty_change']  = (float) $r['qty_change'];
			return $r;
		}, $rows );
		return new WP_REST_Response( $rows, 200 );
	}

	// ── Products (simple list) ───────────────────────────────────────────

	public static function list_products_simple() {
		$products = wc_get_products( array( 'status' => 'publish', 'limit' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$rows = array_map( function ( $p ) {
			return array( 'id' => $p->get_id(), 'name' => $p->get_name() );
		}, $products );
		return new WP_REST_Response( $rows, 200 );
	}

	// ── Settings ────────────────────────────────────────────────────────

	public static function get_settings() {
		$settings = DRC_Settings::all();
		// Read-only sync metadata, not part of DRC_Settings' own storage —
		// update_settings() below only ever writes back keys from
		// DRC_Settings::defaults(), so appending these here is harmless on
		// save (silently ignored, same as any other unknown key would be).
		$settings['exchange_rates_synced_at']  = get_option( 'drc_exchange_rates_synced_at' ) ?: null;
		$settings['exchange_rates_last_error'] = get_option( 'drc_exchange_rates_last_error' ) ?: null;
		return new WP_REST_Response( $settings, 200 );
	}

	/** Manual "Sync Now" trigger for the admin settings screen — same
	 * DRC_Currency_Sync::sync() the daily wp-cron job runs, just on demand
	 * so an admin can see it work (or see why it didn't) immediately
	 * rather than waiting up to 24 hours. */
	public static function sync_currency() {
		$result = DRC_Currency_Sync::sync();
		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'drc_sync_failed', $result->get_error_message(), array( 'status' => 502 ) );
		}
		return new WP_REST_Response( array(
			'exchange_rates' => DRC_Settings::all()['exchange_rates'],
			'synced_at'      => get_option( 'drc_exchange_rates_synced_at' ),
		), 200 );
	}

	// Settings whose value is a list of associative arrays (repeater fields
	// on the admin Settings screen) rather than a flat list of strings —
	// the generic sanitize_text_field() array-map below would corrupt these
	// (it expects each array item to be a string), so each is sanitized
	// field-by-field instead. Value = which of its keys is a URL vs. plain text.
	const REPEATER_SETTINGS = array(
		'order_tracking_carriers'    => array( 'url_keys' => array( 'image_url' ), 'text_keys' => array( 'label' ) ),
		'order_tracking_steps'       => array( 'url_keys' => array(), 'text_keys' => array( 'title', 'description' ) ),
		'colour_matching_items'      => array( 'url_keys' => array( 'image_url' ), 'text_keys' => array( 'title', 'description' ) ),
		'hero_images'                => array( 'url_keys' => array( 'image_url' ), 'text_keys' => array( 'alt_text', 'eyebrow', 'headline', 'button_text' ) ),
		'homepage_values_items'      => array( 'url_keys' => array(), 'text_keys' => array( 'icon', 'title', 'description' ) ),
		'product_accordion_sections' => array( 'url_keys' => array(), 'text_keys' => array( 'id', 'title' ), 'html_keys' => array( 'html' ) ),
	);

	// Settings whose value is rich HTML content (policy pages, narrative
	// copy) — sanitize_text_field() would strip every tag, which is wrong
	// here; these go through wp_kses_post() instead, same allowlist post
	// content itself gets.
	const HTML_SETTINGS = array(
		'refund_cancellation_policy_html',
		'privacy_policy_html',
		'default_catalog_additional_information_html',
		'about_us_content_html',
	);

	/** Recursively sanitizes an arbitrarily-nested settings value: every
	 * string leaf through wp_kses_post() (keeps newlines, drops scripts),
	 * structure preserved. Used for the 'about_page' tree. */
	private static function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				$out[ is_string( $k ) ? sanitize_key( $k ) : $k ] = self::sanitize_deep( $v );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return wp_kses_post( $value );
		}
		return $value; // bool / int / float / null
	}

	private static function sanitize_repeater_rows( array $rows, array $shape ): array {
		$clean = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item = array();
			foreach ( $shape['text_keys'] as $key ) {
				$item[ $key ] = sanitize_text_field( $row[ $key ] ?? '' );
			}
			foreach ( $shape['url_keys'] as $key ) {
				$item[ $key ] = esc_url_raw( $row[ $key ] ?? '' );
			}
			foreach ( $shape['html_keys'] ?? array() as $key ) {
				$item[ $key ] = wp_kses_post( $row[ $key ] ?? '' );
			}
			$clean[] = $item;
		}
		return $clean;
	}

	public static function update_settings( WP_REST_Request $request ) {
		$allowed = array_keys( DRC_Settings::defaults() );
		$partial = array();
		foreach ( $allowed as $key ) {
			$value = $request->get_param( $key );
			if ( null === $value ) {
				continue;
			}
			if ( 'trending_product_ids' === $key && is_array( $value ) ) {
				// Ordered list of product IDs — cast to int and drop anything
				// that isn't a real, published product so a deleted/unpublished
				// rug can't linger in the curated list.
				$partial[ $key ] = array_values( array_filter( array_map( 'intval', $value ), function ( $id ) {
					return $id > 0 && 'publish' === get_post_status( $id ) && 'product' === get_post_type( $id );
				} ) );
			} elseif ( 'exchange_rates' === $key && is_array( $value ) ) {
				// Currency-code => rate map (see DRC_Settings::defaults()'s
				// comment) — values are floats, not text, unlike the other
				// array settings (contact_emails/contact_phones) this same
				// generic loop also handles.
				$partial[ $key ] = array_map( 'floatval', $value );
			} elseif ( 'storefront_menu_labels' === $key && is_array( $value ) ) {
				// {nav-item-key: label} — keys are internal identifiers
				// (header.php's own nav-item slugs), not user-facing text, so
				// only the values need sanitizing.
				$partial[ $key ] = array_map( 'sanitize_text_field', $value );
			} elseif ( 'about_page' === $key ) {
				// Structured, deeply-nested /about page content (the 'about'
				// field type in admin.js; still accepts a raw JSON string too).
				// Stored as-decoded so templates read it as a normal PHP array.
				// Every leaf string goes through wp_kses_post() — strips
				// scripts/dangerous markup but, unlike sanitize_text_field(),
				// keeps the newlines heading/body fields use for line breaks.
				$decoded = is_array( $value ) ? $value : json_decode( (string) $value, true );
				if ( is_array( $decoded ) ) {
					$partial[ $key ] = self::sanitize_deep( $decoded );
				}
			} elseif ( in_array( $key, self::HTML_SETTINGS, true ) ) {
				$partial[ $key ] = wp_kses_post( (string) $value );
			} elseif ( isset( self::REPEATER_SETTINGS[ $key ] ) && is_array( $value ) ) {
				$partial[ $key ] = self::sanitize_repeater_rows( $value, self::REPEATER_SETTINGS[ $key ] );
			} else {
				$partial[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : ( is_bool( $value ) ? $value : ( is_numeric( $value ) ? (float) $value : sanitize_text_field( $value ) ) );
			}
		}
		return new WP_REST_Response( DRC_Settings::update( $partial ), 200 );
	}

	// ── Trade enquiries ─────────────────────────────────────────────────

	public static function list_trade_enquiries() {
		return new WP_REST_Response( DRC_Content_Types::get_trade_enquiries(), 200 );
	}

	public static function mark_trade_enquiry_read( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( 'drc_trade_enquiry' !== get_post_type( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Enquiry not found', array( 'status' => 404 ) );
		}
		update_post_meta( $id, 'is_read', true );
		return new WP_REST_Response( DRC_Content_Types::get_trade_enquiry( $id ), 200 );
	}

	public static function delete_trade_enquiry( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( 'drc_trade_enquiry' !== get_post_type( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Enquiry not found', array( 'status' => 404 ) );
		}
		wp_delete_post( $id, true );
		return new WP_REST_Response( null, 204 );
	}

	// ── Homepage enquiries ────────────────────────────────────────────────

	public static function list_homepage_enquiries() {
		return new WP_REST_Response( DRC_Content_Types::get_homepage_enquiries(), 200 );
	}

	public static function mark_homepage_enquiry_read( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( 'drc_homepage_enquiry' !== get_post_type( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Enquiry not found', array( 'status' => 404 ) );
		}
		update_post_meta( $id, 'is_read', true );
		return new WP_REST_Response( DRC_Content_Types::get_homepage_enquiry( $id ), 200 );
	}

	public static function delete_homepage_enquiry( WP_REST_Request $request ) {
		$id = (int) $request->get_param( 'id' );
		if ( 'drc_homepage_enquiry' !== get_post_type( $id ) ) {
			return new WP_Error( 'drc_not_found', 'Enquiry not found', array( 'status' => 404 ) );
		}
		wp_delete_post( $id, true );
		return new WP_REST_Response( null, 204 );
	}
}
