<?php
/**
 * Infrastructure — RepairEventRepository: read/write helpers for wp_dtb_repair_events.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Insert a row into wp_dtb_repair_events.
 *
 * @param int    $repair_id
 * @param string $event_type
 * @param array  $args
 * @return int|false Inserted row ID, or false on failure.
 */
function dtb_repair_append_event( int $repair_id, string $event_type, array $args = [] ): int|false {
global $wpdb;

if ( $repair_id <= 0 || '' === $event_type ) {
return false;
}

$from_status = isset( $args['from_status'] ) ? sanitize_text_field( (string) $args['from_status'] ) : null;
$to_status   = isset( $args['to_status'] )   ? sanitize_text_field( (string) $args['to_status'] )   : null;
$actor_type  = sanitize_text_field( (string) ( $args['actor_type'] ?? 'system' ) );
$actor_id    = isset( $args['actor_id'] ) ? ( absint( $args['actor_id'] ) ?: null ) : null;
$source      = sanitize_text_field( (string) ( $args['source'] ?? 'system' ) );
$visibility  = sanitize_text_field( (string) ( $args['visibility'] ?? dtb_repair_event_default_visibility( $event_type ) ) );
$payload     = ! empty( $args['payload'] ) && is_array( $args['payload'] ) ? wp_json_encode( $args['payload'] ) : null;

$table  = $wpdb->prefix . 'dtb_repair_events';
$result = $wpdb->insert(
$table,
[
'repair_id'    => $repair_id,
'event_type'   => sanitize_text_field( $event_type ),
'from_status'  => $from_status,
'to_status'    => $to_status,
'actor_type'   => $actor_type,
'actor_id'     => $actor_id,
'source'       => $source,
'visibility'   => $visibility,
'payload_json' => $payload,
'created_at'   => current_time( 'mysql', true ),
],
[
'%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s',
]
);

if ( false === $result ) {
error_log( "[DTB Repairs] Failed to insert event '{$event_type}' for repair #{$repair_id}: " . $wpdb->last_error );
return false;
}

return (int) $wpdb->insert_id;
}

/**
 * Query events for a repair.
 *
 * @param int         $repair_id
 * @param string|null $visibility Filter by visibility level. Null = all.
 * @param int         $limit
 * @param int         $since_id
 * @return object[]
 */
function dtb_repair_get_events( int $repair_id, ?string $visibility = null, int $limit = 100, int $since_id = 0 ): array {
global $wpdb;

$table = $wpdb->prefix . 'dtb_repair_events';
$limit = max( 1, min( 500, $limit ) );

if ( null !== $visibility ) {
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows = $wpdb->get_results(
$wpdb->prepare(
"SELECT * FROM `{$table}`
 WHERE repair_id = %d AND visibility = %s AND id > %d
 ORDER BY id ASC
 LIMIT %d",
$repair_id,
$visibility,
$since_id,
$limit
)
);
} else {
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows = $wpdb->get_results(
$wpdb->prepare(
"SELECT * FROM `{$table}`
 WHERE repair_id = %d AND id > %d
 ORDER BY id ASC
 LIMIT %d",
$repair_id,
$since_id,
$limit
)
);
}

return is_array( $rows ) ? $rows : [];
}

/**
 * Return the most recent event for a repair.
 *
 * @param int         $repair_id
 * @param string|null $visibility  Filter to a specific visibility level. Null = any.
 * @return object|null stdClass row or null.
 */
function dtb_repair_get_last_event( int $repair_id, ?string $visibility = null ): ?object {
global $wpdb;

$table = $wpdb->prefix . 'dtb_repair_events';

if ( null !== $visibility ) {
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$row = $wpdb->get_row(
$wpdb->prepare(
"SELECT * FROM `{$table}` WHERE repair_id = %d AND visibility = %s ORDER BY id DESC LIMIT 1",
$repair_id,
$visibility
)
);
} else {
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$row = $wpdb->get_row(
$wpdb->prepare(
"SELECT * FROM `{$table}` WHERE repair_id = %d ORDER BY id DESC LIMIT 1",
$repair_id
)
);
}

return $row ?: null;
}

/**
 * Return events of a specific type for a repair.
 *
 * @param int    $repair_id
 * @param string $event_type
 * @param int    $limit
 * @return object[]
 */
function dtb_repair_get_events_by_type( int $repair_id, string $event_type, int $limit = 10 ): array {
global $wpdb;

$table = $wpdb->prefix . 'dtb_repair_events';

// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$rows = $wpdb->get_results(
$wpdb->prepare(
"SELECT * FROM `{$table}` WHERE repair_id = %d AND event_type = %s ORDER BY id DESC LIMIT %d",
$repair_id,
$event_type,
max( 1, $limit )
)
);

return is_array( $rows ) ? $rows : [];
}
