<?php
/**
 * Universal parts projection/sync service.
 *
 * Projects the committed backend universal-parts seed CSVs onto WooCommerce part
 * products as product meta. This keeps the frontend brand-specific while giving
 * Inventory Intelligence and repair workflows a deterministic backend universal
 * part layer to read from.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_UniversalPartsProjectionService {
	/** @var array<string,array<string,string>>|null */
	private ?array $parts_by_id = null;
	private ?string $data_dir_override;

	public function __construct( ?string $data_dir = null ) {
		$this->data_dir_override = null === $data_dir ? null : rtrim( $data_dir, '/\\' );
	}

	public function data_dir(): string {
		if ( null !== $this->data_dir_override ) {
			return $this->data_dir_override;
		}

		$repo_root = dirname( __DIR__, 6 );
		$production_dir = $repo_root . '/products/Production/launch/universal_parts';
		if ( is_dir( $production_dir ) ) {
			return $production_dir;
		}

		$canonical_dir = $repo_root . '/products/launch/universal_parts';
		return is_dir( $canonical_dir ) ? $canonical_dir : $production_dir;
	}

	public function file_path( string $type ): string {
		$files = [
			'parts'         => 'parts.csv',
			'members'       => 'members.csv',
			'compatibility' => 'compatibility.csv',
		];
		return $this->data_dir() . '/' . ( $files[ $type ] ?? '' );
	}

	/** @return array<int,array<string,string>> */
	public function read_csv_assoc( string $path ): array {
		if ( ! is_readable( $path ) ) {
			return [];
		}

		$fp = fopen( $path, 'r' );
		if ( false === $fp ) {
			return [];
		}

		$header = fgetcsv( $fp );
		if ( ! is_array( $header ) ) {
			fclose( $fp );
			return [];
		}

		$header = array_map( static fn( $h ) => trim( (string) $h ), $header );
		$rows   = [];
		while ( ( $row = fgetcsv( $fp ) ) !== false ) {
			$assoc = [];
			foreach ( $header as $idx => $key ) {
				$assoc[ $key ] = (string) ( $row[ $idx ] ?? '' );
			}
			if ( ! empty( array_filter( $assoc, static fn( $v ) => '' !== trim( (string) $v ) ) ) ) {
				$rows[] = $assoc;
			}
		}
		fclose( $fp );

		return $rows;
	}

	/** @return array<string,array<string,string>> */
	public function parts_by_id(): array {
		if ( null !== $this->parts_by_id ) {
			return $this->parts_by_id;
		}

		$out = [];
		foreach ( $this->read_csv_assoc( $this->file_path( 'parts' ) ) as $row ) {
			$id = sanitize_text_field( (string) ( $row['universal_part_id'] ?? '' ) );
			if ( '' !== $id ) {
				$out[ $id ] = $row;
			}
		}

		$this->parts_by_id = $out;
		return $out;
	}

	public function summary(): array {
		$parts         = $this->read_csv_assoc( $this->file_path( 'parts' ) );
		$members       = $this->read_csv_assoc( $this->file_path( 'members' ) );
		$compatibility = $this->read_csv_assoc( $this->file_path( 'compatibility' ) );

		$counts = [
			'parts'         => count( $parts ),
			'members'       => count( $members ),
			'compatibility' => count( $compatibility ),
			'active'        => 0,
			'review'        => 0,
			'quarantine'    => 0,
			'verified'      => 0,
			'high'          => 0,
			'medium'        => 0,
			'low'           => 0,
		];

		foreach ( $parts as $row ) {
			$status = sanitize_key( (string) ( $row['status'] ?? '' ) );
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
			$confidence = sanitize_key( (string) ( $row['confidence'] ?? '' ) );
			if ( isset( $counts[ $confidence ] ) ) {
				$counts[ $confidence ]++;
			}
		}

		return [
			'counts'      => $counts,
			'seed_dir'    => $this->data_dir(),
			'seed_exists' => is_dir( $this->data_dir() ),
			'files'       => [
				'parts'         => is_readable( $this->file_path( 'parts' ) ),
				'members'       => is_readable( $this->file_path( 'members' ) ),
				'compatibility' => is_readable( $this->file_path( 'compatibility' ) ),
			],
		];
	}

	public function list_seed_parts( int $page = 1, string $status = '', string $search = '' ): array {
		$page   = max( 1, $page );
		$limit  = 20;
		$status = sanitize_key( $status );
		$search = strtolower( sanitize_text_field( $search ) );
		$rows   = $this->read_csv_assoc( $this->file_path( 'parts' ) );

		$rows = array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $status, $search ): bool {
					if ( '' !== $status && sanitize_key( (string) ( $row['status'] ?? '' ) ) !== $status ) {
						return false;
					}
					if ( '' !== $search ) {
						$haystack = strtolower( implode( ' ', [ $row['universal_part_id'] ?? '', $row['canonical_name'] ?? '', $row['brands'] ?? '', $row['catalog_skus'] ?? '' ] ) );
						return false !== strpos( $haystack, $search );
					}
					return true;
				}
			)
		);

		$total = count( $rows );
		$pages = max( 1, (int) ceil( $total / $limit ) );
		$slice = array_slice( $rows, ( $page - 1 ) * $limit, $limit );

		return [
			'items' => $slice,
			'total' => $total,
			'pages' => $pages,
			'page'  => $page,
		];
	}

	public function export_seed_file( string $type ): array|WP_Error {
		$type = sanitize_key( $type );
		if ( ! in_array( $type, [ 'parts', 'members', 'compatibility' ], true ) ) {
			$type = 'parts';
		}

		$path = $this->file_path( $type );
		if ( ! is_readable( $path ) ) {
			return new WP_Error( 'seed_file_missing', 'Universal seed file is not readable.' );
		}

		return [
			'filename' => 'dtb-universal-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv',
			'mime'     => 'text/csv;charset=utf-8',
			'content'  => (string) file_get_contents( $path ),
		];
	}

	public function sync_members( string $mode = 'dry_run' ): array {
		$mode = sanitize_key( $mode );
		if ( ! in_array( $mode, [ 'dry_run', 'apply' ], true ) ) {
			$mode = 'dry_run';
		}

		$unavailable_files = [];
		foreach ( [ 'parts', 'members' ] as $type ) {
			$path = $this->file_path( $type );
			if ( ! is_file( $path ) || ! is_readable( $path ) ) {
				$unavailable_files[] = basename( $path );
			}
		}
		if ( ! empty( $unavailable_files ) ) {
			return [
				'mode'       => $mode,
				'error'      => 'seed_files_unavailable',
				'resolved'   => 0,
				'unresolved' => 0,
				'updated'    => 0,
				'conflicts'  => [],
				'preview'    => [],
				'message'    => 'Universal seed sync stopped: required input file(s) are missing or unreadable: ' . implode( ', ', $unavailable_files ) . '.',
			];
		}

		$parts_by_id = $this->parts_by_id();
		$members     = $this->read_csv_assoc( $this->file_path( 'members' ) );
		if ( empty( $parts_by_id ) || empty( $members ) ) {
			return [
				'mode'       => $mode,
				'error'      => 'seed_data_empty',
				'resolved'   => 0,
				'unresolved' => 0,
				'updated'    => 0,
				'conflicts'  => [],
				'preview'    => [],
				'message'    => 'Universal seed sync stopped: parts.csv and members.csv must each contain valid data rows.',
			];
		}
		$resolved    = 0;
		$unresolved  = 0;
		$updated     = 0;
		$conflicts   = [];
		$preview     = [];
		$post_to_uid = [];
		$started_at  = current_time( 'mysql', true );
		$start_ms    = microtime( true );

		foreach ( $members as $row ) {
			$universal_id = sanitize_text_field( (string) ( $row['universal_part_id'] ?? '' ) );
			if ( '' === $universal_id || ! isset( $parts_by_id[ $universal_id ] ) ) {
				$conflicts[] = [ 'universal_part_id' => $universal_id, 'reason' => 'Unknown universal_part_id in members.csv.' ];
				continue;
			}

			$resolution = $this->resolve_member_product_id( $row );
			$post_id    = (int) ( $resolution['product_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				$unresolved++;
				$conflicts[] = [
					'universal_part_id' => $universal_id,
					'brand'             => (string) ( $row['brand'] ?? '' ),
					'brand_sku'         => (string) ( $row['brand_sku'] ?? '' ),
					'manufacturer_sku'  => (string) ( $row['manufacturer_sku'] ?? '' ),
					'reason'            => (string) ( $resolution['reason'] ?? 'identity_unresolved' ),
					'candidate_ids'     => (array) ( $resolution['candidate_ids'] ?? [] ),
				];
				if ( count( $preview ) < 25 ) {
					$preview[] = [
						'universal_part_id' => $universal_id,
						'brand'             => (string) ( $row['brand'] ?? '' ),
						'brand_sku'         => (string) ( $row['brand_sku'] ?? '' ),
						'manufacturer_sku'  => (string) ( $row['manufacturer_sku'] ?? '' ),
						'result'            => 'unresolved',
						'reason'            => (string) ( $resolution['reason'] ?? 'identity_unresolved' ),
					];
				}
				continue;
			}

			if ( isset( $post_to_uid[ $post_id ] ) && $post_to_uid[ $post_id ] !== $universal_id ) {
				$conflicts[] = [
					'post_id'           => $post_id,
					'previous_universal' => $post_to_uid[ $post_id ],
					'next_universal'     => $universal_id,
					'reason'             => 'One product resolved to multiple universal IDs in the same sync.',
				];
				continue;
			}

			$post_to_uid[ $post_id ] = $universal_id;
			$resolved++;
			$part_row = $parts_by_id[ $universal_id ];

			if ( 'apply' === $mode ) {
				$this->apply_universal_meta(
					$post_id,
					[
						'universal_part_id'         => $universal_id,
						'universal_part_status'     => '' !== trim( (string) ( $row['status'] ?? '' ) ) ? (string) $row['status'] : (string) ( $part_row['status'] ?? 'review' ),
						'universal_part_confidence' => '' !== trim( (string) ( $row['confidence'] ?? '' ) ) ? (string) $row['confidence'] : (string) ( $part_row['confidence'] ?? 'review' ),
						'universal_part_family'     => (string) ( $part_row['part_family'] ?? '' ),
						'universal_part_signature'  => (string) ( $part_row['source_audit_key'] ?? '' ),
					]
				);
				$updated++;
			}

			if ( count( $preview ) < 25 ) {
				$preview[] = [
					'post_id'           => $post_id,
					'universal_part_id' => $universal_id,
					'brand'             => (string) ( $row['brand'] ?? '' ),
					'brand_sku'         => (string) ( $row['brand_sku'] ?? '' ),
					'result'            => 'resolved',
				];
			}
		}

		if ( 'apply' === $mode && class_exists( 'DTB_InventoryRollupRepository' ) ) {
			$repo = new DTB_InventoryRollupRepository();
			$repo->log_sync_run(
				[
					'job_key'         => 'dtb_universal_parts_projection_sync',
					'status'          => empty( $conflicts ) ? 'completed' : 'completed_with_conflicts',
					'started_at'      => $started_at,
					'finished_at'     => current_time( 'mysql', true ),
					'duration_ms'     => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
					'records_seen'    => count( $members ),
					'records_updated' => $updated,
				'records_failed'  => max( 0, count( $members ) - $resolved ),
					'error_message'   => empty( $conflicts ) ? '' : 'Projection sync completed with conflicts.',
				]
			);
		}

		return [
			'mode'       => $mode,
			'resolved'   => $resolved,
			'unresolved' => $unresolved,
			'updated'    => $updated,
			'conflicts'  => $conflicts,
			'preview'    => $preview,
			'message'    => 'apply' === $mode ? sprintf( 'Universal seed projection applied. %d products updated.', $updated ) : sprintf( 'Universal seed dry run complete. %d resolved, %d unresolved.', $resolved, $unresolved ),
		];
	}

	public function apply_universal_meta( int $post_id, array $payload ): void {
		$product = wc_get_product( $post_id );
		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return;
		}

		$universal_id = sanitize_text_field( (string) ( $payload['universal_part_id'] ?? '' ) );
		$status       = sanitize_key( (string) ( $payload['universal_part_status'] ?? '' ) );
		$confidence   = sanitize_key( (string) ( $payload['universal_part_confidence'] ?? '' ) );
		$family       = sanitize_text_field( (string) ( $payload['universal_part_family'] ?? '' ) );
		$signature    = sanitize_text_field( (string) ( $payload['universal_part_signature'] ?? '' ) );

		if ( '' === $universal_id ) {
			foreach ( [ DTB_ProductMeta::UNIVERSAL_PART_ID, DTB_ProductMeta::UNIVERSAL_PART_STATUS, DTB_ProductMeta::UNIVERSAL_PART_CONFIDENCE, DTB_ProductMeta::UNIVERSAL_PART_FAMILY, DTB_ProductMeta::UNIVERSAL_PART_SIGNATURE, DTB_ProductMeta::UNIVERSAL_PART_SYNCED_AT ] as $key ) {
				$product->delete_meta_data( $key );
			}
			$product->save();
			return;
		}

		if ( ! in_array( $status, [ 'active', 'review', 'quarantine' ], true ) ) {
			$status = 'review';
		}
		if ( ! in_array( $confidence, [ 'verified', 'high', 'medium', 'low', 'review' ], true ) ) {
			$confidence = 'review';
		}

		$product->update_meta_data( DTB_ProductMeta::UNIVERSAL_PART_ID, $universal_id );
		$product->update_meta_data( DTB_ProductMeta::UNIVERSAL_PART_STATUS, $status );
		$product->update_meta_data( DTB_ProductMeta::UNIVERSAL_PART_CONFIDENCE, $confidence );
		$product->update_meta_data( DTB_ProductMeta::UNIVERSAL_PART_FAMILY, $family );
		$product->update_meta_data( DTB_ProductMeta::UNIVERSAL_PART_SIGNATURE, $signature );
		$product->update_meta_data( DTB_ProductMeta::UNIVERSAL_PART_SYNCED_AT, gmdate( 'c' ) );
		$product->save();
	}

	private function resolve_member_product_id( array $row ): array {
		$brand_sku        = sanitize_text_field( (string) ( $row['brand_sku'] ?? '' ) );
		$manufacturer_sku = sanitize_text_field( (string) ( $row['manufacturer_sku'] ?? '' ) );

		$sku_resolution = $this->find_part_id_by_sku( $brand_sku );
		if ( (int) $sku_resolution['product_id'] > 0 || ( '' !== $brand_sku && 'sku_not_found' !== $sku_resolution['reason'] ) ) {
			return $sku_resolution;
		}

		if ( '' === trim( $manufacturer_sku ) ) {
			return $sku_resolution;
		}

		return $this->find_part_id_by_manufacturer_sku( $manufacturer_sku );
	}

	private function find_part_id_by_sku( string $sku ): array {
		if ( '' === trim( $sku ) ) {
			return [ 'product_id' => 0, 'reason' => 'sku_not_provided', 'candidate_ids' => [] ];
		}
		$product_id = wc_get_product_id_by_sku( $sku );
		if ( $product_id <= 0 ) {
			return [ 'product_id' => 0, 'reason' => 'sku_not_found', 'candidate_ids' => [] ];
		}
		if ( 'product' !== get_post_type( $product_id ) ) {
			return [ 'product_id' => 0, 'reason' => 'sku_resolves_to_non_product', 'candidate_ids' => [ (int) $product_id ] ];
		}
		if ( ! $this->is_catalog_part( (int) $product_id ) ) {
			return [ 'product_id' => 0, 'reason' => 'sku_resolves_to_non_part', 'candidate_ids' => [ (int) $product_id ] ];
		}
		return [ 'product_id' => (int) $product_id, 'reason' => 'resolved_by_sku', 'candidate_ids' => [ (int) $product_id ] ];
	}

	private function find_part_id_by_manufacturer_sku( string $manufacturer_sku ): array {
		if ( '' === trim( $manufacturer_sku ) ) {
			return [ 'product_id' => 0, 'reason' => 'manufacturer_sku_not_provided', 'candidate_ids' => [] ];
		}

		$ids = get_posts(
			[
				'post_type'      => 'product',
				'post_status'    => 'any',
				'posts_per_page' => 2,
				'fields'         => 'ids',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'relation' => 'OR',
						[
							'key'     => DTB_ProductMeta::IS_PARTS,
							'value'   => '1',
							'compare' => '=',
						],
						[
							'key'     => DTB_ProductMeta::PRODUCT_KIND,
							'value'   => 'part',
							'compare' => '=',
						],
					],
					[
						'key'     => DTB_ProductMeta::MANUFACTURER_SKU,
						'value'   => $manufacturer_sku,
						'compare' => '=',
					],
				],
			]
		);

		if ( 1 === count( $ids ) ) {
			return [ 'product_id' => (int) $ids[0], 'reason' => 'resolved_by_manufacturer_sku', 'candidate_ids' => [ (int) $ids[0] ] ];
		}
		if ( count( $ids ) > 1 ) {
			return [ 'product_id' => 0, 'reason' => 'manufacturer_sku_ambiguous', 'candidate_ids' => array_map( 'intval', $ids ) ];
		}
		return [ 'product_id' => 0, 'reason' => 'manufacturer_sku_not_found', 'candidate_ids' => [] ];
	}

	private function is_catalog_part( int $product_id ): bool {
		return '1' === (string) get_post_meta( $product_id, DTB_ProductMeta::IS_PARTS, true )
			|| 'part' === (string) get_post_meta( $product_id, DTB_ProductMeta::PRODUCT_KIND, true );
	}
}
