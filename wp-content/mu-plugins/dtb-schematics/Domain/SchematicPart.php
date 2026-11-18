<?php
defined( 'ABSPATH' ) || exit;

/**
 * Normalize linked WooCommerce product IDs for schematic mappings.
 *
 * @param mixed $value Raw product IDs from request/meta.
 * @return int[]
 */
function dtb_schematic_normalize_product_ids( $value ): array {
	if ( is_string( $value ) ) {
		$value = '' === $value ? [] : explode( ',', $value );
	}

	$ids = array_map( 'absint', (array) $value );
	$ids = array_values( array_unique( array_filter( $ids ) ) );

	return $ids;
}
