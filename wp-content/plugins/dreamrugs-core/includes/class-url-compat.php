<?php
/**
 * Keeps the original app's public URLs working, so links, bookmarks and
 * search results carry over to the WordPress site:
 *
 *  - /catalog/ is the rug catalog (the shop page's slug) and each rug lives
 *    at /catalog/{slug}/ — WooCommerce's "shop base" product permalink,
 *    applied once on upgrade (only from the defaults, never over a
 *    vendor's own permalink choice).
 *  - /catalog/{id}/ (the original's numeric legacy URLs) 301s to the rug
 *    imported from that id (SKU legacy-rug-{id}).
 *  - /product/{slug}/ and /shop/ (this site's earlier bases) 301 to the
 *    current addresses.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DRC_Url_Compat {

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'redirect_legacy' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_cart_when_quote_only' ), 5 );
	}

	/** The original only has /cart and /checkout with direct purchase on
	 * (App.tsx: <Navigate to="/catalog" />). Paying for an accepted quote
	 * (checkout/order-pay) and the order-received page always work. */
	public static function redirect_cart_when_quote_only() {
		if ( ! function_exists( 'is_cart' ) || DRC_Settings::get( 'show_direct_purchase' ) ) {
			return;
		}
		if ( is_cart() || ( is_checkout() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) ) ) {
			wp_safe_redirect( wc_get_page_permalink( 'shop' ) );
			exit;
		}
	}

	/** One-time: shop page slug "shop" → "catalog", product base → shop base. */
	public static function apply_catalog_permalinks() {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return;
		}
		$shop_id = wc_get_page_id( 'shop' );
		$shop    = $shop_id > 0 ? get_post( $shop_id ) : null;
		if ( $shop && 'shop' === $shop->post_name && ! get_page_by_path( 'catalog' ) ) {
			wp_update_post( array( 'ID' => $shop_id, 'post_name' => 'catalog' ) );
		}
		$permalinks = (array) get_option( 'woocommerce_permalinks', array() );
		if ( empty( $permalinks['product_base'] ) || in_array( trim( $permalinks['product_base'], '/' ), array( 'product', 'shop' ), true ) ) {
			$permalinks['product_base'] = '/catalog';
			update_option( 'woocommerce_permalinks', $permalinks );
		}
	}

	public static function redirect_legacy() {
		if ( ! is_404() ) {
			return;
		}
		$path = trim( (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '', PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only matched against fixed patterns.
		$target = null;
		if ( preg_match( '#^catalog/(\d+)$#', $path, $m ) ) {
			$product_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( 'legacy-rug-' . $m[1] ) : 0;
			$target     = $product_id ? get_permalink( $product_id ) : null;
		} elseif ( preg_match( '#^shop(/page/(\d+))?$#', $path, $m ) ) {
			$target = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) . ( ! empty( $m[2] ) ? 'page/' . (int) $m[2] . '/' : '' ) : null;
		} elseif ( preg_match( '#^product/([^/]+)$#', $path, $m ) ) {
			$product = get_page_by_path( sanitize_title( $m[1] ), OBJECT, 'product' );
			$target  = $product ? get_permalink( $product ) : null;
		} elseif ( in_array( $path, array( 'login', 'verify-email', 'oauth-callback' ), true ) ) {
			// The original's customer auth pages → the branded /account page.
			$target = self::account_url();
		} elseif ( 'forgot-password' === $path || preg_match( '#^reset-password(/[^/]+)?$#', $path ) ) {
			// Old reset tokens can't carry over — ask for a fresh link.
			$target = add_query_arg( 'mode', 'forgot', self::account_url() );
		} elseif ( preg_match( '#^order/\d+$#', $path ) ) {
			$target = home_url( '/my-orders/' );
		} elseif ( preg_match( '#^admin(/.*)?$#', $path ) ) {
			// Staff bookmarks for the old admin panel.
			$target = 0 === strpos( $path, 'admin/forgot-password' ) || 0 === strpos( $path, 'admin/reset-password' ) ? wp_lostpassword_url() : admin_url();
		} elseif ( 'pricing' === $path ) {
			$target = home_url( '/' );
		}
		if ( $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	private static function account_url(): string {
		$page = get_page_by_path( 'account' );
		return $page ? get_permalink( $page ) : wp_login_url();
	}
}
