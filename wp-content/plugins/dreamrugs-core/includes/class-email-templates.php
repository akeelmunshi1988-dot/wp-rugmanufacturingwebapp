<?php
/**
 * Port of backend/app/services/email_service.py's template system: the 11
 * admin-editable transactional emails, with {{var}} placeholders. Defaults
 * reproduce the original copy; a vendor's edits live in one wp_options
 * entry keyed by template key (single-tenant, so no EmailTemplate table).
 * Edited at DreamRugs → Email Templates (DRC_Admin_Rest_Routes).
 *
 * Sending goes through wp_mail(), so whatever SMTP plugin/config the site
 * uses applies. A template with a non-empty body_html is sent as HTML;
 * otherwise as plain text — same rule as the original send_email().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Email_Templates {

	const OPTION_KEY = 'drc_email_templates';

	/** Shared header/footer wrapper for the HTML defaults — the original's
	 * inline-styled dark header card. */
	private static function wrap_html( string $subtitle, string $inner ): string {
		return '<html><body style="font-family:Arial,sans-serif;color:#222;max-width:600px;margin:0 auto">'
			. '<div style="background:#1c1c1b;padding:24px 32px;border-radius:12px 12px 0 0">'
			. '<h1 style="color:#fbbf24;margin:0;font-size:22px">{{tenant_name}}</h1>'
			. '<p style="color:#929290;margin:4px 0 0;font-size:13px">' . $subtitle . '</p></div>'
			. '<div style="background:#f9f9f9;padding:24px 32px;border:1px solid #e5e5e5;border-top:none">'
			. $inner
			. '</div></body></html>';
	}

	public static function defaults(): array {
		$button = function ( string $href_var, string $label ): string {
			return '<p style="margin:24px 0"><a href="{{' . $href_var . '}}" style="background:#d97706;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:600">' . $label . '</a></p>';
		};

		return array(
			'quote_sent' => array(
				'name'      => 'Quote Sent to Customer',
				'subject'   => 'Your Quote is Ready — {{rug_name}} | {{tenant_name}}',
				'body_html' => self::wrap_html(
					'Your quote is ready for review',
					'<p>Dear <strong>{{customer_name}}</strong>,</p>'
					. '<p>Great news — we\'ve prepared a quote for your order. Please review the details below:</p>'
					. '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px">'
					. '<tr><td style="padding:8px 0;color:#666;width:40%">Rug</td><td style="padding:8px 0;font-weight:600">{{rug_name}}</td></tr>'
					. '<tr><td style="padding:8px 0;color:#666">Size</td><td style="padding:8px 0">{{size}}</td></tr>'
					. '<tr><td style="padding:8px 0;color:#666">Quantity</td><td style="padding:8px 0">{{qty}}</td></tr>'
					. '<tr><td style="padding:8px 0;color:#666">Expected delivery</td><td style="padding:8px 0">{{expected_delivery}}</td></tr>'
					. '<tr style="border-top:1px solid #e5e5e5"><td style="padding:8px 0;color:#666">Subtotal</td><td style="padding:8px 0">{{subtotal}}</td></tr>'
					. '{{discount_line_html}}'
					. '<tr><td style="padding:8px 0;color:#666">GST</td><td style="padding:8px 0">{{gst_pct}}</td></tr>'
					. '<tr style="border-top:2px solid #e5e5e5"><td style="padding:12px 0;color:#666;font-weight:600">Total Price</td>'
					. '<td style="padding:12px 0;font-size:18px;font-weight:700;color:#d97706">{{price}}</td></tr></table>'
					. '{{note_html}}'
					. $button( 'quote_link', 'View My Quotes' )
					. '<p>To <strong>accept or decline</strong> this quote, click above or log in to your account and visit <em>My Quotes</em>.</p>'
					. '<p style="color:#888;font-size:12px;margin-top:24px">This quote is valid for 7 days. For any queries, reply to this email.<br>— {{tenant_name}} Team</p>'
				),
				'body_text' => "Dear {{customer_name}},\n\nYour quote from {{tenant_name}} is ready.\n\nRug: {{rug_name}}\nSize: {{size}}\nQty: {{qty}}\nExpected delivery: {{expected_delivery}}\nSubtotal: {{subtotal}}\n{{discount_line_text}}GST: {{gst_pct}}\nTotal: {{price}}\n{{note_text}}\nView and respond to your quote: {{quote_link}}\n\n– {{tenant_name}} Team",
			),
			'quote_rejected' => array(
				'name'      => 'Quote Declined',
				'subject'   => 'Update on your quote — {{rug_name}} | {{tenant_name}}',
				'body_html' => self::wrap_html(
					'Update on your quote request',
					'<p>Dear <strong>{{customer_name}}</strong>,</p>'
					. '<p>Thank you for your interest in <strong>{{rug_name}}</strong>. Unfortunately, we\'re unable to move forward with this request at this time.</p>'
					. '{{reason_line_html}}'
					. '<p>We\'d still love to help you find the right rug — browse our full collection or reach out to discuss alternatives.</p>'
					. $button( 'catalog_link', 'Browse Collection' )
					. '<p style="color:#888;font-size:12px;margin-top:24px">For any queries, reply to this email.<br>— {{tenant_name}} Team</p>'
				),
				'body_text' => "Dear {{customer_name}},\n\nThank you for your interest in {{rug_name}}. Unfortunately, we're unable to move forward with this request at this time.\n\n{{reason_line_text}}\nWe'd still love to help you find the right rug — browse our collection: {{catalog_link}}\n\n– {{tenant_name}} Team",
			),
			'quote_payment_link' => array(
				'name'      => 'Order Ready for Payment',
				'subject'   => 'Your order #{{order_id}} is ready for payment | {{tenant_name}}',
				'body_html' => self::wrap_html(
					'Your order is confirmed and awaiting payment',
					'<p>Dear <strong>{{customer_name}}</strong>,</p>'
					. '<p>Good news — your order <strong>#{{order_id}}</strong> (from quote #{{quote_id}}) for <strong>{{rug_name}}</strong> has been confirmed.</p>'
					. '<p>Total due: <strong>{{price}}</strong></p>'
					. $button( 'pay_link', 'Complete Payment' )
					. '<p style="color:#888;font-size:12px;margin-top:24px">For any queries, reply to this email.<br>— {{tenant_name}} Team</p>'
				),
				'body_text' => "Dear {{customer_name}},\n\nYour order #{{order_id}} (from quote #{{quote_id}}) for {{rug_name}} has been confirmed and is awaiting payment.\n\nTotal due: {{price}}\n\nComplete your payment here:\n{{pay_link}}\n\n– {{tenant_name}} Team",
			),
			'invoice_email' => array(
				'name'      => 'Invoice Email',
				'subject'   => '{{invoice_type_label}} – {{rug_name}} – {{tenant_name}}',
				'body_html' => '',
				'body_text' => "Dear {{customer_name}},\n\nPlease find attached your {{invoice_type_label}} from {{tenant_name}}.\n\nOrder Details:\n  Rug      : {{rug_name}}\n  Size     : {{size}}\n  Quantity : {{qty}}\n  Amount   : {{price}}\n\n{{disclaimer}}\n\nFor any queries, please reply to this email.\n\nBest regards,\n{{tenant_name}}\n",
			),
			'vendor_review_request' => array(
				'name'      => 'Customer Review Request (to Vendor)',
				'subject'   => '[Review Request #{{request_num}}] {{customer_name}} — {{rug_name}}',
				'body_html' => '',
				'body_text' => "Hello {{tenant_name}} team,\n\n{{customer_name}} ({{customer_email}}) has requested a review of Quote #{{quote_id}}.\n\nRug: {{rug_name}}\nSize: {{size}}\nStatus: {{status}}\nReview Request: #{{request_num}} of {{max_requests}}\n\nPlease log in to the admin panel to review and update the quote.\n\n— {{tenant_name}} System",
			),
			'vendor_quote_request' => array(
				'name'      => 'New Quote Request (to Vendor)',
				'subject'   => 'New quote request — {{rug_name}} from {{customer_name}}',
				'body_html' => '',
				'body_text' => "Hello {{tenant_name}} team,\n\n{{customer_name}} ({{customer_email}}{{customer_phone_line}}) just requested a quote.\n\nRug: {{rug_name}}\nSize: {{size}}\nQuantity: {{qty}}\nEstimated price: {{price}}\n{{notes_line}}\nQuote #{{quote_id}} has been created as a draft. Log in to the admin panel to review and send it.\n\n— {{tenant_name}} System",
			),
			'vendor_custom_rug_request' => array(
				'name'      => 'New Custom Rug Request (to Vendor)',
				'subject'   => 'New custom rug request from {{customer_name}}',
				'body_html' => '',
				'body_text' => "Hello {{tenant_name}} team,\n\n{{customer_name}} ({{customer_email}}{{customer_phone_line}}) submitted a custom rug design request.\n\nRoom: {{room_type}}\nApproximate size: {{size}}\nQuantity: {{qty}}\nMaterial preference: {{material_preference}}\nBudget range: {{budget_range}}\n{{notes_line}}{{images_line}}\nQuote #{{quote_id}} has been created as a draft with no price yet. Log in to the admin panel, review the brief, set a price, and send it to the customer.\n\n— {{tenant_name}} System",
			),
			'vendor_trade_enquiry' => array(
				'name'      => 'New Trade Enquiry (to Vendor)',
				'subject'   => 'New trade enquiry from {{customer_name}} ({{company}})',
				'body_html' => '',
				'body_text' => "Hello {{tenant_name}} team,\n\n{{customer_name}} ({{customer_email}}, {{customer_phone}}) submitted a trade partner enquiry.\n\nProfession: {{profession}}\nCompany / studio: {{company}}\nWebsite / Instagram: {{website}}\nCity: {{city}}\nCountry: {{country}}\nPrimary project type: {{project_type}}\n\nProject brief:\n{{project_brief}}\n\nLog in to the admin panel's Trade Enquiries page to review this enquiry.\n\n— {{tenant_name}} System",
			),
			'vendor_homepage_enquiry' => array(
				'name'      => 'New Homepage Enquiry (to Vendor)',
				'subject'   => 'New website enquiry from {{customer_name}}',
				'body_html' => '',
				'body_text' => "Hello {{tenant_name}} team,\n\n{{customer_name}} ({{customer_email}}{{customer_phone_line}}) sent a message through the homepage contact form.\n\n{{message}}\n\nLog in to the admin panel's Homepage Enquiries page to review it.\n\n— {{tenant_name}} System",
			),
			'vendor_new_review' => array(
				'name'      => 'New Rug Review (to Vendor)',
				'subject'   => 'New {{rating}}-star review of {{rug_name}} — awaiting approval',
				'body_html' => '',
				'body_text' => "Hello {{tenant_name}} team,\n\n{{customer_name}} ({{customer_email}}) reviewed {{rug_name}}.\n\nRating: {{rating}} / 5\nVerified buyer: {{verified}}\nTitle: {{title}}\n\n{{body}}\n\nIt won't appear on the storefront until you approve it under Products → Reviews.\n\n— {{tenant_name}} System",
			),
			'customer_verification' => array(
				'name'      => 'Registration Verification',
				'subject'   => 'Verify your email — {{tenant_name}}',
				'body_html' => self::wrap_html(
					'Confirm your email address',
					'<p>Dear <strong>{{customer_name}}</strong>,</p>'
					. '<p>Thanks for creating an account with {{tenant_name}}. Please confirm your email address to activate your account:</p>'
					. $button( 'verification_link', 'Verify Email Address' )
					. '<p style="color:#888;font-size:12px">Or copy this link into your browser:<br>{{verification_link}}</p>'
					. '<p style="color:#888;font-size:12px;margin-top:24px">This link expires in 24 hours. If you didn\'t create this account, you can ignore this email.<br>— {{tenant_name}} Team</p>'
				),
				'body_text' => "Dear {{customer_name}},\n\nThanks for creating an account with {{tenant_name}}. Please confirm your email address by visiting the link below:\n\n{{verification_link}}\n\nThis link expires in 24 hours. If you didn't create this account, you can ignore this email.\n\n– {{tenant_name}} Team",
			),
			'customer_password_reset' => array(
				'name'      => 'Customer Password Reset',
				'subject'   => 'Reset your password — {{tenant_name}}',
				'body_html' => self::wrap_html(
					'Password reset request',
					'<p>Dear <strong>{{customer_name}}</strong>,</p>'
					. '<p>We received a request to reset your {{tenant_name}} account password. Click below to choose a new one:</p>'
					. $button( 'reset_link', 'Reset Password' )
					. '<p style="color:#888;font-size:12px">Or copy this link into your browser:<br>{{reset_link}}</p>'
					. '<p style="color:#888;font-size:12px;margin-top:24px">This link expires in 1 hour. If you didn\'t request this, you can safely ignore this email — your password won\'t change.<br>— {{tenant_name}} Team</p>'
				),
				'body_text' => "Dear {{customer_name}},\n\nWe received a request to reset your {{tenant_name}} account password. Visit the link below to choose a new one:\n\n{{reset_link}}\n\nThis link expires in 1 hour. If you didn't request this, you can safely ignore this email — your password won't change.\n\n– {{tenant_name}} Team",
			),
			'staff_password_reset' => array(
				'name'      => 'Staff Password Reset',
				'subject'   => 'Reset your password — {{tenant_name}}',
				'body_html' => self::wrap_html(
					'Password reset request',
					'<p>Dear <strong>{{staff_name}}</strong>,</p>'
					. '<p>We received a request to reset your {{tenant_name}} staff account password. Click below to choose a new one:</p>'
					. $button( 'reset_link', 'Reset Password' )
					. '<p style="color:#888;font-size:12px">Or copy this link into your browser:<br>{{reset_link}}</p>'
					. '<p style="color:#888;font-size:12px;margin-top:24px">This link expires in 1 hour. If you didn\'t request this, you can safely ignore this email — your password won\'t change.<br>— {{tenant_name}} Team</p>'
				),
				'body_text' => "Dear {{staff_name}},\n\nWe received a request to reset your {{tenant_name}} staff account password. Visit the link below to choose a new one:\n\n{{reset_link}}\n\nThis link expires in 1 hour. If you didn't request this, you can safely ignore this email — your password won't change.\n\n– {{tenant_name}} Team",
			),
		);
	}

	/** Every template, defaults merged with the vendor's saved overrides. */
	public static function all(): array {
		$saved    = get_option( self::OPTION_KEY, array() );
		$saved    = is_array( $saved ) ? $saved : array();
		$out      = array();
		foreach ( self::defaults() as $key => $default ) {
			$override = isset( $saved[ $key ] ) && is_array( $saved[ $key ] ) ? $saved[ $key ] : array();
			$is_active = ! isset( $override['is_active'] ) || (bool) $override['is_active'];
			$out[ $key ] = array(
				'key'         => $key,
				'name'        => $default['name'],
				'subject'     => $override['subject'] ?? $default['subject'],
				'body_html'   => $override['body_html'] ?? $default['body_html'],
				'body_text'   => $override['body_text'] ?? $default['body_text'],
				'is_active'   => $is_active,
				'is_modified' => ! empty( $override ),
				'placeholders' => self::placeholders( $default ),
			);
		}
		return $out;
	}

	private static function placeholders( array $template ): array {
		preg_match_all( '/\{\{([a-z_]+)\}\}/', $template['subject'] . $template['body_html'] . $template['body_text'], $m );
		return array_values( array_unique( $m[1] ) );
	}

	public static function save( string $key, array $fields ): void {
		if ( ! isset( self::defaults()[ $key ] ) ) {
			return;
		}
		$saved          = get_option( self::OPTION_KEY, array() );
		$saved          = is_array( $saved ) ? $saved : array();
		$saved[ $key ]  = array(
			'subject'   => sanitize_text_field( $fields['subject'] ?? '' ),
			// Vendor-authored email HTML (staff with manage_woocommerce only);
			// wp_kses_post keeps the inline-styled table markup the defaults use.
			'body_html' => wp_kses_post( $fields['body_html'] ?? '' ),
			'body_text' => sanitize_textarea_field( $fields['body_text'] ?? '' ),
			'is_active' => ! empty( $fields['is_active'] ),
		);
		update_option( self::OPTION_KEY, $saved, false );
	}

	public static function reset( string $key ): void {
		$saved = get_option( self::OPTION_KEY, array() );
		if ( is_array( $saved ) && isset( $saved[ $key ] ) ) {
			unset( $saved[ $key ] );
			update_option( self::OPTION_KEY, $saved, false );
		}
	}

	/** Mirrors render_template(): an inactive override falls back to the
	 * default copy. Values substituted into body_html are HTML-escaped unless
	 * the variable name ends in _html (pre-built markup lines). */
	public static function render( string $key, array $vars ): array {
		$templates = self::all();
		$defaults  = self::defaults();
		if ( ! isset( $templates[ $key ] ) ) {
			return array( '', '', '' );
		}
		$t = $templates[ $key ]['is_active'] ? $templates[ $key ] : $defaults[ $key ];
		$vars = array_merge( array( 'tenant_name' => self::tenant_name() ), $vars );

		$sub = function ( string $text, bool $html ) use ( $vars ): string {
			foreach ( $vars as $k => $v ) {
				$value = null === $v ? '' : (string) $v;
				if ( $html && '_html' !== substr( $k, -5 ) ) {
					$value = esc_html( $value );
				}
				$text = str_replace( '{{' . $k . '}}', $value, $text );
			}
			return $text;
		};
		return array( $sub( $t['subject'], false ), $sub( $t['body_text'], false ), $sub( $t['body_html'], true ) );
	}

	public static function tenant_name(): string {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ?: 'DreamRugsCreation';
	}

	/** Vendor-facing recipient — same fallback as email_service.vendor_recipient(). */
	public static function vendor_recipient(): string {
		return DRC_Settings::get( 'vendor_notification_email' ) ?: (string) get_option( 'admin_email' );
	}

	/**
	 * Renders and sends a template. $attachments is a list of
	 * ['content' => bytes, 'filename' => string]; they're written to a temp
	 * dir for wp_mail() and removed afterwards. Returns wp_mail()'s result.
	 */
	public static function send( string $key, string $to, array $vars, array $attachments = array(), array $headers = array() ): bool {
		if ( ! $to || ! is_email( $to ) ) {
			return false;
		}
		list( $subject, $text, $html ) = self::render( $key, $vars );
		$body = $text;
		if ( '' !== trim( $html ) ) {
			$body      = $html;
			$headers[] = 'Content-Type: text/html; charset=UTF-8';
		}

		$paths = array();
		if ( $attachments ) {
			$dir = trailingslashit( get_temp_dir() ) . 'drc-mail-' . wp_generate_password( 12, false );
			wp_mkdir_p( $dir );
			foreach ( $attachments as $a ) {
				$path = $dir . '/' . sanitize_file_name( $a['filename'] );
				file_put_contents( $path, $a['content'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp attachment for wp_mail().
				$paths[] = $path;
			}
		}

		$sent = wp_mail( $to, $subject, $body, $headers, $paths );

		foreach ( $paths as $path ) {
			wp_delete_file( $path );
		}
		if ( $paths ) {
			@rmdir( dirname( $paths[0] ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return (bool) $sent;
	}
}
