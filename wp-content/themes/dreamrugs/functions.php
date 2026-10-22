<?php
/**
 * Theme setup. Interactivity that was React state in the original app
 * (mobile menu, account dropdown, currency switcher, announcement rotation,
 * cookie banner) is reimplemented with Alpine.js per the migration plan —
 * see header.php/footer.php for the x-data blocks. The mega menu needs no
 * JS at all: it was already a pure CSS `group-hover` in the original
 * Tailwind markup and stays that way here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dreamrugs_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'woocommerce' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption' ) );
	// header.php already reads get_theme_mod('custom_logo') and falls back to
	// the text logotype when it's empty — this was the only missing piece
	// wiring that up to the Customizer's own Site Identity logo uploader.
	add_theme_support( 'custom-logo', array(
		'height'      => 80,
		'width'       => 80,
		'flex-height' => true,
		'flex-width'  => true,
	) );
	register_nav_menus( array( 'primary' => 'Primary Navigation' ) );
}
add_action( 'after_setup_theme', 'dreamrugs_setup' );

require get_theme_file_path( 'inc/customizer.php' );
require get_theme_file_path( 'inc/collection-content.php' );
require get_theme_file_path( 'inc/about-page-content.php' );
require get_theme_file_path( 'inc/seo.php' );
require get_theme_file_path( 'inc/content-pages.php' );
require get_theme_file_path( 'inc/sitemap.php' );

/**
 * Checkout field shaping for the woocommerce/checkout/*.php overrides —
 * ported from CustomerCheckout.tsx's single combined-address, single-name
 * design. WooCommerce always ships first_name+last_name and an optional
 * separate shipping address; folding to one "Full Name" field and forcing
 * shipping = billing (no second address form) matches the original without
 * touching how WooCommerce actually processes/validates the order.
 */
add_filter( 'woocommerce_ship_to_billing_address_only', '__return_true' );
add_filter( 'woocommerce_checkout_fields', function ( $fields ) {
	if ( isset( $fields['billing']['billing_last_name'] ) ) {
		unset( $fields['billing']['billing_last_name'] );
	}
	if ( isset( $fields['billing']['billing_first_name'] ) ) {
		$fields['billing']['billing_first_name']['label'] = 'Full Name';
	}
	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['required'] = false;
	}
	if ( isset( $fields['billing']['billing_company'] ) ) {
		$fields['billing']['billing_company']['required'] = false;
	}
	// Priorities set to match the visual pairing woocommerce/checkout/
	// form-billing.php's flat grid relies on (Name+Email, then
	// Phone+Company). This isn't just cosmetic ordering: WooCommerce's own
	// address-i18n.js re-sorts checkout fields by this same priority and
	// detaches/reattaches them as direct siblings of their container —
	// confirmed live via a DOM-mutation trace, this is what silently pulled
	// billing_email out of a custom per-field wrapper <div> in an earlier
	// version of that template, breaking the 2-column layout. Making our
	// desired order match WooCommerce's own sort order means its reordering
	// is a same-order no-op instead of something to fight.
	if ( isset( $fields['billing']['billing_email'] ) ) {
		$fields['billing']['billing_email']['priority'] = 20;
	}
	if ( isset( $fields['billing']['billing_phone'] ) ) {
		$fields['billing']['billing_phone']['priority'] = 30;
	}
	if ( isset( $fields['billing']['billing_company'] ) ) {
		$fields['billing']['billing_company']['priority'] = 40;
	}
	return $fields;
} );
add_filter( 'woocommerce_order_button_text', fn() => 'Place Order &amp; Pay' );

// The promo-code input already lives inline in the order summary panel
// (woocommerce/checkout/review-order.php) — WooCommerce's own "Have a
// coupon? Click here to enter your code" banner above the whole checkout
// form would just be a redundant, differently-styled second way to do the
// same thing.
remove_action( 'woocommerce_before_checkout_form', 'woocommerce_checkout_coupon_form', 10 );

// woocommerce/checkout/thankyou.php's own "Order Details" box already shows
// the line items, total, and (via get_formatted_shipping_address()) the
// delivery address — WooCommerce core's default order-details-table
// (hooked to woocommerce_thankyou by wc-template-hooks.php) would render
// all of that a second time, in WooCommerce's own unstyled default markup,
// directly below it. Deliberately still calling do_action('woocommerce_thankyou', ...)
// from that template (rather than dropping the call entirely) so other
// legitimate listeners — e.g. the payment gateway's own order-confirmation
// tracking — still fire; this removes only the one redundant callback.
remove_action( 'woocommerce_thankyou', 'woocommerce_order_details_table', 10 );

