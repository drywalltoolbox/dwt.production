<?php
/**
 * Same-origin bootstrap for Google's installed WooCommerce Gemini widget.
 *
 * This endpoint publishes only the same non-secret presentation configuration
 * Google's Storefront class emits through wp_footer/wp_localize_script on a
 * WordPress-rendered page. Authentication, session tokens, and cart mutations
 * remain exclusively in Google's plugin and WooCommerce.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', static function (): void {
    register_rest_route( 'dtb/v1', '/gemini-storefront', [
        'methods'             => WP_REST_Server::READABLE,
        'permission_callback' => '__return_true',
        'callback'            => static function () {
            if ( ! class_exists( \Google\Gemini_Enterprise_For_CX\Storefront::class ) ) {
                return new WP_REST_Response( [ 'enabled' => false ], 200 );
            }

            $agent = (string) apply_filters( 'gecx_active_agent_name', (string) get_option( 'gecx_agent_name', '' ) );
            $enabled = (bool) apply_filters( 'gecx_is_widget_enabled', (bool) get_option( 'gecx_agent_enabled', false ) );
            if ( ! $enabled || '' === $agent ) {
                return new WP_REST_Response( [ 'enabled' => false ], 200 );
            }

            $plugin_file = WP_PLUGIN_DIR . '/gemini-enterprise-for-cx/gecx-agent.php';
            if ( ! is_file( $plugin_file ) ) {
                return new WP_REST_Response( [ 'enabled' => false ], 200 );
            }

            $widget_url = (string) apply_filters( 'gecx_chat_widget_script_url', 'https://www.gstatic.com/gecx/chat-widget/woocommerce-chat-widget.js' );
            $load_now = (bool) apply_filters(
                'gecx_should_load_widget',
                ! (bool) get_option( 'gecx_defer_widget_until_interaction', false )
            );
            $config = [
                'placement'        => 'manual',
                'markup'           => [ 'button' => do_shortcode( '[gecx_agent_button]' ) ],
                'isWidgetEnabled'  => true,
                'widgetScriptUrl'  => esc_url_raw( $widget_url ),
                'shouldLoadWidget' => $load_now,
                'cartRestUrl'      => esc_url_raw( rest_url( 'wc/store/v1/cart' ) ),
                'authContextUrl'   => esc_url_raw( rest_url( 'gecx/v1/auth-context' ) ),
                'sessionUrl'       => esc_url_raw( rest_url( 'gecx/v1/session' ) ),
                'isCart'           => false,
                'isCheckout'       => false,
                'isCartOrCheckout' => false,
                'cartRefresh'      => \Google\Gemini_Enterprise_For_CX\Storefront::get_cart_refresh_config(),
            ];

            $data = [
                'enabled'       => true,
                'agentName'     => $agent,
                'tokenBroker'   => (string) apply_filters( 'gecx_token_broker_name', (string) get_option( 'gecx_token_broker_name', '' ) ),
                'storeUrl'      => home_url( '/' ),
                'restUrl'       => rest_url(),
                'config'        => $config,
                'styleUrl'      => plugins_url( 'assets/css/theme.css', $plugin_file ),
                'storefrontUrl' => plugins_url( 'build/scripts/storefront/index.min.js', $plugin_file ),
                'widgetUrl'     => esc_url_raw( $widget_url ),
            ];
            $response = new WP_REST_Response( $data, 200 );
            $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate' );
            return $response;
        },
    ] );
} );
