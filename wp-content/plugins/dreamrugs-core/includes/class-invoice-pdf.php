<?php
/**
 * GST invoice PDFs — tax, export and proforma — for a quote or an order.
 * Ports the layout of backend/app/services/invoice_generator.py (supplier /
 * buyer / invoice-details header, item card, price calculation card,
 * amount in words, notes, signature) to HTML, and renders it through the
 * Dompdf build bundled with WooCommerce PDF Invoices & Packing Slips
 * (wcpdf_get_pdf_maker()). The tax maths itself is DRC_Invoice_Data.
 *
 * Inputs mirror invoices.py _build_pdf(): taxable value and GST are split
 * back out of the quote's canonical final_price using the GST rate that
 * was actually applied to it (CLAUDE.md: final_price is already inclusive;
 * never add on top).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Invoice_PDF {

	const TYPES = array( 'tax', 'export', 'proforma' );

	/** Builds the invoice for a quote, optionally with the order it became
	 * (for billing details). Returns ['pdf' => bytes, 'filename' => string,
	 * 'type' => effective type, 'final_price' => float] or WP_Error. */
	public static function for_quote( array $quote, string $invoice_type = 'tax', ?WC_Order $order = null ) {
		if ( ! in_array( $invoice_type, self::TYPES, true ) ) {
			$invoice_type = 'tax';
		}
		if ( empty( $quote['final_price'] ) || empty( $quote['custom_size_w'] ) || empty( $quote['custom_size_h'] ) ) {
			return new WP_Error( 'drc_invoice_incomplete', 'Quote is missing dimensions or final price — cannot generate invoice.', array( 'status' => 422 ) );
		}
		if ( ! $order && ! empty( $quote['wc_order_id'] ) ) {
			$order = wc_get_order( (int) $quote['wc_order_id'] ) ?: null;
		}

		$buyer     = self::buyer( $quote, $order );
		$is_export = 'export' === $invoice_type || $buyer['is_export'];
		$effective = ( $is_export && 'proforma' !== $invoice_type ) ? 'export' : $invoice_type;

		$final    = (float) $quote['final_price'];
		$gst_pct  = null !== $quote['gst_pct'] ? (float) $quote['gst_pct'] : (float) DRC_Settings::get( 'default_gst_pct' );
		$pre_gst  = $gst_pct ? round( $final / ( 1 + $gst_pct / 100 ), 2 ) : $final;
		$size_sqm = round( (float) $quote['custom_size_w'] * (float) $quote['custom_size_h'], 4 );
		$dims     = DRC_Quote_Context::size_display( $quote );
		$hsn      = '5703';
		if ( ! empty( $quote['product_id'] ) ) {
			$hsn = get_post_meta( (int) $quote['product_id'], '_drc_hsn_code', true ) ?: $hsn;
		}

		$settings = DRC_Settings::all();
		$data     = DRC_Invoice_Data::calculate( array(
			'quote_id'               => (int) $quote['id'],
			'invoice_type'           => $effective,
			'supplier_state_code'    => DRC_Quote_Context::gst_state_code( $settings['state_code'] ),
			'buyer_state_code'       => $buyer['state_code'],
			'lut_number'             => $settings['lut_number'],
			'is_export_buyer'        => $is_export,
			'qty'                    => max( 1, (int) $quote['qty'] ),
			'pre_gst_price'          => $pre_gst,
			'gst_amount'             => round( $final - $pre_gst, 2 ),
			'gst_pct'                => $gst_pct,
			'size_sqm'               => $size_sqm,
			'currency'               => $quote['price_currency'] ?: $settings['currency'],
			'expected_delivery_days' => $quote['expected_delivery_days'] ? (int) $quote['expected_delivery_days'] : null,
		) );

		$html = self::render_html( array(
			'data'      => $data,
			'settings'  => $settings,
			'buyer'     => $buyer,
			'rug_name'  => DRC_Quote_Context::rug_name( $quote ),
			'hsn'       => $hsn,
			'size_desc' => sprintf( '%s (%.2fm²)', $dims, $size_sqm ),
			'size_dims' => $dims,
			'qty'       => max( 1, (int) $quote['qty'] ),
			'quote_id'  => (int) $quote['id'],
			'currency'  => $quote['price_currency'] ?: $settings['currency'],
		) );

		$pdf = self::render_pdf( $html );
		if ( is_wp_error( $pdf ) ) {
			return $pdf;
		}
		return array(
			'pdf'         => $pdf,
			'filename'    => sprintf( 'invoice-Q%04d-%s.pdf', (int) $quote['id'], $effective ),
			'type'        => $effective,
			'final_price' => $final,
		);
	}

	/** An order's invoice: through its linked quote (every DRC order has
	 * one — quote acceptance and direct checkout both create it). */
	public static function for_order( WC_Order $order, string $invoice_type = 'tax' ) {
		global $wpdb;
		$quote_id = (int) $order->get_meta( '_drc_quote_id' );
		$quote    = $quote_id ? DRC_Quote_Context::quote_row( $quote_id ) : null;
		if ( ! $quote ) {
			$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}drc_quotes WHERE wc_order_id = %d ORDER BY id ASC LIMIT 1", $order->get_id() ), ARRAY_A );
			$quote = $row ?: null;
		}
		if ( ! $quote ) {
			return new WP_Error( 'drc_invoice_no_quote', 'This order has no priced quote to invoice.', array( 'status' => 422 ) );
		}
		// The order total is canonical for what was actually charged
		// (multi-line direct checkouts carry one quote per line).
		$lines = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}drc_quotes WHERE wc_order_id = %d", $order->get_id() ) );
		if ( (int) $lines > 1 ) {
			$quote['final_price'] = (float) $order->get_total() - (float) $order->get_shipping_total();
			$quote['qty']         = array_sum( array_map( function ( $i ) { return $i->get_quantity(); }, $order->get_items() ) );
		}
		return self::for_quote( $quote, $invoice_type, $order );
	}

	private static function buyer( array $quote, ?WC_Order $order ): array {
		$c       = DRC_Quote_Context::customer( $quote );
		$user_id = $c['user_id'] ?: ( $order ? $order->get_customer_id() : 0 );

		$gstin   = $order ? (string) $order->get_meta( '_billing_gstin' ) : '';
		$state   = null;
		$country = '';
		$address = '';
		$company = $c['company'];
		if ( $order ) {
			$country = $order->get_billing_country();
			$state   = DRC_Quote_Context::gst_state_code( $order->get_billing_state() );
			$address = trim( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), $order->get_billing_postcode(), $order->get_billing_country() ) ) ) );
			$company = $order->get_billing_company() ?: $company;
		}
		$is_export = false;
		if ( $user_id ) {
			$gstin   = $gstin ?: (string) get_user_meta( $user_id, 'drc_gstin', true );
			$state   = $state ?: DRC_Quote_Context::gst_state_code( get_user_meta( $user_id, 'drc_state_code', true ) ?: get_user_meta( $user_id, 'billing_state', true ) );
			$country = $country ?: (string) get_user_meta( $user_id, 'billing_country', true );
			$address = $address ?: (string) get_user_meta( $user_id, 'billing_address_1', true );
			$is_export = '1' === (string) get_user_meta( $user_id, 'drc_is_export_buyer', true );
		}
		if ( $country && ! DRC_Quote_Context::is_india( $country ) ) {
			$is_export = true;
		}
		return array(
			'name'       => $order ? ( trim( $order->get_formatted_billing_full_name() ) ?: $c['name'] ) : $c['name'],
			'company'    => $company,
			'address'    => $address,
			'gstin'      => $gstin,
			'state_code' => $state,
			'is_export'  => $is_export,
			'email'      => $order ? ( $order->get_billing_email() ?: $c['email'] ) : $c['email'],
		);
	}

	private static function money( float $amount, string $sym ): string {
		return esc_html( $sym . number_format( $amount, 2 ) );
	}

	private static function render_html( array $a ): string {
		$d        = $a['data'];
		$s        = $a['settings'];
		$b        = $a['buyer'];
		$sym      = $d['currency_symbol'];
		$supplier = DRC_Email_Templates::tenant_name();
		$gold     = '#B8860B';
		$qty      = $a['qty'];
		$plural   = 1 === $qty ? '' : 's';

		$tax_colour = $d['is_proforma'] ? '#d97706' : ( $d['is_export'] ? $gold : '#1e3a8a' );
		$calc_rows  = '<tr><td>Price: ' . self::money( $d['price_per_piece'], $sym ) . ' × ' . $qty . ' rug' . $plural . '</td><td class="r">' . self::money( $d['taxable_value'], $sym ) . '</td></tr>'
			. '<tr class="muted"><td>Pre-tax total</td><td class="r">' . self::money( $d['taxable_value'], $sym ) . '</td></tr>';
		if ( $d['is_proforma'] ) {
			$calc_rows .= '<tr class="muted"><td>GST (indicative — final invoice may vary)</td><td class="r">—</td></tr>';
		} elseif ( $d['is_export'] ) {
			$calc_rows .= '<tr class="muted"><td>IGST (0% — export under LUT)</td><td class="r">' . self::money( 0, $sym ) . '</td></tr>';
		} elseif ( $d['same_state'] ) {
			$calc_rows .= '<tr class="muted"><td>CGST @ ' . number_format( $d['gst_pct'] / 2, 1 ) . '%</td><td class="r">+' . self::money( $d['cgst'], $sym ) . '</td></tr>'
				. '<tr class="muted"><td>SGST @ ' . number_format( $d['gst_pct'] / 2, 1 ) . '%</td><td class="r">+' . self::money( $d['sgst'], $sym ) . '</td></tr>';
		} else {
			$calc_rows .= '<tr class="muted"><td>IGST @ ' . number_format( $d['gst_pct'], 1 ) . '%</td><td class="r">+' . self::money( $d['igst'], $sym ) . '</td></tr>';
		}

		$notes = array();
		if ( $d['is_proforma'] ) {
			$notes[] = 'This is a Proforma Invoice only. It is not a tax invoice and does not represent a demand for payment.';
			$notes[] = 'GST will be charged on the final Tax Invoice at applicable rates.';
			$notes[] = 'This document is valid for 15 days from the date of issue.';
		} elseif ( $d['is_export'] ) {
			$notes[] = 'This invoice is issued for export under LUT. No GST charged as per Section 16(3)(a) of IGST Act, 2017.';
		} else {
			$notes[] = 'All disputes subject to jurisdiction of the supplier\'s state courts.';
		}
		$notes[] = 'E&OE – Errors and Omissions Excepted.';
		if ( ! $d['is_proforma'] ) {
			$notes[] = 'Payment within 30 days of invoice date unless otherwise agreed.';
		}
		$notes_html = implode( '', array_map( function ( $n ) { return '<p class="small">• ' . esc_html( $n ) . '</p>'; }, $notes ) );

		$fourth = $d['delivery_date']
			? array( 'EST. DELIVERY', $d['delivery_date'] )
			: array( 'HSN CODE', $a['hsn'] );

		ob_start();
		?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><style>
	@page { margin: 15mm; }
	body { font-family: "DejaVu Sans", Helvetica, sans-serif; color: #1a1a1a; font-size: 9pt; }
	h1 { color: <?php echo esc_attr( $gold ); ?>; text-align: center; font-size: 16pt; margin: 0 0 2px; letter-spacing: .5px; }
	h2 { text-align: center; font-size: 11pt; margin: 0 0 4px; }
	.note { text-align: center; font-size: 8pt; color: #d97706; margin: 0 0 4px; }
	hr.gold { border: 0; border-top: 1.5px solid <?php echo esc_attr( $gold ); ?>; margin: 6px 0 8px; }
	table { width: 100%; border-collapse: collapse; }
	.grid td { background: #f5f5f5; border: .5px solid #e0e0e0; padding: 6px; vertical-align: top; width: 33%; }
	.label { font-size: 7pt; font-weight: bold; color: #808080; }
	.bold { font-weight: bold; }
	.card { background: #f5f5f5; border: .5px solid #e0e0e0; padding: 10px; margin-top: 8px; }
	.item-name { font-size: 12pt; font-weight: bold; margin: 0 0 1px; }
	.small { font-size: 7pt; color: #808080; margin: 0 0 2px; }
	.stat-l { font-size: 6.5pt; font-weight: bold; color: #808080; }
	.stat-v { font-size: 10pt; font-weight: bold; }
	.calc td { padding: 3px 0; }
	.calc .muted td { color: #808080; }
	.r { text-align: right; }
	.total td { border-top: 1px solid <?php echo esc_attr( $gold ); ?>; padding-top: 6px; }
	.total .lbl { font-size: 11pt; font-weight: bold; }
	.total .val { font-size: 13pt; font-weight: bold; color: <?php echo esc_attr( $gold ); ?>; text-align: right; }
	.words { border-top: .5px solid #e0e0e0; padding-top: 4px; margin: 8px 0; }
	.sig td { padding-top: 20px; vertical-align: bottom; }
	.sigline { border-top: .5px solid #808080; text-align: right; font-size: 7pt; color: #808080; }
</style></head><body>
	<h1><?php echo esc_html( strtoupper( $supplier ) ); ?></h1>
	<h2><?php echo esc_html( $d['doc_title'] ); ?></h2>
	<?php if ( $d['is_proforma'] ) : ?><p class="note">This is a Proforma Invoice — not a tax document. Subject to change before final invoice.</p><?php endif; ?>
	<?php if ( $d['is_export'] && $d['lut_number'] ) : ?><p class="note">Supply meant for Export under LUT · LUT No: <?php echo esc_html( $d['lut_number'] ); ?></p><?php endif; ?>
	<hr class="gold">

	<table class="grid"><tr>
		<td>
			<div class="label">SUPPLIER</div>
			<div class="bold"><?php echo esc_html( $supplier ); ?></div>
			<div><?php echo esc_html( $s['address'] ?: 'India' ); ?></div>
			<div>GSTIN: <?php echo esc_html( $s['gstin'] ?: '—' ); ?></div>
			<div>State: <?php echo esc_html( $d['supplier_state_name'] ); ?> (<?php echo esc_html( DRC_Quote_Context::gst_state_code( $s['state_code'] ) ?: '—' ); ?>)</div>
		</td>
		<td>
			<div class="label">BUYER</div>
			<div class="bold"><?php echo esc_html( $b['company'] ?: $b['name'] ); ?></div>
			<div>Contact: <?php echo esc_html( $b['name'] ); ?></div>
			<div><?php echo esc_html( $b['address'] ?: '—' ); ?></div>
			<div>GSTIN: <?php echo esc_html( $b['gstin'] ?: 'URP (Unregistered)' ); ?></div>
			<div>State: <?php echo esc_html( $d['buyer_state_name'] ); ?> (<?php echo esc_html( $b['state_code'] ?: '—' ); ?>)</div>
		</td>
		<td>
			<div class="label">INVOICE DETAILS</div>
			<div class="bold">Invoice No: <?php echo esc_html( $d['invoice_number'] ); ?></div>
			<div>Date: <?php echo esc_html( $d['invoice_date'] ); ?></div>
			<div>Quote Ref: #<?php echo (int) $a['quote_id']; ?></div>
			<div>Currency: <?php echo esc_html( $a['currency'] ); ?></div>
			<div class="bold" style="font-size:8pt;color:<?php echo esc_attr( $tax_colour ); ?>;"><?php echo esc_html( $d['tax_type_label'] ); ?></div>
		</td>
	</tr></table>

	<div class="card">
		<p class="item-name"><?php echo esc_html( $a['rug_name'] ); ?></p>
		<p class="small">HSN <?php echo esc_html( $a['hsn'] ); ?> · <?php echo esc_html( $a['size_desc'] ); ?></p>
		<table style="margin-top:4px;"><tr>
			<td class="stat-l">SIZE (PER PIECE)</td><td class="stat-l">QUANTITY</td><td class="stat-l">PRICE / RUG</td><td class="stat-l"><?php echo esc_html( $fourth[0] ); ?></td>
		</tr><tr>
			<td class="stat-v"><?php echo esc_html( $a['size_dims'] ); ?></td>
			<td class="stat-v"><?php echo (int) $qty; ?> pc<?php echo esc_html( $plural ); ?></td>
			<td class="stat-v"><?php echo self::money( $d['price_per_piece'], $sym ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in money(). ?></td>
			<td class="stat-v"><?php echo esc_html( $fourth[1] ); ?></td>
		</tr></table>
	</div>

	<div class="card">
		<table class="calc"><?php echo $calc_rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above. ?></table>
		<table class="total"><tr>
			<td class="lbl"><?php echo $d['is_proforma'] ? 'Total (indicative)' : 'Total (incl. GST)'; ?></td>
			<td class="val"><?php echo self::money( $d['grand_total'], $sym ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
		</tr></table>
	</div>

	<p class="words"><strong>Amount in Words:</strong> <?php echo esc_html( $d['total_in_words'] ); ?></p>
	<?php echo $notes_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per note above. ?>

	<table class="sig"><tr>
		<td class="small" style="width:60%;">This is a computer-generated invoice.</td>
		<td class="r bold">For <?php echo esc_html( $supplier ); ?></td>
	</tr><tr><td></td><td class="sigline">Authorised Signatory</td></tr></table>
</body></html>
		<?php
		return (string) ob_get_clean();
	}

	/** @return string|WP_Error */
	private static function render_pdf( string $html ) {
		if ( ! function_exists( 'wcpdf_get_pdf_maker' ) ) {
			return new WP_Error( 'drc_pdf_unavailable', 'PDF rendering needs the WooCommerce PDF Invoices & Packing Slips plugin to be active.', array( 'status' => 500 ) );
		}
		$maker = wcpdf_get_pdf_maker( $html, array( 'paper_size' => 'A4', 'paper_orientation' => 'portrait', 'font_subsetting' => true ) );
		$pdf   = $maker->output();
		if ( ! $pdf ) {
			return new WP_Error( 'drc_pdf_failed', 'The invoice PDF could not be rendered.', array( 'status' => 500 ) );
		}
		return $pdf;
	}

	/** Emails a rendered invoice with the invoice_email template — mirrors
	 * invoices.py send_quote_email(). @return true|WP_Error */
	public static function email( array $result, array $quote, string $to, ?WC_Order $order = null ) {
		if ( ! $to || ! is_email( $to ) ) {
			return new WP_Error( 'drc_no_recipient', 'No recipient email address available.', array( 'status' => 422 ) );
		}
		$labels = array( 'proforma' => 'Proforma Invoice', 'tax' => 'Tax Invoice', 'export' => 'Export Invoice' );
		$c      = $quote ? DRC_Quote_Context::customer( $quote ) : array( 'name' => $order ? $order->get_formatted_billing_full_name() : 'Customer' );
		$sent   = DRC_Email_Templates::send(
			'invoice_email',
			$to,
			array(
				'customer_name'      => $c['name'],
				'invoice_type_label' => $labels[ $result['type'] ] ?? 'Invoice',
				'rug_name'           => $quote ? DRC_Quote_Context::rug_name( $quote ) : 'Your order',
				'size'               => $quote ? DRC_Quote_Context::size_display( $quote ) : '',
				'qty'                => $quote ? max( 1, (int) $quote['qty'] ) : 1,
				'price'              => DRC_Quote_Context::money( (float) $result['final_price'], $quote['price_currency'] ?? null ),
				'disclaimer'         => 'proforma' === $result['type']
					? 'This is a proforma invoice. The final tax invoice will be issued upon order confirmation.'
					: 'Please make payment as per the invoice terms.',
			),
			array( array( 'content' => $result['pdf'], 'filename' => $result['filename'] ) )
		);
		return $sent ? true : new WP_Error( 'drc_mail_failed', 'The email could not be sent — check the site\'s mail settings.', array( 'status' => 502 ) );
	}

	/** Streams a PDF result to the browser and ends the request. */
	public static function stream( array $result, bool $download = true ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $download ? 'attachment' : 'inline' ) . '; filename="' . $result['filename'] . '"' );
		header( 'Content-Length: ' . strlen( $result['pdf'] ) );
		echo $result['pdf']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF body.
		exit;
	}
}