// The "Add to Cart"/"Buy Now" buttons in the rug configurator
// (single-product.php's #rug-configurator) already take the shopper
// straight to the cart/checkout page — WooCommerce's own "'Test Rug' has
// been added to your cart. View cart" notice on the reload in between is
// redundant chrome the original design never had. wc_add_to_cart_message()
// (called directly from WC_Form_Handler, not via an action) builds this
// text and always runs it through this specific filter before calling
// wc_add_notice(); wc_add_notice() itself skips adding anything when the
// filtered message is empty, so this fully suppresses just this one
// notice — every other WooCommerce success/error notice is untouched.
add_filter( 'wc_add_to_cart_message_html', '__return_empty_string' );

function dreamrugs_enqueue_assets() {
	wp_enqueue_style(
		'dreamrugs-fonts',
		dreamrugs_google_fonts_url(),
		array(),
		null
	);
	wp_enqueue_style(
		'dreamrugs-style',
		get_stylesheet_directory_uri() . '/assets/style.build.css',
		array(),
		filemtime( get_stylesheet_directory() . '/assets/style.build.css' )
	);
	// Alpine's x-cloak needs a matching CSS rule to actually hide elements
	// until Alpine initializes — header.php (mobile menu, account dropdown,
	// the rotating announcement variants) relies on it. Kept as a one-line
	// inline rule rather than a Tailwind source change so it doesn't need a
	// `npm run build` to take effect.
	wp_add_inline_style( 'dreamrugs-style', '[x-cloak]{display:none!important;}' );

	// Site-wide display-currency converter (header dropdown + every
	// .drc-price span) — see assets/currency.js's header comment. Registered
	// early so the inline config is ready before it runs; DRC_Settings/WC
	// currency helpers may not be loaded on every request type, hence the
	// class_exists/function_exists guards rather than assuming plugin order.
	$drc_settings_for_currency = class_exists( 'DRC_Settings' ) ? DRC_Settings::all() : array();
	wp_enqueue_script(
		'dreamrugs-currency',
		get_stylesheet_directory_uri() . '/assets/currency.js',
		array(),
		filemtime( get_stylesheet_directory() . '/assets/currency.js' ),
		true
	);
	wp_add_inline_script( 'dreamrugs-currency', 'window.drcCurrencyConfig = ' . wp_json_encode( array(
		'base_currency'    => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		'default_currency' => $drc_settings_for_currency['currency'] ?? 'USD',
		'exchange_rates'   => (object) ( $drc_settings_for_currency['exchange_rates'] ?? array() ),
		'root'             => esc_url_raw( rest_url( 'dreamrugs/v1' ) ),
		// A signed-in customer's own country decides their default currency
		// (CurrencyContext.tsx); guests are geolocated by currency.js.
		'customer_country' => is_user_logged_in() ? (string) get_user_meta( get_current_user_id(), 'billing_country', true ) : '',
		// Currencies the store will charge in (empty = every supported one).
		'currencies'       => array_values( array_filter( (array) ( $drc_settings_for_currency['enabled_currencies'] ?? array() ) ) ),
	) ) . ';', 'before' );
	// A virtual (no-file) handle carrying the REST nonce every authenticated
	// dreamrugs/v1 fetch() call needs. WordPress's REST API requires
	// X-WP-Nonce alongside cookie auth for a logged-in request to actually
	// authenticate — without it, is_user_logged_in() evaluates false inside
	// the REST dispatch even for a genuinely logged-in browser (confirmed
	// live: My Quotes failed to load, and quote submission from a logged-in
	// product page would have silently saved as a guest quote instead of
	// linking to the account). Any script that calls a require_login()
	// route must depend on this handle and send window.dreamrugsRest.nonce
	// as the X-WP-Nonce header.
	wp_register_script( 'dreamrugs-rest-config', '', array(), null, true );
	wp_add_inline_script( 'dreamrugs-rest-config', 'window.dreamrugsRest = ' . wp_json_encode( array(
		'nonce' => wp_create_nonce( 'wp_rest' ),
		'root'  => esc_url_raw( rest_url() ),
	) ) . ';' );
	wp_enqueue_script( 'dreamrugs-rest-config' );

	// Rug back-to-top button (template-parts/scroll-to-top-rug.php), every
	// storefront page. Non-deferred for the same reason as rug-detail.js below.
	wp_enqueue_script(
		'dreamrugs-scroll-top-rug',
		get_stylesheet_directory_uri() . '/assets/scroll-top-rug.js',
		array(),
		filemtime( get_stylesheet_directory() . '/assets/scroll-top-rug.js' ),
		true
	);

	if ( function_exists( 'is_product' ) && is_product() ) {
		// Plain (non-deferred) script: per the HTML spec, a non-deferred
		// script always finishes executing before any deferred script runs,
		// regardless of source order — so rugDetail() is guaranteed to be
		// defined on window before Alpine (deferred, below) evaluates the
		// x-data="rugDetail(...)" attribute.
		wp_enqueue_script(
			'dreamrugs-rug-detail',
			get_stylesheet_directory_uri() . '/assets/rug-detail.js',
			array( 'dreamrugs-rest-config' ),
			filemtime( get_stylesheet_directory() . '/assets/rug-detail.js' ),
			true
		);
	}

	if ( ( function_exists( 'is_shop' ) && is_shop() ) || is_tax( array( 'room_type', 'mood_tag', 'material_type' ) ) || get_query_var( 'drc_weave' ) ) {
		wp_enqueue_script(
			'dreamrugs-catalog',
			get_stylesheet_directory_uri() . '/assets/catalog.js',
			array(),
			filemtime( get_stylesheet_directory() . '/assets/catalog.js' ),
			true
		);
	}

	if ( function_exists( 'is_cart' ) && is_cart() ) {
		wp_register_script( 'dreamrugs-store-api-config', '', array(), null, true );
		wp_add_inline_script( 'dreamrugs-store-api-config', 'window.drcStoreApi = ' . wp_json_encode( array(
			'nonce' => wp_create_nonce( 'wc_store_api' ),
			'root'  => esc_url_raw( rest_url( 'wc/store/v1' ) ),
		) ) . ';' );
		wp_enqueue_script( 'dreamrugs-store-api-config' );

		wp_enqueue_script(
			'dreamrugs-cart',
			get_stylesheet_directory_uri() . '/assets/cart.js',
			array( 'dreamrugs-store-api-config' ),
			filemtime( get_stylesheet_directory() . '/assets/cart.js' ),
			true
		);
	}

	if ( function_exists( 'is_checkout' ) && is_checkout() ) {
		// Depends on 'wc-checkout' (WooCommerce's own checkout.js) purely for
		// load order — it's what localizes window.wc_checkout_params
		// (apply_coupon_nonce + wc_ajax_url) that this script reads, not for
		// any function call into it. See review-order.php's header comment
		// on why the promo-code "Apply" button needs this separate script
		// instead of WooCommerce's own form.checkout_coupon AJAX handler.
		wp_enqueue_script(
			'dreamrugs-checkout-coupon',
			get_stylesheet_directory_uri() . '/assets/checkout-coupon.js',
			array( 'wc-checkout' ),
			filemtime( get_stylesheet_directory() . '/assets/checkout-coupon.js' ),
			true
		);
	}

	if ( is_page( 'my-quotes' ) ) {
		wp_enqueue_script(
			'dreamrugs-my-quotes',
			get_stylesheet_directory_uri() . '/assets/my-quotes.js',
			array( 'dreamrugs-rest-config' ),
			filemtime( get_stylesheet_directory() . '/assets/my-quotes.js' ),
			true
		);
	}

	if ( is_page( 'my-orders' ) ) {
		wp_enqueue_script(
			'dreamrugs-my-orders',
			get_stylesheet_directory_uri() . '/assets/my-orders.js',
			array( 'dreamrugs-rest-config' ),
			filemtime( get_stylesheet_directory() . '/assets/my-orders.js' ),
			true
		);
	}

	if ( is_front_page() ) {
		wp_enqueue_script(
			'dreamrugs-homepage-contact',
			get_stylesheet_directory_uri() . '/assets/homepage-contact.js',
			array( 'dreamrugs-rest-config' ),
			filemtime( get_stylesheet_directory() . '/assets/homepage-contact.js' ),
			true
		);
	}

	if ( is_page( 'custom-rug-request' ) ) {
		wp_enqueue_script(
			'dreamrugs-custom-rug-request',
			get_stylesheet_directory_uri() . '/assets/custom-rug-request.js',
			array( 'dreamrugs-rest-config' ),
			filemtime( get_stylesheet_directory() . '/assets/custom-rug-request.js' ),
			true
		);
	}

	wp_enqueue_script(
		'alpinejs',
		'https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js',
		array(),
		'3.14.1',
		array( 'strategy' => 'defer' )
	);
}
add_action( 'wp_enqueue_scripts', 'dreamrugs_enqueue_assets' );

