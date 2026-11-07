<?php
/**
 * Domain: Order Event — visibility map, label map, payload sanitizer.
 *
 * @package drywall-toolbox
 */
defined( 'ABSPATH' ) || exit;

function dtb_order_event_default_visibility( string $event_type ): string {
$customer_events = [
'order.created','order.payment_pending','order.payment_confirmed',
'order.payment_failed','order.inventory_reserved','order.picked',
'order.packed','order.shipped','order.tracking_updated','order.delivered',
'order.completed','order.cancelled','order.refund_requested','order.refunded',
'notification.order_confirmation.sent','notification.shipped.sent','notification.refund.sent',
];
$operator_events = [
'order.payment_review_required','order.inventory_reservation_failed',
'order.fulfillment_queued','integration.veeqo.queued','integration.veeqo.synced',
'integration.veeqo.failed','integration.veeqo.polling_expired','integration.quickbooks.queued','integration.quickbooks.synced',
'integration.quickbooks.failed','integration.rewards.queued','integration.rewards.issued',
'integration.rewards.failed',
];
if ( in_array( $event_type, $customer_events, true ) ) { return 'customer'; }
if ( in_array( $event_type, $operator_events, true ) ) { return 'operator'; }
return 'internal';
}

function dtb_order_event_customer_label( string $event_type ): string {
$labels = [
'order.created'                        => __( 'Order placed', 'drywall-toolbox' ),
'order.payment_pending'                => __( 'Awaiting payment', 'drywall-toolbox' ),
'order.payment_confirmed'              => __( 'Payment confirmed', 'drywall-toolbox' ),
'order.payment_failed'                 => __( 'Payment failed', 'drywall-toolbox' ),
'order.inventory_reserved'             => __( 'Inventory reserved', 'drywall-toolbox' ),
'order.picked'                         => __( 'Picking order', 'drywall-toolbox' ),
'order.packed'                         => __( 'Order packed', 'drywall-toolbox' ),
'order.shipped'                        => __( 'Order shipped', 'drywall-toolbox' ),
'order.tracking_updated'               => __( 'Tracking updated', 'drywall-toolbox' ),
'order.delivered'                      => __( 'Delivered', 'drywall-toolbox' ),
'order.completed'                      => __( 'Order completed', 'drywall-toolbox' ),
'order.cancelled'                      => __( 'Order cancelled', 'drywall-toolbox' ),
'order.refund_requested'               => __( 'Refund requested', 'drywall-toolbox' ),
'order.refunded'                       => __( 'Refunded', 'drywall-toolbox' ),
'notification.order_confirmation.sent' => __( 'Confirmation sent', 'drywall-toolbox' ),
'notification.shipped.sent'            => __( 'Shipping notification sent', 'drywall-toolbox' ),
'notification.refund.sent'             => __( 'Refund notification sent', 'drywall-toolbox' ),
];
return $labels[ $event_type ] ?? ucwords( str_replace( [ '.', '_' ], ' ', $event_type ) );
}

function dtb_order_sanitize_event_payload( array $payload ): array {
$deny_keys = [
'card_number','cvv','cvc','card_cvc','raw_error','stack_trace','gateway_raw',
'payment_method_details','fraud_score','quickbooks_token','veeqo_api_key','password','secret',
];
$out = [];
foreach ( $payload as $key => $val ) {
if ( in_array( strtolower( (string) $key ), $deny_keys, true ) ) { continue; }
if ( is_array( $val ) ) { $out[ $key ] = dtb_order_sanitize_event_payload( $val ); }
elseif ( is_scalar( $val ) || null === $val ) { $out[ $key ] = $val; }
}
return $out;
}
