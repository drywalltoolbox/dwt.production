<?php
defined( 'ABSPATH' ) || exit;

/**
 * Resolve product IDs mapped to a schematic identifier.
 */
function dtb_schematics_resolve_product_ids_for_schematic( string $schematic_id ): array {
	return dtb_schematics_resolve_product_ids_with_fallback( $schematic_id );
}
