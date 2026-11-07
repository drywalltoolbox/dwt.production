<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB API Security
 *
 * Owns REST/CORS policy that used to be split between themes and dtb-rest-api.
 * Keep this layer admin-aware and feature-flagged so hardening can be tightened
 * gradually without turning Woo Admin into a 403 confetti cannon.
 *
 * @package drywall-toolbox
 */


add_action( 'rest_api_init', 'dtb_cors_init', 15 );
add_action( 'init', 'dtb_emit_cors_headers_early', 0 );
add_action( 'send_headers', 'dtb_send_rest_cors_headers', 1 );
add_action( 'woocommerce_init', 'dtb_wc_cors_early', 1 );
add_action( 'init', 'dtb_api_security_handle_options_preflight', 1 );
add_filter( 'rest_endpoints', 'dtb_api_security_restrict_user_endpoints', 20 );
add_filter( 'woocommerce_rest_check_permissions', 'dtb_api_security_wc_public_read', 10, 4 );
add_filter( 'rest_authentication_errors', 'dtb_api_security_relax_admin_background_nonce_failures', 110 );
add_action( 'rest_api_init', 'dtb_api_security_register_nonce_route', 20 );

add_action(
	'rest_api_init',
	static function (): void {
		remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );
	},
	-999
);

function dtb_wc_cors_early(): void {
	if ( dtb_feature_enabled( 'DTB_ENABLE_REST_CORS', true ) ) {
		dtb_emit_cors_headers();
	}
}

function dtb_cors_init(): void {
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_REST_CORS', true ) ) {
		return;
	}

	remove_filter( 'rest_pre_serve_request', 'rest_send_cors_headers' );

	add_filter(
		'rest_pre_serve_request',
		static function ( $served, $result, $request, $server ) {
			dtb_emit_cors_headers();
			return $served;
		},
		10,
		4
	);
}

function dtb_emit_cors_headers_early(): void {
	if ( dtb_feature_enabled( 'DTB_ENABLE_REST_CORS', true ) && dtb_is_rest_request() ) {
		dtb_emit_cors_headers();
	}
}

function dtb_send_rest_cors_headers(): void {
	if ( dtb_feature_enabled( 'DTB_ENABLE_REST_CORS', true ) && dtb_is_rest_request() ) {
		dtb_emit_cors_headers();
	}
}

function dtb_api_security_handle_options_preflight(): void {
	if (
		! dtb_feature_enabled( 'DTB_ENABLE_REST_CORS', true )
		|| 'OPTIONS' !== ( $_SERVER['REQUEST_METHOD'] ?? '' )
		|| ! dtb_is_rest_request()
	) {
		return;
	}

	if ( dtb_check_origin() ) {
		dtb_emit_cors_headers();
		status_header( 204 );
		exit;
	}

	dtb_security_log( 'cors_preflight_denied' );
	status_header( 403 );
	exit;
}

function dtb_is_rest_request(): bool {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( '' === $request_uri ) {
		return false;
	}

	$rest_path   = wp_parse_url( rest_url(), PHP_URL_PATH );

	return ( $rest_path && str_starts_with( $request_uri, $rest_path ) )
		|| str_contains( $request_uri, '/wp-json/' );
}

function dtb_emit_cors_headers( ?string $raw_origin = null ): void {
	if ( headers_sent() ) {
		return;
	}

	$raw_origin ??= isset( $_SERVER['HTTP_ORIGIN'] )
		? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( class_exists( 'DTB_CorsPolicy' ) ) {
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );
		header_remove( 'Access-Control-Allow-Methods' );
		header_remove( 'Access-Control-Allow-Headers' );
		DTB_CorsPolicy::emit( $raw_origin );
		return;
	}

	header_remove( 'Access-Control-Allow-Origin' );
	header_remove( 'Access-Control-Allow-Credentials' );
	header_remove( 'Access-Control-Allow-Methods' );
	header_remove( 'Access-Control-Allow-Headers' );

	if ( '' !== $raw_origin && dtb_check_origin() ) {
		header( 'Access-Control-Allow-Origin: ' . esc_url_raw( rtrim( $raw_origin, '/' ) ) );
		header( 'Access-Control-Allow-Credentials: true' );
		header( 'Vary: Origin', false );
	}

	header( 'Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS' );
	header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-WP-Nonce, X-Requested-With, X-WC-Store-API-Nonce, Cart-Token' );
	header( 'Access-Control-Expose-Headers: X-WC-Store-API-Nonce, Nonce, Cart-Token' );
	header( 'Access-Control-Max-Age: 86400' );

	$ops_version = defined( 'DTB_OPS_VERSION' ) ? DTB_OPS_VERSION : '1.0.0';
	header( 'X-DTB-Version: ' . $ops_version );
}

