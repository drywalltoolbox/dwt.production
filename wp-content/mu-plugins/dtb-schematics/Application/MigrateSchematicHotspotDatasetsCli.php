<?php
/**
 * DTB Schematics — Phase 5 hotspot/product migration operational entry point.
 *
 * Registers a WP-CLI command over Application/MigrateSchematicHotspotDatasets.php,
 * following the same pattern as Application/ReconcileSchematicSourceCli.php.
 *
 * Usage:
 *   wp dtb schematics migrate-hotspots                       # dry run, every schematic
 *   wp dtb schematics migrate-hotspots --commit               # writes
 *   wp dtb schematics migrate-hotspots --schematic=tapetech-07tt --commit
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'init', 'dtb_register_cli_schematic_migrate_hotspots', 99 );
}

function dtb_register_cli_schematic_migrate_hotspots(): void {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}
	WP_CLI::add_command( 'dtb schematics migrate-hotspots', 'dtb_schematic_migrate_hotspots_cli_command' );
}

/**
 * @param array $args
 * @param array $assoc_args {
 *     @type bool   $commit    Write mode. Omitted/absent = dry run (default).
 *     @type string $schematic Restrict to one canonical schematic ID.
 *     @type int    $per_page  Records per query page (default 50).
 * }
 */
function dtb_schematic_migrate_hotspots_cli_command( array $args, array $assoc_args ): void {
	$dry_run = ! isset( $assoc_args['commit'] );
	$per_page = max( 1, min( DTB_SCHEMATIC_OPERATION_MAX_SELECTED, (int) ( $assoc_args['per_page'] ?? 50 ) ) );
	$canonical_id = sanitize_key( (string) ( $assoc_args['schematic'] ?? '' ) );

	WP_CLI::log( sprintf( 'DTB Schematic Hotspot/Part Migration — %s.', $dry_run ? 'DRY RUN (no writes)' : 'COMMIT MODE' ) );

	if ( '' !== $canonical_id ) {
		$record = dtb_schematic_record_repo_find_by_canonical_id( $canonical_id );
		if ( ! $record ) {
			WP_CLI::error( sprintf( 'No authoritative schematic record found for "%s".', $canonical_id ) );
		}
		$batches = [ [ $record->id ] ];
	} else {
		$batches = [];
		$page    = 1;
		do {
			$query = dtb_schematic_record_repo_query( [ 'page' => $page, 'per_page' => $per_page ] );
			if ( ! empty( $query['items'] ) ) {
				$batches[] = array_map( static fn( $record ) => $record->id, $query['items'] );
			}
			$page++;
		} while ( $page <= (int) $query['pages'] && $page <= DTB_SCHEMATIC_HOTSPOT_MIGRATE_MAX_PAGES );
	}

	$summary = [ 'examined' => 0, 'changed' => 0, 'failed' => 0 ];
	foreach ( $batches as $ids ) {
		$run = dtb_schematic_run_operation( [ 'kind' => DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS, 'dry_run' => $dry_run, 'operator_id' => 0, 'schematic_ids' => $ids, 'trusted_cli' => true ] );
		if ( is_wp_error( $run ) || 'completed' !== ( $run['status'] ?? '' ) ) {
			WP_CLI::error( is_wp_error( $run ) ? $run->get_error_message() : (string) ( $run['error'] ?? 'Hotspot migration run failed.' ) );
		}
		$result = (array) ( $run['result'] ?? [] );
		$summary['examined'] += (int) ( $result['examined'] ?? 0 );
		$summary['changed']  += (int) ( $result['changed'] ?? 0 );
		$summary['failed']   += (int) ( $result['failed'] ?? 0 );
		foreach ( (array) ( $result['results'] ?? [] ) as $row ) {
			WP_CLI::log( sprintf( '  %-40s %-28s %s', $row['canonical_id'] ?? (string) ( $row['schematic_id'] ?? '' ), $row['status'] ?? 'unknown', $row['detail'] ?? '' ) );
		}
	}

	if ( $summary['failed'] > 0 ) {
		WP_CLI::error( sprintf( 'Complete with failures. Examined %d, changed %d, failed %d.', $summary['examined'], $summary['changed'], $summary['failed'] ) );
	}
	WP_CLI::success( sprintf( 'Complete. Examined %d, changed %d, failed 0.', $summary['examined'], $summary['changed'] ) );
}
