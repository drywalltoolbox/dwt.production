<?php
/**
 * DTB Schematics — ReconcileSchematicSource (Phase 3 reconciliation engine).
 *
 * The one deterministic reconciliation engine required by the schematics
 * platform rebuild. Compares the canonical source manifest/binaries
 * (Infrastructure/SchematicSourceManifestReader.php) against WordPress
 * attachments, legacy attachment metadata, the authoritative `dtb_schematic`
 * domain records, page relationships, hotspot dataset references, and
 * SKU/product linkage (Data/SkuSchematicMap.php) — and assigns every
 * discovered source asset exactly one DTB_Schematic_Asset_Disposition.
 *
 * Identity resolution (source filename -> canonical schematic ID/page) is
 * delegated entirely to the existing, already-authoritative
 * dtb_schematics_parse_upload_filename() / dtb_schematics_retired_upload_reason()
 * (Application/RegisterSchematicUploads.php). This engine does not
 * reimplement fuzzy filename matching — doing so would create a second,
 * competing identity authority, which is explicitly disallowed.
 *
 * All domain-record writes go through Application/ManageSchematicRecord.php.
 * Nothing in this file calls dtb_schematic_record_repo_save() directly.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Fallback only, used when a source binary cannot be located under any
// wp-content/uploads/{year}/schematics candidate directory (e.g. the
// dev-only repo-relative source package) — in the normal production case
// each row's actual uploads-relative path is derived per-file via
// dtb_schematics_relative_uploads_file_for_filename()
// (Infrastructure/SchematicSourceManifestReader.php), so this is never
// hardcoded to a single year for real uploaded assets.
const DTB_SCHEMATIC_RECONCILE_DEFAULT_UPLOAD_PATH = '2026/schematics';
const DTB_SCHEMATIC_RECONCILE_DEFAULT_BATCH_SIZE  = 25;
const DTB_SCHEMATIC_RECONCILE_MAX_BATCH_SIZE      = 100;

/**
 * Run one bounded batch of the reconciliation pipeline.
 *
 * @param array $args {
 *     @type bool   $dry_run     Default true. When false, eligible dispositions are written.
 *     @type int    $batch_size  Rows processed this call, capped at DTB_SCHEMATIC_RECONCILE_MAX_BATCH_SIZE.
 *     @type bool   $resume      Default true. When false, restarts the pass from row 0.
 *     @type string $upload_path Relative wp-content/uploads path schematic binaries are expected to live under.
 *     @type bool   $retire_uncovered Explicitly allow retirement after a reviewed full pass. Defaults false.
 *     @type bool   $persist_state Whether resumable cursor state may be read/written. Defaults true.
 *     @type array  $state Isolated cursor state for a non-persistent run. Defaults to a fresh pass.
 *     @type callable|null $lease_heartbeat Owner-verified commit lease renewal callback.
 * }
 * @return array Structured result report (see dtb_schematic_reconcile_empty_report()).
 */
function dtb_schematic_reconcile_source( array $args = [] ): array {
	$dry_run     = array_key_exists( 'dry_run', $args ) ? (bool) $args['dry_run'] : true;
	$batch_size  = max( 1, min( DTB_SCHEMATIC_RECONCILE_MAX_BATCH_SIZE, (int) ( $args['batch_size'] ?? DTB_SCHEMATIC_RECONCILE_DEFAULT_BATCH_SIZE ) ) );
	$resume      = array_key_exists( 'resume', $args ) ? (bool) $args['resume'] : true;
	$retire_uncovered = ! empty( $args['retire_uncovered'] );
	$persist_state = array_key_exists( 'persist_state', $args ) ? (bool) $args['persist_state'] : true;
	$upload_path = trim( (string) ( $args['upload_path'] ?? DTB_SCHEMATIC_RECONCILE_DEFAULT_UPLOAD_PATH ), '/' );
	$lease_heartbeat = isset( $args['lease_heartbeat'] ) && is_callable( $args['lease_heartbeat'] ) ? $args['lease_heartbeat'] : null;

	$report = dtb_schematic_reconcile_empty_report( $dry_run );

	$manifest = dtb_schematics_read_source_manifest();
	if ( ! $manifest['ok'] ) {
		$report['fatal_error'] = $manifest['error'];
		return $report;
	}

	$all_rows = $manifest['rows'];
	$report['source_row_count'] = count( $all_rows );

	// Resolve identity for the *entire* manifest up front (bounded — the
	// source package is on the order of a few hundred rows at most) so
	// duplicate-page collisions are detected consistently regardless of
	// which batch window is currently being processed.
	$resolved_all  = dtb_schematic_reconcile_resolve_all_identities( $all_rows );
	$duplicate_keys = dtb_schematic_reconcile_find_duplicate_keys( $resolved_all );
	$last_row_by_canonical_id = dtb_schematic_reconcile_last_row_by_canonical_id( $resolved_all );
	$source_rows_by_canonical_id = dtb_schematic_reconcile_source_rows_by_canonical_id( $all_rows, $resolved_all );

	if ( $persist_state ) {
		$state = $resume ? dtb_schematic_reconcile_state_get() : dtb_schematic_reconcile_state_start_pass();
	} else {
		// A dry-run must not read, advance, reset, or otherwise perturb the
		// shared commit cursor. Its cursor exists only in this request/result.
		$state = dtb_schematic_reconcile_isolated_state( $args['state'] ?? [] );
	}
	$cursor = min( (int) $state['cursor'], count( $all_rows ) );

	$batch = array_slice( $resolved_all, $cursor, $batch_size, true );
	$is_last_batch = ( $cursor + count( $batch ) ) >= count( $all_rows );

	$wp_context = dtb_schematic_reconcile_wp_context_available();
	$report['wordpress_context_available'] = $wp_context;

	$seen_canonical_ids = $state['seen_canonical_ids'];

	foreach ( $batch as $index => $resolved ) {
		if ( $lease_heartbeat ) {
			$renewed = $lease_heartbeat();
			if ( is_wp_error( $renewed ) ) {
				$report['fatal_error'] = $renewed->get_error_message();
				return $report;
			}
		}
		$row = $all_rows[ $index ];
		$asset_report = dtb_schematic_reconcile_process_row(
			$row,
			$resolved,
			$duplicate_keys,
			$wp_context,
			$dry_run,
			$upload_path
		);

		$canonical_id = (string) ( $resolved['canonical_id'] ?? '' );
		if ( ! $dry_run && $wp_context && '' !== $canonical_id && ( $last_row_by_canonical_id[ $canonical_id ] ?? null ) === $index ) {
			$finalization = dtb_schematic_reconcile_finalize_record_pipeline( $canonical_id, $source_rows_by_canonical_id[ $canonical_id ] ?? [] );
			$asset_report['record_finalization'] = $finalization;
			$report['finalized']++;
			if ( 'blocked' === $finalization['status'] ) {
				$report['publication_blocked']++;
			}
			if ( ! empty( $finalization['changed'] ) ) {
				$asset_report['changed'] = true;
			}
		}

		$report['examined']++;
		$report['dispositions'][ $asset_report['disposition'] ] = 1 + ( $report['dispositions'][ $asset_report['disposition'] ] ?? 0 );

		if ( $asset_report['changed'] ) {
			$report['changed']++;
		} elseif ( $asset_report['skipped'] ) {
			$report['skipped']++;
		} elseif ( DTB_Schematic_Asset_Disposition::AMBIGUOUS_AND_UNRESOLVED === $asset_report['disposition'] ) {
			$report['unresolved']++;
		} else {
			$report['unchanged']++;
		}

		$report['assets'][] = $asset_report;

		if ( ! empty( $resolved['canonical_id'] ) && DTB_Schematic_Asset_Disposition::RETIRED !== $asset_report['disposition'] ) {
			$seen_canonical_ids[] = $resolved['canonical_id'];
		}
	}

	$new_cursor = $cursor + count( $batch );
	$state['cursor']             = $is_last_batch ? 0 : $new_cursor;
	$state['seen_canonical_ids'] = $is_last_batch ? [] : array_values( array_unique( $seen_canonical_ids ) );
	$state['last_run_at']        = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	$state['last_run_mode']      = $dry_run ? 'dry_run' : 'commit';

	$report['batch_start']  = $cursor;
	$report['batch_end']    = $new_cursor;
	$report['is_last_batch'] = $is_last_batch;
	$report['next_offset']  = $is_last_batch ? null : $new_cursor;

	// Retirement is opt-in only after a separately reviewed full-pass policy;
	// ordinary reconciliation never changes lifecycle state by omission.
	if ( $is_last_batch && ! $dry_run && $wp_context && $retire_uncovered ) {
		$report['retired'] = dtb_schematic_reconcile_retire_uncovered( array_values( array_unique( $seen_canonical_ids ) ) );
	}

	if ( ! $dry_run && $wp_context ) {
		if ( $lease_heartbeat ) {
			$renewed = $lease_heartbeat();
			if ( is_wp_error( $renewed ) ) {
				$report['fatal_error'] = $renewed->get_error_message();
				return $report;
			}
		}
		dtb_schematics_invalidate_domain_cache();
	}

	if ( $persist_state ) {
		dtb_schematic_reconcile_state_save( $state );
	} else {
		$report['next_state'] = dtb_schematic_reconcile_isolated_state( $state );
	}

	return $report;
}