function dtb_api_security_restrict_user_endpoints( array $endpoints ): array {
	if ( ! dtb_feature_enabled( 'DTB_RESTRICT_USER_ENDPOINTS', true ) ) {
		return $endpoints;
	}

	$restricted_routes = [
		'/wp/v2/users',
		'/wp/v2/users/(?P<id>[\d]+)',
		'/wp/v2/users/me',
	];

	foreach ( $restricted_routes as $route ) {
		if ( ! isset( $endpoints[ $route ] ) || ! is_array( $endpoints[ $route ] ) ) {
			continue;
		}

		foreach ( $endpoints[ $route ] as &$handler ) {
			if ( ! is_array( $handler ) ) {
				continue;
			}

			$handler['permission_callback'] = static function () use ( $route ): bool {
				// Preserve hardened user-list protections, but do not break
				// authenticated self-profile reads used by wp-admin / Woo Admin.
				if ( '/wp/v2/users/me' === $route ) {
					// Some hardened/edge role setups can lack the nominal "read"
					// capability while still representing a valid authenticated
					// wp-admin session. For /users/me we only require auth.
					$allowed = is_user_logged_in() && get_current_user_id() > 0;

					if ( ! $allowed ) {
						dtb_security_log(
							'wp_users_endpoint_denied',
							[
								'route'        => $route,
								'required_cap' => 'authenticated_user',
							]
						);
					}

					return $allowed;
				}

				$allowed = current_user_can( 'list_users' );

				if ( ! $allowed ) {
					dtb_security_log(
						'wp_users_endpoint_denied',
						[
							'route'        => $route,
							'required_cap' => 'list_users',
						]
					);
				}

				return $allowed;
			};
		}
		unset( $handler );
	}

	return $endpoints;
}

/**
 * Relax cookie-nonce failures for low-risk wp-admin background GET requests.
 *
 * Some shared-hosting/proxy paths intermittently invalidate wp_rest nonces,
 * which causes retry loops in wp-admin for benign read-only endpoints.
 * Keep this narrowly scoped to authenticated same-host admin requests.
 *
 * @param WP_Error|mixed $result Authentication result from prior callbacks.
 * @return WP_Error|mixed|null
 */
