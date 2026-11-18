<?php
/**
 * Exact, bounded projector for reviewed canonical compatibility changes.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ProductRelationshipProjectionService {
	public const MAX_ARTIFACT_BYTES = 2097152;
	private const FIELDS = [
		'Meta: _dtb_compatible_tool_skus' => DTB_ProductMeta::COMPATIBLE_TOOL_SKUS,
		'Meta: _dtb_replacement_part_for' => DTB_ProductMeta::REPLACEMENT_PART_FOR,
	];
	private const WRITABLE_FIELDS = [
		'Meta: _dtb_compatible_tool_skus' => DTB_ProductMeta::COMPATIBLE_TOOL_SKUS,
	];
	private const MAX_PARTS = 25;
	private const MAX_TARGETS_PER_FIELD = 50;
	private const STAGED_ARTIFACT_TTL = 86400;

	/** Store exact artifact bytes outside the web root for bounded MCP operations. */
	public static function stage_artifact_payload( string $payload, string $expected_sha256 ): array {
		$actual_sha256 = hash( 'sha256', $payload );
		if ( strlen( $payload ) < 2 || strlen( $payload ) > self::MAX_ARTIFACT_BYTES || ! preg_match( '/^[a-f0-9]{64}$/D', $expected_sha256 ) || ! hash_equals( strtolower( $expected_sha256 ), $actual_sha256 ) ) {
			throw new RuntimeException( 'Artifact size or SHA-256 validation failed.' );
		}
		$artifact = json_decode( $payload, true );
		$release_id = is_array( $artifact ) ? strtolower( (string) ( $artifact['release_id'] ?? '' ) ) : '';
		$batch_index = is_array( $artifact ) ? absint( $artifact['batch_index'] ?? 0 ) : 0;
		$batch_count = is_array( $artifact ) ? absint( $artifact['batch_count'] ?? 0 ) : 0;
		if ( ! is_array( $artifact ) || 1 !== ( $artifact['schema_version'] ?? null ) || 'dtb_compatibility_projection' !== ( $artifact['artifact_type'] ?? '' ) || ! preg_match( '/^[a-f0-9]{24}$/D', $release_id ) || $batch_index < 1 || $batch_count < $batch_index ) {
			throw new RuntimeException( 'Projection artifact release identity is invalid.' );
		}

		$dir = self::private_report_dir();
		self::cleanup_expired_staged_artifacts( $dir );
		$path = $dir . '/staged-projection-' . $actual_sha256 . '.json';
		if ( is_file( $path ) ) {
			$existing = self::get_staged_artifact_path( $release_id, $batch_index, $actual_sha256 );
			return [ 'release_id' => $release_id, 'batch_index' => $batch_index, 'artifact_sha256' => $actual_sha256, 'staged' => true, 'already_staged' => true, 'expires_at' => gmdate( 'c', filemtime( $existing ) + self::STAGED_ARTIFACT_TTL ) ];
		}

		$temp_path = tempnam( $dir, '.staged-projection-' );
		if ( ! is_string( $temp_path ) || '' === $temp_path ) {
			throw new RuntimeException( 'Unable to create private compatibility artifact staging storage.' );
		}
		try {
			if ( ! @chmod( $temp_path, 0600 ) ) {
				throw new RuntimeException( 'Unable to restrict staged compatibility artifact permissions.' );
			}
			$written = file_put_contents( $temp_path, $payload, LOCK_EX );
			if ( false === $written || $written !== strlen( $payload ) || ! hash_equals( $actual_sha256, (string) hash_file( 'sha256', $temp_path ) ) ) {
				throw new RuntimeException( 'Staged compatibility artifact failed its exact-byte read-back check.' );
			}
			if ( ! rename( $temp_path, $path ) ) {
				if ( is_file( $path ) && hash_equals( $actual_sha256, (string) hash_file( 'sha256', $path ) ) ) {
					return [ 'release_id' => $release_id, 'batch_index' => $batch_index, 'artifact_sha256' => $actual_sha256, 'staged' => true, 'already_staged' => true, 'expires_at' => gmdate( 'c', filemtime( $path ) + self::STAGED_ARTIFACT_TTL ) ];
				}
				throw new RuntimeException( 'Unable to finalize the staged compatibility artifact.' );
			}
			if ( ! hash_equals( $actual_sha256, (string) hash_file( 'sha256', $path ) ) ) {
				@unlink( $path );
				throw new RuntimeException( 'Staged compatibility artifact final read-back failed.' );
			}
			return [ 'release_id' => $release_id, 'batch_index' => $batch_index, 'artifact_sha256' => $actual_sha256, 'staged' => true, 'already_staged' => false, 'expires_at' => gmdate( 'c', filemtime( $path ) + self::STAGED_ARTIFACT_TTL ) ];
		} finally {
			if ( is_file( $temp_path ) ) {
				@unlink( $temp_path );
			}
		}
	}

	/** Resolve a digest-addressed staged artifact without exposing its path to the caller. */
	public static function get_staged_artifact_path( string $release_id, int $batch_index, string $artifact_sha256 ): string {
		$release_id = strtolower( $release_id );
		$artifact_sha256 = strtolower( $artifact_sha256 );
		if ( ! preg_match( '/^[a-f0-9]{24}$/D', $release_id ) || $batch_index < 1 || ! preg_match( '/^[a-f0-9]{64}$/D', $artifact_sha256 ) ) {
			throw new RuntimeException( 'Staged artifact identity is invalid.' );
		}
		$dir = realpath( self::private_report_dir() );
		$candidate = $dir ? $dir . '/staged-projection-' . $artifact_sha256 . '.json' : '';
		$path = '' !== $candidate ? realpath( $candidate ) : false;
		if ( ! $dir || ! $path || dirname( $path ) !== $dir || ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'Staged artifact is missing from private compatibility storage.' );
		}
		if ( filemtime( $path ) < time() - self::STAGED_ARTIFACT_TTL ) {
			@unlink( $path );
			throw new RuntimeException( 'Staged artifact expired; stage the exact release batch again.' );
		}
		if ( filesize( $path ) > self::MAX_ARTIFACT_BYTES || ! hash_equals( $artifact_sha256, (string) hash_file( 'sha256', $path ) ) ) {
			throw new RuntimeException( 'Staged artifact is oversized or its SHA-256 no longer matches.' );
		}
		$artifact = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $artifact ) || strtolower( (string) ( $artifact['release_id'] ?? '' ) ) !== $release_id || absint( $artifact['batch_index'] ?? 0 ) !== $batch_index ) {
			throw new RuntimeException( 'Staged artifact release or batch identity does not match.' );
		}
		return $path;
	}

	private static function cleanup_expired_staged_artifacts( string $dir ): void {
		$cutoff = time() - self::STAGED_ARTIFACT_TTL;
		$candidates = array_merge( glob( $dir . '/staged-projection-*.json' ) ?: [], glob( $dir . '/.staged-projection-*' ) ?: [] );
		foreach ( $candidates as $candidate ) {
			$real_path = realpath( $candidate );
			if ( $real_path && dirname( $real_path ) === $dir && is_file( $real_path ) && filemtime( $real_path ) < $cutoff ) {
				@unlink( $real_path );
			}
		}
	}

	public static function run_artifact( string $path, string $expected_sha256, bool $apply ): array {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'Projection artifact is not a readable file.' );
		}
		$actual_sha256 = hash_file( 'sha256', $path );
		if ( ! is_string( $actual_sha256 ) || ! preg_match( '/^[a-f0-9]{64}$/i', $expected_sha256 ) || ! hash_equals( strtolower( $expected_sha256 ), strtolower( $actual_sha256 ) ) ) {
			throw new RuntimeException( 'Projection artifact SHA-256 does not match the explicitly supplied digest.' );
		}
		$artifact = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $artifact ) || 1 !== ( $artifact['schema_version'] ?? null ) || 'dtb_compatibility_projection' !== ( $artifact['artifact_type'] ?? '' ) || ! is_array( $artifact['rows'] ?? null ) || ! preg_match( '/^[a-f0-9]{24}$/i', (string) ( $artifact['release_id'] ?? '' ) ) || absint( $artifact['batch_index'] ?? 0 ) < 1 || absint( $artifact['batch_count'] ?? 0 ) < 1 || absint( $artifact['batch_index'] ?? 0 ) > absint( $artifact['batch_count'] ?? 0 ) ) {
			throw new RuntimeException( 'Projection artifact schema is invalid.' );
		}
		$inventory = $artifact['batch_inventory'] ?? null;
		$batch_index = absint( $artifact['batch_index'] );
		$inventory_digest = (string) ( $artifact['batch_inventory_sha256'] ?? '' );
		foreach ( [ 'catalog_sha256', 'manifest_sha256', 'catalog_apply_report_sha256' ] as $digest_field ) {
			if ( ! preg_match( '/^[a-f0-9]{64}$/i', (string) ( $artifact[ $digest_field ] ?? '' ) ) ) {
				throw new RuntimeException( 'Projection artifact source digest is missing or invalid.' );
			}
		}
		$batch_entry = is_array( $inventory ) ? ( $inventory[ $batch_index - 1 ] ?? null ) : null;
		$batch_skus = array_map( static fn( array $row ): string => (string) ( $row['sku'] ?? '' ), $artifact['rows'] );
		$expected_release_id = self::expected_release_id( $artifact, $inventory_digest );
		if ( ! is_array( $inventory ) || count( $inventory ) !== absint( $artifact['batch_count'] ) || ! hash_equals( $inventory_digest, self::canonical_sha256( $inventory ) ) || ! is_array( $batch_entry ) || (int) ( $batch_entry['batch_index'] ?? 0 ) !== $batch_index || ( $batch_entry['part_skus'] ?? null ) !== $batch_skus || ! hash_equals( (string) ( $batch_entry['rows_sha256'] ?? '' ), self::canonical_sha256( $artifact['rows'] ) ) || ! hash_equals( strtolower( (string) $artifact['release_id'] ), $expected_release_id ) ) {
			throw new RuntimeException( 'Projection batch does not match its immutable release inventory.' );
		}
		$excluded = $artifact['excluded_fields'] ?? [];
		if ( ! is_array( $excluded ) || ! in_array( 'Upsells', $excluded, true ) || ! in_array( 'Cross-sells', $excluded, true ) ) {
			throw new RuntimeException( 'Artifact does not explicitly exclude WooCommerce merchandising fields.' );
		}
		$rows = $artifact['rows'];
		if ( count( $rows ) > self::MAX_PARTS ) {
			throw new RuntimeException( 'Projection batch exceeds the 25-part bound.' );
		}
		if ( empty( $rows ) ) {
			return [ 'mode' => $apply ? 'apply' : 'dry-run', 'artifact_sha256' => $actual_sha256, 'rows' => [], 'applied' => false ];
		}

		$validated = self::validate_artifact_rows( $rows );
		$identity_map = self::resolve_skus( $validated['all_skus'] );
		$runtime_records = self::validate_runtime_records( $validated['rows'], $identity_map );
		$prior_batch = [];
		$prior_batch_status = '';
		if ( $apply ) {
			try {
				$existing_release = self::read_release_status( strtolower( (string) $artifact['release_id'] ) );
				$prior_batch = $existing_release['batches'][ (string) absint( $artifact['batch_index'] ) ] ?? [];
				if ( in_array( $prior_batch['status'] ?? '', [ 'applying', 'failed', 'rollback_failed' ], true ) ) {
					if ( 'rollback_failed' === $prior_batch['status'] ) {
						throw new RuntimeException( 'This release batch has an incomplete rollback. Reconcile its private rollback report before retrying.' );
					}
					if ( ! hash_equals( $actual_sha256, (string) ( $prior_batch['artifact_sha256'] ?? '' ) ) || ( $prior_batch['part_skus'] ?? null ) !== array_column( $rows, 'sku' ) ) {
						throw new RuntimeException( 'The prior attempt does not match this exact release batch.' );
					}
					$prior_batch_status = (string) $prior_batch['status'];
				}
			} catch ( RuntimeException $error ) {
				if ( 'No private reconciliation report exists for that release ID.' !== $error->getMessage() ) {
					throw $error;
				}
			}
		}
		$products = $runtime_records['parts'];
		$plan = self::build_plan( $validated['rows'], $products );
		$conflicts = array_values( array_filter( $plan, static fn( array $row ): bool => 'conflict' === $row['status'] ) );
		if ( '' !== $prior_batch_status ) {
			return self::reconcile_applied_attempt( $artifact, $actual_sha256, $validated, $runtime_records, $products, $prior_batch, $plan, $conflicts );
		}
		if ( $conflicts ) {
			return [ 'mode' => $apply ? 'apply' : 'dry-run', 'artifact_sha256' => $actual_sha256, 'applied' => false, 'rows' => $plan, 'error' => 'Runtime state conflicts with the artifact; no writes were made.' ];
		}
		if ( ! $apply ) {
			return [ 'mode' => 'dry-run', 'artifact_sha256' => $actual_sha256, 'applied' => false, 'rows' => $plan ];
		}
		$apply_rows = array_values( array_filter( $plan, static fn( array $row ): bool => 'ready' === $row['status'] ) );
		if ( empty( $apply_rows ) ) {
			$readback = self::verify_public_routes( $validated['rows'], $products );
			$release = self::record_release_batch( $artifact, $actual_sha256, 'already_applied', [], null, null, $readback );
			return [ 'mode' => 'apply', 'artifact_sha256' => $actual_sha256, 'applied' => false, 'already_applied' => true, 'rows' => $plan, 'public_api_readback' => $readback, 'release' => $release ];
		}

		$report = [
			'schema_version' => 1,
			'report_type' => 'dtb_compatibility_projection_rollback',
			'artifact_sha256' => $actual_sha256,
			'release_id' => (string) $artifact['release_id'],
			'batch_index' => absint( $artifact['batch_index'] ),
			'batch_count' => absint( $artifact['batch_count'] ),
			'batch_inventory' => $artifact['batch_inventory'],
			'batch_inventory_sha256' => $artifact['batch_inventory_sha256'],
			'catalog_sha256' => (string) ( $artifact['catalog_sha256'] ?? '' ),
			'manifest_sha256' => (string) ( $artifact['manifest_sha256'] ?? '' ),
			'catalog_apply_report_sha256' => (string) ( $artifact['catalog_apply_report_sha256'] ?? '' ),
			'created_at' => gmdate( 'c' ),
			'rows' => $apply_rows,
		];
		$report_path = self::write_private_report( $report );
		$locks = [];
		try {
			$lock_ids = array_values( array_unique( array_merge( array_column( $runtime_records['parts'], 'id' ), array_values( $runtime_records['targets'] ) ) ) );
			sort( $lock_ids, SORT_NUMERIC );
			foreach ( $lock_ids as $lock_id ) {
				$lock = dtb_product_mapping_acquire_lock( (int) $lock_id );
				if ( is_wp_error( $lock ) ) {
					throw new RuntimeException( $lock->get_error_message() );
				}
				$locks[] = $lock;
			}
			// Re-resolve every identity and re-check every source after all locks are held.
			$fresh_map = self::resolve_skus( $validated['all_skus'] );
			$fresh_records = self::validate_runtime_records( $validated['rows'], $fresh_map );
			if ( $fresh_records !== $runtime_records ) {
				throw new RuntimeException( 'Runtime SKU identity changed during projection preparation; no writes were made.' );
			}
			foreach ( $validated['rows'] as $index => $row ) {
				$product = wc_get_product( (int) $products[ $row['sku'] ]['id'] );
				if ( ! $product || ! self::row_state_is_expected( $product, $row ) ) {
					throw new RuntimeException( 'Runtime fitment changed during projection preparation; no writes were made.' );
				}
			}
			$prepared_report_sha256 = hash_file( 'sha256', $report_path );
			self::record_release_batch( $artifact, $actual_sha256, 'applying', array_column( $report['rows'], 'sku' ), $report_path, $prepared_report_sha256 );
			foreach ( $apply_rows as $index => $row ) {
				$report['rows'][ $index ]['status'] = 'applying';
				self::write_private_report( $report, $report_path );
				$product = wc_get_product( (int) $products[ $row['sku'] ]['id'] );
				self::write_relationships( $product, $row['after'] );
				$report['rows'][ $index ]['status'] = 'applied';
				$report['rows'][ $index ]['product_id'] = $product->get_id();
			$report['rows'][ $index ]['result'] = self::read_product_relationships( $product );
				self::write_private_report( $report, $report_path );
			}
			$report['public_api_readback'] = self::verify_public_routes( $validated['rows'], $products );
			self::write_private_report( $report, $report_path );
		} catch ( Throwable $error ) {
			$report['failure'] = sanitize_text_field( $error->getMessage() );
			$report['failure_at'] = gmdate( 'c' );
			$report['partial_apply'] = (bool) array_filter( $report['rows'], static fn( array $row ): bool => in_array( $row['status'] ?? '', [ 'applying', 'applied' ], true ) );
			self::write_private_report( $report, $report_path );
			$report_sha256 = hash_file( 'sha256', $report_path );
			try {
				self::record_release_batch( $artifact, $actual_sha256, 'failed', array_column( $report['rows'], 'sku' ), $report_path, $report_sha256, $report['public_api_readback'] ?? [] );
			} catch ( Throwable $release_error ) {
				$report['release_recording_error'] = sanitize_text_field( $release_error->getMessage() );
				self::write_private_report( $report, $report_path );
				$report_sha256 = hash_file( 'sha256', $report_path );
			}
			throw new RuntimeException( $error->getMessage() . ' Rollback report: ' . $report_path . ' SHA-256: ' . $report_sha256, 0, $error );
		} finally {
			foreach ( array_reverse( $locks ) as $lock ) {
				dtb_product_mapping_release_lock( $lock );
			}
		}
		try {
			$report['partial_apply'] = false;
			self::write_private_report( $report, $report_path );
			$report_sha256 = hash_file( 'sha256', $report_path );
			$release = self::record_release_batch( $artifact, $actual_sha256, 'applied', array_column( $report['rows'], 'sku' ), $report_path, $report_sha256, $report['public_api_readback'] ?? [] );
		} catch ( Throwable $error ) {
			$report['finalization_failure'] = sanitize_text_field( $error->getMessage() );
			$report['finalization_failure_at'] = gmdate( 'c' );
			try {
				self::write_private_report( $report, $report_path );
			} catch ( Throwable $report_error ) {
				// The pre-registered applying report remains the recovery record.
			}
			$report_sha256 = hash_file( 'sha256', $report_path );
			throw new RuntimeException( 'Projection writes may have completed; inspect release-status and use this rollback report if needed: ' . $report_path . ' SHA-256: ' . $report_sha256, 0, $error );
		}
		return [ 'mode' => 'apply', 'artifact_sha256' => $actual_sha256, 'applied' => true, 'report_path' => $report_path, 'report_sha256' => $report_sha256, 'release' => $release, 'rows' => $report['rows'] ];
	}

	/**
	 * Reconcile an interrupted apply only when the exact approved after-state is
	 * already present for every owner and the private rollback report is intact.
	 * This path verifies state and records recovery; it never writes product data.
	 *
	 * @return array
	 */
	private static function reconcile_applied_attempt( array $artifact, string $artifact_sha256, array $validated, array $runtime_records, array $products, array $prior_batch, array $plan, array $conflicts ): array {
		if ( $conflicts || count( $plan ) !== count( $validated['rows'] ) || array_filter( $plan, static fn( array $row ): bool => 'already_applied' !== $row['status'] ) ) {
			throw new RuntimeException( 'The interrupted batch is not wholly at its approved after-state; no reconciliation or product writes were made.' );
		}

		$report_path = (string) ( $prior_batch['report_path'] ?? '' );
		$report_sha256 = strtolower( (string) ( $prior_batch['current_report_sha256'] ?? '' ) );
		$report = self::read_private_report( $report_path, $report_sha256, 'dtb_compatibility_projection_rollback' );
		$release_id = strtolower( (string) ( $artifact['release_id'] ?? '' ) );
		$batch_index = absint( $artifact['batch_index'] ?? 0 );
		$report_rows = $report['rows'] ?? null;
		$expected_skus = array_column( $validated['rows'], 'sku' );
		$report_skus = is_array( $report_rows ) ? array_map( static fn( $row ): string => is_array( $row ) ? (string) ( $row['sku'] ?? '' ) : '', $report_rows ) : [];
		if ( ! is_array( $report_rows ) || ( $report['release_id'] ?? '' ) !== $release_id || (int) ( $report['batch_index'] ?? 0 ) !== $batch_index || (int) ( $report['batch_count'] ?? 0 ) !== (int) $artifact['batch_count'] || ! hash_equals( $artifact_sha256, (string) ( $report['artifact_sha256'] ?? '' ) ) || ! hash_equals( (string) $artifact['batch_inventory_sha256'], (string) ( $report['batch_inventory_sha256'] ?? '' ) ) || ! hash_equals( self::canonical_sha256( $artifact['batch_inventory'] ), self::canonical_sha256( $report['batch_inventory'] ?? null ) ) || $expected_skus !== $report_skus || count( $report_rows ) !== count( $validated['rows'] ) ) {
			throw new RuntimeException( 'The private rollback report does not match the exact interrupted release batch.' );
		}
		foreach ( $validated['rows'] as $index => $row ) {
			$report_row = $report_rows[ $index ];
			if ( ! is_array( $report_row ) || ! in_array( $report_row['status'] ?? '', [ 'applying', 'applied' ], true ) || (int) ( $report_row['product_id'] ?? 0 ) !== (int) $products[ $row['sku'] ]['id'] || ! self::same_relationship_map( (array) ( $report_row['before'] ?? [] ), $row['before'] ) || ! self::same_relationship_map( (array) ( $report_row['after'] ?? [] ), $row['after'] ) ) {
				throw new RuntimeException( "The private rollback report does not match approved owner {$row['sku']}." );
			}
		}

		$lock_ids = array_values( array_unique( array_merge( array_column( $runtime_records['parts'], 'id' ), array_values( $runtime_records['targets'] ) ) ) );
		sort( $lock_ids, SORT_NUMERIC );
		$locks = [];
		try {
			foreach ( $lock_ids as $lock_id ) {
				$lock = dtb_product_mapping_acquire_lock( (int) $lock_id );
				if ( is_wp_error( $lock ) ) {
					throw new RuntimeException( $lock->get_error_message() );
				}
				$locks[] = $lock;
			}

			$release = self::read_release_status( $release_id );
			$current_batch = $release['batches'][ (string) $batch_index ] ?? [];
			if ( ! in_array( $current_batch['status'] ?? '', [ 'applying', 'failed' ], true ) || ! hash_equals( $artifact_sha256, (string) ( $current_batch['artifact_sha256'] ?? '' ) ) || ! hash_equals( $report_sha256, (string) ( $current_batch['current_report_sha256'] ?? '' ) ) ) {
				throw new RuntimeException( 'The interrupted batch changed during reconciliation; no product data was written.' );
			}

			$fresh_map = self::resolve_skus( $validated['all_skus'] );
			$fresh_records = self::validate_runtime_records( $validated['rows'], $fresh_map );
			if ( $fresh_records !== $runtime_records ) {
				throw new RuntimeException( 'Runtime SKU identity changed during reconciliation; no product data was written.' );
			}
			foreach ( $validated['rows'] as $row ) {
				$product = wc_get_product( (int) $products[ $row['sku'] ]['id'] );
				if ( ! $product || ! self::same_relationship_subset( self::read_product_relationships( $product ), $row['after'] ) ) {
					throw new RuntimeException( "Approved after-state changed during reconciliation for {$row['sku']}; no product data was written." );
				}
			}

			$readback = self::verify_public_routes( $validated['rows'], $products );
			$report['partial_apply'] = false;
			$report['public_api_readback'] = $readback;
			$report['reconciliation'] = [ 'status' => 'applied_without_product_writes', 'at' => gmdate( 'c' ) ];
			$report_path = self::write_private_report( $report, $report_path );
			$report_sha256 = hash_file( 'sha256', $report_path );
			$release_status = self::record_release_batch( $artifact, $artifact_sha256, 'applied', $expected_skus, $report_path, $report_sha256, $readback );
			return [ 'mode' => 'apply', 'artifact_sha256' => $artifact_sha256, 'applied' => false, 'already_applied' => true, 'reconciled' => true, 'rows' => $plan, 'public_api_readback' => $readback, 'release' => $release_status ];
		} finally {
			foreach ( array_reverse( $locks ) as $lock ) {
				dtb_product_mapping_release_lock( $lock );
			}
		}
	}

	public static function rollback_report( string $path, string $expected_sha256 ): array {
		$report = self::read_private_report( $path, $expected_sha256, 'dtb_compatibility_projection_rollback' );
		$path = realpath( $path );
		$sku_list = array_map( static fn( array $row ): string => (string) ( $row['sku'] ?? '' ), $report['rows'] );
		$identity_map = self::resolve_skus( $sku_list );
		$plan = [];
		foreach ( $report['rows'] as $row ) {
			$sku = strtoupper( trim( (string) ( $row['sku'] ?? '' ) ) );
			$product_row = $identity_map[ $sku ] ?? null;
			if ( ! is_array( $product_row ) || 1 !== count( $product_row ) || 'product' !== $product_row[0]['post_type'] || $product_row[0]['sku'] !== $sku ) {
				throw new RuntimeException( "Rollback source SKU {$sku} no longer resolves uniquely to a parent product." );
			}
			if ( empty( $product_row[0]['is_part'] ) ) {
				throw new RuntimeException( "Rollback source SKU {$sku} is no longer classified as a part." );
			}
			$product = wc_get_product( (int) $product_row[0]['id'] );
			if ( ! $product ) {
				throw new RuntimeException( "Rollback source SKU {$sku} is unavailable." );
			}
			$current = self::read_product_relationships( $product );
			$before = $row['before'] ?? [];
			$after = $row['after'] ?? [];
			$already_rolled_back = self::same_relationship_subset( $current, $before );
			if ( ! $already_rolled_back && ! self::same_relationship_subset( $current, $after ) ) {
				throw new RuntimeException( "Rollback conflict on {$sku}; current values do not match the recorded after-state." );
			}
			$plan[] = [ 'sku' => $sku, 'product_id' => $product->get_id(), 'before' => $before, 'before_raw' => $row['before_raw'] ?? [], 'after' => $after, 'status' => $already_rolled_back ? 'already_rolled_back' : 'ready' ];
		}
		$locks = [];
		try {
			$lock_ids = array_values( array_unique( array_column( $plan, 'product_id' ) ) );
			sort( $lock_ids, SORT_NUMERIC );
			foreach ( $lock_ids as $lock_id ) {
				$lock = dtb_product_mapping_acquire_lock( (int) $lock_id );
				if ( is_wp_error( $lock ) ) {
					throw new RuntimeException( $lock->get_error_message() );
				}
				$locks[] = $lock;
			}
			foreach ( $plan as $row ) {
				$product = wc_get_product( (int) $row['product_id'] );
				if ( ! $product || ( 'ready' === $row['status'] && ! self::same_relationship_subset( self::read_product_relationships( $product ), $row['after'] ) ) ) {
					throw new RuntimeException( 'Rollback state changed during preparation; no rollback writes were made.' );
				}
			}
			foreach ( $plan as $index => $row ) {
				if ( 'already_rolled_back' === $row['status'] ) {
					continue;
				}
				$product = wc_get_product( (int) $row['product_id'] );
				$plan[ $index ]['status'] = 'rolling_back';
				$report['rollback'] = [ 'at' => gmdate( 'c' ), 'rows' => $plan ];
				self::write_private_report( $report, $path );
				self::write_relationships( $product, $row['before'], $row['before_raw'] ?? [] );
				$plan[ $index ]['status'] = 'rolled_back';
				$plan[ $index ]['result'] = self::read_product_relationships( $product );
				if ( ! self::same_relationship_subset( $plan[ $index ]['result'], $row['before'] ) ) {
					throw new RuntimeException( "Rollback read-back failed on {$row['sku']}." );
				}
			}
		} catch ( Throwable $error ) {
			$report['rollback_failure'] = sanitize_text_field( $error->getMessage() );
			$report['rollback_failure_at'] = gmdate( 'c' );
			$report['rollback'] = [ 'at' => gmdate( 'c' ), 'rows' => $plan, 'status' => 'partial_or_failed' ];
			self::write_private_report( $report, $path );
			$report_sha256 = hash_file( 'sha256', $path );
			try {
				self::record_release_batch( $report, (string) $report['artifact_sha256'], 'rollback_failed', array_column( $plan, 'sku' ), $path, $report_sha256, $report['rollback']['public_api_readback'] ?? [] );
			} catch ( Throwable $release_error ) {
				$report['rollback_release_recording_error'] = sanitize_text_field( $release_error->getMessage() );
				self::write_private_report( $report, $path );
				$report_sha256 = hash_file( 'sha256', $path );
			}
			throw new RuntimeException( $error->getMessage() . ' Updated rollback report: ' . $path . ' SHA-256: ' . $report_sha256, 0, $error );
		} finally {
			foreach ( array_reverse( $locks ) as $lock ) {
				dtb_product_mapping_release_lock( $lock );
			}
		}
		$rollback_rows = [];
		$rollback_products = [];
		foreach ( $plan as $row ) {
			$rollback_rows[] = [ 'sku' => $row['sku'], 'before' => $row['after'], 'after' => $row['before'] ];
			$rollback_products[ $row['sku'] ] = [ 'id' => $row['product_id'] ];
		}
		try {
			$report['rollback'] = [ 'at' => gmdate( 'c' ), 'rows' => $plan, 'public_api_readback' => self::verify_public_routes( $rollback_rows, $rollback_products ) ];
			$written = self::write_private_report( $report, $path );
			$report_sha256 = hash_file( 'sha256', $written );
			$release = self::record_release_batch( $report, (string) $report['artifact_sha256'], 'rolled_back', array_column( $plan, 'sku' ), $written, $report_sha256, $report['rollback']['public_api_readback'] ?? [] );
		} catch ( Throwable $error ) {
			$report['rollback_verification_failure'] = sanitize_text_field( $error->getMessage() );
			$report['rollback_verification_failure_at'] = gmdate( 'c' );
			self::write_private_report( $report, $path );
			$report_sha256 = hash_file( 'sha256', $path );
			try {
				self::record_release_batch( $report, (string) $report['artifact_sha256'], 'rollback_failed', array_column( $plan, 'sku' ), $path, $report_sha256, $report['rollback']['public_api_readback'] ?? [] );
			} catch ( Throwable $release_error ) {
				$report['rollback_release_recording_error'] = sanitize_text_field( $release_error->getMessage() );
				self::write_private_report( $report, $path );
				$report_sha256 = hash_file( 'sha256', $path );
			}
			throw new RuntimeException( $error->getMessage() . ' Updated rollback report: ' . $path . ' SHA-256: ' . $report_sha256, 0, $error );
		}
		return [ 'rolled_back' => true, 'report_path' => $written, 'report_sha256' => $report_sha256, 'release' => $release, 'rows' => $plan ];
	}

	public static function read_release_status( string $release_id ): array {
		if ( ! preg_match( '/^[a-f0-9]{24}$/i', $release_id ) ) {
			throw new RuntimeException( 'Release ID must be 24 hexadecimal characters.' );
		}
		$path = self::private_report_dir() . '/release-' . strtolower( $release_id ) . '.json';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'No private reconciliation report exists for that release ID.' );
		}
		$report = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $report ) || ( $report['release_id'] ?? '' ) !== strtolower( $release_id ) ) {
			throw new RuntimeException( 'Release reconciliation report is invalid.' );
		}
		$report['report_sha256'] = hash_file( 'sha256', $path );
		$private_dir = realpath( self::private_report_dir() );
		if ( isset( $report['batches'] ) && is_array( $report['batches'] ) ) {
			foreach ( $report['batches'] as &$batch ) {
				$batch_report_path = (string) ( $batch['report_path'] ?? '' );
				if ( '' === $batch_report_path ) {
					continue;
				}
				$real_batch_report = realpath( $batch_report_path );
				if ( ! $private_dir || ! $real_batch_report || dirname( $real_batch_report ) !== $private_dir || ! is_readable( $real_batch_report ) ) {
					throw new RuntimeException( 'A batch rollback report path is missing, unreadable, or outside the private report directory.' );
				}
				$batch['current_report_sha256'] = hash_file( 'sha256', $real_batch_report );
			}
			unset( $batch );
		}
		return $report;
	}

	public static function verify_private_storage(): array {
		$path = self::private_report_dir();
		if ( ! is_writable( $path ) ) {
			throw new RuntimeException( 'Private compatibility projection directory is not writable by PHP.' );
		}
		return [ 'path' => $path, 'writable' => true, 'web_accessible' => false ];
	}

	private static function validate_artifact_rows( array $rows ): array {
		$seen = [];
		$validated = [];
		$all_skus = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'Projection artifact contains an invalid row.' );
			}
			$sku = strtoupper( trim( (string) ( $row['sku'] ?? '' ) ) );
			if ( ! preg_match( '/^[A-Z0-9._-]+$/', $sku ) || isset( $seen[ $sku ] ) ) {
				throw new RuntimeException( 'Projection artifact contains a blank or duplicate part SKU.' );
			}
			$seen[ $sku ] = true;
			$before = self::validate_relationship_map( $row['before'] ?? null, 'before' );
			$after = self::validate_relationship_map( $row['after'] ?? null, 'after' );
			if ( empty( $after ) || array_diff( array_keys( $after ), array_keys( $before ) ) ) {
				throw new RuntimeException( "{$sku}: before/after compatibility fields do not match or after-state is empty." );
			}
			$approved_fields = [];
			if ( ! is_array( $row['approvals'] ?? null ) ) {
				throw new RuntimeException( "{$sku}: approval bindings must be an array." );
			}
			foreach ( $row['approvals'] as $approval ) {
				if ( ! is_array( $approval ) || ! isset( self::WRITABLE_FIELDS[ $approval['field'] ?? '' ] ) || ! preg_match( '/^[a-f0-9]{64}$/i', (string) ( $approval['approval_sha256'] ?? '' ) ) ) {
					throw new RuntimeException( "{$sku}: approval binding is invalid." );
				}
				if ( isset( $approved_fields[ $approval['field'] ] ) ) {
					throw new RuntimeException( "{$sku}: approval binding contains a duplicate field." );
				}
				$workflow = (string) ( $approval['workflow'] ?? '' );
				if ( ! in_array( $workflow, [ 'compatibility', 'compatibility_remove' ], true ) ) {
					throw new RuntimeException( "{$sku}: unsupported compatibility approval workflow." );
				}
				$approved_fields[ $approval['field'] ] = $workflow;
			}
			$approved_field_names = array_keys( $approved_fields );
			$after_field_names = array_keys( $after );
			sort( $approved_field_names );
			sort( $after_field_names );
			if ( $approved_field_names !== $after_field_names ) {
				throw new RuntimeException( "{$sku}: each projected field must be tied to exactly one approval." );
			}
			foreach ( $after as $field => $targets ) {
				$workflow = $approved_fields[ $field ] ?? '';
				$before_set = array_map( 'strtoupper', $before[ $field ] ?? [] );
				$after_set = array_map( 'strtoupper', $targets );
				if ( 'compatibility' === $workflow && ( empty( $targets ) || array_diff( $before_set, $after_set ) ) ) {
					throw new RuntimeException( "{$sku}: additive fitment projection cannot clear or remove existing targets." );
				}
				if ( 'compatibility_remove' === $workflow && ( ! array_diff( $before_set, $after_set ) || array_diff( $after_set, $before_set ) ) ) {
					throw new RuntimeException( "{$sku}: removal projection must remove existing targets without adding any." );
				}
				if ( count( $targets ) > self::MAX_TARGETS_PER_FIELD ) {
					throw new RuntimeException( "{$sku}: target list exceeds the 50-SKU bound." );
				}
				foreach ( $targets as $target ) {
					$all_skus[ strtoupper( $target ) ] = $target;
				}
			}
			$all_skus[ $sku ] = $sku;
			$validated[] = [ 'sku' => $sku, 'before' => $before, 'after' => $after ];
		}
		$all_targets = [];
		foreach ( $validated as $row ) {
			foreach ( $row['after'] as $targets ) {
				foreach ( $targets as $target ) {
					$all_targets[ strtoupper( $target ) ] = $target;
				}
			}
		}
		if ( count( $all_targets ) > 50 ) {
			throw new RuntimeException( 'Projection batch exceeds the 50-unique-tool public-route verification bound.' );
		}
		return [ 'rows' => $validated, 'all_skus' => array_values( $all_skus ) ];
	}

	private static function validate_relationship_map( $map, string $label ): array {
		if ( ! is_array( $map ) ) {
			throw new RuntimeException( "Projection {$label} state must be an object." );
		}
		$out = [];
		foreach ( $map as $field => $skus ) {
			if ( ! isset( self::WRITABLE_FIELDS[ $field ] ) || ! is_array( $skus ) || count( $skus ) !== count( array_unique( array_map( 'strval', $skus ) ) ) ) {
				throw new RuntimeException( "Projection {$label} contains an unknown field or invalid SKU list." );
			}
			$seen = [];
			foreach ( $skus as $sku ) {
				$normalized_sku = strtoupper( trim( (string) $sku ) );
				if ( ! is_string( $sku ) || ! preg_match( '/^[A-Z0-9._-]+$/', $normalized_sku ) || isset( $seen[ $normalized_sku ] ) ) {
					throw new RuntimeException( "Projection {$label} contains a blank or duplicate SKU." );
				}
				$seen[ $normalized_sku ] = true;
			}
			$out[ $field ] = array_values( $skus );
		}
		return $out;
	}

	private static function resolve_skus( array $skus ): array {
		global $wpdb;
		$skus = array_values( array_unique( array_filter( array_map( 'strval', $skus ) ) ) );
		if ( ! $skus ) {
			return [];
		}
		$placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
		$sql = "SELECT pm.meta_value AS sku,p.ID AS id,p.post_type,p.post_status,p.post_parent FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='_sku' WHERE p.post_type IN ('product','product_variation') AND p.post_status NOT IN ('auto-draft','inherit') AND pm.meta_value IN ({$placeholders})";
		$prepared = $wpdb->prepare( $sql, $skus );
		$rows = $wpdb->get_results( $prepared, ARRAY_A );
		$map = [];
		$ids = [];
		foreach ( (array) $rows as $row ) {
			$key = strtoupper( (string) $row['sku'] );
			$map[ $key ][] = [ 'id' => (int) $row['id'], 'sku' => (string) $row['sku'], 'post_type' => (string) $row['post_type'], 'post_status' => (string) $row['post_status'], 'post_parent' => (int) $row['post_parent'] ];
			$ids[ (int) $row['id'] ] = true;
			if ( 'product_variation' === $row['post_type'] && (int) $row['post_parent'] > 0 ) {
				$ids[ (int) $row['post_parent'] ] = true;
			}
		}
		if ( $ids ) {
			$id_placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$post_rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID,post_type,post_status FROM {$wpdb->posts} WHERE ID IN ({$id_placeholders})", array_keys( $ids ) ), ARRAY_A );
			$post_map = [];
			foreach ( (array) $post_rows as $post_row ) {
				$post_map[ (int) $post_row['ID'] ] = $post_row;
			}
			$meta_sql = "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN ({$id_placeholders}) AND meta_key IN (%s,%s)";
			$meta_args = array_merge( array_keys( $ids ), [ DTB_ProductMeta::PRODUCT_KIND, DTB_ProductMeta::IS_PARTS ] );
			$meta_rows = $wpdb->get_results( $wpdb->prepare( $meta_sql, $meta_args ), ARRAY_A );
			$meta_map = [];
			foreach ( (array) $meta_rows as $meta_row ) {
				$meta_map[ (int) $meta_row['post_id'] ][ (string) $meta_row['meta_key'] ][] = (string) $meta_row['meta_value'];
			}
			foreach ( $map as &$matches ) {
				foreach ( $matches as &$match ) {
					$record_meta = $meta_map[ $match['id'] ] ?? [];
					$kinds = $record_meta[ DTB_ProductMeta::PRODUCT_KIND ] ?? [];
					$parts = $record_meta[ DTB_ProductMeta::IS_PARTS ] ?? [];
					$match['is_part'] = in_array( 'part', $kinds, true ) || in_array( '1', $parts, true );
					$match['parent_id'] = (int) ( $match['post_parent'] ?? 0 );
					$match['parent_is_tool'] = false;
					$match['parent_is_part'] = false;
					if ( 'product_variation' === $match['post_type'] && $match['parent_id'] > 0 ) {
						$parent_kinds = $meta_map[ $match['parent_id'] ][ DTB_ProductMeta::PRODUCT_KIND ] ?? [];
						$parent_parts = $meta_map[ $match['parent_id'] ][ DTB_ProductMeta::IS_PARTS ] ?? [];
						$parent = $post_map[ $match['parent_id'] ] ?? [];
						$match['parent_is_tool'] = 'product' === ( $parent['post_type'] ?? '' ) && 'publish' === ( $parent['post_status'] ?? '' ) && ! in_array( 'part', $parent_kinds, true ) && ! in_array( '1', $parent_parts, true );
						$match['parent_is_part'] = 'product' === ( $parent['post_type'] ?? '' ) && 'publish' === ( $parent['post_status'] ?? '' ) && ( in_array( 'part', $parent_kinds, true ) || in_array( '1', $parent_parts, true ) );
					}
				}
				unset( $match );
			}
			unset( $matches );
		}
		return $map;
	}

	private static function validate_runtime_records( array $rows, array $identity_map ): array {
		$parts = [];
		$target_ids = [];
		foreach ( $rows as $row ) {
			$sku = $row['sku'];
			$matches = $identity_map[ $sku ] ?? [];
			if ( 1 !== count( $matches ) || $matches[0]['sku'] !== $sku || 'publish' !== $matches[0]['post_status'] ) {
				throw new RuntimeException( "{$sku}: part SKU does not resolve exactly to one published relationship owner." );
			}
			$id = (int) $matches[0]['id'];
			$is_variation_owner = 'product_variation' === $matches[0]['post_type'] && ! empty( $matches[0]['parent_is_part'] );
			$is_parent_owner = 'product' === $matches[0]['post_type'] && ! empty( $matches[0]['is_part'] );
			if ( ! $is_variation_owner && ! $is_parent_owner ) {
				throw new RuntimeException( "{$sku}: runtime relationship owner is not a part or a variation of a published part parent." );
			}
			$parts[ $sku ] = [ 'id' => $id ];
			foreach ( $row['after'] as $targets ) {
				foreach ( $targets as $target_sku ) {
					$key = strtoupper( $target_sku );
					$target_matches = $identity_map[ $key ] ?? [];
					if ( 1 !== count( $target_matches ) || $target_matches[0]['sku'] !== $target_sku || 'publish' !== $target_matches[0]['post_status'] || ( 'product' !== $target_matches[0]['post_type'] && ( 'product_variation' !== $target_matches[0]['post_type'] || empty( $target_matches[0]['parent_is_tool'] ) ) ) ) {
						throw new RuntimeException( "{$sku}: target {$target_sku} does not resolve exactly to a unique published tool or a variation of a published tool." );
					}
					$target_id = (int) $target_matches[0]['id'];
					if ( ! empty( $target_matches[0]['is_part'] ) ) {
						throw new RuntimeException( "{$sku}: target {$target_sku} is classified as a part, not a compatible tool." );
					}
					$target_ids[ $key ] = $target_id;
					if ( 'product_variation' === $target_matches[0]['post_type'] ) {
						$target_ids[ 'parent:' . $key ] = (int) $target_matches[0]['parent_id'];
					}
				}
			}
		}
		foreach ( $rows as $row ) {
			foreach ( $row['after'] as $targets ) {
				foreach ( $targets as $target_sku ) {
					if ( isset( $parts[ strtoupper( $target_sku ) ] ) ) {
						throw new RuntimeException( "{$row['sku']}: fitment target {$target_sku} resolves to another part in the same projection." );
					}
				}
			}
		}
		return [ 'parts' => $parts, 'targets' => $target_ids ];
	}

	private static function read_product_relationships( WC_Product $product ): array {
		$out = [];
		foreach ( self::FIELDS as $field => $meta_key ) {
			$values = [];
			foreach ( (array) get_post_meta( $product->get_id(), $meta_key, false ) as $raw ) {
				$values = array_merge( $values, DTB_CatalogProductNormalizer::decode_csv_or_array( $raw ) );
			}
			$seen = [];
			foreach ( $values as $value ) {
				$key = strtoupper( trim( (string) $value ) );
				if ( '' !== $key && ! isset( $seen[ $key ] ) ) {
					$seen[ $key ] = (string) $value;
				}
			}
			$out[ $field ] = array_values( $seen );
		}
		return $out;
	}

	private static function same_relationships( array $left, array $right ): bool {
		foreach ( self::FIELDS as $field => $_meta_key ) {
			$a = array_map( 'strtoupper', $left[ $field ] ?? [] );
			$b = array_map( 'strtoupper', $right[ $field ] ?? [] );
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				return false;
			}
		}
		return true;
	}

	private static function same_relationship_subset( array $actual, array $expected_subset ): bool {
		foreach ( $expected_subset as $field => $expected ) {
			if ( ! isset( self::FIELDS[ $field ] ) ) {
				return false;
			}
			$a = array_map( 'strtoupper', $actual[ $field ] ?? [] );
			$b = array_map( 'strtoupper', $expected );
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				return false;
			}
		}
		return true;
	}

	private static function same_relationship_map( array $actual, array $expected ): bool {
		$actual_fields = array_keys( $actual );
		$expected_fields = array_keys( $expected );
		sort( $actual_fields, SORT_STRING );
		sort( $expected_fields, SORT_STRING );
		return $actual_fields === $expected_fields && self::same_relationship_subset( $actual, $expected ) && self::same_relationship_subset( $expected, $actual );
	}

	private static function read_raw_relationships( WC_Product $product ): array {
		$out = [];
		foreach ( self::FIELDS as $field => $meta_key ) {
			$out[ $field ] = array_values( (array) get_post_meta( $product->get_id(), $meta_key, false ) );
		}
		return $out;
	}

	private static function row_state_is_expected( WC_Product $product, array $row ): bool {
		$current = self::read_product_relationships( $product );
		foreach ( self::FIELDS as $field => $_meta_key ) {
			if ( ! isset( $row['after'][ $field ] ) ) {
				continue;
			}
			$expected_before = $row['before'][ $field ] ?? [];
			$expected_after = $row['after'][ $field ];
			$only_field_before = $current;
			$only_field_after = $current;
			$only_field_before[ $field ] = $expected_before;
			$only_field_after[ $field ] = $expected_after;
			if ( ! self::same_relationships( $current, $only_field_before ) && ! self::same_relationships( $current, $only_field_after ) ) {
				return false;
			}
		}
		return true;
	}

	private static function build_plan( array $rows, array $products ): array {
		$plan = [];
		foreach ( $rows as $row ) {
			$product = wc_get_product( (int) $products[ $row['sku'] ]['id'] );
			$current = $product ? self::read_product_relationships( $product ) : [];
			$status = 'ready';
			$changed_fields = [];
			foreach ( $row['after'] as $field => $target_values ) {
				$now = array_map( 'strtoupper', $current[ $field ] ?? [] );
				$before = array_map( 'strtoupper', $row['before'][ $field ] ?? [] );
				$after = array_map( 'strtoupper', $target_values );
				sort( $now );
				sort( $before );
				sort( $after );
				if ( $now === $after ) {
					continue;
				}
				$changed_fields[ $field ] = true;
				if ( $now !== $before ) {
					$status = 'conflict';
				}
			}
			if ( 'ready' === $status && empty( $changed_fields ) ) {
				$status = 'already_applied';
			}
			$raw = self::read_raw_relationships( $product );
			$plan[] = [ 'sku' => $row['sku'], 'product_id' => $products[ $row['sku'] ]['id'], 'before' => array_intersect_key( $current, $changed_fields ), 'before_raw' => array_intersect_key( $raw, array_fill_keys( array_keys( $changed_fields ), true ) ), 'after' => array_intersect_key( $row['after'], $changed_fields ), 'status' => $status ];
		}
		return $plan;
	}

	private static function write_relationships( WC_Product $product, array $relationships, array $raw_restore = [] ): void {
		foreach ( $relationships as $field => $values ) {
			$meta_key = self::WRITABLE_FIELDS[ $field ] ?? '';
			if ( '' === $meta_key ) {
				throw new RuntimeException( 'Unknown relationship field reached the persistence boundary.' );
			}
			$product->delete_meta_data( $meta_key );
			if ( array_key_exists( $field, $raw_restore ) ) {
				foreach ( (array) $raw_restore[ $field ] as $raw_value ) {
					$product->add_meta_data( $meta_key, $raw_value, false );
				}
			} else {
				$product->add_meta_data( $meta_key, wp_json_encode( array_values( $values ) ), true );
			}
		}
		$product->save();
		$verified = wc_get_product( $product->get_id() );
		if ( ! $verified || ! self::same_relationships( self::read_product_relationships( $verified ), array_replace( self::read_product_relationships( $verified ), $relationships ) ) ) {
			throw new RuntimeException( 'Compatibility projection read-back did not match the approved artifact.' );
		}
	}

	private static function write_private_report( array $report, string $existing_path = '' ): string {
		$dir = self::private_report_dir();
		$path = '' !== $existing_path ? $existing_path : $dir . '/projection-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 8, false, false ) . '.json';
		$real_dir = realpath( $dir );
		$parent = realpath( dirname( $path ) );
		if ( ! $real_dir || $parent !== $real_dir ) {
			throw new RuntimeException( 'Report path must stay inside the configured private directory.' );
		}
		self::cleanup_expired_reports( $real_dir );
		$temp = $path . '.tmp';
		$payload = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$previous_umask = umask( 0077 );
		$written = is_string( $payload ) ? file_put_contents( $temp, $payload . "\n", LOCK_EX ) : false;
		umask( $previous_umask );
		if ( false === $written || ! @chmod( $temp, 0600 ) || ! rename( $temp, $path ) ) {
			@unlink( $temp );
			throw new RuntimeException( 'Unable to write the compatibility rollback report atomically.' );
		}
		if ( ! @chmod( $path, 0600 ) ) {
			@unlink( $path );
			throw new RuntimeException( 'Unable to restrict permissions on the rollback report.' );
		}
		return $path;
	}

	private static function read_private_report( string $path, string $expected_sha256, string $expected_type ): array {
		$private_dir = realpath( self::private_report_dir() );
		$real_path = realpath( $path );
		if ( ! $private_dir || ! $real_path || dirname( $real_path ) !== $private_dir ) {
			throw new RuntimeException( 'Reconciliation report must resolve to a file in the private compatibility report directory.' );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/i', $expected_sha256 ) || ! is_file( $real_path ) || ! is_readable( $real_path ) || ! hash_equals( strtolower( $expected_sha256 ), strtolower( (string) hash_file( 'sha256', $real_path ) ) ) ) {
			throw new RuntimeException( 'Reconciliation report is unavailable or its SHA-256 does not match.' );
		}
		$report = json_decode( (string) file_get_contents( $real_path ), true );
		if ( ! is_array( $report ) || $expected_type !== ( $report['report_type'] ?? '' ) || ! is_array( $report['rows'] ?? null ) || count( $report['rows'] ) > self::MAX_PARTS ) {
			throw new RuntimeException( 'Reconciliation report schema is invalid.' );
		}
		return $report;
	}

	private static function private_report_dir(): string {
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false;
		$private_base = $document_root ? dirname( $document_root ) : dirname( untrailingslashit( ABSPATH ), 2 );
		$configured_dir = defined( 'DTB_RELATIONSHIP_PRIVATE_DIR' ) ? DTB_RELATIONSHIP_PRIVATE_DIR : $private_base . '/.dtb-private/product-relationships';
		if ( ! is_string( $configured_dir ) || '' === $configured_dir ) {
			throw new RuntimeException( 'The private compatibility projection directory is not configured.' );
		}
		$dir = untrailingslashit( $configured_dir );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Unable to create the configured private compatibility rollback directory.' );
		}
		if ( ! @chmod( $dir, 0700 ) ) {
			throw new RuntimeException( 'Unable to restrict permissions on the private rollback directory.' );
		}
		$real_dir = realpath( $dir );
		$web_root = realpath( ABSPATH );
		$document_root = isset( $_SERVER['DOCUMENT_ROOT'] ) ? realpath( (string) $_SERVER['DOCUMENT_ROOT'] ) : false;
		if ( ! $real_dir || ! $web_root || self::is_path_within( $real_dir, $web_root ) || ( $document_root && self::is_path_within( $real_dir, $document_root ) ) ) {
			throw new RuntimeException( 'Configured rollback directory must resolve outside the WordPress and web document roots.' );
		}
		return $real_dir;
	}

	private static function record_release_batch( array $artifact, string $artifact_sha256, string $batch_status, array $part_skus, ?string $report_path, ?string $report_sha256, array $public_api_readback = [] ): array {
		$release_id = strtolower( (string) ( $artifact['release_id'] ?? '' ) );
		$batch_index = absint( $artifact['batch_index'] ?? 0 );
		$batch_count = absint( $artifact['batch_count'] ?? 0 );
		if ( ! preg_match( '/^[a-f0-9]{24}$/', $release_id ) || $batch_index < 1 || $batch_count < $batch_index || ! in_array( $batch_status, [ 'applying', 'applied', 'already_applied', 'failed', 'rolled_back', 'rollback_failed' ], true ) ) {
			throw new RuntimeException( 'Cannot record invalid compatibility release batch state.' );
		}
		global $wpdb;
		$lock_name = 'dtb_rel_release_' . $release_id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ) ) {
			throw new RuntimeException( 'Another process is updating this release reconciliation report.' );
		}
		try {
			$dir = self::private_report_dir();
			$path = $dir . '/release-' . $release_id . '.json';
			if ( is_file( $path ) ) {
				$report = json_decode( (string) file_get_contents( $path ), true );
				if ( ! is_array( $report ) || ( $report['release_id'] ?? '' ) !== $release_id || (int) ( $report['batch_count'] ?? 0 ) !== $batch_count || ( $report['catalog_sha256'] ?? '' ) !== ( $artifact['catalog_sha256'] ?? '' ) || ( $report['manifest_sha256'] ?? '' ) !== ( $artifact['manifest_sha256'] ?? '' ) || ( $report['catalog_apply_report_sha256'] ?? '' ) !== ( $artifact['catalog_apply_report_sha256'] ?? '' ) || ( $report['batch_inventory_sha256'] ?? '' ) !== ( $artifact['batch_inventory_sha256'] ?? '' ) ) {
					throw new RuntimeException( 'Release batch does not match the immutable release digests.' );
				}
			} else {
				$report = [
					'schema_version' => 1,
					'report_type' => 'dtb_compatibility_release_reconciliation',
					'release_id' => $release_id,
					'catalog_sha256' => (string) ( $artifact['catalog_sha256'] ?? '' ),
					'manifest_sha256' => (string) ( $artifact['manifest_sha256'] ?? '' ),
					'catalog_apply_report_sha256' => (string) ( $artifact['catalog_apply_report_sha256'] ?? '' ),
					'batch_inventory' => $artifact['batch_inventory'],
					'batch_inventory_sha256' => $artifact['batch_inventory_sha256'],
					'batch_count' => $batch_count,
					'created_at' => gmdate( 'c' ),
					'batches' => [],
				];
			}
			$recorded_batch = $report['batches'][ (string) $batch_index ] ?? null;
			if ( is_array( $recorded_batch ) && ! hash_equals( (string) ( $recorded_batch['artifact_sha256'] ?? '' ), $artifact_sha256 ) ) {
				throw new RuntimeException( 'Release batch digest changed after it was first recorded.' );
			}
			$previous_batch = $report['batches'][ (string) $batch_index ] ?? [];
			$attempts = is_array( $previous_batch['attempts'] ?? null ) ? $previous_batch['attempts'] : [];
			if ( $previous_batch && empty( $previous_batch['attempts'] ) ) {
				$attempts[] = array_intersect_key( $previous_batch, array_flip( [ 'artifact_sha256', 'status', 'part_skus', 'report_path', 'report_sha256', 'updated_at' ] ) );
			}
			$attempts[] = [ 'artifact_sha256' => $artifact_sha256, 'status' => $batch_status, 'part_skus' => array_values( $part_skus ), 'report_path' => $report_path, 'report_sha256' => $report_sha256, 'updated_at' => gmdate( 'c' ) ];
			$effective_status = $batch_status;
			$effective_report_path = $report_path;
			$effective_report_sha256 = $report_sha256;
			if ( 'already_applied' === $batch_status && 'applied' === ( $previous_batch['status'] ?? '' ) ) {
				$effective_status = 'applied';
				$effective_report_path = $previous_batch['report_path'] ?? null;
				$effective_report_sha256 = $previous_batch['report_sha256'] ?? null;
			}
			$effective_part_skus = 'already_applied' === $batch_status && 'applied' === ( $previous_batch['status'] ?? '' ) ? ( $previous_batch['part_skus'] ?? $part_skus ) : $part_skus;
			$report['batches'][ (string) $batch_index ] = [
				'artifact_sha256' => $artifact_sha256,
				'status' => $effective_status,
				'part_skus' => array_values( $effective_part_skus ),
				'report_path' => $effective_report_path,
				'report_sha256' => $effective_report_sha256,
				'public_api_readback' => $public_api_readback,
				'updated_at' => gmdate( 'c' ),
				'attempts' => $attempts,
			];
		$all_recorded = true;
		$all_success = true;
		$all_rolled_back = true;
		$has_terminal_failure = false;
		$has_rolled_back = false;
		$has_success = false;
		for ( $index = 1; $index <= $batch_count; $index++ ) {
			$state = $report['batches'][ (string) $index ]['status'] ?? '';
			$all_recorded = $all_recorded && in_array( $state, [ 'applied', 'already_applied', 'failed', 'rolled_back', 'rollback_failed' ], true );
			$all_success = $all_success && in_array( $state, [ 'applied', 'already_applied' ], true );
			$all_rolled_back = $all_rolled_back && 'rolled_back' === $state;
			$has_terminal_failure = $has_terminal_failure || in_array( $state, [ 'failed', 'rollback_failed' ], true );
			$has_rolled_back = $has_rolled_back || 'rolled_back' === $state;
			$has_success = $has_success || in_array( $state, [ 'applied', 'already_applied' ], true );
		}
		$report['status'] = $all_success ? 'complete' : ( $all_rolled_back ? 'rolled_back' : ( $all_recorded && $has_terminal_failure ? ( $has_success || $has_rolled_back ? 'partial_failure' : 'failed' ) : ( $all_recorded && $has_rolled_back ? 'partially_rolled_back' : ( $has_rolled_back && $has_success ? 'partial' : 'in_progress' ) ) ) );
		$report['updated_at'] = gmdate( 'c' );
		$path = self::write_private_report( $report, $path );
		$report['report_sha256'] = hash_file( 'sha256', $path );
		foreach ( $report['batches'] as &$batch ) {
			$report_path = (string) ( $batch['report_path'] ?? '' );
			if ( '' !== $report_path && is_file( $report_path ) ) {
				$batch['current_report_sha256'] = hash_file( 'sha256', $report_path );
			}
		}
		unset( $batch );
		return $report;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	private static function is_path_within( string $path, string $root ): bool {
		$path = rtrim( wp_normalize_path( $path ), '/' ) . '/';
		$root = rtrim( wp_normalize_path( $root ), '/' ) . '/';
		return 0 === strpos( $path, $root );
	}

	private static function canonical_sha256( $value ): string {
		$value = self::canonicalize( $value );
		$payload = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $payload ) ) {
			throw new RuntimeException( 'Unable to encode compatibility release inventory.' );
		}
		return hash( 'sha256', $payload );
	}

	private static function expected_release_id( array $artifact, string $inventory_digest ): string {
		$release_payload = implode(
			'|',
			[
				'dtb-compatibility-release-v2',
				strtolower( (string) ( $artifact['catalog_sha256'] ?? '' ) ),
				strtolower( (string) ( $artifact['manifest_sha256'] ?? '' ) ),
				strtolower( (string) ( $artifact['catalog_apply_report_sha256'] ?? '' ) ),
				strtolower( $inventory_digest ),
			]
		);
		return substr( hash( 'sha256', $release_payload ), 0, 24 );
	}

	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		$is_list = true;
		$expected_key = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected_key++ ) {
				$is_list = false;
				break;
			}
		}
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		return $value;
	}

	private static function cleanup_expired_reports( string $dir ): void {
		$cutoff = time() - YEAR_IN_SECONDS;
		foreach ( array_merge( glob( $dir . '/projection-*.json' ) ?: [], glob( $dir . '/release-*.json' ) ?: [] ) as $file ) {
			$real_file = realpath( $file );
			if ( $real_file && dirname( $real_file ) === $dir && filemtime( $real_file ) < $cutoff ) {
				@unlink( $real_file );
			}
		}
	}

	private static function verify_public_routes( array $rows, array $products ): array {
		$verified_parts = [];
		$verified_tools = [];
		$targets = [];
		$public_parts = [];
		foreach ( $rows as $row ) {
			$part_id = absint( $products[ $row['sku'] ]['id'] ?? 0 );
			$part = $part_id > 0 ? wc_get_product( $part_id ) : false;
			if ( $part instanceof WC_Product_Variation ) {
				$part = wc_get_product( absint( $part->get_parent_id() ) );
			}
			if ( ! $part ) {
				throw new RuntimeException( "Could not determine public visibility for part {$row['sku']}." );
			}
			$public_parts[ $row['sku'] ] = (bool) $part->is_visible();
		}
		foreach ( $rows as $row ) {
			$expected_tools = [];
			foreach ( array_merge( $row['before'], $row['after'] ) as $values ) {
				foreach ( $values as $sku ) {
					$targets[ strtoupper( $sku ) ] = $sku;
				}
			}
			foreach ( $row['after'] as $values ) {
				foreach ( $values as $sku ) {
					$expected_tools[ strtoupper( $sku ) ] = $sku;
				}
			}
			$path = '/dtb/v1/parts/' . rawurlencode( $row['sku'] ) . '/compatible-tools';
			$response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
			if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response || 200 !== $response->get_status() ) {
				throw new RuntimeException( "Public compatible-tools route read-back failed for {$row['sku']}." );
			}
			$data = $response->get_data();
			$actual_tools = self::compatible_tool_sku_set( (array) ( $data['products'] ?? [] ) );
			foreach ( $expected_tools as $tool_sku => $_display_sku ) {
				if ( empty( $actual_tools[ $tool_sku ] ) ) {
					throw new RuntimeException( "Public compatible-tools route omitted {$row['sku']} -> {$tool_sku}." );
				}
			}
			$removed_tools = [];
			foreach ( $row['before'] as $values ) {
				foreach ( $values as $sku ) {
					$removed_tools[ strtoupper( $sku ) ] = true;
				}
			}
			foreach ( $row['after'] as $values ) {
				foreach ( $values as $sku ) {
					unset( $removed_tools[ strtoupper( $sku ) ] );
				}
			}
			foreach ( array_keys( $removed_tools ) as $removed_sku ) {
				if ( isset( $actual_tools[ $removed_sku ] ) ) {
					throw new RuntimeException( "Public compatible-tools route still returns removed target {$row['sku']} -> {$removed_sku}." );
				}
			}
			$verified_parts[] = $row['sku'];
		}
		foreach ( $targets as $target_sku ) {
			$path = '/dtb/v1/products/' . rawurlencode( $target_sku ) . '/compatible-parts';
			$response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
			if ( is_wp_error( $response ) || ! $response instanceof WP_REST_Response || 200 !== $response->get_status() ) {
				throw new RuntimeException( "Public compatible-parts route read-back failed for tool SKU {$target_sku}." );
			}
			$data = $response->get_data();
			$actual_parts = self::compatible_part_sku_set( (array) ( $data['products'] ?? [] ) );
			foreach ( $rows as $row ) {
				$part_targets = [];
				foreach ( array_merge( $row['before'], $row['after'] ) as $values ) {
					foreach ( $values as $sku ) {
						$part_targets[ strtoupper( $sku ) ] = true;
					}
				}
				$after_targets = [];
				foreach ( $row['after'] as $values ) {
					foreach ( $values as $sku ) {
						$after_targets[ strtoupper( $sku ) ] = true;
					}
				}
				if ( empty( $public_parts[ $row['sku'] ] ) && isset( $actual_parts[ $row['sku'] ] ) ) {
					throw new RuntimeException( "Public compatible-parts route exposed hidden part {$row['sku']} for tool SKU {$target_sku}." );
				}
				if ( ! empty( $public_parts[ $row['sku'] ] ) && isset( $after_targets[ strtoupper( $target_sku ) ] ) && empty( $actual_parts[ $row['sku'] ] ) ) {
					throw new RuntimeException( "Public compatible-parts route omitted {$row['sku']} for tool SKU {$target_sku}." );
				}
				if ( isset( $part_targets[ strtoupper( $target_sku ) ] ) && ! isset( $after_targets[ strtoupper( $target_sku ) ] ) && isset( $actual_parts[ $row['sku'] ] ) ) {
					throw new RuntimeException( "Public compatible-parts route still returns removed part {$row['sku']} for tool SKU {$target_sku}." );
				}
			}
			$verified_tools[] = $target_sku;
		}
		return [ 'verified_part_skus' => $verified_parts, 'verified_tool_skus' => $verified_tools ];
	}

	/** Include exact variation SKUs returned under their parent product DTO. */
	private static function compatible_tool_sku_set( array $products ): array {
		$skus = [];
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$sku = strtoupper( trim( (string) ( $product['sku'] ?? '' ) ) );
			if ( '' !== $sku ) {
				$skus[ $sku ] = true;
			}
			foreach ( (array) ( $product['compatible_variations'] ?? [] ) as $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}
				$variation_sku = strtoupper( trim( (string) ( $variation['sku'] ?? '' ) ) );
				if ( '' !== $variation_sku ) {
					$skus[ $variation_sku ] = true;
				}
			}
		}
		return $skus;
	}

	/** Include exact part variation SKUs returned beneath each parent product DTO. */
	private static function compatible_part_sku_set( array $products ): array {
		$skus = [];
		foreach ( $products as $product ) {
			if ( ! is_array( $product ) ) {
				continue;
			}
			$sku = strtoupper( trim( (string) ( $product['sku'] ?? '' ) ) );
			if ( '' !== $sku ) {
				$skus[ $sku ] = true;
			}
			foreach ( (array) ( $product['compatible_part_variations'] ?? [] ) as $variation ) {
				if ( ! is_array( $variation ) ) {
					continue;
				}
				$variation_sku = strtoupper( trim( (string) ( $variation['sku'] ?? '' ) ) );
				if ( '' !== $variation_sku ) {
					$skus[ $variation_sku ] = true;
				}
			}
		}
		return $skus;
	}
}

