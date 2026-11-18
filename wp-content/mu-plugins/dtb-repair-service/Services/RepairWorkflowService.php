<?php
/**
 * Services — RepairWorkflowService: canonical status transition and integration state.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Transition a repair to a new status.
 *
 * This is THE canonical function. All status changes MUST go through here.
 *
 * @param int    $repair_id
 * @param string $to_status
 * @param array  $context
 * @return true|WP_Error
 */
function dtb_transition_repair_status( int $repair_id, string $to_status, array $context = [] ): bool|WP_Error {
$post = get_post( $repair_id );

if ( ! $post || 'dtb_repair_request' !== $post->post_type ) {
return new WP_Error(
'dtb_repair_not_found',
sprintf( __( 'Repair #%d not found.', 'drywall-toolbox' ), $repair_id )
);
}

$from_status = dtb_get_repair_status( $repair_id );

if ( '' === $from_status ) {
return new WP_Error(
'dtb_repair_no_status',
sprintf( __( 'Repair #%d has no current status set.', 'drywall-toolbox' ), $repair_id )
);
}

if ( ! dtb_is_valid_repair_transition( $from_status, $to_status ) ) {
return new WP_Error(
'dtb_repair_invalid_transition',
sprintf(
/* translators: 1: from status, 2: to status */
__( 'Cannot transition repair from "%1$s" to "%2$s".', 'drywall-toolbox' ),
$from_status,
$to_status
)
);
}

$actor_type = sanitize_text_field( (string) ( $context['actor_type'] ?? 'system' ) );
$actor_id   = isset( $context['actor_id'] ) ? absint( $context['actor_id'] ) : get_current_user_id();
$source     = sanitize_text_field( (string) ( $context['source'] ?? 'system' ) );
$payload    = is_array( $context['payload'] ?? null ) ? $context['payload'] : [];

update_post_meta( $repair_id, '_repair_status', $to_status );

$now    = gmdate( 'Y-m-d\TH:i:s\Z' );
$ts_map = [
'reviewed'  => '_repair_reviewed_at',
'completed' => '_repair_completed_at',
'closed'    => '_repair_closed_at',
];

if ( isset( $ts_map[ $to_status ] ) ) {
update_post_meta( $repair_id, $ts_map[ $to_status ], $now );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
$event_type_map = [
'reviewed'          => 'repair.reviewed',
'awaiting_customer' => 'repair.info_requested',
'approved'          => 'repair.approved',
'quoted'            => 'repair.quoted',
'quote_accepted'    => 'repair.quote_accepted',
'quote_declined'    => 'repair.quote_declined',
'parts_allocated'   => 'repair.parts_allocated',
'in_progress'       => 'repair.in_progress',
'ready_to_ship'     => 'repair.ready_to_ship',
'completed'         => 'repair.completed',
'closed'            => 'repair.closed',
'cancelled'         => 'repair.cancelled',
];

$event_type = $event_type_map[ $to_status ] ?? 'repair.status_changed';

dtb_repair_append_event(
$repair_id,
$event_type,
[
'from_status' => $from_status,
'to_status'   => $to_status,
'actor_type'  => $actor_type,
'actor_id'    => $actor_id ?: null,
'source'      => $source,
'payload'     => $payload,
]
);

if ( ! empty( $context['note'] ) ) {
dtb_repair_append_event(
$repair_id,
'repair.note_added',
[
'actor_type' => $actor_type,
'actor_id'   => $actor_id ?: null,
'source'     => $source,
'visibility' => 'operator',
'payload'    => [ 'note' => wp_kses_post( (string) $context['note'] ) ],
]
);
}
}

dtb_repair_schedule_integration_jobs( $repair_id, $to_status, $context );

/**
 * Fires after a repair status transition completes.
 *
 * @param int    $repair_id
 * @param string $from_status
 * @param string $to_status
 * @param array  $context
 */
do_action( 'dtb_repair_status_changed', $repair_id, $from_status, $to_status, $context );

return true;
}

