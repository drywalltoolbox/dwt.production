<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB REST API — Must-Use Plugin
 *
 * Single authoritative source for ALL custom REST routes registered by the
 * Drywall Toolbox suite.  Consolidates what was previously spread across:
 *
 *   • drywall-api-proxy.php   (drywall/v1 namespace — commerce and webhook routes)
 *   • dtb-woocommerce.php     (dtb/v1 config, catalog, products-csv, import-catalog)
 *   • dtb-app-passwords.php   (dtb/v1 create-app-password)
 *
 * Namespaces
 * ──────────
 *   drywall/v1   Server-side WC REST proxy (orders, customers, coupons, webhooks)
 *   dtb/v1       Site-management endpoints  (config, catalog, import, auth, …)
 *
 * Depends on (loaded before this file via 00-dtb-loader.php)
 * ────────────────────────────────────────────────────
 *   00-dtb-loader.php  → dtb_allowed_origins(), dtb_check_origin()
 *   dtb-utils.php      → dtb_get_config(), dtb_get_wc_credentials(),
 *                         dtb_error_envelope(), dtb_get_client_ip()
 *   dtb-auth.php       → dtb_jwt_permission()
 *   dtb-cache.php      → dtb_cached_proxy(), dtb_invalidate_product_cache(),
 *                         dtb_log_cache_event()
 *
 * Non-REST concerns (WC config, webhooks, schematics, coming-soon) remain
 * in their dedicated files. REST/CORS policy lives in dtb-api-security.php.
 *
 * @package drywall-toolbox
 */


// =============================================================================
// ROUTE REGISTRATION
// =============================================================================

add_action( 'rest_api_init', 'dtb_register_all_routes', 10 );

function dtb_register_all_routes(): void {
	dtb_register_proxy_routes();
	dtb_register_config_routes();
}

/**
 * WP REST-safe numeric validator callback.
 *
 * WP passes ( $value, $request, $param ) to validate callbacks, so built-ins
 * like is_numeric() cannot be used directly as callback strings.
 */
function dtb_rest_validate_numeric( $value, $request = null, $param = null ): bool {
	return is_numeric( $value );
}

// =============================================================================
// A. drywall/v1 — WooCommerce Proxy
//    Server-side proxy that forwards requests to WC REST API v3.
//    Consumer credentials (WC_PROXY_CONSUMER_KEY / SECRET) live exclusively
//    in wp-config.php and are never returned to the client.
// =============================================================================

function dtb_register_proxy_routes(): void {
	$ns = 'drywall/v1';

	// ── JWT-gated order routes ────────────────────────────────────────────────

	register_rest_route( $ns, '/orders/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'dtb_proxy_get_order',
		'permission_callback' => 'dtb_jwt_permission',
		'args'                => [ 'id' => [ 'validate_callback' => 'dtb_rest_validate_numeric' ] ],
	] );

	// ── GET /drywall/v1/orders — customer's own order list (JWT-gated) ────────
	register_rest_route( $ns, '/orders', [
		'methods'             => 'GET',
		'callback'            => 'dtb_proxy_get_orders',
		'permission_callback' => 'dtb_jwt_permission',
	] );

	// ── Coupons ───────────────────────────────────────────────────────────────

	register_rest_route( $ns, '/coupons/(?P<code>[a-zA-Z0-9_-]+)', [
		'methods'             => 'GET',
		'callback'            => 'dtb_proxy_coupon',
		'permission_callback' => '__return_true',
	] );

	// ── Customer routes ───────────────────────────────────────────────────────

	register_rest_route( $ns, '/customers', [
		'methods'             => 'POST',
		'callback'            => 'dtb_proxy_create_customer',
		'permission_callback' => '__return_true',
	] );

	register_rest_route( $ns, '/customers/(?P<id>\d+)', [
		'methods'             => 'GET',
		'callback'            => 'dtb_proxy_get_customer',
		'permission_callback' => 'dtb_jwt_permission',
		'args'                => [ 'id' => [ 'validate_callback' => 'dtb_rest_validate_numeric' ] ],
	] );

	// ── Cache-invalidation webhook receiver ───────────────────────────────────

	register_rest_route( $ns, '/webhooks/products', [
		'methods'             => 'POST',
		'callback'            => 'dtb_proxy_webhook_products',
		'permission_callback' => '__return_true',
	] );
}

// =============================================================================
// B. dtb/v1 — Site Management Endpoints
// =============================================================================

