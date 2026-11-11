<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Admin Security
 *
 * Admin/Woo Admin compatibility diagnostics for REST hardening. This file
 * intentionally observes first and avoids broad permission overrides.
 *
 * @package drywall-toolbox
 */


add_action( 'rest_api_init', 'dtb_admin_security_register_routes', 20 );
add_action( 'rest_api_init', 'dtb_admin_security_register_auth_diagnostic_route', 25 );
add_filter( 'rest_post_dispatch', 'dtb_admin_security_log_rest_denials', 10, 3 );
add_action( 'admin_notices', 'dtb_admin_security_render_cookie_path_notice' );
add_action( 'login_init', 'dtb_admin_security_trace_login_init', 1 );
add_action( 'wp_login_failed', 'dtb_admin_security_trace_login_failed', 10, 2 );
add_action( 'set_auth_cookie', 'dtb_admin_security_trace_auth_cookie', 10, 6 );
add_action( 'set_logged_in_cookie', 'dtb_admin_security_trace_logged_in_cookie', 10, 6 );
add_action( 'wp_login', 'dtb_admin_security_trace_login_success', 10, 2 );

function dtb_admin_security_register_routes(): void {
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_SMOKE_ROUTE', true ) ) {
		return;
	}

	register_rest_route(
		'dtb/v1',
		'/admin-smoke',
		[
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'dtb_admin_security_can_run_smoke',
			'callback'            => static function (): WP_REST_Response {
				return rest_ensure_response(
					[
						'user'   => [
							'id'                  => get_current_user_id(),
							'manage_options'      => current_user_can( 'manage_options' ),
							'manage_woocommerce'  => current_user_can( 'manage_woocommerce' ),
							'view_woocommerce_reports' => current_user_can( 'view_woocommerce_reports' ),
						],
						'routes' => dtb_admin_security_smoke_results(),
					]
				);
			},
		]
	);
}

function dtb_admin_security_can_run_smoke(): bool {
	return current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' );
}

function dtb_admin_security_trace_login_init(): void {
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_LOGIN_TRACE', false ) ) {
		return;
	}

	dtb_admin_security_emit_login_trace(
		'login_init',
		[
			'method'          => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '',
			'has_test_cookie' => ! empty( $_COOKIE[ TEST_COOKIE ] ) ? '1' : '0',
			'cookiepath'      => defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : '',
			'adminpath'       => defined( 'ADMIN_COOKIE_PATH' ) ? (string) ADMIN_COOKIE_PATH : '',
			'sitecookiepath'  => defined( 'SITECOOKIEPATH' ) ? (string) SITECOOKIEPATH : '',
			'cookie_domain'   => defined( 'COOKIE_DOMAIN' ) ? (string) COOKIE_DOMAIN : '',
		]
	);
}

function dtb_admin_security_trace_login_failed( string $username, $error = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_LOGIN_TRACE', false ) ) {
		return;
	}

	$error_code = $error instanceof WP_Error ? implode( ',', array_map( 'sanitize_key', $error->get_error_codes() ) ) : '';

	dtb_admin_security_emit_login_trace(
		'login_failed',
		[
			'user_hash'  => '' !== $username ? substr( hash( 'sha256', strtolower( trim( $username ) ) ), 0, 12 ) : '',
			'error_code' => $error_code,
		]
	);
}

function dtb_admin_security_trace_auth_cookie( string $auth_cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_LOGIN_TRACE', false ) ) {
		return;
	}

	dtb_admin_security_emit_login_trace(
		'set_auth_cookie',
		[
			'user_id' => (string) absint( $user_id ),
			'scheme'  => sanitize_key( $scheme ),
			'expire'  => $expire > time() ? 'future' : 'session',
		]
	);
}

function dtb_admin_security_trace_logged_in_cookie( string $logged_in_cookie, int $expire, int $expiration, int $user_id, string $scheme, string $token ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_LOGIN_TRACE', false ) ) {
		return;
	}

	dtb_admin_security_emit_login_trace(
		'set_logged_in_cookie',
		[
			'user_id' => (string) absint( $user_id ),
			'scheme'  => sanitize_key( $scheme ),
			'expire'  => $expire > time() ? 'future' : 'session',
		]
	);
}

