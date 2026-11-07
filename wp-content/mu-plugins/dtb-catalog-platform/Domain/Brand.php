<?php
defined( 'ABSPATH' ) || exit;

/**
 * Build canonical DTB brand identity from raw brand input.
 *
 * @return array{key:string,label:string,slug:string}
 */
function dtb_catalog_brand_make( string $raw_brand ): array {
	return DTB_BrandNormalizer::normalize( $raw_brand );
}

/**
 * Resolve canonical brand label from slug.
 */
function dtb_catalog_brand_label_from_slug( string $slug ): string {
	return DTB_BrandNormalizer::label_from_slug( $slug );
}