function dtb_register_config_routes(): void {
	$ns = 'dtb/v1';

	// ── GET /dtb/v1/config — runtime WC credentials for the SPA ──────────────
	register_rest_route( $ns, '/config', [
		'methods'             => 'GET',
		'callback'            => 'dtb_route_config',
		'permission_callback' => '__return_true',
	] );

	/*
	 * Raw catalog export and remote catalog import are intentionally not
	 * registered. The import CSV contains private commercial metadata and the
	 * legacy mutation route used a replayable shared secret. A replacement must
	 * be owned by dtb-catalog-platform and use an authenticated, hash-pinned
	 * release operation before either capability can return.
	 */

	// ── POST /dtb/v1/create-app-password ─────────────────────────────────────
	register_rest_route( $ns, '/create-app-password', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_create_app_password',
		'permission_callback' => '__return_true',
		'args'                => [
			'username' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_user',
				'description'       => 'WordPress username.',
			],
			'password' => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'WordPress password.',
			],
			'app_name' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'Drywall Toolbox',
				'description'       => 'Application name for the generated password.',
			],
		],
	] );

	// ── POST /dtb/v1/webhooks/ensure — create any missing DTB product webhooks ─
	register_rest_route( $ns, '/webhooks/ensure', [
		'methods'             => [ 'GET', 'POST' ],
		'callback'            => 'dtb_route_ensure_webhooks',
		'permission_callback' => 'dtb_route_ensure_webhooks_permission',
		'args'                => [
			'secret' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Optional webhook secret for non-cookie auth.',
			],
		],
	] );

	// ── POST /dtb/v1/admin/webhooks/sync-secrets — force-push rotated secrets ─
	register_rest_route( $ns, '/admin/webhooks/sync-secrets', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_admin_sync_webhook_secrets',
		'permission_callback' => static function () {
			return current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' );
		},
	] );

	// ── POST /dtb/v1/admin/cache/products/flush — admin cache flush ───────────
	register_rest_route( $ns, '/admin/cache/products/flush', [
		'methods'             => 'POST',
		'callback'            => 'dtb_route_admin_cache_flush',
		'permission_callback' => 'dtb_admin_cache_flush_permission',
	] );

	// ── Diagnostic: GET /dtb/v1/cors-test — CORS debugging endpoint ──────────
	register_rest_route( $ns, '/cors-test', [
		'methods'             => 'GET',
		'callback'            => 'dtb_route_cors_test',
		'permission_callback' => '__return_true',
	] );

	// ── WC-Admin onboarding profile shim (suppresses core-profiler crash) ────
	register_rest_route( 'wc-admin', '/profile', [
		'methods'             => 'GET',
		'callback'            => 'dtb_route_wc_admin_profile',
		'permission_callback' => '__return_true',
	] );

	// ── POST /dtb/v1/contact — public contact form submission ─────────────────
	register_rest_route( $ns, '/contact', [
		'methods'             => 'POST',
		'callback'            => 'dtb_contact_form_handler',
		'permission_callback' => '__return_true',
		'args'                => [
			'name'         => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'description'       => 'Sender full name.',
			],
			'email'        => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_email',
				'description'       => 'Sender e-mail address.',
			],
			'inquiry_type' => [
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => 'General Question',
				'description'       => 'Category of inquiry.',
			],
			'message'      => [
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'description'       => 'Message body.',
			],
		],
	] );

}


// =============================================================================
// HELPERS — WC HTTP transport
// =============================================================================

/**
 * Build the Basic-auth header for WC REST API v3 server-to-server calls.
 *
 * Priority:
 *   1) WC REST API consumer key/secret (preferred for service-to-service proxying)
 *   2) WordPress Application Password credentials (staging fallback)
 *
 * Returns an empty string when neither credential pair is configured.
 */
function dtb_wc_auth_header(): string {
	$config = dtb_get_config();

	$proxy_key    = trim( (string) ( $config['wc_proxy_key'] ?? '' ) );
	$proxy_secret = trim( (string) ( $config['wc_proxy_secret'] ?? '' ) );
	if ( '' !== $proxy_key && '' !== $proxy_secret ) {
		return 'Basic ' . base64_encode( $proxy_key . ':' . $proxy_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	$auth_user = trim( (string) ( $config['wc_auth_user'] ?? '' ) );
	// WP app passwords are often displayed with spaces; strip all whitespace.
	$auth_pass = preg_replace( '/\s+/', '', (string) ( $config['wc_auth_pass'] ?? '' ) );
	if ( '' !== $auth_user && '' !== $auth_pass ) {
		return 'Basic ' . base64_encode( $auth_user . ':' . $auth_pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	return '';
}

/**
 * Resolve a WC REST path to an absolute URL.
 */
function dtb_wc_url( string $path ): string {
	$normalized = ltrim( $path, '/' );
	$base       = rtrim( home_url( '/wp-json' ), '/' );
	return $base . '/' . $normalized;
}

/**
 * POST to a WC endpoint (no caching — mutating).
 */
function dtb_wc_post( string $wc_path, array $body ): WP_REST_Response {
	if ( ! dtb_check_origin() ) {
		return new WP_REST_Response( dtb_error_envelope( 'forbidden_origin', 'Origin not allowed.', 403 ), 403 );
	}

	$auth_header = dtb_wc_auth_header();
	if ( '' === $auth_header ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'wc_auth_not_configured', 'Store backend credentials are not configured.', 500 ),
			500
		);
	}

	$raw = wp_remote_post( dtb_wc_url( $wc_path ), [
		'headers' => [
			'Authorization' => $auth_header,
			'Content-Type'  => 'application/json',
		],
		'body'    => wp_json_encode( $body ),
		'timeout' => 15,
	] );

	if ( is_wp_error( $raw ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'upstream_error', 'Could not reach the store backend.', 502 ),
			502
		);
	}

	$code = wp_remote_retrieve_response_code( $raw );
	$data = json_decode( wp_remote_retrieve_body( $raw ), true );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'upstream_error', 'Store backend returned an error.', (int) $code ),
			(int) $code
		);
	}

	return new WP_REST_Response( $data, (int) $code );
}

