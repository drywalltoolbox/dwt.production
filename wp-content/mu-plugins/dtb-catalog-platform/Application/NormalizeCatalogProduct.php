<?php
defined( 'ABSPATH' ) || exit;

/**
 * Application use case: normalize WC product payload.
 */
function dtb_catalog_normalize_product( array $wc_product, ?array $parent_wc = null ): array {
	return dtb_catalog_lookup_normalize_product( $wc_product, $parent_wc );
}
