<?php
/**
 * Inventory Intelligence application service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_InventoryIntelligenceService {
	private DTB_InventoryStockRepository $stock_repository;
	private DTB_InventoryRollupRepository $rollup_repository;

	public function __construct() {
		$this->stock_repository  = new DTB_InventoryStockRepository();
		$this->rollup_repository = new DTB_InventoryRollupRepository();
	}

	public function health(): array {
		$latest_stock_sync  = $this->rollup_repository->latest_sync_run( 'dtb_inventory_stock_projection_sync' );
		$latest_rollup_sync = $this->rollup_repository->latest_sync_run( 'dtb_inventory_rollup_recompute' );
		return [
			'stock_rows'         => $this->stock_repository->count_stock_rows(),
			'rollup_rows'        => $this->rollup_repository->count_rollups(),
			'critical_rollups'    => $this->rollup_repository->count_rollups( 'critical' ),
			'reorder_rollups'     => $this->rollup_repository->count_rollups( 'reorder' ),
			'watch_rollups'       => $this->rollup_repository->count_rollups( 'watch' ),
			'latest_stock_sync'   => $latest_stock_sync,
			'latest_rollup_sync'  => $latest_rollup_sync,
			'generated_at'        => current_time( 'mysql', true ),
		];
	}

	public function list_universal_stock( int $page = 1, string $signal = '', string $search = '' ): array {
		$limit  = 25;
		$page   = max( 1, $page );
		$offset = ( $page - 1 ) * $limit;
		$total  = $this->rollup_repository->count_rollups( $signal, $search );
		$items  = $this->rollup_repository->list_rollups( $limit, $offset, $signal, $search );

		return [
			'items' => $items,
			'total' => $total,
			'pages' => max( 1, (int) ceil( $total / $limit ) ),
			'page'  => $page,
		];
	}

	public function true_stockouts(): array {
		return $this->rollup_repository->list_rollups( 25, 0, 'critical', '' );
	}

	public function substitute_preview( string $sku ): array {
		$sku        = strtoupper( sanitize_text_field( $sku ) );
		$product_id = wc_get_product_id_by_sku( $sku );
		if ( $product_id <= 0 ) {
			return [
				'sku'        => $sku,
				'found'      => false,
				'message'    => 'No WooCommerce product matched this SKU.',
				'substitutes'=> [],
			];
		}

		$universal_id = (string) get_post_meta( $product_id, DTB_ProductMeta::UNIVERSAL_PART_ID, true );
		if ( '' === $universal_id ) {
			return [
				'sku'                 => $sku,
				'product_id'          => $product_id,
				'found'               => true,
				'universal_part_id'   => '',
				'message'             => 'Product has no universal part assignment.',
				'substitutes'         => [],
			];
		}

		$rollup = $this->rollup_repository->get_rollup( $universal_id );
		if ( ! $rollup ) {
			return [
				'sku'               => $sku,
				'product_id'        => $product_id,
				'found'             => true,
				'universal_part_id' => $universal_id,
				'message'           => 'Universal part has no computed inventory rollup yet.',
				'substitutes'       => [],
			];
		}

		$substitutes = [];
		foreach ( (array) ( $rollup['brand_breakdown'] ?? [] ) as $member ) {
			$member_sku = strtoupper( (string) ( $member['sku'] ?? '' ) );
			if ( '' === $member_sku || $member_sku === $sku ) {
				continue;
			}
			if ( empty( $member['effective'] ) || (int) ( $member['qty_available'] ?? 0 ) <= 0 ) {
				continue;
			}

			$substitutes[] = [
				'sku'           => $member_sku,
				'brand'         => (string) ( $member['brand'] ?? '' ),
				'product_id'    => (int) ( $member['product_id'] ?? 0 ),
				'qty_available' => (int) ( $member['qty_available'] ?? 0 ),
				'confidence'    => (string) ( $member['confidence'] ?? '' ),
				'status'        => (string) ( $member['status'] ?? '' ),
				'reason'        => 'Same active universal part with high-or-verified confidence.',
			];
		}

		usort( $substitutes, static fn( array $a, array $b ): int => (int) $b['qty_available'] <=> (int) $a['qty_available'] );

		return [
			'sku'                    => $sku,
			'product_id'             => $product_id,
			'found'                  => true,
			'universal_part_id'      => $universal_id,
			'canonical_name'         => (string) ( $rollup['canonical_name'] ?? '' ),
			'effective_qty_available'=> (int) ( $rollup['effective_qty_available'] ?? 0 ),
			'substitutes'            => $substitutes,
		];
	}
}
