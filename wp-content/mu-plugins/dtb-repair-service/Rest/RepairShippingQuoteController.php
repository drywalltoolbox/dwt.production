<?php
/**
 * Rest — RepairShippingQuoteController: POST /wp-json/dtb/v1/repairs/shipping-quote
 *
 * Read-safe, server-authoritative repair return-shipping quote endpoint.
 * It is intentionally independent from the WooCommerce storefront cart/session.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', 'dtb_repair_register_shipping_quote_route' );

function dtb_repair_register_shipping_quote_route(): void {
	register_rest_route(
		'dtb/v1',
		'/repairs/shipping-quote',
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_repair_rest_shipping_quote',
			'permission_callback' => 'dtb_repair_shipping_quote_permission',
		]
	);
}

/**
 * Public by design: this endpoint performs only bounded local policy calculation,
 * exposes no credentials, performs no external calls, and persists no state.
 */
function dtb_repair_shipping_quote_permission(): bool {
	return true;
}

function dtb_repair_rest_shipping_quote( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$payload = $request->get_json_params();
	$payload = is_array( $payload ) ? $payload : [];
	$quote   = dtb_repair_build_shipping_quote( $payload );

	if ( is_wp_error( $quote ) ) {
		return $quote;
	}

	$response = new WP_REST_Response(
		[
			'success'              => true,
			'quote_version'        => $quote['quote_version'],
			'source'               => $quote['source'],
			'currency'             => $quote['currency'],
			'estimated_weight_lbs' => $quote['estimated_weight_lbs'],
			'rates'                => $quote['rates'],
		],
		200
	);
	$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );

	return $response;
}