function dtb_schematic_reconcile_empty_report( bool $dry_run ): array {
	return [
		'dry_run'                     => $dry_run,
		'source_row_count'            => 0,
		'wordpress_context_available' => false,
		'examined'                    => 0,
		'unchanged'                   => 0,
		'changed'                     => 0,
		'skipped'                     => 0,
		'unresolved'                  => 0,
		'retired'                     => 0,
		'finalized'                   => 0,
		'publication_blocked'         => 0,
		'dispositions'                => [],
		'assets'                      => [],
		'batch_start'                 => 0,
		'batch_end'                   => 0,
		'is_last_batch'               => true,
		'next_offset'                 => null,
		'fatal_error'                 => null,
	];
}

/**
 * A live WordPress runtime is required for every attachment/domain-record
 * comparison and for all writes. This repository checkout has no such
 * runtime, so a dry-run here can only validate manifest parsing and identity
 * resolution — see the static harness at
 * scripts/catalog/reconcile_schematics_dry_run_harness.php.
 */
function dtb_schematic_reconcile_wp_context_available(): bool {
	return function_exists( 'get_posts' ) && function_exists( 'wp_upload_dir' ) && function_exists( 'dtb_schematic_record_repo_find_by_canonical_id' );
}

/**
 * Resolve canonical identity for every manifest row via the existing
 * authoritative filename resolver. Returns a map keyed by the same index as
 * $rows so batches can be sliced consistently.
 *
 * @return array<int, array{retired_reason:?string, canonical_id:?string, type:?string, page:?int, error:?string}>
 */
function dtb_schematic_reconcile_resolve_all_identities( array $rows ): array {
	$resolved = [];
	foreach ( $rows as $index => $row ) {
		$filename = $row['filename'];
		$retired_reason = dtb_schematics_retired_upload_reason( $filename );
		if ( null !== $retired_reason ) {
			$resolved[ $index ] = [
				'retired_reason' => $retired_reason,
				'canonical_id'   => null,
				'type'           => null,
				'page'           => null,
				'error'          => null,
			];
			continue;
		}

		$parsed = dtb_schematics_parse_upload_filename( $filename );
		if ( is_wp_error( $parsed ) ) {
			$resolved[ $index ] = [
				'retired_reason' => null,
				'canonical_id'   => null,
				'type'           => null,
				'page'           => null,
				'error'          => $parsed->get_error_message(),
			];
			continue;
		}

		$resolved[ $index ] = [
			'retired_reason' => null,
			'canonical_id'   => $parsed['schematic_id'],
			'type'           => $parsed['type'],
			'page'           => (int) $parsed['page'],
			'error'          => null,
		];
	}
	return $resolved;
}

/**
 * @return array<string,int[]> Map of "{canonical_id}|{page}" -> row indexes, for keys with more than one row.
 */
function dtb_schematic_reconcile_find_duplicate_keys( array $resolved_all ): array {
	$by_key = [];
	foreach ( $resolved_all as $index => $resolved ) {
		if ( null === $resolved['canonical_id'] ) {
			continue;
		}
		$key = $resolved['canonical_id'] . '|' . $resolved['page'];
		$by_key[ $key ][] = $index;
	}
	return array_filter( $by_key, static fn( $indexes ) => count( $indexes ) > 1 );
}

/** Return the final manifest row index for every resolved canonical record. */
function dtb_schematic_reconcile_last_row_by_canonical_id( array $resolved_all ): array {
	$last_rows = [];
	foreach ( $resolved_all as $index => $resolved ) {
		$canonical_id = (string) ( $resolved['canonical_id'] ?? '' );
		if ( '' !== $canonical_id ) {
			$last_rows[ $canonical_id ] = $index;
		}
	}
	return $last_rows;
}

/** Group manifest rows with their resolved page identity for final readiness. */
function dtb_schematic_reconcile_source_rows_by_canonical_id( array $rows, array $resolved_all ): array {
	$grouped = [];
	foreach ( $resolved_all as $index => $resolved ) {
		$canonical_id = (string) ( $resolved['canonical_id'] ?? '' );
		if ( '' !== $canonical_id ) {
			$grouped[ $canonical_id ][] = [
				'row'      => $rows[ $index ],
				'resolved' => $resolved,
			];
		}
	}
	return $grouped;
}

