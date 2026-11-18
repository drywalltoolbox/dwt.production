<?php
defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_operational_pipeline_register_veeqo_webhook_route', 20 );
add_action( 'dtb_order_process_veeqo_webhook', 'dtb_operational_pipeline_process_veeqo_webhook', 10, 2 );

function dtb_operational_pipeline_register_veeqo_webhook_route(): void {
	register_rest_route( 'dtb/v1', '/veeqo/webhooks/order', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_operational_pipeline_veeqo_webhook_order',
		'permission_callback' => '__return_true',
	], true );
}

function dtb_operational_pipeline_veeqo_response( string $code, string $message, int $status = 200, array $extra = [] ): WP_REST_Response {
	return new WP_REST_Response( array_merge( [
		'success' => $status >= 200 && $status < 300,
		'code'    => sanitize_key( $code ),
		'message' => $message,
	], $extra ), $status );
}

function dtb_operational_pipeline_validate_veeqo_webhook_signature( WP_REST_Request $request ): ?WP_Error {
	$cfg       = function_exists( 'dtb_veeqo_config' ) ? dtb_veeqo_config() : [];
	$secret    = (string) ( $cfg['webhook_secret'] ?? '' );
	$raw_body  = $request->get_body();
	$signature = trim( (string) ( $request->get_header( 'x_veeqo_signature' ) ?: $request->get_header( 'x-veeqo-signature' ) ) );
	$timestamp = trim( (string) ( $request->get_header( 'x-veeqo-timestamp' ) ?: $request->get_header( 'x-veeqo-webhook-timestamp' ) ) );

	if ( '' === $secret ) {
		return new WP_Error( 'webhook_not_configured', 'Veeqo webhook verification is not configured.', [ 'status' => 503 ] );
	}
	if ( '' === $signature || '' === $timestamp || ! ctype_digit( $timestamp ) ) {
		return new WP_Error( 'invalid_webhook_headers', 'Webhook signature and timestamp are required.', [ 'status' => 401 ] );
	}
	if ( abs( time() - (int) $timestamp ) > 300 ) {
		return new WP_Error( 'stale_webhook', 'Webhook timestamp is outside the replay window.', [ 'status' => 401 ] );
	}

	$signature = preg_replace( '/^sha256=/i', '', $signature );
	$expected_raw = hash_hmac( 'sha256', $raw_body, $secret );
	$expected_v1  = hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
	if ( ! hash_equals( strtolower( $expected_raw ), strtolower( (string) $signature ) ) && ! hash_equals( strtolower( $expected_v1 ), strtolower( (string) $signature ) ) ) {
		return new WP_Error( 'invalid_signature', 'Webhook signature mismatch.', [ 'status' => 401 ] );
	}

	return null;
}

function dtb_operational_pipeline_sanitize_webhook_value( $value, int $depth = 0 ) {
	if ( $depth > 4 ) {
		return null;
	}
	if ( is_array( $value ) ) {
		$safe = [];
		foreach ( $value as $key => $child ) {
			$key = sanitize_key( (string) $key );
			if ( preg_match( '/(secret|token|password|authorization|api.?key)/i', $key ) ) {
				continue;
			}
			$safe[ $key ] = dtb_operational_pipeline_sanitize_webhook_value( $child, $depth + 1 );
		}
		return $safe;
	}
	if ( is_scalar( $value ) || null === $value ) {
		return is_string( $value ) ? substr( sanitize_text_field( $value ), 0, 2000 ) : $value;
	}
	return null;
}

