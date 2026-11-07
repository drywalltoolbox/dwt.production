<?php
defined( 'ABSPATH' ) || exit;

/**
 * Build a variation matrix payload from normalized variations.
 */
function dtb_catalog_build_variation_matrix( array $variations ): ?array {
	if ( empty( $variations ) ) {
		return null;
	}

	$axis = '';
	foreach ( $variations as $variation ) {
		$candidate_axis = (string) ( $variation['variation']['axis'] ?? '' );
		if ( '' !== $candidate_axis ) {
			$axis = $candidate_axis;
			break;
		}
	}

	$options = [];
	foreach ( $variations as $variation ) {
		$value = (string) ( $variation['variation']['value'] ?? '' );
		if ( '' === $value ) {
			continue;
		}

		$options[] = [
			'value'       => $value,
			'label'       => (string) ( $variation['variation']['label'] ?? $value ),
			'variationId' => (int) ( $variation['id'] ?? 0 ),
			'sku'         => (string) ( $variation['sku'] ?? '' ),
			'price'       => isset( $variation['price']['value'] ) ? (float) $variation['price']['value'] : null,
			'stockStatus' => (string) ( $variation['inventory']['stockStatus'] ?? 'instock' ),
			'purchasable' => (bool) ( $variation['inventory']['purchasable'] ?? true ),
		];
	}

	return [
		'axis'    => $axis,
		'options' => $options,
	];
}
