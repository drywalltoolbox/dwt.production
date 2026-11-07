<?php
/**
 * Domain — RepairEvent: constant and event visibility helper.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/** Current schema version. Bump to trigger dbDelta on next boot. */
define( 'DTB_REPAIR_EVENTS_DB_VERSION', '1.0.0' );

/**
 * Return the default visibility for a given event type.
 *
 * @param string $event_type
 * @return string  'customer' | 'operator' | 'internal'
 */
function dtb_repair_event_default_visibility( string $event_type ): string {
static $map = [
'repair.submitted'         => 'customer',
'repair.reviewed'          => 'customer',
'repair.info_requested'    => 'customer',
'repair.approved'          => 'customer',
'repair.quoted'            => 'customer',
'repair.quote_accepted'    => 'customer',
'repair.quote_declined'    => 'customer',
'repair.parts_allocated'   => 'customer',
'repair.in_progress'       => 'customer',
'repair.ready_to_ship'     => 'customer',
'repair.completed'         => 'customer',
'repair.closed'            => 'customer',
'repair.cancelled'         => 'customer',
'repair.media_uploaded'    => 'customer',
'repair.note_added'        => 'operator',
'notification.email.queued'  => 'operator',
'notification.email.sent'    => 'operator',
'notification.email.failed'  => 'operator',
'integration.wc.order_created'    => 'operator',
'integration.wc.order_failed'     => 'operator',
'integration.veeqo.synced'        => 'operator',
'integration.veeqo.failed'        => 'operator',
'integration.veeqo.tracking_set'  => 'operator',
'integration.qbo.invoice_created' => 'operator',
'integration.qbo.invoice_failed'  => 'operator',
'integration.rewards.issued'      => 'operator',
'integration.rewards.failed'      => 'operator',
'system.sla_recalculated'  => 'internal',
'system.archived'          => 'internal',
'system.projection_refresh'=> 'internal',
'system.job_enqueued'      => 'internal',
'system.job_retry'         => 'internal',
];

if ( isset( $map[ $event_type ] ) ) {
return $map[ $event_type ];
}

if ( 0 === strpos( $event_type, 'repair.' ) ) {
return 'customer';
}
if ( 0 === strpos( $event_type, 'system.' ) ) {
return 'internal';
}

return 'operator';
}