/**
 * Process a single manifest row and return its per-asset report, applying
 * writes when $dry_run is false and the disposition is writable.
 */
function dtb_schematic_reconcile_process_row( array $row, array $resolved, array $duplicate_keys, bool $wp_context, bool $dry_run, string $upload_path ): array {
	$asset = [
		'source_filename' => $row['filename'],
		'source_schematic_manifest_id' => $row['schematic_id'],
		'sku_or_alias'    => $row['sku_or_alias'],
		'brand'           => $row['brand'],
		'canonical_id'    => $resolved['canonical_id'],
		'page'            => $resolved['page'],
		'disposition'     => DTB_Schematic_Asset_Disposition::AMBIGUOUS_AND_UNRESOLVED,
		'notes'           => [],
		'changed'         => false,
		'skipped'         => false,
	];
	if ( ! dtb_schematics_source_filename_is_safe( (string) ( $row['filename'] ?? '' ) ) ) {
		$asset['notes'][] = 'Source filenames must be a single supported image filename without path separators or control bytes.';
		$asset['skipped'] = true;
		return $asset;
	}

	if ( null !== $resolved['retired_reason'] ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::RETIRED;
		$asset['notes'][]     = $resolved['retired_reason'];
		$asset['skipped']     = true;
		return $asset;
	}

	if ( null !== $resolved['error'] ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::AMBIGUOUS_AND_UNRESOLVED;
		$asset['notes'][]     = $resolved['error'];
		return $asset;
	}

	$canonical_id = $resolved['canonical_id'];
	$page         = $resolved['page'];
	$key          = $canonical_id . '|' . $page;

	if ( isset( $duplicate_keys[ $key ] ) ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::DUPLICATE_SCHEMATIC_PAGE;
		$asset['notes'][]     = 'Multiple source rows resolve to the same schematic/page; no write performed until an operator resolves the collision.';
		return $asset;
	}

	// Source binary check — bounded to this row's file only.
	$binary = dtb_schematics_describe_source_binary( $row['filename'], true );
	if ( ! $binary['exists'] ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::MISSING_SOURCE_BINARY;
		$asset['notes'][]     = 'Source manifest references a file that is not present in the source package.';
		return $asset;
	}
	if ( '' !== $row['checksum_sha256'] && $binary['checksum_sha256'] !== $row['checksum_sha256'] ) {
		$asset['notes'][] = 'Source binary checksum does not match the manifest (source drift) — flagged, not auto-corrected.';
	}

	if ( ! $wp_context ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::SOURCE_ONLY;
		$asset['notes'][]     = 'No WordPress runtime in this process — attachment/domain-record state could not be verified.';
		return $asset;
	}

	// Prefer the uploads-relative path actually derived from where this
	// row's source binary was found (correct regardless of which
	// wp-content/uploads/{year}/schematics directory it lives under); fall
	// back to the caller-supplied/default $upload_path only when the binary
	// was not found under any candidate uploads directory at all (e.g. it
	// only exists in the dev-only repo-relative source package).
	$derived_relative_file = dtb_schematics_relative_uploads_file_for_filename( $row['filename'] );
	$effective_upload_path = $derived_relative_file ? dirname( $derived_relative_file ) : $upload_path;

	return dtb_schematic_reconcile_process_row_with_wp( $asset, $row, $canonical_id, $page, $dry_run, $effective_upload_path );
}

/**
 * WordPress-runtime-dependent half of row processing. Split out so the
 * WP-independent parsing/duplicate/source-binary logic above stays testable
 * without a WordPress bootstrap.
 */
function dtb_schematic_reconcile_process_row_with_wp( array $asset, array $row, string $canonical_id, int $page, bool $dry_run, string $upload_path ): array {
	$relative_file = $upload_path . '/' . $row['filename'];
	$attachment_id = dtb_schematics_find_attachment_by_relative_file( $relative_file );

	$record  = dtb_schematic_record_repo_find_by_canonical_id( $canonical_id );
	$page_id = sanitize_key( $canonical_id . '-page-' . $page );

	if ( 0 === $attachment_id ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::MISSING_WORDPRESS_ATTACHMENT;
		$asset['notes'][]     = sprintf( 'No attachment found for expected uploads path "%s".', $relative_file );

		if ( ! $dry_run ) {
			$created = dtb_schematic_reconcile_create_attachment_from_uploads( $relative_file, $canonical_id, $page );
			if ( is_wp_error( $created ) ) {
				$asset['notes'][] = $created->get_error_message();
			} else {
				$attachment_id = $created;
				$asset['notes'][] = sprintf( 'Created attachment #%d from existing uploads file.', $attachment_id );
			}
		}
	}

	if ( 0 === $attachment_id ) {
		// Still nothing to attach to — the binary only exists in the source
		// package, not yet copied into wp-content/uploads. Reported, not written.
		return dtb_schematic_reconcile_finalize_asset( $asset, $record, $canonical_id, $page, $page_id, null, $dry_run, $row );
	}

	$legacy_schematic_id = (string) get_post_meta( $attachment_id, '_dtb_schematic_id', true );
	$legacy_page         = (string) get_post_meta( $attachment_id, '_dtb_schematic_page', true );
	$legacy_is_schematic = (string) get_post_meta( $attachment_id, '_dtb_is_schematic', true );

	$asset['attachment_id'] = $attachment_id;

	if ( '' !== $legacy_schematic_id && $legacy_schematic_id !== $canonical_id ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::REGISTERED_TO_WRONG_SCHEMATIC;
		$asset['notes'][]     = sprintf( 'Attachment #%d legacy identity is "%s", expected "%s". Not auto-corrected.', $attachment_id, $legacy_schematic_id, $canonical_id );
		return $asset;
	}

	if ( '' !== $legacy_schematic_id && (int) $legacy_page !== $page ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::REGISTERED_TO_WRONG_PAGE;
		$asset['notes'][]     = sprintf( 'Attachment #%d legacy page is "%s", expected "%d".', $attachment_id, $legacy_page, $page );
		// Writable: legacy page metadata is corrected (not the domain page — the
		// domain record itself is the source of truth and is reconciled below).
	}

	if ( '' === $legacy_schematic_id && '1' === $legacy_is_schematic ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::ATTACHED_BUT_UNIDENTIFIED;
		$asset['notes'][]     = sprintf( 'Attachment #%d has legacy `_dtb_is_schematic` flag but no resolvable schematic identity.', $attachment_id );
	}

	return dtb_schematic_reconcile_finalize_asset( $asset, $record, $canonical_id, $page, $page_id, $attachment_id, $dry_run, $row );
}

