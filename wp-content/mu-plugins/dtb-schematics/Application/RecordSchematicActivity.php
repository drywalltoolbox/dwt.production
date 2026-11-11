<?php
/**
 * DTB Schematics — RecordSchematicActivity (application service).
 *
 * The one operation-history authority for the Schematics control center.
 * Every control-center operation (source scan, reconciliation run,
 * attachment registration, hotspot dataset association, product-projection
 * refresh, publication, retirement, public/frontend projection refresh)
 * should call dtb_schematic_activity_log() once it has a structured result.
 *
 * Reconciliation CLI runs share RunSchematicOperation.php and are recorded
 * here as well. Historical operations performed before that shared boundary
 * are not backfilled.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_ACTIVITY_TYPES = [
	'source_scan',
	'reconciliation',
	'attachment_registration',
	'hotspot_dataset_association',
	'hotspot_dataset_migration',
	'hotspot_one_time_optimizer',
	'product_projection_refresh',
	'publish',
	'update_published_projection',
	'retire',
	'public_projection_refresh',
	'record_create',
	'record_update',
];

/**
 * Record one completed pipeline operation.
 *
 * @param array $data {
 *     @type string $operation_type       One of DTB_SCHEMATIC_ACTIVITY_TYPES.
 *     @type int    $schematic_id         Internal post ID, 0 if not scoped to one record.
 *     @type string $schematic_canonical_id
 *     @type bool   $dry_run
 *     @type string $result               'ok' | 'error' | 'partial'.
 *     @type int    $examined
 *     @type int    $changed
 *     @type int    $skipped
 *     @type int    $unresolved
 *     @type string $source_version
 *     @type int    $catalog_version
 *     @type string $summary              Human-readable one-line result.
 *     @type array  $detail               Structured detail (stored as JSON, bounded).
 *     @type string $started_at           MySQL datetime (UTC); defaults to now if omitted.
 * }
 * @return int Inserted row ID, 0 on failure.
 */
function dtb_schematic_activity_log( array $data ): int {
	global $wpdb;

	$type = (string) ( $data['operation_type'] ?? '' );
	if ( ! in_array( $type, DTB_SCHEMATIC_ACTIVITY_TYPES, true ) ) {
		$type = 'reconciliation';
	}

	$now = current_time( 'mysql', true );

	// Bound stored detail JSON — never persist unbounded per-asset arrays.
	$detail = (array) ( $data['detail'] ?? [] );
	if ( isset( $detail['assets'] ) && is_array( $detail['assets'] ) && count( $detail['assets'] ) > 50 ) {
		$detail['assets']           = array_slice( $detail['assets'], 0, 50 );
		$detail['assets_truncated'] = true;
	}

	$row = [
		'operation_type'         => $type,
		'operator_id'            => get_current_user_id() ?: null,
		'schematic_id'           => ! empty( $data['schematic_id'] ) ? (int) $data['schematic_id'] : null,
		'schematic_canonical_id' => ! empty( $data['schematic_canonical_id'] ) ? sanitize_text_field( (string) $data['schematic_canonical_id'] ) : null,
		'dry_run'                => ! empty( $data['dry_run'] ) ? 1 : 0,
		'result'                 => in_array( (string) ( $data['result'] ?? 'ok' ), [ 'ok', 'error', 'partial' ], true ) ? (string) $data['result'] : 'ok',
		'examined_count'         => max( 0, (int) ( $data['examined'] ?? 0 ) ),
		'changed_count'          => max( 0, (int) ( $data['changed'] ?? 0 ) ),
		'skipped_count'          => max( 0, (int) ( $data['skipped'] ?? 0 ) ),
		'unresolved_count'       => max( 0, (int) ( $data['unresolved'] ?? 0 ) ),
		'source_version'         => ! empty( $data['source_version'] ) ? sanitize_text_field( (string) $data['source_version'] ) : null,
		'catalog_version'        => ! empty( $data['catalog_version'] ) ? (int) $data['catalog_version'] : null,
		'summary'                => sanitize_textarea_field( (string) ( $data['summary'] ?? '' ) ),
		'detail_json'            => wp_json_encode( $detail ),
		'started_at'             => (string) ( $data['started_at'] ?? $now ),
		'completed_at'           => $now,
	];

	$inserted = $wpdb->insert( dtb_schematic_activity_table_name(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	return $inserted ? (int) $wpdb->insert_id : 0;
}

/**
 * Query activity log rows. Bounded and paginated.
 *
 * @param array $args {
 *     @type string $type
 *     @type int    $operator_id
 *     @type int    $schematic_id
 *     @type string $result
 *     @type int    $catalog_version
 *     @type string $date_from  MySQL date/datetime.
 *     @type string $date_to
 *     @type int    $page
 *     @type int    $per_page   Capped at 100.
 * }
 * @return array{items:array[], total:int, pages:int}
 */
function dtb_schematic_activity_query( array $args = [] ): array {
	global $wpdb;

	$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
	$offset   = ( $page - 1 ) * $per_page;

	$table   = dtb_schematic_activity_table_name();
	$where   = [ '1=1' ];
	$params  = [];

	if ( ! empty( $args['type'] ) ) {
		$where[]  = 'operation_type = %s';
		$params[] = (string) $args['type'];
	}
	if ( ! empty( $args['operator_id'] ) ) {
		$where[]  = 'operator_id = %d';
		$params[] = (int) $args['operator_id'];
	}
	if ( ! empty( $args['schematic_id'] ) ) {
		$where[]  = 'schematic_id = %d';
		$params[] = (int) $args['schematic_id'];
	}
	if ( ! empty( $args['result'] ) ) {
		$where[]  = 'result = %s';
		$params[] = (string) $args['result'];
	}
	if ( ! empty( $args['catalog_version'] ) ) {
		$where[]  = 'catalog_version = %d';
		$params[] = (int) $args['catalog_version'];
	}
	if ( ! empty( $args['date_from'] ) ) {
		$where[]  = 'completed_at >= %s';
		$params[] = (string) $args['date_from'];
	}
	if ( ! empty( $args['date_to'] ) ) {
		$where[]  = 'completed_at <= %s';
		$params[] = (string) $args['date_to'];
	}

	$where_sql = implode( ' AND ', $where );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
	$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
	$total     = (int) $wpdb->get_var( $params ? $wpdb->prepare( $total_sql, $params ) : $total_sql );

	$list_sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY completed_at DESC LIMIT %d OFFSET %d";
	$list_params = array_merge( $params, [ $per_page, $offset ] );
	$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ), ARRAY_A );
	// phpcs:enable

	$items = [];
	foreach ( (array) $rows as $row ) {
		$items[] = dtb_schematic_activity_row_to_array( $row );
	}

	return [
		'items' => $items,
		'total' => $total,
		'pages' => $per_page > 0 ? (int) ceil( $total / $per_page ) : 1,
	];
}