/**
 * GET a WC endpoint without caching (used for order/customer reads).
 */
function dtb_wc_get( string $wc_path, array $params = [] ): WP_REST_Response {
	if ( ! dtb_check_origin() ) {
		return new WP_REST_Response( dtb_error_envelope( 'forbidden_origin', 'Origin not allowed.', 403 ), 403 );
	}

	$auth_header = dtb_wc_auth_header();
	if ( '' === $auth_header ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'wc_auth_not_configured', 'Store backend credentials are not configured.', 500 ),
			500
		);
	}

	$rl = dtb_rate_limit_get( $wc_path );
	if ( $rl ) {
		return $rl;
	}

	$wc_url = dtb_wc_url( $wc_path );
	if ( ! empty( $params ) ) {
		$wc_url = add_query_arg( $params, $wc_url );
	}

	$raw = wp_remote_get( $wc_url, [
		'headers' => [ 'Authorization' => $auth_header ],
		'timeout' => 15,
	] );

	if ( is_wp_error( $raw ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'upstream_error', 'Could not reach the store backend.', 502 ),
			502
		);
	}

	$code = wp_remote_retrieve_response_code( $raw );
	$data = json_decode( wp_remote_retrieve_body( $raw ), true );

	if ( $code < 200 || $code >= 300 ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'upstream_error', 'Store backend returned an error.', (int) $code ),
			(int) $code
		);
	}

	return new WP_REST_Response( $data, (int) $code );
}

// =============================================================================
// HELPERS — Rate limiters
// =============================================================================

/**
 * Rate-limit mutating routes: 10 requests per 60 s per IP.
 *
 * @param WP_REST_Request $request Current request (used for context in errors).
 * @param string          $route_key Unique key per route (e.g. 'orders_post').
 * @return WP_REST_Response|null  Response to return immediately, or null to continue.
 */
function dtb_proxy_mutation_rate_limit( WP_REST_Request $request, string $route_key ): ?WP_REST_Response {
	if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return new WP_REST_Response( dtb_error_envelope( 'bad_request', 'Unable to identify request origin.', 400 ), 400 );
	}
	$ip    = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	$key   = 'drywall_rl_' . md5( $ip ) . '_' . md5( $route_key );
	$count = (int) get_transient( $key );
	if ( $count >= 10 ) {
		$resp = new WP_REST_Response(
			dtb_error_envelope( 'rate_limited', 'Too many requests. Please try again later.', 429 ),
			429
		);
		$resp->header( 'Retry-After', '60' );
		return $resp;
	}
	set_transient( $key, $count + 1, 60 );
	return null;
}

/**
 * Rate-limit public GET routes: 100 requests per 60 s per IP.
 *
 * @return WP_REST_Response|null  Response to return immediately, or null to continue.
 */
function dtb_get_public_get_rate_limit_config( string $route_key ): array {
	$normalized = ltrim( strtolower( $route_key ), '/' );

	if ( false !== strpos( $normalized, '/variations' ) ) {
		return [ 'key' => 'products_variations', 'limit' => 300, 'window' => 60 ];
	}

	if ( 0 === strpos( $normalized, 'wc/v3/products/categories' ) ) {
		return [ 'key' => 'products_categories', 'limit' => 180, 'window' => 60 ];
	}

	if ( 0 === strpos( $normalized, 'wc/v3/products/attributes' ) ) {
		return [ 'key' => 'products_attributes', 'limit' => 180, 'window' => 60 ];
	}

	if ( 0 === strpos( $normalized, 'wc/v3/products' ) ) {
		return [ 'key' => 'products', 'limit' => 240, 'window' => 60 ];
	}

	return [ 'key' => 'public_get', 'limit' => 120, 'window' => 60 ];
}

function dtb_rate_limit_get( string $route_key = 'public_get' ): ?WP_REST_Response {
	$ip = dtb_get_client_ip();
	if ( '0.0.0.0' === $ip ) {
		return new WP_REST_Response( dtb_error_envelope( 'bad_request', 'Unable to identify request origin.', 400 ), 400 );
	}

	$config = dtb_get_public_get_rate_limit_config( $route_key );
	$key    = 'drywall_rl_get_' . md5( $ip ) . '_' . md5( $config['key'] );
	$count  = (int) get_transient( $key );
	if ( $count >= $config['limit'] ) {
		$resp = new WP_REST_Response(
			dtb_error_envelope( 'rate_limited', 'Too many requests. Please try again later.', 429 ),
			429
		);
		$resp->header( 'Retry-After', (string) $config['window'] );
		return $resp;
	}
	set_transient( $key, $count + 1, $config['window'] );
	return null;
}

// =============================================================================
// HELPERS — App-password rate limiter
// =============================================================================

/**
 * Rate-limit the create-app-password endpoint: 5 attempts per IP per 5 min.
 *
 * @return WP_REST_Response|null 429 response to return, or null to proceed.
 */
