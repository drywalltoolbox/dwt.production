<?php
defined( 'ABSPATH' ) || exit;

/**
 * Application use case: resolve default variation.
 */
function dtb_catalog_resolve_default_variation( array $product_dto, array $variations ): ?array {
	return DTB_DefaultVariationResolver::resolve( $product_dto, $variations );
}

/**
 * Application use case: apply default variation card context.
 */
function dtb_catalog_apply_default_variation_to_card( array $product_dto, ?array $default_variation ): array {
	return DTB_DefaultVariationResolver::apply_to_card( $product_dto, $default_variation );
}
