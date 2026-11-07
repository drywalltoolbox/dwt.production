<?php
defined( 'ABSPATH' ) || exit;

/**
 * Application use case: build/get scoped facets.
 */
function dtb_catalog_build_facets( array $scope = [] ): array {
	return DTB_CatalogFacetService::get( $scope );
}
