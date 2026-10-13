<?php
/**
 * Single-use, operator-authorized accounting isolation for a native Woo checkout.
 * Does not create orders, alter gateway settings or affect Veeqo fulfillment.
 */
defined( 'ABSPATH' ) || exit;

final class DTB_TestCheckoutExclusion {
    private const TTL = 900;
    private const META = '_dtb_quickbooks_export_suppressed';

    public static function register(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register_route' ] );
        add_action( 'woocommerce_store_api_checkout_update_order_from_request', [ __CLASS__, 'mark_store_api_order' ], 1, 2 );
        add_action( 'woocommerce_checkout_create_order', [ __CLASS__, 'mark_classic_order' ], 1, 2 );
    }

    public static function register_route(): void {
        register_rest_route( 'dtb/v1', '/admin/test-checkout/arm', [
            'methods' => WP_REST_Server::CREATABLE,
            'permission_callback' => static function (): bool {
                return current_user_can( 'manage_woocommerce' );
            },
            'callback' => [ __CLASS__, 'arm' ],
        ] );
    }

    private static function is_gateway_in_test_mode(): bool {
        $settings = get_option( 'woocommerce_woocommerce_payments_settings', [] );
        return is_array( $settings ) && 'yes' === (string) ( $settings['enabled'] ?? '' )
            && 'yes' === (string) ( $settings['test_mode'] ?? '' );
    }

    private static function cart_session_key(): string {
        $matches = [];
        foreach ( $_COOKIE as $name => $value ) {
            if ( str_starts_with( (string) $name, 'wp_woocommerce_session_' ) && is_string( $value ) && '' !== $value ) {
                $matches[] = $value;
            }
        }
        if ( 1 !== count( $matches ) ) {
            return '';
        }
        return 'dtb_test_checkout_' . hash_hmac( 'sha256', $matches[0], wp_salt( 'auth' ) );
    }

    public static function arm(): WP_REST_Response|WP_Error {
        if ( ! self::is_gateway_in_test_mode() ) {
            return new WP_Error( 'test_payment_mode_required', 'WooPayments must be enabled in test mode.', [ 'status' => 409 ] );
        }
        $key = self::cart_session_key();
        if ( '' === $key ) {
            return new WP_Error( 'test_cart_session_required', 'An existing WooCommerce cart session is required.', [ 'status' => 409 ] );
        }
        set_transient( $key, [ 'user_id' => get_current_user_id(), 'expires' => time() + self::TTL ], self::TTL );
        return new WP_REST_Response( [ 'armed' => true, 'expires_in_seconds' => self::TTL, 'single_use' => true ], 200 );
    }

    private static function claim( WC_Order $order ): void {
        if ( 'yes' === (string) $order->get_meta( self::META, true ) || ! self::is_gateway_in_test_mode() ) {
            return;
        }
        $key = self::cart_session_key();
        if ( '' === $key ) {
            return;
        }
        $authorization = get_transient( $key );
        if ( ! is_array( $authorization ) || absint( $authorization['user_id'] ?? 0 ) <= 0
            || time() > absint( $authorization['expires'] ?? 0 ) ) {
            return;
        }
        // Consume the authorization before marking the order. An operator must
        // explicitly rearm for another order; retries cannot arm themselves.
        delete_transient( $key );
        $order->update_meta_data( self::META, 'yes' );
        $order->save_meta_data();
    }

    public static function mark_store_api_order( $order, $request ): void {
        unset( $request );
        if ( $order instanceof WC_Order ) {
            self::claim( $order );
        }
    }

    public static function mark_classic_order( $order, $data ): void {
        unset( $data );
        if ( $order instanceof WC_Order ) {
            self::claim( $order );
        }
    }
}

DTB_TestCheckoutExclusion::register();
