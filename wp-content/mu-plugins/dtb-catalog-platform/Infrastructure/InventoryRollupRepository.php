<?php
/**
 * Inventory rollup and sync-run repository.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_InventoryRollupRepository {
	public function upsert_rollup( array $row ): bool {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$universal_part_id = sanitize_text_field( (string) ( $row['universal_part_id'] ?? '' ) );
		if ( '' === $universal_part_id ) {
			return false;
		}

		$now  = current_time( 'mysql', true );
		$data = [
			'universal_part_id'       => $universal_part_id,
			'canonical_name'          => sanitize_text_field( (string) ( $row['canonical_name'] ?? '' ) ),
			'part_family'             => sanitize_text_field( (string) ( $row['part_family'] ?? '' ) ),
			'total_qty_on_hand'       => (int) ( $row['total_qty_on_hand'] ?? 0 ),
			'total_qty_committed'     => (int) ( $row['total_qty_committed'] ?? 0 ),
			'total_qty_available'     => (int) ( $row['total_qty_available'] ?? 0 ),
			'effective_qty_available' => (int) ( $row['effective_qty_available'] ?? 0 ),
			'active_member_count'     => (int) ( $row['active_member_count'] ?? 0 ),
			'stocked_member_count'    => (int) ( $row['stocked_member_count'] ?? 0 ),
			'brand_breakdown'         => wp_json_encode( $row['brand_breakdown'] ?? [], JSON_UNESCAPED_SLASHES ),
			'reorder_signal'          => sanitize_key( (string) ( $row['reorder_signal'] ?? 'none' ) ),
			'days_of_supply'          => isset( $row['days_of_supply'] ) ? (float) $row['days_of_supply'] : null,
			'last_computed_at'        => $now,
			'updated_at'              => $now,
		];

		$existing = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . DTB_InventoryIntelligenceSchema::rollups_table() . ' WHERE universal_part_id = %s LIMIT 1',
				$universal_part_id
			)
		);

		$formats = [ '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%f', '%s', '%s' ];
		if ( $existing ) {
			return false !== $wpdb->update(
				DTB_InventoryIntelligenceSchema::rollups_table(),
				$data,
				[ 'id' => (int) $existing ],
				$formats,
				[ '%d' ]
			);
		}

		$data['created_at'] = $now;
		$formats[] = '%s';
		return false !== $wpdb->insert( DTB_InventoryIntelligenceSchema::rollups_table(), $data, $formats );
	}

	public function get_rollup( string $universal_part_id ): ?array {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM ' . DTB_InventoryIntelligenceSchema::rollups_table() . ' WHERE universal_part_id = %s LIMIT 1',
				$universal_part_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}
		$row['brand_breakdown'] = json_decode( (string) ( $row['brand_breakdown'] ?? '[]' ), true ) ?: [];
		return $row;
	}

	public function list_rollups( int $limit = 50, int $offset = 0, string $signal = '', string $search = '' ): array {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$where = '1=1';
		$args  = [];
		if ( '' !== $signal ) {
			$where .= ' AND reorder_signal = %s';
			$args[] = sanitize_key( $signal );
		}
		if ( '' !== $search ) {
			$where .= ' AND (universal_part_id LIKE %s OR canonical_name LIKE %s)';
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		$args[] = max( 1, $limit );
		$args[] = max( 0, $offset );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . DTB_InventoryIntelligenceSchema::rollups_table() . " WHERE {$where} ORDER BY FIELD(reorder_signal, 'critical', 'reorder', 'watch', 'none'), effective_qty_available ASC, universal_part_id ASC LIMIT %d OFFSET %d",
				$args
			),
			ARRAY_A
		);

		return array_map(
			static function ( array $row ): array {
				$row['brand_breakdown'] = json_decode( (string) ( $row['brand_breakdown'] ?? '[]' ), true ) ?: [];
				return $row;
			},
			(array) $rows
		);
	}

	public function count_rollups( string $signal = '', string $search = '' ): int {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$where = '1=1';
		$args  = [];
		if ( '' !== $signal ) {
			$where .= ' AND reorder_signal = %s';
			$args[] = sanitize_key( $signal );
		}
		if ( '' !== $search ) {
			$where .= ' AND (universal_part_id LIKE %s OR canonical_name LIKE %s)';
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$args[] = $like;
			$args[] = $like;
		}
		$sql = 'SELECT COUNT(*) FROM ' . DTB_InventoryIntelligenceSchema::rollups_table() . " WHERE {$where}";
		return empty( $args ) ? (int) $wpdb->get_var( $sql ) : (int) $wpdb->get_var( $wpdb->prepare( $sql, $args ) );
	}

	public function log_sync_run( array $run ): int {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$wpdb->insert(
			DTB_InventoryIntelligenceSchema::sync_runs_table(),
			[
				'job_key'         => sanitize_key( (string) ( $run['job_key'] ?? 'inventory_job' ) ),
				'status'          => sanitize_key( (string) ( $run['status'] ?? 'completed' ) ),
				'started_at'      => (string) ( $run['started_at'] ?? current_time( 'mysql', true ) ),
				'finished_at'     => (string) ( $run['finished_at'] ?? current_time( 'mysql', true ) ),
				'duration_ms'     => (int) ( $run['duration_ms'] ?? 0 ),
				'records_seen'    => (int) ( $run['records_seen'] ?? 0 ),
				'records_updated' => (int) ( $run['records_updated'] ?? 0 ),
				'records_failed'  => (int) ( $run['records_failed'] ?? 0 ),
				'error_message'   => sanitize_textarea_field( (string) ( $run['error_message'] ?? '' ) ),
				'created_at'      => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}

	public function latest_sync_run( string $job_key = '' ): ?array {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		if ( '' !== $job_key ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					'SELECT * FROM ' . DTB_InventoryIntelligenceSchema::sync_runs_table() . ' WHERE job_key = %s ORDER BY id DESC LIMIT 1',
					$job_key
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row( 'SELECT * FROM ' . DTB_InventoryIntelligenceSchema::sync_runs_table() . ' ORDER BY id DESC LIMIT 1', ARRAY_A );
		}
		return is_array( $row ) ? $row : null;
	}

	public function log_event( array $event ): int {
		global $wpdb;
		DTB_InventoryIntelligenceSchema::maybe_install();

		$wpdb->insert(
			DTB_InventoryIntelligenceSchema::events_table(),
			[
				'event_type'        => sanitize_key( (string) ( $event['event_type'] ?? 'inventory_event' ) ),
				'sku'               => strtoupper( sanitize_text_field( (string) ( $event['sku'] ?? '' ) ) ),
				'universal_part_id' => sanitize_text_field( (string) ( $event['universal_part_id'] ?? '' ) ),
				'qty_delta'         => isset( $event['qty_delta'] ) ? (int) $event['qty_delta'] : null,
				'source'            => sanitize_key( (string) ( $event['source'] ?? 'system' ) ),
				'source_event_id'   => sanitize_text_field( (string) ( $event['source_event_id'] ?? '' ) ),
				'payload'           => isset( $event['payload'] ) ? wp_json_encode( $event['payload'], JSON_UNESCAPED_SLASHES ) : null,
				'created_at'        => current_time( 'mysql', true ),
			],
			[ '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
		return (int) $wpdb->insert_id;
	}
}
