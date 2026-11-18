<?php
/**
 * Catalog Health scan use cases.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run the variable-product catalog health scan.
 *
 * @param int $page     Page number.
 * @param int $per_page Products per page.
 * @return array[]
 */
function dtb_catalog_health_run_scan( int $page, int $per_page ): array {
	$issues = [];

	foreach ( DTB_CatalogHealthRepository::get_variable_product_ids( $page, $per_page ) as $product_id ) {
		$product = wc_get_product( $product_id );
		if ( $product instanceof WC_Product_Variable ) {
			$issues = array_merge( $issues, DTB_CatalogHealthService::inspect_variable_product( $product ) );
		}
	}

	return $issues;
}

/**
 * Run the DTB meta catalog health scan.
 *
 * @param int $page     Page number.
 * @param int $per_page Products per page.
 * @return array[]
 */
function dtb_catalog_health_run_dtb_meta_scan( int $page, int $per_page ): array {
	$product_ids = DTB_CatalogHealthRepository::get_published_product_ids( $page, $per_page );
	return DTB_CatalogHealthService::inspect_dtb_meta( $product_ids );
}
