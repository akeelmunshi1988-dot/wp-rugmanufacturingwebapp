<?php
/**
 * Admin REST routes for the staff tools the first port left out:
 *
 *  - Quote Builder: live calculation, create and edit a quote
 *    (quotes.py create_quote / update_quote / calculate_quote).
 *  - Manual status override, including "accepted" → creates the order.
 *  - GST invoice PDF download and email for a quote (invoices.py).
 *  - Vendor sample-image upload (quotes.py upload_quote_sample_image).
 *  - Customer lookup for the builder.
 *  - Email template editing (routes/email_templates.py).
 *  - Newsletter subscriber list, delete and CSV export (routes/newsletter.py).
 *
 * All require manage_woocommerce, like DRC_Admin_Rest_Routes.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Admin_Quote_Tools {

	const NS = 'dreamrugs/v1';

	public static function register() {
		$auth = array( 'DRC_Rest_Routes', 'require_manage_woocommerce' );
		$routes = array(
			array( '/admin/quotes/calculate', 'POST', 'calculate' ),
			array( '/admin/quotes/create', 'POST', 'create_quote' ),
			array( '/admin/quotes/(?P<id>\d+)/edit', 'PUT', 'update_quote' ),
			array( '/admin/quotes/(?P<id>\d+)/status', 'PATCH', 'set_status' ),
			array( '/admin/quotes/(?P<id>\d+)/invoice', 'GET', 'download_invoice' ),
			array( '/admin/quotes/(?P<id>\d+)/send-email', 'POST', 'email_invoice' ),
			array( '/admin/quotes/(?P<id>\d+)/sample-images/upload', 'POST', 'upload_sample_image' ),
			array( '/admin/customers/search', 'GET', 'search_customers' ),
			array( '/admin/catalog/rugs', 'GET', 'list_rugs' ),
			array( '/admin/email-templates', 'GET', 'list_templates' ),
			array( '/admin/email-templates/(?P<key>[a-z_]+)', 'PUT', 'save_template' ),
			array( '/admin/email-templates/(?P<key>[a-z_]+)/reset', 'POST', 'reset_template' ),
			array( '/admin/email-templates/(?P<key>[a-z_]+)/test', 'POST', 'test_template' ),
			array( '/admin/newsletter', 'GET', 'list_newsletter' ),
			array( '/admin/newsletter/export', 'GET', 'export_newsletter' ),
			array( '/admin/newsletter', 'DELETE', 'delete_newsletter' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route( self::NS, $r[0], array(
				'methods'             => $r[1],
				'callback'            => array( __CLASS__, $r[2] ),
				'permission_callback' => $auth,
			) );
		}
	}

	private static function err( string $code, string $message, int $status = 400 ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	private static function quote_or_404( WP_REST_Request $request ) {
		$quote = DRC_Quote_Context::quote_row( (int) $request->get_param( 'id' ) );
		return $quote ?: self::err( 'drc_not_found', 'Quote not found.', 404 );
	}

	// ── Quote Builder ───────────────────────────────────────────────────

	/** Catalog-priced calculation for the builder (quotes.py calculate_quote). */
	public static function calculate( WP_REST_Request $request ) {
		$rug = DRC_Quote_Engine::get_rug_for_pricing( (int) $request->get_param( 'product_id' ) );
		if ( ! $rug ) {
			return self::err( 'drc_not_found', 'Rug not found (it must be a variable product with sizes).', 404 );
		}
		$discount = $request->get_param( 'manual_discount_pct' );
		$result   = ( new DRC_Quote_Engine() )->calculate_quote(
			$rug,
			(float) $request->get_param( 'size_w' ),
			(float) $request->get_param( 'size_h' ),
			(int) $request->get_param( 'material_id' ),
			max( 1, (int) ( $request->get_param( 'qty' ) ?: 1 ) ),
			(bool) $request->get_param( 'rush_order' ),
			null !== $request->get_param( 'margin_pct' ) && '' !== $request->get_param( 'margin_pct' ) ? (float) $request->get_param( 'margin_pct' ) : null,
			null,
			null !== $discount && '' !== $discount ? (float) $discount : null,
			sanitize_key( $request->get_param( 'shape' ) ?: 'rect' )
		);
		if ( isset( $result['error'] ) ) {
			return self::err( 'drc_calc_failed', $result['error'] );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/** Fields staff may set when creating/editing a quote. */
	private static function quote_fields( WP_REST_Request $request ): array {
		$data = array();
		$map  = array(
			'product_id'             => 'int',
			'material_id'            => 'int',
			'custom_size_w'          => 'float',
			'custom_size_h'          => 'float',
			'qty'                    => 'int',
			'base_price'             => 'float',
			'final_price'            => 'float',
			'margin_pct'             => 'float',
			'gst_pct'                => 'float',
			'manual_discount_pct'    => 'float',
			'shipping_cost'          => 'float',
			'expected_delivery_days' => 'int',
			'rush_order'             => 'bool',
			'rug_shape'              => 'key',
			'selected_color'         => 'text',
			'notes'                  => 'textarea',
			'vendor_notes'           => 'textarea',
		);
		foreach ( $map as $key => $type ) {
			if ( ! $request->has_param( $key ) ) {
				continue;
			}
			$v = $request->get_param( $key );
			if ( null === $v || '' === $v ) {
				$data[ $key ] = 'bool' === $type ? 0 : null;
				continue;
			}
			switch ( $type ) {
				case 'int':
					$data[ $key ] = (int) $v;
					break;
				case 'float':
					$data[ $key ] = (float) $v;
					break;
				case 'bool':
					$data[ $key ] = $v ? 1 : 0;
					break;
				case 'key':
					$data[ $key ] = sanitize_key( $v );
					break;
				case 'textarea':
					$data[ $key ] = sanitize_textarea_field( $v );
					break;
				default:
					$data[ $key ] = sanitize_text_field( $v );
			}
		}
		if ( isset( $data['qty'] ) ) {
			$data['qty'] = max( 1, (int) $data['qty'] );
		}
		return $data;
	}

	/** Existing customer (user id) or a new one by name/email — a new one
	 * is kept as guest contact details, which link to their account
	 * automatically when they register/sign in with that email. */
	private static function customer_fields( WP_REST_Request $request ) {
		$user_id = (int) $request->get_param( 'customer_user_id' );
		if ( $user_id ) {
			if ( ! get_userdata( $user_id ) ) {
				return self::err( 'drc_customer_not_found', 'Customer not found.', 404 );
			}
			return array( 'customer_user_id' => $user_id, 'guest_name' => null, 'guest_email' => null );
		}
		$email = sanitize_email( (string) $request->get_param( 'customer_email' ) );
		$name  = sanitize_text_field( (string) $request->get_param( 'customer_name' ) );
		if ( ! $name || ! is_email( $email ) ) {
			return self::err( 'drc_customer_required', 'Select a customer or enter a name and valid email for a new one.' );
		}
		$existing = get_user_by( 'email', $email );
		if ( $existing ) {
			return array( 'customer_user_id' => $existing->ID, 'guest_name' => null, 'guest_email' => null );
		}
		return array( 'customer_user_id' => null, 'guest_name' => $name, 'guest_email' => $email );
	}

	public static function create_quote( WP_REST_Request $request ) {
		global $wpdb;
		$customer = self::customer_fields( $request );
		if ( is_wp_error( $customer ) ) {
			return $customer;
		}
		$data = self::quote_fields( $request );
		if ( ! empty( $data['product_id'] ) && ! wc_get_product( $data['product_id'] ) ) {
			return self::err( 'drc_not_found', 'Rug not found.', 404 );
		}
		if ( ! empty( $data['material_id'] ) && ! DRC_Quote_Engine::get_material( $data['material_id'] ) ) {
			return self::err( 'drc_not_found', 'Material not found.', 404 );
		}
		$phone = sanitize_text_field( (string) $request->get_param( 'customer_phone' ) );
		if ( $phone && ! $customer['customer_user_id'] ) {
			$data['notes'] = trim( 'Phone: ' . $phone . ( ! empty( $data['notes'] ) ? "\n\n" . $data['notes'] : '' ) );
		}
		$row = array_merge( $data, $customer, array(
			'status'            => 'draft',
			'price_currency'    => DRC_Settings::get( 'currency' ),
			'is_custom_request' => empty( $data['product_id'] ) ? 1 : 0,
			'created_at'        => current_time( 'mysql' ),
		) );
		if ( ! $wpdb->insert( $wpdb->prefix . 'drc_quotes', $row ) ) {
			return self::err( 'drc_insert_failed', 'Could not create the quote.', 500 );
		}
		$quote = DRC_Quote_Context::quote_row( $wpdb->insert_id );

		if ( $request->get_param( 'send' ) ) {
			if ( null === $quote['final_price'] ) {
				return self::err( 'drc_not_priced', 'Calculate or set a price before sending.' );
			}
			$wpdb->update( $wpdb->prefix . 'drc_quotes', array( 'status' => 'sent' ), array( 'id' => $quote['id'] ) );
			$quote = DRC_Quote_Context::quote_row( (int) $quote['id'] );
			DRC_Quote_Context::notify_customer_quote_sent( $quote );
		}
		return new WP_REST_Response( $quote, 201 );
	}

	public static function update_quote( WP_REST_Request $request ) {
		global $wpdb;
		$quote = self::quote_or_404( $request );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		if ( in_array( $quote['status'], array( 'accepted', 'rejected' ), true ) ) {
			return self::err( 'drc_invalid_state', 'accepted' === $quote['status']
				? "This quote is already 'accepted' and can no longer be modified. Cancel the order to make changes."
				: "This quote is already 'rejected' and can no longer be modified. Use \"Revise & Resend\" to send a new offer instead." );
		}
		$data = self::quote_fields( $request );
		if ( $request->has_param( 'customer_user_id' ) || $request->has_param( 'customer_email' ) ) {
			$customer = self::customer_fields( $request );
			if ( is_wp_error( $customer ) ) {
				return $customer;
			}
			$data = array_merge( $data, $customer );
		}
		if ( $data ) {
			$wpdb->update( $wpdb->prefix . 'drc_quotes', $data, array( 'id' => $quote['id'] ) );
		}
		return new WP_REST_Response( DRC_Quote_Context::quote_row( (int) $quote['id'] ), 200 );
	}

	/** Manual status override (quotes.py update_quote's status field). */
	public static function set_status( WP_REST_Request $request ) {
		global $wpdb;
		$quote = self::quote_or_404( $request );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$status = sanitize_key( (string) $request->get_param( 'status' ) );
		if ( ! in_array( $status, array( 'draft', 'sent', 'accepted', 'rejected' ), true ) ) {
			return self::err( 'drc_bad_status', 'Unknown status.' );
		}
		if ( in_array( $quote['status'], array( 'accepted', 'rejected' ), true ) ) {
			return self::err( 'drc_invalid_state', "This quote is already '{$quote['status']}' and can no longer be changed." );
		}
		if ( in_array( $status, array( 'sent', 'accepted' ), true ) && null === $quote['final_price'] ) {
			return self::err( 'drc_not_priced', "Cannot mark this quote as '{$status}' until a price has been set. Use \"Set Price\" first." );
		}
		// "Accepted" by staff (confirmed by phone/WhatsApp) creates a real
		// order, same as the customer accepting it themselves.
		if ( 'accepted' === $status ) {
			$accept = new WP_REST_Request( 'PATCH', '/' . self::NS . '/admin/quotes/' . $quote['id'] . '/accept' );
			$accept->set_url_params( array( 'id' => $quote['id'] ) );
			return DRC_Admin_Rest_Routes::accept_quote( $accept );
		}
		if ( 'rejected' === $status ) {
			$reject = new WP_REST_Request( 'PATCH', '/' . self::NS . '/admin/quotes/' . $quote['id'] . '/reject' );
			$reject->set_url_params( array( 'id' => $quote['id'] ) );
			$reject->set_param( 'reason', $request->get_param( 'reason' ) );
			return DRC_Admin_Rest_Routes::reject_quote( $reject );
		}
		$wpdb->update( $wpdb->prefix . 'drc_quotes', array( 'status' => $status ), array( 'id' => $quote['id'] ) );
		return new WP_REST_Response( DRC_Quote_Context::quote_row( (int) $quote['id'] ), 200 );
	}

	// ── Invoices ────────────────────────────────────────────────────────

	public static function download_invoice( WP_REST_Request $request ) {
		$quote = self::quote_or_404( $request );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$result = DRC_Invoice_PDF::for_quote( $quote, sanitize_key( $request->get_param( 'invoice_type' ) ?: 'tax' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		DRC_Invoice_PDF::stream( $result );
	}

	public static function email_invoice( WP_REST_Request $request ) {
		$quote = self::quote_or_404( $request );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$result = DRC_Invoice_PDF::for_quote( $quote, sanitize_key( $request->get_param( 'invoice_type' ) ?: 'proforma' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$to   = sanitize_email( (string) $request->get_param( 'recipient_email' ) ) ?: DRC_Quote_Context::customer( $quote )['email'];
		$sent = DRC_Invoice_PDF::email( $result, $quote, $to );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		return new WP_REST_Response( array( 'message' => "Email sent to {$to}", 'recipient' => $to ), 200 );
	}

	// ── Sample images ───────────────────────────────────────────────────

	public static function upload_sample_image( WP_REST_Request $request ) {
		global $wpdb;
		$quote = self::quote_or_404( $request );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return self::err( 'drc_no_file', 'No file uploaded.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$check = wp_check_filetype_and_ext( $files['file']['tmp_name'], $files['file']['name'] );
		if ( ! in_array( $check['type'], array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return self::err( 'drc_bad_type', 'Unsupported file type. Use JPEG, PNG, or WebP.' );
		}
		$_FILES['drc_sample'] = $files['file'];
		$attachment_id        = media_handle_upload( 'drc_sample', 0, array( 'post_title' => sprintf( 'Quote #%d design sample', $quote['id'] ) ) );
		if ( is_wp_error( $attachment_id ) ) {
			return self::err( 'drc_upload_failed', $attachment_id->get_error_message() );
		}
		$urls   = $quote['vendor_sample_image_urls'] ? (array) json_decode( $quote['vendor_sample_image_urls'], true ) : array();
		$urls[] = wp_get_attachment_url( $attachment_id );
		$wpdb->update( $wpdb->prefix . 'drc_quotes', array( 'vendor_sample_image_urls' => wp_json_encode( array_values( $urls ) ) ), array( 'id' => $quote['id'] ) );
		return new WP_REST_Response( array( 'url' => end( $urls ), 'vendor_sample_image_urls' => $urls ), 201 );
	}

	// ── Lookups for the builder ─────────────────────────────────────────

	public static function search_customers( WP_REST_Request $request ) {
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$users  = get_users( array(
			'search'         => $search ? '*' . $search . '*' : '',
			'search_columns' => array( 'user_email', 'display_name', 'user_login' ),
			'number'         => 20,
			'orderby'        => 'display_name',
		) );
		return new WP_REST_Response( array_map( function ( $u ) {
			return array(
				'id'      => $u->ID,
				'name'    => $u->display_name,
				'email'   => $u->user_email,
				'phone'   => get_user_meta( $u->ID, 'billing_phone', true ),
				'company' => get_user_meta( $u->ID, 'billing_company', true ),
			);
		}, $users ), 200 );
	}

	/** Variable rug products with their sizes, colours and material — what
	 * the builder needs to price one. */
	public static function list_rugs() {
		$ids = wc_get_products( array( 'type' => 'variable', 'status' => array( 'publish', 'private' ), 'limit' => -1, 'return' => 'ids', 'orderby' => 'title', 'order' => 'ASC' ) );
		$out = array();
		foreach ( $ids as $id ) {
			$rug = DRC_Quote_Engine::get_rug_for_pricing( $id );
			$out[] = array(
				'id'          => $id,
				'name'        => get_the_title( $id ),
				'material_id' => (int) get_post_meta( $id, '_drc_material_id', true ) ?: null,
				'sizes'       => $rug ? array_values( array_filter( $rug['sizes'], function ( $s ) { return $s['ft'] || $s['cm']; } ) ) : array(),
				'colors'      => wp_list_pluck( DRC_Color_Options::get( $id ), 'name' ),
			);
		}
		return new WP_REST_Response( $out, 200 );
	}

	// ── Email templates ─────────────────────────────────────────────────

	public static function list_templates() {
		return new WP_REST_Response( array_values( DRC_Email_Templates::all() ), 200 );
	}

	public static function save_template( WP_REST_Request $request ) {
		$key = sanitize_key( $request->get_param( 'key' ) );
		if ( ! isset( DRC_Email_Templates::defaults()[ $key ] ) ) {
			return self::err( 'drc_not_found', 'Unknown template.', 404 );
		}
		DRC_Email_Templates::save( $key, array(
			'subject'   => (string) $request->get_param( 'subject' ),
			'body_html' => (string) $request->get_param( 'body_html' ),
			'body_text' => (string) $request->get_param( 'body_text' ),
			'is_active' => (bool) $request->get_param( 'is_active' ),
		) );
		return new WP_REST_Response( DRC_Email_Templates::all()[ $key ], 200 );
	}

	public static function reset_template( WP_REST_Request $request ) {
		$key = sanitize_key( $request->get_param( 'key' ) );
		DRC_Email_Templates::reset( $key );
		$all = DRC_Email_Templates::all();
		return isset( $all[ $key ] ) ? new WP_REST_Response( $all[ $key ], 200 ) : self::err( 'drc_not_found', 'Unknown template.', 404 );
	}

	/** Sends the template with placeholder names as values to the current
	 * staff member, so they can see it in a real inbox. */
	public static function test_template( WP_REST_Request $request ) {
		$key = sanitize_key( $request->get_param( 'key' ) );
		$all = DRC_Email_Templates::all();
		if ( ! isset( $all[ $key ] ) ) {
			return self::err( 'drc_not_found', 'Unknown template.', 404 );
		}
		$vars = array();
		foreach ( $all[ $key ]['placeholders'] as $p ) {
			$vars[ $p ] = '_html' === substr( $p, -5 ) ? '' : '[' . $p . ']';
		}
		$to   = wp_get_current_user()->user_email;
		$sent = DRC_Email_Templates::send( $key, $to, $vars );
		return $sent ? new WP_REST_Response( array( 'message' => "Test sent to {$to}" ), 200 ) : self::err( 'drc_mail_failed', 'The test email could not be sent — check the site\'s mail settings.', 502 );
	}

	// ── Newsletter ──────────────────────────────────────────────────────

	/** drc_newsletter_subscribers is keyed by email: {source, subscribed_at}. */
	private static function subscribers(): array {
		$stored = get_option( 'drc_newsletter_subscribers', array() );
		$rows   = array();
		foreach ( is_array( $stored ) ? $stored : array() as $email => $row ) {
			$rows[] = array(
				'email'         => (string) $email,
				'source'        => (string) ( $row['source'] ?? '' ),
				'subscribed_at' => (string) ( $row['subscribed_at'] ?? '' ),
			);
		}
		usort( $rows, function ( $a, $b ) { return strcmp( $b['subscribed_at'], $a['subscribed_at'] ); } );
		return $rows;
	}

	public static function list_newsletter() {
		return new WP_REST_Response( self::subscribers(), 200 );
	}

	public static function delete_newsletter( WP_REST_Request $request ) {
		$email  = (string) $request->get_param( 'email' );
		$stored = get_option( 'drc_newsletter_subscribers', array() );
		if ( is_array( $stored ) && isset( $stored[ $email ] ) ) {
			unset( $stored[ $email ] );
			update_option( 'drc_newsletter_subscribers', $stored, false );
		}
		return new WP_REST_Response( array( 'message' => 'Subscriber removed.' ), 200 );
	}

	public static function export_newsletter() {
		$rows = self::subscribers();
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="newsletter_subscribers.csv"' );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'email', 'source', 'subscribed_at' ) );
		foreach ( $rows as $r ) {
			// Neutralise spreadsheet formula injection in user-supplied cells.
			$cells = array( $r['email'], $r['source'], $r['subscribed_at'] );
			fputcsv( $out, array_map( function ( $c ) { return preg_match( '/^[=+\-@]/', (string) $c ) ? "'" . $c : $c; }, $cells ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
