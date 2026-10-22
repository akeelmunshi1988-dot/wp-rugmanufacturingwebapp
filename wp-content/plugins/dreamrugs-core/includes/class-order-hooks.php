<?php
/**
 * WooCommerce order lifecycle glue that the original app did inline in
 * customer.py's checkout / orders.py:
 *
 *  - Server-side cart checks (_price_cart_items): rug available, colour
 *    valid, material available with enough stock. The product page hints
 *    at these; this enforces them.
 *  - Direct checkout creates one accepted wp_drc_quotes row per line
 *    (_create_order_from_items) so breakdowns, invoices and dashboard
 *    revenue work the same for cart orders as for quote orders.
 *  - Material stock is deducted once, when the order is paid, with a
 *    "used" inventory transaction (atomic, never below zero).
 *  - Estimated delivery date is stamped on every DRC order.
 *  - Staff tools on the order screen: pricing breakdown, GST invoice
 *    download/email, policy-based cancellation, and combining several
 *    unpaid orders for one customer (orders.py combine_orders).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Order_Hooks {

	public static function init() {
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'validate_add_to_cart' ), 10, 5 );
		add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_cart' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'record_checkout_quotes' ) );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'record_checkout_quotes' ) );

		foreach ( array( 'processing', 'completed', 'in_production', 'on-hold' ) as $status ) {
			add_action( "woocommerce_order_status_{$status}", array( __CLASS__, 'deduct_stock' ) );
		}
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'deduct_stock' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_drc_order_invoice', array( __CLASS__, 'admin_download_invoice' ) );

		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'register_bulk_action' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_combine' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_combine' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'combine_notice' ) );

		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'gstin_field' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'gstin_default' ), 10, 2 );
		add_filter( 'woocommerce_process_checkout_field_billing_gstin', array( __CLASS__, 'normalise_gstin' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_gstin' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_user_meta', array( __CLASS__, 'save_gstin_to_customer' ), 10, 2 );
	}

	// ── Buyer GSTIN ─────────────────────────────────────────────────────
	// The original keeps Customer.gstin and prints it as the buyer GSTIN on
	// tax invoices. Here it's an optional billing field: WooCommerce saves
	// it as the order's _billing_gstin (what DRC_Invoice_PDF reads first)
	// and the customer's drc_gstin is kept in step for later orders/quotes.

	const GSTIN_PATTERN = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

	public static function gstin_field( $fields ) {
		$fields['billing_gstin'] = array(
			'label'       => 'GSTIN (for a business tax invoice)',
			'placeholder' => 'Optional — e.g. 09AAACH7409R1ZZ',
			'required'    => false,
			'priority'    => 35,
			'maxlength'   => 15,
		);
		return $fields;
	}

	public static function gstin_default( $value, $input ) {
		if ( 'billing_gstin' === $input && null === $value && is_user_logged_in() ) {
			$saved = get_user_meta( get_current_user_id(), 'drc_gstin', true );
			return $saved ?: $value;
		}
		return $value;
	}

	/** Uppercase, no spaces — runs before validation and saving. */
	public static function normalise_gstin( $value ) {
		return strtoupper( preg_replace( '/\s+/', '', (string) $value ) );
	}

	public static function validate_gstin( $data, $errors ) {
		if ( empty( $data['billing_gstin'] ) ) {
			return;
		}
		if ( ! preg_match( self::GSTIN_PATTERN, (string) $data['billing_gstin'] ) ) {
			$errors->add( 'billing_gstin', 'Please enter a valid 15-character GSTIN, or leave it blank.' );
		} elseif ( 'IN' !== ( $data['billing_country'] ?? '' ) ) {
			$errors->add( 'billing_gstin', 'A GSTIN only applies to Indian billing addresses — please clear it for orders shipping abroad.' );
		}
	}

	public static function save_gstin_to_customer( $customer_id, $data ) {
		if ( $customer_id && ! empty( $data['billing_gstin'] ) ) {
			update_user_meta( $customer_id, 'drc_gstin', (string) $data['billing_gstin'] );
		}
	}

	// ── Cart validation ─────────────────────────────────────────────────

	/** Required material for one line, in sqm — same basis as the
	 * original's stock check (size × qty, no waste buffer). */
	private static function line_requirement( WC_Product $variation, int $qty ): ?array {
		$parent_id   = $variation->get_parent_id() ?: $variation->get_id();
		$material_id = (int) get_post_meta( $parent_id, '_drc_material_id', true );
		$ft          = $variation->get_meta( '_drc_size_ft' );
		$cm          = $variation->get_meta( '_drc_size_cm' );
		$dims        = DRC_Quote_Engine::parse_catalog_dimensions( $ft ?: null, 0.3048 )
			?: DRC_Quote_Engine::parse_catalog_dimensions( $cm ?: null, 0.01 );
		if ( ! $material_id || ! $dims ) {
			return null;
		}
		return array(
			'material_id' => $material_id,
			'parent_id'   => $parent_id,
			'size_w'      => $dims[0],
			'size_h'      => $dims[1],
			'total_sqm'   => round( $dims[0] * $dims[1] * $qty, 4 ),
		);
	}

	private static function check_line( WC_Product $product, int $qty, string $color, int $extra_sqm_same_material = 0 ): ?string {
		$parent_id = $product->get_parent_id() ?: $product->get_id();
		$name      = get_the_title( $parent_id );
		$parent    = wc_get_product( $parent_id );
		// WooCommerce stock status is the vendor's availability switch here
		// (the original's RugCatalog.is_available / inventory_quantity).
		if ( ! $product->is_in_stock() || ( $parent && ! $parent->is_in_stock() ) ) {
			return sprintf( '"%s" is currently out of stock.', $name );
		}
		if ( $product->managing_stock() && null !== $product->get_stock_quantity() && $product->get_stock_quantity() < $qty ) {
			return sprintf( 'Only %d unit(s) of "%s" are available.', (int) $product->get_stock_quantity(), $name );
		}
		$colors = wp_list_pluck( DRC_Color_Options::get( $parent_id ), 'name' );
		if ( $colors && ! in_array( $color, $colors, true ) ) {
			return sprintf( 'Select an available color for "%s".', $name );
		}
		$req = self::line_requirement( $product, $qty );
		if ( ! $req ) {
			return null;
		}
		$material = DRC_Quote_Engine::get_material( $req['material_id'] );
		if ( ! $material || ! $material['is_available'] ) {
			return sprintf( 'Material for "%s" is not available.', $name );
		}
		$needed = $req['total_sqm'] + $extra_sqm_same_material;
		if ( $material['stock_meters'] < $needed ) {
			return sprintf( 'Insufficient stock for "%s". Available: %.1f sqm, Required: %.1f sqm', $name, $material['stock_meters'], $needed );
		}
		return null;
	}

	public static function validate_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
		if ( ! $passed || ! $variation_id ) {
			return $passed;
		}
		$product = wc_get_product( $variation_id );
		if ( ! $product ) {
			return $passed;
		}
		$color = isset( $_POST['drc_selected_color'] ) ? sanitize_text_field( wp_unslash( $_POST['drc_selected_color'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's add-to-cart handler owns this request.
		$error = self::check_line( $product, (int) $quantity, $color );
		if ( $error ) {
			wc_add_notice( $error, 'error' );
			return false;
		}
		return $passed;
	}

	/** Re-checks the whole cart (also runs at checkout) — lines sharing a
	 * material are summed so two rugs can't each "fit" the same stock. */
	public static function validate_cart() {
		if ( ! WC()->cart ) {
			return;
		}
		$used = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'];
			if ( ! $product instanceof WC_Product_Variation ) {
				continue;
			}
			$req   = self::line_requirement( $product, (int) $item['quantity'] );
			$prior = $req ? ( $used[ $req['material_id'] ] ?? 0 ) : 0;
			$error = self::check_line( $product, (int) $item['quantity'], (string) ( $item['drc_selected_color'] ?? '' ), (int) ceil( $prior ) );
			if ( $error ) {
				wc_add_notice( $error, 'error' );
			}
			if ( $req ) {
				$used[ $req['material_id'] ] = $prior + $req['total_sqm'];
			}
		}
	}

	// ── Checkout → quote rows ───────────────────────────────────────────

	/** One accepted quote per DRC-priced line, linked to the order — the
	 * WooCommerce analogue of _create_order_from_items(). */
	public static function record_checkout_quotes( $order ) {
		global $wpdb;
		if ( ! $order instanceof WC_Order || $order->get_meta( '_drc_quote_id' ) ) {
			return;
		}
		$table     = $wpdb->prefix . 'drc_quotes';
		$is_export = ! DRC_Quote_Context::is_india( $order->get_billing_country() );
		$fx_rate   = (float) $order->get_meta( '_drc_fx_rate' ) ?: 1.0;
		$first_id  = null;
		$max_days  = 0;

		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product_Variation ) {
				continue;
			}
			$req = self::line_requirement( $product, (int) $item->get_quantity() );
			if ( ! $req ) {
				continue;
			}
			$rug  = DRC_Quote_Engine::get_rug_for_pricing( $req['parent_id'] );
			$calc = $rug ? ( new DRC_Quote_Engine() )->calculate_quote(
				$rug, $req['size_w'], $req['size_h'], $req['material_id'], (int) $item->get_quantity(),
				'Yes' === $item->get_meta( 'Rush Order' ), null, $is_export ? 0.0 : null, null, 'rect'
			) : array( 'error' => 'not priced' );

			$days = isset( $calc['estimated_days'] ) ? (int) $calc['estimated_days'] : ( (int) ( $rug['lead_time_days'] ?? 21 ) ?: 21 );
			$max_days = max( $max_days, $days );

			$wpdb->insert( $table, array(
				'customer_user_id'       => $order->get_customer_id() ?: null,
				'guest_name'             => $order->get_customer_id() ? null : trim( $order->get_formatted_billing_full_name() ),
				'guest_email'            => $order->get_customer_id() ? null : $order->get_billing_email(),
				'product_id'             => $req['parent_id'],
				'variation_id'           => $product->get_id(),
				'custom_size_w'          => $req['size_w'],
				'custom_size_h'          => $req['size_h'],
				'rug_shape'              => 'rect',
				'material_id'            => $req['material_id'],
				'qty'                    => (int) $item->get_quantity(),
				// The line total actually charged (in the order's currency) is
				// canonical — converted back to base if the shopper paid in
				// another currency (see DRC_Multi_Currency).
				'base_price'             => isset( $calc['subtotal'] ) ? (float) $calc['subtotal'] : null,
				'final_price'            => round( (float) $item->get_total() / $fx_rate, 2 ),
				'price_currency'         => DRC_Settings::get( 'currency' ),
				'margin_pct'             => $calc['profit_margin_pct'] ?? null,
				'gst_pct'                => $is_export ? 0.0 : ( $calc['gst_pct'] ?? null ),
				'rush_order'             => 'Yes' === $item->get_meta( 'Rush Order' ) ? 1 : 0,
				'selected_color'         => $item->get_meta( 'Color' ) ?: null,
				'expected_delivery_days' => $days,
				'status'                 => 'accepted',
				'notes'                  => 'Direct checkout',
				'wc_order_id'            => $order->get_id(),
				'created_at'             => current_time( 'mysql' ),
			) );
			$first_id = $first_id ?: $wpdb->insert_id;
		}

		if ( $first_id ) {
			$order->update_meta_data( '_drc_quote_id', $first_id );
			$order->update_meta_data( '_drc_estimated_delivery', gmdate( 'Y-m-d', time() + $max_days * DAY_IN_SECONDS ) );
			$order->save();
		}
	}

	// ── Stock deduction ─────────────────────────────────────────────────

	public static function deduct_stock( $order_id ) {
		global $wpdb;
		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( '_drc_stock_deducted' ) ) {
			return;
		}
		// on-hold = payment initiated offline (bank transfer etc.) — only
		// treat it as paid when the gateway says so.
		if ( 'on-hold' === $order->get_status() && ! $order->is_paid() ) {
			return;
		}
		// Claim the order first so a concurrent hook firing can't deduct twice.
		$order->update_meta_data( '_drc_stock_deducted', current_time( 'mysql' ) );
		$order->save();

		$quotes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_quotes WHERE wc_order_id = %d", $order->get_id() ), ARRAY_A );
		$materials = $wpdb->prefix . 'drc_materials';
		$tx        = $wpdb->prefix . 'drc_inventory_transactions';
		$ref       = $order->get_transaction_id() ? ' via ' . $order->get_payment_method_title() . ' ' . $order->get_transaction_id() : '';

		foreach ( $quotes as $q ) {
			if ( empty( $q['material_id'] ) || empty( $q['custom_size_w'] ) || empty( $q['custom_size_h'] ) ) {
				continue;
			}
			$sqm = round( (float) $q['custom_size_w'] * (float) $q['custom_size_h'] * max( 1, (int) $q['qty'] ), 4 );
			// Atomic: never drives stock negative. A paid order is never
			// blocked here (the money is taken) — a shortfall is flagged on
			// the order for the vendor instead.
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$materials} SET stock_meters = stock_meters - %f WHERE id = %d AND stock_meters >= %f",
				$sqm, (int) $q['material_id'], $sqm
			) );
			if ( ! $updated ) {
				$order->add_order_note( sprintf( 'Material #%d had less than %.2f sqm in stock when this order was paid — stock not deducted, please restock or adjust.', (int) $q['material_id'], $sqm ) );
				continue;
			}
			$wpdb->insert( $tx, array(
				'material_id'      => (int) $q['material_id'],
				'qty_change'       => -$sqm,
				'transaction_type' => 'used',
				'notes'            => sprintf( 'Order #%d%s — %s %s ×%d', $order->get_id(), $ref, DRC_Quote_Context::rug_name( $q ), DRC_Quote_Context::size_display( $q ), max( 1, (int) $q['qty'] ) ),
				'created_at'       => current_time( 'mysql' ),
			) );
		}
	}

	// ── Admin meta box ──────────────────────────────────────────────────

	public static function add_meta_box() {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'drc-order-tools', 'DreamRugs: Pricing, Invoices & Cancellation', array( __CLASS__, 'render_meta_box' ), $screen, 'normal', 'high' );
		}
	}

	public static function invoice_url( int $order_id, string $type ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=drc_order_invoice&order_id=' . $order_id . '&type=' . $type ), 'drc_order_invoice_' . $order_id );
	}

	public static function render_meta_box( $post_or_order ) {
		global $wpdb;
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order ) {
			return;
		}
		$quotes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_quotes WHERE wc_order_id = %d ORDER BY id ASC", $order->get_id() ), ARRAY_A );
		$terms  = DRC_Customer_Orders_Rest_Routes::policy_terms( $order );
		$eta    = $order->get_meta( '_drc_estimated_delivery' );
		?>
		<div class="drc-order-tools" data-order-id="<?php echo (int) $order->get_id(); ?>">
			<?php if ( $quotes ) : ?>
				<table class="widefat striped" style="margin-bottom:12px;">
					<thead><tr><th>Quote</th><th>Rug</th><th>Size</th><th>Qty</th><th>Base</th><th>Margin</th><th>GST</th><th>Rush</th><th>Final</th></tr></thead>
					<tbody>
					<?php foreach ( $quotes as $q ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=dreamrugs-quotes&quote=' . (int) $q['id'] ) ); ?>">#<?php echo (int) $q['id']; ?></a></td>
							<td><?php echo esc_html( DRC_Quote_Context::rug_name( $q ) ); ?><?php echo $q['selected_color'] ? ' · ' . esc_html( $q['selected_color'] ) : ''; ?></td>
							<td><?php echo esc_html( DRC_Quote_Context::size_display( $q ) ); ?></td>
							<td><?php echo (int) $q['qty']; ?></td>
							<td><?php echo null !== $q['base_price'] ? esc_html( DRC_Quote_Context::money( (float) $q['base_price'], $q['price_currency'] ) ) : '—'; ?></td>
							<td><?php echo null !== $q['margin_pct'] ? esc_html( round( (float) $q['margin_pct'], 2 ) . '% (locked)' ) : '—'; ?></td>
							<td><?php echo null !== $q['gst_pct'] ? esc_html( round( (float) $q['gst_pct'], 2 ) . '% (locked)' ) : '—'; ?></td>
							<td><?php echo $q['rush_order'] ? 'Yes' : 'No'; ?></td>
							<td><strong><?php echo esc_html( DRC_Quote_Context::money( (float) $q['final_price'], $q['price_currency'] ) ); ?></strong></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					Order total: <strong><?php echo wp_kses_post( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ); ?></strong>
					<?php if ( (float) $order->get_meta( '_drc_fx_rate' ) && get_woocommerce_currency() !== $order->get_currency() ) : ?>
						· charged in <?php echo esc_html( $order->get_currency() ); ?> at <?php echo esc_html( $order->get_meta( '_drc_fx_rate' ) ); ?> per <?php echo esc_html( get_woocommerce_currency() ); ?>
					<?php endif; ?>
					<?php if ( $eta ) : ?> · Estimated delivery: <strong><?php echo esc_html( date_i18n( 'j M Y', strtotime( $eta ) ) ); ?></strong><?php endif; ?>
				</p>
				<p>
					<strong>GST invoice:</strong>
					<a class="button" href="<?php echo esc_url( self::invoice_url( $order->get_id(), 'tax' ) ); ?>">Tax</a>
					<a class="button" href="<?php echo esc_url( self::invoice_url( $order->get_id(), 'export' ) ); ?>">Export</a>
					<a class="button" href="<?php echo esc_url( self::invoice_url( $order->get_id(), 'proforma' ) ); ?>">Proforma</a>
					<button type="button" class="button drc-order-email-invoice">Email tax invoice to customer</button>
				</p>
			<?php else : ?>
				<p>No DreamRugs pricing is linked to this order.</p>
			<?php endif; ?>
			<p>
				<strong>Cancellation policy:</strong>
				<?php if ( $terms['eligible'] ) : ?>
					cancelling now refunds <?php echo esc_html( round( $terms['refund_pct'] * 100 ) ); ?>%<?php echo $order->get_transaction_id() ? '' : ' (no online payment on this order, so nothing is refunded)'; ?>.
					<button type="button" class="button drc-order-cancel">Cancel with policy refund</button>
				<?php else : ?>
					<?php echo esc_html( $terms['reason'] ); ?>
				<?php endif; ?>
			</p>
			<p class="description">Use “Cancel with policy refund” rather than setting the status to Cancelled by hand — a manual status change issues no refund.</p>
			<div class="drc-order-msg"></div>
		</div>
		<script>
		( function () {
			var box = document.querySelector( '.drc-order-tools' );
			if ( ! box ) return;
			var id = box.dataset.orderId, msg = box.querySelector( '.drc-order-msg' );
			function call( path, method ) {
				return fetch( <?php echo wp_json_encode( esc_url_raw( rest_url( 'dreamrugs/v1/admin/orders/' ) ) ); ?> + id + path, { method: method, credentials: 'same-origin', headers: { 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> } } )
					.then( function ( r ) { return r.json().then( function ( d ) { if ( ! r.ok ) throw new Error( d.message || 'Request failed' ); return d; } ); } );
			}
			var email = box.querySelector( '.drc-order-email-invoice' );
			if ( email ) email.addEventListener( 'click', function () {
				email.disabled = true;
				call( '/invoice/email?type=tax', 'POST' ).then( function ( d ) { msg.innerHTML = '<p style="color:#00a32a;">' + d.message + '</p>'; } )
					.catch( function ( e ) { msg.innerHTML = '<p style="color:#d63638;">' + e.message + '</p>'; } ).finally( function () { email.disabled = false; } );
			} );
			var cancel = box.querySelector( '.drc-order-cancel' );
			if ( cancel ) cancel.addEventListener( 'click', function () {
				if ( ! window.confirm( 'Cancel this order and issue the policy refund?' ) ) return;
				cancel.disabled = true;
				call( '/cancel', 'POST' ).then( function () { window.location.reload(); } )
					.catch( function ( e ) { msg.innerHTML = '<p style="color:#d63638;">' + e.message + '</p>'; cancel.disabled = false; } );
			} );
		} )();
		</script>
		<?php
	}

	public static function admin_download_invoice() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'drc_order_invoice_' . $order_id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( 'Not allowed.', 403 );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( 'Order not found.', 404 );
		}
		$type   = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : 'tax';
		$result = DRC_Invoice_PDF::for_order( $order, $type );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ), 422 );
		}
		DRC_Invoice_PDF::stream( $result );
	}

	// ── Combine orders ──────────────────────────────────────────────────

	public static function register_bulk_action( array $actions ): array {
		$actions['drc_combine'] = 'Combine into one order (unpaid, same customer)';
		return $actions;
	}

	public static function handle_bulk_combine( $redirect, $action, $ids ) {
		if ( 'drc_combine' !== $action ) {
			return $redirect;
		}
		$result = self::combine( array_map( 'absint', (array) $ids ) );
		$arg    = is_wp_error( $result ) ? array( 'drc_combine_error' => rawurlencode( $result->get_error_message() ) ) : array( 'drc_combined' => $result->get_id() );
		return add_query_arg( $arg, remove_query_arg( array( 'drc_combined', 'drc_combine_error' ), $redirect ) );
	}

	public static function combine_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only notice params set by our own redirect.
		if ( isset( $_GET['drc_combined'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Orders combined into order #' . (int) $_GET['drc_combined'] . '.</p></div>';
		} elseif ( isset( $_GET['drc_combine_error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['drc_combine_error'] ) ) ) ) . '</p></div>';
		}
		// phpcs:enable
	}

	/** Merges unpaid orders for one customer into the lowest-numbered one:
	 * line items and linked quotes move over, the total is the sum of the
	 * canonical totals (never recomputed), the latest estimated delivery
	 * wins, and the emptied orders are trashed with a note. Unpaid only —
	 * paid orders carry their own gateway transaction for refunds.
	 *
	 * @return WC_Order|WP_Error */
	public static function combine( array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( count( $ids ) < 2 ) {
			return new WP_Error( 'drc_combine_few', 'Select at least two orders to combine.' );
		}
		$orders = array();
		foreach ( $ids as $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				return new WP_Error( 'drc_combine_missing', "Order #{$id} was not found." );
			}
			$orders[] = $o;
		}
		$not_pending = array_filter( $orders, function ( $o ) { return 'pending' !== $o->get_status(); } );
		if ( $not_pending ) {
			return new WP_Error( 'drc_combine_status', 'Only unpaid (Pending payment) orders can be combined. Not pending: #' . implode( ', #', array_map( function ( $o ) { return $o->get_id(); }, $not_pending ) ) );
		}
		$emails = array_unique( array_map( function ( $o ) { return strtolower( $o->get_billing_email() ); }, $orders ) );
		if ( count( $emails ) !== 1 || '' === reset( $emails ) ) {
			return new WP_Error( 'drc_combine_customer', 'Selected orders must all belong to the same customer.' );
		}
		$currencies = array_unique( array_map( function ( $o ) { return $o->get_currency(); }, $orders ) );
		if ( count( $currencies ) !== 1 ) {
			return new WP_Error( 'drc_combine_currency', 'Selected orders are in different currencies.' );
		}

		usort( $orders, function ( $a, $b ) { return $a->get_id() <=> $b->get_id(); } );
		$primary = array_shift( $orders );
		$total   = (float) $primary->get_total();
		$latest  = $primary->get_meta( '_drc_estimated_delivery' );

		foreach ( $orders as $other ) {
			foreach ( $other->get_items() as $item ) {
				$copy = new WC_Order_Item_Product();
				$copy->set_props( array(
					'name'         => $item->get_name(),
					'product_id'   => $item->get_product_id(),
					'variation_id' => $item->get_variation_id(),
					'quantity'     => $item->get_quantity(),
					'subtotal'     => $item->get_subtotal(),
					'total'        => $item->get_total(),
				) );
				foreach ( $item->get_meta_data() as $meta ) {
					$copy->add_meta_data( $meta->key, $meta->value );
				}
				$primary->add_item( $copy );
			}
			$total += (float) $other->get_total();
			$eta = $other->get_meta( '_drc_estimated_delivery' );
			if ( $eta && ( ! $latest || $eta > $latest ) ) {
				$latest = $eta;
			}
			$wpdb->update( $wpdb->prefix . 'drc_quotes', array( 'wc_order_id' => $primary->get_id() ), array( 'wc_order_id' => $other->get_id() ) );
			$other->add_order_note( sprintf( 'Combined into order #%d.', $primary->get_id() ) );
			$other->save();
			$other->delete( false );
		}

		if ( $latest ) {
			$primary->update_meta_data( '_drc_estimated_delivery', $latest );
		}
		$primary->set_total( round( $total, 2 ) );
		$primary->add_order_note( 'Combined with order(s) #' . implode( ', #', array_map( function ( $o ) { return $o->get_id(); }, $orders ) ) . '.' );
		$primary->save();
		return $primary;
	}
}