/** Settings the header/footer render server-side — no client fetch needed
 * (unlike the original SPA, which had to fetch /api/customer/settings on
 * mount). Thin wrapper so templates don't reach into DRC_Settings directly. */
function dreamrugs_settings(): array {
	return class_exists( 'DRC_Settings' ) ? DRC_Settings::all() : array();
}

/** Catalog filter options for meta-stored fields (pile height, weave type)
 * that have no dedicated taxonomy — unlike material_type/room_type/mood_tag
 * (real WooCommerce product taxonomies, see class-catalog-taxonomies.php),
 * pile/weave are plain product postmeta, so "what values exist" is a
 * distinct-value query over published products rather than get_terms().
 * Mirrors the original app's /customer/menu-options weaves/piles fields,
 * which similarly return only values actually in use, not every value a
 * vendor could type. */
function dreamrugs_distinct_product_meta_values( string $meta_key ): array {
	global $wpdb;
	$values = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = %s AND pm.meta_value != '' AND p.post_status = 'publish' AND p.post_type = 'product'
		 ORDER BY pm.meta_value ASC",
		$meta_key
	) );
	return $values ?: array();
}

/**
 * Wraps a server-rendered price (already formatted for the store's real
 * transactional currency via wc_price()/wc_cart_totals_*_html()) in a span
 * carrying the raw numeric amount — assets/currency.js finds every
 * `.drc-price` on the page and rewrites its displayed text into whichever
 * currency the shopper picked from the header dropdown. Ported from
 * CurrencyContext.tsx's displayPrice(): this only ever changes what's
 * *shown* — the customer is always actually charged in the store's one
 * real currency at checkout regardless of what this displays, exactly as
 * the original app's own currency switcher worked.
 *
 * $formatted_html is trusted, pre-escaped markup (the caller's own
 * wc_price()/wc_cart_totals_*_html() output) — never raw user input.
 */
