<?php
/**
 * Domain: Order Transition — fulfillment substate read/write.
 *
 * @package drywall-toolbox
 */
defined( 'ABSPATH' ) || exit;

function dtb_order_get_fulfillment_substate( int $order_id ): string {
$val = get_post_meta( $order_id, '_dtb_fulfillment_substate', true );
return ( is_string( $val ) && '' !== $val ) ? $val : 'pending';
}

function dtb_order_set_fulfillment_substate( int $order_id, string $substate, array $payload = [] ): void {
$prev = dtb_order_get_fulfillment_substate( $order_id );
if ( $prev === $substate ) { return; }
update_post_meta( $order_id, '_dtb_fulfillment_substate', sanitize_key( $substate ) );
$event_map = [
'inventory_reserved' => 'order.inventory_reserved',
'picked'             => 'order.picked',
'packed'             => 'order.packed',
'shipped'            => 'order.shipped',
'delivered'          => 'order.delivered',
'exception'          => 'integration.veeqo.failed',
];
if ( isset( $event_map[ $substate ] ) ) {
dtb_order_append_event( $order_id, $event_map[ $substate ], [
'source'     => 'veeqo',
'actor_type' => 'veeqo',
'visibility' => 'customer',
'payload'    => $payload,
] );
}
}
