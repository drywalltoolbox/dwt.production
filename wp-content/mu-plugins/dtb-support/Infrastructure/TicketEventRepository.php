<?php
/**
 * Infrastructure — TicketEventRepository: append and query the support events stream.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the fully-qualified events table name.
 *
 * @return string
 */
function dtb_support_events_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'dtb_support_events';
}

/**
 * Append a single event to the support event stream.
 *
 * @param array $event  Output of dtb_support_build_event().
 * @return int|WP_Error  Inserted row ID, or WP_Error.
 */
function dtb_support_append_event( array $event ): int|WP_Error {
	global $wpdb;
	$table = dtb_support_events_table();

	$row = [
		'ticket_id'    => (int) $event['ticket_id'],
		'event_type'   => $event['event_type'],
		'from_status'  => $event['from_status'] ?: null,
		'to_status'    => $event['to_status']   ?: null,
		'actor_type'   => $event['actor_type'],
		'actor_id'     => $event['actor_id'] ?: null,
		'source'       => $event['source'],
		'visibility'   => $event['visibility'],
		'body'         => function_exists( 'dtb_str_normalize_display' )
			? dtb_str_normalize_display( sanitize_textarea_field( (string) ( $event['body'] ?? '' ) ), true )
			: sanitize_textarea_field( (string) ( $event['body'] ?? '' ) ),
		'payload_json' => ! empty( $event['payload'] ) ? wp_json_encode( $event['payload'] ) : null,
		'created_at'   => $event['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
	];

	$inserted = $wpdb->insert( $table, $row );

	if ( false === $inserted ) {
		return new WP_Error( 'dtb_support_event_error', 'Could not persist support event.' );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Return all events for a ticket in chronological order.
 *
 * @param int    $ticket_id
 * @param string $visibility 'all' | 'customer' | 'operator'
 * @return object[]
 */
function dtb_support_get_events( int $ticket_id, string $visibility = 'all' ): array {
	global $wpdb;
	$table = dtb_support_events_table();

	if ( 'all' === $visibility ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE ticket_id = %d ORDER BY created_at ASC, id ASC",
			$ticket_id
		) );
	} elseif ( 'operator' === $visibility ) {
		// Operator timelines should include public ('all') events plus internal notes.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE ticket_id = %d AND visibility IN ('operator','all') ORDER BY created_at ASC, id ASC",
			$ticket_id
		) );
	} elseif ( 'customer' === $visibility ) {
		// Customer views should include public ('all') events plus customer-specific events.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE ticket_id = %d AND visibility IN ('customer','all') ORDER BY created_at ASC, id ASC",
			$ticket_id
		) );
	} else {
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE ticket_id = %d AND visibility = %s ORDER BY created_at ASC, id ASC",
			$ticket_id,
			$visibility
		) );
	}

	foreach ( (array) $rows as $row ) {
		if ( ! empty( $row->payload_json ) ) {
			$row->payload = json_decode( $row->payload_json, true );
			if ( is_array( $row->payload ) && function_exists( 'dtb_str_normalize_display_mixed' ) ) {
				$row->payload = dtb_str_normalize_display_mixed( $row->payload );
			}
		} else {
			$row->payload = [];
		}
		if ( isset( $row->body ) && is_string( $row->body ) && function_exists( 'dtb_str_normalize_display' ) ) {
			$row->body = dtb_str_normalize_display( $row->body, true );
		}
	}

	return $rows ?: [];
}

/**
 * Return the most recent staff reply timestamp for a ticket (used for SLA first-response).
 *
 * @param int $ticket_id
 * @return string|null MySQL datetime or null.
 */
function dtb_support_get_first_staff_reply_at( int $ticket_id ): ?string {
	global $wpdb;
	$table = dtb_support_events_table();

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_var( $wpdb->prepare(
		"SELECT created_at FROM {$table}
		 WHERE ticket_id = %d AND event_type = 'ticket.reply_customer'
		 ORDER BY created_at ASC LIMIT 1",
		$ticket_id
	) );
}
