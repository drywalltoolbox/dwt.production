<?php
/**
 * DTB QuickBooks Online client.
 *
 * Owns sandbox/production configuration, OAuth2, encrypted token storage,
 * authenticated Intuit API requests, customer projection, and operator status.
 * Order and refund writes remain exclusively queue-owned by dtb-order-platform.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_QBO_API_BASE' ) ) {
	define( 'DTB_QBO_API_BASE', 'https://quickbooks.api.intuit.com/v3/company' );
}
if ( ! defined( 'DTB_QBO_SANDBOX_BASE' ) ) {
	define( 'DTB_QBO_SANDBOX_BASE', 'https://sandbox-quickbooks.api.intuit.com/v3/company' );
}
if ( ! defined( 'DTB_QBO_AUTH_URL' ) ) {
	define( 'DTB_QBO_AUTH_URL', 'https://appcenter.intuit.com/connect/oauth2' );
}
if ( ! defined( 'DTB_QBO_TOKEN_URL' ) ) {
	define( 'DTB_QBO_TOKEN_URL', 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer' );
}
if ( ! defined( 'DTB_QBO_SCOPE' ) ) {
	define( 'DTB_QBO_SCOPE', 'com.intuit.quickbooks.accounting' );
}
if ( ! defined( 'DTB_QBO_MINOR_VERSION' ) ) {
	define( 'DTB_QBO_MINOR_VERSION', 75 );
}

function dtb_qbo_environment(): string {
	$environment = defined( 'DTB_QBO_ENVIRONMENT' ) ? strtolower( (string) DTB_QBO_ENVIRONMENT ) : '';
	if ( in_array( $environment, [ 'sandbox', 'production' ], true ) ) {
		return $environment;
	}
	return function_exists( 'dtb_feature_enabled' ) && dtb_feature_enabled( 'DTB_QBO_SANDBOX', false ) ? 'sandbox' : 'production';
}

function dtb_qbo_option_name( string $suffix ): string {
	return 'dtb_qbo_' . dtb_qbo_environment() . '_' . sanitize_key( $suffix );
}

function dtb_qbo_redirect_uri(): string {
	return admin_url( 'admin-ajax.php?action=dtb_qbo_oauth_callback' );
}

/**
 * Settings saved from System Manager -> Integration Settings
 * (dtb-platform/SystemManager/Rest/IntegrationSettingsController.php).
 * wp-config.php constants always take priority when defined, matching the
 * override precedent already used by dtb_veeqo_config().
 */
function dtb_qbo_settings_option(): array {
	return (array) get_option( 'dtb_qbo_settings', [] );
}

function dtb_qbo_config(): array {
	$realm_option = (string) get_option( dtb_qbo_option_name( 'realm_id' ), '' );
	$stored       = dtb_qbo_settings_option();
	return [
		'environment'   => dtb_qbo_environment(),
		'client_id'     => defined( 'DTB_QBO_CLIENT_ID' ) ? trim( (string) DTB_QBO_CLIENT_ID ) : trim( (string) ( $stored['client_id'] ?? '' ) ),
		'client_secret' => defined( 'DTB_QBO_CLIENT_SECRET' ) ? trim( (string) DTB_QBO_CLIENT_SECRET ) : trim( (string) ( $stored['client_secret'] ?? '' ) ),
		'realm_id'      => defined( 'DTB_QBO_REALM_ID' ) ? trim( (string) DTB_QBO_REALM_ID ) : $realm_option,
		'sandbox'       => 'sandbox' === dtb_qbo_environment(),
		'redirect_uri'  => dtb_qbo_redirect_uri(),
	];
}

/**
 * Resolve the Stripe restricted reporting key used for Settlement Import
 * (DTB_QBO_AccountingOperations::sync_settlements()). Constant takes
 * priority; falls back to the System Manager-configured option.
 */
function dtb_qbo_stripe_restricted_key(): string {
	if ( defined( 'DTB_STRIPE_ACCOUNTING_RESTRICTED_KEY' ) && '' !== trim( (string) DTB_STRIPE_ACCOUNTING_RESTRICTED_KEY ) ) {
		return trim( (string) DTB_STRIPE_ACCOUNTING_RESTRICTED_KEY );
	}
	return trim( (string) ( dtb_qbo_settings_option()['stripe_restricted_key'] ?? '' ) );
}

