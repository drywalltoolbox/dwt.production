<?php
/**
 * Administrator-only staging preview of the *native* WooPayments product ECE.
 *
 * The real WooCommerce single product request is required by WooPayments 11.2:
 * its product checks and JS settings are computed from is_product(), the global
 * product and the normal Woo hooks. This adapter never handles payments/orders.
 */
defined( 'ABSPATH' ) || exit;

function dtb_staging_native_wallet_request_id(): int {
    if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return 0;
    }
    $path = (string) parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
    if ( ! preg_match( '#^/staging/__native-wallet/([1-9][0-9]*)/?$#D', $path, $match ) ) {
        return 0;
    }
    return absint( $match[1] );
}

function dtb_staging_native_wallet_allowed(): bool {
    $id = dtb_staging_native_wallet_request_id();
    if ( ! $id || ! is_user_logged_in() || ! current_user_can( 'manage_woocommerce' ) ) {
        return false;
    }
    $settings = get_option( 'woocommerce_woocommerce_payments_settings', [] );
    if ( ! is_array( $settings ) || ( $settings['test_mode'] ?? '' ) !== 'yes' ) {
        return false;
    }
    if ( ! class_exists( 'WC_Payments' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
        return false;
    }
    if ( absint( get_queried_object_id() ) !== $id ) {
        return false;
    }
    $product = wc_get_product( $id );
    return $product instanceof WC_Product && $product->is_purchasable() && $product->is_in_stock();
}

add_action( 'wp', static function (): void {
    if ( ! dtb_staging_native_wallet_request_id() ) {
        return;
    }
    // Hide the surface instead of falling through to the React SPA or redirecting
    // to a publicly routable product page. No cache may store authenticated HTML.
    if ( ! dtb_staging_native_wallet_allowed() ) {
        status_header( 404 );
        nocache_headers();
        exit;
    }
    remove_action( 'template_redirect', 'redirect_canonical' );
    remove_action( 'wp_enqueue_scripts', 'dtb_dequeue_non_react_assets', 9999 );
    remove_action( 'wp_enqueue_scripts', 'dtb_enqueue_react_app' );
    add_action( 'send_headers', static function (): void {
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
        header( 'Content-Security-Policy: frame-ancestors \'self\'', true );
    } );
}, 1 );

add_filter( 'template_include', static function ( string $template ): string {
    if ( ! dtb_staging_native_wallet_allowed() ) {
        return $template;
    }
    $native = __DIR__ . '/dtb-commerce/templates/staging-native-wallet.php';
    return is_readable( $native ) ? $native : $template;
}, 1000 );
