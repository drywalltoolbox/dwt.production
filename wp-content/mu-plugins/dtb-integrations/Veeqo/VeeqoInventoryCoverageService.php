<?php
/**
 * Read-only coverage diagnostics for WooCommerce <-> Veeqo SKU identity.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audit published WooCommerce SKU coverage without making external calls.
 *
 * `_veeqo_mapped_sku` is written only after an exact Veeqo sku_code match.
 * Absence is an exception to investigate, never an instruction to zero stock.
 *
 * @return array<string,mixed>
 */
function dtb_veeqo_inventory_coverage_audit( int $sample_limit = 100 ): array {
	global $wpdb;
	$sample_limit = min( 500, max( 1, $sample_limit ) );
	$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
	$posts_table  = $wpdb->posts;
	$meta_table   = $wpdb->postmeta;

	$total = (int) $wpdb->get_var(
		"SELECT COUNT(*)
		 FROM {$lookup_table} l
		 INNER JOIN {$posts_table} p ON p.ID = l.product_id
		 WHERE l.sku <> ''
		   AND p.post_type IN ('product','product_variation')
		   AND p.post_status = 'publish'"
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static table names/clauses only.

	$mapped = (int) $wpdb->get_var(
		"SELECT COUNT(*)
		 FROM {$lookup_table} l
		 INNER JOIN {$posts_table} p ON p.ID = l.product_id
		 INNER JOIN {$meta_table} m ON m.post_id = l.product_id AND m.meta_key = '_veeqo_mapped_sku' AND m.meta_value = l.sku
		 WHERE l.sku <> ''
		   AND p.post_type IN ('product','product_variation')
		   AND p.post_status = 'publish'"
	); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- static table names/clauses only.

	$missing_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.product_id, l.sku
			 FROM {$lookup_table} l
			 INNER JOIN {$posts_table} p ON p.ID = l.product_id
			 LEFT JOIN {$meta_table} m ON m.post_id = l.product_id AND m.meta_key = '_veeqo_mapped_sku' AND m.meta_value = l.sku
			 WHERE l.sku <> ''
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status = 'publish'
			   AND m.post_id IS NULL
			 ORDER BY l.product_id ASC
			 LIMIT %d",
			$sample_limit
		),
		ARRAY_A
	);

	$duplicate_rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT l.sku, COUNT(*) AS count, GROUP_CONCAT(l.product_id ORDER BY l.product_id ASC) AS product_ids
			 FROM {$lookup_table} l
			 INNER JOIN {$posts_table} p ON p.ID = l.product_id
			 WHERE l.sku <> ''
			   AND p.post_type IN ('product','product_variation')
			   AND p.post_status = 'publish'
			 GROUP BY l.sku
			 HAVING COUNT(*) > 1
			 ORDER BY l.sku ASC
			 LIMIT %d",
			$sample_limit
		),
		ARRAY_A
	);

	return [
		'total_sku_products' => $total,
		'exactly_mapped'     => $mapped,
		'unmapped_count'     => max( 0, $total - $mapped ),
		'coverage_percent'   => $total > 0 ? round( ( $mapped / $total ) * 100, 2 ) : 100.0,
		'unmapped_sample'    => array_map(
			static fn( array $row ): array => [ 'product_id' => absint( $row['product_id'] ?? 0 ), 'sku' => sanitize_text_field( (string) ( $row['sku'] ?? '' ) ) ],
			(array) $missing_rows
		),
		'duplicate_skus'     => array_map(
			static fn( array $row ): array => [
				'sku'         => sanitize_text_field( (string) ( $row['sku'] ?? '' ) ),
				'count'       => absint( $row['count'] ?? 0 ),
				'product_ids' => array_values( array_filter( array_map( 'absint', explode( ',', (string) ( $row['product_ids'] ?? '' ) ) ) ) ),
			],
			(array) $duplicate_rows
		),
	];
}