function dtb_qbo_api_base(): string {
	return 'sandbox' === dtb_qbo_environment() ? DTB_QBO_SANDBOX_BASE : DTB_QBO_API_BASE;
}

function dtb_qbo_enabled(): bool {
	$config = dtb_qbo_config();
	$tokens = dtb_qbo_load_tokens();
	return '' !== $config['client_id']
		&& '' !== $config['client_secret']
		&& '' !== $config['realm_id']
		&& is_array( $tokens )
		&& ! empty( $tokens['access_token'] )
		&& ! empty( $tokens['refresh_token'] );
}

function dtb_qbo_encryption_key(): string {
	$source = defined( 'WP_AUTH_KEY' ) ? (string) WP_AUTH_KEY : wp_salt( 'auth' );
	return hash( 'sha256', 'dtb-qbo|' . $source, true );
}

function dtb_qbo_encrypt( string $plaintext ): string {
	if ( ! function_exists( 'openssl_encrypt' ) ) {
		return '';
	}
	$iv  = random_bytes( 12 );
	$tag = '';
	$out = openssl_encrypt( $plaintext, 'aes-256-gcm', dtb_qbo_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
	if ( false === $out ) {
		return '';
	}
	return (string) wp_json_encode( [
		'v'   => 2,
		'alg' => 'aes-256-gcm',
		'iv'  => base64_encode( $iv ),
		'tag' => base64_encode( $tag ),
		'ct'  => base64_encode( $out ),
	] );
}

function dtb_qbo_decrypt( string $encrypted ): string {
	if ( '' === $encrypted || ! function_exists( 'openssl_decrypt' ) ) {
		return '';
	}
	$envelope = json_decode( $encrypted, true );
	if ( is_array( $envelope ) && 2 === (int) ( $envelope['v'] ?? 0 ) ) {
		$iv  = base64_decode( (string) ( $envelope['iv'] ?? '' ), true );
		$tag = base64_decode( (string) ( $envelope['tag'] ?? '' ), true );
		$ct  = base64_decode( (string) ( $envelope['ct'] ?? '' ), true );
		if ( false === $iv || false === $tag || false === $ct ) {
			return '';
		}
		$result = openssl_decrypt( $ct, 'aes-256-gcm', dtb_qbo_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $result ? '' : $result;
	}
	$raw = base64_decode( $encrypted, true );
	if ( false === $raw ) {
		return '';
	}
	$legacy_key = hash( 'sha256', defined( 'WP_AUTH_KEY' ) ? (string) WP_AUTH_KEY : wp_salt( 'auth' ), true );
	$iv_len     = openssl_cipher_iv_length( 'aes-256-cbc' );
	if ( strlen( $raw ) <= $iv_len ) {
		return '';
	}
	$result = openssl_decrypt( substr( $raw, $iv_len ), 'aes-256-cbc', $legacy_key, OPENSSL_RAW_DATA, substr( $raw, 0, $iv_len ) );
	return false === $result ? '' : $result;
}

function dtb_qbo_save_tokens( array $tokens ): bool {
	$config = dtb_qbo_config();
	$realm  = preg_replace( '/[^0-9]/', '', (string) ( $tokens['realm_id'] ?? $config['realm_id'] ) );
	if ( '' === $realm || empty( $tokens['access_token'] ) || empty( $tokens['refresh_token'] ) ) {
		return false;
	}
	$obtained_at             = time();
	$tokens['environment']    = dtb_qbo_environment();
	$tokens['realm_id']       = $realm;
	$tokens['obtained_at']    = $obtained_at;
	$tokens['expires_at']     = $obtained_at + max( 60, (int) ( $tokens['expires_in'] ?? 3600 ) );
	$tokens['client_id_hash'] = hash( 'sha256', (string) $config['client_id'] );
	$encrypted = dtb_qbo_encrypt( (string) wp_json_encode( $tokens ) );
	if ( '' === $encrypted ) {
		return false;
	}
	update_option( dtb_qbo_option_name( 'tokens' ), $encrypted, false );
	update_option( dtb_qbo_option_name( 'realm_id' ), $realm, false );
	return true;
}

function dtb_qbo_load_tokens(): ?array {
	$encrypted = (string) get_option( dtb_qbo_option_name( 'tokens' ), '' );
	if ( '' === $encrypted ) {
		return null;
	}
	$tokens = json_decode( dtb_qbo_decrypt( $encrypted ), true );
	$config = dtb_qbo_config();
	if ( ! is_array( $tokens ) ) {
		return null;
	}
	if ( ! empty( $tokens['environment'] ) && dtb_qbo_environment() !== $tokens['environment'] ) {
		return null;
	}
	if ( ! empty( $tokens['client_id_hash'] ) && ! hash_equals( (string) $tokens['client_id_hash'], hash( 'sha256', (string) $config['client_id'] ) ) ) {
		return null;
	}
	return $tokens;
}

function dtb_qbo_clear_tokens(): void {
	delete_option( dtb_qbo_option_name( 'tokens' ) );
	delete_option( dtb_qbo_option_name( 'realm_id' ) );
	delete_option( dtb_qbo_option_name( 'company' ) );
}

function dtb_qbo_fault_message( int $status, mixed $data ): string {
	$message = '';
	if ( is_array( $data ) ) {
		$message = (string) ( $data['Fault']['Error'][0]['Message'] ?? $data['error_description'] ?? $data['error'] ?? '' );
	}
	$message = sanitize_text_field( $message );
	return '' !== $message ? sprintf( 'QuickBooks request failed (HTTP %d): %s', $status, $message ) : sprintf( 'QuickBooks request failed (HTTP %d).', $status );
}

function dtb_qbo_token_request( array $body ): array|WP_Error {
	$config = dtb_qbo_config();
	if ( '' === $config['client_id'] || '' === $config['client_secret'] ) {
		return new WP_Error( 'qbo_credentials_missing', 'QuickBooks credentials are not configured.' );
	}
	$response = wp_remote_post( DTB_QBO_TOKEN_URL, [
		'timeout' => 20,
		'headers' => [
			'Authorization' => 'Basic ' . base64_encode( $config['client_id'] . ':' . $config['client_secret'] ),
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/x-www-form-urlencoded',
		],
		'body' => $body,
	] );
	if ( is_wp_error( $response ) ) {
		return new WP_Error( 'qbo_token_transport', 'QuickBooks authorization service is unavailable.' );
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$data   = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 200 !== $status || ! is_array( $data ) || empty( $data['access_token'] ) ) {
		return new WP_Error( 'qbo_token_failed', dtb_qbo_fault_message( $status, $data ) );
	}
	return $data;
}

function dtb_qbo_refresh_token(): array|WP_Error {
	$lock = dtb_qbo_option_name( 'refresh_lock' );
	if ( ! add_option( $lock, time(), '', false ) ) {
		for ( $i = 0; $i < 5; $i++ ) {
			usleep( 200000 );
			$tokens = dtb_qbo_load_tokens();
			if ( is_array( $tokens ) && (int) ( $tokens['expires_at'] ?? 0 ) > time() + 120 ) {
				return $tokens;
			}
		}
		return new WP_Error( 'qbo_refresh_locked', 'QuickBooks token refresh is already in progress.' );
	}
	try {
		$tokens = dtb_qbo_load_tokens();
		if ( ! is_array( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			return new WP_Error( 'qbo_reauthorization_required', 'Reconnect QuickBooks to continue.' );
		}
		$result = dtb_qbo_token_request( [
			'grant_type'    => 'refresh_token',
			'refresh_token' => (string) $tokens['refresh_token'],
		] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$result['realm_id'] = (string) ( $tokens['realm_id'] ?? dtb_qbo_config()['realm_id'] );
		if ( ! dtb_qbo_save_tokens( $result ) ) {
			return new WP_Error( 'qbo_token_store_failed', 'QuickBooks token storage failed.' );
		}
		return dtb_qbo_load_tokens() ?? $result;
	} finally {
		delete_option( $lock );
	}
}

function dtb_qbo_get_access_token(): string {
	$tokens = dtb_qbo_load_tokens();
	if ( ! is_array( $tokens ) || empty( $tokens['access_token'] ) ) {
		return '';
	}
	if ( (int) ( $tokens['expires_at'] ?? 0 ) <= time() + 300 ) {
		$tokens = dtb_qbo_refresh_token();
		if ( is_wp_error( $tokens ) ) {
			return '';
		}
	}
	return (string) ( $tokens['access_token'] ?? '' );
}

/**
 * Prelaunch accounting-write pause. Changing this to false requires a reviewed
 * deployment after checkout validation. Read-only connection diagnostics remain.
 */
function dtb_qbo_exports_paused(): bool {
	return true;
}

function dtb_qbo_request( string $method, string $path, array $params = [], array $body = [], bool $retry_auth = true ): array {
	if ( dtb_qbo_exports_paused() && ! in_array( strtoupper( $method ), [ 'GET', 'HEAD', 'OPTIONS' ], true ) ) {
		return [ 'ok' => false, 'status' => 0, 'data' => null, 'error' => 'QuickBooks writes temporarily paused during checkout validation.', 'retryable' => false ];
	}

	$config = dtb_qbo_config();
	if ( ! dtb_qbo_enabled() ) {
		return [ 'ok' => false, 'status' => 0, 'data' => null, 'error' => 'QuickBooks is not connected.', 'retryable' => false ];
	}
	$token = dtb_qbo_get_access_token();
	if ( '' === $token ) {
		return [ 'ok' => false, 'status' => 401, 'data' => null, 'error' => 'QuickBooks authorization is unavailable.', 'retryable' => false ];
	}
	$params['minorversion'] = (int) DTB_QBO_MINOR_VERSION;
	$url = trailingslashit( dtb_qbo_api_base() . '/' . rawurlencode( $config['realm_id'] ) ) . ltrim( $path, '/' );
	$url = add_query_arg( $params, $url );
	$args = [
		'method'      => strtoupper( $method ),
		'timeout'     => 25,
		'redirection' => 0,
		'headers'     => [
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'User-Agent'    => 'DrywallToolbox-QBO/1.0',
		],
	];
	if ( ! empty( $body ) ) {
		$args['body'] = wp_json_encode( $body );
	}
	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return [ 'ok' => false, 'status' => 0, 'data' => null, 'error' => 'QuickBooks network request failed.', 'retryable' => true ];
	}
	$status = (int) wp_remote_retrieve_response_code( $response );
	$data   = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( 401 === $status && $retry_auth ) {
		$refresh = dtb_qbo_refresh_token();
		if ( ! is_wp_error( $refresh ) ) {
			return dtb_qbo_request( $method, $path, $params, $body, false );
		}
	}
	$ok = $status >= 200 && $status < 300;
	return [
		'ok'         => $ok,
		'status'     => $status,
		'data'       => is_array( $data ) ? $data : [],
		'error'      => $ok ? null : dtb_qbo_fault_message( $status, $data ),
		'retryable'  => 0 === $status || 429 === $status || $status >= 500,
		'intuit_tid' => sanitize_text_field( (string) wp_remote_retrieve_header( $response, 'intuit_tid' ) ),
	];
}

function dtb_qbo_verify_company(): array|WP_Error {
	$config = dtb_qbo_config();
	$result = dtb_qbo_request( 'GET', '/companyinfo/' . rawurlencode( $config['realm_id'] ) );
	if ( empty( $result['ok'] ) ) {
		return new WP_Error( 'qbo_company_unverified', (string) $result['error'] );
	}
	$company = (array) ( $result['data']['CompanyInfo'] ?? [] );
	if ( '' === (string) ( $company['Id'] ?? '' ) ) {
		return new WP_Error( 'qbo_company_invalid', 'QuickBooks returned an invalid company response.' );
	}
	$snapshot = [
		'id'          => sanitize_text_field( (string) $company['Id'] ),
		'name'        => sanitize_text_field( (string) ( $company['CompanyName'] ?? '' ) ),
		'country'     => sanitize_text_field( (string) ( $company['Country'] ?? '' ) ),
		'environment' => dtb_qbo_environment(),
		'verified_at' => gmdate( 'c' ),
	];
	update_option( dtb_qbo_option_name( 'company' ), $snapshot, false );
	return $snapshot;
}

function dtb_qbo_get_auth_url(): string {
	$config = dtb_qbo_config();
	if ( '' === $config['client_id'] || '' === $config['client_secret'] ) {
		return '';
	}
	$state = bin2hex( random_bytes( 32 ) );
	set_transient( 'dtb_qbo_oauth_' . hash( 'sha256', $state ), [
		'user_id'      => get_current_user_id(),
		'environment'  => dtb_qbo_environment(),
		'redirect_uri' => dtb_qbo_redirect_uri(),
	], 10 * MINUTE_IN_SECONDS );
	return add_query_arg( [
		'client_id'     => $config['client_id'],
		'response_type' => 'code',
		'scope'         => DTB_QBO_SCOPE,
		'redirect_uri'  => dtb_qbo_redirect_uri(),
		'state'         => $state,
	], DTB_QBO_AUTH_URL );
}

add_action( 'wp_ajax_dtb_qbo_oauth_callback', 'dtb_qbo_handle_oauth_callback' );
function dtb_qbo_handle_oauth_callback(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'dtb' ), 403 );
	}
	$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
	$key   = 'dtb_qbo_oauth_' . hash( 'sha256', $state );
	$txn   = get_transient( $key );
	delete_transient( $key );
	if ( '' === $state || ! is_array( $txn ) || (int) $txn['user_id'] !== get_current_user_id() || $txn['environment'] !== dtb_qbo_environment() || $txn['redirect_uri'] !== dtb_qbo_redirect_uri() ) {
		wp_die( esc_html__( 'QuickBooks authorization session is invalid or expired.', 'dtb' ), 400 );
	}
	if ( isset( $_GET['error'] ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=dtb-ops-quickbooks&qbo_error=authorization_denied' ) );
		exit;
	}
	$code     = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
	$realm_id = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_GET['realmId'] ?? '' ) );
	if ( '' === $code || '' === $realm_id ) {
		wp_die( esc_html__( 'QuickBooks did not return a valid authorization response.', 'dtb' ), 400 );
	}
	$tokens = dtb_qbo_token_request( [
		'grant_type'   => 'authorization_code',
		'code'         => $code,
		'redirect_uri' => dtb_qbo_redirect_uri(),
	] );
	if ( is_wp_error( $tokens ) ) {
		wp_safe_redirect( admin_url( 'admin.php?page=dtb-ops-quickbooks&qbo_error=token_exchange' ) );
		exit;
	}
	$tokens['realm_id'] = $realm_id;
	if ( ! dtb_qbo_save_tokens( $tokens ) || is_wp_error( dtb_qbo_verify_company() ) ) {
		dtb_qbo_clear_tokens();
		wp_safe_redirect( admin_url( 'admin.php?page=dtb-ops-quickbooks&qbo_error=company_verification' ) );
		exit;
	}
	if ( function_exists( 'dtb_ops_audit_log' ) ) {
		dtb_ops_audit_log( 'qbo_oauth_connected', [ 'environment' => dtb_qbo_environment(), 'realm_suffix' => substr( $realm_id, -4 ) ] );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=dtb-ops-quickbooks&qbo_connected=1' ) );
	exit;
}