function dtb_admin_security_trace_login_success( string $user_login, WP_User $user ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_LOGIN_TRACE', false ) ) {
		return;
	}

	dtb_admin_security_emit_login_trace(
		'login_success',
		[
			'user_id'            => (string) absint( $user->ID ),
			'roles'              => implode( ',', array_map( 'sanitize_key', (array) $user->roles ) ),
			'manage_options'     => user_can( $user, 'manage_options' ) ? '1' : '0',
			'manage_woocommerce' => user_can( $user, 'manage_woocommerce' ) ? '1' : '0',
		]
	);
}

function dtb_admin_security_emit_login_trace( string $event, array $context = [] ): void {
	$safe = [
		'event' => sanitize_key( $event ),
	];

	foreach ( $context as $key => $value ) {
		if ( ! is_scalar( $value ) && null !== $value ) {
			continue;
		}

		$safe[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
	}

	dtb_security_log( 'wp_login_trace', $safe );

	if ( headers_sent() ) {
		return;
	}

	$parts = [];
	foreach ( $safe as $key => $value ) {
		$parts[] = sanitize_key( (string) $key ) . '=' . rawurlencode( (string) $value );
	}

	header( 'X-DTB-WP-Login-Trace: ' . implode( '; ', $parts ), false );
}

function dtb_admin_security_register_auth_diagnostic_route(): void {
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_AUTH_DIAGNOSTICS', true ) ) {
		return;
	}

	register_rest_route(
		'dtb/v1',
		'/admin-auth-smoke',
		[
			'methods'             => WP_REST_Server::READABLE,
			'permission_callback' => 'dtb_admin_security_can_run_smoke',
			'callback'            => static function (): WP_REST_Response {
				return rest_ensure_response( dtb_admin_security_auth_diagnostics() );
			},
		]
	);
}

function dtb_admin_security_auth_diagnostics(): array {
	$current_user = wp_get_current_user();
	$user_id      = get_current_user_id();
	$nonce        = isset( $_SERVER['HTTP_X_WP_NONCE'] )
		? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_X_WP_NONCE'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	$sample_routes = [
		'/wc-admin/settings/payments/providers',
		'/wc-analytics/admin/notes',
		'/wp/v2/users/me',
		'/dtb/v1/checkout/session',
		'/drywall/v1/orders',
	];

	$route_policy = [];
	foreach ( $sample_routes as $route ) {
		$is_native_admin = function_exists( 'dtb_jwt_rest_route_is_native_admin_namespace' )
			? dtb_jwt_rest_route_is_native_admin_namespace( $route )
			: false;

		$route_policy[] = [
			'route'                       => $route,
			'native_admin_namespace'      => $is_native_admin,
			'dtb_jwt_current_user_allowed' => ! $is_native_admin,
		];
	}

	return [
		'current_user'      => [
			'id'                         => $user_id,
			'user_login'                 => $current_user instanceof WP_User ? (string) $current_user->user_login : '',
			'roles'                      => $current_user instanceof WP_User ? array_values( (array) $current_user->roles ) : [],
			'read'                       => current_user_can( 'read' ),
			'manage_options'             => current_user_can( 'manage_options' ),
			'manage_woocommerce'         => current_user_can( 'manage_woocommerce' ),
			'view_woocommerce_reports'   => current_user_can( 'view_woocommerce_reports' ),
			'edit_shop_orders'           => current_user_can( 'edit_shop_orders' ),
		],
		'request'           => [
			'method'                     => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'route'                      => function_exists( 'dtb_jwt_current_rest_route' ) ? dtb_jwt_current_rest_route() : '',
			'origin'                     => isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['HTTP_ORIGIN'] ) ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			'referer_is_wp_admin'        => false !== strpos( (string) wp_get_raw_referer(), '/wp-admin/' ),
			'has_x_wp_nonce'             => '' !== $nonce,
			'x_wp_nonce_validity'        => '' !== $nonce ? wp_verify_nonce( $nonce, 'wp_rest' ) : false,
		],
		'native_wp_auth'    => dtb_admin_security_native_auth_cookie_diagnostics(),
		'dtb_auth'          => dtb_admin_security_dtb_auth_cookie_diagnostics(),
		'cookie_paths'      => dtb_admin_security_cookie_path_diagnostics(),
		'route_policy'      => $route_policy,
		'expected_admin_rest_prerequisites' => [
			'valid_native_wp_auth_cookie' => dtb_admin_security_has_native_auth_cookie_user(),
			'valid_wp_rest_nonce'        => '' !== $nonce && false !== wp_verify_nonce( $nonce, 'wp_rest' ),
			'has_admin_capability'       => current_user_can( 'manage_options' ) || current_user_can( 'manage_woocommerce' ),
			'dtb_jwt_excluded'           => function_exists( 'dtb_jwt_rest_route_is_native_admin_namespace' )
				? dtb_jwt_rest_route_is_native_admin_namespace( '/wc-admin/settings/payments/providers' )
				: false,
		],
	];
}

