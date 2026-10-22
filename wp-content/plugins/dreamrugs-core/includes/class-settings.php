<?php
/**
 * Replaces the original app's per-tenant Tenant row with a single wp_options
 * entry — this is confirmed single-tenant (see the migration plan's
 * "Scope decisions"), so there's no need for a settings table keyed by
 * tenant. Field names mirror Tenant's business-relevant columns
 * (backend/app/models/models.py) — the SaaS/billing columns (plan,
 * plan_status, razorpay_subscription_id, ai_credits_used, etc.) are
 * intentionally not carried over; that system is dormant
 * (SHOW_BILLING=false) even in the original app.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Settings {

	const OPTION_KEY = 'drc_settings';

	public static function defaults(): array {
		return array(
			'gstin'                        => '',
			'state_code'                   => '',
			'address'                      => '',
			'lut_number'                   => '',
			'currency'                     => 'INR', // always overridden by WooCommerce's store currency in all() — see there.
			'default_profit_margin_pct'    => 40.0,
			'rush_surcharge_pct'           => 25.0,
			'default_gst_pct'              => 12.0,
			'gst_inclusive'                => false,
			'large_format_threshold_sqm'   => 20.0,
			'large_format_surcharge_pct'   => 5.0,
			'default_size_unit'            => 'ft',
			'contact_emails'               => array(),
			'contact_phones'               => array(),
			'contact_address'              => '',
			'contact_hours'                => '',
			'hero_image_url'               => '',
			'hero_images'                  => array(), // ordered list[{image_url, alt_text}] — homepage hero carousel
			'hero_eyebrow'                 => '',
			'hero_heading'                 => '',
			'hero_cta_label'               => '',
			'certifications'               => array(),
			'catalog_pdf_url'              => '', // downloadable lookbook — footer.php already reads this key, it just never had a settings field/admin control before
			'enabled_currencies'           => array(), // currency codes offered in the storefront's currency switcher; empty = every code with a rate in `exchange_rates`
			'storefront_menu_labels'       => array(), // {nav-item-key: custom label} overrides for header.php's nav/mega-menu, edited via the Settings screen
			'homepage_full_bleed_image_url'   => '',
			'homepage_full_bleed_alt_text'    => '',
			'homepage_full_bleed_enabled'     => true,
			'homepage_values_eyebrow'         => '',
			'homepage_values_headline'        => '',
			'homepage_values_headline_accent' => '',
			'homepage_values_description'     => '',
			'homepage_values_items'           => array(), // list[{icon, title, description}]
			'homepage_values_enabled'         => true,
			'homepage_intro_title_line_one'    => '',
			'homepage_intro_title_line_two'    => '',
			'homepage_intro_label'             => '',
			'homepage_intro_description'       => '',
			'homepage_intro_cta_label'         => '',
			'homepage_intro_cta_url'           => '',
			'homepage_intro_trusted_by_text'   => '',
			'homepage_intro_enabled'           => true,
			'homepage_contact_image_url'       => '',
			'homepage_contact_image_alt'       => '',
			'homepage_contact_heading'         => '',
			'homepage_contact_consent_text'    => '',
			'homepage_contact_button_label'    => '',
			'homepage_contact_success_message' => '',
			'homepage_contact_enabled'         => true,
			'refund_cancellation_policy_html'  => '',
			'privacy_policy_html'              => '',
			'default_catalog_additional_information_html' => '', // fallback rug-detail-accordion copy for products with no per-product override
			'product_accordion_sections'       => array(), // list[{id, title, html}] — extra accordion sections shown on every rug detail page
			'about_us_content_html'            => '', // main narrative body on the public About page
			// Structured /about page content (hero/credentials/story/process/
			// principles/founder/cta, each with its own `enabled` flag) — kept
			// as a single JSON blob rather than a full nested repeater UI here;
			// see the 'json' field type in assets/admin.js. A dedicated
			// section-by-section editor (matching the original app's AboutPage.tsx
			// admin screen) is a reasonable follow-up, not built in this pass.
			'about_page'                       => array(),
			'vendor_notification_email'    => '', // falls back to get_option('admin_email') at send time
			'order_tracking_eyebrow'              => '',
			'order_tracking_heading'              => '',
			'order_tracking_body'                 => '',
			'order_tracking_shipping_policy_url'  => '',
			'order_tracking_carriers'             => array(), // list[{label, image_url}]
			'order_tracking_steps'                => array(), // list[{title, description}]
			'order_tracking_media_url'            => '',
			'order_tracking_media_type'           => '', // "image" | "video"
			'colour_matching_eyebrow'      => '',
			'colour_matching_heading'      => '',
			'colour_matching_body'         => '',
			'colour_matching_items'        => array(), // list[{title, description, image_url}]
			// Off by default — matches the original app's current live
			// behaviour (FEATURE_FLAGS.SHOW_DIRECT_PURCHASE = false): the
			// product page shows only "Request a Quote", no visible price,
			// no Add to Cart/Buy Now. Turning this on reveals the fuller
			// live-estimate + WooCommerce cart/checkout flow already built
			// into single-product.php — a deliberate opt-in differentiator
			// for a vendor who wants direct e-commerce rather than
			// quote-only, not something every install should default to.
			'show_direct_purchase'         => false,
			// Ordered list[int] of admin-picked WooCommerce product IDs for the
			// homepage "Latest Trending Rug Designs" section — mirrors the
			// original app's Tenant.trending_rug_ids. Empty ⇒ front-page.php
			// falls back to its existing newest-first product query.
			'trending_product_ids'         => array(),
			'default_shipping_rate'        => 0.0,
			'cancellation_window_hours'    => 24,
			// Display-only currency conversion (ported from CurrencyContext.tsx):
			// how many units of each currency equal 1 unit of the store's real
			// transactional currency (get_woocommerce_currency()). The customer
			// is always actually charged in that one currency regardless of
			// what's shown — this only changes the displayed number, matching
			// the original app's behavior exactly (it never took payment in
			// anything but the tenant's base_currency either).
			'exchange_rates'               => array(),
			// false = rates are only ever edited by hand (the daily sync and
			// "Sync Now" leave them alone) — Tenant.exchange_rates_auto.
			'exchange_rates_auto'          => true,
			// {platform: profile URL} for instagram/pinterest/linkedin/facebook/
			// youtube/x — header icons, footer "Follow" grid, Instagram handle.
			'social_links'                 => array(),
			// {menu-key: false} hides that nav item / mega-menu heading / link
			// (same keys as storefront_menu_labels) — storefront_menu_visibility.
			'storefront_menu_visibility'   => array(),
			// Rotating headings for the homepage Project Gallery section.
			'homepage_gallery_titles'      => array(),
			// Cloudflare Turnstile for public forms; empty = honeypot only.
			'turnstile_site_key'           => '',
			'turnstile_secret_key'         => '',
			// Comma-separated personal tokens; when set, visitors browsing
			// from India need ?key=<token> once (DRC_Geo). Empty = no gate.
			'india_access_keys'            => '',
			// Require customers to confirm their email before signing in
			// (the original's Customer.is_verified flow).
			'require_email_verification'   => true,
		);
	}

	public static function all(): array {
		$stored = get_option( self::OPTION_KEY, array() );
		$all    = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		// The pricing/base currency IS WooCommerce's store currency — one
		// source of truth, so quotes can never be stamped in a different
		// currency from the one checkout charges (the original's
		// Tenant.base_currency). Change it under WooCommerce → Settings.
		if ( class_exists( 'DRC_Multi_Currency' ) && function_exists( 'get_woocommerce_currency' ) ) {
			$all['currency'] = DRC_Multi_Currency::base();
		}
		return $all;
	}

	public static function get( string $key ) {
		$settings = self::all();
		return $settings[ $key ] ?? null;
	}

	public static function update( array $partial ): array {
		$merged = array_merge( self::all(), $partial );
		update_option( self::OPTION_KEY, $merged );
		return $merged;
	}
}
