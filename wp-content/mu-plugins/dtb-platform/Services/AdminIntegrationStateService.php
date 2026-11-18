<?php
/**
 * DTB Platform — AdminIntegrationStateService
 *
 * Canonical integration-state facade for admin workbench payloads.
 * Rewards are intentionally omitted from launch integration state.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_admin_get_integration_state( string $module, int $record_id, array $context = [] ): array {
	$module = sanitize_key( $module );

	switch ( $module ) {
		case 'order':
		case 'product_order':
		case 'repair_order':
			return dtb_admin_integration_state_for_order( $record_id );
		case 'repair':
			return dtb_admin_integration_state_for_repair( $record_id );
		case 'returns':
		case 'return':
			return dtb_admin_integration_state_for_return( $record_id, $context );
		case 'support':
			return dtb_admin_integration_state_for_support( $record_id, $context );
		default:
			return dtb_admin_integration_state_defaults();
	}
}

function dtb_admin_integration_state_defaults(): array {
	return [
		'woocommerce'   => [ 'status' => 'unknown', 'label' => __( 'WooCommerce', 'drywall-toolbox' ), 'last_checked_at' => gmdate( 'c' ) ],
		'veeqo'         => [ 'status' => 'unknown', 'label' => __( 'Veeqo', 'drywall-toolbox' ), 'last_checked_at' => gmdate( 'c' ) ],
		'quickbooks'    => [ 'status' => 'unknown', 'label' => __( 'QuickBooks', 'drywall-toolbox' ), 'last_checked_at' => gmdate( 'c' ) ],
		'notifications' => [ 'status' => 'unknown', 'label' => __( 'Notifications', 'drywall-toolbox' ), 'last_checked_at' => gmdate( 'c' ) ],
		'shipment'      => [ 'status' => 'unknown', 'label' => __( 'Shipment', 'drywall-toolbox' ), 'last_checked_at' => gmdate( 'c' ) ],
		'webhooks'      => [ 'status' => 'unknown', 'label' => __( 'Webhooks', 'drywall-toolbox' ), 'last_checked_at' => gmdate( 'c' ) ],
	];
}

function dtb_admin_normalize_integration_slice( array $slice, string $label ): array {
	$status = sanitize_key( (string) ( $slice['status'] ?? $slice['state'] ?? 'unknown' ) );
	if ( '' === $status ) {
		$status = 'unknown';
	}

	return array_merge(
		[
			'status'          => $status,
			'label'           => $label,
			'last_checked_at' => gmdate( 'c' ),
			'last_success_at' => $slice['last_success_at'] ?? $slice['updated_at'] ?? null,
			'last_error'      => $slice['last_error'] ?? $slice['error'] ?? $slice['last_error_code'] ?? null,
		],
		$slice
	);
}

function dtb_admin_integration_state_for_order( int $order_id ): array {
	$state = dtb_admin_integration_state_defaults();
	$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( ! $order instanceof WC_Order ) {
		$state['woocommerce'] = dtb_admin_normalize_integration_slice( [ 'status' => 'orphaned', 'order_id' => $order_id ], __( 'WooCommerce', 'drywall-toolbox' ) );
		return $state;
	}

	$stored = function_exists( 'dtb_order_get_integration_state' )
		? dtb_order_get_integration_state( $order_id )
		: (array) get_post_meta( $order_id, '_dtb_integration_state', true );

	$veeqo_order_id = $stored['veeqo']['order_id']
		?? $order->get_meta( '_dtb_veeqo_order_id', true )
		?: $order->get_meta( '_veeqo_order_id', true );
	$veeqo_tracking = $stored['veeqo']['tracking']
		?? $order->get_meta( '_dtb_veeqo_tracking', true )
		?: $order->get_meta( '_tracking_number', true );
	$veeqo_status   = $stored['veeqo']['status']
		?? $order->get_meta( '_dtb_veeqo_sync_status', true )
		?: ( $veeqo_order_id ? 'synced' : 'pending' );

	$qbo_entity_id = $stored['quickbooks']['entity_id']
		?? $order->get_meta( '_dtb_quickbooks_entity_id', true )
		?: ( $order->get_meta( '_dtb_qbo_receipt_id', true ) ?: $order->get_meta( '_dtb_quickbooks_invoice_id', true ) );
	$qbo_status    = $stored['quickbooks']['status']
		?? $order->get_meta( '_dtb_quickbooks_sync_status', true )
		?: ( $qbo_entity_id ? 'synced' : 'pending' );

	$state['woocommerce'] = dtb_admin_normalize_integration_slice(
		[
			'status'    => 'verified',
			'order_id'  => $order_id,
			'order_url' => (string) get_edit_post_link( $order_id ),
		],
		__( 'WooCommerce', 'drywall-toolbox' )
	);
	$state['veeqo']       = dtb_admin_normalize_integration_slice(
		array_merge(
			(array) ( $stored['veeqo'] ?? [] ),
			[
				'status'      => $veeqo_status,
				'order_id'    => $veeqo_order_id,
				'tracking'    => $veeqo_tracking,
				'last_error'  => $stored['veeqo']['error'] ?? $order->get_meta( '_dtb_veeqo_sync_error', true ) ?: null,
				'sync_status' => $order->get_meta( '_dtb_veeqo_sync_status', true ) ?: $veeqo_status,
			]
		),
		__( 'Veeqo', 'drywall-toolbox' )
	);
	$state['quickbooks']  = dtb_admin_normalize_integration_slice(
		array_merge(
			(array) ( $stored['quickbooks'] ?? [] ),
			[
				'status'      => $qbo_status,
				'entity_id'   => $qbo_entity_id,
				'entity_type' => $stored['quickbooks']['entity_type'] ?? $order->get_meta( '_dtb_quickbooks_entity_type', true ) ?: null,
				'last_error'  => $stored['quickbooks']['error'] ?? $order->get_meta( '_dtb_quickbooks_sync_error', true ) ?: null,
				'sync_status' => $order->get_meta( '_dtb_quickbooks_sync_status', true ) ?: $qbo_status,
			]
		),
		__( 'QuickBooks', 'drywall-toolbox' )
	);
	$state['notifications'] = dtb_admin_normalize_integration_slice( [ 'status' => empty( $stored['notifications'] ) ? 'none' : 'available', 'items' => $stored['notifications'] ?? [] ], __( 'Notifications', 'drywall-toolbox' ) );
	$state['shipment']      = dtb_admin_normalize_integration_slice( [ 'status' => $state['veeqo']['tracking'] ? 'tracking_available' : 'pending', 'tracking' => $state['veeqo']['tracking'] ?? null ], __( 'Shipment', 'drywall-toolbox' ) );

	return $state;
}

function dtb_admin_integration_state_for_repair( int $repair_id ): array {
	$state    = dtb_admin_integration_state_defaults();
	$raw      = function_exists( 'dtb_get_repair_integration_state' ) ? dtb_get_repair_integration_state( $repair_id ) : [];
	$order_id = absint( get_post_meta( $repair_id, '_repair_wc_order_id', true ) );
	if ( ! $order_id ) {
		$order_id = absint( get_post_meta( $repair_id, '_repair_order_id', true ) );
	}

	$state['woocommerce'] = dtb_admin_normalize_integration_slice(
		array_merge( (array) ( $raw['woocommerce'] ?? [] ), [ 'order_id' => $order_id ?: ( $raw['woocommerce']['order_id'] ?? null ) ] ),
		__( 'WooCommerce', 'drywall-toolbox' )
	);
	$state['veeqo']       = dtb_admin_normalize_integration_slice(
		array_merge(
			(array) ( $raw['veeqo'] ?? [] ),
			[
				'order_id' => get_post_meta( $repair_id, '_repair_veeqo_order_id', true ),
				'tracking' => get_post_meta( $repair_id, '_repair_veeqo_tracking', true ),
			]
		),
		__( 'Veeqo', 'drywall-toolbox' )
	);
	$state['quickbooks']  = dtb_admin_normalize_integration_slice( (array) ( $raw['quickbooks'] ?? [] ), __( 'QuickBooks', 'drywall-toolbox' ) );
	$state['shipment']    = dtb_admin_normalize_integration_slice( [ 'status' => $state['veeqo']['tracking'] ? 'tracking_available' : 'pending', 'tracking' => $state['veeqo']['tracking'] ?? null ], __( 'Shipment', 'drywall-toolbox' ) );

	return $state;
}

function dtb_admin_integration_state_for_return( int $return_id, array $context = [] ): array {
	$state    = dtb_admin_integration_state_defaults();
	$order_id = absint( $context['order_id'] ?? 0 );
	if ( ! $order_id && function_exists( 'dtb_returns_get' ) ) {
		$return   = dtb_returns_get( $return_id );
		$order_id = $return ? absint( $return->order_id ?? 0 ) : 0;
	}

	if ( $order_id ) {
		$order_state = dtb_admin_integration_state_for_order( $order_id );
		$state       = array_replace_recursive( $state, $order_state );
	} else {
		$state['woocommerce'] = dtb_admin_normalize_integration_slice( [ 'status' => 'not_linked' ], __( 'WooCommerce', 'drywall-toolbox' ) );
	}

	$state['notifications'] = dtb_admin_normalize_integration_slice( [ 'status' => 'manual' ], __( 'Notifications', 'drywall-toolbox' ) );

	return $state;
}

function dtb_admin_integration_state_for_support( int $ticket_id, array $context = [] ): array {
	$state = dtb_admin_integration_state_defaults();
	$state['notifications'] = dtb_admin_normalize_integration_slice( [ 'status' => 'manual', 'ticket_id' => $ticket_id, 'context' => $context ], __( 'Notifications', 'drywall-toolbox' ) );
	return $state;
}
