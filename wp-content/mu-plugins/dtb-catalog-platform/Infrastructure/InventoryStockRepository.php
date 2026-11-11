<?php
/**
 * Inventory stock repository.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_InventoryStockRepository {
	public function upsert_stock( array $row ): bool {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$sku = strtoupper( sanitize_text_field( (string) ( $row['sku'] ?? '' ) ) );
		if ( '' === $sku ) {
			return false;
		}

		$now = current_time( 'mysql', true );
		$data = [
			'sku'              => $sku,
			'woo_product_id'   => isset( $row['woo_product_id'] ) ? absint( $row['woo_product_id'] ) : null,
			'veeqo_product_id' => sanitize_text_field( (string) ( $row['veeqo_product_id'] ?? '' ) ),
			'veeqo_variant_id' => sanitize_text_field( (string) ( $row['veeqo_variant_id'] ?? '' ) ),
			'qty_on_hand'      => (int) ( $row['qty_on_hand'] ?? 0 ),
			'qty_committed'    => (int) ( $row['qty_committed'] ?? 0 ),
			'qty_available'    => (int) ( $row['qty_available'] ?? 0 ),
			'last_synced_at'   => $now,
			'sync_source'      => sanitize_key( (string) ( $row['sync_source'] ?? 'manual' ) ),
			'raw_payload'      => isset( $row['raw_payload'] ) ? wp_json_encode( $row['raw_payload'], JSON_UNESCAPED_SLASHES ) : null,
			'updated_at'       => $now,
		];

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . DTB_InventoryIntelligenceSchema::stock_table() . ' WHERE sku = %s LIMIT 1',
				$sku
			)
		);

		if ( $existing ) {
			return false !== $wpdb->update(
				DTB_InventoryIntelligenceSchema::stock_table(),
				$data,
				[ 'id' => (int) $existing ],
				[ '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s' ],
				[ '%d' ]
			);
		}

		$data['created_at'] = $now;
		return false !== $wpdb->insert(
			DTB_InventoryIntelligenceSchema::stock_table(),
			$data,
			[ '%s', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public function get_by_sku( string $sku ): ?array {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . DTB_InventoryIntelligenceSchema::stock_table() . ' WHERE sku = %s LIMIT 1',
				strtoupper( sanitize_text_field( $sku ) )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function get_by_skus( array $skus ): array {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$skus = array_values( array_unique( array_filter( array_map( static fn( $sku ) => strtoupper( sanitize_text_field( (string) $sku ) ), $skus ) ) ) );
		if ( empty( $skus ) ) {
			return [];
		}

		$placeholders = implode( ',', array_fill( 0, count( $skus ), '%s' ) );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . DTB_InventoryIntelligenceSchema::stock_table() . " WHERE sku IN ({$placeholders})",
				$skus
			),
			ARRAY_A
		);

		$indexed = [];
		foreach ( (array) $rows as $row ) {
			$indexed[ (string) $row['sku'] ] = $row;
		}
		return $indexed;
	}

	public function list_stock( int $limit = 50, int $offset = 0, string $search = '' ): array {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$where = '1=1';
		$args  = [];
		if ( '' !== $search ) {
			$where .= ' AND sku LIKE %s';
			$args[] = '%' . $wpdb->esc_like( strtoupper( $search ) ) . '%';
		}

		$args[] = max( 1, $limit );
		$args[] = max( 0, $offset );

		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . DTB_InventoryIntelligenceSchema::stock_table() . " WHERE {$where} ORDER BY qty_available ASC, sku ASC LIMIT %d OFFSET %d",
				$args
			),
			ARRAY_A
		);
	}

	public function count_stock_rows(): int {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DTB_InventoryIntelligenceSchema::stock_table() );
	}
}
