<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB API Health Monitor
 *
 * Live diagnostic checks for all REST API endpoints the React SPA depends on.
 * All checks are triggered on-demand via AJAX — nothing fires on page load.
 *
 * @package DrywallToolbox
 */

defined( 'ABSPATH' ) || exit;

// Only load this admin UI tool when inside wp-admin or AJAX requests.
if ( ! dtb_is_admin_or_ajax_request() ) {
	return;
}

// ── Shared top-level DTB admin menu (registers once across all DTB mu-plugins) ──

if ( ! function_exists( 'dtb_register_top_level_menu' ) ) {
	function dtb_register_top_level_menu() {
		add_menu_page(
			__( 'Drywall Toolbox', 'dtb' ),
			__( 'DTB Tools', 'dtb' ),
			'manage_options',
			'dtb-toolbox',
			'dtb_toolbox_dashboard_page',
			'dashicons-hammer',
			30
		);
	}
	add_action( 'admin_menu', 'dtb_register_top_level_menu', 5 );
}

if ( ! function_exists( 'dtb_toolbox_dashboard_page' ) ) {
	function dtb_toolbox_dashboard_page() {
		echo '<div class="wrap"><h1>' . esc_html__( 'DTB Tools', 'dtb' ) . '</h1>';
		echo '<p>' . esc_html__( 'Select a tool from the menu on the left.', 'dtb' ) . '</p></div>';
	}
}

// ── Submenu ───────────────────────────────────────────────────────────────────

add_action( 'admin_menu', function () {
	add_submenu_page(
		'dtb-toolbox',
		__( 'API Health Monitor', 'dtb' ),
		__( 'API Health', 'dtb' ),
		'manage_options',
		'dtb-api-health',
		'dtb_api_health_render_page'
	);
} );

// ── AJAX: Run all health checks ───────────────────────────────────────────────

