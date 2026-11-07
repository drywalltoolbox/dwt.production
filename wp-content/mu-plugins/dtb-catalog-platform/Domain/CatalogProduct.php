<?php
defined( 'ABSPATH' ) || exit;

/** True when a product title identifies a tube tool rather than an accessory. */
function dtb_catalog_product_name_is_compound_tube_tool( string $name ): bool {
	return 1 === preg_match(
		'/\b(?:compound|mud)[\s_-]*tubes?\b|\bcam[\s_-]*lock[\s_-]*tubes?\b/i',
		$name
	)
		&& 1 !== preg_match( '/\b(?:adapter|adaptor|filler|sleeve|spring|cap|pin|body|repair|replacement)\b/i', $name );
}

/**
 * True when a product is a compound/mud/cam-lock tube tool, regardless of an
 * older imported display-category value.
 */
function dtb_catalog_product_is_compound_tube( array $dto ): bool {
	if ( ! empty( $dto['isParts'] ) ) {
		return false;
	}

	$name = (string) ( $dto['name'] ?? '' );
	if (
		1 === preg_match( '/\b(?:compound|mud)[\s_-]*tubes?\b|\bcam[\s_-]*lock[\s_-]*tubes?\b/i', $name )
		&& ! dtb_catalog_product_name_is_compound_tube_tool( $name )
	) {
		return false;
	}

	$haystack = strtolower( implode( ' ', array_filter( [
		(string) ( $dto['sku'] ?? '' ),
		(string) ( $dto['mpn'] ?? '' ),
		$name,
		(string) ( $dto['slug'] ?? '' ),
		(string) ( $dto['displayCategory']['key'] ?? '' ),
		(string) ( $dto['displayCategory']['label'] ?? '' ),
		(string) ( $dto['category']['label'] ?? '' ),
	] ) ) );

	return 1 === preg_match( '/\b(?:compound|mud)[\s_-]*tubes?\b|\bcam[\s_-]*lock[\s_-]*tubes?\b|\b(?:cmt|clt)(?:\d{2}|bf)\b/', $haystack );
}

/** Normalize legacy display-category DTO keys into the active storefront taxonomy. */
function dtb_catalog_product_normalize_display_category( array $dto ): array {
	$key = (string) ( $dto['displayCategory']['key'] ?? '' );
	$key = str_replace( '-', '_', sanitize_title( $key ) );

	if ( in_array( $key, [ 'semi_automatic_tools', 'semi_automatic_taping_tools' ], true ) ) {
		$dto['displayCategory'] = [
			'key'   => 'semi_automatic_tapers',
			'label' => 'Semi-Automatic Tapers',
			'slug'  => 'semi-automatic-tapers',
		];
	}

	return $dto;
}

/**
 * Ensure catalog product DTO is consistently structured.
 */
function dtb_catalog_product_finalize( array $dto ): array {
	$dto = dtb_catalog_product_normalize_display_category( $dto );

	if ( dtb_catalog_product_is_compound_tube( $dto ) ) {
		$dto['category'] = [
			'key'   => 'automatic_taping_tools',
			'label' => 'Automatic Taping Tools',
			'slug'  => 'automatic-taping-tools',
		];
		$dto['displayCategory'] = [
			'key'   => 'automatic_compound_tubes',
			'label' => 'Compound Tubes',
			'slug'  => 'compound-tubes',
		];
	}

	if ( ! isset( $dto['cardProduct'] ) || ! is_array( $dto['cardProduct'] ) ) {
		$dto['cardProduct'] = [
			'id'             => (int) ( $dto['id'] ?? 0 ),
			'parentId'       => null,
			'sku'            => (string) ( $dto['sku'] ?? '' ),
			'name'           => (string) ( $dto['name'] ?? '' ),
			'price'          => isset( $dto['price']['value'] ) ? (float) $dto['price']['value'] : 0.0,
			'image'          => (string) ( $dto['media']['image'] ?? '' ),
			'stockStatus'    => (string) ( $dto['inventory']['stockStatus'] ?? 'instock' ),
			'variationLabel' => (string) ( $dto['variation']['label'] ?? '' ),
			'addToCartType'  => 'variable' === ( $dto['type'] ?? '' ) ? 'variation' : 'simple',
		];
	}

	return $dto;
}
