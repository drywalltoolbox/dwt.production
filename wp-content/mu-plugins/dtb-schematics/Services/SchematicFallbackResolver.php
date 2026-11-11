<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fallback resolver for schematic -> product mappings.
 *
 * Current behavior preserves existing production semantics:
 * exact _dtb_schematic_id mapping is authoritative, fallback is optional.
 */
function dtb_schematics_resolve_product_ids_with_fallback( string $schematic_id ): array {
	$product_ids = dtb_schematics_resolve_product_ids_for_schematic_exact( $schematic_id );
	if ( ! empty( $product_ids ) ) {
		return $product_ids;
	}

	return dtb_schematics_resolve_product_ids_for_schematic_model_fallback( $schematic_id );
}
