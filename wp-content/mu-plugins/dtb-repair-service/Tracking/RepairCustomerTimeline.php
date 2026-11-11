<?php
/**
 * Tracking — RepairCustomerTimeline: build a customer-safe timeline from events.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the customer-visible timeline for a repair.
 *
 * @param int $repair_id
 * @return array
 */
function dtb_repair_get_customer_timeline( int $repair_id ): array {
if ( ! function_exists( 'dtb_repair_get_events' ) ) {
return [];
}

$events    = dtb_repair_get_events( $repair_id );
$timeline  = [];

foreach ( $events as $event ) {
$payload = is_string( $event->payload_json ?? null ) ? json_decode( (string) $event->payload_json, true ) : [];

$visibility = sanitize_text_field( (string) ( $event->visibility ?? '' ) );
if ( ! in_array( $visibility, [ 'customer', 'public' ], true ) ) {
continue;
}

$row = [
'event_type' => $event->event_type,
'created_at' => $event->created_at,
'label'      => dtb_repair_event_label( $event->event_type ),
];

if ( 'repair.note_added' === (string) $event->event_type && ! empty( $payload['note'] ) ) {
	$actor_type = sanitize_text_field( (string) ( $event->actor_type ?? '' ) );
	$row['message'] = wp_strip_all_tags( (string) $payload['note'] );
	$row['actor_type'] = $actor_type;
	$row['actor_label'] = ( 'customer' === $actor_type )
		? __( 'You', 'drywall-toolbox' )
		: __( 'DTB Team', 'drywall-toolbox' );
	$row['label'] = ( 'customer' === $actor_type )
		? __( 'Message sent', 'drywall-toolbox' )
		: __( 'New message from DTB Team', 'drywall-toolbox' );
}

$timeline[] = $row;
}

return $timeline;
}

/**
 * Return a human-readable label for an event type.
 *
 * @param string $event_type
 * @return string
 */
function dtb_repair_event_label( string $event_type ): string {
$map = [
'repair.submitted'       => __( 'Request submitted', 'drywall-toolbox' ),
'repair.reviewed'        => __( 'Under review', 'drywall-toolbox' ),
'repair.info_requested'  => __( 'Additional info requested', 'drywall-toolbox' ),
'repair.approved'        => __( 'Approved for repair', 'drywall-toolbox' ),
'repair.quoted'          => __( 'Quote ready', 'drywall-toolbox' ),
'repair.quote_resent'    => __( 'Quote resent', 'drywall-toolbox' ),
'repair.quote_accepted'  => __( 'Quote accepted', 'drywall-toolbox' ),
'repair.quote_declined'  => __( 'Quote declined', 'drywall-toolbox' ),
'repair.parts_allocated' => __( 'Parts allocated', 'drywall-toolbox' ),
'repair.in_progress'     => __( 'Repair in progress', 'drywall-toolbox' ),
'repair.ready_to_ship'   => __( 'Ready to ship', 'drywall-toolbox' ),
'repair.completed'       => __( 'Repair completed', 'drywall-toolbox' ),
'repair.closed'          => __( 'Closed', 'drywall-toolbox' ),
'repair.cancelled'       => __( 'Cancelled', 'drywall-toolbox' ),
'repair.note_added'      => __( 'Customer note added', 'drywall-toolbox' ),
'repair.media_uploaded'  => __( 'Media uploaded', 'drywall-toolbox' ),
'notification.email.queued' => __( 'Email queued', 'drywall-toolbox' ),
'notification.email.sent'   => __( 'Email delivered', 'drywall-toolbox' ),
'notification.email.failed' => __( 'Email delivery failed', 'drywall-toolbox' ),
'integration.wc.order_created'    => __( 'WooCommerce order created', 'drywall-toolbox' ),
'integration.wc.order_failed'     => __( 'WooCommerce order sync issue', 'drywall-toolbox' ),
'integration.veeqo.synced'        => __( 'Veeqo synced', 'drywall-toolbox' ),
'integration.veeqo.failed'        => __( 'Veeqo sync issue', 'drywall-toolbox' ),
'integration.veeqo.tracking_set'  => __( 'Tracking updated', 'drywall-toolbox' ),
'integration.qbo.invoice_created' => __( 'QuickBooks invoice created', 'drywall-toolbox' ),
'integration.qbo.invoice_failed'  => __( 'QuickBooks sync issue', 'drywall-toolbox' ),
'integration.rewards.issued'      => __( 'Rewards issued', 'drywall-toolbox' ),
'integration.rewards.failed'      => __( 'Rewards issue', 'drywall-toolbox' ),
'system.sla_recalculated'         => __( 'SLA recalculated', 'drywall-toolbox' ),
'system.archived'                 => __( 'Archived', 'drywall-toolbox' ),
'system.projection_refresh'       => __( 'System sync refresh', 'drywall-toolbox' ),
'system.job_enqueued'             => __( 'Background task queued', 'drywall-toolbox' ),
'system.job_retry'                => __( 'Background task retried', 'drywall-toolbox' ),
];

if ( isset( $map[ $event_type ] ) ) {
	return $map[ $event_type ];
}

$fallback = str_replace( [ 'repair.', 'integration.', 'notification.', 'system.', '_', '.' ], [ '', '', '', '', ' ', ' ' ], $event_type );
return ucwords( trim( $fallback ) );
}
