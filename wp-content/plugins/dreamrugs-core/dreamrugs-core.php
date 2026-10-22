<?php
/**
 * Plugin Name: DreamRugs Core
 * Description: Custom quote/pricing engine, quote-negotiation lifecycle, and admin tooling for the DreamRugsCreation WooCommerce site — the parts of the original FastAPI app with no native WooCommerce equivalent. See /Users/user/.claude/plans/gentle-doodling-origami.md for the migration plan this implements.
 * Version: 0.1.0
 * Requires Plugins: woocommerce
 * Text Domain: dreamrugs-core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DRC_PLUGIN_FILE', __FILE__ );
define( 'DRC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DRC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
// Bumped whenever a new rewrite rule/permastruct is added (DRC_Weave_Routes'
// /weaves/{slug}; 0.6.0: DRC_Design_Stories' /stories + /stories/{slug};
// 0.7.0: shipping method in the catch-all zone; 0.8.0: /collections/{facet}
// permastructs, /guides, landing pages; 0.9.0: the /account page;
// 0.9.1: wp_drc_inventory_transactions.legacy_id; 0.9.2: publish draft
// pages such as WordPress's own /privacy-policy/) so
// DRC_Activator::maybe_upgrade()'s version-change branch flushes rewrite
// rules once — otherwise the new URLs 404 until something else (a
// permalink-settings save, a manual flush) happens to trigger a flush.
define( 'DRC_DB_VERSION', '0.9.2' );

require_once DRC_PLUGIN_DIR . 'includes/class-activator.php';
require_once DRC_PLUGIN_DIR . 'includes/class-settings.php';
require_once DRC_PLUGIN_DIR . 'includes/class-legacy-password.php';
require_once DRC_PLUGIN_DIR . 'includes/class-size-format.php';
require_once DRC_PLUGIN_DIR . 'includes/class-quote-engine.php';
require_once DRC_PLUGIN_DIR . 'includes/class-product-data.php';
require_once DRC_PLUGIN_DIR . 'includes/class-pinterest-feed.php';
require_once DRC_PLUGIN_DIR . 'includes/class-invoice-data.php';
require_once DRC_PLUGIN_DIR . 'includes/class-email-templates.php';
require_once DRC_PLUGIN_DIR . 'includes/class-quote-context.php';
require_once DRC_PLUGIN_DIR . 'includes/class-invoice-pdf.php';
require_once DRC_PLUGIN_DIR . 'includes/class-multi-currency.php';
require_once DRC_PLUGIN_DIR . 'includes/class-shipping.php';
require_once DRC_PLUGIN_DIR . 'includes/class-order-hooks.php';
require_once DRC_PLUGIN_DIR . 'includes/class-coupon-start-date.php';
require_once DRC_PLUGIN_DIR . 'includes/class-catalog-masters.php';
require_once DRC_PLUGIN_DIR . 'includes/class-storefront-menu.php';
require_once DRC_PLUGIN_DIR . 'includes/class-url-compat.php';
require_once DRC_PLUGIN_DIR . 'includes/class-reviews.php';
require_once DRC_PLUGIN_DIR . 'includes/class-security.php';
require_once DRC_PLUGIN_DIR . 'includes/class-customer-accounts.php';
require_once DRC_PLUGIN_DIR . 'includes/class-admin-customer-view.php';
require_once DRC_PLUGIN_DIR . 'includes/class-content-meta-boxes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-import-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-geo.php';
require_once DRC_PLUGIN_DIR . 'includes/class-content-types.php';
require_once DRC_PLUGIN_DIR . 'includes/class-catalog-taxonomies.php';
require_once DRC_PLUGIN_DIR . 'includes/class-cart-pricing.php';
require_once DRC_PLUGIN_DIR . 'includes/class-color-options.php';
require_once DRC_PLUGIN_DIR . 'includes/class-variation-fields.php';
require_once DRC_PLUGIN_DIR . 'includes/class-weave-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-project-gallery-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-design-stories.php';
require_once DRC_PLUGIN_DIR . 'includes/class-currency-sync.php';
require_once DRC_PLUGIN_DIR . 'includes/class-order-creator.php';
require_once DRC_PLUGIN_DIR . 'includes/class-order-statuses.php';
require_once DRC_PLUGIN_DIR . 'includes/class-rest-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-admin-rest-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-admin-quote-tools.php';
require_once DRC_PLUGIN_DIR . 'includes/class-customer-orders-rest-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-admin-menu.php';

register_activation_hook( __FILE__, array( 'DRC_Activator', 'activate' ) );
// An immediate first sync on activation, so the exchange-rates table isn't
// empty for up to 24 hours until the daily cron first fires.
register_activation_hook( __FILE__, array( 'DRC_Currency_Sync', 'sync' ) );
register_deactivation_hook( __FILE__, array( 'DRC_Currency_Sync', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'DRC_Activator', 'maybe_upgrade' ) );
add_action( 'init', array( 'DRC_Activator', 'create_pages' ) );
add_action( 'init', array( 'DRC_Content_Types', 'register' ) );
add_action( 'init', array( 'DRC_Catalog_Taxonomies', 'register' ) );
DRC_Multi_Currency::init();
DRC_Shipping::init();
DRC_Order_Hooks::init();
DRC_Coupon_Start_Date::init();
DRC_Catalog_Masters::init();
DRC_Storefront_Menu::init();
DRC_Url_Compat::init();
DRC_Reviews::init();
DRC_Security::init();
DRC_Customer_Accounts::init();
DRC_Admin_Customer_View::init();
DRC_Content_Meta_Boxes::init();
DRC_Import_Routes::init();
DRC_Geo::init();
DRC_Pinterest_Feed::init();
DRC_Cart_Pricing::init();
DRC_Color_Options::init();
DRC_Variation_Fields::init();
DRC_Weave_Routes::init();
DRC_Project_Gallery_Routes::init();
DRC_Design_Stories::init();
DRC_Currency_Sync::init();
DRC_Order_Statuses::init();
DRC_Legacy_Password::init();
add_action( 'rest_api_init', array( 'DRC_Rest_Routes', 'register' ) );
add_action( 'rest_api_init', array( 'DRC_Admin_Rest_Routes', 'register' ) );
add_action( 'rest_api_init', array( 'DRC_Admin_Quote_Tools', 'register' ) );
// Guest quotes follow the customer into their account.
add_action( 'wp_login', array( 'DRC_Rest_Routes', 'claim_on_login' ), 10, 2 );
add_action( 'user_register', array( 'DRC_Rest_Routes', 'claim_on_register' ) );
add_action( 'rest_api_init', array( 'DRC_Customer_Orders_Rest_Routes', 'register' ) );
add_action( 'admin_menu', array( 'DRC_Admin_Menu', 'register' ) );
