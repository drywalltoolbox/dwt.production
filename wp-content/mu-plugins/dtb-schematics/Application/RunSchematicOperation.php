<?php
/**
 * Explicit, bounded orchestration for operator-triggered schematic runs.
 *
 * The wp-admin layer calls this service once per submitted command. It owns
 * no rendering, does not infer an operation from UI labels, and never permits
 * reconciliation to retire records implicitly.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_OPERATION_RECONCILE       = 'reconcile';
const DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS = 'migrate_hotspots';
const DTB_SCHEMATIC_OPERATION_REFRESH_PRODUCTS = 'refresh_products';
const DTB_SCHEMATIC_OPERATION_PUBLISH          = 'publish';
const DTB_SCHEMATIC_OPERATION_RETIRE           = 'retire';
const DTB_SCHEMATIC_OPERATION_REFRESH_PUBLIC   = 'refresh_public_projection';
const DTB_SCHEMATIC_OPERATION_REGENERATE_OVERSIZED = 'regenerate_oversized';
const DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS = 'optimize_hotspots';
const DTB_SCHEMATIC_OPERATION_MAX_SELECTED     = 50;

function dtb_schematic_run_operation( array $args = [] ) {
	$kind        = sanitize_key( (string) ( $args['kind'] ?? '' ) );
	$dry_run     = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : true;
	$operator_id = max( 0, (int) ( $args['operator_id'] ?? get_current_user_id() ) );
	$selected    = dtb_schematic_operation_selected_ids( $args['schematic_ids'] ?? [] );
	$trusted_cli = ! empty( $args['trusted_cli'] ) && defined( 'WP_CLI' ) && WP_CLI;
	$all_records = in_array( $kind, [ DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS, DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS ], true ) && ! empty( $args['all_records'] );

	$supported = [ DTB_SCHEMATIC_OPERATION_RECONCILE, DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS, DTB_SCHEMATIC_OPERATION_REFRESH_PRODUCTS, DTB_SCHEMATIC_OPERATION_PUBLISH, DTB_SCHEMATIC_OPERATION_RETIRE, DTB_SCHEMATIC_OPERATION_REFRESH_PUBLIC, DTB_SCHEMATIC_OPERATION_REGENERATE_OVERSIZED, DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS ];
	if ( ! in_array( $kind, $supported, true ) ) {
		return new WP_Error( 'dtb_schematic_operation_kind_invalid', __( 'The requested schematic operation is not supported.', 'drywall-toolbox' ) );
	}
	if ( $dry_run && in_array( $kind, [ DTB_SCHEMATIC_OPERATION_PUBLISH, DTB_SCHEMATIC_OPERATION_RETIRE, DTB_SCHEMATIC_OPERATION_REFRESH_PUBLIC ], true ) ) {
		return new WP_Error( 'dtb_schematic_operation_preview_unsupported', __( 'Lifecycle and public-projection mutations do not support preview mode.', 'drywall-toolbox' ) );
	}
	if ( ! in_array( $kind, [ DTB_SCHEMATIC_OPERATION_RECONCILE, DTB_SCHEMATIC_OPERATION_REGENERATE_OVERSIZED ], true ) && empty( $selected ) && ! $all_records ) {
		return new WP_Error( 'dtb_schematic_operation_selection_required', __( 'Select at least one schematic for this operation.', 'drywall-toolbox' ) );
	}

	$request = [ 'schematic_ids' => $selected, 'all_records' => $all_records ];
	if ( $all_records ) {
		$request['per_page'] = max( 1, min( 100, (int) ( $args['per_page'] ?? 25 ) ) );
	}
	if ( DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS === $kind && ! $dry_run ) {
		$approved_fingerprint = strtolower( trim( (string) ( $args['approved_fingerprint'] ?? '' ) ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $approved_fingerprint ) ) {
			return new WP_Error( 'dtb_schematic_hotspot_approval_required', __( 'A valid operator-reviewed hotspot resolution plan is required before any hotspot optimizer commit.', 'drywall-toolbox' ) );
		}
		$request['approved_fingerprint'] = $approved_fingerprint;
	}
	if ( DTB_SCHEMATIC_OPERATION_RECONCILE === $kind ) {
		$request['batch_size'] = max( 1, min( DTB_SCHEMATIC_RECONCILE_MAX_BATCH_SIZE, (int) ( $args['batch_size'] ?? DTB_SCHEMATIC_RECONCILE_DEFAULT_BATCH_SIZE ) ) );
		$request['resume'] = array_key_exists( 'resume', $args ) ? (bool) $args['resume'] : true;
		$request['persist_state'] = array_key_exists( 'persist_state', $args ) ? (bool) $args['persist_state'] : ! $dry_run;
		if ( $trusted_cli && isset( $args['upload_path'] ) ) {
			$upload_path = trim( str_replace( '\\', '/', (string) $args['upload_path'] ), '/' );
			if ( '' === $upload_path || false !== strpos( $upload_path, '..' ) || ! preg_match( '#^[A-Za-z0-9._/-]+$#', $upload_path ) ) {
				return new WP_Error( 'dtb_schematic_operation_upload_path_invalid', __( 'The reconciliation uploads path must be a relative uploads path.', 'drywall-toolbox' ) );
			}
			$request['upload_path'] = $upload_path;
		}
		if ( isset( $args['state'] ) && is_array( $args['state'] ) ) {
			$request['state'] = $args['state'];
		}
	}
	$run = dtb_schematic_operation_run_create( $kind, $dry_run, $operator_id, $request );

	$lease = false;
	if ( ! $dry_run ) {
		$lease = dtb_schematic_operation_commit_lease_acquire( $run['id'] );
		if ( is_wp_error( $lease ) ) {
			$run = dtb_schematic_operation_run_complete( $run, [], $lease->get_error_message() );
			dtb_schematic_operation_log_activity( $kind, $run );
			return $run;
		}
		dtb_schematic_operation_register_fatal_lease_cleanup( $run['id'] );
	}

	try {
		$result = dtb_schematic_operation_execute( $kind, $dry_run, $request, $run['id'] );
		$result = dtb_schematic_converge_operation_result( $kind, $dry_run, $result );
		$error  = ! empty( $result['fatal_error'] ) ? (string) $result['fatal_error'] : '';
		$run    = dtb_schematic_operation_run_complete( $run, $result, $error );
		dtb_schematic_operation_log_activity( $kind, $run );
		return $run;
	} catch ( Throwable $exception ) {
		$run = dtb_schematic_operation_run_complete( $run, [], $exception->getMessage() );
		dtb_schematic_operation_log_activity( $kind, $run );
		return $run;
	} finally {
		if ( $lease ) {
			dtb_schematic_operation_commit_lease_release( $run['id'] );
		}
	}
}

function dtb_schematic_operation_selected_ids( $value ): array {
	$ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $value ), static fn( $id ) => $id > 0 ) ) );
	return array_slice( $ids, 0, DTB_SCHEMATIC_OPERATION_MAX_SELECTED );
}

function dtb_schematic_operation_execute( string $kind, bool $dry_run, array $request, string $run_id = '' ): array {
	$heartbeat = ! $dry_run && '' !== $run_id
		? static fn() => dtb_schematic_operation_commit_lease_renew( $run_id )
		: null;
	if ( DTB_SCHEMATIC_OPERATION_RECONCILE === $kind ) {
		return dtb_schematic_reconcile_source( [
			'dry_run'          => $dry_run,
			'batch_size'       => $request['batch_size'],
			'resume'           => $request['resume'],
			'persist_state'    => $request['persist_state'],
			'retire_uncovered' => false,
			'upload_path'      => $request['upload_path'] ?? DTB_SCHEMATIC_RECONCILE_DEFAULT_UPLOAD_PATH,
			'state'            => $request['state'] ?? [],
			'lease_heartbeat'  => $heartbeat,
		] );
	}
	if ( DTB_SCHEMATIC_OPERATION_REGENERATE_OVERSIZED === $kind ) {
		$report = dtb_schematic_regenerate_oversized_run( $dry_run, $heartbeat );
		return [
			'dry_run'     => $dry_run,
			'examined'    => $report['examined'],
			'changed'     => $dry_run ? 0 : $report['regenerated'],
			'skipped'     => $report['examined'] - $report['candidates'],
			'unresolved'  => 0,
			'failed'      => $report['failed'],
			'candidates'  => $report['candidates'],
			'results'     => $report['errors'],
			'fatal_error' => $report['fatal_error'],
		];
	}
	if ( DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS === $kind && ! empty( $request['all_records'] ) ) {
		if ( ! $dry_run ) {
			if ( $heartbeat ) {
				$renewed = $heartbeat();
				if ( is_wp_error( $renewed ) ) {
					return [ 'dry_run' => false, 'examined' => 0, 'changed' => 0, 'failed' => 1, 'unresolved' => 0, 'results' => [], 'fatal_error' => $renewed->get_error_message() ];
				}
			}
			$preview = dtb_schematic_hotspot_optimizer_run( [
				'dry_run'  => true,
				'per_page' => $request['per_page'] ?? DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_PER_PAGE,
			] );
			$plan = dtb_schematic_hotspot_build_resolution_plan( $preview );
			$actual_fingerprint = (string) ( $plan['fingerprint'] ?? '' );
			if ( empty( $plan['can_apply'] ) || '' === $actual_fingerprint || ! hash_equals( (string) $request['approved_fingerprint'], $actual_fingerprint ) ) {
				return [
					'dry_run'     => false,
					'examined'    => (int) ( $preview['examined'] ?? 0 ),
					'changed'     => 0,
					'failed'      => 1,
					'unresolved'  => (int) ( $preview['unresolved'] ?? 0 ),
					'results'     => [],
					'fatal_error' => 'The approved hotspot resolution plan changed or is no longer eligible before commit. No writes were made.',
				];
			}
		}
		return dtb_schematic_hotspot_optimizer_run( [
			'dry_run'         => $dry_run,
			'per_page'        => $request['per_page'] ?? DTB_SCHEMATIC_HOTSPOT_OPTIMIZER_PER_PAGE,
			'lease_heartbeat' => $heartbeat,
		] );
	}
	if ( DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS === $kind && ! empty( $request['all_records'] ) ) {
		return dtb_schematic_migrate_hotspot_datasets(
			[
				'dry_run'         => $dry_run,
				'per_page'        => $request['per_page'],
				'lease_heartbeat' => $heartbeat,
			]
		);
	}

	$items = [];
	foreach ( $request['schematic_ids'] as $schematic_id ) {
		if ( $heartbeat ) {
			$renewed = $heartbeat();
			if ( is_wp_error( $renewed ) ) {
				$changed = count( array_filter( $items, static fn( $item ) => in_array( $item['status'] ?? '', [ 'changed', 'migrated' ], true ) ) );
				return [ 'dry_run' => $dry_run, 'examined' => count( $items ), 'changed' => $changed, 'failed' => 1, 'results' => $items, 'fatal_error' => $renewed->get_error_message() ];
			}
		}
		$record = dtb_schematic_record_repo_get( $schematic_id );
		if ( ! $record ) {
			$items[] = [ 'schematic_id' => $schematic_id, 'status' => 'not_found' ];
			continue;
		}
		if ( DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS === $kind ) {
			$items[] = dtb_schematic_migrate_hotspot_dataset_for_record( $record, $dry_run );
			continue;
		}
		if ( in_array( $kind, [ DTB_SCHEMATIC_OPERATION_PUBLISH, DTB_SCHEMATIC_OPERATION_RETIRE, DTB_SCHEMATIC_OPERATION_REFRESH_PUBLIC ], true ) ) {
			$mutation = DTB_SCHEMATIC_OPERATION_PUBLISH === $kind
				? dtb_schematic_publish( $schematic_id )
				: ( DTB_SCHEMATIC_OPERATION_RETIRE === $kind ? dtb_schematic_retire( $schematic_id ) : dtb_schematic_update_published_projection( $schematic_id ) );
			$items[] = is_wp_error( $mutation )
				? [ 'schematic_id' => $schematic_id, 'canonical_id' => $record->canonical_id, 'status' => 'failed', 'detail' => $mutation->get_error_message() ]
				: [ 'schematic_id' => $schematic_id, 'canonical_id' => $mutation->canonical_id, 'status' => 'changed' ];
			continue;
		}
		if ( $dry_run ) {
			$current = $record->linked_products;
			sort( $current );
			$changed = $current !== dtb_schematic_reconcile_linked_product_ids( $record );
		} else {
			$changed = dtb_schematic_reconcile_refresh_linked_products( $record );
		}
		$items[] = [ 'schematic_id' => $schematic_id, 'canonical_id' => $record->canonical_id, 'status' => $changed ? 'changed' : 'unchanged' ];
	}

	$changed = count( array_filter( $items, static fn( $item ) => in_array( $item['status'] ?? '', [ 'changed', 'migrated' ], true ) ) );
	$failed  = count( array_filter( $items, static fn( $item ) => in_array( $item['status'] ?? '', [ 'failed', 'not_found' ], true ) ) );
	if ( ! $dry_run && DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS === $kind && $changed > 0 ) {
		dtb_schematics_invalidate_domain_cache();
	}
	return [ 'dry_run' => $dry_run, 'examined' => count( $items ), 'changed' => $changed, 'failed' => $failed, 'results' => $items ];
}

function dtb_schematic_operation_log_activity( string $kind, array $run ): void {
	if ( ! function_exists( 'dtb_schematic_activity_log' ) ) {
		return;
	}
	$types = [
		DTB_SCHEMATIC_OPERATION_RECONCILE       => 'reconciliation',
		DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS => 'hotspot_dataset_migration',
		DTB_SCHEMATIC_OPERATION_REFRESH_PRODUCTS => 'product_projection_refresh',
		DTB_SCHEMATIC_OPERATION_PUBLISH          => 'publish',
		DTB_SCHEMATIC_OPERATION_RETIRE           => 'retire',
		DTB_SCHEMATIC_OPERATION_REFRESH_PUBLIC   => 'update_published_projection',
		DTB_SCHEMATIC_OPERATION_REGENERATE_OVERSIZED => 'regenerate_oversized_images',
		DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS => 'hotspot_one_time_optimizer',
	];
	$labels = [
		DTB_SCHEMATIC_OPERATION_RECONCILE        => 'Schematic sync',
		DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS => 'Hotspot synchronization',
		DTB_SCHEMATIC_OPERATION_REFRESH_PRODUCTS => 'Product linking',
		DTB_SCHEMATIC_OPERATION_PUBLISH          => 'Publish',
		DTB_SCHEMATIC_OPERATION_RETIRE           => 'Retire',
		DTB_SCHEMATIC_OPERATION_REFRESH_PUBLIC   => 'Public projection refresh',
		DTB_SCHEMATIC_OPERATION_REGENERATE_OVERSIZED => 'Regenerate oversized schematic images',
		DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS => 'One-time hotspot optimizer',
	];
	$result = (array) ( $run['result'] ?? [] );
	dtb_schematic_activity_log( [
		'operation_type' => $types[ $kind ],
		'dry_run'        => ! empty( $run['dry_run'] ),
		'result'         => 'completed' === $run['status'] ? ( ! empty( $result['failed'] ) || ! empty( $result['unresolved'] ) ? 'partial' : 'ok' ) : 'error',
		'examined'       => (int) ( $result['examined'] ?? 0 ),
		'changed'        => (int) ( $result['changed'] ?? 0 ),
		'skipped'        => (int) ( $result['skipped'] ?? 0 ),
		'unresolved'     => (int) ( $result['unresolved'] ?? 0 ),
		'summary'        => sprintf( '%s %s (%s).', $labels[ $kind ], ! empty( $run['dry_run'] ) ? 'previewed' : 'applied', $run['id'] ),
		'detail'         => [ 'run_id' => $run['id'], 'status' => $run['status'], 'error' => $run['error'] ?? '' ],
	] );
}