function dreamrugs_price_span( float $amount, string $formatted_html ): string {
	// data-drc-currency = the currency $amount is already in: the store's
	// base everywhere, except cart/checkout while DRC_Multi_Currency is
	// charging in the shopper's currency — currency.js converts from it.
	$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
	return '<span class="drc-price" data-drc-amount="' . esc_attr( $amount ) . '" data-drc-currency="' . esc_attr( $currency ) . '">' . $formatted_html . '</span>';
}

/**
 * Inline SVG output matching the Lucide icon set used by the original
 * React app (lucide-react) — same viewBox/stroke conventions, just the
 * specific icons this theme's ported templates reference. Add more `case`
 * entries here as later template ports need additional icons, rather than
 * pulling in a JS icon library for markup that's otherwise pure PHP.
 */
function dreamrugs_icon( string $name, int $size = 16, string $class = '' ) {
	$paths = array(
		'x'                => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
		'menu'             => '<line x1="4" x2="20" y1="12" y2="12"/><line x1="4" x2="20" y1="6" y2="6"/><line x1="4" x2="20" y1="18" y2="18"/>',
		'chevron-down'     => '<path d="m6 9 6 6 6-6"/>',
		'user'             => '<path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
		'shopping-cart'    => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
		'phone'            => '<path d="M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.804 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384"/>',
		'map-pin'          => '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
		'package'          => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73Z"/><path d="M12 22V12"/><path d="m3.3 7 8.7 5 8.7-5"/>',
		'file-text'        => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>',
		'layout-dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1"/><rect width="7" height="5" x="14" y="3" rx="1"/><rect width="7" height="9" x="14" y="12" rx="1"/><rect width="7" height="5" x="3" y="16" rx="1"/>',
		'log-out'          => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
		'mail'             => '<path d="m22 7-8.991 5.727a2 2 0 0 1-2.009 0L2 7"/><rect x="2" y="4" width="20" height="16" rx="2"/>',
		'download'         => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
		'send'             => '<path d="M14.536 21.686a.5.5 0 0 0 .937-.024l6.5-19a.496.496 0 0 0-.635-.635l-19 6.5a.5.5 0 0 0-.024.937l7.93 3.18a2 2 0 0 1 1.112 1.11z"/><path d="m21.854 2.147-10.94 10.939"/>',
		'check'            => '<path d="M20 6 9 17l-5-5"/>',
		'message-circle'   => '<path d="M2.992 16.342a2 2 0 0 1 .094 1.167l-1.065 3.29a1 1 0 0 0 1.236 1.168l3.413-.998a2 2 0 0 1 1.099.092 10 10 0 1 0-4.777-4.719"/>',
		'arrow-right'      => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
		'clock'            => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
		'gem'              => '<path d="M6 3h12l4 6-10 13L2 9Z"/><path d="M11 3 8 9l4 13 4-13-3-6"/><path d="M2 9h20"/>',
		'hand'             => '<path d="M18 11V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2"/><path d="M14 10V4a2 2 0 0 0-2-2a2 2 0 0 0-2 2v2"/><path d="M10 10.5V6a2 2 0 0 0-2-2a2 2 0 0 0-2 2v8"/><path d="M18 8a2 2 0 1 1 4 0v6a8 8 0 0 1-8 8h-2c-2.8 0-4.5-.86-5.99-2.34l-3.6-3.6a2 2 0 0 1 2.83-2.82L7 15"/>',
		'leaf'             => '<path d="M11 20A7 7 0 0 1 9.8 6.1C15.5 5 17 4.48 19 2c1 2 2 4.18 2 8 0 5.5-4.78 10-10 10Z"/><path d="M2 21c0-3 1.85-5.36 5.08-6C9.5 14.52 12 13 13 12"/>',
		'ruler'            => '<path d="M21.3 15.3a2.4 2.4 0 0 1 0 3.4l-2.6 2.6a2.4 2.4 0 0 1-3.4 0L2.7 8.7a2.4 2.4 0 0 1 0-3.4l2.6-2.6a2.4 2.4 0 0 1 3.4 0Z"/><path d="m14.5 12.5 2-2"/><path d="m11.5 9.5 2-2"/><path d="m8.5 6.5 2-2"/><path d="m17.5 15.5 2-2"/>',
		'grid'             => '<rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/>',
		'maximize'         => '<path d="M8 3H5a2 2 0 0 0-2 2v3"/><path d="M21 8V5a2 2 0 0 0-2-2h-3"/><path d="M3 16v3a2 2 0 0 0 2 2h3"/><path d="M16 21h3a2 2 0 0 0 2-2v-3"/>',
		'shield-check'     => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
		'globe'            => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
		'palette'          => '<path d="M12 22a1 1 0 0 1 0-20 10 9 0 0 1 10 9 5 5 0 0 1-5 5h-2.25a1.75 1.75 0 0 0-1.4 2.8l.3.4a1.75 1.75 0 0 1-1.4 2.8z"/><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/>',
		'scissors'         => '<circle cx="6" cy="6" r="3"/><path d="M8.12 8.12 12 12"/><path d="M20 4 8.12 15.88"/><circle cx="6" cy="18" r="3"/><path d="M14.8 14.8 20 20"/>',
		'pencil-ruler'     => '<path d="M13 7 8.7 2.7a2.41 2.41 0 0 0-3.4 0L2.7 5.3a2.41 2.41 0 0 0 0 3.4L7 13"/><path d="m8 6 2-2"/><path d="m2 22 5.5-1.5L21.17 6.83a2.82 2.82 0 0 0-4-4L3.5 16.5Z"/><path d="m18 16 2-2"/><path d="m17 11 4.3 4.3c.94.94.94 2.46 0 3.4l-2.6 2.6c-.94.94-2.46.94-3.4 0L11 17"/>',
		'package-check'    => '<path d="M16 16h6"/><path d="M19 13v6"/><path d="M21 10V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l1.174-.671"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
		'external-link'    => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
		'sparkles'         => '<path d="M9.937 15.5A2 2 0 0 0 8.5 14.063l-6.135-1.582a.5.5 0 0 1 0-.962L8.5 9.936A2 2 0 0 0 9.937 8.5l1.582-6.135a.5.5 0 0 1 .963 0L14.063 8.5A2 2 0 0 0 15.5 9.937l6.135 1.581a.5.5 0 0 1 0 .964L15.5 14.063a2 2 0 0 0-1.437 1.437l-1.582 6.135a.5.5 0 0 1-.963 0z"/>',
		'arrow-down'       => '<path d="M12 5v14"/><path d="m19 12-7 7-7-7"/>',
		'zap'              => '<path d="M4 14a1 1 0 0 1-.78-1.63l9.9-10.2a.5.5 0 0 1 .86.46l-1.92 6.02A1 1 0 0 0 13 10h7a1 1 0 0 1 .78 1.63l-9.9 10.2a.5.5 0 0 1-.86-.46l1.92-6.02A1 1 0 0 0 11 14z"/>',
		'star'             => '<path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"/>',
		'chevron-left'     => '<path d="m15 18-6-6 6-6"/>',
		'chevron-right'    => '<path d="m9 18 6-6-6-6"/>',
		'play'             => '<polygon points="6 3 20 12 6 21 6 3"/>',
		'search'           => '<path d="m21 21-4.34-4.34"/><circle cx="11" cy="11" r="8"/>',
		'plus'             => '<path d="M5 12h14"/><path d="M12 5v14"/>',
		'minus'            => '<path d="M5 12h14"/>',
		'layers'           => '<path d="M12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.91a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83z"/><path d="M2 12a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 12"/><path d="M2 17a1 1 0 0 0 .58.91l8.6 3.91a2 2 0 0 0 1.65 0l8.58-3.9A1 1 0 0 0 22 17"/>',
		'alert-triangle'   => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
		'tag'              => '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
		'shopping-bag'     => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
		'trash-2'          => '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
		'chevron-up'       => '<path d="m18 15-6-6-6 6"/>',
		'user-plus'        => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><line x1="19" x2="19" y1="8" y2="14"/><line x1="22" x2="16" y1="11" y2="11"/>',
		'mail-check'       => '<path d="M22 13V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v12c0 1.1.9 2 2 2h8"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/><path d="m16 19 2 2 4-4"/>',
		'log-in'           => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" x2="3" y1="12" y2="12"/>',
		'x-circle'         => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
		'bell'             => '<path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/>',
		'refresh-cw'       => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
		'message-square'   => '<path d="M22 17a2 2 0 0 1-2 2H6.5L2 21V5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2z"/>',
		'truck'            => '<path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/>',
		'check-circle'     => '<path d="M21.801 10A10 10 0 1 1 17 3.335"/><path d="m9 11 3 3L22 4"/>',
		'upload'           => '<path d="M12 3v12"/><path d="m17 8-5-5-5 5"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>',
		'corner-down-left' => '<path d="M20 4v7a4 4 0 0 1-4 4H4"/><path d="m9 10-5 5 5 5"/>',
		'scroll-text'      => '<path d="M15 12h-5"/><path d="M15 8h-5"/><path d="M19 17V5a2 2 0 0 0-2-2H4"/><path d="M8 21h12a2 2 0 0 0 2-2v-1a1 1 0 0 0-1-1H11a1 1 0 0 0-1 1v1a2 2 0 1 1-4 0V5a2 2 0 1 0-4 0v2a1 1 0 0 0 1 1h3"/>',
	);
	if ( ! isset( $paths[ $name ] ) ) {
		return;
	}
	printf(
		'<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="inline-block %2$s">%3$s</svg>',
		(int) $size,
		esc_attr( $class ),
		$paths[ $name ] // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed, trusted SVG path constants defined above, not user input.
	);
}

