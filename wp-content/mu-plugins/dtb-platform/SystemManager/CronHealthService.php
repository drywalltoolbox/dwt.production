<?php
/**
 * DTB Platform — CronHealthService
 *
 * Surfaces overdue WP-Cron events.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_cron_health_get(): array {
	$transient = get_transient( 'dtb_cron_health' );
	if ( is_array( $transient ) ) {
		return $transient;
	}

	$now          = time();
	$overdue      = [];
	$next_events  = [];

	foreach ( (array) _get_cron_array() as $timestamp => $crons ) {
		foreach ( $crons as $hook => $args ) {
			$delay = $timestamp - $now;
			if ( $delay < 0 ) {
				$overdue[] = [
					'hook'      => $hook,
					'overdue_s' => abs( $delay ),
				];
			} else {
				$next_events[] = [
					'hook'  => $hook,
					'in_s'  => $delay,
				];
			}
		}
	}

	usort( $overdue, fn( $a, $b ) => $b['overdue_s'] <=> $a['overdue_s'] );
	usort( $next_events, fn( $a, $b ) => $a['in_s'] <=> $b['in_s'] );

	$data = [
		'overdue_count'  => count( $overdue ),
		'overdue'        => array_slice( $overdue, 0, 10 ),
		'upcoming'       => array_slice( $next_events, 0, 10 ),
		'wp_cron_active' => ! defined( 'DISABLE_WP_CRON' ) || ! DISABLE_WP_CRON,
	];

	set_transient( 'dtb_cron_health', $data, 2 * MINUTE_IN_SECONDS );

	return $data;
}