function dtb_qbo_status(): array {
	$config  = dtb_qbo_config();
	$tokens  = dtb_qbo_load_tokens();
	$company = get_option( dtb_qbo_option_name( 'company' ), [] );
	return [
		'environment'            => dtb_qbo_environment(),
		'credentials_configured' => '' !== $config['client_id'] && '' !== $config['client_secret'],
		'connected'              => dtb_qbo_enabled(),
		'company_verified'       => is_array( $company ) && ! empty( $company['id'] ),
		'company_name'           => is_array( $company ) ? (string) ( $company['name'] ?? '' ) : '',
		'realm_suffix'           => '' !== $config['realm_id'] ? substr( $config['realm_id'], -4 ) : '',
		'token_expires_at'       => is_array( $tokens ) ? (int) ( $tokens['expires_at'] ?? 0 ) : 0,
		'redirect_uri'           => dtb_qbo_redirect_uri(),
	];
}

add_action( 'admin_menu', 'dtb_qbo_register_settings_page', 20 );
function dtb_qbo_register_settings_page(): void {
	add_submenu_page( 'dtb-ops', __( 'QuickBooks', 'dtb' ), __( 'QuickBooks', 'dtb' ), 'manage_options', 'dtb-ops-quickbooks', 'dtb_qbo_render_settings_page' );
}

