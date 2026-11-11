<?php
defined( 'ABSPATH' ) || exit;

/**
 * Application use case: validate one product.
 *
 * @return array[]
 */
function dtb_catalog_validate_product( int $product_id ): array {
	return DTB_CatalogValidationService::validate_product( $product_id );
}

/**
 * Application use case: validate many products.
 *
 * @param int[] $product_ids
 * @return array[]
 */
function dtb_catalog_validate_products( array $product_ids ): array {
	return DTB_CatalogValidationService::validate_products( $product_ids );
}
