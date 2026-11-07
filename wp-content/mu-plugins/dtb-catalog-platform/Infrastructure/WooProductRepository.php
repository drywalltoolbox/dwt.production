<?php
defined( 'ABSPATH' ) || exit;

/**
 * Ensure WooCommerce's v3 products REST controller is available for in-process
 * catalog reads.
 *
 * Normal storefront catalog reads must never fall back to a credential-bearing
 * self-HTTP request. WooCommerce is installed in the same WordPress runtime, so
 * DTB loads the official controller directly when the autoloader has not done so
 * yet and fails explicitly if the local Woo runtime is unavailable.
 */
function dtb_catalog_ensure_wc_products_controller(): bool {
	if ( class_exists( 'WC_REST_Products_Controller' ) ) {
		return true;
	}

	if ( defined( 'WC_ABSPATH' ) ) {
		$controller_file = trailingslashit( WC_ABSPATH ) . 'includes/rest-api/Controllers/Version3/class-wc-rest-products-controller.php';
		if ( is_readable( $controller_file ) ) {
			require_once $controller_file;
		}
	}

	return class_exists( 'WC_REST_Products_Controller' );
}

/**
 * Convert values returned by direct Woo controller calls into the same plain
 * array/scalar shapes produced by the REST server serialization boundary.
 *
 * Direct controller invocation can leave WC_Meta_Data value objects inside the
 * response payload. DTB catalog normalizers intentionally consume REST-shaped
 * arrays, so normalize those objects before the payload crosses into the DTB
 * read model.
 *
 * @param mixed $value Raw controller response value.
 * @return mixed
 */
function dtb_catalog_normalize_wc_rest_value( $value ) {
	if ( $value instanceof WC_Meta_Data ) {
		$data = $value->get_data();
		return [
			'id'    => isset( $data['id'] ) ? absint( $data['id'] ) : 0,
			'key'   => isset( $data['key'] ) ? (string) $data['key'] : '',
			'value' => $data['value'] ?? '',
		];
	}

	if ( is_array( $value ) ) {
		$normalized = [];
		foreach ( $value as $key => $entry ) {
			$normalized[ $key ] = dtb_catalog_normalize_wc_rest_value( $entry );
		}
		return $normalized;
	}

	if ( $value instanceof JsonSerializable ) {
		return dtb_catalog_normalize_wc_rest_value( $value->jsonSerialize() );
	}

	return $value;
}

/**
 * Execute a read-only WooCommerce product request in-process.
 *
 * Catalog reads run inside the same WordPress/WooCommerce installation and must
 * not depend on self-HTTP requests or WooCommerce consumer credentials. The
 * official Woo REST products controller remains the schema authority while DTB
 * avoids network latency, credential drift, and recursive HTTP failure modes.
 *
 * @param string              $route  wc/v3/products or wc/v3/products/{id}.
 * @param array<string,mixed> $params Request parameters.
 * @return WP_REST_Response|null
 */
