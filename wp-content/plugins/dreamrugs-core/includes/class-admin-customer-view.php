<?php
/**
 * Per-customer view for staff — the original's admin customer detail
 * (quotes + orders for one customer) on the WordPress user profile, plus a
 * "Quotes" column on Users → All Users. Also lets staff edit the buyer
 * GSTIN printed on tax invoices and confirm a customer's email by hand.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Admin_Customer_View {

	public static function init() {
		add_action( 'show_user_profile', array( __CLASS__, 'render' ), 5 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render' ), 5 );
		add_action( 'personal_options_update', array( __CLASS__, 'save' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save' ) );
		add_filter( 'manage_users_columns', array( __CLASS__, 'add_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'column' ), 10, 3 );
	}

	private static function quotes_link( array $args ): string {
		return add_query_arg( array_merge( array( 'page' => 'dreamrugs-quotes' ), $args ), admin_url( 'admin.php' ) );
	}

	/** Quotes for a user: their own plus guest quotes under the same email. */
	private static function quotes( WP_User $user, int $limit = 50 ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'drc_quotes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE customer_user_id = %d OR (customer_user_id IS NULL AND guest_email = %s) ORDER BY created_at DESC LIMIT %d", $user->ID, $user->user_email, $limit ), ARRAY_A );
	}

	public static function render( WP_User $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || user_can( $user, 'edit_posts' ) ) {
			return;
		}
		$quotes = self::quotes( $user );
		$orders = function_exists( 'wc_get_orders' ) ? wc_get_orders( array( 'customer_id' => $user->ID, 'limit' => 20, 'orderby' => 'date', 'order' => 'DESC' ) ) : array();
		$verified = '0' !== (string) get_user_meta( $user->ID, 'drc_email_verified', true );
		wp_nonce_field( 'drc_customer_view', 'drc_customer_view_nonce' );
		?>
		<h2>DreamRugs customer</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="drc_gstin">GSTIN</label></th>
				<td>
					<input type="text" name="drc_gstin" id="drc_gstin" maxlength="15" class="regular-text" value="<?php echo esc_attr( (string) get_user_meta( $user->ID, 'drc_gstin', true ) ); ?>" style="text-transform:uppercase">
					<p class="description">Printed as the buyer GSTIN on tax invoices. Updated automatically when the customer enters one at checkout.</p>
				</td>
			</tr>
			<tr>
				<th>Email</th>
				<td>
					<?php if ( $verified ) : ?>
						<span style="color:#00a32a">✓ Verified</span>
					<?php else : ?>
						<span style="color:#b32d2e">Not verified yet</span> —
						<label><input type="checkbox" name="drc_mark_verified" value="1"> Mark as verified</label>
						<p class="description">The customer can't sign in until they use the link in their verification email.</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h3>Quotes (<?php echo count( $quotes ); ?><?php echo count( $quotes ) >= 50 ? '+' : ''; ?>)</h3>
		<?php if ( ! $quotes ) : ?>
			<p>No quotes yet. <a href="<?php echo esc_url( admin_url( 'admin.php?page=dreamrugs-quote-builder' ) ); ?>">Create one in the Quote Builder →</a></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>#</th><th>Rug</th><th>Size</th><th>Status</th><th style="text-align:right">Total</th><th>Created</th></tr></thead>
				<tbody>
				<?php foreach ( $quotes as $q ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( self::quotes_link( array( 'quote' => $q['id'] ) ) ); ?>">#<?php echo (int) $q['id']; ?></a></td>
						<td><?php echo esc_html( DRC_Quote_Context::rug_name( $q ) ); ?><?php echo $q['is_custom_request'] ? ' <em>(custom)</em>' : ''; ?></td>
						<td><?php echo esc_html( DRC_Quote_Context::size_display( $q ) ); ?></td>
						<td><?php echo esc_html( ucwords( str_replace( '_', ' ', $q['status'] ) ) ); ?></td>
						<td style="text-align:right"><?php echo null !== $q['final_price'] ? esc_html( DRC_Quote_Context::money( (float) $q['final_price'], $q['price_currency'] ) ) : '—'; ?></td>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $q['created_at'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><a href="<?php echo esc_url( self::quotes_link( array( 'search' => $user->user_email ) ) ); ?>">Open in Quotes →</a></p>
		<?php endif; ?>

		<h3>Orders (<?php echo count( $orders ); ?>)</h3>
		<?php if ( ! $orders ) : ?>
			<p>No orders yet.</p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Order</th><th>Status</th><th style="text-align:right">Total</th><th>Date</th></tr></thead>
				<tbody>
				<?php foreach ( $orders as $order ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a></td>
						<td><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></td>
						<td style="text-align:right"><?php echo wp_kses_post( $order->get_formatted_order_total() ); ?></td>
						<td><?php echo esc_html( $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	public static function save( $user_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['drc_customer_view_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['drc_customer_view_nonce'] ) ), 'drc_customer_view' ) ) {
			return;
		}
		if ( isset( $_POST['drc_gstin'] ) ) {
			$gstin = strtoupper( preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['drc_gstin'] ) ) ) );
			if ( '' === $gstin || preg_match( DRC_Order_Hooks::GSTIN_PATTERN, $gstin ) ) {
				update_user_meta( $user_id, 'drc_gstin', $gstin );
			}
		}
		if ( ! empty( $_POST['drc_mark_verified'] ) ) {
			update_user_meta( $user_id, 'drc_email_verified', '1' );
			delete_user_meta( $user_id, 'drc_email_verify_token' );
		}
	}

	public static function add_column( $columns ) {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			$columns['drc_quotes'] = 'Quotes';
		}
		return $columns;
	}

	public static function column( $output, $column, $user_id ) {
		if ( 'drc_quotes' !== $column ) {
			return $output;
		}
		global $wpdb;
		$user  = get_userdata( $user_id );
		$table = $wpdb->prefix . 'drc_quotes';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE customer_user_id = %d OR (customer_user_id IS NULL AND guest_email = %s)", $user_id, $user ? $user->user_email : '' ) );
		return $count ? '<a href="' . esc_url( self::quotes_link( array( 'search' => $user->user_email ) ) ) . '">' . $count . '</a>' : '—';
	}
}
