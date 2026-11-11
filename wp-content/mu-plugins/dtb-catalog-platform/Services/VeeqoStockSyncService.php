<?php
/**
 * Veeqo/Woo stock sync service.
 *
 * The first production foundation uses WooCommerce stock as the local fallback
 * projection source. Real Veeqo API/webhook ingestion should write through this
 * service/repository contract without changing downstream rollup logic.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_VeeqoStockSyncService {
	private DTB_InventoryStockRepository $stock_repository;
	private DTB_InventoryRollupRepository $rollup_repository;

	public function __construct() {
		$this->stock_repository  = new DTB_InventoryStockRepository();
		$this->rollup_repository = new DTB_InventoryRollupRepository();
	}

	/**
	 * Sync local stock cache from WooCommerce products.
	 *
	 * @param bool $parts_only Whether to limit to DTB part products.
	 * @return array<string,int|string>
	 */
	public function sync_from_woocommerce( bool $parts_only = true ): array {
		$started_at = current_time( 'mysql', true );
		$start_ms   = microtime( true );
		$updated    = 0;
		$failed     = 0;
		$seen       = 0;

		$args = [
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
		];

		if ( $parts_only ) {
			$args['meta_query'] = [
				[
					'key'     => DTB_ProductMeta::IS_PARTS,
					'value'   => '1',
					'compare' => '=',
				],
			];
		}

		$product_ids = get_posts( $args );
		foreach ( (array) $product_ids as $product_id ) {
			$product_id = (int) $product_id;
			$product    = wc_get_product( $product_id );
			if ( ! $product ) {
				$failed++;
				continue;
			}

			$sku = strtoupper( trim( (string) $product->get_sku() ) );
			if ( '' === $sku ) {
				$failed++;
				continue;
			}

			$seen++;
			$stock_quantity = $product->managing_stock() ? $product->get_stock_quantity() : null;
			$qty_on_hand    = null === $stock_quantity ? ( $product->is_in_stock() ? 1 : 0 ) : (int) $stock_quantity;
			$qty_committed  = 0;
			$qty_available  = max( 0, $qty_on_hand - $qty_committed );

			$ok = $this->stock_repository->upsert_stock(
				[
					'sku'            => $sku,
					'woo_product_id' => $product_id,
					'qty_on_hand'    => $qty_on_hand,
					'qty_committed'  => $qty_committed,
					'qty_available'  => $qty_available,
					'sync_source'    => 'woocommerce',
					'raw_payload'    => [
						'name'           => $product->get_name(),
						'managing_stock' => $product->managing_stock(),
						'stock_status'   => $product->get_stock_status(),
					],
				]
			);

			if ( $ok ) {
				$updated++;
			} else {
				$failed++;
			}
		}

		$duration_ms = (int) round( ( microtime( true ) - $start_ms ) * 1000 );
		$this->rollup_repository->log_sync_run(
			[
				'job_key'         => 'dtb_inventory_stock_projection_sync',
				'status'          => 0 === $failed ? 'completed' : 'completed_with_errors',
				'started_at'      => $started_at,
				'records_seen'    => $seen,
				'records_updated' => $updated,
				'records_failed'  => $failed,
				'error_message'   => '',
			]
		);

		return [
			'seen'        => $seen,
			'updated'     => $updated,
			'failed'      => $failed,
			'duration_ms' => $duration_ms,
			'source'      => 'woocommerce',
		];
	}

	/**
	 * Write a single Veeqo stock row into the local cache.
	 *
	 * @param array<string,mixed> $payload Normalized or raw stock event payload.
	 * @return bool
	 */
	public function ingest_stock_payload( array $payload ): bool {
		$sku = strtoupper( sanitize_text_field( (string) ( $payload['sku'] ?? '' ) ) );
		if ( '' === $sku ) {
			return false;
		}

		$woo_product_id = wc_get_product_id_by_sku( $sku );
		$qty_on_hand    = (int) ( $payload['qty_on_hand'] ?? $payload['stock_level'] ?? 0 );
		$qty_committed  = (int) ( $payload['qty_committed'] ?? $payload['allocated_stock_level'] ?? 0 );
		$qty_available  = isset( $payload['qty_available'] ) ? (int) $payload['qty_available'] : max( 0, $qty_on_hand - $qty_committed );

		$ok = $this->stock_repository->upsert_stock(
			[
				'sku'              => $sku,
				'woo_product_id'   => $woo_product_id > 0 ? $woo_product_id : null,
				'veeqo_product_id' => (string) ( $payload['veeqo_product_id'] ?? $payload['product_id'] ?? '' ),
				'veeqo_variant_id' => (string) ( $payload['veeqo_variant_id'] ?? $payload['sellable_id'] ?? '' ),
				'qty_on_hand'      => $qty_on_hand,
				'qty_committed'    => $qty_committed,
				'qty_available'    => $qty_available,
				'sync_source'      => 'veeqo',
				'raw_payload'      => $payload,
			]
		);

		$this->rollup_repository->log_event(
			[
				'event_type'      => 'stock_payload_ingested',
				'sku'             => $sku,
				'qty_delta'       => null,
				'source'          => 'veeqo',
				'source_event_id' => (string) ( $payload['event_id'] ?? '' ),
				'payload'         => $payload,
			]
		);

		return $ok;
	}
}