function dtb_qbo_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Insufficient permissions.', 'dtb' ) );
	}
	if ( isset( $_GET['qbo_disconnect'] ) ) {
		check_admin_referer( 'dtb_qbo_disconnect' );
		dtb_qbo_clear_tokens();
	}
	$status   = dtb_qbo_status();
	$auth_url = dtb_qbo_get_auth_url();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'DTB Ops — QuickBooks Online', 'dtb' ); ?></h1>
		<?php if ( isset( $_GET['qbo_connected'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'QuickBooks connected and company access verified.', 'dtb' ); ?></p></div><?php endif; ?>
		<?php if ( isset( $_GET['qbo_error'] ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'QuickBooks connection failed. Review the registered redirect URI and development credentials, then reconnect.', 'dtb' ); ?></p></div><?php endif; ?>
		<table class="widefat striped" style="max-width:900px"><tbody>
		<tr><th><?php esc_html_e( 'Environment', 'dtb' ); ?></th><td><?php echo esc_html( ucfirst( $status['environment'] ) ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Credentials', 'dtb' ); ?></th><td><?php echo esc_html( $status['credentials_configured'] ? 'Configured' : 'Missing' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Connection', 'dtb' ); ?></th><td><?php echo esc_html( $status['connected'] ? 'Connected' : 'Not connected' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Company', 'dtb' ); ?></th><td><?php echo esc_html( $status['company_name'] ?: 'Not verified' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Realm', 'dtb' ); ?></th><td><?php echo esc_html( $status['realm_suffix'] ? '••••' . $status['realm_suffix'] : '—' ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Redirect URI', 'dtb' ); ?></th><td><code><?php echo esc_html( $status['redirect_uri'] ); ?></code></td></tr>
		</tbody></table>
		<p>
		<?php if ( $status['connected'] ) : ?>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=dtb-ops-quickbooks&qbo_disconnect=1' ), 'dtb_qbo_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'dtb' ); ?></a>
		<?php elseif ( $auth_url ) : ?>
			<a class="button button-primary" href="<?php echo esc_url( $auth_url ); ?>"><?php esc_html_e( 'Connect QuickBooks Sandbox', 'dtb' ); ?></a>
		<?php endif; ?>
		</p>
	</div>
	<?php
}

if ( function_exists( 'dtb_is_rest_api_request' ) && dtb_is_rest_api_request() ) {
	add_action( 'rest_api_init', 'dtb_qbo_register_rest_routes' );
}
function dtb_qbo_register_rest_routes(): void {
	register_rest_route( 'dtb/v1', '/qbo/status', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_qbo_rest_status',
		'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
	] );
	register_rest_route( 'dtb/v1', '/qbo/test-connection', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_qbo_rest_test_connection',
		'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
	] );
}
function dtb_qbo_rest_status(): WP_REST_Response {
	return new WP_REST_Response( dtb_qbo_status(), 200 );
}
function dtb_qbo_rest_test_connection(): WP_REST_Response|WP_Error {
	$result = dtb_qbo_verify_company();
	return is_wp_error( $result ) ? $result : new WP_REST_Response( [ 'ok' => true, 'company' => $result ], 200 );
}

