<?php
/**
 * QuickBooks operator and OAuth controller.
 *
 * Exposes the permanent administrator REST contract and owns the browser
 * callback for Intuit OAuth. No QuickBooks accounting writes live here.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QuickBooksOAuthController {
	private const REST_NAMESPACE    = 'dtb/v1';
	private const NOTICE_QUERY      = 'dtb_qbo_notice';
	private const STATE_TTL_SECONDS = 10 * MINUTE_IN_SECONDS;

	public static function register(): void {
		remove_action( 'admin_menu', 'dtb_qbo_register_settings_page', 20 );
		remove_action( 'wp_ajax_dtb_qbo_oauth_callback', 'dtb_qbo_handle_oauth_callback' );
		remove_action( 'rest_api_init', 'dtb_qbo_register_rest_routes' );

		add_action( 'wp_ajax_dtb_qbo_oauth_callback', [ self::class, 'handle_oauth_callback' ] );
		add_action( 'rest_api_init', [ self::class, 'register_rest_routes' ] );
		add_action( 'admin_notices', [ self::class, 'render_admin_notice' ] );
	}

	public static function register_rest_routes(): void {
		foreach ( [
			'/admin/qbo/status'     => [ WP_REST_Server::READABLE, 'rest_status' ],
			'/admin/qbo/connect'    => [ WP_REST_Server::CREATABLE, 'rest_connect' ],
			'/admin/qbo/test'       => [ WP_REST_Server::CREATABLE, 'rest_test' ],
			'/admin/qbo/disconnect' => [ WP_REST_Server::CREATABLE, 'rest_disconnect' ],
		] as $route => $definition ) {
			register_rest_route( self::REST_NAMESPACE, $route, [
				'methods'             => $definition[0],
				'callback'            => [ self::class, $definition[1] ],
				'permission_callback' => [ self::class, 'can_manage' ],
			] );
		}
	}

	public static function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function rest_status(): WP_REST_Response {
		$status                                = dtb_qbo_status();
		$status['webhook_verifier_configured'] = self::webhook_verifier_configured();
		$status['webhook_endpoint']            = rest_url( 'dtb/v1/webhooks/qbo' );
		$status['ready_for_connection']        = ! empty( $status['credentials_configured'] ) && $status['webhook_verifier_configured'];
		return new WP_REST_Response( $status, 200 );
	}

	public static function rest_connect(): WP_REST_Response|WP_Error {
		$status = dtb_qbo_status();
		if ( empty( $status['credentials_configured'] ) ) {
			return new WP_Error( 'qbo_credentials_missing', __( 'QuickBooks credentials are not configured for the active environment.', 'dtb' ), [ 'status' => 503 ] );
		}
		if ( ! self::webhook_verifier_configured() ) {
			return new WP_Error( 'qbo_webhook_verifier_missing', __( 'QuickBooks webhook verification is not configured for the active environment.', 'dtb' ), [ 'status' => 503 ] );
		}
		if ( ! empty( $status['connected'] ) ) {
			return new WP_Error( 'qbo_already_connected', __( 'QuickBooks is already connected for the active environment.', 'dtb' ), [ 'status' => 409 ] );
		}

		$authorization_url = self::create_authorization_url();
		if ( is_wp_error( $authorization_url ) ) {
			return $authorization_url;
		}

		return new WP_REST_Response( [
			'ok'                => true,
			'environment'       => dtb_qbo_environment(),
			'authorization_url' => $authorization_url,
			'redirect_uri'      => dtb_qbo_redirect_uri(),
		], 200 );
	}

	public static function rest_test(): WP_REST_Response|WP_Error {
		if ( ! dtb_qbo_enabled() ) {
			return new WP_Error( 'qbo_not_connected', __( 'QuickBooks is not connected for the active environment.', 'dtb' ), [ 'status' => 409 ] );
		}
		$result = dtb_qbo_verify_company();
		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 502 ] );
			return $result;
		}
		return new WP_REST_Response( [ 'ok' => true, 'company' => $result ], 200 );
	}

	public static function rest_disconnect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( true !== rest_sanitize_boolean( $request->get_param( 'confirm' ) ) ) {
			return new WP_Error( 'qbo_confirmation_required', __( 'Set confirm=true to disconnect QuickBooks.', 'dtb' ), [ 'status' => 400 ] );
		}
		$environment = dtb_qbo_environment();
		dtb_qbo_clear_tokens();
		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( 'qbo_disconnected', [ 'environment' => $environment ] );
		}
		return new WP_REST_Response( [ 'ok' => true, 'environment' => $environment, 'connected' => false ], 200 );
	}

	public static function handle_oauth_callback(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dtb' ), '', [ 'response' => 403 ] );
		}

		$state = sanitize_text_field( wp_unslash( $_GET['state'] ?? '' ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/D', $state ) ) {
			self::redirect_with_notice( 'invalid_state' );
		}

		$state_hash = hash( 'sha256', $state );
		$key        = 'dtb_qbo_oauth_' . $state_hash;
		$txn        = get_transient( $key );
		delete_transient( $key );

		$valid_state = is_array( $txn )
			&& isset( $txn['state_hash'] )
			&& hash_equals( (string) $txn['state_hash'], $state_hash )
			&& (int) ( $txn['user_id'] ?? 0 ) === get_current_user_id()
			&& (string) ( $txn['environment'] ?? '' ) === dtb_qbo_environment()
			&& (string) ( $txn['redirect_uri'] ?? '' ) === dtb_qbo_redirect_uri();

		if ( ! $valid_state ) {
			self::redirect_with_notice( 'invalid_state' );
		}
		if ( isset( $_GET['error'] ) ) {
			self::redirect_with_notice( 'authorization_denied' );
		}

		$code     = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) );
		$realm_id = preg_replace( '/[^0-9]/', '', (string) wp_unslash( $_GET['realmId'] ?? '' ) );
		if ( '' === $code || '' === $realm_id ) {
			self::redirect_with_notice( 'invalid_response' );
		}

		$tokens = dtb_qbo_token_request( [
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => dtb_qbo_redirect_uri(),
		] );
		if ( is_wp_error( $tokens ) ) {
			self::redirect_with_notice( 'token_exchange' );
		}

		$tokens['realm_id'] = $realm_id;
		if ( ! dtb_qbo_save_tokens( $tokens ) ) {
			dtb_qbo_clear_tokens();
			self::redirect_with_notice( 'token_storage' );
		}

		$company = dtb_qbo_verify_company();
		if ( is_wp_error( $company ) ) {
			dtb_qbo_clear_tokens();
			self::redirect_with_notice( 'company_verification' );
		}
		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( 'qbo_oauth_connected', [ 'environment' => dtb_qbo_environment(), 'realm_suffix' => substr( $realm_id, -4 ) ] );
		}

		// Orders paid/fulfilled while QuickBooks was disconnected were terminally
		// marked 'not_configured' (retryable: false — see OrderIntegrationContracts.php)
		// and nothing re-queues them on its own. Without this, every order placed
		// before the very moment credentials are entered stays permanently
		// unsynced. Queue a backfill pass now, at the one moment we know the
		// connection just became live. Uncapped by design here (default limit),
		// bounded per-run at the source in dtb_operational_pipeline_queue_qbo_sync_orders();
		// operators can also re-run this manually from the Workflow tab for orders
		// that arrive later than this single pass.
		if ( function_exists( 'dtb_operational_pipeline_queue_qbo_sync_orders' ) ) {
			$backfill = dtb_operational_pipeline_queue_qbo_sync_orders( 250, 'oauth_connected' );
			if ( function_exists( 'dtb_ops_audit_log' ) ) {
				dtb_ops_audit_log( 'qbo_oauth_connect_backfill', [
					'environment' => dtb_qbo_environment(),
					'queued'      => (int) ( $backfill['queued'] ?? 0 ),
					'skipped'     => (int) ( $backfill['skipped'] ?? 0 ),
				] );
			}
		}

		self::redirect_with_notice( 'connected' );
	}

	public static function render_admin_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$notice = sanitize_key( wp_unslash( $_GET[ self::NOTICE_QUERY ] ?? '' ) );
		if ( '' === $notice ) {
			return;
		}
		$class   = 'connected' === $notice ? 'notice-success' : 'notice-error';
		$message = 'connected' === $notice ? __( 'QuickBooks connected and the selected company was verified.', 'dtb' ) : __( 'QuickBooks connection failed. Verify the registered redirect URI and server-managed credentials for the active environment, then retry.', 'dtb' );
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	public static function get_authorization_url(): string {
		$authorization_url = self::create_authorization_url();
		return is_wp_error( $authorization_url ) ? '' : $authorization_url;
	}

	private static function create_authorization_url(): string|WP_Error {
		$config       = dtb_qbo_config();
		$redirect_uri = dtb_qbo_redirect_uri();
		$user_id      = get_current_user_id();

		if ( '' === (string) ( $config['client_id'] ?? '' ) || '' === (string) ( $config['client_secret'] ?? '' ) ) {
			return new WP_Error( 'qbo_credentials_missing', __( 'QuickBooks credentials are not configured for the active environment.', 'dtb' ), [ 'status' => 503 ] );
		}
		if ( $user_id <= 0 || ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'qbo_authorization_forbidden', __( 'QuickBooks authorization requires an authenticated administrator.', 'dtb' ), [ 'status' => 403 ] );
		}
		if ( ! wp_http_validate_url( $redirect_uri ) || 'https' !== strtolower( (string) wp_parse_url( $redirect_uri, PHP_URL_SCHEME ) ) ) {
			return new WP_Error( 'qbo_redirect_uri_invalid', __( 'QuickBooks authorization requires a valid HTTPS redirect URI.', 'dtb' ), [ 'status' => 503 ] );
		}

		try {
			$state = bin2hex( random_bytes( 32 ) );
		} catch ( Throwable $error ) {
			return new WP_Error( 'qbo_state_generation_failed', __( 'QuickBooks authorization could not be initialized securely.', 'dtb' ), [ 'status' => 503 ] );
		}

		$state_hash = hash( 'sha256', $state );
		$stored     = set_transient(
			'dtb_qbo_oauth_' . $state_hash,
			[
				'state_hash'   => $state_hash,
				'user_id'      => $user_id,
				'environment'  => dtb_qbo_environment(),
				'redirect_uri' => $redirect_uri,
				'issued_at'    => time(),
			],
			self::STATE_TTL_SECONDS
		);
		if ( ! $stored ) {
			return new WP_Error( 'qbo_state_store_failed', __( 'QuickBooks authorization state could not be stored.', 'dtb' ), [ 'status' => 503 ] );
		}

		$query = http_build_query(
			[
				'client_id'     => (string) $config['client_id'],
				'response_type' => 'code',
				'scope'         => DTB_QBO_SCOPE,
				'redirect_uri'  => $redirect_uri,
				'state'         => $state,
			],
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		$authorization_url = rtrim( DTB_QBO_AUTH_URL, '?' ) . '?' . $query;

		$parsed_query = (string) wp_parse_url( $authorization_url, PHP_URL_QUERY );
		parse_str( $parsed_query, $parsed );
		if (
			(string) ( $parsed['redirect_uri'] ?? '' ) !== $redirect_uri
			|| (string) ( $parsed['state'] ?? '' ) !== $state
			|| 'code' !== (string) ( $parsed['response_type'] ?? '' )
		) {
			delete_transient( 'dtb_qbo_oauth_' . $state_hash );
			return new WP_Error( 'qbo_authorization_url_invalid', __( 'QuickBooks authorization URL validation failed.', 'dtb' ), [ 'status' => 503 ] );
		}

		return $authorization_url;
	}

	private static function webhook_verifier_configured(): bool {
		return class_exists( 'DTB_QuickBooksWebhookController' ) && DTB_QuickBooksWebhookController::verifier_configured();
	}

	private static function redirect_with_notice( string $notice ): never {
		wp_safe_redirect( add_query_arg( self::NOTICE_QUERY, sanitize_key( $notice ), admin_url( 'index.php' ) ) );
		exit;
	}
}

DTB_QuickBooksOAuthController::register();

function dtb_integrations_qbo_auth_url(): string {
	return DTB_QuickBooksOAuthController::get_authorization_url();
}
