<?php
/**
 * Catalog meta registration and WooCommerce REST response projection.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'dtb_catalog_register_meta', 20 );

/**
 * Register all canonical DTB product meta fields with WordPress.
 *
 * Fields are declared for the product post type and exposed via the WP REST API
 * with typed schemas. Writes remain restricted to users that can edit products.
 */
function dtb_catalog_register_meta(): void {
	foreach ( DTB_ProductMeta::FIELDS as $meta_key => $definition ) {
		$type   = $definition['type'];
		$single = 'array' !== $type;

		$rest_schema = match ( $type ) {
			'boolean' => [ 'type' => 'boolean' ],
			'integer' => [ 'type' => 'integer' ],
			'array'   => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			default   => [ 'type' => 'string' ],
		};

		register_post_meta( 'product', $meta_key, [
			'single'        => $single,
			'type'          => $single ? $type : 'string',
			'description'   => $definition['description'],
			'show_in_rest'  => [ 'schema' => $rest_schema ],
			'auth_callback' => static fn() => current_user_can( 'edit_products' ),
		] );
	}
}

add_filter( 'woocommerce_rest_prepare_product_object', 'dtb_catalog_inject_meta_rest', 10, 2 );
add_filter( 'woocommerce_rest_prepare_product_variation_object', 'dtb_catalog_inject_meta_rest', 10, 2 );

/**
 * Append a dtb_meta object to WC REST product and variation responses.
 *
 * @param WP_REST_Response   $response REST response.
 * @param WC_Product|WC_Data $product  Product-like object.
 * @return WP_REST_Response
 */
function dtb_catalog_inject_meta_rest( WP_REST_Response $response, object $product ): WP_REST_Response {
	$id = method_exists( $product, 'get_id' ) ? $product->get_id() : 0;
	if ( ! $id ) {
		return $response;
	}

	$data = $response->get_data();
	if ( ! isset( $data['dtb_meta'] ) ) {
		$data['dtb_meta'] = dtb_catalog_meta_get_dtb_map( (int) $id );
	}

	$response->set_data( $data );
	return $response;
}