/**
 * Schedule the appropriate integration jobs for a given transition.
 *
 * @param int    $repair_id
 * @param string $to_status
 * @param array  $context
 */
function dtb_repair_schedule_integration_jobs( int $repair_id, string $to_status, array $context = [] ): void {
if ( ! function_exists( 'dtb_repair_enqueue_job' ) ) {
return;
}

dtb_repair_enqueue_job(
'dtb_repair_send_notification',
$repair_id,
[ 'template' => dtb_repair_notification_template_for_status( $to_status ) ]
);

dtb_repair_enqueue_job( 'dtb_repair_refresh_projection', $repair_id );

switch ( $to_status ) {
case 'approved':
dtb_repair_enqueue_job( 'dtb_repair_create_wc_order', $repair_id );
break;

case 'quote_accepted':
dtb_repair_enqueue_job( 'dtb_repair_sync_quickbooks', $repair_id );
break;

case 'parts_allocated':
dtb_repair_enqueue_job( 'dtb_repair_sync_veeqo', $repair_id, [ 'action' => 'reserve_parts' ] );
break;

case 'ready_to_ship':
dtb_repair_enqueue_job( 'dtb_repair_sync_veeqo', $repair_id, [ 'action' => 'create_shipment' ] );
break;

case 'completed':
dtb_repair_enqueue_job( 'dtb_repair_issue_rewards', $repair_id );
dtb_repair_enqueue_job( 'dtb_repair_recalculate_sla', $repair_id );
break;

case 'closed':
dtb_repair_enqueue_job( 'dtb_repair_archive_closed', $repair_id );
break;
}
}

/**
 * Map a target status to the appropriate notification template slug.
 *
 * @param string $to_status
 * @return string
 */
function dtb_repair_notification_template_for_status( string $to_status ): string {
$map = [
'awaiting_customer' => 'repair-info-requested',
'reviewed'          => 'repair-reviewed',
'approved'          => 'repair-approved',
'quoted'            => 'repair-quote-created',
'quote_accepted'    => 'repair-quote-accepted',
'in_progress'       => 'repair-in-progress',
'ready_to_ship'     => 'repair-ready-to-ship',
'completed'         => 'repair-completed',
'cancelled'         => 'repair-cancelled',
];

return $map[ $to_status ] ?? '';
}

/**
 * Return the integration state for a repair.
 *
 * @param int $repair_id
 * @return array
 */
function dtb_get_repair_integration_state( int $repair_id ): array {
$raw = (string) get_post_meta( $repair_id, '_repair_integration_state', true );

if ( '' === $raw ) {
return [
'woocommerce' => [ 'state' => 'pending', 'order_id' => null, 'last_success_at' => null, 'last_error' => null ],
'veeqo'       => [ 'state' => 'pending', 'tracking_number' => null, 'last_success_at' => null, 'last_error_code' => null ],
'quickbooks'  => [ 'state' => 'pending', 'invoice_id' => null, 'last_success_at' => null, 'last_error_code' => null ],
'rewards'     => [ 'state' => 'not_eligible', 'issued' => false ],
];
}

$decoded = json_decode( $raw, true );
return is_array( $decoded ) ? $decoded : [];
}

/**
 * Update one integration slice in the repair's integration state projection.
 *
 * @param int    $repair_id
 * @param string $integration
 * @param array  $data
 */
function dtb_update_repair_integration_state( int $repair_id, string $integration, array $data ): void {
$allowed = [ 'woocommerce', 'veeqo', 'quickbooks', 'rewards' ];

if ( ! in_array( $integration, $allowed, true ) ) {
return;
}

$state                 = dtb_get_repair_integration_state( $repair_id );
$state[ $integration ] = array_merge( $state[ $integration ] ?? [], $data );

update_post_meta( $repair_id, '_repair_integration_state', wp_json_encode( $state ) );
}