/**
 * Compare the resolved source truth against the domain record's current page
 * state, assign the remaining dispositions (active/synchronized, missing
 * metadata, uploaded-but-unattached), and perform the write when eligible.
 *
 * @param array $row Source manifest row for this asset (carries `filename`
 *                    and `checksum_sha256` so the domain page's
 *                    `source_checksum`/`source_filename` fields stay in sync
 *                    with the canonical source manifest — see
 *                    Infrastructure/SchematicSourceManifestReader.php).
 */
function dtb_schematic_reconcile_finalize_asset( array $asset, ?DTB_Schematic_Record_Entity $record, string $canonical_id, int $page, string $page_id, ?int $attachment_id, bool $dry_run, array $row = [] ): array {
	if ( $record && $record->lifecycle->is_retired() ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::RETIRED;
		$asset['notes'][]     = 'Owning schematic record is retired; not reactivated automatically.';
		return $asset;
	}

	if ( DTB_Schematic_Asset_Disposition::AMBIGUOUS_AND_UNRESOLVED !== $asset['disposition']
		&& in_array(
			$asset['disposition'],
			[ DTB_Schematic_Asset_Disposition::REGISTERED_TO_WRONG_SCHEMATIC, DTB_Schematic_Asset_Disposition::ATTACHED_BUT_UNIDENTIFIED ],
			true
		)
	) {
		// Collision/unidentified states are never auto-written.
		return $asset;
	}

	$existing_page = null;
	if ( $record ) {
		foreach ( $record->pages as $existing ) {
			if ( $existing['page_id'] === $page_id ) {
				$existing_page = $existing;
				break;
			}
		}
	}

	if ( null === $attachment_id ) {
		if ( DTB_Schematic_Asset_Disposition::MISSING_WORDPRESS_ATTACHMENT !== $asset['disposition'] ) {
			$asset['disposition'] = DTB_Schematic_Asset_Disposition::MISSING_WORDPRESS_ATTACHMENT;
		}
		return $asset;
	}

	$described = dtb_schematic_attachment_repo_describe( $attachment_id );
	$has_dimensions = $described && $described['exists'] && $described['width'] > 0 && $described['height'] > 0;
	if ( ! $dry_run && ! $has_dimensions ) {
		$metadata_repair = dtb_schematic_reconcile_repair_attachment_metadata( $attachment_id );
		if ( is_wp_error( $metadata_repair ) ) {
			$asset['notes'][] = $metadata_repair->get_error_message();
		} elseif ( $metadata_repair ) {
			$asset['changed'] = true;
			$asset['notes'][] = sprintf( 'Rebuilt metadata for attachment #%d after an interrupted registration.', $attachment_id );
			$described      = dtb_schematic_attachment_repo_describe( $attachment_id );
			$has_dimensions = $described && $described['exists'] && $described['width'] > 0 && $described['height'] > 0;
		}
	}

	$already_synchronized = $existing_page
		&& (int) $existing_page['attachment_id'] === $attachment_id
		&& (int) $existing_page['page_number'] === $page
		&& $has_dimensions;

	if ( $already_synchronized ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::ACTIVE_AND_SYNCHRONIZED;
		return $asset;
	}

	if ( $existing_page && (int) $existing_page['attachment_id'] === $attachment_id && ! $has_dimensions ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::MISSING_MEDIA_METADATA;
	} elseif ( DTB_Schematic_Asset_Disposition::REGISTERED_TO_WRONG_PAGE !== $asset['disposition'] ) {
		$asset['disposition'] = DTB_Schematic_Asset_Disposition::UPLOADED_BUT_UNATTACHED;
	}

	if ( $dry_run ) {
		return $asset;
	}

	if ( ! in_array( $asset['disposition'], DTB_Schematic_Asset_Disposition::writable(), true ) ) {
		return $asset;
	}

	$write_result = dtb_schematic_reconcile_write_row( $record, $canonical_id, $page, $page_id, $attachment_id, $row );
	if ( is_wp_error( $write_result ) ) {
		$asset['notes'][] = $write_result->get_error_message();
		return $asset;
	}

	if ( $write_result['record_changed'] ) {
		$asset['changed'] = true;
		$asset['notes'][] = 'Domain record page attached/updated via ManageSchematicRecord.';
	}

	return $asset;
}

/**
 * Finalize one record only after its final manifest page has reconciled.
 * Metadata, product linkage, hotspot migration, exact part resolution and
 * runtime media readiness must all succeed before publication.
 *
 * @return array{canonical_id:string,status:string,changed:bool,requirements:array,hotspots:array,error:string}
 */
