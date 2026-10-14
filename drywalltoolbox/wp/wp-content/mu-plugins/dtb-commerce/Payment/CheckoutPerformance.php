<?php
/**
 * Checkout runtime telemetry and diagnostics.
 *
 * WooCommerce Checkout Block and Payment Plugins for Stripe own the complete
 * checkout/payment runtime graph. This class records bounded, non-secret
 * diagnostics only; it never dequeues, preloads, reprioritizes, or changes
 * provider execution strategy or apply presentation styling.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CheckoutPerformance {
	private const ASSET_VERSION              = '2026.07.28.3';
	private const TELEMETRY_NONCE_ACTION     = 'dtb_checkout_runtime_telemetry';
	private const PAYMENT_SURFACE_TIMEOUT_MS = 15000;
	private const TELEMETRY_EVENT_TTL        = 10 * MINUTE_IN_SECONDS;

	public static function register(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue_runtime_asset' ], 40 );
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'augment_checkout_capabilities' ], 20, 3 );
	}

	public static function register_rest_routes(): void {
		register_rest_route(
			'dtb/v1',
			'/checkout/runtime-telemetry',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'record_runtime_telemetry' ],
				'permission_callback' => [ __CLASS__, 'telemetry_permission' ],
			]
		);
	}

	public static function enqueue_runtime_asset(): void {
		if ( ! self::is_primary_checkout_request() ) {
			return;
		}

		wp_enqueue_script(
			'dtb-woo-native-checkout-performance',
			content_url( 'mu-plugins/dtb-commerce/assets/woo-native-checkout-performance.js' ),
			[],
			self::ASSET_VERSION,
			true
		);

		wp_localize_script(
			'dtb-woo-native-checkout-performance',
			'DTB_CHECKOUT_PERFORMANCE',
			[
				// Keep WordPress cookie authentication attached to this same-origin REST request.
				'wpRestNonce'             => wp_create_nonce( 'wp_rest' ),
				'telemetryUrl'            => esc_url_raw( rest_url( 'dtb/v1/checkout/runtime-telemetry' ) ),
				'telemetryNonce'          => wp_create_nonce( self::TELEMETRY_NONCE_ACTION ),
				'paymentSurfaceTimeoutMs' => self::PAYMENT_SURFACE_TIMEOUT_MS,
			]
		);
	}

	/**
	 * Add non-secret runtime-integrity metadata to the existing capabilities route.
	 *
	 * @param WP_REST_Response|WP_HTTP_Response|WP_Error $response REST response.
	 * @param array                                      $handler  Route handler.
	 * @param WP_REST_Request                            $request  Request object.
	 * @return WP_REST_Response|WP_HTTP_Response|WP_Error
	 */
	public static function augment_checkout_capabilities( $response, array $handler, WP_REST_Request $request ) {
		unset( $handler );
		if ( '/dtb/v1/checkout/capabilities' !== $request->get_route() || is_wp_error( $response ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}

		$data = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}

		$data['performance'] = [
			'checkout_runtime_telemetry' => true,
			'payment_surface_timeout_ms' => self::PAYMENT_SURFACE_TIMEOUT_MS,
			'checkout_document_cache'    => 'private_no_store',
			'runtime_asset_authority'    => 'woocommerce_payment_plugins_stripe',
			'dtb_asset_queue_mutation'   => false,
			'dtb_asset_prewarm'          => false,
		];
		$response->set_data( $data );
		return $response;
	}

	/** @return true|WP_Error */
	public static function telemetry_permission( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'X-DTB-Checkout-Telemetry' );
		if ( '' === $nonce ) {
			$nonce = sanitize_text_field( (string) $request->get_param( 'nonce' ) );
		}
		if ( ! wp_verify_nonce( $nonce, self::TELEMETRY_NONCE_ACTION ) ) {
			return new WP_Error( 'dtb_checkout_telemetry_forbidden', 'Invalid checkout telemetry nonce.', [ 'status' => 403 ] );
		}
		if ( ! self::request_origin_is_same_site() ) {
			return new WP_Error( 'dtb_checkout_telemetry_origin', 'Invalid checkout telemetry origin.', [ 'status' => 403 ] );
		}
		if ( class_exists( 'DTB_RateLimiter' ) ) {
			return DTB_RateLimiter::check( 'checkout_runtime_telemetry', 18, 5 * MINUTE_IN_SECONDS );
		}
		return true;
	}

	public static function record_runtime_telemetry( WP_REST_Request $request ): WP_REST_Response {
		$event_id = sanitize_text_field( (string) $request->get_param( 'event_id' ) );
		if ( ! preg_match( '/^[A-Za-z0-9_-]{8,80}$/', $event_id ) ) {
			return new WP_REST_Response( [ 'accepted' => false, 'reason' => 'invalid_event_id' ], 400 );
		}

		$dedupe_key = 'dtb_checkout_rt_' . substr( hash( 'sha256', $event_id ), 0, 32 );
		if ( false !== get_transient( $dedupe_key ) ) {
			return new WP_REST_Response( [ 'accepted' => true, 'duplicate' => true ], 202 );
		}
		set_transient( $dedupe_key, '1', self::TELEMETRY_EVENT_TTL );

		$kind          = sanitize_key( (string) $request->get_param( 'kind' ) );
		$allowed_kinds = [
			'js_error',
			'unhandled_rejection',
			'resource_error',
			'payment_surface_timeout',
			'checkout_root_replaced',
			'checkout_vitals',
			'third_party_budget',
		];
		if ( ! in_array( $kind, $allowed_kinds, true ) ) {
			$kind = 'js_error';
		}

		$detail = $request->get_param( 'detail' );
		$detail = is_array( $detail ) ? self::sanitize_detail( $detail ) : [];
		$context = [
			'kind'       => $kind,
			'event_id'   => $event_id,
			'message'    => self::bounded_text( (string) $request->get_param( 'message' ), 500 ),
			'source'     => self::sanitize_source( (string) $request->get_param( 'source' ) ),
			'line'       => absint( $request->get_param( 'line' ) ),
			'column'     => absint( $request->get_param( 'column' ) ),
			'viewport_w' => min( 10000, absint( $request->get_param( 'viewport_w' ) ) ),
			'step'       => sanitize_key( (string) $request->get_param( 'step' ) ),
			'detail'     => $detail,
		];

		if ( class_exists( 'DTB_Logger' ) ) {
			DTB_Logger::warning( 'Checkout runtime telemetry', $context );
		} else {
			error_log( wp_json_encode( [ 'source' => 'dtb-checkout', 'level' => 'warning', 'context' => $context ], JSON_UNESCAPED_SLASHES ) );
		}
		return new WP_REST_Response( [ 'accepted' => true ], 202 );
	}

	private static function request_origin_is_same_site(): bool {
		$origin = get_http_origin();
		if ( ! $origin ) {
			return true;
		}
		$request_origin = self::normalized_origin( $origin );
		$home_origin    = self::normalized_origin( home_url( '/' ) );
		return '' !== $request_origin && '' !== $home_origin && hash_equals( $home_origin, $request_origin );
	}

	private static function normalized_origin( string $url ): string {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port   = absint( wp_parse_url( $url, PHP_URL_PORT ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return '';
		}
		$default_port = 'https' === $scheme ? 443 : 80;
		$port_suffix  = $port > 0 && $port !== $default_port ? ':' . $port : '';
		return $scheme . '://' . $host . $port_suffix;
	}

	private static function sanitize_source( string $source ): string {
		$source = trim( $source );
		if ( '' === $source ) {
			return '';
		}
		$parts = wp_parse_url( $source );
		if ( false === $parts ) {
			return self::bounded_text( $source, 240 );
		}
		$host = sanitize_text_field( (string) ( $parts['host'] ?? '' ) );
		$path = sanitize_text_field( (string) ( $parts['path'] ?? '' ) );
		return self::bounded_text( trim( $host . $path ), 240 );
	}

	private static function sanitize_detail( array $detail ): array {
		$clean = [];
		foreach ( array_slice( $detail, 0, 16, true ) as $key => $value ) {
			$clean_key = sanitize_key( (string) $key );
			if ( '' === $clean_key ) {
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $clean_key ] = $value;
				continue;
			}
			if ( is_string( $value ) ) {
				$clean[ $clean_key ] = self::bounded_text( $value, 240 );
				continue;
			}
			if ( is_array( $value ) ) {
				$items = [];
				foreach ( array_slice( $value, 0, 12 ) as $item ) {
					if ( is_scalar( $item ) ) {
						$items[] = self::bounded_text( (string) $item, 160 );
					}
				}
				$clean[ $clean_key ] = $items;
			}
		}
		return $clean;
	}

	private static function redact_sensitive_text( string $value ): string {
		$redactions = [
			'/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i' => '[redacted-email]',
			'/\b(?:sk|rk)_(?:live|test)_[A-Za-z0-9_\-]+\b/' => '[redacted-stripe-key]',
			'/\bpk_(?:live|test)_[A-Za-z0-9_\-]+\b/' => '[redacted-stripe-key]',
			'/\bwhsec_[A-Za-z0-9_\-]+\b/' => '[redacted-webhook-secret]',
			'/\b(?:pi|seti)_[A-Za-z0-9]+_secret_[A-Za-z0-9_\-]+\b/' => '[redacted-client-secret]',
			'/\bcs_(?:live|test)_[A-Za-z0-9_\-]+\b/' => '[redacted-checkout-secret]',
			'/\bwc_order_[A-Za-z0-9_\-]+\b/' => '[redacted-order-key]',
			'/\bBearer\s+[A-Za-z0-9._~+\/\-]+=*/i' => 'Bearer [redacted-token]',
			'/\beyJ[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\.[A-Za-z0-9_\-]{10,}\b/' => '[redacted-jwt]',
			'/client_secret\s*[=:]\s*[^\s&,;]+/i' => 'client_secret=[redacted]',
		];
		$redacted = preg_replace( array_keys( $redactions ), array_values( $redactions ), $value );
		return is_string( $redacted ) ? $redacted : '[redacted]';
	}

	private static function bounded_text( string $value, int $max_length ): string {
		$value = self::redact_sensitive_text( sanitize_text_field( $value ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	private static function is_primary_checkout_request(): bool {
		if ( is_admin() || ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return false;
		}
		if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) ) {
			return false;
		}
		return true;
	}
}

DTB_CheckoutPerformance::register();