function dtb_app_password_rate_limit(): ?WP_REST_Response {
	if ( empty( $_SERVER['REMOTE_ADDR'] ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'bad_request', 'Unable to identify request origin.', 400 ),
			400
		);
	}
	$ip    = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
	$key   = 'dtb_app_pw_rl_' . md5( $ip );
	$count = (int) get_transient( $key );
	if ( $count >= 5 ) {
		$resp = new WP_REST_Response(
			dtb_error_envelope( 'rate_limited', 'Too many requests. Please try again later.', 429 ),
			429
		);
		$resp->header( 'Retry-After', '300' );
		return $resp;
	}
	set_transient( $key, $count + 1, 300 );
	return null;
}

// =============================================================================
// ROUTE CALLBACKS — drywall/v1 proxy
// =============================================================================

// Shared WooCommerce projection fields used by the canonical catalog detail API.
/** GET /drywall/v1/orders/{id}  (JWT-gated) */
function dtb_proxy_get_order( WP_REST_Request $request ): WP_REST_Response {
	return dtb_wc_get( 'wc/v3/orders/' . absint( $request->get_param( 'id' ) ) );
}

/**
 * GET /drywall/v1/orders  (JWT-gated — customer's own order list)
 *
 * Supported query params: page, per_page, customer (WC customer ID).
 * The SPA must always pass the authenticated user's customer ID so WC only
 * returns orders belonging to that customer (no cross-customer data leakage).
 */
function dtb_proxy_get_orders( WP_REST_Request $request ): WP_REST_Response {
	$allowed = [ 'page', 'per_page', 'customer', 'status', 'orderby', 'order' ];
	$params  = [];
	foreach ( $allowed as $k ) {
		$v = $request->get_param( $k );
		if ( null !== $v ) {
			$params[ $k ] = sanitize_text_field( $v );
		}
	}

	// Safety guard: require a customer filter — never return an unfiltered order list.
	if ( empty( $params['customer'] ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'missing_param', 'customer parameter is required.', 400 ),
			400
		);
	}

	return dtb_wc_get( 'wc/v3/orders', $params );
}

/** GET /drywall/v1/coupons/{code} */
function dtb_proxy_coupon( WP_REST_Request $request ): WP_REST_Response {
	return dtb_wc_get( 'wc/v3/coupons', [ 'code' => sanitize_text_field( $request->get_param( 'code' ) ) ] );
}

/** POST /drywall/v1/customers  (rate-limited) */
function dtb_proxy_create_customer( WP_REST_Request $request ): WP_REST_Response {
	$rl = dtb_proxy_mutation_rate_limit( $request, 'customers_post' );
	if ( $rl ) {
		return $rl;
	}
	$body = $request->get_json_params();
	if ( empty( $body ) ) {
		return new WP_REST_Response( dtb_error_envelope( 'invalid_body', 'Request body must be valid JSON.', 400 ), 400 );
	}
	return dtb_wc_post( 'wc/v3/customers', $body );
}

/** GET /drywall/v1/customers/{id}  (JWT-gated) */
function dtb_proxy_get_customer( WP_REST_Request $request ): WP_REST_Response {
	return dtb_wc_get( 'wc/v3/customers/' . absint( $request->get_param( 'id' ) ) );
}

