<?php
/**
 * Veeqo inventory service facade.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_VeeqoInventoryService' ) ) {
	return;
}

final class DTB_VeeqoInventoryService {
	/**
	 * Return inventory summary from the legacy-compatible Veeqo runtime when present.
	 *
	 * @return array<string,mixed>|array<int,array<string,mixed>>
	 */
	public static function summary(): array {
		return function_exists( 'dtb_veeqo_get_inventory_summary' ) ? (array) dtb_veeqo_get_inventory_summary() : [];
	}

	/**
	 * Normalize inventory rows by SKU for admin/read-model consumers.
	 *
	 * @param array<int,array<string,mixed>> $rows Raw inventory rows.
	 * @return array<string,array<string,mixed>>
	 */
	public static function index_by_sku( array $rows ): array {
		$indexed = [];
		foreach ( $rows as $row ) {
			$sku = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
			if ( '' === $sku ) {
				continue;
			}
			$indexed[ $sku ] = $row;
		}
		return $indexed;
	}
}

/**
 * Backward-compatible inventory summary wrapper.
 *
 * @return array<string,mixed>|array<int,array<string,mixed>>
 */
function dtb_integrations_veeqo_inventory_summary(): array {
	return DTB_VeeqoInventoryService::summary();
}
