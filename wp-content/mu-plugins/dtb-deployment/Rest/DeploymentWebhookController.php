<?php
/**
 * Rest — DeploymentWebhookController
 *
 * Receives signed release lifecycle events from
 * scripts/deployment/report-release-event.sh (called throughout
 * .github/workflows/release-siteground.yml) and records them as the
 * authoritative, auditable release event log the Deployment Center reads.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_DeploymentWebhookController {
	private const REST_NAMESPACE = 'dtb/v1';
	private const ROUTE          = '/deployment/webhook';
	private const MAX_BODY_BYTES = 65536;

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_route' ] );
	}

	public static function register_route(): void {
		register_rest_route( self::REST_NAMESPACE, self::ROUTE, [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'receive' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( ! dtb_deployment_webhook_configured() ) {
			return new WP_Error( 'dtb_deployment_webhook_not_configured', 'Deployment webhook verification is not configured.', [ 'status' => 503 ] );
		}

		$body = $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'dtb_deployment_webhook_invalid_body', 'Invalid deployment webhook body.', [ 'status' => 400 ] );
		}

		$signature = (string) $request->get_header( 'x-dtb-signature' );
		if ( ! dtb_deployment_webhook_verify_signature( $body, $signature ) ) {
			dtb_security_log( 'deployment_webhook_invalid_signature', [] );
			return new WP_Error( 'dtb_deployment_webhook_invalid_signature', 'Invalid deployment webhook signature.', [ 'status' => 401 ] );
		}

		$payload = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			return new WP_Error( 'dtb_deployment_webhook_invalid_payload', 'Invalid deployment webhook payload.', [ 'status' => 400 ] );
		}

		$release_id = sanitize_text_field( (string) ( $payload['release_id'] ?? '' ) );
		$event_type = sanitize_key( (string) ( $payload['event_type'] ?? '' ) );

		if ( '' === $release_id || ! in_array( $event_type, dtb_release_known_event_types(), true ) ) {
			return new WP_Error( 'dtb_deployment_webhook_invalid_event', 'Unknown or missing release event.', [ 'status' => 400 ] );
		}

		$context = self::allowlisted_context( $payload );
		$recorded = dtb_release_event_record( $release_id, $event_type, $context );

		if ( null === $recorded ) {
			return new WP_Error( 'dtb_deployment_webhook_storage_failed', 'Failed to record release event.', [ 'status' => 503 ] );
		}

		if ( in_array( $event_type, dtb_release_deployed_event_types(), true ) ) {
			self::purge_caches_after_deploy();
		}

		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( 'deployment_' . $event_type, [ 'release_id' => $release_id ] );
		}

		return new WP_REST_Response( [ 'ok' => true, 'recorded' => $recorded ], 200 );
	}

	/**
	 * Extract and sanitize only the recognised metadata fields the release
	 * workflow sends, rather than persisting the full, unbounded request
	 * payload verbatim.
	 *
	 * @param array<string,mixed> $payload Decoded, signature-verified webhook body.
	 * @return array<string,string>
	 */
	private static function allowlisted_context( array $payload ): array {
		$allowed_keys = [
			'ref_type',
			'ref',
			'commit_sha',
			'actor',
			'workflow_run_id',
			'workflow_run_url',
			'backup_ref',
			'restored_ref',
			'deploy_commit',
			'restoring_release_id',
			'restored_release_id',
		];

		$context = [];
		foreach ( $allowed_keys as $key ) {
			if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
				$context[ $key ] = sanitize_text_field( (string) $payload[ $key ] );
			}
		}
		$context['source'] = 'github_actions';

		return $context;
	}

	/**
	 * Clear PHP OPcache and the SiteGround Dynamic Cache after a successful
	 * deploy/rollback so newly-pushed mu-plugin/theme code takes effect
	 * immediately instead of waiting for natural cache expiry.
	 */
	private static function purge_caches_after_deploy(): void {
		if ( ! class_exists( 'DTB_CacheOperationsService' ) ) {
			return;
		}

		try {
			DTB_CacheOperationsService::run( [ 'opcache', 'page_cache' ] );
		} catch ( \Throwable $e ) {
			error_log( '[DTB Deployment] Post-deploy cache purge failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

DTB_DeploymentWebhookController::register();
