<?php
/**
 * DTB Order Operator Timeline — operator-facing event timeline.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_order_get_operator_timeline( int $order_id ): array {
	$events   = dtb_order_get_events( $order_id, [ 'order' => 'ASC' ] );
	$timeline = [];

	foreach ( $events as $row ) {
		if ( 'internal' === (string) $row->visibility ) {
			continue;
		}

		$timeline[] = [
			'type'        => (string) $row->event_type,
			'visibility'  => (string) $row->visibility,
			'actor_type'  => (string) $row->actor_type,
			'actor_id'    => $row->actor_id !== null ? (int) $row->actor_id : null,
			'occurred_at' => (string) $row->created_at,
		];
	}

	return $timeline;
}
