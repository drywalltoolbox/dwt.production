<?php
/**
 * DTB Schematics — post-operation convergence for authoritative projections.
 *
 * Reconciliation, hotspot migration, and product-link refresh are separate
 * bounded operations, but they all mutate fields that participate in public
 * readiness. This service closes that loop after an operator commit so the
 * authoritative record, linked-product projection, lifecycle, and public
 * projection cannot remain stale relative to one another.
 *
 * This is not a second import/resolution authority. It only re-evaluates the
 * existing authoritative record using the existing reconciliation, readiness,
 * lifecycle, and public-projection services.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the current canonical source rows for one schematic when the source
 * package is available. The manifest is resolved once per request so an
 * all-record hotspot synchronization stays O(source + records), not O(source
 * * records).
 *
 * An unavailable operational source package is not a runtime-publication
 * failure by itself; existing authoritative records may remain publicly usable
 * while source storage is temporarily unavailable.
 */
function dtb_schematic_convergence_source_rows( string $canonical_id ): array {
	static $grouped = null;

	$canonical_id = sanitize_key( $canonical_id );
	if ( '' === $canonical_id || ! function_exists( 'dtb_schematics_read_source_manifest' ) ) {
		return [];
	}

	if ( null === $grouped ) {
		$grouped  = [];
		$manifest = dtb_schematics_read_source_manifest();
		if ( ! empty( $manifest['ok'] ) && ! empty( $manifest['rows'] ) ) {
			$rows         = (array) $manifest['rows'];
			$resolved_all = dtb_schematic_reconcile_resolve_all_identities( $rows );
			$grouped      = dtb_schematic_reconcile_source_rows_by_canonical_id( $rows, $resolved_all );
		}
	}

	return (array) ( $grouped[ $canonical_id ] ?? [] );
}

/**
 * Converge one authoritative record after a committed synchronization step.
 *
 * @return array{canonical_id:string,status:string,changed:bool,requirements:array,error:string}
 */
function dtb_schematic_converge_projection( string $canonical_id, bool $projection_dirty = false ): array {
	$canonical_id = sanitize_key( $canonical_id );
	$result       = [
		'canonical_id' => $canonical_id,
		'status'       => 'failed',
		'changed'      => false,
		'requirements' => [],
		'error'        => '',
	];

	if ( '' === $canonical_id ) {
		$result['error'] = 'A canonical schematic ID is required.';
		return $result;
	}

	$record = dtb_schematic_record_repo_find_by_canonical_id( $canonical_id );
	if ( ! $record ) {
		$result['error'] = 'The authoritative schematic record was not found.';
		return $result;
	}
	if ( $record->lifecycle->is_retired() ) {
		$result['status'] = 'retired';
		return $result;
	}

	// Hotspot migration can resolve product identities after reconciliation's
	// earlier linked-product pass. Refresh this projection before readiness so
	// public detail/catalog data cannot lag the authoritative part relations.
	if ( function_exists( 'dtb_schematic_reconcile_refresh_linked_products' ) ) {
		$linked_changed    = (bool) dtb_schematic_reconcile_refresh_linked_products( $record );
		$result['changed'] = $result['changed'] || $linked_changed;
		$projection_dirty  = $projection_dirty || $linked_changed;
		$record            = dtb_schematic_record_repo_find_by_canonical_id( $canonical_id ) ?: $record;
	}

	$requirements = [];
	$source_rows  = dtb_schematic_convergence_source_rows( $canonical_id );
	if ( $source_rows && function_exists( 'dtb_schematic_reconcile_source_requirements' ) ) {
		$requirements = array_merge( $requirements, dtb_schematic_reconcile_source_requirements( $record, $source_rows ) );
	}
	$requirements = array_merge( $requirements, dtb_schematic_runtime_publication_requirements( $record ) );
	$requirements = array_values( array_unique( array_filter( array_map( 'strval', $requirements ) ) ) );
	$result['requirements'] = $requirements;

	if ( $requirements ) {
		$before     = $record->lifecycle->value();
		$incomplete = dtb_schematic_mark_incomplete( $record->id, $requirements );
		if ( is_wp_error( $incomplete ) ) {
			$result['error'] = $incomplete->get_error_message();
			return $result;
		}
		$result['changed'] = $result['changed'] || $before !== $incomplete->lifecycle->value();
		$result['status']  = 'blocked';
		return $result;
	}

	$record = dtb_schematic_record_repo_find_by_canonical_id( $canonical_id ) ?: $record;
	if ( $record->lifecycle->is_published() ) {
		if ( $projection_dirty ) {
			$projection = dtb_schematic_update_published_projection( $record->id );
			if ( is_wp_error( $projection ) ) {
				$result['error'] = $projection->get_error_message();
				return $result;
			}
			$result['changed'] = true;
		}
		$result['status'] = 'published';
		return $result;
	}

	if ( DTB_Schematic_Lifecycle_Status::READY !== $record->lifecycle->value() ) {
		$record = dtb_schematic_mark_ready( $record->id );
		if ( is_wp_error( $record ) ) {
			$result['error'] = $record->get_error_message();
			return $result;
		}
		$result['changed'] = true;
	}

	$published = dtb_schematic_publish( $record->id );
	if ( is_wp_error( $published ) ) {
		$result['error'] = $published->get_error_message();
		return $result;
	}

	$result['changed'] = true;
	$result['status']  = 'published';
	return $result;
}

