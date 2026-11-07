<?php
/**
 * Catalog Platform: Product Placeholder Image Filter.
 *
 * Removes environment-relative no-image placeholder URLs from product/catalog
 * REST payloads so PDP galleries, variation galleries, thumbnails, and product
 * cards never receive environment-relative links like /no-image-placeholder.webp.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_request_after_callbacks', 'dtb_strip_product_placeholder_images_from_rest_response', 999, 3 );

/**
 * Strip placeholder images from product/catalog REST responses.
 *
 * @param mixed           $response REST response or raw data.
 * @param array           $handler  Route handler metadata.
 * @param WP_REST_Request $request  Current REST request.
 * @return mixed
 */
function dtb_strip_product_placeholder_images_from_rest_response( $response, $handler, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
		return $response;
	}

	$route = (string) $request->get_route();
	if ( ! dtb_should_strip_product_placeholder_images_for_route( $route ) ) {
		return $response;
	}

	$rest_response = rest_ensure_response( $response );
	$data          = $rest_response->get_data();
	if ( ! is_array( $data ) ) {
		return $response;
	}

	$rest_response->set_data( dtb_strip_product_placeholder_images_recursive( $data ) );
	return $rest_response;
}

/**
 * Scope stripper to product/catalog routes used by the storefront.
 *
 * @param string $route REST route.
 * @return bool
 */
function dtb_should_strip_product_placeholder_images_for_route( string $route ): bool {
	return str_contains( $route, '/dtb/v1/catalog' )
		|| str_contains( $route, '/drywall/v1/products' )
		|| str_contains( $route, '/wc/store/v1/products' )
		|| str_contains( $route, '/wc/v3/products' );
}

/**
 * Recursively remove placeholder image links and image objects from a payload.
 *
 * @param mixed $value Payload value.
 * @return mixed|null
 */
function dtb_strip_product_placeholder_images_recursive( $value ) {
	if ( is_string( $value ) ) {
		return dtb_is_product_placeholder_image_url( $value ) ? '' : $value;
	}

	if ( ! is_array( $value ) ) {
		return $value;
	}

	// Image object: remove the whole object if its source is the no-image placeholder.
	foreach ( [ 'src', 'url', 'full', 'large' ] as $src_key ) {
		if ( isset( $value[ $src_key ] ) && is_string( $value[ $src_key ] ) && dtb_is_product_placeholder_image_url( $value[ $src_key ] ) ) {
			return null;
		}
	}

	$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
	$out     = [];

	foreach ( $value as $key => $child ) {
		$clean = dtb_strip_product_placeholder_images_recursive( $child );
		if ( null === $clean ) {
			continue;
		}
		if ( $is_list && '' === $clean ) {
			continue;
		}
		$out[ $key ] = $clean;
	}

	return $is_list ? array_values( $out ) : $out;
}

/**
 * Determine whether a URL is a generated no-image placeholder.
 *
 * @param string $url URL or src value.
 * @return bool
 */
function dtb_is_product_placeholder_image_url( string $url ): bool {
	$normalized = strtolower( trim( $url ) );
	if ( '' === $normalized ) {
		return false;
	}

	$path = (string) wp_parse_url( $normalized, PHP_URL_PATH );
	return str_contains( $normalized, 'no-image-placeholder.webp' )
		|| str_contains( $normalized, 'no-image-placeholder' )
		|| str_ends_with( $path, '/placeholder.webp' )
		|| str_contains( $path, '/placeholder-' );
}