function dtb_schematic_activity_row_to_array( array $row ): array {
	$detail = json_decode( (string) ( $row['detail_json'] ?? '' ), true );
	return [
		'id'                     => (int) $row['id'],
		'operation_type'         => (string) $row['operation_type'],
		'operator_id'            => isset( $row['operator_id'] ) ? (int) $row['operator_id'] : 0,
		'operator_name'          => isset( $row['operator_id'] ) && $row['operator_id'] ? ( get_userdata( (int) $row['operator_id'] )->display_name ?? '' ) : __( 'System', 'drywall-toolbox' ),
		'schematic_id'           => isset( $row['schematic_id'] ) ? (int) $row['schematic_id'] : 0,
		'schematic_canonical_id' => (string) ( $row['schematic_canonical_id'] ?? '' ),
		'dry_run'                => (bool) $row['dry_run'],
		'result'                 => (string) $row['result'],
		'examined'               => (int) $row['examined_count'],
		'changed'                => (int) $row['changed_count'],
		'skipped'                => (int) $row['skipped_count'],
		'unresolved'             => (int) $row['unresolved_count'],
		'source_version'         => (string) ( $row['source_version'] ?? '' ),
		'catalog_version'        => isset( $row['catalog_version'] ) ? (int) $row['catalog_version'] : 0,
		'summary'                => (string) $row['summary'],
		'detail'                 => is_array( $detail ) ? $detail : [],
		'started_at'             => (string) $row['started_at'],
		'completed_at'           => (string) $row['completed_at'],
	];
}

/**
 * Convenience: log a reconciliation-engine report (see
 * Application/ReconcileSchematicSource.php::dtb_schematic_reconcile_source()).
 */
function dtb_schematic_activity_log_reconciliation( array $report, string $started_at ): int {
	$dispositions = $report['dispositions'] ?? [];
	$summary_bits = [];
	foreach ( $dispositions as $key => $count ) {
		$summary_bits[] = DTB_Schematic_Asset_Disposition::label( (string) $key ) . ': ' . (int) $count;
	}

	return dtb_schematic_activity_log(
		[
			'operation_type'  => 'reconciliation',
			'dry_run'         => ! empty( $report['dry_run'] ),
			'result'          => ! empty( $report['fatal_error'] ) ? 'error' : ( ( $report['unresolved'] ?? 0 ) > 0 ? 'partial' : 'ok' ),
			'examined'        => $report['examined'] ?? 0,
			'changed'         => $report['changed'] ?? 0,
			'skipped'         => $report['skipped'] ?? 0,
			'unresolved'      => $report['unresolved'] ?? 0,
			'catalog_version' => function_exists( 'dtb_schematics_public_catalog_version' ) ? dtb_schematics_public_catalog_version() : 0,
			'summary'         => ! empty( $report['fatal_error'] )
				? sprintf( 'Reconciliation failed: %s', (string) $report['fatal_error'] )
				: sprintf(
					'%s batch: rows %d-%d of %d — %s',
					! empty( $report['dry_run'] ) ? 'Preview' : 'Applied sync',
					$report['batch_start'] ?? 0,
					$report['batch_end'] ?? 0,
					$report['source_row_count'] ?? 0,
					implode( ', ', $summary_bits )
				),
			'detail'          => $report,
			'started_at'      => $started_at,
		]
	);
}