/**
 * Identify records touched by an operation result and converge them while the
 * operation's commit lease is still held.
 */
function dtb_schematic_converge_operation_result( string $kind, bool $dry_run, array $result ): array {
	if ( $dry_run || ! empty( $result['fatal_error'] ) ) {
		return $result;
	}

	$canonical_ids    = [];
	$projection_dirty = in_array(
		$kind,
		[
			DTB_SCHEMATIC_OPERATION_RECONCILE,
			DTB_SCHEMATIC_OPERATION_MIGRATE_HOTSPOTS,
			DTB_SCHEMATIC_OPERATION_REFRESH_PRODUCTS,
			DTB_SCHEMATIC_OPERATION_OPTIMIZE_HOTSPOTS,
		],
		true
	);

	if ( DTB_SCHEMATIC_OPERATION_RECONCILE === $kind ) {
		foreach ( (array) ( $result['assets'] ?? [] ) as $asset ) {
			$finalization = (array) ( $asset['record_finalization'] ?? [] );
			$canonical_id = sanitize_key( (string) ( $finalization['canonical_id'] ?? '' ) );
			if ( '' !== $canonical_id ) {
				$canonical_ids[] = $canonical_id;
			}
		}
	} else {
		foreach ( (array) ( $result['results'] ?? [] ) as $item ) {
			$status = (string) ( $item['status'] ?? '' );
			if ( in_array( $status, [ 'failed', 'not_found', 'schematic_not_found', 'source_file_missing', 'no_source_file' ], true ) ) {
				continue;
			}
			$canonical_id = sanitize_key( (string) ( $item['canonical_id'] ?? '' ) );
			if ( '' !== $canonical_id ) {
				$canonical_ids[] = $canonical_id;
			}
		}
	}

	$canonical_ids = array_values( array_unique( $canonical_ids ) );
	if ( ! $canonical_ids ) {
		return $result;
	}

	$convergence = [];
	$failed      = 0;
	$blocked     = 0;
	$published   = 0;
	foreach ( $canonical_ids as $canonical_id ) {
		$item          = dtb_schematic_converge_projection( $canonical_id, $projection_dirty );
		$convergence[] = $item;
		if ( 'failed' === $item['status'] ) {
			$failed++;
		} elseif ( 'blocked' === $item['status'] ) {
			$blocked++;
		} elseif ( 'published' === $item['status'] ) {
			$published++;
		}
	}

	$result['convergence']           = $convergence;
	$result['convergence_failed']    = $failed;
	$result['publication_blocked']   = max( (int) ( $result['publication_blocked'] ?? 0 ), $blocked );
	$result['publication_published'] = $published;

	// A readiness-blocked record means the requested synchronization did not
	// reach a usable storefront projection. Count it as a partial operation so
	// existing wp-admin and activity-history success criteria fail closed
	// instead of reporting a misleading green success state.
	if ( $failed + $blocked > 0 ) {
		$result['failed'] = (int) ( $result['failed'] ?? 0 ) + $failed + $blocked;
	}

	return $result;
}
