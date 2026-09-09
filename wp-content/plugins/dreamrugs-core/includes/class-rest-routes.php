<?php
/**
 * REST routes under /wp-json/dreamrugs/v1/... — only for concepts with no
 * native WooCommerce/WP REST equivalent (quotes, materials pricing). See
 * the migration plan's "Custom plugin: dreamrugs-core" section: catalog
 * browsing, coupons, cart/checkout, orders, and CMS content should all use
 * WooCommerce's/WP's own native REST surface instead of parallel routes.
 *
 * Phase-2 scope: the customer-facing quote lifecycle (estimate, submit,
 * list-own, accept/reject/negotiate/request-review) is fully wired since
 * the quote engine is already ported. Vendor-side quote management
 * (send/revise/adjust-price/sample-images) and the dashboard-stats
 * aggregation are registered but stubbed — Phase 5 work per the plan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Rest_Routes {

	const NS = 'dreamrugs/v1';

	public static function register() {
		// Mirrors GET /customer/materials — a minimal public id/name list so
		// the custom-rug-request form's Material Preference dropdown reflects
		// the vendor's own Material table instead of a fixed guess (the same
		// principle already applied to the catalog's material/pile/weave
		// filters — see archive-product.php).
		register_rest_route( self::NS, '/materials', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_materials_public' ),
			'permission_callback' => '__return_true',
		) );

		// Mirrors GET /customer/faqs?rug_id= — ported from the original app's
		// FAQ model (global ∪ per-rug merge, see
		// DRC_Content_Types::get_faqs_for_product()).
		register_rest_route( self::NS, '/faqs', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_faqs_public' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/quotes/estimate', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'estimate' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/quotes', array(
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_quote' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_my_quotes' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
			),
		) );

		foreach ( array( 'accept', 'reject', 'negotiate', 'request-review' ) as $action ) {
			register_rest_route( self::NS, '/quotes/(?P<id>\d+)/' . $action, array(
				'methods'             => 'PATCH',
				'callback'            => array( __CLASS__, 'transition_quote' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
				// The (?P<id>\d+) route regex already guarantees a numeric id
				// before this ever runs, but a bare 'is_numeric' string here
				// is a real footgun: WP calls validate_callback with 3 args
				// ($value, $request, $param), and is_numeric() as a raw PHP
				// builtin only accepts 1 — PHP 8 throws a hard
				// ArgumentCountError fatal on every request to these routes.
				// Confirmed live: this broke accept/reject/negotiate/
				// request-review entirely until wrapped below.
				'args'                => array( 'id' => array( 'validate_callback' => fn( $param ) => is_numeric( $param ) ) ),
			) );
		}

		register_rest_route( self::NS, '/currency-config', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'currency_config' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/newsletter-subscribe', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'newsletter_subscribe' ),
			'permission_callback' => '__return_true',
		) );

		// Deliberately unauthenticated — trade/design partners submit this
		// before any account exists. Mirrors /customer/trade-enquiries in
		// the original app (see class-content-types.php's drc_trade_enquiry
		// registration for the stored shape).
		register_rest_route( self::NS, '/trade-enquiry', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_trade_enquiry' ),
			'permission_callback' => '__return_true',
		) );

		// Homepage contact-form submissions — mirrors POST
		// /api/customer/homepage-enquiries in the original app (see
		// class-content-types.php's drc_homepage_enquiry registration).
		register_rest_route( self::NS, '/homepage-enquiry', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_homepage_enquiry' ),
			'permission_callback' => '__return_true',
		) );

		// Deliberately unauthenticated (guests submit reference images before
		// creating an account) — mirrors submit_custom_rug_request()'s design
		// in the original app exactly.
		register_rest_route( self::NS, '/custom-rug-request/upload-image', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'upload_custom_rug_request_image' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( self::NS, '/custom-rug-request', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'submit_custom_rug_request' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( self::NS, '/room-preview', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'room_preview' ),
			'permission_callback' => '__return_true',
		) );

		// Data-migration bulk-import routes (Phase 9) — authenticated as a WP
		// Application Password user with manage_woocommerce, same as the
		// admin routes below. Idempotent on legacy_id (see class-activator.php's
		// UNIQUE KEY legacy_id) — a re-run of the importer gets 409s for rows
		// already present rather than duplicating them.
		register_rest_route( self::NS, '/admin/import/materials', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_material' ),
			'permission_callback' => array( __CLASS__, 'require_manage_woocommerce' ),
		) );

		// wp_drc_quotes has no native WooCommerce surface, so the importer
		// writes quotes through here — same idempotency contract as
		// import_material (UNIQUE KEY legacy_quote_id → 409 on re-run).
		register_rest_route( self::NS, '/admin/import/quotes', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_quote' ),
			'permission_callback' => array( __CLASS__, 'require_manage_woocommerce' ),
		) );

		// Customers: WooCommerce's own /wc/v3/customers can't take a
		// pre-computed password hash (it always re-hashes), so registered
		// customers with a legacy bcrypt hash come through here — the row's
		// user_pass is written verbatim and DRC_Legacy_Password verifies it
		// on first login, then upgrades it. Idempotent on the `_legacy_customer_id`
		// user-meta back-reference.
		register_rest_route( self::NS, '/admin/import/customers', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_customer' ),
			'permission_callback' => array( __CLASS__, 'require_manage_woocommerce' ),
		) );

		// Orders: /wc/v3/orders recomputes its own total from line items and
		// tax, which can't reproduce an already-agreed inclusive total. This
		// route uses wc_create_order() + $order->set_total() explicitly
		// (CLAUDE.md's canonical-total rule) and records CGST/SGST/IGST as
		// order meta rather than WC tax lines. Idempotent on `_legacy_order_id`.
		register_rest_route( self::NS, '/admin/import/orders', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'import_order' ),
			'permission_callback' => array( __CLASS__, 'require_manage_woocommerce' ),
		) );

		// Vendor/admin quote management, dashboard stats, inventory, pricing
		// rules, and settings all live in DRC_Admin_Rest_Routes now (Phase 5
		// — see class-admin-rest-routes.php).
	}

	// ── Permission callbacks ────────────────────────────────────────────

	public static function require_login(): bool {
		return is_user_logged_in();
	}

	public static function require_manage_woocommerce(): bool {
		return current_user_can( 'manage_woocommerce' );
	}

	// ── Handlers ────────────────────────────────────────────────────────

	public static function estimate( WP_REST_Request $request ) {
		$product_id  = (int) $request->get_param( 'product_id' );
		$size_w      = (float) $request->get_param( 'size_w' );
		$size_h      = (float) $request->get_param( 'size_h' );
		$material_id = (int) $request->get_param( 'material_id' );
		$qty         = (int) ( $request->get_param( 'qty' ) ?: 1 );
		$rush_order  = (bool) $request->get_param( 'rush_order' );
		$shape       = $request->get_param( 'shape' ) ?: 'rect';

		$rug = DRC_Quote_Engine::get_rug_for_pricing( $product_id );
		if ( ! $rug ) {
			return new WP_Error( 'drc_not_found', 'Rug product not found or is not a variable product.', array( 'status' => 404 ) );
		}

		$engine = new DRC_Quote_Engine();
		$result = $engine->calculate_quote( $rug, $size_w, $size_h, $material_id, $qty, $rush_order, null, null, null, $shape );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'drc_estimate_failed', $result['error'], array( 'status' => 400 ) );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Submits a new quote request — either against a catalog product
	 * (product_id set) or a bespoke custom-rug request (is_custom_request,
	 * no product_id, custom_size_w/h instead). Mirrors the original app's
	 * /customer/request-quote and /customer/custom-rug-request, merged into
	 * one row shape since wp_drc_quotes (like the original Quote model)
	 * handles both cases in a single table.
	 */
	public static function create_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';

		$user_id    = get_current_user_id();
		$product_id = $request->get_param( 'product_id' ) ? (int) $request->get_param( 'product_id' ) : null;

		// Mirrors backend/app/api/routes/customer.py's request_quote() validation
		// exactly: if the rug has any color options defined, the submitted
		// selected_color must be one of them (empty/no selection is only valid
		// when the rug has no color options at all).
		$selected_color = sanitize_text_field( $request->get_param( 'selected_color' ) ?: '' );
		if ( $product_id ) {
			$available_colors = wp_list_pluck( DRC_Color_Options::get( $product_id ), 'name' );
			if ( ! empty( $available_colors ) && ! in_array( $selected_color, $available_colors, true ) ) {
				return new WP_Error( 'drc_invalid_color', 'Select an available color for this rug.', array( 'status' => 400 ) );
			}
		}

		$data = array(
			'customer_user_id'         => $user_id ?: null,
			'guest_name'               => $user_id ? null : sanitize_text_field( $request->get_param( 'name' ) ),
			'guest_email'              => $user_id ? null : sanitize_email( $request->get_param( 'email' ) ),
			'product_id'               => $product_id,
			'custom_size_w'            => $request->get_param( 'size_w' ) ? (float) $request->get_param( 'size_w' ) : null,
			'custom_size_h'            => $request->get_param( 'size_h' ) ? (float) $request->get_param( 'size_h' ) : null,
			'rug_shape'                => $request->get_param( 'shape' ) ?: 'rect',
			'qty'                      => (int) ( $request->get_param( 'qty' ) ?: 1 ),
			'rush_order'               => (bool) $request->get_param( 'rush_order' ) ? 1 : 0,
			'selected_color'           => '' !== $selected_color ? $selected_color : null,
			'status'                   => 'draft',
			'notes'                    => sanitize_textarea_field( $request->get_param( 'notes' ) ?: '' ),
			'is_custom_request'        => $request->get_param( 'is_custom_request' ) ? 1 : 0,
			'room_type'                => sanitize_text_field( $request->get_param( 'room_type' ) ?: '' ),
			'material_preference'      => sanitize_text_field( $request->get_param( 'material_preference' ) ?: '' ),
			'budget_range'             => sanitize_text_field( $request->get_param( 'budget_range' ) ?: '' ),
			'expected_delivery'        => sanitize_text_field( $request->get_param( 'expected_delivery' ) ?: '' ),
			'request_group_id'         => sanitize_text_field( $request->get_param( 'request_group_id' ) ?: '' ),
			'reference_image_urls'     => wp_json_encode( array_map( 'esc_url_raw', (array) $request->get_param( 'reference_image_urls' ) ) ),
			'created_at'               => current_time( 'mysql' ),
		);

		if ( ! $user_id && ( ! $data['guest_name'] || ! $data['guest_email'] ) ) {
			return new WP_Error( 'drc_missing_contact', 'Name and email are required.', array( 'status' => 400 ) );
		}

		$inserted = $wpdb->insert( $table, $data );
		if ( ! $inserted ) {
			return new WP_Error( 'drc_insert_failed', 'Could not save the quote request.', array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'quote_id' => $wpdb->insert_id ), 201 );
	}

	/**
	 * Mirrors the original app's /customer/quotes list — enriched with
	 * product name/image (unlike a bare SELECT *) since the My Quotes page
	 * has no other way to know what rug a quote is for. Supports the same
	 * filter/sort query params as CustomerMyQuotes.tsx (status, sort_by,
	 * date_from/to) via plain query args, matching this theme's established
	 * "full-page-load filtering" convention rather than a client SPA.
	 */
	public static function list_my_quotes( WP_REST_Request $request ) {
		global $wpdb;
		$table       = $wpdb->prefix . 'drc_quotes';
		$user_id     = get_current_user_id();
		$status      = $request->get_param( 'status' );
		$sort_by     = $request->get_param( 'sort_by' ) ?: 'date_desc';
		$date_from   = $request->get_param( 'date_from' );
		$date_to     = $request->get_param( 'date_to' );

		$where  = array( $wpdb->prepare( 'customer_user_id = %d', $user_id ) );
		if ( $status && 'all' !== $status ) {
			$where[] = $wpdb->prepare( 'status = %s', $status );
		}
		if ( $date_from ) {
			$where[] = $wpdb->prepare( 'created_at >= %s', $date_from . ' 00:00:00' );
		}
		if ( $date_to ) {
			$where[] = $wpdb->prepare( 'created_at <= %s', $date_to . ' 23:59:59' );
		}

		$order_map = array(
			'date_desc'  => 'created_at DESC',
			'date_asc'   => 'created_at ASC',
			'price_desc' => 'final_price DESC',
			'price_asc'  => 'final_price ASC',
		);
		$order_by = $order_map[ $sort_by ] ?? $order_map['date_desc'];

		$sql  = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order_by}";
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value above went through wpdb->prepare() individually; $order_by is from the fixed $order_map, not user input.

		$action_needed = 0;
		$items         = array_map( function ( $row ) use ( &$action_needed ) {
			if ( 'sent' === $row['status'] ) {
				++$action_needed;
			}
			return self::format_quote_for_customer( $row );
		}, $rows );

		return new WP_REST_Response( array(
			'items'         => $items,
			'total'         => count( $items ),
			'action_needed' => $action_needed,
		), 200 );
	}

	/** Shapes a wp_drc_quotes row into the CustomerQuote-like structure the
	 * My Quotes page expects, resolving the linked product's name/image
	 * (or a "Custom Rug Request" label when there's no catalog product). */
	private static function format_quote_for_customer( array $q ): array {
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
		$settings = DRC_Settings::all();

		return array(
			'quote_id'                => (int) $q['id'],
			'status'                  => $q['status'],
			'rug_name'                => $rug_name,
			'rug_image_url'           => $rug_image,
			'rug_id'                  => $q['product_id'] ? (int) $q['product_id'] : null,
			'qty'                     => (int) $q['qty'],
			'size_w'                  => $q['custom_size_w'] !== null ? (float) $q['custom_size_w'] : null,
			'size_h'                  => $q['custom_size_h'] !== null ? (float) $q['custom_size_h'] : null,
			'base_price'              => $q['base_price'] !== null ? (float) $q['base_price'] : null,
			'final_price'             => $q['final_price'] !== null ? (float) $q['final_price'] : null,
			'pre_gst_price'           => null,
			'gst_amount'              => null,
			'gst_pct'                 => $q['gst_pct'] !== null ? (float) $q['gst_pct'] : null,
			'gst_inclusive'           => false,
			'price_currency'          => $q['price_currency'] ?: $settings['currency'],
			'rush_order'              => (bool) $q['rush_order'],
			'selected_color'          => $q['selected_color'] ?: null,
			'notes'                   => $q['notes'],
			'vendor_notes'            => $q['vendor_notes'],
			'customer_response_notes' => $q['customer_response_notes'],
			'manual_discount_pct'     => $q['manual_discount_pct'] !== null ? (float) $q['manual_discount_pct'] : null,
			'created_at'              => $q['created_at'] ? gmdate( 'd M Y', strtotime( $q['created_at'] ) ) : null,
			'has_order'               => ! empty( $q['wc_order_id'] ),
			'order_id'                => $q['wc_order_id'] ? (int) $q['wc_order_id'] : null,
			'review_request_count'    => (int) $q['review_request_count'],
			'lead_time_days'          => $q['expected_delivery_days'] !== null ? (int) $q['expected_delivery_days'] : null,
			'is_custom_request'       => (bool) $q['is_custom_request'],
			'room_type'               => $q['room_type'],
			'material_preference'     => $q['material_preference'],
			'budget_range'            => $q['budget_range'],
			'reference_image_urls'    => $q['reference_image_urls'] ? json_decode( $q['reference_image_urls'], true ) : null,
			'vendor_sample_image_urls' => $q['vendor_sample_image_urls'] ? json_decode( $q['vendor_sample_image_urls'], true ) : null,
		);
	}

	/**
	 * The quote-negotiation state machine, mirroring
	 * customer.py's accept_quote/reject_quote/negotiate_quote exactly:
	 *   - accept/reject/negotiate only valid when status === "sent"
	 *   - accept refuses if an order already exists for the quote
	 *   - negotiate resets status to "draft" (routes back through the
	 *     vendor's normal review screen, no separate negotiation queue)
	 *   - request-review only valid when status === "sent", increments
	 *     review_request_count rather than changing status
	 *   - "accept" itself only updates status here; WooCommerce order
	 *     creation from an accepted quote is Phase 6 (checkout) work.
	 */
	public static function transition_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'drc_quotes';
		$quote_id = (int) $request->get_param( 'id' );
		$action   = $request->get_route();
		$action   = substr( $action, strrpos( $action, '/' ) + 1 );

		$quote = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $quote_id ), ARRAY_A );
		if ( ! $quote ) {
			return new WP_Error( 'drc_not_found', 'Quote not found.', array( 'status' => 404 ) );
		}
		if ( (int) $quote['customer_user_id'] !== get_current_user_id() ) {
			return new WP_Error( 'drc_forbidden', 'This quote does not belong to you.', array( 'status' => 403 ) );
		}

		if ( in_array( $action, array( 'accept', 'reject', 'negotiate' ), true ) && $quote['status'] !== 'sent' ) {
			return new WP_Error( 'drc_invalid_state', 'This quote is not awaiting your response.', array( 'status' => 409 ) );
		}

		switch ( $action ) {
			case 'accept':
				if ( ! empty( $quote['wc_order_id'] ) ) {
					return new WP_Error( 'drc_already_ordered', 'An order already exists for this quote.', array( 'status' => 409 ) );
				}
				$order = DRC_Order_Creator::create_from_quote( $quote );
				if ( is_wp_error( $order ) ) {
					return new WP_Error( 'drc_order_creation_failed', $order->get_error_message(), array( 'status' => 400 ) );
				}
				$wpdb->update( $table, array( 'status' => 'accepted' ), array( 'id' => $quote_id ) );
				// The frontend redirects the customer straight to paying for
				// THIS order, not the generic cart-based checkout (which
				// would checkout whatever's in their cart, not this quote).
				$pay_url = $order->get_checkout_payment_url();
				break;

			case 'reject':
				$wpdb->update( $table, array(
					'status'                  => 'rejected',
					'customer_response_notes' => sanitize_textarea_field( $request->get_param( 'reason' ) ?: '' ),
				), array( 'id' => $quote_id ) );
				break;

			case 'negotiate':
				// No dedicated "counter-offer" columns exist yet (the
				// vendor-side quote admin screen that would read them is
				// Phase 5 work) — fold the structured counter-offer into a
				// readable summary in customer_response_notes, the same
				// field the vendor already reviews for a decline reason.
				$parts = array();
				if ( null !== $request->get_param( 'proposed_price' ) ) {
					$parts[] = sprintf( 'Proposed price: %s', sanitize_text_field( $request->get_param( 'proposed_price' ) ) );
				}
				if ( null !== $request->get_param( 'proposed_qty' ) ) {
					$parts[] = sprintf( 'Proposed qty: %d', (int) $request->get_param( 'proposed_qty' ) );
				}
				if ( $request->get_param( 'remove_rush' ) ) {
					$parts[] = 'Requests rush fee removed';
				}
				if ( null !== $request->get_param( 'requested_lead_days' ) ) {
					$parts[] = sprintf( 'Requested delivery: %d days', (int) $request->get_param( 'requested_lead_days' ) );
				}
				$message = sanitize_textarea_field( $request->get_param( 'message' ) ?: '' );
				if ( $message ) {
					$parts[] = $message;
				}
				$wpdb->update( $table, array(
					'status'                  => 'draft',
					'customer_response_notes' => implode( ' | ', $parts ),
				), array( 'id' => $quote_id ) );
				break;

			case 'request-review':
				if ( $quote['status'] !== 'sent' ) {
					return new WP_Error( 'drc_invalid_state', 'This quote is not awaiting your response.', array( 'status' => 409 ) );
				}
				$wpdb->update( $table, array(
					'review_request_count' => (int) $quote['review_request_count'] + 1,
				), array( 'id' => $quote_id ) );
				break;
		}

		$updated  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $quote_id ), ARRAY_A );
		$response = self::format_quote_for_customer( $updated );
		if ( isset( $pay_url ) ) {
			$response['pay_url'] = $pay_url;
		}
		return new WP_REST_Response( $response, 200 );
	}

	/** Proxies to the room-visualizer sidecar service over the Docker-internal
	 * network — see room-visualizer/main.py. */
	public static function room_preview( WP_REST_Request $request ) {
		$rug_image_url = $request->get_param( 'rug_image_url' );
		$opacity       = $request->get_param( 'opacity' ) ?: '0.90';
		$base_url      = defined( 'DRC_ROOM_VISUALIZER_URL' ) ? DRC_ROOM_VISUALIZER_URL : 'http://room-visualizer:8100';

		if ( ! $rug_image_url ) {
			$response = wp_remote_get( "{$base_url}/room-only", array( 'timeout' => 15 ) );
		} else {
			$url      = add_query_arg( array(
				'rug_image_url' => rawurlencode( $rug_image_url ),
				'opacity'       => $opacity,
			), "{$base_url}/compose" );
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'drc_visualizer_unreachable', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			return new WP_Error( 'drc_visualizer_error', wp_remote_retrieve_body( $response ), array( 'status' => $code ) );
		}

		header( 'Content-Type: image/jpeg' );
		echo wp_remote_retrieve_body( $response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary JPEG passthrough.
		exit;
	}

	/** Mirrors /customer/newsletter-subscribe. Stores into a simple option-backed
	 * list for now; a dedicated table/CPT is Phase 7 (CMS content types) work
	 * if the subscriber list grows large enough to need real querying. */
	public static function newsletter_subscribe( WP_REST_Request $request ) {
		$email = sanitize_email( $request->get_param( 'email' ) );
		if ( ! $email || ! is_email( $email ) ) {
			return new WP_Error( 'drc_invalid_email', 'A valid email is required.', array( 'status' => 400 ) );
		}
		$subscribers = get_option( 'drc_newsletter_subscribers', array() );
		if ( ! isset( $subscribers[ $email ] ) ) {
			$subscribers[ $email ] = array(
				'source'         => sanitize_text_field( $request->get_param( 'source' ) ?: '' ),
				'subscribed_at'  => current_time( 'mysql' ),
			);
			update_option( 'drc_newsletter_subscribers', $subscribers );
		}
		return new WP_REST_Response( array( 'status' => 'subscribed' ), 201 );
	}

	public static function list_materials_public() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}drc_materials WHERE is_available = 1 ORDER BY name ASC", ARRAY_A );
		return new WP_REST_Response( array_map( fn( $r ) => array( 'id' => (int) $r['id'], 'name' => $r['name'] ), $rows ), 200 );
	}

	public static function list_faqs_public( WP_REST_Request $request ) {
		$product_id = (int) ( $request->get_param( 'rug_id' ) ?: 0 );
		return new WP_REST_Response( DRC_Content_Types::get_faqs_for_product( $product_id ), 200 );
	}

	/** Mirrors /customer/trade-enquiries in the original app: validate,
	 * store as a drc_trade_enquiry post (class-content-types.php), then
	 * best-effort email the vendor — a delivery failure never fails the
	 * request, matching the original's try/except-and-continue behavior. */
	public static function create_trade_enquiry( WP_REST_Request $request ) {
		$required = array( 'first_name', 'last_name', 'email', 'phone', 'profession', 'company', 'project_brief' );
		$fields   = array();
		foreach ( $required as $key ) {
			$fields[ $key ] = trim( sanitize_text_field( $request->get_param( $key ) ?: '' ) );
			if ( '' === $fields[ $key ] ) {
				return new WP_Error( 'drc_missing_field', "The field \"$key\" is required.", array( 'status' => 422 ) );
			}
		}
		$email = sanitize_email( $fields['email'] );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'drc_invalid_email', 'A valid email is required.', array( 'status' => 422 ) );
		}
		$fields['email'] = $email;
		foreach ( array( 'city', 'country', 'website', 'project_type' ) as $key ) {
			$fields[ $key ] = trim( sanitize_text_field( $request->get_param( $key ) ?: '' ) );
		}

		$post_id = wp_insert_post( array(
			'post_type'   => 'drc_trade_enquiry',
			'post_status' => 'publish', // not public-facing (post type itself is public => false); this just means "not a draft"
			'post_title'  => $fields['first_name'] . ' ' . $fields['last_name'],
		), true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'drc_save_failed', 'Could not save this enquiry.', array( 'status' => 500 ) );
		}
		foreach ( $fields as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		update_post_meta( $post_id, 'is_read', false );

		self::notify_vendor_trade_enquiry( $fields );

		return new WP_REST_Response( array( 'message' => 'Enquiry received' ), 201 );
	}

	private static function notify_vendor_trade_enquiry( array $fields ): void {
		$to = DRC_Settings::get( 'vendor_notification_email' ) ?: get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$subject = sprintf( 'New trade enquiry from %s %s (%s)', $fields['first_name'], $fields['last_name'], $fields['company'] );
		$body    = "A new trade partner enquiry was submitted:\n\n"
			. "Name: {$fields['first_name']} {$fields['last_name']}\n"
			. "Email: {$fields['email']}\n"
			. "Phone: {$fields['phone']}\n"
			. "Profession: {$fields['profession']}\n"
			. "Company: {$fields['company']}\n"
			. ( $fields['website'] ? "Website: {$fields['website']}\n" : '' )
			. ( $fields['city'] || $fields['country'] ? 'Location: ' . trim( $fields['city'] . ' ' . $fields['country'] ) . "\n" : '' )
			. ( $fields['project_type'] ? "Project type: {$fields['project_type']}\n" : '' )
			. "\nProject brief:\n{$fields['project_brief']}\n\n"
			. 'View it in wp-admin under DreamRugs → Trade Enquiries.';
		wp_mail( $to, $subject, $body, array( 'Reply-To: ' . $fields['email'] ) );
	}

	/** Mirrors POST /customer/homepage-enquiries: validate (consent required,
	 * matching the original's 422 on missing consent), store as a
	 * drc_homepage_enquiry post, then best-effort email the vendor. */
	public static function create_homepage_enquiry( WP_REST_Request $request ) {
		if ( ! $request->get_param( 'consent' ) ) {
			return new WP_Error( 'drc_consent_required', 'Consent is required before sending an enquiry.', array( 'status' => 422 ) );
		}
		$fields = array();
		foreach ( array( 'name', 'subject', 'message' ) as $key ) {
			$fields[ $key ] = trim( sanitize_text_field( $request->get_param( $key ) ?: '' ) );
			if ( '' === $fields[ $key ] ) {
				return new WP_Error( 'drc_missing_field', "The field \"$key\" is required.", array( 'status' => 422 ) );
			}
		}
		$email = sanitize_email( $request->get_param( 'email' ) ?: '' );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'drc_invalid_email', 'A valid email is required.', array( 'status' => 422 ) );
		}
		$fields['email'] = $email;

		$post_id = wp_insert_post( array(
			'post_type'   => 'drc_homepage_enquiry',
			'post_status' => 'publish',
			'post_title'  => $fields['name'] . ' — ' . $fields['subject'],
		), true );
		if ( is_wp_error( $post_id ) ) {
			return new WP_Error( 'drc_save_failed', 'Could not save this enquiry.', array( 'status' => 500 ) );
		}
		foreach ( $fields as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		update_post_meta( $post_id, 'is_read', false );

		self::notify_vendor_homepage_enquiry( $fields );

		return new WP_REST_Response( array( 'message' => 'Enquiry received' ), 201 );
	}

	private static function notify_vendor_homepage_enquiry( array $fields ): void {
		$to = DRC_Settings::get( 'vendor_notification_email' ) ?: get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$subject = sprintf( 'New homepage enquiry from %s: %s', $fields['name'], $fields['subject'] );
		$body    = "A new homepage contact-form enquiry was submitted:\n\n"
			. "Name: {$fields['name']}\n"
			. "Email: {$fields['email']}\n"
			. "Subject: {$fields['subject']}\n\n"
			. "Message:\n{$fields['message']}\n\n"
			. 'View it in wp-admin under DreamRugs → Homepage Enquiries.';
		wp_mail( $to, $subject, $body, array( 'Reply-To: ' . $fields['email'] ) );
	}

	/**
	 * Ported from CurrencyContext.tsx — a display-only currency converter.
	 * base_currency is WooCommerce's own real transactional currency
	 * (get_woocommerce_currency()); the customer is always actually charged
	 * in that currency at checkout regardless of what this lets the
	 * storefront *show* them. exchange_rates gives "how many units of each
	 * currency equal 1 unit of base_currency", same semantics as the
	 * original's Tenant.exchange_rates dict.
	 */
	public static function currency_config() {
		$settings = DRC_Settings::all();
		return new WP_REST_Response( array(
			'base_currency'    => get_woocommerce_currency(),
			'default_currency' => $settings['currency'] ?: get_woocommerce_currency(),
			'exchange_rates'   => (object) ( $settings['exchange_rates'] ?: array() ),
			'currencies'       => array(
				array( 'code' => 'INR', 'label' => 'Indian Rupee (₹)', 'symbol' => '₹' ),
				array( 'code' => 'USD', 'label' => 'US Dollar ($)', 'symbol' => '$' ),
				array( 'code' => 'EUR', 'label' => 'Euro (€)', 'symbol' => '€' ),
				array( 'code' => 'GBP', 'label' => 'British Pound (£)', 'symbol' => '£' ),
				array( 'code' => 'CAD', 'label' => 'Canadian Dollar (CA$)', 'symbol' => 'CA$' ),
				array( 'code' => 'AUD', 'label' => 'Australian Dollar (A$)', 'symbol' => 'A$' ),
				array( 'code' => 'AED', 'label' => 'UAE Dirham (AED)', 'symbol' => 'AED' ),
				array( 'code' => 'SGD', 'label' => 'Singapore Dollar (S$)', 'symbol' => 'S$' ),
				array( 'code' => 'CHF', 'label' => 'Swiss Franc (CHF)', 'symbol' => 'CHF' ),
				array( 'code' => 'SAR', 'label' => 'Saudi Riyal (SAR)', 'symbol' => 'SAR' ),
				array( 'code' => 'QAR', 'label' => 'Qatari Riyal (QAR)', 'symbol' => 'QAR' ),
				array( 'code' => 'JPY', 'label' => 'Japanese Yen (¥)', 'symbol' => '¥' ),
				array( 'code' => 'NZD', 'label' => 'New Zealand Dollar (NZ$)', 'symbol' => 'NZ$' ),
				array( 'code' => 'ZAR', 'label' => 'South African Rand (R)', 'symbol' => 'R' ),
			),
		), 200 );
	}

	/** Bulk-import target for migration/migrate_to_woocommerce.py's
	 * migrate_materials() — idempotent on legacy_id. */
	public static function import_material( WP_REST_Request $request ) {
		global $wpdb;
		$table     = $wpdb->prefix . 'drc_materials';
		$legacy_id = (int) $request->get_param( 'legacy_id' );

		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE legacy_id = %d", $legacy_id ) );
		if ( $existing ) {
			// Include the id so a re-run of the importer can still rebuild its
			// legacy→new material-id map without a separate lookup.
			return new WP_Error( 'drc_already_imported', 'Material already imported.', array( 'status' => 409, 'id' => (int) $existing ) );
		}

		$inserted = $wpdb->insert( $table, array(
			'name'          => sanitize_text_field( $request->get_param( 'name' ) ),
			'type'          => sanitize_text_field( $request->get_param( 'type' ) ),
			'color'         => sanitize_text_field( $request->get_param( 'color' ) ),
			'stock_meters'  => (float) $request->get_param( 'stock_meters' ),
			'cost_per_sqm'  => (float) $request->get_param( 'cost_per_sqm' ),
			'cost_currency' => sanitize_text_field( $request->get_param( 'cost_currency' ) ?: '' ),
			'is_available'  => $request->get_param( 'is_available' ) ? 1 : 0,
			'legacy_id'     => $legacy_id,
		) );
		if ( ! $inserted ) {
			return new WP_Error( 'drc_insert_failed', 'Could not import material.', array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'id' => $wpdb->insert_id ), 201 );
	}

	/**
	 * Bulk-import target for migrate_to_woocommerce.py's
	 * migrate_quotes_and_orders() — idempotent on legacy_quote_id. The
	 * importer resolves every legacy foreign key to its new WP id before
	 * calling (rug_catalog_id → product_id/variation_id, material_id →
	 * wp_drc_materials.id, customer_id → wp_users.ID, revised_from_quote_id
	 * → the new wp_drc_quotes.id, walking quotes in ascending legacy id
	 * order); this route just writes the row. final_price is stored exactly
	 * as given — it is the already-inclusive canonical total, never
	 * recomputed (CLAUDE.md's money rule).
	 */
	public static function import_quote( WP_REST_Request $request ) {
		global $wpdb;
		$table    = $wpdb->prefix . 'drc_quotes';
		$legacy   = (int) $request->get_param( 'legacy_quote_id' );

		if ( ! $legacy ) {
			return new WP_Error( 'drc_bad_request', 'legacy_quote_id is required.', array( 'status' => 400 ) );
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE legacy_quote_id = %d", $legacy ) );
		if ( $existing ) {
			return new WP_Error( 'drc_already_imported', 'Quote already imported.', array( 'status' => 409, 'id' => (int) $existing ) );
		}

		// nullable helpers: keep NULLs as NULL rather than coercing to 0/'' .
		$num  = function ( $key ) use ( $request ) {
			$v = $request->get_param( $key );
			return ( $v === null || $v === '' ) ? null : 0 + $v;
		};
		$int  = function ( $key ) use ( $request ) {
			$v = $request->get_param( $key );
			return ( $v === null || $v === '' ) ? null : (int) $v;
		};
		$str  = function ( $key ) use ( $request ) {
			$v = $request->get_param( $key );
			return ( $v === null ) ? null : sanitize_text_field( (string) $v );
		};
		$json = function ( $key ) use ( $request ) {
			$v = $request->get_param( $key );
			return is_array( $v ) && $v ? wp_json_encode( array_map( 'esc_url_raw', $v ) ) : null;
		};

		$created_at = $request->get_param( 'created_at' );
		$created_at = $created_at ? gmdate( 'Y-m-d H:i:s', strtotime( $created_at ) ) : current_time( 'mysql', true );

		$data = array(
			'customer_user_id'         => $int( 'customer_user_id' ),
			'guest_name'               => $str( 'guest_name' ),
			'guest_email'              => is_email( (string) $request->get_param( 'guest_email' ) ) ? sanitize_email( $request->get_param( 'guest_email' ) ) : null,
			'product_id'               => $int( 'product_id' ),
			'variation_id'             => $int( 'variation_id' ),
			'custom_size_w'            => $num( 'custom_size_w' ),
			'custom_size_h'            => $num( 'custom_size_h' ),
			'rug_shape'                => $str( 'rug_shape' ) ?: 'rect',
			'material_id'              => $int( 'material_id' ),
			'qty'                      => $int( 'qty' ) ?: 1,
			'base_price'               => $num( 'base_price' ),
			'final_price'              => $num( 'final_price' ),
			'rush_order'               => $request->get_param( 'rush_order' ) ? 1 : 0,
			'selected_color'           => $str( 'selected_color' ),
			'price_currency'           => $str( 'price_currency' ),
			'margin_pct'               => $num( 'margin_pct' ),
			'gst_pct'                  => $num( 'gst_pct' ),
			'manual_discount_pct'      => $num( 'manual_discount_pct' ),
			'shipping_cost'            => $num( 'shipping_cost' ),
			'expected_delivery_days'   => $int( 'expected_delivery_days' ),
			'status'                   => $str( 'status' ) ?: 'draft',
			'notes'                    => $request->get_param( 'notes' ) !== null ? wp_kses_post( $request->get_param( 'notes' ) ) : null,
			'vendor_notes'             => $request->get_param( 'vendor_notes' ) !== null ? wp_kses_post( $request->get_param( 'vendor_notes' ) ) : null,
			'customer_response_notes'  => $request->get_param( 'customer_response_notes' ) !== null ? wp_kses_post( $request->get_param( 'customer_response_notes' ) ) : null,
			'review_request_count'     => $int( 'review_request_count' ) ?: 0,
			'is_custom_request'        => $request->get_param( 'is_custom_request' ) ? 1 : 0,
			'room_type'                => $str( 'room_type' ),
			'material_preference'      => $str( 'material_preference' ),
			'budget_range'             => $str( 'budget_range' ),
			'expected_delivery'        => $str( 'expected_delivery' ),
			'request_group_id'         => $str( 'request_group_id' ),
			'reference_image_urls'     => $json( 'reference_image_urls' ),
			'vendor_sample_image_urls' => $json( 'vendor_sample_image_urls' ),
			'revised_from_quote_id'    => $int( 'revised_from_quote_id' ),
			'wc_order_id'              => $int( 'wc_order_id' ),
			'legacy_quote_id'          => $legacy,
			'created_at'               => $created_at,
		);

		if ( ! $wpdb->insert( $table, $data ) ) {
			return new WP_Error( 'drc_insert_failed', 'Could not import quote.', array( 'status' => 500 ) );
		}
		return new WP_REST_Response( array( 'id' => $wpdb->insert_id ), 201 );
	}

	/**
	 * Bulk-import target for migrate_to_woocommerce.py's migrate_customers()
	 * — a registered customer (source `hashed_password` not null). Creates a
	 * WP user with role `customer`, writes the billing meta WooCommerce
	 * expects, and — the reason this can't use /wc/v3/customers — sets
	 * user_pass to the legacy bcrypt hash verbatim so DRC_Legacy_Password
	 * can verify and transparently upgrade it on first login. Idempotent on
	 * the `_legacy_customer_id` meta: a re-run returns 409. Guest customers
	 * are not sent here — they import as order/quote metadata only.
	 */
	public static function import_customer( WP_REST_Request $request ) {
		global $wpdb;
		$legacy_id = (int) $request->get_param( 'legacy_customer_id' );
		$email     = sanitize_email( (string) $request->get_param( 'email' ) );

		if ( ! $legacy_id || ! is_email( $email ) ) {
			return new WP_Error( 'drc_bad_request', 'legacy_customer_id and a valid email are required.', array( 'status' => 400 ) );
		}

		$already = get_users( array(
			'meta_key'   => '_legacy_customer_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time importer, not a request-path query.
			'meta_value' => $legacy_id,             // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'number'     => 1,
			'fields'     => 'ID',
		) );
		if ( $already ) {
			return new WP_Error( 'drc_already_imported', 'Customer already imported.', array( 'status' => 409, 'user_id' => (int) $already[0] ) );
		}

		// An account may already exist at this email (e.g. created by an
		// earlier order import) — adopt it rather than failing.
		$user_id = email_exists( $email );
		if ( ! $user_id ) {
			$user_id = wp_insert_user( array(
				'user_login'   => $email,
				'user_email'   => $email,
				'user_pass'    => wp_generate_password( 24 ), // replaced below with the legacy hash
				'display_name' => sanitize_text_field( (string) $request->get_param( 'name' ) ),
				'role'         => 'customer',
			) );
			if ( is_wp_error( $user_id ) ) {
				return new WP_Error( 'drc_insert_failed', $user_id->get_error_message(), array( 'status' => 500 ) );
			}
		}

		$legacy_hash = (string) $request->get_param( 'hashed_password' );
		if ( $legacy_hash !== '' && preg_match( '/^\$2[aby]\$/', $legacy_hash ) ) {
			// Straight to the column — wp_set_password() would phpass-hash it.
			$wpdb->update( $wpdb->users, array( 'user_pass' => $legacy_hash ), array( 'ID' => $user_id ) );
			clean_user_cache( $user_id );
		}

		$name  = trim( (string) $request->get_param( 'name' ) );
		$parts = preg_split( '/\s+/', $name, 2 );
		$meta  = array(
			'_legacy_customer_id' => $legacy_id,
			'first_name'          => $parts[0] ?? '',
			'last_name'           => $parts[1] ?? '',
			'billing_first_name'  => $parts[0] ?? '',
			'billing_last_name'   => $parts[1] ?? '',
			'billing_email'       => $email,
			'billing_phone'       => sanitize_text_field( (string) $request->get_param( 'phone' ) ),
			'billing_company'     => sanitize_text_field( (string) $request->get_param( 'company' ) ),
			'billing_address_1'   => sanitize_text_field( (string) $request->get_param( 'address' ) ),
			'billing_country'     => sanitize_text_field( (string) ( $request->get_param( 'country' ) ?: 'India' ) ),
			'drc_gstin'           => sanitize_text_field( (string) $request->get_param( 'gstin' ) ),
			'drc_state_code'      => sanitize_text_field( (string) $request->get_param( 'state_code' ) ),
			'drc_account_type'    => sanitize_text_field( (string) ( $request->get_param( 'account_type' ) ?: 'retail' ) ),
			'drc_is_export_buyer' => $request->get_param( 'is_export_buyer' ) ? '1' : '0',
		);
		foreach ( $meta as $k => $v ) {
			update_user_meta( $user_id, $k, $v );
		}

		return new WP_REST_Response( array( 'user_id' => (int) $user_id ), 201 );
	}

	/**
	 * Bulk-import target for migrate_to_woocommerce.py's
	 * migrate_quotes_and_orders() — one source Order. Uses wc_create_order()
	 * and sets the total EXPLICITLY from the source total_amount (never
	 * recomputed from line items / WC tax — CLAUDE.md's money rule and a
	 * real historical double-charge bug). GST split is written as order meta
	 * for the invoice generator, not as WC tax lines (WooCommerce's tax
	 * classes are zeroed — see the migration plan). Idempotent on the
	 * `_legacy_order_id` meta.
	 */
	public static function import_order( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_create_order' ) ) {
			return new WP_Error( 'drc_no_woo', 'WooCommerce not active.', array( 'status' => 500 ) );
		}
		$legacy_id = (int) $request->get_param( 'legacy_order_id' );
		if ( ! $legacy_id ) {
			return new WP_Error( 'drc_bad_request', 'legacy_order_id is required.', array( 'status' => 400 ) );
		}

		$existing = wc_get_orders( array(
			'limit'      => 1,
			'return'     => 'ids',
			'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-time importer, HPOS-safe form.
				array( 'key' => '_legacy_order_id', 'value' => $legacy_id ),
			),
		) );
		if ( $existing ) {
			return new WP_Error( 'drc_already_imported', 'Order already imported.', array( 'status' => 409, 'order_id' => (int) $existing[0] ) );
		}

		$order = wc_create_order();
		if ( is_wp_error( $order ) ) {
			return new WP_Error( 'drc_insert_failed', $order->get_error_message(), array( 'status' => 500 ) );
		}

		$customer_user_id = (int) $request->get_param( 'customer_user_id' );
		if ( $customer_user_id ) {
			$order->set_customer_id( $customer_user_id );
		}

		$currency = sanitize_text_field( (string) $request->get_param( 'price_currency' ) ) ?: get_woocommerce_currency();
		$order->set_currency( $currency );

		// Line items: one non-taxable line per resolved product (or a single
		// generic line when the quote's rug didn't map). Line totals are
		// informational — the order total is force-set below regardless.
		$line_items = $request->get_param( 'line_items' );
		if ( is_array( $line_items ) && $line_items ) {
			foreach ( $line_items as $li ) {
				$product = ! empty( $li['product_id'] ) ? wc_get_product( (int) $li['product_id'] ) : null;
				$item    = new WC_Order_Item_Product();
				$item->set_name( $product ? $product->get_name() : ( sanitize_text_field( $li['name'] ?? 'Custom rug' ) ) );
				$item->set_quantity( max( 1, (int) ( $li['qty'] ?? 1 ) ) );
				if ( $product ) {
					$item->set_product_id( $product->get_id() );
				}
				$item->set_subtotal( (float) ( $li['total'] ?? 0 ) );
				$item->set_total( (float) ( $li['total'] ?? 0 ) );
				$order->add_item( $item );
			}
		} else {
			$item = new WC_Order_Item_Fee();
			$item->set_name( 'Imported order' );
			$item->set_amount( (float) $request->get_param( 'total_amount' ) );
			$item->set_total( (float) $request->get_param( 'total_amount' ) );
			$order->add_item( $item );
		}

		$shipping_address = sanitize_textarea_field( (string) $request->get_param( 'shipping_address' ) );
		if ( $shipping_address ) {
			$order->set_shipping_address_1( $shipping_address );
		}

		$order->set_created_via( 'drc-migration' );
		$status = sanitize_key( (string) $request->get_param( 'status' ) ) ?: 'processing';
		$order->set_status( $status );

		// Persist line items/calc first, THEN overwrite the total — order
		// matters, calculate_totals() would clobber set_total().
		$order->calculate_totals( false );
		$order->set_total( (float) $request->get_param( 'total_amount' ) );

		// Informational breakdown + GST meta for the invoice generator.
		foreach ( array(
			'_legacy_order_id'     => $legacy_id,
			'_drc_shipping_cost'   => $request->get_param( 'shipping_cost' ),
			'_drc_discount_amount' => $request->get_param( 'discount_amount' ),
			'_drc_promo_code'      => $request->get_param( 'promo_code' ),
			'_drc_gst_cgst'        => $request->get_param( 'cgst' ),
			'_drc_gst_sgst'        => $request->get_param( 'sgst' ),
			'_drc_gst_igst'        => $request->get_param( 'igst' ),
			'_drc_legacy_invoice_number' => $request->get_param( 'invoice_number' ),
		) as $k => $v ) {
			if ( $v !== null && $v !== '' ) {
				$order->update_meta_data( $k, is_scalar( $v ) ? sanitize_text_field( (string) $v ) : $v );
			}
		}

		$order->save();

		$created_at = $request->get_param( 'created_at' );
		if ( $created_at ) {
			$order->set_date_created( strtotime( $created_at ) );
			$order->save();
		}

		return new WP_REST_Response( array( 'order_id' => $order->get_id(), 'total' => $order->get_total() ), 201 );
	}

	/**
	 * Ported from upload_custom_rug_request_image() — deliberately
	 * unauthenticated (guests submit reference images before creating an
	 * account), so the upload itself must not be trusted at all: the
	 * client-supplied MIME type is trivially spoofable, and a
	 * filename-derived extension would let an attacker store a
	 * browser-executable file (.html/.svg) under the uploads directory.
	 * Decoding with GD and always re-encoding to .jpg closes both holes —
	 * anything that isn't real raster image data is rejected outright, the
	 * extension is never taken from client input, and the file never lands
	 * on disk under its original name.
	 */
	public static function upload_custom_rug_request_image( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || UPLOAD_ERR_OK !== $files['file']['error'] ) {
			return new WP_Error( 'drc_no_file', 'No file uploaded.', array( 'status' => 400 ) );
		}
		$file = $files['file'];
		if ( ! in_array( $file['type'], array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ), true ) ) {
			return new WP_Error( 'drc_bad_type', "Unsupported file type: {$file['type']}. Use JPEG, PNG, WebP, or GIF.", array( 'status' => 400 ) );
		}
		if ( $file['size'] > 10 * 1024 * 1024 ) {
			return new WP_Error( 'drc_too_large', 'File too large. Max 10MB allowed.', array( 'status' => 400 ) );
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading an already-validated PHP upload tmp file, not a remote/user-supplied path.
		$image    = $contents ? @imagecreatefromstring( $contents ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- deliberately treating any decode failure (corrupt/non-image data) as a clean 400, not a fatal.
		if ( ! $image ) {
			return new WP_Error( 'drc_bad_image', 'Could not decode image.', array( 'status' => 400 ) );
		}

		$upload_dir = wp_upload_dir();
		$subdir     = trailingslashit( $upload_dir['basedir'] ) . 'custom-requests';
		wp_mkdir_p( $subdir );
		$filename = wp_generate_uuid4() . '.jpg';
		imagejpeg( $image, trailingslashit( $subdir ) . $filename, 92 );
		imagedestroy( $image );

		return new WP_REST_Response( array( 'url' => trailingslashit( $upload_dir['baseurl'] ) . 'custom-requests/' . $filename ), 201 );
	}

	/**
	 * Ported from submit_custom_rug_request() — a bespoke (no catalog rug)
	 * request, one wp_drc_quotes row per rug in the submission, tied
	 * together with a shared request_group_id when there's more than one
	 * (mirrors the "combine and quote together" note on the original) and
	 * left null for a single-rug request, matching create_quote()'s
	 * existing convention for guest vs. logged-in ownership.
	 */
	public static function submit_custom_rug_request( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';

		$user_id = get_current_user_id();
		$name    = sanitize_text_field( $request->get_param( 'name' ) ?: '' );
		$email   = sanitize_email( $request->get_param( 'email' ) ?: '' );
		if ( ! $user_id && ( ! $name || ! $email || ! is_email( $email ) ) ) {
			return new WP_Error( 'drc_missing_contact', 'Name and email are required.', array( 'status' => 400 ) );
		}

		$items = (array) $request->get_param( 'items' );
		if ( empty( $items ) ) {
			return new WP_Error( 'drc_missing_items', 'At least one rug is required.', array( 'status' => 400 ) );
		}
		if ( count( $items ) > 10 ) {
			return new WP_Error( 'drc_too_many_items', 'A maximum of 10 rugs per request is allowed.', array( 'status' => 400 ) );
		}

		$group_id  = count( $items ) > 1 ? wp_generate_uuid4() : null;
		$quote_ids = array();

		foreach ( $items as $item ) {
			$size_w = isset( $item['size_w'] ) ? (float) $item['size_w'] : 0.0;
			$size_h = isset( $item['size_h'] ) ? (float) $item['size_h'] : 0.0;
			if ( $size_w <= 0 || $size_h <= 0 || $size_w > 50 || $size_h > 50 ) {
				return new WP_Error( 'drc_invalid_size', 'Each rug needs a valid approximate size.', array( 'status' => 400 ) );
			}
			$inserted = $wpdb->insert( $table, array(
				'customer_user_id'     => $user_id ?: null,
				'guest_name'           => $user_id ? null : $name,
				'guest_email'          => $user_id ? null : $email,
				'custom_size_w'        => $size_w,
				'custom_size_h'        => $size_h,
				'qty'                  => isset( $item['qty'] ) ? max( 1, (int) $item['qty'] ) : 1,
				'status'               => 'draft',
				'notes'                => sanitize_textarea_field( $item['notes'] ?? '' ) ?: null,
				'is_custom_request'    => 1,
				'room_type'            => sanitize_text_field( $item['room_type'] ?? '' ) ?: null,
				'material_preference'  => sanitize_text_field( $item['material_preference'] ?? '' ) ?: null,
				'budget_range'         => sanitize_text_field( $item['budget_range'] ?? '' ) ?: null,
				'expected_delivery'    => sanitize_text_field( $item['expected_delivery'] ?? '' ) ?: null,
				'reference_image_urls' => ! empty( $item['reference_image_urls'] ) ? wp_json_encode( array_map( 'esc_url_raw', (array) $item['reference_image_urls'] ) ) : null,
				'request_group_id'     => $group_id,
				'created_at'           => current_time( 'mysql' ),
			) );
			if ( ! $inserted ) {
				return new WP_Error( 'drc_insert_failed', 'Could not save one of the rug requests.', array( 'status' => 500 ) );
			}
			$quote_ids[] = $wpdb->insert_id;
		}

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			wp_mail(
				$admin_email,
				sprintf( 'New custom rug request from %s', $name ?: $email ),
				sprintf( "%s (%s) submitted %d custom rug request(s).\n\nReview in wp-admin: %s", $name ?: 'A customer', $email ?: 'no email given', count( $quote_ids ), admin_url( 'admin.php?page=dreamrugs-quotes' ) )
			);
		}

		return new WP_REST_Response( array(
			'quote_ids' => $quote_ids,
			'message'   => "Thanks — we've received your custom rug request. Our team will review it and send you a personalized quote within 24–48 hours.",
		), 201 );
	}
}
