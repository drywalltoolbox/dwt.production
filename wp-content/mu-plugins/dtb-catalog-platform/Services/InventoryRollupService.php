<?php
/**
 * Universal inventory rollup service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_InventoryRollupService {
	private DTB_InventoryStockRepository $stock_repository;
	private DTB_InventoryRollupRepository $rollup_repository;

	public function __construct() {
		$this->stock_repository  = new DTB_InventoryStockRepository();
		$this->rollup_repository = new DTB_InventoryRollupRepository();
	}

	/**
	 * Recompute every active universal-part rollup currently projected onto Woo products.
	 *
	 * @return array<string,int>
	 */
	public function recompute_all(): array {
		$started_at = current_time( 'mysql', true );
		$start_ms   = microtime( true );
		$groups     = $this->get_projected_universal_groups();
		$updated    = 0;
		$failed     = 0;

		foreach ( $groups as $universal_part_id => $members ) {
			$ok = $this->recompute_universal_part( (string) $universal_part_id, $members );
			if ( $ok ) {
				$updated++;
			} else {
				$failed++;
			}
		}

		$this->rollup_repository->log_sync_run(
			[
				'job_key'         => 'dtb_inventory_rollup_recompute',
				'status'          => 0 === $failed ? 'completed' : 'completed_with_errors',
				'started_at'      => $started_at,
				'finished_at'     => current_time( 'mysql', true ),
				'duration_ms'     => (int) round( ( microtime( true ) - $start_ms ) * 1000 ),
				'records_seen'    => count( $groups ),
				'records_updated' => $updated,
				'records_failed'  => $failed,
			]
		);

		return [
			'groups_seen' => count( $groups ),
			'updated'     => $updated,
			'failed'      => $failed,
		];
	}

	/**
	 * Recompute a single universal rollup.
	 *
	 * @param string     $universal_part_id Universal part ID.
	 * @param array|null $members Optional preloaded member rows.
	 * @return bool
	 */
	public function recompute_universal_part( string $universal_part_id, ?array $members = null ): bool {
		$universal_part_id = sanitize_text_field( $universal_part_id );
		if ( '' === $universal_part_id ) {
			return false;
		}

		if ( null === $members ) {
			$groups  = $this->get_projected_universal_groups( $universal_part_id );
			$members = $groups[ $universal_part_id ] ?? [];
		}
		if ( empty( $members ) ) {
			return false;
		}

		$skus      = array_values( array_filter( array_map( static fn( $m ) => (string) ( $m['sku'] ?? '' ), $members ) ) );
		$stock_map = $this->stock_repository->get_by_skus( $skus );

		$total_on_hand   = 0;
		$total_committed = 0;
		$total_available = 0;
		$effective       = 0;
		$active_count    = 0;
		$stocked_count   = 0;
		$breakdown       = [];
		$canonical_name  = '';
		$family          = '';

		foreach ( $members as $member ) {
			$sku        = strtoupper( (string) ( $member['sku'] ?? '' ) );
			$status     = sanitize_key( (string) ( $member['status'] ?? '' ) );
			$confidence = sanitize_key( (string) ( $member['confidence'] ?? '' ) );
			$stock      = $stock_map[ $sku ] ?? null;
			$on_hand    = $stock ? (int) $stock['qty_on_hand'] : 0;
			$committed  = $stock ? (int) $stock['qty_committed'] : 0;
			$available  = $stock ? (int) $stock['qty_available'] : 0;

			$total_on_hand   += $on_hand;
			$total_committed += $committed;
			$total_available += $available;

			$is_effective = 'active' === $status && in_array( $confidence, [ 'verified', 'high' ], true );
			if ( $is_effective ) {
				$active_count++;
				$effective += $available;
			}
			if ( $available > 0 ) {
				$stocked_count++;
			}

			if ( '' === $canonical_name && ! empty( $member['title'] ) ) {
				$canonical_name = (string) $member['title'];
			}
			if ( '' === $family && ! empty( $member['family'] ) ) {
				$family = (string) $member['family'];
			}

			$breakdown[] = [
				'brand'         => (string) ( $member['brand'] ?? '' ),
				'sku'           => $sku,
				'product_id'    => (int) ( $member['product_id'] ?? 0 ),
				'qty_on_hand'   => $on_hand,
				'qty_committed' => $committed,
				'qty_available' => $available,
				'status'        => $status,
				'confidence'    => $confidence,
				'effective'     => $is_effective,
			];
		}

		return $this->rollup_repository->upsert_rollup(
			[
				'universal_part_id'       => $universal_part_id,
				'canonical_name'          => $canonical_name,
				'part_family'             => $family,
				'total_qty_on_hand'       => $total_on_hand,
				'total_qty_committed'     => $total_committed,
				'total_qty_available'     => $total_available,
				'effective_qty_available' => $effective,
				'active_member_count'     => $active_count,
				'stocked_member_count'    => $stocked_count,
				'brand_breakdown'         => $breakdown,
				'reorder_signal'          => $this->compute_reorder_signal( $effective, $active_count ),
			]
		);
	}

	/**
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function get_projected_universal_groups( string $only_universal_part_id = '' ): array {
		$args = [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => DTB_ProductMeta::UNIVERSAL_PART_ID,
					'compare' => 'EXISTS',
				],
			],
		];

		if ( '' !== $only_universal_part_id ) {
			$args['meta_query'][] = [
				'key'     => DTB_ProductMeta::UNIVERSAL_PART_ID,
				'value'   => $only_universal_part_id,
				'compare' => '=',
			];
		}

		$ids    = get_posts( $args );
		$groups = [];
		foreach ( (array) $ids as $product_id ) {
			$product_id = (int) $product_id;
			$product    = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}
			$universal_id = (string) get_post_meta( $product_id, DTB_ProductMeta::UNIVERSAL_PART_ID, true );
			if ( '' === $universal_id ) {
				continue;
			}

			$groups[ $universal_id ][] = [
				'product_id'  => $product_id,
				'sku'         => (string) $product->get_sku(),
				'title'       => (string) $product->get_name(),
				'brand'       => (string) get_post_meta( $product_id, DTB_ProductMeta::BRAND_LABEL, true ),
				'status'      => (string) get_post_meta( $product_id, DTB_ProductMeta::UNIVERSAL_PART_STATUS, true ),
				'confidence'  => (string) get_post_meta( $product_id, DTB_ProductMeta::UNIVERSAL_PART_CONFIDENCE, true ),
				'family'      => (string) get_post_meta( $product_id, DTB_ProductMeta::UNIVERSAL_PART_FAMILY, true ),
			];
		}

		return $groups;
	}

	private function compute_reorder_signal( int $effective_qty_available, int $active_member_count ): string {
		if ( $active_member_count <= 0 ) {
			return 'none';
		}
		if ( $effective_qty_available <= 0 ) {
			return 'critical';
		}
		if ( $effective_qty_available <= 5 ) {
			return 'reorder';
		}
		if ( $effective_qty_available <= 15 ) {
			return 'watch';
		}
		return 'none';
	}
}