function dtb_qbo_get_or_create_customer( WC_Order $order ): string|WP_Error {
	$user_id = (int) $order->get_user_id();
	$order_cache_key = '_dtb_qbo_customer_id_' . dtb_qbo_environment();
	$order_cached    = sanitize_text_field( (string) $order->get_meta( $order_cache_key, true ) );
	if ( '' !== $order_cached ) {
		return $order_cached;
	}
	if ( $user_id > 0 ) {
		$cached = (string) get_user_meta( $user_id, '_dtb_qbo_customer_id_' . dtb_qbo_environment(), true );
		if ( '' !== $cached ) {
			return $cached;
		}
	}
	$email = sanitize_email( $order->get_billing_email() );
	if ( '' === $email ) {
		return new WP_Error( 'qbo_customer_email_missing', 'A valid billing email is required for QuickBooks customer matching.', [ 'retryable' => false ] );
	}
	$safe_email = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $email );
	$query      = dtb_qbo_request( 'GET', '/query', [ 'query' => "SELECT * FROM Customer WHERE PrimaryEmailAddr = '{$safe_email}' MAXRESULTS 2" ] );
	if ( empty( $query['ok'] ) ) {
		return new WP_Error(
			'qbo_customer_lookup_failed',
			sanitize_text_field( (string) ( $query['error'] ?? 'QuickBooks customer lookup failed.' ) ),
			[ 'retryable' => (bool) ( $query['retryable'] ?? true ) ]
		);
	}
	$customers = (array) ( $query['data']['QueryResponse']['Customer'] ?? [] );
	if ( count( $customers ) > 1 ) {
		return new WP_Error( 'qbo_customer_ambiguous', 'Multiple QuickBooks customers use this billing email; accountant review is required.', [ 'retryable' => false ] );
	}
	$id = sanitize_text_field( (string) ( $customers[0]['Id'] ?? '' ) );
	if ( '' === $id ) {
		$name         = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$external     = $user_id > 0 ? 'DTB-C-' . $user_id : 'DTB-G-' . $order->get_id();
		$display_name = substr( ( $name ?: 'DTB Customer' ) . ' [' . $external . ']', 0, 100 );
		$safe_display = str_replace( [ '\\', "'" ], [ '\\\\', "\\'" ], $display_name );
		$by_display   = dtb_qbo_request( 'GET', '/query', [ 'query' => "SELECT * FROM Customer WHERE DisplayName = '{$safe_display}' MAXRESULTS 2" ] );
		if ( empty( $by_display['ok'] ) ) {
			return new WP_Error( 'qbo_customer_lookup_failed', sanitize_text_field( (string) ( $by_display['error'] ?? 'QuickBooks customer identity lookup failed.' ) ), [ 'retryable' => (bool) ( $by_display['retryable'] ?? true ) ] );
		}
		$display_rows = (array) ( $by_display['data']['QueryResponse']['Customer'] ?? [] );
		if ( count( $display_rows ) > 1 ) {
			return new WP_Error( 'qbo_customer_ambiguous', 'Multiple QuickBooks customers match the deterministic DTB customer identity.', [ 'retryable' => false ] );
		}
		$id = sanitize_text_field( (string) ( $display_rows[0]['Id'] ?? '' ) );
	}
	if ( '' === $id ) {
		$name         = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$external     = $user_id > 0 ? 'DTB-C-' . $user_id : 'DTB-G-' . $order->get_id();
		$display_name = substr( ( $name ?: 'DTB Customer' ) . ' [' . $external . ']', 0, 100 );
		$create = dtb_qbo_request( 'POST', '/customer', [], [
			'DisplayName'      => $display_name,
			'GivenName'        => sanitize_text_field( $order->get_billing_first_name() ),
			'FamilyName'       => sanitize_text_field( $order->get_billing_last_name() ),
			'PrimaryEmailAddr' => [ 'Address' => $email ],
		] );
		if ( empty( $create['ok'] ) ) {
			return new WP_Error(
				'qbo_customer_create_failed',
				sanitize_text_field( (string) ( $create['error'] ?? 'QuickBooks customer creation failed.' ) ),
				[ 'retryable' => (bool) ( $create['retryable'] ?? false ) ]
			);
		}
		$id = sanitize_text_field( (string) ( $create['data']['Customer']['Id'] ?? '' ) );
		if ( '' === $id ) {
			return new WP_Error( 'qbo_customer_invalid_success', 'QuickBooks did not return a customer ID.', [ 'retryable' => false ] );
		}
	}
	if ( $user_id > 0 ) {
		update_user_meta( $user_id, '_dtb_qbo_customer_id_' . dtb_qbo_environment(), $id );
	}
	$order->update_meta_data( $order_cache_key, $id );
	$order->save_meta_data();
	return $id;
}
