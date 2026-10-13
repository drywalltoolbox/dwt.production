<?php
/**
 * Staging-only WooPayments product-context verification surface.
 *
 * Deliberately limited to the private WordPress page #19665, requested through
 * /staging/__wallet-probe-20261013/ on drywalltoolbox.com. This does not alter
 * React PDP/quick-view components, payment gateway configuration or checkout.
 */
defined( 'ABSPATH' ) || exit;

function dtb_staging_wallet_probe_request(): bool {
    if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
        return false;
    }
    $path = parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
    return $path === '/staging/__wallet-probe-20261013/' || $path === '/staging/__wallet-probe-20261013';
}

function dtb_staging_wallet_probe_authorized(): bool {
    if ( ! dtb_staging_wallet_probe_request() || ! is_page( 19665 ) ) {
        return false;
    }
    $settings = get_option( 'woocommerce_woocommerce_payments_settings', [] );
    return (
        is_user_logged_in()
        && current_user_can( 'manage_woocommerce' )
        && is_array( $settings )
        && ( $settings['test_mode'] ?? '' ) === 'yes'
        && class_exists( 'WooCommerce' )
        && class_exists( 'WC_Payments' )
    );
}

add_action( 'wp', static function (): void {
    if ( ! dtb_staging_wallet_probe_request() ) {
        return;
    }
    if ( ! dtb_staging_wallet_probe_authorized() ) {
        status_header( 404 );
        nocache_headers();
        exit;
    }

    // The active React theme strips all payment plugin scripts by default.
    // Only for this private, sandbox-only native page do we allow provider assets.
    remove_action( 'wp_enqueue_scripts', 'dtb_dequeue_non_react_assets', 9999 );
    remove_action( 'wp_enqueue_scripts', 'dtb_enqueue_react_app' );
    add_filter( 'wp_robots', static function ( array $robots ): array {
        $robots['noindex'] = true;
        $robots['nofollow'] = true;
        return $robots;
    } );
    add_action( 'send_headers', static function (): void {
        nocache_headers();
        header( 'X-Robots-Tag: noindex, nofollow', true );
    } );
} );

add_filter( 'template_include', static function ( string $template ): string {
    if ( ! dtb_staging_wallet_probe_authorized() ) {
        return $template;
    }
    $native = __DIR__ . '/dtb-commerce/templates/staging-wallet-probe.php';
    return is_readable( $native ) ? $native : $template;
}, 1000 );
