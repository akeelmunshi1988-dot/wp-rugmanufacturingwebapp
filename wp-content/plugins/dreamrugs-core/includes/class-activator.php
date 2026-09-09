<?php
/**
 * Creates/upgrades the custom tables that have no native WooCommerce
 * equivalent: materials, pricing/MOQ/production-timeline config, quotes
 * (the quote-negotiation lifecycle — see the migration plan's "Custom
 * plugin: dreamrugs-core" section), and inventory transaction history.
 *
 * These are real tables, not postmeta, because they're relational/queried
 * at volume (status + customer filters on every quote-list page load) —
 * postmeta meta_query joins at this volume are a known WordPress
 * performance anti-pattern, and it's the same reason WooCommerce itself
 * moved order data to dedicated HPOS tables rather than postmeta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Activator {

	public static function activate() {
		self::create_tables();
		update_option( 'drc_db_version', DRC_DB_VERSION );
	}

	/** Idempotently creates the WP Pages each page-{slug}.php template needs
	 * to be reachable at all — WordPress only ever uses a `page-{slug}.php`
	 * template for an *existing* page with that slug, it doesn't infer pages
	 * from template files. None of this was scripted anywhere before (not
	 * here, not setup.sh) — these must have been created by hand once — so
	 * this also fixes existing pages, not just the 4 new ones from this pass.
	 * Skips cart/checkout/my-account: WooCommerce's own activation hook
	 * already creates those three with matching slugs.
	 *
	 * Hooked to `init` (dreamrugs-core.php), not `plugins_loaded`/activation
	 * — wp_insert_post() calls get_permalink() internally, which needs
	 * $wp_rewrite initialized; that isn't ready yet at plugins_loaded and
	 * crashes ("get_page_permastruct() on null") if called that early. */
	public static function create_pages() {
		// Cheap short-circuit so this doesn't run its get_page_by_path()
		// checks on every single request — only after a version bump,
		// mirroring drc_db_version's own "only on change" gate.
		if ( get_option( 'drc_pages_version' ) === DRC_DB_VERSION ) {
			return;
		}
		$pages = array(
			'about'             => 'About Us',
			'custom-rug-request' => 'Request a Custom Rug',
			'my-orders'         => 'My Orders',
			'my-quotes'         => 'My Quotes',
			'colour-matching'   => 'Colour Matching',
			'rug-size-guide'    => 'Rug Size Guide',
			'trade-enquiry'     => 'Trade Enquiry',
			'order-tracking'    => 'Order Tracking',
			'refund-cancellation-policy' => 'Refund & Cancellation Policy',
			'privacy-policy'    => 'Privacy Policy',
		);
		foreach ( $pages as $slug => $title ) {
			if ( get_page_by_path( $slug ) ) {
				continue;
			}
			wp_insert_post( array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
				'post_name'   => $slug,
			) );
		}
		update_option( 'drc_pages_version', DRC_DB_VERSION );
	}

	/** Runs on every load; dbDelta() is itself idempotent (diffs against
	 * existing schema), so this is safe to call unconditionally — matches
	 * the "idempotent, safe to re-run" convention the original app's
	 * migrate_v##.py scripts used for the same reason. */
	public static function maybe_upgrade() {
		$installed = get_option( 'drc_db_version' );
		if ( $installed !== DRC_DB_VERSION ) {
			self::create_tables();
			update_option( 'drc_db_version', DRC_DB_VERSION );
			// Rewrite rules (e.g. DRC_Weave_Routes' /weaves/{slug}) are only
			// registered in PHP memory each request — flush once per version
			// bump so a newly-added rule takes effect without requiring an
			// admin to visit Settings → Permalinks by hand. flush_rewrite_rules()
			// itself re-runs the 'init' registrations first, so this always
			// sees the rules current as of this deploy.
			flush_rewrite_rules();
		}
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$materials = $wpdb->prefix . 'drc_materials';
		$pricing_rules = $wpdb->prefix . 'drc_pricing_rules';
		$moq_rules = $wpdb->prefix . 'drc_moq_rules';
		$production_timelines = $wpdb->prefix . 'drc_production_timelines';
		$quotes = $wpdb->prefix . 'drc_quotes';
		$inventory_transactions = $wpdb->prefix . 'drc_inventory_transactions';

		// Mirrors the original app's Material model
		// (backend/app/models/models.py) minus tenant_id (single-tenant).
		$sql_materials = "CREATE TABLE {$materials} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(100) NOT NULL,
			type VARCHAR(50) NOT NULL,
			color VARCHAR(100) NOT NULL,
			stock_meters FLOAT NOT NULL DEFAULT 0,
			cost_per_sqm FLOAT NOT NULL,
			cost_currency VARCHAR(10) DEFAULT NULL,
			is_available TINYINT(1) NOT NULL DEFAULT 1,
			legacy_id BIGINT UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY stock_meters (stock_meters),
			UNIQUE KEY legacy_id (legacy_id)
		) {$charset_collate};";

		// Mirrors PricingRule — tiered bulk-discount schedule read by the
		// quote engine's bulk-discount step.
		$sql_pricing_rules = "CREATE TABLE {$pricing_rules} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(150) NOT NULL,
			rule_type VARCHAR(50) NOT NULL,
			min_qty FLOAT DEFAULT NULL,
			max_qty FLOAT DEFAULT NULL,
			multiplier FLOAT DEFAULT NULL,
			flat_fee FLOAT DEFAULT NULL,
			description TEXT DEFAULT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// Mirrors MOQRule.
		$sql_moq_rules = "CREATE TABLE {$moq_rules} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rug_type VARCHAR(100) NOT NULL,
			minimum_sqm FLOAT DEFAULT NULL,
			minimum_pieces INT DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// Mirrors ProductionTimeline.
		$sql_production_timelines = "CREATE TABLE {$production_timelines} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_type VARCHAR(100) NOT NULL,
			base_days INT NOT NULL,
			complexity_multiplier_per_sqm FLOAT NOT NULL DEFAULT 0,
			notes TEXT DEFAULT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};";

		// Mirrors Quote — the central quote-negotiation entity. customer_user_id
		// is a wp_users id (null for a guest, whose name/email are stored
		// directly); product_id/variation_id point at the WooCommerce product
		// for catalog-rug quotes and are null for bespoke/custom requests
		// (which use custom_size_w/h instead, same as the original model).
		$sql_quotes = "CREATE TABLE {$quotes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			customer_user_id BIGINT UNSIGNED DEFAULT NULL,
			guest_name VARCHAR(150) DEFAULT NULL,
			guest_email VARCHAR(200) DEFAULT NULL,
			product_id BIGINT UNSIGNED DEFAULT NULL,
			variation_id BIGINT UNSIGNED DEFAULT NULL,
			custom_size_w FLOAT DEFAULT NULL,
			custom_size_h FLOAT DEFAULT NULL,
			rug_shape VARCHAR(20) NOT NULL DEFAULT 'rect',
			material_id BIGINT UNSIGNED DEFAULT NULL,
			qty INT NOT NULL DEFAULT 1,
			base_price FLOAT DEFAULT NULL,
			final_price FLOAT DEFAULT NULL,
			rush_order TINYINT(1) NOT NULL DEFAULT 0,
			selected_color VARCHAR(100) DEFAULT NULL,
			price_currency VARCHAR(10) DEFAULT NULL,
			margin_pct FLOAT DEFAULT NULL,
			gst_pct FLOAT DEFAULT NULL,
			manual_discount_pct FLOAT DEFAULT NULL,
			shipping_cost FLOAT DEFAULT NULL,
			expected_delivery_days INT DEFAULT NULL,
			status VARCHAR(50) NOT NULL DEFAULT 'draft',
			notes TEXT DEFAULT NULL,
			vendor_notes TEXT DEFAULT NULL,
			customer_response_notes TEXT DEFAULT NULL,
			review_request_count INT NOT NULL DEFAULT 0,
			is_custom_request TINYINT(1) NOT NULL DEFAULT 0,
			room_type VARCHAR(100) DEFAULT NULL,
			material_preference VARCHAR(50) DEFAULT NULL,
			budget_range VARCHAR(100) DEFAULT NULL,
			expected_delivery VARCHAR(50) DEFAULT NULL,
			request_group_id VARCHAR(36) DEFAULT NULL,
			reference_image_urls LONGTEXT DEFAULT NULL,
			vendor_sample_image_urls LONGTEXT DEFAULT NULL,
			revised_from_quote_id BIGINT UNSIGNED DEFAULT NULL,
			wc_order_id BIGINT UNSIGNED DEFAULT NULL,
			legacy_quote_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY customer_status (customer_user_id, status),
			KEY created_at (created_at),
			KEY request_group_id (request_group_id),
			UNIQUE KEY legacy_quote_id (legacy_quote_id)
		) {$charset_collate};";

		// Mirrors InventoryTransaction — the restock/consumption audit ledger.
		$sql_inventory_transactions = "CREATE TABLE {$inventory_transactions} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			material_id BIGINT UNSIGNED NOT NULL,
			qty_change FLOAT NOT NULL,
			transaction_type VARCHAR(50) NOT NULL,
			notes TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY material_id (material_id)
		) {$charset_collate};";

		dbDelta( $sql_materials );
		dbDelta( $sql_pricing_rules );
		dbDelta( $sql_moq_rules );
		dbDelta( $sql_production_timelines );
		dbDelta( $sql_quotes );
		dbDelta( $sql_inventory_transactions );
	}
}
