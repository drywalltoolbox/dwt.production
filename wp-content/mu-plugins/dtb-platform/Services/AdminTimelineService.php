<?php
/**
 * DTB Platform — AdminTimelineService
 *
 * Aggregates module-native event ledgers and shared admin audit records into a
 * normalized operator timeline.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return a normalized operator timeline for a workbench record.
 *
 * @param string $module    support|returns|repair|order.
 * @param int    $record_id Record ID.
 * @param array  $context   Optional already-fetched events.
 * @return array<int,array>
 */
function dtb_admin_get_timeline( string $module, int $record_id, array $context = [] ): array {
	$module = sanitize_key( $module );
	$events = [];

	foreach ( (array) ( $context['events'] ?? [] ) as $event ) {
		if ( is_array( $event ) ) {
			$events[] = dtb_admin_normalize_timeline_event( $event, $module, $record_id, 'module' );
		}
	}

	if ( function_exists( 'dtb_admin_audit_get_events' ) ) {
		$audit_module = 'returns' === $module ? 'returns' : $module;
		foreach ( dtb_admin_audit_get_events( $audit_module, $record_id, 100 ) as $event ) {
			$events[] = dtb_admin_normalize_timeline_event( $event, $module, $record_id, 'admin_audit' );
		}
	}

	if ( 'order' === $module && function_exists( 'dtb_order_get_events' ) ) {
		foreach ( dtb_order_get_events( $record_id, [ 'order' => 'DESC', 'limit' => 100 ] ) as $row ) {
			$events[] = dtb_admin_normalize_timeline_event(
				[
					'event_type' => (string) $row->event_type,
					'visibility' => (string) $row->visibility,
					'actor_type' => (string) $row->actor_type,
					'actor_id'   => $row->actor_id !== null ? (int) $row->actor_id : null,
					'source'     => (string) $row->source,
					'payload'    => json_decode( (string) ( $row->payload_json ?? '{}' ), true ) ?: [],
					'created_at' => (string) $row->created_at,
				],
				'order',
				$record_id,
				'order_events'
			);
		}
	}

	usort(
		$events,
		static function ( array $a, array $b ): int {
			return strcmp( (string) ( $b['created_at'] ?? '' ), (string) ( $a['created_at'] ?? '' ) );
		}
	);

	$seen = [];
	$out  = [];
	foreach ( $events as $event ) {
		$key = md5( ( $event['source'] ?? '' ) . '|' . ( $event['event_type'] ?? '' ) . '|' . ( $event['created_at'] ?? '' ) . '|' . wp_json_encode( $event['payload'] ?? [] ) );
		if ( isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[] = $event;
		if ( count( $out ) >= 100 ) {
			break;
		}
	}

	return $out;
}

/**
 * Normalize one event into the operator timeline shape.
 *
 * @param array  $event     Raw event.
 * @param string $module    Module.
 * @param int    $record_id Record ID.
 * @param string $source    Event source.
 * @return array
 */
function dtb_admin_normalize_timeline_event( array $event, string $module, int $record_id, string $source ): array {
	$type = (string) ( $event['event_type'] ?? $event['action'] ?? $event['event'] ?? $event['type'] ?? 'event' );
	$created_at = (string) ( $event['created_at'] ?? $event['created_at_utc'] ?? $event['ts'] ?? $event['date'] ?? $event['occurred_at'] ?? '' );
	$payload = isset( $event['payload'] ) && is_array( $event['payload'] )
		? $event['payload']
		: ( isset( $event['context'] ) && is_array( $event['context'] ) ? $event['context'] : [] );

	return [
		'event_type'  => sanitize_text_field( $type ),
		'module'      => sanitize_key( $module ),
		'record_id'   => $record_id,
		'actor'       => [
			'id'    => isset( $event['actor_id'] ) ? absint( $event['actor_id'] ) : null,
			'label' => sanitize_text_field( (string) ( $event['actor_label'] ?? $event['user_login'] ?? $event['user'] ?? $event['actor_type'] ?? 'System' ) ),
		],
		'visibility'  => sanitize_key( (string) ( $event['visibility'] ?? 'internal' ) ),
		'source'      => sanitize_key( (string) ( $event['source'] ?? $source ) ),
		'summary'     => sanitize_text_field( (string) ( $event['summary'] ?? dtb_admin_timeline_summary_from_type( $type ) ) ),
		'payload'     => $payload,
		'created_at'  => $created_at ?: gmdate( 'c' ),
	];
}

/**
 * Generate a readable summary from an event type.
 *
 * @param string $type Event type.
 * @return string
 */
function dtb_admin_timeline_summary_from_type( string $type ): string {
	$type = preg_replace( '/^(ticket|support|return|repair|order|integration|notification)\./', '', $type );
	return ucwords( str_replace( [ '_', '.', '-' ], ' ', (string) $type ) );
}
