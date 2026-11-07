<?php
/**
 * DTB Platform — QueueHealthService
 *
 * Inspects WooCommerce Action Scheduler queue health.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_queue_health_get(): array {
	$transient = get_transient( 'dtb_queue_health' );
	if ( is_array( $transient ) ) {
		return $transient;
	}

	global $wpdb;

	$data = [
		'pending'  => 0,
		'running'  => 0,
		'failed'   => 0,
		'failed_recent_24h' => 0,
		'complete' => 0,
		'total'    => 0,
		'oldest_pending_seconds' => 0,
		'failed_notification_jobs' => 0,
		'failed_notification_hooks'=> [],
		'failed_by_hook' => [],
		'failed_by_group' => [],
		'failed_age_buckets' => [],
		'dtb_failed_24h' => 0,
	];

	if ( $wpdb instanceof wpdb ) {
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$table = $wpdb->prefix . 'actionscheduler_actions';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$rows = $wpdb->get_results(
				"SELECT status, COUNT(*) AS cnt FROM {$table} GROUP BY status",
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$status            = $row['status'] ?? '';
				$cnt               = (int) ( $row['cnt'] ?? 0 );
				$data[ $status ]   = $cnt;
				$data['total']    += $cnt;
			}

			// Distinguish actionable new failures from all retained history.
			// Action Scheduler records its most recent attempt on the actions table.
			$failed_since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
			$data['failed_recent_24h'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND last_attempt_gmt >= %s",
				$failed_since
			) );

			$oldest = $wpdb->get_var(
				"SELECT MIN(scheduled_date_gmt) FROM {$table} WHERE status = 'pending'"
			);
			if ( $oldest ) {
				$data['oldest_pending_seconds'] = max( 0, time() - strtotime( $oldest ) );
			}

			// Bounded, read-only failure attribution; never read action arguments or payloads.
			// last_attempt_gmt is the authoritative attempted time for a failed action.
			$data['failed_by_hook'] = (array) $wpdb->get_results(
				"SELECT hook, COUNT(*) AS count, SUM(last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS recent_24h, MAX(last_attempt_gmt) AS last_failed_gmt
				 FROM {$table} WHERE status = 'failed'
				 GROUP BY hook ORDER BY count DESC LIMIT 25",
				ARRAY_A
			);
			$group_table = $wpdb->prefix . 'actionscheduler_groups';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $group_table ) ) === $group_table ) {
				$data['failed_by_group'] = (array) $wpdb->get_results(
					"SELECT COALESCE(g.slug, '(no group)') AS group_name, COUNT(*) AS count,
					 SUM(a.last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 1 DAY) AS recent_24h
					 FROM {$table} a LEFT JOIN {$group_table} g ON g.group_id = a.group_id
					 WHERE a.status = 'failed' GROUP BY a.group_id, g.slug ORDER BY count DESC LIMIT 20",
					ARRAY_A
				);
			}
			$data['failed_age_buckets'] = (array) $wpdb->get_results(
				"SELECT CASE
				 WHEN last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 1 DAY THEN '0-24 hours'
				 WHEN last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 7 DAY THEN '1-7 days'
				 WHEN last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 30 DAY THEN '7-30 days'
				 ELSE '30+ days / unknown' END AS age, COUNT(*) AS count
				 FROM {$table} WHERE status = 'failed' GROUP BY age ORDER BY MIN(last_attempt_gmt) DESC",
				ARRAY_A
			);
			$data['dtb_failed_24h'] = (int) $wpdb->get_var(
				"SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND hook LIKE 'dtb_%'
				 AND last_attempt_gmt >= UTC_TIMESTAMP() - INTERVAL 1 DAY"
			);

			$failed_hooks = $wpdb->get_results(
				"SELECT hook, COUNT(*) AS cnt FROM {$table} WHERE status = 'failed' AND (hook LIKE '%notification%' OR hook LIKE '%email%' OR hook LIKE '%outbox%') GROUP BY hook ORDER BY cnt DESC LIMIT 10",
				ARRAY_A
			);
			foreach ( (array) $failed_hooks as $row ) {
				$count = (int) ( $row['cnt'] ?? 0 );
				$data['failed_notification_jobs'] += $count;
				$data['failed_notification_hooks'][] = [
					'hook'  => (string) ( $row['hook'] ?? '' ),
					'count' => $count,
				];
			}
		}
		// phpcs:enable
	}

	set_transient( 'dtb_queue_health', $data, 2 * MINUTE_IN_SECONDS );

	return $data;
}
