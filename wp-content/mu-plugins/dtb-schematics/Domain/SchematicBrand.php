<?php
defined( 'ABSPATH' ) || exit;

/**
 * Return canonical DTB schematic brands.
 *
 * @return string[]
 */
function dtb_schematic_supported_brands(): array {
	if ( defined( 'DTB_BRANDS' ) && is_array( DTB_BRANDS ) ) {
		$brands   = array_values( array_map( 'strval', DTB_BRANDS ) );
		$brands[] = 'Asgard';
		return array_values( array_unique( $brands ) );
	}

	return [
		'Asgard',
		'Columbia Tools',
		'Platinum Drywall Tools',
		'SurPro',
		'TapeTech',
	];
}

/**
 * Normalize and canonicalize a schematic brand.
 */
function dtb_schematic_normalize_brand( string $brand ): string {
	$brand = sanitize_text_field( $brand );
	if ( '' === $brand ) {
		return '';
	}

	foreach ( dtb_schematic_supported_brands() as $supported_brand ) {
		if ( 0 === strcasecmp( $brand, $supported_brand ) ) {
			return $supported_brand;
		}
	}

	return $brand;
}
