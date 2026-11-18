<?php
defined( 'ABSPATH' ) || exit;

/**
 * Resolve product IDs mapped by exact schematic identifier.
 *
 * @return int[]
 */
function dtb_schematics_resolve_product_ids_for_schematic_exact( string $schematic_id ): array {
	$schematic_id = sanitize_text_field( trim( $schematic_id ) );
	if ( '' === $schematic_id ) {
		return [];
	}

	$ids = get_posts(
		[
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => '_dtb_schematic_id',
					'value'   => $schematic_id,
					'compare' => '=',
				],
			],
		]
	);

	return array_values( array_map( 'intval', (array) $ids ) );
}

/**
 * Fallback: resolve products by model-number-like mappings.
 *
 * @return int[]
 */
function dtb_schematics_resolve_product_ids_for_schematic_model_fallback( string $schematic_id ): array {
	$schematic_id = sanitize_text_field( trim( $schematic_id ) );
	if ( '' === $schematic_id ) {
		return [];
	}

	$ids = get_posts(
		[
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				'relation' => 'OR',
				[
					'key'     => '_dtb_schematic_model_number',
					'value'   => $schematic_id,
					'compare' => '=',
				],
				[
					'key'     => '_dtb_model_number',
					'value'   => $schematic_id,
					'compare' => '=',
				],
			],
		]
	);

	return array_values( array_map( 'intval', (array) $ids ) );
}
