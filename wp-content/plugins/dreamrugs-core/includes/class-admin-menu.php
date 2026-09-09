<?php
/**
 * The "DreamRugs" wp-admin menu — Phase 5's vendor UI, wired to
 * DRC_Admin_Rest_Routes. Built as plain PHP admin-screen markup (WordPress's
 * own .wrap/.form-table/.button conventions, dashicons, the built-in
 * list-table look) plus one vanilla-JS file talking to the REST API — not
 * the React app the original migration plan sketched, since a WP-idiomatic
 * screen ships full functional parity without a separate build toolchain
 * to maintain, and functional parity (not "must be React") is the actual
 * goal. Each screen is a thin PHP shell; assets/admin.js does the fetching
 * and rendering, same "server renders markup + a JS file owns interactivity"
 * split already established by the storefront theme's Alpine components.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Admin_Menu {

	public static function register() {
		add_menu_page(
			'DreamRugs',
			'DreamRugs',
			'manage_woocommerce',
			'dreamrugs-core',
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-store',
			56
		);

		add_submenu_page( 'dreamrugs-core', 'Dashboard', 'Dashboard', 'manage_woocommerce', 'dreamrugs-core', array( __CLASS__, 'render_dashboard' ) );
		add_submenu_page( 'dreamrugs-core', 'Quotes', 'Quotes', 'manage_woocommerce', 'dreamrugs-quotes', array( __CLASS__, 'render_quotes' ) );
		add_submenu_page( 'dreamrugs-core', 'Inventory', 'Inventory', 'manage_woocommerce', 'dreamrugs-inventory', array( __CLASS__, 'render_inventory' ) );
		add_submenu_page( 'dreamrugs-core', 'Pricing Rules', 'Pricing Rules', 'manage_woocommerce', 'dreamrugs-pricing-rules', array( __CLASS__, 'render_pricing_rules' ) );
		add_submenu_page( 'dreamrugs-core', 'Trade Enquiries', 'Trade Enquiries', 'manage_woocommerce', 'dreamrugs-trade-enquiries', array( __CLASS__, 'render_trade_enquiries' ) );
		add_submenu_page( 'dreamrugs-core', 'Homepage Enquiries', 'Homepage Enquiries', 'manage_woocommerce', 'dreamrugs-homepage-enquiries', array( __CLASS__, 'render_homepage_enquiries' ) );
		add_submenu_page( 'dreamrugs-core', 'Settings', 'Settings', 'manage_woocommerce', 'dreamrugs-settings', array( __CLASS__, 'render_settings' ) );

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function enqueue_assets( string $hook ) {
		if ( strpos( $hook, 'dreamrugs' ) === false ) {
			return;
		}
		if ( strpos( $hook, 'dreamrugs-settings' ) !== false ) {
			wp_enqueue_media(); // Needed for the hero-image picker's wp.media() call.
		}
		wp_enqueue_script( 'dreamrugs-admin', DRC_PLUGIN_URL . 'assets/admin.js', array(), DRC_DB_VERSION, true );
		wp_add_inline_script( 'dreamrugs-admin', 'window.drcAdmin = ' . wp_json_encode( array(
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'root'     => esc_url_raw( rest_url( 'dreamrugs/v1' ) ),
			'currency' => get_woocommerce_currency(),
		) ) . ';', 'before' );
		wp_enqueue_style( 'dreamrugs-admin', DRC_PLUGIN_URL . 'assets/admin.css', array(), DRC_DB_VERSION );
	}

	public static function render_dashboard() {
		?>
		<div class="wrap">
			<h1>DreamRugs Dashboard</h1>
			<div id="drc-dashboard-root">Loading…</div>
		</div>
		<?php
	}

	public static function render_quotes() {
		?>
		<div class="wrap">
			<h1>Quotes</h1>
			<div id="drc-quotes-root">Loading…</div>
		</div>
		<?php
	}

	public static function render_inventory() {
		?>
		<div class="wrap">
			<h1>Inventory — Materials</h1>
			<div id="drc-inventory-root">Loading…</div>
		</div>
		<?php
	}

	public static function render_pricing_rules() {
		?>
		<div class="wrap">
			<h1>Pricing, MOQ &amp; Timeline Rules</h1>
			<div id="drc-pricing-rules-root">Loading…</div>
		</div>
		<?php
	}

	public static function render_trade_enquiries() {
		?>
		<div class="wrap">
			<h1>Trade Enquiries</h1>
			<div id="drc-trade-enquiries-root">Loading…</div>
		</div>
		<?php
	}

	public static function render_homepage_enquiries() {
		?>
		<div class="wrap">
			<h1>Homepage Enquiries</h1>
			<div id="drc-homepage-enquiries-root">Loading…</div>
		</div>
		<?php
	}

	public static function render_settings() {
		?>
		<div class="wrap">
			<h1>Business Settings</h1>
			<div id="drc-settings-root">Loading…</div>
		</div>
		<?php
	}
}