/**
 * Social platforms and their brand glyphs — Simple Icons (CC0, 24×24),
 * the same set and order as the original components/SocialIcon.tsx.
 * Keys match DRC_Settings 'social_links'.
 */
function dreamrugs_social_platforms(): array {
	return array(
		'instagram' => array( 'label' => 'Instagram', 'path' => 'M7.0301.084c-1.2768.0602-2.1487.264-2.911.5634-.7888.3075-1.4575.72-2.1228 1.3877-.6652.6677-1.075 1.3368-1.3802 2.127-.2954.7638-.4956 1.6365-.552 2.914-.0564 1.2775-.0689 1.6882-.0626 4.947.0062 3.2586.0206 3.6671.0825 4.9473.061 1.2765.264 2.1482.5635 2.9107.308.7889.72 1.4573 1.388 2.1228.6679.6655 1.3365 1.0743 2.1285 1.38.7632.295 1.6361.4961 2.9134.552 1.2773.056 1.6884.069 4.9462.0627 3.2578-.0062 3.668-.0207 4.9478-.0814 1.28-.0607 2.147-.2652 2.9098-.5633.7889-.3086 1.4578-.72 2.1228-1.3881.665-.6682 1.0745-1.3378 1.3795-2.1284.2957-.7632.4966-1.636.552-2.9124.056-1.2809.0692-1.6898.063-4.948-.0063-3.2583-.021-3.6668-.0817-4.9465-.0607-1.2797-.264-2.1487-.5633-2.9117-.3084-.7889-.72-1.4568-1.3876-2.1228C21.2982 1.33 20.628.9208 19.8378.6165 19.074.321 18.2017.1197 16.9244.0645 15.6471.0093 15.236-.005 11.977.0014 8.718.0076 8.31.0215 7.0301.0839m.1402 21.6932c-1.17-.0509-1.8053-.2453-2.2287-.408-.5606-.216-.96-.4771-1.3819-.895-.422-.4178-.6811-.8186-.9-1.378-.1644-.4234-.3624-1.058-.4171-2.228-.0595-1.2645-.072-1.6442-.079-4.848-.007-3.2037.0053-3.583.0607-4.848.05-1.169.2456-1.805.408-2.2282.216-.5613.4762-.96.895-1.3816.4188-.4217.8184-.6814 1.3783-.9003.423-.1651 1.0575-.3614 2.227-.4171 1.2655-.06 1.6447-.072 4.848-.079 3.2033-.007 3.5835.005 4.8495.0608 1.169.0508 1.8053.2445 2.228.408.5608.216.96.4754 1.3816.895.4217.4194.6816.8176.9005 1.3787.1653.4217.3617 1.056.4169 2.2263.0602 1.2655.0739 1.645.0796 4.848.0058 3.203-.0055 3.5834-.061 4.848-.051 1.17-.245 1.8055-.408 2.2294-.216.5604-.4763.96-.8954 1.3814-.419.4215-.8181.6811-1.3783.9-.4224.1649-1.0577.3617-2.2262.4174-1.2656.0595-1.6448.072-4.8493.079-3.2045.007-3.5825-.006-4.848-.0608M16.953 5.5864A1.44 1.44 0 1 0 18.39 4.144a1.44 1.44 0 0 0-1.437 1.4424M5.8385 12.012c.0067 3.4032 2.7706 6.1557 6.173 6.1493 3.4026-.0065 6.157-2.7701 6.1506-6.1733-.0065-3.4032-2.771-6.1565-6.174-6.1498-3.403.0067-6.156 2.771-6.1496 6.1738M8 12.0077a4 4 0 1 1 4.008 3.9921A3.9996 3.9996 0 0 1 8 12.0077' ),
		'pinterest' => array( 'label' => 'Pinterest', 'path' => 'M12.017 0C5.396 0 .029 5.367.029 11.987c0 5.079 3.158 9.417 7.618 11.162-.105-.949-.199-2.403.041-3.439.219-.937 1.406-5.957 1.406-5.957s-.359-.72-.359-1.781c0-1.663.967-2.911 2.168-2.911 1.024 0 1.518.769 1.518 1.688 0 1.029-.653 2.567-.992 3.992-.285 1.193.6 2.165 1.775 2.165 2.128 0 3.768-2.245 3.768-5.487 0-2.861-2.063-4.869-5.008-4.869-3.41 0-5.409 2.562-5.409 5.199 0 1.033.394 2.143.889 2.741.099.12.112.225.085.345-.09.375-.293 1.199-.334 1.363-.053.225-.172.271-.401.165-1.495-.69-2.433-2.878-2.433-4.646 0-3.776 2.748-7.252 7.92-7.252 4.158 0 7.392 2.967 7.392 6.923 0 4.135-2.607 7.462-6.233 7.462-1.214 0-2.354-.629-2.758-1.379l-.749 2.848c-.269 1.045-1.004 2.352-1.498 3.146 1.123.345 2.306.535 3.55.535 6.607 0 11.985-5.365 11.985-11.987C23.97 5.39 18.592.026 11.985.026L12.017 0z' ),
		'linkedin' => array( 'label' => 'LinkedIn', 'path' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433c-1.144 0-2.063-.926-2.063-2.065 0-1.138.92-2.063 2.063-2.063 1.14 0 2.064.925 2.064 2.063 0 1.139-.925 2.065-2.064 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z' ),
		'facebook' => array( 'label' => 'Facebook', 'path' => 'M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z' ),
		'youtube' => array( 'label' => 'YouTube', 'path' => 'M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z' ),
		'x' => array( 'label' => 'X', 'path' => 'M18.901 1.153h3.68l-8.04 9.19L24 22.846h-7.406l-5.8-7.584-6.638 7.584H.474l8.6-9.83L0 1.154h7.594l5.243 6.932ZM17.61 20.644h2.039L6.486 3.24H4.298Z' ),
	);
}

function dreamrugs_whatsapp_icon_path(): string {
	return 'M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z';
}

/** Inline SVG for a social/WhatsApp glyph path. */
function dreamrugs_social_svg( string $path, int $size = 16, string $class = '' ): void {
	printf(
		'<svg viewBox="0 0 24 24" width="%1$d" height="%1$d" fill="currentColor" aria-hidden="true"%2$s><path d="%3$s"/></svg>',
		(int) $size,
		$class ? ' class="' . esc_attr( $class ) . '"' : '',
		esc_attr( $path )
	);
}

/** The vendor's configured social links, in platform order. */
function dreamrugs_active_social_links(): array {
	$links = (array) ( dreamrugs_settings()['social_links'] ?? array() );
	$out   = array();
	foreach ( dreamrugs_social_platforms() as $key => $platform ) {
		if ( ! empty( $links[ $key ] ) ) {
			$out[ $key ] = $platform + array( 'url' => $links[ $key ] );
		}
	}
	return $out;
}

/** wa.me link for the first contact phone, or null. */
function dreamrugs_whatsapp_url( string $text = '' ): ?string {
	$phones = (array) ( dreamrugs_settings()['contact_phones'] ?? array() );
	$digits = isset( $phones[0] ) ? preg_replace( '/\\D/', '', (string) $phones[0] ) : '';
	if ( ! $digits ) {
		return null;
	}
	return 'https://wa.me/' . $digits . ( '' !== $text ? '?text=' . rawurlencode( $text ) : '' );
}

/** "@handle" from the Instagram profile URL's first path segment. */
function dreamrugs_instagram_handle(): ?string {
	$links = (array) ( dreamrugs_settings()['social_links'] ?? array() );
	if ( empty( $links['instagram'] ) ) {
		return null;
	}
	$path = trim( (string) wp_parse_url( $links['instagram'], PHP_URL_PATH ), '/' );
	$first = $path ? explode( '/', $path )[0] : '';
	return $first ? '@' . $first : null;
}

/** Branded sign-in page (page-account.php) with a return path. */
function dreamrugs_login_url( string $redirect = '' ): string {
	$page = get_page_by_path( 'account' );
	$url  = $page ? get_permalink( $page ) : wp_login_url();
	$redirect = $redirect ?: ( isset( $_SERVER['REQUEST_URI'] ) ? home_url( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- passed through esc_url_raw/add_query_arg.
	return $redirect ? add_query_arg( 'redirect_to', rawurlencode( esc_url_raw( $redirect ) ), $url ) : $url;
}

/**
 * Hidden honeypot + Turnstile container for a public form (assets/bot.js,
 * DRC_Security). The "website" field is invisible to people; bots fill it.
 */
function dreamrugs_bot_fields(): void {
	echo '<div aria-hidden="true" style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off" value=""></label></div>';
	if ( ! empty( dreamrugs_settings()['turnstile_site_key'] ) ) {
		echo '<div class="drc-turnstile"></div>';
	}
}

add_action( 'wp_enqueue_scripts', function () {
	wp_enqueue_script( 'dreamrugs-bot', get_stylesheet_directory_uri() . '/assets/bot.js', array(), filemtime( get_stylesheet_directory() . '/assets/bot.js' ), true );
	wp_add_inline_script( 'dreamrugs-bot', 'window.drcBotConfig = ' . wp_json_encode( array( 'siteKey' => (string) ( dreamrugs_settings()['turnstile_site_key'] ?? '' ) ) ) . ';', 'before' );
}, 5 );

// The same checks on WordPress / WooCommerce registration and sign-in forms.
foreach ( array( 'register_form', 'woocommerce_register_form', 'lostpassword_form', 'woocommerce_lostpassword_form' ) as $drc_hook ) {
	add_action( $drc_hook, 'dreamrugs_bot_fields' );
}
add_action( 'login_enqueue_scripts', function () {
	wp_enqueue_script( 'dreamrugs-bot', get_stylesheet_directory_uri() . '/assets/bot.js', array(), filemtime( get_stylesheet_directory() . '/assets/bot.js' ), true );
	wp_add_inline_script( 'dreamrugs-bot', 'window.drcBotConfig = ' . wp_json_encode( array( 'siteKey' => (string) ( dreamrugs_settings()['turnstile_site_key'] ?? '' ) ) ) . ';', 'before' );
} );