function dtb_schematic_reconcile_finalize_record_pipeline( string $canonical_id, array $source_rows = [] ): array {
	$result = [
		'canonical_id' => $canonical_id,
		'status'       => 'failed',
		'changed'      => false,
		'requirements' => [],
		'hotspots'     => [],
		'error'        => '',
	];
	$record = dtb_schematic_record_repo_find_by_canonical_id( $canonical_id );
	if ( ! $record ) {
		$result['error'] = 'The authoritative schematic record was not created.';
		return $result;
	}
	if ( $record->lifecycle->is_retired() ) {
		$result['status'] = 'retired';
		return $result;
	}

	$enriched = dtb_schematic_enrich_metadata( $record );
	if ( is_wp_error( $enriched ) ) {
		$result['error'] = $enriched->get_error_message();
		return $result;
	}
	$record            = $enriched['record'];
	$result['changed'] = $result['changed'] || $enriched['changed'];

	$family = dtb_schematic_reconcile_backfill_family( $record );
	if ( $family ) {
		$record            = $family;
		$result['changed'] = true;
	}

	if ( dtb_schematic_reconcile_refresh_linked_products( $record ) ) {
		$result['changed'] = true;
		$record = dtb_schematic_record_repo_get( $record->id ) ?: $record;
	}

	$result['hotspots'] = dtb_schematic_migrate_hotspot_dataset_for_record( $record, false );
	if ( 'failed' === $result['hotspots']['status'] ) {
		$result['error'] = (string) $result['hotspots']['detail'];
		return $result;
	}
	if ( 'migrated' === $result['hotspots']['status'] ) {
		$result['changed'] = true;
	}
	$record = dtb_schematic_record_repo_get( $record->id ) ?: $record;

	$result['requirements'] = array_merge(
		dtb_schematic_reconcile_source_requirements( $record, $source_rows ),
		dtb_schematic_runtime_publication_requirements( $record )
	);
	if ( 'source_file_missing' === $result['hotspots']['status'] ) {
		$result['requirements'][] = 'hotspot_source_file_missing';
	}
	$result['requirements'] = array_values( array_unique( $result['requirements'] ) );
	if ( ! empty( $result['requirements'] ) ) {
		$incomplete = dtb_schematic_mark_incomplete( $record->id, $result['requirements'] );
		if ( is_wp_error( $incomplete ) ) {
			$result['error'] = $incomplete->get_error_message();
			return $result;
		}
		$result['changed'] = $result['changed'] || $incomplete->lifecycle->value() !== $record->lifecycle->value();
		$result['status']  = 'blocked';
		return $result;
	}

	if ( $record->lifecycle->is_published() ) {
		if ( $result['changed'] ) {
			$projection = dtb_schematic_update_published_projection( $record->id );
			if ( is_wp_error( $projection ) ) {
				$result['error'] = $projection->get_error_message();
				return $result;
			}
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

/** Confirm every canonical manifest page reached the authoritative record. */
function dtb_schematic_reconcile_source_requirements( DTB_Schematic_Record_Entity $record, array $source_rows ): array {
	$unmet = [];
	$pages = [];
	foreach ( $record->pages as $page ) {
		$pages[ (int) $page['page_number'] ] = $page;
	}
	$seen = [];
	foreach ( $source_rows as $source ) {
		$page_number = (int) ( $source['resolved']['page'] ?? 0 );
		if ( $page_number < 1 || isset( $seen[ $page_number ] ) ) {
			$unmet[] = 'canonical_source_page_collision:' . $page_number;
			continue;
		}
		$seen[ $page_number ] = true;
		$binary = dtb_schematics_describe_source_binary( (string) ( $source['row']['filename'] ?? '' ), true );
		if ( empty( $binary['exists'] ) ) {
			$unmet[] = 'canonical_source_binary_missing:' . $page_number;
		}
		if ( ! isset( $pages[ $page_number ] ) ) {
			$unmet[] = 'canonical_source_page_unreconciled:' . $page_number;
			continue;
		}
		$expected_checksum = (string) ( $source['row']['checksum_sha256'] ?? '' );
		if ( '' !== $expected_checksum && $expected_checksum !== (string) ( $pages[ $page_number ]['source_checksum'] ?? '' ) ) {
			$unmet[] = 'canonical_source_checksum_mismatch:' . $page_number;
		}
	}
	return $unmet;
}

/**
 * Backfill family_id/variant_label from DTB_SCHEMATIC_FAMILY_MAP for a
 * record that doesn't have one yet. Mirrors the brand/category backfill
 * pattern above: idempotent (only writes when the map actually has an entry
 * this record is missing), never overwrites an operator-set value, and
 * failure is logged rather than silently swallowed.
 *
 * @return DTB_Schematic_Record_Entity|null The updated record, or null if nothing changed.
 */
function dtb_schematic_reconcile_backfill_family( DTB_Schematic_Record_Entity $record ): ?DTB_Schematic_Record_Entity {
	$family = DTB_SCHEMATIC_FAMILY_MAP[ $record->canonical_id ] ?? null;
	if ( ! $family ) {
		return null; // No family grouping known for this id — not every schematic belongs to a multi-variant family.
	}

	$backfill = [];
	if ( '' === trim( $record->family_id ) && '' !== trim( (string) $family['family_id'] ) ) {
		$backfill['family_id'] = $family['family_id'];
	}
	if ( '' === trim( $record->variant_label ) && '' !== trim( (string) $family['variant_label'] ) ) {
		$backfill['variant_label'] = $family['variant_label'];
	}
	if ( empty( $backfill ) ) {
		return null;
	}

	$updated = dtb_schematic_update( $record->id, $backfill );
	if ( is_wp_error( $updated ) ) {
		error_log( sprintf( '[dtb-schematics] family backfill failed for %s: %s', $record->canonical_id, $updated->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return null;
	}

	return $updated;
}

/**
 * The only place in this engine that calls into Application/ManageSchematicRecord.php
 * for a given manifest row. Idempotent: only calls a write function when the
 * target state actually differs from the current stored state.
 *
 * @param array $row Source manifest row for this asset; its `checksum_sha256`
 *                    and `filename` are written through to the domain page's
 *                    `source_checksum`/`source_filename` fields so the public
 *                    API's versioned media URLs stay keyed off the real
 *                    source checksum (see Rest/SchematicPublicApiController.php
 *                    dtb_schematics_public_api_versioned_url()).
 * @return array{record_changed:bool}|WP_Error
 */
function dtb_schematic_reconcile_write_row( ?DTB_Schematic_Record_Entity $record, string $canonical_id, int $page, string $page_id, int $attachment_id, array $row = [] ) {
	$record_changed   = false;
	$source_checksum  = (string) ( $row['checksum_sha256'] ?? '' );
	$source_filename  = (string) ( $row['filename'] ?? '' );

	if ( ! $record ) {
		$create_data = [
			'canonical_id'      => $canonical_id,
			'title'             => dtb_schematic_reconcile_default_title( $canonical_id ),
			'source_provenance' => [ 'source' => 'reconciliation_pipeline', 'created_at' => gmdate( 'c' ) ],
		];
		$brand_category = DTB_SCHEMATIC_BRAND_CATEGORY_MAP[ $canonical_id ] ?? null;
		if ( $brand_category ) {
			$create_data['brand_id']    = $brand_category['brand_id'];
			$create_data['category_id'] = $brand_category['category_id'];
		}
		$family = DTB_SCHEMATIC_FAMILY_MAP[ $canonical_id ] ?? null;
		if ( $family ) {
			if ( '' !== trim( (string) $family['family_id'] ) ) {
				$create_data['family_id'] = $family['family_id'];
			}
			if ( '' !== trim( (string) $family['variant_label'] ) ) {
				$create_data['variant_label'] = $family['variant_label'];
			}
		}
		$created = dtb_schematic_create( $create_data );
		if ( is_wp_error( $created ) ) {
			return $created;
		}
		$record          = $created;
		$record_changed  = true;
	} elseif ( '' === trim( $record->brand_id ) || '' === trim( $record->category_id ) ) {
		// Backfill records created before DTB_SCHEMATIC_BRAND_CATEGORY_MAP
		// existed; publication requires both fields
		// (Domain/SchematicPublicationRules.php) and reconciliation is the
		// only writer of these ids, so a record with neither ever set stays
		// unpublishable forever without this pass.
		$brand_category = DTB_SCHEMATIC_BRAND_CATEGORY_MAP[ $canonical_id ] ?? null;
		if ( $brand_category ) {
			$backfill = [];
			if ( '' === trim( $record->brand_id ) ) {
				$backfill['brand_id'] = $brand_category['brand_id'];
			}
			if ( '' === trim( $record->category_id ) ) {
				$backfill['category_id'] = $brand_category['category_id'];
			}
			$updated = dtb_schematic_update( $record->id, $backfill );
			if ( is_wp_error( $updated ) ) {
				error_log( sprintf( '[dtb-schematics] brand/category backfill failed for %s: %s', $canonical_id, $updated->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			} else {
				$record         = $updated;
				$record_changed = true;
			}
		} else {
			// No catalog-derived mapping for this id — surfaced so a real
			// catalog/map gap doesn't look identical to "already backfilled".
			error_log( sprintf( '[dtb-schematics] no brand/category mapping available for %s; publication will stay blocked until scripts/catalog/gen_sku_schematic_map.py is regenerated with a row for this id.', $canonical_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
	// Customer-facing metadata and lifecycle publication are finalized once,
	// after the record's final canonical manifest page has reconciled.

	$existing_page = null;
	foreach ( $record->pages as $existing ) {
		if ( $existing['page_id'] === $page_id ) {
			$existing_page = $existing;
			break;
		}
	}

	$needs_attach = ! $existing_page
		|| (int) $existing_page['attachment_id'] !== $attachment_id
		|| (int) $existing_page['page_number'] !== $page;

	// Never let a manifest row missing a checksum wipe out a previously
	// recorded good one — the checksum only ever moves forward from a real
	// value to another real value.
	if ( '' === $source_checksum && $existing_page ) {
		$source_checksum = (string) $existing_page['source_checksum'];
	}
	if ( '' === $source_filename && $existing_page ) {
		$source_filename = (string) $existing_page['source_filename'];
	}

	if ( $needs_attach ) {
		$updated = dtb_schematic_attach_page(
			$record->id,
			[
				'page_id'         => $page_id,
				'page_number'     => $page,
				'label'           => sprintf( 'Page %d', $page ),
				'attachment_id'   => $attachment_id,
				'source_checksum' => $source_checksum,
				'source_filename' => $source_filename,
			]
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$record         = $updated;
		$record_changed = true;
	} elseif ( ! dtb_schematic_page_is_metadata_current( $existing_page, $attachment_id )
		|| ( '' !== $source_checksum && $existing_page['source_checksum'] !== $source_checksum ) ) {
		// Metadata-only refresh (dimensions/sources/checksum) without disturbing ordering.
		$updated = dtb_schematic_attach_page(
			$record->id,
			[
				'page_id'         => $page_id,
				'page_number'     => $page,
				'label'           => $existing_page['label'],
				'attachment_id'   => $attachment_id,
				'source_checksum' => $source_checksum,
				'source_filename' => $source_filename,
			]
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		$record         = $updated;
		$record_changed = true;
	}

	$hotspot_result = dtb_schematic_reconcile_associate_hotspot_dataset( $record );
	$record_changed = $record_changed || $hotspot_result;

	$products_result = dtb_schematic_reconcile_refresh_linked_products( $record );
	$record_changed  = $record_changed || $products_result;

	return [ 'record_changed' => $record_changed ];
}

function dtb_schematic_page_is_metadata_current( array $page, int $attachment_id ): bool {
	$described = dtb_schematic_attachment_repo_describe( $attachment_id );
	if ( ! $described || ! $described['exists'] ) {
		return true; // Nothing we can refresh.
	}
	return (int) $page['width'] === (int) $described['width'] && (int) $page['height'] === (int) $described['height'];
}

function dtb_schematic_reconcile_default_title( string $canonical_id ): string {
	return ucwords( str_replace( '-', ' ', $canonical_id ) );
}

/**
 * Best-effort, existence-check-only hotspot dataset association. Never
 * parses JSON content (Phase 5's responsibility) — only records where a
 * plausible dataset file lives, if one can be found deterministically.
 * Returns true if the association changed anything.
 */
function dtb_schematic_reconcile_associate_hotspot_dataset( DTB_Schematic_Record_Entity $record ): bool {
	if ( '' !== $record->hotspot_dataset['reference'] ) {
		return false; // Already associated; this pipeline never overwrites an existing pointer.
	}

	$located = dtb_schematic_reconcile_locate_hotspot_dataset_file( $record->canonical_id, $record->brand_name ?: $record->brand_id );
	if ( null === $located ) {
		return false;
	}

	$result = dtb_schematic_associate_hotspot_dataset(
		$record->id,
		[
			'type'      => 'frontend_json',
			'reference' => $located,
		]
	);

	return ! is_wp_error( $result );
}

/**
 * Deterministic multi-file source groups inherited from the legacy viewer.
 * Each entry declares which source document owns each public schematic page;
 * migration combines them into one normalized record-owned dataset.
 */
function dtb_schematic_reconcile_hotspot_source_group( string $canonical_id ): array {
	if ( ! defined( 'DTB_SCHEMATIC_HOTSPOT_SOURCE_MAP' ) ) {
		return [];
	}
	$entries = DTB_SCHEMATIC_HOTSPOT_SOURCE_MAP[ $canonical_id ] ?? [];
	return is_array( $entries ) ? $entries : [];
}

/**
 * Heuristic (not authoritative) search for a frontend hotspot dataset file
 * matching a canonical schematic ID, under frontend/public/brands/**\/schematic_data.json.
 * Existence-check only — the JSON content is never parsed here.
 */
function dtb_schematic_reconcile_locate_hotspot_dataset_file( string $canonical_id, string $brand ): ?string {
	$group = dtb_schematic_reconcile_hotspot_source_group( $canonical_id );
	if ( $group ) {
		return $group[0]['reference'];
	}
	static $index = null;

	if ( null === $index ) {
		$index = [];
		if ( function_exists( 'dtb_schematic_hotspot_enumerate_source_files' ) ) {
			$index = dtb_schematic_hotspot_enumerate_source_files();
		}
	}

	$tokens = array_filter( explode( '-', preg_replace( '/[^a-z0-9-]/', '', strtolower( $canonical_id ) ) ) );
	$tokens = array_diff( $tokens, [ strtolower( preg_replace( '/[^a-z0-9]/', '', $brand ) ) ] );
	if ( empty( $tokens ) ) {
		return null;
	}

	foreach ( $index as $path ) {
		$path_tokens = strtolower( preg_replace( '/[^a-z0-9]+/', '', $path ) );
		$all_match   = true;
		foreach ( $tokens as $token ) {
			if ( strlen( $token ) < 3 ) {
				continue;
			}
			if ( false === strpos( $path_tokens, $token ) ) {
				$all_match = false;
				break;
			}
		}
		if ( $all_match ) {
			return $path;
		}
	}

	return null;
}

/**
 * Refresh the schematic's linked-product projection from Data/SkuSchematicMap.php.
 * Read-only against WooCommerce (wc_get_product_id_by_sku); never creates or
 * modifies WooCommerce products. Returns true if the stored list changed.
 */
function dtb_schematic_reconcile_linked_product_ids( DTB_Schematic_Record_Entity $record ): array {
	if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
		return [];
	}

	$product_ids = [];
	foreach ( DTB_SKU_SCHEMATIC_MAP as $sku => $mapped ) {
		if ( $mapped['schematic_id'] !== $record->canonical_id ) {
			continue;
		}
		$product_id = (int) wc_get_product_id_by_sku( $sku );
		if ( $product_id > 0 ) {
			$product_ids[] = $product_id;
		}
	}
	$product_ids = array_values( array_unique( $product_ids ) );
	sort( $product_ids );

	return $product_ids;
}

/**
 * Normalize non-persistent cursor state for a dry-run. This deliberately
 * does not delegate to the option-backed state store.
 */
function dtb_schematic_reconcile_isolated_state( $state ): array {
	$state = is_array( $state ) ? $state : [];
	$seen  = array_slice(
		array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $state['seen_canonical_ids'] ?? [] ) ) ) ) ),
		0,
		5000
	);
	return [
		'cursor'             => max( 0, (int) ( $state['cursor'] ?? 0 ) ),
		'pass_started_at'    => '',
		'seen_canonical_ids' => $seen,
		'last_run_at'        => '',
		'last_run_mode'      => 'dry_run',
		'totals'             => [],
	];
}

function dtb_schematic_reconcile_refresh_linked_products( DTB_Schematic_Record_Entity $record ): bool {
	$product_ids = dtb_schematic_reconcile_linked_product_ids( $record );
	$current     = $record->linked_products;
	sort( $current );

	if ( $current === $product_ids ) {
		return false;
	}

	$result = dtb_schematic_update( $record->id, [ 'linked_products' => $product_ids ] );
	return ! is_wp_error( $result );
}

/**
 * Create a WordPress attachment from a file already present under
 * wp-content/uploads/{relative_file}. Does not copy the canonical source
 * binary into uploads — that copy is an external deployment step (the
 * canonical source package and the live uploads directory are documented as
 * separate locations; see docs/_working/schematics_prompt.md, "CURRENT
 * SOURCE AND RUNTIME CONTEXT").
 *
 * @return int|WP_Error New attachment ID, or WP_Error if the uploads file does not exist.
 */
function dtb_schematic_reconcile_create_attachment_from_uploads( string $relative_file, string $canonical_id, int $page ) {
	$uploads  = wp_upload_dir();
	if ( is_wp_error( $uploads ) || ! is_array( $uploads ) || empty( $uploads['basedir'] ) ) {
		$message = is_wp_error( $uploads ) ? $uploads->get_error_message() : 'The WordPress uploads directory is unavailable.';
		return new WP_Error( 'dtb_schematic_uploads_unavailable', $message );
	}
	$relative_file = trim( str_replace( '\\', '/', $relative_file ), '/' );
	if ( '' === $relative_file || false !== strpos( $relative_file, '..' ) || ! preg_match( '#^[A-Za-z0-9._/-]+$#', $relative_file ) ) {
		return new WP_Error( 'dtb_schematic_uploads_path_invalid', 'The attachment source must be a normalized relative uploads path.' );
	}
	$abs_path = trailingslashit( $uploads['basedir'] ) . $relative_file;

	if ( ! is_file( $abs_path ) ) {
		return new WP_Error( 'dtb_schematic_uploads_binary_not_present', sprintf( 'No file at uploads path "%s" — cannot create an attachment without the binary already present in wp-content/uploads.', $relative_file ) );
	}
	$uploads_root = realpath( (string) $uploads['basedir'] );
	$source_path  = realpath( $abs_path );
	if ( false === $uploads_root || false === $source_path || 0 !== strpos( str_replace( '\\', '/', $source_path ), trailingslashit( str_replace( '\\', '/', $uploads_root ) ) ) ) {
		return new WP_Error( 'dtb_schematic_uploads_path_outside_root', 'The attachment source resolved outside the WordPress uploads directory.' );
	}
	$abs_path = $source_path;

	$filetype = wp_check_filetype( basename( $abs_path ), null );
	if ( empty( $filetype['type'] ) || 0 !== strpos( (string) $filetype['type'], 'image/' ) ) {
		return new WP_Error( 'dtb_schematic_unsupported_mime', 'Unsupported image MIME type.' );
	}

	$attachment_id = wp_insert_attachment(
		[
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_text_field( $canonical_id . ' page ' . $page ),
			'post_status'    => 'inherit',
		],
		$abs_path
	);
	if ( is_wp_error( $attachment_id ) ) {
		return $attachment_id;
	}

	update_post_meta( $attachment_id, '_wp_attached_file', $relative_file );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = dtb_schematic_generate_attachment_metadata_full_resolution( $attachment_id, $abs_path );
	if ( is_array( $metadata ) ) {
		wp_update_attachment_metadata( $attachment_id, $metadata );
	}

	return (int) $attachment_id;
}

/**
 * Exploded-view schematic diagrams are viewed at deep zoom (200-400%) in the
 * frontend viewer, so they need their true uploaded resolution to stay
 * crisp — unlike ordinary content images, WordPress's default "big image"
 * downscale (`big_image_size_threshold`, 2560px on the long edge) is actively
 * harmful here: it silently replaces the attachment's main file/dimensions
 * with a capped `-scaled` derivative. Schematic responses use the original
 * attachment URL for deep zoom, so generating every registered intermediate
 * size adds substantial Imagick work without a consumer. Both behaviors are
 * disabled only for the single wp_generate_attachment_metadata() call this
 * wraps; other media retains the normal WordPress image-size policy.
 */
function dtb_schematic_generate_attachment_metadata_full_resolution( int $attachment_id, string $abs_path ) {
	add_filter( 'big_image_size_threshold', 'dtb_schematic_disable_big_image_size_threshold' );
	add_filter( 'intermediate_image_sizes_advanced', 'dtb_schematic_disable_intermediate_image_sizes', 10, 3 );
	try {
		return wp_generate_attachment_metadata( $attachment_id, $abs_path );
	} finally {
		remove_filter( 'intermediate_image_sizes_advanced', 'dtb_schematic_disable_intermediate_image_sizes', 10 );
		remove_filter( 'big_image_size_threshold', 'dtb_schematic_disable_big_image_size_threshold' );
	}
}

/**
 * @see dtb_schematic_generate_attachment_metadata_full_resolution()
 */
function dtb_schematic_disable_big_image_size_threshold() {
	return false;
}

/** Keep schematic registration metadata-only; the original is the public source. */
function dtb_schematic_disable_intermediate_image_sizes( array $sizes ): array {
	return [];
}

/**
 * Repair metadata for an attachment inserted by an interrupted prior run.
 *
 * @return true|WP_Error True when metadata was rebuilt.
 */
function dtb_schematic_reconcile_repair_attachment_metadata( int $attachment_id ) {
	$abs_path = get_attached_file( $attachment_id );
	if ( ! is_string( $abs_path ) || '' === $abs_path || ! is_file( $abs_path ) ) {
		return new WP_Error( 'dtb_schematic_attachment_binary_missing', sprintf( 'Attachment #%d has no readable source file for metadata repair.', $attachment_id ) );
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$metadata = dtb_schematic_generate_attachment_metadata_full_resolution( $attachment_id, $abs_path );
	if ( ! is_array( $metadata ) || empty( $metadata['width'] ) || empty( $metadata['height'] ) ) {
		return new WP_Error( 'dtb_schematic_attachment_metadata_failed', sprintf( 'Attachment #%d metadata could not be rebuilt.', $attachment_id ) );
	}

	wp_update_attachment_metadata( $attachment_id, $metadata );
	return true;
}

/**
 * Regenerate attachment metadata for a schematic page attachment that was
 * previously downscaled by WordPress's default big-image behavior (i.e. its
 * metadata still carries an `original_image` pointer — WordPress's marker
 * that the main file/dimensions are a scaled derivative, not the true
 * uploaded source). Re-derives metadata from the true original file on disk
 * (kept in the same directory by WordPress at scale time; never deleted)
 * with the threshold disabled, so already-uploaded schematics can be
 * repaired without a re-upload. No-op (returns false, no error) if the
 * attachment was never scaled or the true original file is missing.
 *
 * @return true|WP_Error|false True if metadata changed, false if nothing to do.
 */
function dtb_schematic_regenerate_oversized_attachment_metadata( int $attachment_id ) {
	$meta = wp_get_attachment_metadata( $attachment_id );
	if ( ! is_array( $meta ) || empty( $meta['original_image'] ) ) {
		return false; // Never scaled — nothing to regenerate.
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$original_path = wp_get_original_image_path( $attachment_id );
	if ( ! $original_path || ! is_file( $original_path ) ) {
		return new WP_Error( 'dtb_schematic_original_missing', sprintf( 'Attachment #%d has no recoverable original file on disk.', $attachment_id ) );
	}

	$regenerated = dtb_schematic_generate_attachment_metadata_full_resolution( $attachment_id, $original_path );
	if ( ! is_array( $regenerated ) ) {
		return new WP_Error( 'dtb_schematic_regenerate_failed', sprintf( 'Metadata regeneration failed for attachment #%d.', $attachment_id ) );
	}

	wp_update_attachment_metadata( $attachment_id, $regenerated );
	return true;
}

/**
 * Enumerate every schematic page attachment and repair the ones WordPress
 * previously downscaled via `big_image_size_threshold` before that behavior
 * was disabled for schematic uploads (see
 * dtb_schematic_generate_attachment_metadata_full_resolution() above). This
 * is the single shared implementation used by both
 * `wp dtb schematics regenerate-oversized` (Application/ReconcileSchematicSourceCli.php)
 * and the one-time wp-admin "Regenerate Oversized Schematic Images" action
 * (Admin/Workspace/Workspace.php) — neither caller re-walks records or
 * re-implements the repair itself.
 *
 * Idempotent: an attachment with no `original_image` metadata marker (never
 * scaled, or already repaired by a prior run) is left untouched and is not
 * counted as a candidate.
 *
 * @param bool          $dry_run   When true, only counts candidates; performs no writes.
 * @param callable|null $heartbeat Optional commit-lease renewal callback, called before each repair.
 * @return array{examined:int,candidates:int,regenerated:int,failed:int,errors:array<int,string>,fatal_error:?string}
 */
function dtb_schematic_regenerate_oversized_run( bool $dry_run = true, ?callable $heartbeat = null ): array {
	$report = [
		'examined'    => 0,
		'candidates'  => 0,
		'regenerated' => 0,
		'failed'      => 0,
		'errors'      => [],
		'fatal_error' => null,
	];

	$attachment_ids = [];
	$page = 1;
	do {
		$results = dtb_schematic_record_repo_query( [ 'page' => $page, 'per_page' => 100 ] );
		foreach ( $results['items'] as $record ) {
			foreach ( $record->pages as $record_page ) {
				$attachment_id = (int) ( $record_page['attachment_id'] ?? 0 );
				if ( $attachment_id > 0 ) {
					$attachment_ids[ $attachment_id ] = true;
				}
			}
		}
		$page++;
	} while ( $page <= $results['pages'] );

	$attachment_ids = array_keys( $attachment_ids );
	$report['examined'] = count( $attachment_ids );

	foreach ( $attachment_ids as $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['original_image'] ) ) {
			continue; // Never scaled, or already repaired — nothing to do.
		}
		$report['candidates']++;

		if ( $dry_run ) {
			continue;
		}

		if ( $heartbeat ) {
			$renewed = $heartbeat();
			if ( is_wp_error( $renewed ) ) {
				$report['fatal_error'] = $renewed->get_error_message();
				return $report;
			}
		}

		$result = dtb_schematic_regenerate_oversized_attachment_metadata( $attachment_id );
		if ( is_wp_error( $result ) ) {
			$report['failed']++;
			$report['errors'][] = sprintf( 'Attachment #%d: %s', $attachment_id, $result->get_error_message() );
			continue;
		}
		if ( true === $result ) {
			$report['regenerated']++;
		}
	}

	return $report;
}

/**
 * Explicitly retire domain records whose canonical ID was not observed as
 * "covered by an active source row" across a just-completed full pass.
 * Never deletes — only transitions lifecycle to retired (preserving the
 * administrative record per Application/ManageSchematicRecord.php).
 */
function dtb_schematic_reconcile_retire_uncovered( array $seen_canonical_ids ): int {
	$retired_count = 0;
	$page          = 1;

	do {
		$results = dtb_schematic_record_repo_query( [ 'page' => $page, 'per_page' => 100 ] );
		foreach ( $results['items'] as $record ) {
			if ( $record->lifecycle->is_retired() ) {
				continue;
			}
			if ( in_array( $record->canonical_id, $seen_canonical_ids, true ) ) {
				continue;
			}
			$result = dtb_schematic_retire( $record->id );
			if ( ! is_wp_error( $result ) ) {
				$retired_count++;
			}
		}
		$page++;
	} while ( $page <= $results['pages'] );

	return $retired_count;
}