/** POST /drywall/v1/webhooks/products — cache-invalidation receiver */
function dtb_proxy_webhook_products( WP_REST_Request $request ): WP_REST_Response {
	$config = dtb_get_config();
	$secret = $config['webhook_secret'];

	if ( '' === $secret ) {
		return new WP_REST_Response( dtb_error_envelope( 'config_error', 'Webhook secret not configured.', 500 ), 500 );
	}

	$raw_body = $request->get_body();
	$sig      = $request->get_header( 'x_wc_webhook_signature' );

	if ( ! $sig ) {
		return new WP_REST_Response( dtb_error_envelope( 'missing_signature', 'Webhook signature is required.', 401 ), 401 );
	}

	$expected = base64_encode( hash_hmac( 'sha256', $raw_body, $secret, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	if ( ! hash_equals( $expected, $sig ) ) {
		return new WP_REST_Response( dtb_error_envelope( 'invalid_signature', 'Webhook signature mismatch.', 401 ), 401 );
	}

	// Invalidate all drywall cache transients and log the event.
	$payload    = json_decode( $raw_body, true );
	$product_id = isset( $payload['id'] ) ? absint( $payload['id'] ) : 0;

	dtb_invalidate_product_cache();
	dtb_log_cache_event( 'webhook_received', [
		'product_id' => $product_id,
		'topic'      => $request->get_header( 'x_wc_webhook_topic' ) ?? 'product.unknown',
	] );

	return new WP_REST_Response( [ 'success' => true ], 200 );
}

// =============================================================================
// ROUTE CALLBACKS — dtb/v1 management
// =============================================================================

/** GET /dtb/v1/config */
function dtb_route_config(): WP_REST_Response {
	$credentials = dtb_get_wc_credentials();

	$response = rest_ensure_response( [
		'wc_auth_user' => $credentials['auth_user'],
		'wc_auth_pass' => $credentials['auth_pass'],
	] );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

/** GET /dtb/v1/catalog */
function dtb_route_catalog(): WP_REST_Response {
	$config = dtb_get_config();
	return rest_ensure_response( [
		'csv_url'       => rest_url( 'dtb/v1/products-csv' ),
		'filename'      => $config['csv_filename'],
		'filenames'     => $config['csv_filenames'],
		'source'        => $config['csv_source'] ?? '',
		'missing'       => $config['csv_missing'] ?? [],
	] );
}

/**
 * GET /dtb/v1/products-csv
 *
 * Streams one or more WooCommerce product CSVs to the browser as a single
 * merged CSV.  When multiple files are configured the header row is taken
 * from the first file only; subsequent files have their header rows stripped
 * so the browser receives a single well-formed CSV document.
 */
function dtb_route_products_csv(): void {
	$config      = dtb_get_config();
	$upload_dir  = wp_upload_dir();
	$uploads_dir = trailingslashit( $upload_dir['basedir'] );
	$filenames   = $config['csv_filenames'];

	// Validate every file before we start streaming so we never send a partial response.
	$file_paths = [];
	foreach ( $filenames as $filename ) {
		$file_path = $uploads_dir . 'wc-imports/' . $filename;
		$real_path    = realpath( $file_path );
		$real_uploads = realpath( $uploads_dir );

		if (
			false === $real_path ||
			false === $real_uploads ||
			0 !== strpos( $real_path, trailingslashit( $real_uploads ) ) ||
			! file_exists( $real_path )
		) {
			wp_send_json_error( dtb_error_envelope( 'csv_not_found', 'Product CSV file not found: ' . $filename, 404 ), 404 );
		}
		$file_paths[] = $real_path;
	}

	$raw_origin = isset( $_SERVER['HTTP_ORIGIN'] )
		? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	// Use the first filename for the Content-Disposition header.
	$display_name = count( $filenames ) === 1 ? $filenames[0] : 'wp-catalog-merged.csv';

	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: inline; filename="' . $display_name . '"' );
	header( 'Cache-Control: public, max-age=3600' );

	if ( $raw_origin && in_array( rtrim( $raw_origin, '/' ), dtb_allowed_origins(), true ) ) {
		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( $raw_origin ) );
		header( 'Vary: Origin' );
	}

	$first = true;
	foreach ( $file_paths as $path ) {
		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			continue;
		}
		if ( $first ) {
			// Output the first file in full (header row included).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru
			fpassthru( $handle );
			$first = false;
		} else {
			// Skip the header row of subsequent files so the merged CSV
			// has exactly one header row at the top.
			fgets( $handle ); // discard header line
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fpassthru
			fpassthru( $handle );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
	exit;
}

/** POST /dtb/v1/import-catalog — trigger WC CSV import for all configured files */
function dtb_route_import_catalog( WP_REST_Request $request ) {
	$config   = dtb_get_config();
	$provided = (string) ( $request->get_param( 'secret' ) ?? '' );
	$expected = $config['import_secret'] ?: (string) get_option( 'dtb_import_secret', '' );

	if ( empty( $expected ) || ! hash_equals( $expected, $provided ) ) {
		return new WP_Error( 'forbidden', 'Invalid or missing import secret.', [ 'status' => 403 ] );
	}

	$upload_dir  = wp_upload_dir();
	$uploads_dir = trailingslashit( $upload_dir['basedir'] );
	$filenames   = $config['csv_filenames'];

	// Validate all files exist before scheduling anything.
	$file_paths = [];
	foreach ( $filenames as $filename ) {
		$file_path = $uploads_dir . 'wc-imports/' . $filename;
		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'csv_not_found',
				'Product CSV not found: ' . $filename . '. Ensure the deploy step has uploaded it to wc-imports/.',
				[ 'status' => 404 ]
			);
		}
		$file_paths[] = $file_path;
	}

	// Use Action Scheduler when available — schedule one job per file.
	if ( function_exists( 'as_unschedule_all_actions' ) && function_exists( 'as_schedule_single_action' ) ) {
		as_unschedule_all_actions( 'dtb_run_catalog_import', [], 'dtb-catalog-sync' );
		$action_ids = [];
		foreach ( $file_paths as $file_path ) {
			$action_ids[] = as_schedule_single_action( time(), 'dtb_run_catalog_import', [ $file_path ], 'dtb-catalog-sync' );
		}
		return rest_ensure_response( [
			'status'     => 'scheduled',
			'action_ids' => $action_ids,
			'files'      => array_map( 'basename', $file_paths ),
			'message'    => count( $file_paths ) . ' WooCommerce product import(s) scheduled as background jobs.',
		] );
	}

	// No Action Scheduler — schedule background WP-Cron events instead of
	// running the heavy import synchronously. This keeps the REST route
	// fast and avoids proxy timeouts (Cloudflare 524). Note: WP-Cron must
	// be enabled (either via real traffic or a system cron calling
	// wp-cron.php) for the jobs to run.
	$scheduled = [];
	foreach ( $file_paths as $file_path ) {
		// Avoid duplicate scheduling for the same file.
		if ( ! wp_next_scheduled( 'dtb_run_catalog_import_wpcron', [ $file_path ] ) ) {
			wp_schedule_single_event( time() + 5, 'dtb_run_catalog_import_wpcron', [ $file_path ] );
			$scheduled[] = basename( $file_path );
		}
	}

	return rest_ensure_response( [
		'status'  => 'scheduled',
		'files'   => $scheduled,
		'message' => count( $scheduled ) . ' WooCommerce product import(s) scheduled via WP-Cron. Ensure WP-Cron is running (system cron or background runner).',
	] );
}

/** POST /dtb/v1/create-app-password */
function dtb_route_create_app_password( WP_REST_Request $request ): WP_REST_Response {
	$rl = dtb_app_password_rate_limit();
	if ( $rl ) {
		return $rl;
	}

	try {
		$username = sanitize_user( $request->get_param( 'username' ) );
		$password = $request->get_param( 'password' );
		$app_name = sanitize_text_field( $request->get_param( 'app_name' ) ) ?: 'Drywall Toolbox';

		if ( empty( $username ) || empty( $password ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'missing_credentials', 'Username and password are required.', 400 ),
				400
			);
		}

		$user = wp_authenticate( $username, $password );

		if ( ! $user || is_wp_error( $user ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'invalid_credentials', 'Invalid username or password.', 401 ),
				401
			);
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			require_once ABSPATH . 'wp-includes/class-wp-application-passwords.php';
		}

		$result = WP_Application_Passwords::create_new_application_password(
			$user->ID,
			[ 'name' => $app_name ]
		);

		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'create_failed', $result->get_error_message(), 500 ),
				500
			);
		}

		$password_string = $result[0] ?? '';

		if ( empty( $password_string ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'empty_password', 'Application password was created but the string is empty.', 500 ),
				500
			);
		}

		return new WP_REST_Response( [
			'success'  => true,
			'message'  => 'Application password created successfully.',
			'username' => $username,
			'password' => $password_string,
			'app_name' => $app_name,
			'note'     => 'Use these credentials for WooCommerce REST API access. Password will not be shown again.',
		], 200 );

	} catch ( Exception $e ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'server_error', $e->getMessage(), 500 ),
			500
		);
	}
}