function dtb_operational_pipeline_veeqo_order_by_id( int $veeqo_order_id ): ?WC_Order {
	if ( $veeqo_order_id <= 0 || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}
	$orders = wc_get_orders( [
		'limit'      => 1,
		'return'     => 'objects',
		'meta_query' => [
			'relation' => 'OR',
			[ 'key' => '_dtb_veeqo_order_id', 'value' => $veeqo_order_id, 'compare' => '=' ],
			[ 'key' => '_veeqo_order_id', 'value' => $veeqo_order_id, 'compare' => '=' ],
		],
	] );
	return ! empty( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
}

function dtb_operational_pipeline_find_order_by_order_number( string $order_number ): ?WC_Order {
	if ( '' === $order_number || ! function_exists( 'wc_get_orders' ) ) {
		return null;
	}
	$orders = wc_get_orders( [
		'limit'      => 1,
		'return'     => 'objects',
		'meta_query' => [ [ 'key' => '_dtb_veeqo_correlation_key', 'value' => $order_number, 'compare' => '=' ] ],
	] );
	if ( ! empty( $orders[0] ) && $orders[0] instanceof WC_Order ) {
		return $orders[0];
	}
	$orders = wc_get_orders( [ 'order_number' => $order_number, 'limit' => 1, 'return' => 'objects' ] );
	return ! empty( $orders[0] ) && $orders[0] instanceof WC_Order ? $orders[0] : null;
}

function dtb_operational_pipeline_extract_veeqo_tracking( array $payload ): array {
	return [
		'tracking_number'  => sanitize_text_field( (string) ( $payload['tracking_number'] ?? ( $payload['shipments'][0]['tracking_number'] ?? '' ) ) ),
		'tracking_carrier' => sanitize_text_field( (string) ( $payload['carrier'] ?? $payload['tracking_carrier'] ?? ( $payload['shipments'][0]['tracking_carrier'] ?? '' ) ) ),
	];
}

function dtb_operational_pipeline_veeqo_webhook_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$signature_error = dtb_operational_pipeline_validate_veeqo_webhook_signature( $request );
	if ( $signature_error instanceof WP_Error ) {
		return $signature_error;
	}

	$payload = $request->get_json_params();
	if ( ! is_array( $payload ) || empty( $payload ) ) {
		return dtb_operational_pipeline_veeqo_response( 'invalid_body', 'Empty or invalid JSON payload.', 400 );
	}

	$timestamp     = (int) $request->get_header( 'x-veeqo-timestamp' );
	$event_id      = sanitize_text_field( (string) ( $request->get_header( 'x-veeqo-event-id' ) ?: $payload['event_id'] ?? '' ) );
	$event_hash    = hash( 'sha256', $request->get_body() . '|' . $timestamp . '|' . $event_id );
	$ingress_key   = 'dtb_veeqo_webhook_ingress_' . $event_hash;
	$veeqo_order_id = absint( $payload['id'] ?? $payload['order_id'] ?? 0 );
	$order_number  = sanitize_text_field( (string) ( $payload['channel_order_number'] ?? $payload['number'] ?? '' ) );
	$order         = dtb_operational_pipeline_veeqo_order_by_id( $veeqo_order_id );
	if ( ! $order instanceof WC_Order ) {
		$order = dtb_operational_pipeline_find_order_by_order_number( $order_number );
	}
	$order_id = $order instanceof WC_Order ? (int) $order->get_id() : 0;

	$record = [
		'event_hash'      => $event_hash,
		'event_id'        => $event_id,
		'veeqo_order_id'  => $veeqo_order_id,
		'order_number'    => $order_number,
		'order_id'        => $order_id,
		'received_at'     => gmdate( 'c' ),
		'timestamp'       => $timestamp,
		'status'          => $order_id > 0 ? 'queued' : 'quarantined',
		'payload'         => dtb_operational_pipeline_sanitize_webhook_value( $payload ),
	];
	if ( ! add_option( $ingress_key, $record, '', 'no' ) ) {
		return dtb_operational_pipeline_veeqo_response( 'duplicate_webhook', 'Webhook was already accepted.', 202, [ 'event_hash' => $event_hash, 'order_id' => $order_id ?: null ] );
	}

	if ( $order_id <= 0 ) {
		if ( function_exists( 'dtb_veeqo_log' ) ) {
			dtb_veeqo_log( 'warn', 'webhook_quarantined', 'Veeqo webhook stored because its WooCommerce order could not be resolved.', [ 'event_hash' => $event_hash, 'veeqo_order_id' => $veeqo_order_id, 'order_number' => $order_number ] );
		}
		return dtb_operational_pipeline_veeqo_response( 'webhook_quarantined', 'Webhook accepted for operator reconciliation.', 202, [ 'event_hash' => $event_hash ] );
	}

	$job = function_exists( 'dtb_order_enqueue_job' )
		? dtb_order_enqueue_job( 'dtb_order_process_veeqo_webhook', $order_id, [ 'ingress_hash' => $event_hash ] )
		: false;
	if ( false === $job ) {
		$record['status'] = 'queue_failed';
		update_option( $ingress_key, $record, false );
		return new WP_Error( 'webhook_queue_unavailable', 'Webhook processor queue is unavailable.', [ 'status' => 503 ] );
	}

	return dtb_operational_pipeline_veeqo_response( 'webhook_accepted', 'Webhook accepted for asynchronous processing.', 202, [ 'event_hash' => $event_hash, 'order_id' => $order_id, 'job_id' => $job ] );
}

