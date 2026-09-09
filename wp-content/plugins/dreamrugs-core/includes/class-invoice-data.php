<?php
/**
 * Port of the India-GST business logic from
 * backend/app/services/invoice_generator.py — deliberately split from PDF
 * rendering. Per the migration plan, the actual PDF document is rendered
 * via WooCommerce PDF Invoices & Packing Slips' template-override hooks
 * (Phase 6); this class only computes the numbers/labels a template needs
 * (tax-type determination, CGST/SGST/IGST split, fiscal-year invoice
 * numbering, Lakh/Crore amount-in-words) so that logic is independently
 * portable/testable regardless of which plugin ends up rendering the page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Invoice_Data {

	const STATE_NAMES = array(
		'01' => 'Jammu & Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab',
		'04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana',
		'07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh',
		'10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh',
		'13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram',
		'16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam',
		'19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha',
		'22' => 'Chhattisgarh', '23' => 'Madhya Pradesh', '24' => 'Gujarat',
		'25' => 'Daman & Diu', '26' => 'Dadra & Nagar Haveli', '27' => 'Maharashtra',
		'28' => 'Andhra Pradesh', '29' => 'Karnataka', '30' => 'Goa',
		'31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu',
		'34' => 'Puducherry', '35' => 'Andaman & Nicobar', '36' => 'Telangana',
		'37' => 'Andhra Pradesh (New)',
	);

	private const CURRENCY_SYMBOLS = array( 'INR' => 'Rs. ', 'USD' => '$', 'EUR' => '€', 'GBP' => '£' );

	/** Mirrors _num_to_words() — Lakh/Crore Indian numbering, not Million/Billion. */
	public static function num_to_words( float $n ): string {
		$rupees = (int) $n;
		$paise  = (int) round( ( $n - $rupees ) * 100 );
		$result = self::words( $rupees ) . ' Rupees';
		if ( $paise ) {
			$result .= ' and ' . self::words( $paise ) . ' Paise';
		}
		return $result . ' Only';
	}

	private static function words( int $num ): string {
		static $ones = array(
			'', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven',
			'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
			'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen',
		);
		static $tens = array( '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety' );

		if ( $num < 20 ) {
			return $ones[ $num ];
		}
		if ( $num < 100 ) {
			return $tens[ intdiv( $num, 10 ) ] . ( $num % 10 ? ' ' . $ones[ $num % 10 ] : '' );
		}
		if ( $num < 1000 ) {
			return $ones[ intdiv( $num, 100 ) ] . ' Hundred' . ( $num % 100 ? ' ' . self::words( $num % 100 ) : '' );
		}
		if ( $num < 100000 ) {
			return self::words( intdiv( $num, 1000 ) ) . ' Thousand' . ( $num % 1000 ? ' ' . self::words( $num % 1000 ) : '' );
		}
		if ( $num < 10000000 ) {
			return self::words( intdiv( $num, 100000 ) ) . ' Lakh' . ( $num % 100000 ? ' ' . self::words( $num % 100000 ) : '' );
		}
		return self::words( intdiv( $num, 10000000 ) ) . ' Crore' . ( $num % 10000000 ? ' ' . self::words( $num % 10000000 ) : '' );
	}

	/**
	 * Mirrors the tax-type/invoice-number/total computation from the top
	 * of generate_invoice_pdf() — everything a PDF template needs, before
	 * any ReportLab-specific layout code. $args keys match the original
	 * function's parameters (quote_id, invoice_type, supplier_*, buyer_*,
	 * is_export_buyer, qty, pre_gst_price, gst_amount, gst_pct, size_sqm,
	 * currency, expected_delivery_days).
	 */
	public static function calculate( array $args ): array {
		$quote_id            = (int) $args['quote_id'];
		$invoice_type        = $args['invoice_type'];
		$supplier_state_code = $args['supplier_state_code'] ?? null;
		$buyer_state_code    = $args['buyer_state_code'] ?? null;
		$lut_number          = $args['lut_number'] ?? null;
		$is_export_buyer     = ! empty( $args['is_export_buyer'] );
		$qty                 = (int) $args['qty'];
		$pre_gst_price       = (float) $args['pre_gst_price'];
		$gst_amount          = (float) $args['gst_amount'];
		$gst_pct             = (float) $args['gst_pct'];
		$size_sqm            = (float) $args['size_sqm'];
		$currency            = $args['currency'] ?? 'INR';
		$expected_delivery_days = $args['expected_delivery_days'] ?? null;

		$is_proforma  = $invoice_type === 'proforma';
		$is_export    = ( $invoice_type === 'export' || $is_export_buyer ) && ! $is_proforma;
		$same_state   = $supplier_state_code && $buyer_state_code
			&& $supplier_state_code === $buyer_state_code && ! $is_export && ! $is_proforma;
		$inter_state  = ! $same_state && ! $is_export && ! $is_proforma;

		// Financial year rolls over in April, not January.
		$now      = current_datetime();
		$fy_start = (int) $now->format( 'n' ) >= 4 ? (int) $now->format( 'Y' ) : (int) $now->format( 'Y' ) - 1;
		$fy_label = substr( (string) $fy_start, 2 ) . substr( (string) ( $fy_start + 1 ), 2 );
		$invoice_number = sprintf( 'INV/FY%s/%04d', $fy_label, $quote_id );
		$invoice_date   = $now->format( 'd-m-Y' );

		$total_sqm      = $size_sqm * $qty;
		$taxable_value  = round( $pre_gst_price, 2 );
		$price_per_piece = $qty > 0 ? round( $pre_gst_price / $qty, 2 ) : 0.0;

		if ( $is_export || $is_proforma ) {
			$cgst = $sgst = $igst = 0.0;
			$tax_total = 0.0;
		} elseif ( $same_state ) {
			$cgst = round( $gst_amount / 2, 2 );
			$sgst = round( $gst_amount - $cgst, 2 );
			$igst = 0.0;
			$tax_total = $cgst + $sgst;
		} else {
			$igst = round( $gst_amount, 2 );
			$cgst = $sgst = 0.0;
			$tax_total = $igst;
		}

		$grand_total    = round( $taxable_value + $tax_total, 2 );
		$total_in_words = self::num_to_words( $grand_total );

		$delivery_date = null;
		if ( $expected_delivery_days ) {
			$delivery_date = $now->modify( "+{$expected_delivery_days} days" )->format( 'd-m-Y' );
		}

		$doc_title = $is_proforma ? 'PROFORMA INVOICE' : ( $is_export ? 'EXPORT INVOICE' : 'TAX INVOICE' );
		$tax_type_label = $inter_state ? 'IGST' : ( $same_state ? 'CGST + SGST' : ( $is_proforma ? 'Proforma (No Tax)' : 'Export (Zero-Rated)' ) );

		return array(
			'doc_title'          => $doc_title,
			'is_proforma'        => $is_proforma,
			'is_export'          => $is_export,
			'same_state'         => $same_state,
			'inter_state'        => $inter_state,
			'lut_number'         => $lut_number,
			'supplier_state_name' => self::STATE_NAMES[ $supplier_state_code ] ?? ( $supplier_state_code ?: '' ),
			'buyer_state_name'   => self::STATE_NAMES[ $buyer_state_code ] ?? ( $buyer_state_code ?: '' ),
			'invoice_number'     => $invoice_number,
			'invoice_date'       => $invoice_date,
			'tax_type_label'     => $tax_type_label,
			'currency_symbol'    => self::CURRENCY_SYMBOLS[ $currency ] ?? "{$currency} ",
			'total_sqm'          => $total_sqm,
			'taxable_value'      => $taxable_value,
			'price_per_piece'    => $price_per_piece,
			'cgst'               => $cgst,
			'sgst'               => $sgst,
			'igst'               => $igst,
			'gst_pct'            => $gst_pct,
			'tax_total'          => $tax_total,
			'grand_total'        => $grand_total,
			'total_in_words'     => $total_in_words,
			'delivery_date'      => $delivery_date,
		);
	}
}