/** POST /dtb/v1/webhooks/ensure */
function dtb_route_ensure_webhooks( WP_REST_Request $request ): WP_REST_Response {
	if ( ! function_exists( 'dtb_wc_ensure_webhooks' ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'not_available', 'Webhook creation is not available on this site.', 500 ),
			500
		);
	}

	$result = dtb_wc_ensure_webhooks();

	if ( ! is_array( $result ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'unexpected_result', 'Webhook creation returned an unexpected result.', 500 ),
			500
		);
	}

	if ( isset( $result['status'] ) && 'completed' !== $result['status'] ) {
		return new WP_REST_Response( [
			'success'  => false,
			'message'  => 'Webhook creation skipped or failed.',
			'result'   => $result,
		], 200 );
	}

	return new WP_REST_Response( [
		'success'  => true,
		'message'  => 'Webhook creation completed.',
		'result'   => $result,
	], 200 );
}

/** Permission callback for POST /dtb/v1/webhooks/ensure */
function dtb_route_ensure_webhooks_permission( WP_REST_Request $request ): bool {
	if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ) ) {
		return true;
	}

	$secret = $request->get_param( 'secret' );
	if ( ! empty( $secret ) && defined( 'WC_WEBHOOK_SECRET' ) ) {
		return hash_equals( (string) WC_WEBHOOK_SECRET, trim( (string) $secret ) );
	}

	$header_secret = $request->get_header( 'x-wc-webhook-secret' );
	if ( ! empty( $header_secret ) && defined( 'WC_WEBHOOK_SECRET' ) ) {
		return hash_equals( (string) WC_WEBHOOK_SECRET, trim( (string) $header_secret ) );
	}

	return false;
}

/**
 * POST /dtb/v1/admin/webhooks/sync-secrets
 *
 * Force-pushes the current WC_WEBHOOK_SECRET and DTB_VEEQO_WEBHOOK_SECRET to
 * all registered webhook rows/registrations, bypassing the fingerprint gate.
 * Use this after deploying a rotated wp-config.php to complete the rotation
 * without waiting for the next woocommerce_init / init boot cycle.
 *
 * Requires manage_woocommerce or manage_options capability.
 */
function dtb_route_admin_sync_webhook_secrets( WP_REST_Request $request ): WP_REST_Response {
	$report = [];

	// --- WooCommerce webhook rows ---
	if ( function_exists( 'dtb_wc_sync_webhook_secrets' ) ) {
		// Delete the stored fingerprint so the function sees a "rotation" unconditionally.
		delete_option( 'dtb_wc_webhook_secret_hash' );
		$wc_result          = dtb_wc_sync_webhook_secrets();
		$report['woocommerce'] = $wc_result;
	} else {
		$report['woocommerce'] = [ 'status' => 'skipped', 'reason' => 'function_not_available' ];
	}

	// --- Veeqo webhook registration ---
	if ( function_exists( 'dtb_veeqo_ensure_webhooks' ) ) {
		// Clear both the transient and the fingerprint so ensure_webhooks re-registers.
		delete_transient( 'dtb_veeqo_webhook_registered' );
		delete_option( 'dtb_veeqo_webhook_secret_hash' );
		dtb_veeqo_ensure_webhooks();
		$report['veeqo'] = [ 'status' => 'triggered' ];
	} else {
		$report['veeqo'] = [ 'status' => 'skipped', 'reason' => 'function_not_available' ];
	}

	$all_ok = ( 'synced' === ( $report['woocommerce']['status'] ?? '' ) || 'in_sync' === ( $report['woocommerce']['status'] ?? '' ) )
		&& 'triggered' === ( $report['veeqo']['status'] ?? '' );

	return new WP_REST_Response( [
		'success' => $all_ok,
		'report'  => $report,
	], 200 );
}

