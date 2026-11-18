<?php
/**
 * QuickBooks webhook ingress and reconciliation queue.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_QuickBooksWebhookController {
	private const REST_NAMESPACE       = 'dtb/v1';
	private const ROUTE                = '/webhooks/qbo';
	private const QUEUE_HOOK           = 'dtb_qbo_process_webhook_event';
	private const QUEUE_GROUP          = 'dtb-orders';
	private const MAX_BODY_BYTES       = 2097152;
	private const DEDUPE_TTL           = 30 * DAY_IN_SECONDS;
	private const PROCESSING_LOCK_TTL  = 15 * MINUTE_IN_SECONDS;
	private const MAX_RETRY_ATTEMPTS   = 5;
	private const RETRY_DELAYS_SECONDS = [ 60, 300, 1800, 7200, 21600 ];

	public static function register(): void {
		add_action( 'rest_api_init', [ self::class, 'register_route' ] );
		add_action( self::QUEUE_HOOK, [ self::class, 'process_event' ], 10, 1 );
	}

	public static function register_route(): void {
		register_rest_route( self::REST_NAMESPACE, self::ROUTE, [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ self::class, 'receive' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function verifier_configured(): bool {
		return '' !== self::verifier_token();
	}

	public static function receive( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$verifier = self::verifier_token();
		if ( '' === $verifier ) {
			return new WP_Error( 'qbo_webhook_not_configured', 'QuickBooks webhook verification is not configured.', [ 'status' => 503 ] );
		}

		$body = $request->get_body();
		if ( '' === $body || strlen( $body ) > self::MAX_BODY_BYTES ) {
			return new WP_Error( 'qbo_webhook_invalid_body', 'Invalid QuickBooks webhook body.', [ 'status' => 400 ] );
		}

		$provided = trim( (string) $request->get_header( 'intuit-signature' ) );
		$expected = base64_encode( hash_hmac( 'sha256', $body, $verifier, true ) );
		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'qbo_webhook_invalid_signature', 'Invalid QuickBooks webhook signature.', [ 'status' => 401 ] );
		}

		$events = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $events ) || ! array_is_list( $events ) ) {
			return new WP_Error( 'qbo_webhook_invalid_payload', 'Invalid QuickBooks webhook payload.', [ 'status' => 400 ] );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'qbo_webhook_queue_unavailable', 'QuickBooks webhook queue is unavailable.', [ 'status' => 503 ] );
		}

		$accepted = 0;
		foreach ( $events as $event ) {
			$normalized = self::normalize_event( $event );
			if ( null === $normalized ) {
				continue;
			}
			$action_id = as_enqueue_async_action( self::QUEUE_HOOK, [ $normalized ], self::QUEUE_GROUP, true );
			if ( 0 !== (int) $action_id ) {
				++$accepted;
			}
		}

		return new WP_REST_Response( [ 'ok' => true, 'accepted' => $accepted ], 200 );
	}

	/**
	 * Constants take priority; falls back to the System Manager -> Integration
	 * Settings-configured `dtb_qbo_settings` option (see QuickBooksClient.php's
	 * dtb_qbo_settings_option()) when the environment-specific constant isn't defined.
	 */
	private static function verifier_token(): string {
		$environment = function_exists( 'dtb_qbo_environment' ) ? dtb_qbo_environment() : 'production';
		if ( 'sandbox' === $environment && defined( 'DTB_QBO_SANDBOX_WEBHOOK_VERIFIER_TOKEN' ) ) {
			return trim( (string) DTB_QBO_SANDBOX_WEBHOOK_VERIFIER_TOKEN );
		}
		if ( 'production' === $environment && defined( 'DTB_QBO_PRODUCTION_WEBHOOK_VERIFIER_TOKEN' ) ) {
			return trim( (string) DTB_QBO_PRODUCTION_WEBHOOK_VERIFIER_TOKEN );
		}
		if ( defined( 'DTB_QBO_WEBHOOK_VERIFIER_TOKEN' ) ) {
			return trim( (string) DTB_QBO_WEBHOOK_VERIFIER_TOKEN );
		}

		$stored = function_exists( 'dtb_qbo_settings_option' ) ? dtb_qbo_settings_option() : [];
		$field  = 'sandbox' === $environment ? 'sandbox_webhook_verifier_token' : 'production_webhook_verifier_token';
		return trim( (string) ( $stored[ $field ] ?? '' ) );
	}

	private static function normalize_event( mixed $event ): ?array {
		if ( ! is_array( $event ) || '1.0' !== (string) ( $event['specversion'] ?? '' ) ) {
			return null;
		}
		$id       = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
		$type     = strtolower( sanitize_text_field( (string) ( $event['type'] ?? '' ) ) );
		$entity   = sanitize_text_field( (string) ( $event['intuitentityid'] ?? '' ) );
		$realm    = preg_replace( '/[^0-9]/', '', (string) ( $event['intuitaccountid'] ?? '' ) );
		$occurred = sanitize_text_field( (string) ( $event['time'] ?? '' ) );

		if ( '' === $id || '' === $entity || '' === $realm ) {
			return null;
		}
		if ( ! preg_match( '/^qbo\.(salesreceipt|refundreceipt)\.(created|updated|voided|deleted)\.v[0-9]+$/', $type ) ) {
			return null;
		}

		return [
			'id'          => substr( $id, 0, 191 ),
			'type'        => $type,
			'entity_id'   => substr( $entity, 0, 64 ),
			'realm_id'    => $realm,
			'occurred_at' => substr( $occurred, 0, 64 ),
			'environment' => function_exists( 'dtb_qbo_environment' ) ? dtb_qbo_environment() : '',
			'attempt'     => 0,
		];
	}

	public static function process_event( array $event ): void {
		$event_id = sanitize_text_field( (string) ( $event['id'] ?? '' ) );
		if ( '' === $event_id ) {
			return;
		}

		$dedupe_key = 'dtb_qbo_webhook_done_' . hash( 'sha256', $event_id );
		if ( false !== get_transient( $dedupe_key ) ) {
			return;
		}

		$lock_key = 'dtb_qbo_webhook_lock_' . hash( 'sha256', $event_id );
		if ( ! self::acquire_processing_lock( $lock_key ) ) {
			return;
		}

		try {
			if ( self::dispatch_event( $event ) ) {
				set_transient( $dedupe_key, 1, self::DEDUPE_TTL );
				return;
			}
			self::schedule_retry( $event );
		} finally {
			delete_option( $lock_key );
		}
	}

	private static function acquire_processing_lock( string $lock_key ): bool {
		if ( add_option( $lock_key, time(), '', false ) ) {
			return true;
		}
		$locked_at = (int) get_option( $lock_key, 0 );
		if ( $locked_at > 0 && $locked_at < time() - self::PROCESSING_LOCK_TTL ) {
			delete_option( $lock_key );
			return add_option( $lock_key, time(), '', false );
		}
		return false;
	}

	private static function dispatch_event( array $event ): bool {
		if ( (string) ( $event['environment'] ?? '' ) !== dtb_qbo_environment() ) {
			self::audit( 'qbo_webhook_environment_ignored', $event );
			return true;
		}

		$config = function_exists( 'dtb_qbo_config' ) ? dtb_qbo_config() : [];
		if ( (string) ( $config['realm_id'] ?? '' ) !== (string) ( $event['realm_id'] ?? '' ) ) {
			self::audit( 'qbo_webhook_realm_ignored', $event );
			return true;
		}

		$type = (string) ( $event['type'] ?? '' );
		preg_match( '/^qbo\.(salesreceipt|refundreceipt)\.(created|updated|voided|deleted)\./', $type, $matches );
		$entity    = $matches[1] ?? '';
		$operation = $matches[2] ?? '';
		if ( '' === $entity || '' === $operation ) {
			return true;
		}

		if ( 'salesreceipt' === $entity ) {
			return self::reconcile_sales_receipt( (string) $event['entity_id'], $operation, $event );
		}
		self::audit( 'qbo_refund_webhook_observed', $event + [ 'operation' => $operation ] );
		return true;
	}

	private static function reconcile_sales_receipt( string $entity_id, string $operation, array $event ): bool {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			self::audit( 'qbo_webhook_woocommerce_unavailable', $event );
			return false;
		}
		$orders = wc_get_orders( [
			'limit'      => 2,
			'return'     => 'objects',
			'meta_key'   => '_dtb_quickbooks_entity_id',
			'meta_value' => $entity_id,
		] );
		if ( 1 !== count( $orders ) || ! $orders[0] instanceof WC_Order ) {
			$ambiguous = count( $orders ) > 1;
			self::audit(
				$ambiguous ? 'qbo_sales_receipt_webhook_ambiguous_ignored' : 'qbo_sales_receipt_webhook_unmapped_ignored',
				$event + [ 'operation' => $ambiguous ? 'ambiguous_ignored' : 'unmapped_ignored' ]
			);
			// There is no safe order identity to reconcile. Acknowledge and retain
			// the audit record instead of retrying an event the worker cannot map.
			return true;
		}
		$order = $orders[0];
		$order->update_meta_data( '_dtb_qbo_last_webhook_event_id', sanitize_text_field( (string) $event['id'] ) );
		$order->update_meta_data( '_dtb_qbo_last_webhook_operation', sanitize_key( $operation ) );
		$order->update_meta_data( '_dtb_qbo_last_webhook_at', sanitize_text_field( (string) ( $event['occurred_at'] ?? '' ) ) );
		if ( in_array( $operation, [ 'updated', 'voided', 'deleted' ], true ) ) {
			$order->update_meta_data( '_dtb_qbo_reconciliation_state', 'external_' . sanitize_key( $operation ) );
		}
		$order->save_meta_data();
		self::audit( 'qbo_sales_receipt_webhook_reconciled', $event + [ 'order_id' => $order->get_id(), 'operation' => $operation ] );
		return true;
	}

	private static function schedule_retry( array $event ): void {
		$attempt = max( 0, absint( $event['attempt'] ?? 0 ) );
		if ( $attempt >= self::MAX_RETRY_ATTEMPTS ) {
			self::audit( 'qbo_webhook_retries_exhausted', $event + [ 'operation' => 'failed' ] );
			throw new RuntimeException( 'QuickBooks webhook reconciliation retries exhausted.' );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			throw new RuntimeException( 'QuickBooks webhook retry queue is unavailable.' );
		}
		$event['attempt'] = $attempt + 1;
		$delay            = self::RETRY_DELAYS_SECONDS[ $attempt ] ?? self::RETRY_DELAYS_SECONDS[ self::MAX_RETRY_ATTEMPTS - 1 ];
		$action_id        = as_schedule_single_action( time() + $delay, self::QUEUE_HOOK, [ $event ], self::QUEUE_GROUP, true );
		if ( 0 === (int) $action_id ) {
			throw new RuntimeException( 'QuickBooks webhook retry could not be scheduled.' );
		}
		self::audit( 'qbo_webhook_retry_scheduled', $event + [ 'operation' => 'retry' ] );
	}

	private static function audit( string $event, array $context ): void {
		$context = [
			'event_id'     => sanitize_text_field( (string) ( $context['id'] ?? '' ) ),
			'entity_id'    => sanitize_text_field( (string) ( $context['entity_id'] ?? '' ) ),
			'realm_suffix' => substr( preg_replace( '/[^0-9]/', '', (string) ( $context['realm_id'] ?? '' ) ), -4 ),
			'operation'    => sanitize_key( (string) ( $context['operation'] ?? '' ) ),
			'order_id'     => absint( $context['order_id'] ?? 0 ),
			'attempt'      => absint( $context['attempt'] ?? 0 ),
		];
		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( $event, $context );
		}
	}
}

DTB_QuickBooksWebhookController::register();
