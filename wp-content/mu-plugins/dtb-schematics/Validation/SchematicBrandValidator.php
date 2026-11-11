<?php
defined( 'ABSPATH' ) || exit;

/**
 * Validate brand value against DTB-supported brands.
 */
function dtb_schematic_is_supported_brand( string $brand ): bool {
	return in_array( dtb_schematic_normalize_brand( $brand ), dtb_schematic_supported_brands(), true );
}