/** GET /wc-admin/profile — shim to suppress core-profiler crash */
function dtb_route_wc_admin_profile(): WP_REST_Response {
	return rest_ensure_response( [
		'title'               => 'Drywall Toolbox',
		'industries'          => [ [ 'slug' => 'retail' ] ],
		'products'            => [],
		'business_extensions' => [],
		'completed'           => true,
		'skipped'             => true,
	] );
}

// =============================================================================
// CATALOG IMPORT — Action Scheduler callback + sync runner
// (shared between the REST endpoint and background processing)
// =============================================================================

add_action( 'dtb_run_catalog_import', function ( string $file_path ): void {
	dtb_run_catalog_import_sync( $file_path );
} );

// Fallback WP-Cron action: runs when wp_schedule_single_event fires.
add_action( 'dtb_run_catalog_import_wpcron', function ( string $file_path ): void {
    dtb_run_catalog_import_sync( $file_path );
} );

/**
 * Run a WooCommerce CSV product import synchronously.
 *
 * @param string $file_path Absolute server path to the CSV.
 * @return WP_REST_Response|WP_Error
 */
function dtb_run_catalog_import_sync( string $file_path ) {
	// Raise execution-time and memory limits for large CSV imports.
	// This function is invoked either by Action Scheduler (dtb_run_catalog_import)
	// or directly from dtb_route_import_catalog when Action Scheduler is unavailable.
	// The admin_init hook in dtb-woocommerce.php only covers WC admin AJAX actions
	// and does NOT cover this code path.
	if ( function_exists( 'set_time_limit' ) ) {
		set_time_limit( 300 );
	}
	if ( function_exists( 'ini_set' ) ) {
		ini_set( 'memory_limit', '512M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Blacklisted
	}

	if ( ! file_exists( $file_path ) ) {
		return new WP_Error( 'csv_not_found', 'CSV file not found: ' . basename( $file_path ), [ 'status' => 404 ] );
	}

	if ( ! defined( 'WC_ABSPATH' ) ) {
		return new WP_Error( 'wc_not_loaded', 'WooCommerce is not loaded.', [ 'status' => 500 ] );
	}

	if ( ! class_exists( 'WC_Product_CSV_Importer' ) ) {
		$importer_file = WC_ABSPATH . 'includes/import/class-wc-product-csv-importer.php';
		if ( ! file_exists( $importer_file ) ) {
			return new WP_Error( 'importer_not_found', 'WooCommerce CSV importer class not available.', [ 'status' => 500 ] );
		}
		require_once $importer_file;
	}

	$importer = new WC_Product_CSV_Importer( $file_path, [
		'update_existing'    => true,
		'character_encoding' => '',
		'lines'              => -1,
		'mapping'            => [],
		'parse'              => true,
	] );
	$results = $importer->import();

	do_action( 'woocommerce_product_import_end' );
	wc_delete_product_transients();

	return rest_ensure_response( [
		'status'   => 'completed',
		'file'     => basename( $file_path ),
		'imported' => (int) ( $results['imported'] ?? 0 ),
		'updated'  => (int) ( $results['updated']  ?? 0 ),
		'skipped'  => (int) ( $results['skipped']  ?? 0 ),
		'failed'   => (int) ( $results['failed']   ?? 0 ),
	] );
}

// =============================================================================
// CONTACT FORM
// =============================================================================

/**
 * POST /dtb/v1/contact
 *
 * Accepts a public contact form submission and delivers it to the support
 * inbox via wp_mail().  Rate-limited to 5 submissions per 60 s per IP to
 * prevent abuse.
 *
 * Expected JSON body:
 *   { name, email, inquiry_type, message }
 *
 * Success: HTTP 200  { success: true, message: '...' }
 * Errors:
 *   422 — validation failure (invalid email, empty message)
 *   429 — rate limit exceeded
 *
 * @param WP_REST_Request $request Incoming request.
 * @return WP_REST_Response
 */
function dtb_contact_form_handler( WP_REST_Request $request ): WP_REST_Response {
	// ── Rate limit: 5 submissions per 60 s per IP ────────────────────────────
	$ip  = dtb_get_client_ip();
	$key = 'dtb_contact_' . md5( $ip );
	$count = (int) get_transient( $key );
	if ( $count >= 5 ) {
		$resp = new WP_REST_Response(
			dtb_error_envelope( 'rate_limited', 'Too many submissions. Please wait a moment and try again.', 429 ),
			429
		);
		$resp->header( 'Retry-After', '60' );
		return $resp;
	}
	set_transient( $key, $count + 1, 60 );

	// ── Input retrieval ───────────────────────────────────────────────────────
	$name         = sanitize_text_field( (string) $request->get_param( 'name' ) );
	$email        = sanitize_email( (string) $request->get_param( 'email' ) );
	$inquiry_type = sanitize_text_field( (string) ( $request->get_param( 'inquiry_type' ) ?: 'General Question' ) );
	$message      = sanitize_textarea_field( (string) $request->get_param( 'message' ) );

	// ── Validation ────────────────────────────────────────────────────────────
	if ( empty( $name ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'missing_name', 'Please provide your name.', 422 ),
			422
		);
	}

	if ( ! is_email( $email ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'invalid_email', 'Please provide a valid email address.', 422 ),
			422
		);
	}

	if ( empty( $message ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'missing_message', 'Please provide a message.', 422 ),
			422
		);
	}

	// ── Build and send email ──────────────────────────────────────────────────
	$site_name = get_bloginfo( 'name' ) ?: 'Drywall Toolbox';
	$to        = 'info@drywalltoolbox.com';
	$subject   = sprintf( '[%s Contact] %s — %s', $site_name, $inquiry_type, $name );

	$body  = "New contact form submission from {$name} <{$email}>.\n\n";
	$body .= "Inquiry type: {$inquiry_type}\n";
	$body .= "---------------------------------------\n\n";
	$body .= $message . "\n\n";
	$body .= "---------------------------------------\n";
	$body .= "Submitted: " . gmdate( 'Y-m-d H:i:s T' ) . "\n";
	$body .= "IP: " . dtb_anonymise_ip( $ip ) . "\n";

	// Strip CR/LF from user-supplied name before using it in a header value to
	// prevent email header injection attacks.
	$safe_name = str_replace( [ "\r", "\n" ], ' ', $name );

	$headers = [
		'Content-Type: text/plain; charset=UTF-8',
		'Reply-To: ' . $safe_name . ' <' . $email . '>',
	];

	if ( function_exists( 'dtb_send_email' ) ) {
		$sent = dtb_send_email(
			[
				'to'           => $to,
				'subject'      => $subject,
				'message'      => $body,
				'headers'      => $headers,
				'content_type' => 'text/plain',
				'context'      => [
					'module' => 'dtb-platform',
					'route'  => 'contact-form',
				],
			]
		);
	} else {
		$sent = wp_mail( $to, $subject, $body, $headers );
	}

	if ( ! $sent ) {
		error_log( '[DTB Contact] wp_mail() failed for submission from ' . dtb_anonymise_ip( $ip ) );
		return new WP_REST_Response(
			dtb_error_envelope( 'mail_failed', 'Unable to send your message. Please email us directly at info@drywalltoolbox.com.', 500 ),
			500
		);
	}

	$response = new WP_REST_Response( [
		'success' => true,
		'message' => 'Your message has been sent. We\'ll get back to you within one business day.',
	], 200 );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