function dtb_admin_security_native_auth_cookie_diagnostics(): array {
	$schemes = is_ssl() ? [ 'secure_auth', 'auth', 'logged_in' ] : [ 'auth', 'secure_auth', 'logged_in' ];
	$results = [];

	foreach ( array_unique( $schemes ) as $scheme ) {
		$user_id = (int) wp_validate_auth_cookie( '', $scheme );
		$results[ $scheme ] = [
			'valid_user_id'       => $user_id,
			'manage_options'      => $user_id > 0 && user_can( $user_id, 'manage_options' ),
			'manage_woocommerce'  => $user_id > 0 && user_can( $user_id, 'manage_woocommerce' ),
		];
	}

	$cookie_names = array_keys( $_COOKIE );
	return [
		'has_wordpress_cookie_name' => (bool) array_filter(
			$cookie_names,
			static function ( string $name ): bool {
				return 0 === strpos( $name, 'wordpress_' ) || 0 === strpos( $name, 'wordpress_logged_in_' );
			}
		),
		'schemes'                   => $results,
	];
}

function dtb_admin_security_has_native_auth_cookie_user(): bool {
	foreach ( [ 'secure_auth', 'auth', 'logged_in' ] as $scheme ) {
		$user_id = (int) wp_validate_auth_cookie( '', $scheme );
		if ( $user_id > 0 && ( user_can( $user_id, 'manage_options' ) || user_can( $user_id, 'manage_woocommerce' ) ) ) {
			return true;
		}
	}

	return false;
}

function dtb_admin_security_dtb_auth_cookie_diagnostics(): array {
	$token = defined( 'DTB_AUTH_COOKIE' ) && ! empty( $_COOKIE[ DTB_AUTH_COOKIE ] )
		? sanitize_text_field( wp_unslash( (string) $_COOKIE[ DTB_AUTH_COOKIE ] ) )
		: '';

	$diagnostics = [
		'present'       => '' !== $token,
		'valid'         => false,
		'sub'           => 0,
		'roles'         => [],
		'user_exists'   => false,
		'is_admin_user' => false,
	];

	if ( '' === $token || ! function_exists( 'dtb_verify_jwt' ) ) {
		return $diagnostics;
	}

	$payload = dtb_verify_jwt( $token );
	if ( is_wp_error( $payload ) ) {
		return $diagnostics;
	}

	$sub  = isset( $payload->sub ) ? absint( $payload->sub ) : 0;
	$user = $sub > 0 ? get_user_by( 'id', $sub ) : false;

	$diagnostics['valid']         = true;
	$diagnostics['sub']           = $sub;
	$diagnostics['roles']         = isset( $payload->roles ) && is_array( $payload->roles ) ? array_values( array_map( 'sanitize_key', $payload->roles ) ) : [];
	$diagnostics['user_exists']   = $user instanceof WP_User;
	$diagnostics['is_admin_user'] = $user instanceof WP_User && ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) );

	return $diagnostics;
}