function dtb_api_security_relax_admin_background_nonce_failures( $result ) {
	if ( ! is_wp_error( $result ) || 'rest_cookie_invalid_nonce' !== $result->get_error_code() ) {
		return $result;
	}

	$method = isset( $_SERVER['REQUEST_METHOD'] )
		? strtoupper( sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( 'GET' !== $method ) {
		return $result;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	$allowed_uri_fragments = [
		'/wp-json/wp/v2/users/me',
		'/wp-json/dtb/v1/admin/', // DTB admin live-region background polling.
	];

	$route_allowed = false;
	foreach ( $allowed_uri_fragments as $fragment ) {
		if ( false !== strpos( $request_uri, $fragment ) ) {
			$route_allowed = true;
			break;
		}
	}

	if ( ! $route_allowed || ! is_user_logged_in() || get_current_user_id() <= 0 ) {
		return $result;
	}

	// Accept same-site requests via either the HTTP Origin header (reliable for
	// same-origin fetch() calls) or the HTTP Referer pointing to /wp-admin/.
	// Some hosting reverse-proxies strip Referer; accepting Origin handles that case.
	//
	// Additional case: if NEITHER Origin nor Referer is present the request must
	// be same-origin.  Modern browsers always include Origin for cross-origin
	// fetch() calls; absence of Origin is conclusive evidence that the request
	// originated from the same host. Some hosting reverse proxies can strip
	// Referer, leaving both headers absent for legitimate wp-admin background
	// polls.  We still reject requests that carry a mismatched Origin (a
	// cross-origin request from the wrong host) to preserve the CSRF barrier.
	$raw_origin = isset( $_SERVER['HTTP_ORIGIN'] )
		? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$referrer   = wp_get_raw_referer();
	$site_host  = wp_parse_url( home_url(), PHP_URL_HOST );

	$origin_host = $raw_origin ? wp_parse_url( $raw_origin, PHP_URL_HOST ) : '';
	$ref_host    = $referrer ? wp_parse_url( $referrer, PHP_URL_HOST ) : '';
	$ref_path    = $referrer ? (string) wp_parse_url( $referrer, PHP_URL_PATH ) : '';

	$same_site_origin    = $site_host && $origin_host && strtolower( $site_host ) === strtolower( $origin_host );
	$cross_site_origin   = $origin_host && $site_host && strtolower( $site_host ) !== strtolower( $origin_host );
	$admin_referer       = $site_host && $ref_host
		&& strtolower( $site_host ) === strtolower( $ref_host )
		&& false !== strpos( $ref_path, '/wp-admin/' );
	// No Origin + no Referer = same-origin request with headers stripped by proxy.
	$no_external_headers = '' === $raw_origin && ! $referrer;

	// Reject if a mismatched Origin header is explicitly present (cross-site CSRF risk).
	if ( $cross_site_origin ) {
		return $result;
	}

	if ( ! $same_site_origin && ! $admin_referer && ! $no_external_headers ) {
		return $result;
	}

	dtb_security_log(
		'rest_cookie_invalid_nonce_relaxed',
		[
			'uri' => $request_uri,
		]
	);

	return null;
}

function dtb_api_security_wc_public_read( $permission, $context, $object_id, $post_type ) {
	if (
		dtb_feature_enabled( 'DTB_WC_PUBLIC_READ', true )
		&& 'read' === $context
		&& in_array( $post_type, [ 'product', 'product_cat', 'product_tag', 'product_attribute', 'product_variation' ], true )
	) {
		return true;
	}

	return $permission;
}

function dtb_api_security_register_nonce_route(): void {
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_NONCE_REFRESH', true ) ) {
		return;
	}

	register_rest_route(
		'dtb/v1',
		'/nonce',
		[
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => '__return_true',
			'callback'            => static function (): WP_REST_Response {
				return rest_ensure_response(
					[
						'nonce' => wp_create_nonce( 'wp_rest' ),
					]
				);
			},
		]
	);
}

function dtb_route_cors_test(): WP_REST_Response {
	return rest_ensure_response(
		[
			'ok'              => true,
			'allowed_origins' => dtb_allowed_origins(),
			'origin_allowed'  => dtb_check_origin(),
		]
	);
}

// ── Admin-ajax nonce refresh ─────────────────────────────────────────────────

/**
 * Admin-ajax action: return a fresh wp_rest nonce for authenticated admin users.
 *
 * REST nonce refresh cannot be done via the REST API itself because
 * rest_cookie_check_errors() calls wp_set_current_user(0) for nonce-less
 * requests, causing wp_create_nonce() to generate a user-0 token that cannot
 * authenticate the real admin user.  admin-ajax.php preserves the auth cookie
 * user across the request without requiring a REST nonce, making it the
 * correct transport for this operation.
 *
 * Called by DtbAdmin._refreshNonce() in dtb-admin.js when a background REST
 * request receives a 403 (stale nonce or lost session).
 */
add_action( 'wp_ajax_dtb_refresh_nonce', 'dtb_api_security_ajax_refresh_nonce' );

function dtb_api_security_ajax_refresh_nonce(): void {
	if ( ! is_user_logged_in() || get_current_user_id() <= 0 ) {
		wp_send_json_error( [ 'code' => 'not_authenticated' ], 401 );
	}

	wp_send_json_success( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
}
