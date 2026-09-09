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
// Bumped alongside DRC_Weave_Routes' new rewrite rule so
// DRC_Activator::maybe_upgrade()'s version-change branch flushes rewrite
// rules once — otherwise /weaves/{slug} 404s until something else (a
// permalink-settings save, a manual flush) happens to trigger a flush.
define( 'DRC_DB_VERSION', '0.5.0' );

require_once DRC_PLUGIN_DIR . 'includes/class-activator.php';
require_once DRC_PLUGIN_DIR . 'includes/class-settings.php';
require_once DRC_PLUGIN_DIR . 'includes/class-legacy-password.php';
require_once DRC_PLUGIN_DIR . 'includes/class-size-format.php';
require_once DRC_PLUGIN_DIR . 'includes/class-quote-engine.php';
require_once DRC_PLUGIN_DIR . 'includes/class-invoice-data.php';
require_once DRC_PLUGIN_DIR . 'includes/class-content-types.php';
require_once DRC_PLUGIN_DIR . 'includes/class-catalog-taxonomies.php';
require_once DRC_PLUGIN_DIR . 'includes/class-cart-pricing.php';
require_once DRC_PLUGIN_DIR . 'includes/class-color-options.php';
require_once DRC_PLUGIN_DIR . 'includes/class-variation-fields.php';
require_once DRC_PLUGIN_DIR . 'includes/class-weave-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-project-gallery-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-currency-sync.php';
require_once DRC_PLUGIN_DIR . 'includes/class-order-creator.php';
require_once DRC_PLUGIN_DIR . 'includes/class-order-statuses.php';
require_once DRC_PLUGIN_DIR . 'includes/class-rest-routes.php';
require_once DRC_PLUGIN_DIR . 'includes/class-admin-rest-routes.php';
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
DRC_Cart_Pricing::init();
DRC_Color_Options::init();
DRC_Variation_Fields::init();
DRC_Weave_Routes::init();
DRC_Project_Gallery_Routes::init();
DRC_Currency_Sync::init();
DRC_Order_Statuses::init();
DRC_Legacy_Password::init();
add_action( 'rest_api_init', array( 'DRC_Rest_Routes', 'register' ) );
add_action( 'rest_api_init', array( 'DRC_Admin_Rest_Routes', 'register' ) );
add_action( 'rest_api_init', array( 'DRC_Customer_Orders_Rest_Routes', 'register' ) );
add_action( 'admin_menu', array( 'DRC_Admin_Menu', 'register' ) );
