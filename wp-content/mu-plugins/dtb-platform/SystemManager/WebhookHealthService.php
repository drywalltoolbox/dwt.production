<?php
/**
 * DTB Platform — WebhookHealthService
 *
 * Surfaces registered WooCommerce webhooks and their delivery status.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_webhook_health_get(): array {
	$transient = get_transient( 'dtb_webhook_health' );
	if ( is_array( $transient ) ) {
		return $transient;
	}

	$webhooks = [];

	if ( function_exists( 'wc_get_webhooks' ) ) {
		foreach ( wc_get_webhooks( [ 'limit' => 50 ] ) as $webhook ) {
			$webhooks[] = [
				'id'            => $webhook->get_id(),
				'name'          => $webhook->get_name(),
				'status'        => $webhook->get_status(),
				'topic'         => $webhook->get_topic(),
				'delivery_url'  => $webhook->get_delivery_url(),
				'failure_count' => $webhook->get_failure_count(),
			];
		}
	}

	$data = [
		'total'    => count( $webhooks ),
		'active'   => count( array_filter( $webhooks, fn( $w ) => $w['status'] === 'active' ) ),
		'failing'  => count( array_filter( $webhooks, fn( $w ) => $w['failure_count'] > 0 ) ),
		'webhooks' => $webhooks,
	];

	set_transient( 'dtb_webhook_health', $data, 5 * MINUTE_IN_SECONDS );

	return $data;
}