/**
 * Process a queued, signature-verified webhook ingress record.
 *
 * This function owns the webhook-specific concerns only: resolving the
 * queued ingress record, claiming it for processing, and recording the
 * outcome back onto that record. The actual "given a known Veeqo status,
 * apply it to the WooCommerce order" logic — status→WC-status mapping, rank
 * comparison, tracking meta, order event/notification — is the single shared
 * implementation in Veeqo/VeeqoOrderStatusApplier.php, also used by the
 * VeeqoOrderStatusPoller.php polling job. This keeps the mapping/apply logic
 * defined exactly once even though this route stays fail-closed (see
 * VeeqoRuntimePolicy.php) since Veeqo never actually calls it.
 */
function dtb_operational_pipeline_process_veeqo_webhook( int $order_id, array $args = [] ): void {
	$event_hash = sanitize_key( (string) ( $args['ingress_hash'] ?? '' ) );
	if ( '' === $event_hash ) {
		return;
	}
	$ingress_key = 'dtb_veeqo_webhook_ingress_' . $event_hash;
	$record      = get_option( $ingress_key, [] );
	if ( ! is_array( $record ) || 'done' === (string) ( $record['status'] ?? '' ) ) {
		return;
	}
	$claim_key = $ingress_key . '_processing';
	if ( ! add_option( $claim_key, gmdate( 'c' ), '', 'no' ) ) {
		return;
	}

	try {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$record['status'] = 'quarantined';
			update_option( $ingress_key, $record, false );
			return;
		}
		$payload         = is_array( $record['payload'] ?? null ) ? $record['payload'] : [];
		$veeqo_status    = sanitize_key( (string) ( $payload['status'] ?? '' ) );
		$tracking        = dtb_operational_pipeline_extract_veeqo_tracking( $payload );
		$veeqo_order_id  = absint( $record['veeqo_order_id'] ?? 0 );
		$correlation_key = ( ! empty( $record['order_number'] ) && str_starts_with( (string) $record['order_number'], 'veeqo-order:' ) )
			? sanitize_text_field( (string) $record['order_number'] )
			: '';

		$result = function_exists( 'dtb_veeqo_apply_order_fulfillment_status' )
			? dtb_veeqo_apply_order_fulfillment_status( $order, $veeqo_status, [
				'source'           => 'webhook',
				'reference'        => $event_hash,
				'veeqo_order_id'   => $veeqo_order_id,
				'tracking_number'  => $tracking['tracking_number'],
				'tracking_carrier' => $tracking['tracking_carrier'],
				'correlation_key'  => $correlation_key,
			] )
			: [ 'applied' => false, 'result' => 'unmapped_status' ];

		$record['status'] = 'unpaid_order' === ( $result['result'] ?? '' ) ? 'quarantined' : 'done';
		$record['result'] = $result['result'] ?? 'unknown';
		update_option( $ingress_key, $record, false );

		if ( ! empty( $result['applied'] ) && function_exists( 'dtb_veeqo_log_sync_timestamp' ) ) {
			dtb_veeqo_log_sync_timestamp( 'order_webhook' );
		}
	} finally {
		delete_option( $claim_key );
	}
}
