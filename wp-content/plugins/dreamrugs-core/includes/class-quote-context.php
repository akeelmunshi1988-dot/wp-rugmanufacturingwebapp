<?php
/**
 * Small shared lookups every quote-facing email, invoice and admin view
 * needs — who the customer is, what rug, how big, formatted how — kept in
 * one place so the vendor emails, customer emails and invoice PDFs can't
 * drift apart in how they describe the same quote.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Quote_Context {

	/** WooCommerce India state codes → GST state codes (the 2-digit codes
	 * DRC_Settings 'state_code' and DRC_Invoice_Data::STATE_NAMES use). */
	const WC_STATE_TO_GST = array(
		'JK' => '01', 'HP' => '02', 'PB' => '03', 'CH' => '04', 'UK' => '05', 'UT' => '05',
		'HR' => '06', 'DL' => '07', 'RJ' => '08', 'UP' => '09', 'BR' => '10', 'SK' => '11',
		'AR' => '12', 'NL' => '13', 'MN' => '14', 'MZ' => '15', 'TR' => '16', 'ML' => '17',
		'AS' => '18', 'WB' => '19', 'JH' => '20', 'OR' => '21', 'OD' => '21', 'CT' => '22',
		'CG' => '22', 'MP' => '23', 'GJ' => '24', 'DD' => '25', 'DN' => '26', 'MH' => '27',
		'KA' => '29', 'GA' => '30', 'LD' => '31', 'KL' => '32', 'TN' => '33', 'PY' => '34',
		'AN' => '35', 'TS' => '36', 'TG' => '36', 'AP' => '37', 'LA' => '38',
	);

	/** Normalises either form ('UP', '09', '9') to the 2-digit GST code. */
	public static function gst_state_code( ?string $state ): ?string {
		$state = strtoupper( trim( (string) $state ) );
		if ( '' === $state ) {
			return null;
		}
		if ( ctype_digit( $state ) ) {
			return str_pad( $state, 2, '0', STR_PAD_LEFT );
		}
		return self::WC_STATE_TO_GST[ $state ] ?? null;
	}

	/** Country stored as ISO code ('IN') or a migrated name ('India'). */
	public static function is_india( ?string $country ): bool {
		$country = strtoupper( trim( (string) $country ) );
		return '' === $country || 'IN' === $country || 'INDIA' === $country;
	}

	/** @return array{name:string,email:string,phone:string,company:string,user_id:int} */
	public static function customer( array $quote ): array {
		$out = array(
			'name'    => (string) ( $quote['guest_name'] ?? '' ),
			'email'   => (string) ( $quote['guest_email'] ?? '' ),
			'phone'   => '',
			'company' => '',
			'user_id' => (int) ( $quote['customer_user_id'] ?? 0 ),
		);
		if ( $out['user_id'] ) {
			$user = get_userdata( $out['user_id'] );
			if ( $user ) {
				$out['name']    = $user->display_name;
				$out['email']   = $user->user_email;
				$out['phone']   = (string) get_user_meta( $user->ID, 'billing_phone', true );
				$out['company'] = (string) get_user_meta( $user->ID, 'billing_company', true );
			}
		}
		if ( '' === $out['name'] ) {
			$out['name'] = 'Customer';
		}
		return $out;
	}

	public static function rug_name( array $quote ): string {
		if ( ! empty( $quote['product_id'] ) ) {
			$product = wc_get_product( (int) $quote['product_id'] );
			if ( $product ) {
				return $product->get_name();
			}
		}
		return 'Custom Rug Request';
	}

	public static function product_sizes( array $quote ): ?array {
		if ( empty( $quote['product_id'] ) ) {
			return null;
		}
		$rug = DRC_Quote_Engine::get_rug_for_pricing( (int) $quote['product_id'] );
		return $rug ? $rug['sizes'] : null;
	}

	public static function size_display( array $quote ): string {
		$w = isset( $quote['custom_size_w'] ) && null !== $quote['custom_size_w'] ? (float) $quote['custom_size_w'] : null;
		$h = isset( $quote['custom_size_h'] ) && null !== $quote['custom_size_h'] ? (float) $quote['custom_size_h'] : null;
		return DRC_Size_Format::email_dims_display( $w, $h, $quote['rug_shape'] ?: 'rect', self::product_sizes( $quote ) );
	}

	public static function money( ?float $amount, ?string $currency = null ): string {
		if ( null === $amount ) {
			return 'to be confirmed';
		}
		$currency = $currency ?: DRC_Settings::get( 'currency' );
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ), ENT_QUOTES, 'UTF-8' );
	}

	public static function quote_row( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_quotes WHERE id = %d", $id ), ARRAY_A );
		return $row ?: null;
	}

	/** New-catalog-quote alert to the vendor (vendor_quote_request). */
	public static function notify_vendor_quote_request( array $quote ): void {
		$c = self::customer( $quote );
		DRC_Email_Templates::send(
			'vendor_quote_request',
			DRC_Email_Templates::vendor_recipient(),
			array(
				'customer_name'       => $c['name'],
				'customer_email'      => $c['email'],
				'customer_phone_line' => $c['phone'] ? ', ' . $c['phone'] : '',
				'rug_name'            => self::rug_name( $quote ),
				'size'                => self::size_display( $quote ),
				'qty'                 => (int) $quote['qty'],
				'price'               => self::money( null !== $quote['final_price'] ? (float) $quote['final_price'] : null, $quote['price_currency'] ),
				'notes_line'          => $quote['notes'] ? 'Customer notes: ' . $quote['notes'] . "\n" : '',
				'quote_id'            => (int) $quote['id'],
			),
			array(),
			$c['email'] ? array( 'Reply-To: ' . $c['email'] ) : array()
		);
	}

	/** Customer-facing "your quote is ready" email (quote_sent). */
	public static function notify_customer_quote_sent( array $quote ): void {
		$c        = self::customer( $quote );
		$currency = $quote['price_currency'] ?: DRC_Settings::get( 'currency' );
		$final    = null !== $quote['final_price'] ? (float) $quote['final_price'] : null;
		$discount = null !== $quote['manual_discount_pct'] ? (float) $quote['manual_discount_pct'] : 0.0;
		$subtotal = null !== $quote['base_price'] ? (float) $quote['base_price'] : $final;
		$days     = $quote['expected_delivery_days'] ? (int) $quote['expected_delivery_days'] : null;
		$notes    = (string) $quote['vendor_notes'];
		$gst_pct  = null !== $quote['gst_pct'] && (float) $quote['gst_pct'] > 0 ? rtrim( rtrim( number_format( (float) $quote['gst_pct'], 1 ), '0' ), '.' ) . '% (included)' : 'Included where applicable';

		DRC_Email_Templates::send( 'quote_sent', $c['email'], array(
			'customer_name'      => $c['name'],
			'rug_name'           => self::rug_name( $quote ),
			'size'               => self::size_display( $quote ),
			'qty'                => (int) $quote['qty'],
			'expected_delivery'  => $days ? gmdate( 'd M Y', time() + $days * DAY_IN_SECONDS ) . " (~{$days} days)" : 'To be confirmed',
			'subtotal'           => self::money( $subtotal, $currency ),
			'discount_line_html' => $discount ? '<tr><td style="padding:8px 0;color:#666">Discount</td><td style="padding:8px 0">' . esc_html( rtrim( rtrim( number_format( $discount, 1 ), '0' ), '.' ) ) . '%</td></tr>' : '',
			'discount_line_text' => $discount ? 'Discount: ' . rtrim( rtrim( number_format( $discount, 1 ), '0' ), '.' ) . "%\n" : '',
			'gst_pct'            => $gst_pct,
			'price'              => self::money( $final, $currency ),
			'note_html'          => $notes ? '<p style="background:#fff;border-left:3px solid #d97706;padding:10px 14px;margin:16px 0"><strong>Note from us:</strong> ' . nl2br( esc_html( $notes ) ) . '</p>' : '',
			'note_text'          => $notes ? "\nNote from us: {$notes}\n" : '',
			'quote_link'         => home_url( '/my-quotes/' ),
		) );
	}

	public static function notify_customer_quote_rejected( array $quote, string $reason ): void {
		$c = self::customer( $quote );
		DRC_Email_Templates::send( 'quote_rejected', $c['email'], array(
			'customer_name'    => $c['name'],
			'rug_name'         => self::rug_name( $quote ),
			'reason_line_html' => $reason ? '<p><strong>Reason:</strong> ' . esc_html( $reason ) . '</p>' : '',
			'reason_line_text' => $reason ? "Reason: {$reason}\n" : '',
			'catalog_link'     => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/catalog/' ),
		) );
	}

	public static function notify_customer_payment_link( array $quote, WC_Order $order ): void {
		$c = self::customer( $quote );
		DRC_Email_Templates::send( 'quote_payment_link', $c['email'], array(
			'customer_name' => $c['name'],
			'order_id'      => $order->get_id(),
			'quote_id'      => (int) $quote['id'],
			'rug_name'      => self::rug_name( $quote ),
			'price'         => self::money( (float) $order->get_total(), $order->get_currency() ),
			'pay_link'      => $order->get_checkout_payment_url(),
		) );
	}
}