function dtb_admin_security_cookie_path_diagnostics(): array {
	$cookie_path       = defined( 'COOKIEPATH' ) ? (string) COOKIEPATH : '';
	$site_cookie_path  = defined( 'SITECOOKIEPATH' ) ? (string) SITECOOKIEPATH : '';
	$admin_cookie_path = defined( 'ADMIN_COOKIE_PATH' ) ? (string) ADMIN_COOKIE_PATH : '';
	$rest_path         = (string) wp_parse_url( rest_url(), PHP_URL_PATH );
	$home_path         = (string) wp_parse_url( home_url(), PHP_URL_PATH );
	$site_path         = (string) wp_parse_url( site_url(), PHP_URL_PATH );

	return [
		'COOKIEPATH'          => $cookie_path,
		'SITECOOKIEPATH'      => $site_cookie_path,
		'ADMIN_COOKIE_PATH'   => $admin_cookie_path,
		'rest_path'           => $rest_path,
		'home_path'           => $home_path,
		'site_path'           => $site_path,
		'requires_root_scope' => 0 === strpos( $rest_path, '/wp-json' ) || 0 === strpos( (string) $_SERVER['REQUEST_URI'], '/wp-admin/' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		'root_scoped'         => '/' === $cookie_path && '/' === $site_cookie_path && '/' === $admin_cookie_path,
	];
}

function dtb_admin_security_render_cookie_path_notice(): void {
	if ( ! is_admin() || wp_doing_ajax() || ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'manage_woocommerce' ) ) ) {
		return;
	}

	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'wc-settings' !== $page && 'wc-admin' !== $page ) {
		return;
	}

	$diagnostics = dtb_admin_security_cookie_path_diagnostics();
	if ( empty( $diagnostics['requires_root_scope'] ) || ! empty( $diagnostics['root_scoped'] ) ) {
		return;
	}

	echo '<div class="notice notice-warning"><p>';
	echo esc_html__(
		'Drywall Toolbox detected that WordPress auth cookie paths may not cover root /wp-json REST requests. WooCommerce Admin and payment provider settings require native WordPress auth cookies plus X-WP-Nonce; configure COOKIEPATH, SITECOOKIEPATH, and ADMIN_COOKIE_PATH to "/" in wp-config.php for this root-mounted /wp-admin and /wp-json topology.',
		'drywall-toolbox'
	);
	echo '</p></div>';
}

function dtb_admin_security_log_rest_denials( $response, WP_REST_Server $server, WP_REST_Request $request ) {
	if ( ! dtb_feature_enabled( 'DTB_ENABLE_ADMIN_REST_LOGGING', true ) ) {
		return $response;
	}

	$status = 0;

	if ( $response instanceof WP_HTTP_Response ) {
		$status = (int) $response->get_status();
	} elseif ( is_wp_error( $response ) ) {
		$status = (int) ( $response->get_error_data()['status'] ?? 0 );
	}

	$route = $request->get_route();

	// Log 401/403 on admin routes.
	if ( in_array( $status, [ 401, 403 ], true ) && dtb_admin_security_is_admin_route( $route ) ) {
		dtb_security_log(
			'admin_rest_denied',
			[
				'route'  => $route,
				'status' => $status,
			]
		);
	}

	// Log 500 errors on dtb/* routes for alerting.
	if ( 500 === $status && 0 === strpos( $route, '/dtb/' ) ) {
		dtb_security_log(
			'dtb_rest_server_error',
			[
				'route'  => $route,
				'status' => $status,
			]
		);
	}

	return $response;
}

function dtb_admin_security_is_admin_route( string $route ): bool {
	foreach ( [ '/wp/v2/users/me', '/wc-admin/', '/wc-analytics/', '/wc/v3/', '/dtb/v1/admin/' ] as $prefix ) {
		if ( 0 === strpos( $route, $prefix ) ) {
			return true;
		}
	}

	return false;
}

function dtb_admin_security_smoke_results(): array {
	$checks = [
		[
			'label'  => 'DTB Health',
			'method' => 'GET',
			'route'  => '/dtb/v1/health',
			'params' => [],
		],
		[
			'label'  => 'WP current user',
			'method' => 'GET',
			'route'  => '/wp/v2/users/me',
			'params' => [
				'context' => 'edit',
			],
		],
		[
			'label'  => 'Woo low stock count',
			'method' => 'GET',
			'route'  => '/wc-analytics/products/count-low-in-stock',
			'params' => [
				'status'   => 'publish',
				'page'     => 1,
				'per_page' => 1,
			],
		],
		[
			'label'  => 'Woo admin options',
			'method' => 'GET',
			'route'  => '/wc-admin/options',
			'params' => [
				'options' => 'woocommerce_allow_tracking',
			],
		],
		[
			'label'  => 'Woo admin tasks',
			'method' => 'GET',
			'route'  => '/wc-admin/onboarding/tasks',
			'params' => [],
		],
	];

	$results = [];

	foreach ( $checks as $check ) {
		$request = new WP_REST_Request( $check['method'], $check['route'] );

		foreach ( $check['params'] as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );
		$status   = is_wp_error( $response ) ? (int) ( $response->get_error_data()['status'] ?? 0 ) : (int) $response->get_status();

		$results[] = [
			'label'  => $check['label'],
			'route'  => $check['route'],
			'status' => $status,
			'ok'     => $status >= 200 && $status < 400,
		];
	}

	return $results;
}