function dtb_admin_cache_flush_permission( WP_REST_Request $request ) {
	// Check WordPress logged-in administrator first.
	if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
		// Validate nonce if provided (admin-ajax style callers will supply it).
		$nonce = $request->get_header( 'x_dtb_nonce' ) ?: $request->get_param( '_nonce' );
		if ( $nonce && ! wp_verify_nonce( $nonce, 'dtb_admin_cache_flush' ) ) {
			return new WP_Error( 'invalid_nonce', 'Nonce verification failed.', [ 'status' => 403 ] );
		}
		return true;
	}

	// Fall back to JWT administrator check.
	$token = null;
	if ( ! empty( $_COOKIE[ DTB_AUTH_COOKIE ] ) ) {
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ DTB_AUTH_COOKIE ] ) );
	}
	if ( ! $token ) {
		$auth = $request->get_header( 'authorization' );
		if ( $auth && preg_match( '/^Bearer\s+(\S+)$/i', $auth, $m ) ) {
			$token = $m[1];
		}
	}
	if ( ! $token ) {
		return new WP_Error( 'missing_token', 'Authentication required.', [ 'status' => 401 ] );
	}

	$payload = dtb_verify_jwt( $token );
	if ( is_wp_error( $payload ) ) {
		return $payload;
	}

	$roles = isset( $payload->roles ) ? (array) $payload->roles : [];
	if ( ! in_array( 'administrator', $roles, true ) ) {
		return new WP_Error( 'forbidden', 'Administrator access required.', [ 'status' => 403 ] );
	}

	return true;
}

/**
 * POST /dtb/v1/admin/cache/products/flush
 *
 * Clears all drywall_cache_* transients, logs the flush event, and returns
 * the number of transients deleted.  Requires administrator authentication.
 */
function dtb_route_admin_cache_flush( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;

	// Count before deletion for the audit log.
	$count = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( '_transient_drywall_cache_' ) . '%'
		)
	);

	dtb_invalidate_product_cache();

	dtb_log_cache_event( 'admin_cache_flush', [
		'user_id'          => get_current_user_id(),
		'transients_cleared' => $count,
	] );

	$response = new WP_REST_Response( [
		'success'            => true,
		'transients_cleared' => $count,
		'message'            => sprintf( 'Product cache flushed. %d transient(s) cleared.', $count ),
	], 200 );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}