add_action( 'wp_ajax_dtb_run_health_checks', 'dtb_ajax_run_health_checks' );
function dtb_ajax_run_health_checks() {
	check_ajax_referer( 'dtb_health_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}
	$include_raw_diagnostics = current_user_can( 'dtb_manage_system' )
		&& isset( $_POST['include_raw'] )
		&& '1' === sanitize_text_field( wp_unslash( $_POST['include_raw'] ) );

	$wc_user = defined( 'DTB_WC_AUTH_USER' ) ? DTB_WC_AUTH_USER : get_option( 'dtb_wc_auth_user', '' );
	$wc_pass = defined( 'DTB_WC_AUTH_PASS' ) ? DTB_WC_AUTH_PASS : get_option( 'dtb_wc_auth_pass', '' );
	$wc_basic = ( $wc_user && $wc_pass ) ? 'Basic ' . base64_encode( $wc_user . ':' . $wc_pass ) : '';

	$endpoints = [
		[
			'label'   => 'WP REST API Root',
			'method'  => 'GET',
			'url'     => rest_url( '/' ),
			'auth'    => 'none',
			'expects' => [ 200 ],
			'group'   => 'WordPress',
		],
		[
			'label'   => 'WP Users Endpoint (JWT protected)',
			'method'  => 'GET',
			'url'     => rest_url( 'wp/v2/users/me' ),
			'auth'    => 'none',
			'expects' => [ 401 ],
			'group'   => 'WordPress',
		],
		[
			'label'   => 'DTB Auth Login Endpoint',
			'method'  => 'POST',
			'url'     => rest_url( 'dtb/v1/auth/login' ),
			'auth'    => 'none',
			'body'    => [ 'login' => '__dtb_probe__', 'password' => '__dtb_probe__' ],
			'expects' => [ 200, 400, 401, 429 ],
			'note'    => '200/400/401/429 all confirm endpoint is live (invalid probe credentials are expected).',
			'group'   => 'Auth',
		],
		[
			'label'   => 'DTB Auth Validate Endpoint',
			'method'  => 'POST',
			'url'     => rest_url( 'dtb/v1/auth/validate' ),
			'auth'    => 'none',
			'expects' => [ 200 ],
			'note'    => 'No token/cookie sent — expects 200 with authenticated=false.',
			'group'   => 'Auth',
		],
		[
			'label'   => 'WooCommerce Products (v3)',
			'method'  => 'GET',
			'url'     => rest_url( 'wc/v3/products?per_page=1' ),
			'auth'    => 'wc',
			'expects' => [ 200 ],
			'group'   => 'WooCommerce',
		],
		[
			'label'   => 'WooCommerce Categories (v3)',
			'method'  => 'GET',
			'url'     => rest_url( 'wc/v3/products/categories?per_page=1' ),
			'auth'    => 'wc',
			'expects' => [ 200 ],
			'group'   => 'WooCommerce',
		],
		[
			'label'   => 'WooCommerce Orders (v3)',
			'method'  => 'GET',
			'url'     => rest_url( 'wc/v3/orders?per_page=1' ),
			'auth'    => 'wc',
			'expects' => [ 200, 401, 403 ],
			'note'    => '401/403 confirms route is reachable but current app-password lacks order-read capability.',
			'group'   => 'WooCommerce',
		],
		[
			'label'   => 'WC Store API Cart',
			'method'  => 'GET',
			'url'     => rest_url( 'wc/store/v1/cart' ),
			'auth'    => 'none',
			'expects' => [ 200 ],
			'group'   => 'Store API',
		],
		[
			'label'   => 'WC Store API Products',
			'method'  => 'GET',
			'url'     => rest_url( 'wc/store/v1/products?per_page=1' ),
			'auth'    => 'none',
			'expects' => [ 200 ],
			'group'   => 'Store API',
		],
		[
			'label'   => 'DTB Health Check',
			'method'  => 'GET',
			'url'     => rest_url( 'dtb/v1/health' ),
			'auth'    => 'none',
			'expects' => [ 200 ],
			'group'   => 'DTB Custom',
		],
		[
			'label'   => 'DTB Schematics Catalog',
			'method'  => 'GET',
			'url'     => rest_url( 'dtb/v1/schematics?per_page=1' ),
			'auth'    => 'none',
			'expects' => [ 200 ],
			'group'   => 'DTB Custom',
		],
		[
			'label'   => 'WC System Status',
			'method'  => 'GET',
			'url'     => rest_url( 'wc/v3/system_status' ),
			'auth'    => 'wc',
			'expects' => [ 200, 401, 403 ],
			'note'    => '401/403 confirms route is reachable but current app-password lacks system-status capability.',
			'group'   => 'WooCommerce',
		],
	];

	$results = [];

	foreach ( $endpoints as $ep ) {
		$args = [
			'timeout'   => 12,
			'sslverify' => true,
			'headers'   => [ 'Accept' => 'application/json', 'Content-Type' => 'application/json' ],
		];

		if ( $ep['auth'] === 'wc' && $wc_basic ) {
			$args['headers']['Authorization'] = $wc_basic;
		}

		$start = microtime( true );

		if ( $ep['method'] === 'POST' ) {
			$args['body'] = isset( $ep['body'] ) ? $ep['body'] : [];
			$response = wp_remote_post( $ep['url'], $args );
		} else {
			$response = wp_remote_get( $ep['url'], $args );
		}

		$elapsed_ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			$results[] = [
				'label'   => $ep['label'],
				'url'     => $ep['url'],
				'method'  => $ep['method'],
				'group'   => $ep['group'],
				'status'  => 0,
				'expects' => $ep['expects'],
				'time_ms' => $elapsed_ms,
				'error'   => $response->get_error_message(),
				'pass'    => false,
				'cors'    => null,
				'note'    => $ep['note'] ?? '',
			];
			continue;
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$headers = wp_remote_retrieve_headers( $response );

		$cors = [
			'origin'  => $headers->offsetGet( 'access-control-allow-origin' ) ?: null,
			'methods' => $headers->offsetGet( 'access-control-allow-methods' ) ?: null,
			'headers' => $headers->offsetGet( 'access-control-allow-headers' ) ?: null,
		];

		$pass = in_array( $status, $ep['expects'], true );

		$body_preview = '';
		if ( $include_raw_diagnostics && ! $pass ) {
			$raw          = wp_remote_retrieve_body( $response );
			$decoded      = json_decode( $raw, true );
			$body_preview = is_array( $decoded ) && isset( $decoded['message'] ) ? $decoded['message'] : substr( $raw, 0, 120 );
		}

		$results[] = [
			'label'        => $ep['label'],
			'url'          => $ep['url'],
			'method'       => $ep['method'],
			'group'        => $ep['group'],
			'status'       => $status,
			'expects'      => $ep['expects'],
			'time_ms'      => $elapsed_ms,
			'error'        => null,
			'pass'         => $pass,
			'cors'         => $cors,
			'note'         => $ep['note'] ?? '',
			'body_preview' => $body_preview,
		];
	}

	if ( function_exists( 'dtb_admin_security_smoke_results' ) ) {
		foreach ( dtb_admin_security_smoke_results() as $smoke ) {
			$results[] = [
				'label'        => $smoke['label'],
				'url'          => rest_url( ltrim( $smoke['route'], '/' ) ),
				'method'       => 'GET',
				'group'        => 'Woo Admin',
				'status'       => (int) $smoke['status'],
				'expects'      => [ 200 ],
				'time_ms'      => null,
				'error'        => null,
				'pass'         => (bool) $smoke['ok'],
				'cors'         => null,
				'note'         => 'Internal admin compatibility smoke check.',
				'body_preview' => '',
			];
		}
	}

	// DTB Ops version option check.
	$ops_version_stored  = (string) get_option( 'dtb_ops_version', '' );
	$ops_version_defined = defined( 'DTB_OPS_VERSION' ) ? DTB_OPS_VERSION : null;
	$results[] = [
		'label'        => 'DTB Ops Version Option',
		'url'          => admin_url( 'options.php#dtb_ops_version' ),
		'method'       => 'OPTION',
		'group'        => 'DTB Ops',
		'status'       => '' !== $ops_version_stored ? 200 : 404,
		'expects'      => [ 200 ],
		'time_ms'      => null,
		'error'        => null,
		'pass'         => '' !== $ops_version_stored,
		'cors'         => null,
		'note'         => sprintf(
			'Stored: %s | Defined: %s',
			$ops_version_stored ?: '(not set)',
			$ops_version_defined ?: '(constant not defined)'
		),
		'body_preview' => '',
	];

	wp_send_json_success( [
		'results'    => $results,
		'summary'    => class_exists( 'DTB_ApiHealthController' ) ? DTB_ApiHealthController::summary() : [],
		'checked_at' => current_time( 'mysql' ),
		'site_url'   => get_site_url(),
		'rest_url'   => get_rest_url(),
	] );
}

// ── AJAX: JWT round-trip test with real credentials ───────────────────────────

add_action( 'wp_ajax_dtb_test_jwt_roundtrip', 'dtb_ajax_test_jwt_roundtrip' );
function dtb_ajax_test_jwt_roundtrip() {
	check_ajax_referer( 'dtb_health_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	$username = sanitize_text_field( $_POST['username'] ?? '' );
	$password = sanitize_text_field( $_POST['password'] ?? '' );

	if ( ! $username || ! $password ) {
		wp_send_json_error( [ 'message' => 'Username and password are required.' ] );
	}

	$t0       = microtime( true );
	$response = wp_remote_post( rest_url( 'dtb/v1/auth/login' ), [
		'timeout' => 12,
		'body'    => [ 'login' => $username, 'password' => $password ],
		'headers' => [ 'Accept' => 'application/json' ],
	] );
	$issue_ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( [ 'message' => $response->get_error_message() ] );
	}

	$status = (int) wp_remote_retrieve_response_code( $response );
	$body   = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( $status !== 200 || empty( $body['success'] ) ) {
		wp_send_json_error( [
			'message'  => $body['message'] ?? 'Login endpoint did not return a success response.',
			'status'   => $status,
			'issue_ms' => $issue_ms,
		] );
	}

	$cookies = wp_remote_retrieve_cookies( $response );

	$t1       = microtime( true );
	$validate = wp_remote_post( rest_url( 'dtb/v1/auth/validate' ), [
		'timeout' => 12,
		'cookies' => $cookies,
		'headers' => [
			'Accept'        => 'application/json',
		],
	] );
	$validate_ms = (int) round( ( microtime( true ) - $t1 ) * 1000 );

	$v_status = is_wp_error( $validate ) ? 0 : (int) wp_remote_retrieve_response_code( $validate );
	$v_body   = is_wp_error( $validate ) ? [] : json_decode( wp_remote_retrieve_body( $validate ), true );
	$token_valid = ( 200 === $v_status ) && ! empty( $v_body['authenticated'] );

	wp_send_json_success( [
		'token_issued'    => true,
		'token_valid'     => $token_valid,
		'user_email'      => $body['user']['email'] ?? null,
		'user_name'       => $body['user']['display_name'] ?? null,
		'issue_ms'        => $issue_ms,
		'validate_ms'     => $validate_ms,
		'validate_status' => $v_status,
	] );
}

// ── AJAX: Save WC credentials ─────────────────────────────────────────────────

add_action( 'wp_ajax_dtb_save_wc_creds', 'dtb_ajax_save_wc_creds' );
function dtb_ajax_save_wc_creds() {
	check_ajax_referer( 'dtb_health_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
	}

	$user = sanitize_text_field( $_POST['wc_user'] ?? '' );
	$pass = sanitize_text_field( $_POST['wc_pass'] ?? '' );

	update_option( 'dtb_wc_auth_user', $user );
	update_option( 'dtb_wc_auth_pass', $pass );

	wp_send_json_success( [ 'message' => 'Credentials saved.' ] );
}

// ── Page Render ───────────────────────────────────────────────────────────────

function dtb_api_health_render_page() {
	$nonce   = wp_create_nonce( 'dtb_health_nonce' );
	$wc_user = defined( 'DTB_WC_AUTH_USER' ) ? '(defined in code)' : get_option( 'dtb_wc_auth_user', '' );
	$wc_pass_set = defined( 'DTB_WC_AUTH_PASS' ) ? true : ( (bool) get_option( 'dtb_wc_auth_pass', '' ) );
	?>
	<div class="wrap dtb-health">
		<h1 class="wp-heading-inline">API Health Monitor</h1>
		<hr class="wp-header-end">

		<style>
			.dtb-health { max-width:1060px; }
			.dtb-card  { background:#fff; border:1px solid #dcdcde; border-radius:4px; padding:20px 24px; margin:20px 0; }
			.dtb-card h2 { margin:0 0 4px; font-size:14px; font-weight:600; color:#1d2327; }
			.dtb-card .dtb-card-desc { color:#787c82; font-size:12px; margin:0 0 16px; }
			.dtb-btn { display:inline-flex; align-items:center; gap:6px; padding:7px 16px; border-radius:3px; font-size:13px; font-weight:600; cursor:pointer; border:1px solid transparent; line-height:1.4; }
			.dtb-btn-primary { background:#2271b1; color:#fff; border-color:#2271b1; }
			.dtb-btn-primary:hover { background:#135e96; border-color:#135e96; color:#fff; }
			.dtb-btn-secondary { background:#f6f7f7; color:#2c3338; border-color:#c3c4c7; }
			.dtb-btn-secondary:hover { background:#f0f0f1; }
			.dtb-btn:disabled { opacity:.5; cursor:not-allowed; }
			.dtb-tbl { width:100%; border-collapse:collapse; font-size:13px; }
			.dtb-tbl th { text-align:left; padding:9px 12px; border-bottom:2px solid #dcdcde; background:#f6f7f7; font-weight:600; white-space:nowrap; }
			.dtb-tbl td { padding:9px 12px; border-bottom:1px solid #f0f0f1; vertical-align:top; }
			.dtb-tbl tr:last-child td { border-bottom:0; }
			.dtb-tbl tr:hover td { background:#fafafa; }
			.dtb-tbl .dtb-group-header td { background:#f6f7f7; font-weight:600; font-size:11px; text-transform:uppercase; letter-spacing:.6px; color:#888; padding:6px 12px; }
			.dtb-badge { display:inline-flex; align-items:center; gap:3px; padding:2px 8px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:.4px; white-space:nowrap; }
			.pass { background:#edfaed; color:#1a7f37; }
			.fail { background:#fcf0f1; color:#d63638; }
			.pending { background:#f6f7f7; color:#787c82; }
			.warn { background:#fcf9e8; color:#996800; }
			.dtb-code { display:inline-block; font-family:monospace; font-size:12px; font-weight:700; padding:1px 6px; border-radius:3px; }
			.c2xx { background:#edfaed; color:#1a7f37; }
			.c4xx { background:#fcf9e8; color:#996800; }
			.c5xx { background:#fcf0f1; color:#d63638; }
			.c0   { background:#f6f7f7; color:#787c82; }
			.dtb-url { font-family:monospace; font-size:11px; color:#787c82; word-break:break-all; margin-top:2px; }
			.dtb-note { font-size:11px; color:#787c82; margin-top:2px; font-style:italic; }
			.dtb-err  { font-size:11px; color:#d63638; margin-top:2px; }
			.dtb-cors-ok  { color:#1a7f37; font-size:12px; }
			.dtb-cors-bad { color:#d63638; font-size:12px; }
			.dtb-time { font-family:monospace; font-size:12px; }
			.t-fast { color:#1a7f37; } .t-med { color:#996800; } .t-slow { color:#d63638; }
			.dtb-summary { display:flex; gap:20px; margin-bottom:16px; align-items:center; }
			.dtb-summary-stat { font-size:13px; }
			.dtb-summary-stat strong { font-size:22px; display:block; line-height:1.1; }
			.dtb-spinner-wrap { display:inline-flex; align-items:center; gap:8px; }
			.dtb-jwt-form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
			.dtb-field label { font-size:12px; font-weight:600; display:block; margin-bottom:3px; color:#3c434a; }
			.dtb-field input[type=text], .dtb-field input[type=password] { padding:5px 9px; border:1px solid #c3c4c7; border-radius:3px; font-size:13px; }
			.dtb-result-box { margin-top:12px; padding:12px 16px; border-radius:4px; font-size:13px; }
			.dtb-result-box.ok  { background:#edfaed; border:1px solid #b7e5b7; color:#1a7f37; }
			.dtb-result-box.err { background:#fcf0f1; border:1px solid #f5c6c7; color:#d63638; }
			.dtb-creds-form { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
			.dtb-creds-status { font-size:12px; color:#787c82; margin-top:6px; }
			#dtb-empty-state { color:#787c82; font-size:13px; text-align:center; padding:28px 0; }
		</style>

		<!-- Run Checks Card -->
		<div class="dtb-card">
			<h2>Endpoint Diagnostics</h2>
			<p class="dtb-card-desc">Live checks against every REST endpoint the React SPA depends on. Runs server-side — results reflect real reachability from this server.</p>
			<p class="dtb-card-desc">
				Technical traces are intentionally hidden here by default.
				Use <a href="<?php echo esc_url( admin_url( 'admin.php?page=dtb-system-manager&tab=integrations' ) ); ?>">System Manager</a> for backend diagnostics.
			</p>

			<div style="display:flex;align-items:center;gap:12px;margin-bottom:18px;">
				<button id="dtb-btn-run" class="dtb-btn dtb-btn-primary">
					<span class="dashicons dashicons-update-alt" style="font-size:16px;"></span>
					Run All Checks
				</button>
				<span id="dtb-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
			</div>

			<div id="dtb-summary" class="dtb-summary" style="display:none;">
				<div class="dtb-summary-stat" style="color:#1a7f37;"><strong id="dtb-pass-n">0</strong> Passing</div>
				<div class="dtb-summary-stat" style="color:#d63638;"><strong id="dtb-fail-n">0</strong> Failing</div>
				<div class="dtb-summary-stat" style="color:#787c82;"><strong id="dtb-avg-ms">—</strong> Avg ms</div>
			</div>

			<div id="dtb-results">
				<p id="dtb-empty-state">Click <strong>Run All Checks</strong> to start diagnostics.</p>
			</div>

			<div id="dtb-checked-at" style="font-size:11px;color:#787c82;margin-top:8px;"></div>
		</div>

		<!-- JWT Round-Trip -->
		<div class="dtb-card">
			<h2>Auth Round-Trip Test</h2>
			<p class="dtb-card-desc">Issue and validate a real DTB auth session cookie to confirm the complete auth flow works end-to-end. Uses the same endpoints the React SPA calls.</p>
			<div class="dtb-jwt-form">
				<div class="dtb-field">
					<label>WordPress Username</label>
					<input type="text" id="dtb-jwt-user" placeholder="admin" style="width:200px;">
				</div>
				<div class="dtb-field">
					<label>Password</label>
					<input type="password" id="dtb-jwt-pass" placeholder="••••••••" style="width:200px;">
				</div>
				<button id="dtb-btn-jwt" class="dtb-btn dtb-btn-secondary">Test DTB Auth</button>
				<span id="dtb-jwt-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
			</div>
			<div id="dtb-jwt-result" style="display:none;" class="dtb-result-box"></div>
		</div>

		<!-- WC Credentials -->
		<div class="dtb-card">
			<h2>WooCommerce Application Password</h2>
			<p class="dtb-card-desc">
				Required for WC REST API v3 checks. If credentials are defined in PHP constants (<code>DTB_WC_AUTH_USER</code> / <code>DTB_WC_AUTH_PASS</code>) they take precedence and cannot be overridden here.
			</p>
			<?php if ( defined( 'DTB_WC_AUTH_USER' ) ) : ?>
				<p style="color:#1a7f37;font-size:13px;"><span class="dashicons dashicons-lock"></span> Credentials are defined via PHP constant — no changes needed.</p>
			<?php else : ?>
				<div class="dtb-creds-form">
					<div class="dtb-field">
						<label>WC Username</label>
						<input type="text" id="dtb-wc-user" value="<?php echo esc_attr( $wc_user ); ?>" style="width:200px;">
					</div>
					<div class="dtb-field">
						<label>Application Password</label>
						<input type="password" id="dtb-wc-pass" placeholder="<?php echo $wc_pass_set ? '(saved)' : 'xxxx xxxx xxxx xxxx'; ?>" style="width:240px;">
					</div>
					<button id="dtb-btn-save-creds" class="dtb-btn dtb-btn-secondary">Save Credentials</button>
					<span id="dtb-creds-spinner" style="display:none;"><span class="spinner is-active" style="float:none;margin:0;"></span></span>
				</div>
				<p id="dtb-creds-msg" class="dtb-creds-status"></p>
			<?php endif; ?>
		</div>
	</div>

	<script>
	(function($){
		var nonce = <?php echo wp_json_encode( $nonce ); ?>;

		function statusClass(code) {
			if (!code) return 'c0';
			if (code >= 200 && code < 300) return 'c2xx';
			if (code >= 400 && code < 500) return 'c4xx';
			return 'c5xx';
		}
		function timeClass(ms) {
			if (ms < 350) return 't-fast';
			if (ms < 900) return 't-med';
			return 't-slow';
		}
		function corsHtml(cors) {
			if (!cors) return '<span style="color:#787c82">—</span>';
			if (cors.origin) return '<span class="dtb-cors-ok dashicons dashicons-yes-alt" title="' + $('<div>').text(cors.origin).html() + '"></span>';
			return '<span class="dtb-cors-bad dashicons dashicons-dismiss" title="No CORS origin header"></span>';
		}

		$('#dtb-btn-run').on('click', function(){
			var $btn = $(this);
			$btn.prop('disabled', true);
			$('#dtb-spinner').show();
			$('#dtb-summary').hide();
			$('#dtb-results').html('<p style="color:#787c82;font-size:13px;padding:12px 0;">Running checks…</p>');
			$('#dtb-checked-at').text('');

			$.post(ajaxurl, { action: 'dtb_run_health_checks', nonce: nonce }, function(res){
				$btn.prop('disabled', false);
				$('#dtb-spinner').hide();

				if (!res.success) {
					$('#dtb-results').html('<p style="color:#d63638;">Error: ' + (res.data && res.data.message ? $('<div>').text(res.data.message).html() : 'Unknown error') + '</p>');
					return;
				}

				var results = res.data.results;
				var pass = 0, fail = 0, totalMs = 0;
				var groups = {};

				$.each(results, function(i, r){
					if (r.pass) pass++; else fail++;
					totalMs += r.time_ms;
					if (!groups[r.group]) groups[r.group] = [];
					groups[r.group].push(r);
				});

				var html = '<table class="dtb-tbl"><thead><tr><th>Endpoint</th><th>Method</th><th>Status</th><th>Time</th><th>CORS</th><th>Result</th></tr></thead><tbody>';

				$.each(groups, function(group, rows){
					html += '<tr class="dtb-group-header"><td colspan="6">' + $('<div>').text(group).html() + '</td></tr>';
					$.each(rows, function(i, r){
						var statusHtml = r.error
							? '<span class="dtb-code c0">ERR</span>'
							: '<span class="dtb-code ' + statusClass(r.status) + '">' + r.status + '</span>';
						var timeHtml = r.error ? '—' : '<span class="dtb-time ' + timeClass(r.time_ms) + '">' + r.time_ms + 'ms</span>';
						var badge = r.pass
							? '<span class="dtb-badge pass">✓ Pass</span>'
							: '<span class="dtb-badge fail">✗ Fail</span>';
						var detail = '<div class="dtb-url">' + $('<div>').text(r.url).html() + '</div>';
						if (r.note) detail += '<div class="dtb-note">' + $('<div>').text(r.note).html() + '</div>';
						if (r.error) {
							detail += '<div class="dtb-err">Request failed. Open System Manager for technical diagnostics.</div>';
						} else if (!r.pass) {
							detail += '<div class="dtb-note">Endpoint did not return an expected status. Open System Manager for detailed diagnostics.</div>';
						}
						html += '<tr>';
						html += '<td><strong>' + $('<div>').text(r.label).html() + '</strong>' + detail + '</td>';
						html += '<td><code>' + r.method + '</code></td>';
						html += '<td>' + statusHtml + '<br><small style="color:#787c82;font-size:10px;">expects ' + r.expects.join('/') + '</small></td>';
						html += '<td>' + timeHtml + '</td>';
						html += '<td>' + corsHtml(r.cors) + '</td>';
						html += '<td>' + badge + '</td>';
						html += '</tr>';
					});
				});

				html += '</tbody></table>';
				$('#dtb-results').html(html);
				$('#dtb-pass-n').text(pass);
				$('#dtb-fail-n').text(fail);
				$('#dtb-avg-ms').text(Math.round(totalMs / results.length) + 'ms');
				$('#dtb-summary').show();
				$('#dtb-checked-at').text('Last checked: ' + res.data.checked_at + '  |  Site: ' + res.data.site_url);

			}).fail(function(){
				$btn.prop('disabled', false);
				$('#dtb-spinner').hide();
				$('#dtb-results').html('<p style="color:#d63638;">AJAX request failed. Open System Manager for diagnostics.</p>');
			});
		});

		$('#dtb-btn-jwt').on('click', function(){
			var $btn = $(this);
			var u = $('#dtb-jwt-user').val().trim();
			var p = $('#dtb-jwt-pass').val();
			if (!u || !p) { alert('Enter username and password.'); return; }
			$btn.prop('disabled', true);
			$('#dtb-jwt-spinner').show();
			$('#dtb-jwt-result').hide();

			$.post(ajaxurl, { action: 'dtb_test_jwt_roundtrip', nonce: nonce, username: u, password: p }, function(res){
				$btn.prop('disabled', false);
				$('#dtb-jwt-spinner').hide();
				var $box = $('#dtb-jwt-result');
				if (res.success) {
					var d = res.data;
					$box.removeClass('err').addClass('ok').html(
						'<strong>✓ DTB Auth Working</strong><br>' +
						'Signed in as <strong>' + $('<div>').text(d.user_name).html() + '</strong> (' + $('<div>').text(d.user_email).html() + ')<br>' +
						'Token issued: ' + d.issue_ms + 'ms &nbsp;|&nbsp; Validation: ' + (d.token_valid ? '✓ Valid (' + d.validate_ms + 'ms)' : '✗ Validation failed (HTTP ' + d.validate_status + ')')
					);
				} else {
					var e = res.data || {};
					$box.removeClass('ok').addClass('err').html(
						'<strong>✗ Auth Failed</strong><br>' + $('<div>').text(e.message || 'Unknown error').html() + (e.status ? ' (HTTP ' + e.status + ')' : '') + (e.issue_ms ? ' — ' + e.issue_ms + 'ms' : '')
					);
				}
				$box.show();
			}).fail(function(){
				$btn.prop('disabled', false);
				$('#dtb-jwt-spinner').hide();
				$('#dtb-jwt-result').removeClass('ok').addClass('err').html('AJAX request failed.').show();
			});
		});

		$('#dtb-btn-save-creds') && $('#dtb-btn-save-creds').on('click', function(){
			var $btn = $(this);
			$btn.prop('disabled', true);
			$('#dtb-creds-spinner').show();
			$.post(ajaxurl, {
				action: 'dtb_save_wc_creds',
				nonce: nonce,
				wc_user: $('#dtb-wc-user').val(),
				wc_pass: $('#dtb-wc-pass').val()
			}, function(res){
				$btn.prop('disabled', false);
				$('#dtb-creds-spinner').hide();
				$('#dtb-creds-msg').text(res.success ? '✓ Credentials saved.' : '✗ Save failed.').css('color', res.success ? '#1a7f37' : '#d63638');
			});
		});

	})(jQuery);
	</script>
	<?php
}