function dtb_catalog_wc_get_response( string $route, array $params = [] ): ?WP_REST_Response {
	$route = trim( $route, '/' );

	if ( ! preg_match( '#^wc/v3/products(?:/(?P<id>\d+))?$#', $route, $matches ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'catalog_route_not_supported', 'Unsupported local catalog read route.', 500 ),
			500
		);
	}

	if ( ! dtb_catalog_ensure_wc_products_controller() ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'catalog_runtime_unavailable', 'WooCommerce product runtime is unavailable.', 503 ),
			503
		);
	}

	try {
		$controller = new WC_REST_Products_Controller();
		$request    = new WP_REST_Request( 'GET', '/' . $route );

		foreach ( $params as $key => $value ) {
			$key = (string) $key;
			if ( in_array( $key, [ 'include', 'exclude' ], true ) && is_string( $value ) ) {
				$value = array_values( array_filter( array_map( 'absint', explode( ',', $value ) ) ) );
			}
			$request->set_param( $key, $value );
		}

		$product_id = ! empty( $matches['id'] ) ? absint( $matches['id'] ) : 0;
		if ( $product_id > 0 ) {
			/* Public catalog routes must never expose drafts/private products by ID. */
			if ( 'publish' !== get_post_status( $product_id ) ) {
				return new WP_REST_Response(
					dtb_error_envelope( 'not_found', 'Product not found.', 404 ),
					404
				);
			}
			$request->set_param( 'id', $product_id );
			$response = $controller->get_item( $request );
		} else {
			/* Direct controller calls bypass REST route argument defaults. */
			if ( ! $request->has_param( 'status' ) ) {
				$request->set_param( 'status', 'publish' );
			}
			if ( ! $request->has_param( 'page' ) ) {
				$request->set_param( 'page', 1 );
			}
			if ( ! $request->has_param( 'order' ) ) {
				$request->set_param( 'order', 'desc' );
			}
			if ( ! $request->has_param( 'orderby' ) ) {
				$request->set_param( 'orderby', 'date' );
			}
			$response = $controller->get_items( $request );
		}
	} catch ( Throwable $error ) {
		if ( class_exists( 'DTB_Logger' ) ) {
			DTB_Logger::error( 'Local Woo catalog runtime failed', [
				'route'     => $route,
				'exception' => get_class( $error ),
				'message'   => sanitize_text_field( $error->getMessage() ),
			] );
		}

		return new WP_REST_Response(
			dtb_error_envelope( 'catalog_runtime_failure', 'The local WooCommerce catalog runtime failed.', 503 ),
			503
		);
	}

	if ( is_wp_error( $response ) ) {
		return rest_convert_error_to_response( $response );
	}
	if ( $response instanceof WP_REST_Response ) {
		$response->set_data( dtb_catalog_normalize_wc_rest_value( $response->get_data() ) );
		return $response;
	}
	if ( $response instanceof WP_HTTP_Response ) {
		return new WP_REST_Response(
			dtb_catalog_normalize_wc_rest_value( $response->get_data() ),
			$response->get_status(),
			$response->get_headers()
		);
	}

	return new WP_REST_Response(
		dtb_error_envelope( 'catalog_runtime_invalid_response', 'Unexpected response from local WooCommerce product runtime.', 502 ),
		502
	);
}

/**
 * Fetch products by IDs in include order.
 *
 * @param int[] $ids
 * @return WP_REST_Response|null
 */
function dtb_catalog_wc_get_products_by_ids_response( array $ids ): ?WP_REST_Response {
	$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
	if ( empty( $ids ) ) {
		return null;
	}

	return dtb_catalog_wc_get_response( 'wc/v3/products', [
		'include'  => $ids,
		'orderby'  => 'include',
		'per_page' => count( $ids ),
		'_fields'  => DTB_PRODUCT_DETAIL_FIELDS,
	] );
}

/**
 * Fetch products by IDs in include order.
 *
 * @param int[] $ids
 * @return array[]
 */
function dtb_catalog_wc_fetch_products_by_ids( array $ids ): array {
	$response = dtb_catalog_wc_get_products_by_ids_response( $ids );

	if ( ! is_object( $response ) || 200 !== $response->get_status() ) {
		return [];
	}

	$data = $response->get_data();
	return is_array( $data ) ? $data : [];
}

/** Fetch a single published product by slug. */
function dtb_catalog_wc_fetch_product_by_slug( string $slug ): ?array {
	$slug = sanitize_title( $slug );
	if ( '' === $slug ) {
		return null;
	}

	$response = dtb_catalog_wc_get_response( 'wc/v3/products', [
		'slug'    => $slug,
		'status'  => 'publish',
		'_fields' => DTB_PRODUCT_DETAIL_FIELDS,
	] );

	if ( ! is_object( $response ) || 200 !== $response->get_status() ) {
		return null;
	}

	$data = $response->get_data();
	if ( ! is_array( $data ) || empty( $data[0] ) || ! is_array( $data[0] ) ) {
		return null;
	}

	return $data[0];
}

/** Fetch a single published product by ID. */
function dtb_catalog_wc_fetch_product_by_id( int $product_id ): ?array {
	$product_id = absint( $product_id );
	if ( $product_id <= 0 ) {
		return null;
	}

	$response = dtb_catalog_wc_get_response( 'wc/v3/products/' . $product_id, [] );
	if ( ! is_object( $response ) || 200 !== $response->get_status() ) {
		return null;
	}

	$data = $response->get_data();
	return is_array( $data ) ? $data : null;
}
