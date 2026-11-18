<?php
defined( 'ABSPATH' ) || exit;

/**
 * Plugin Name: DTB Veeqo Integration
 * Description: Server-side Veeqo API proxy, WooCommerce bi-directional order/inventory
 *              sync, real-time shipping rate calculation, webhook receiver, and
 *              structured logging for production monitoring.
 * Version: 1.0.0
 * Author: Drywall Toolbox
 *
 * Must-use plugin: wp/wp-content/mu-plugins/dtb-veeqo.php
 * Loaded by: 00-dtb-loader.php (after dtb-woocommerce.php)
 *
 * Required wp-config.php constants:
 *   DTB_VEEQO_API_KEY        — Veeqo API key (Settings → API Keys in Veeqo)
 *   DTB_VEEQO_WEBHOOK_SECRET — HMAC secret for Veeqo webhook HMAC validation
 *   DTB_VEEQO_WAREHOUSE_ID   — Primary warehouse ID for order routing
 *   DTB_VEEQO_CHANNEL_ID     — Veeqo Direct/Phone channel ID for API-created orders
 *   DTB_VEEQO_DELIVERY_METHOD_ID — Veeqo delivery method ID for API-created orders
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// SECTION 1 — VEEQO API HELPER
//
// All requests to the Veeqo API originate here, server-side only.
// The API key never travels to the browser.
// =============================================================================

define( 'DTB_VEEQO_API_BASE', 'https://api.veeqo.com' );

/**
 * Return the Veeqo configuration array.
 *
 * Resolution order (highest → lowest priority):
 *   1. wp-config.php constants  (DTB_VEEQO_API_KEY, DTB_VEEQO_WEBHOOK_SECRET,
 *                                DTB_VEEQO_WAREHOUSE_ID, DTB_VEEQO_CHANNEL_ID,
 *                                DTB_VEEQO_DELIVERY_METHOD_ID)
 *   2. WordPress option         (woocommerce_dtb_veeqo_settings — written by the
 *                                WC admin settings page and auto-discovery)
 *
 * Result is cached in $GLOBALS for the lifetime of the current request.
 * Call `unset( $GLOBALS['_dtb_veeqo_config'] )` to force a fresh read
 * (e.g. after updating settings in the same request).
 *
 * @return array{api_key: string, webhook_secret: string, warehouse_id: int, channel_id: int, delivery_method_id: int}
 */
function dtb_veeqo_config(): array {
	if ( isset( $GLOBALS['_dtb_veeqo_config'] ) ) {
		return $GLOBALS['_dtb_veeqo_config'];
	}

	// Stored settings (WC admin settings page writes to this option).
	$stored = (array) get_option( 'woocommerce_dtb_veeqo_settings', [] );

	$GLOBALS['_dtb_veeqo_config'] = [
		'api_key' => ( defined( 'DTB_VEEQO_API_KEY' ) && '' !== (string) DTB_VEEQO_API_KEY )
			? (string) DTB_VEEQO_API_KEY
			: (string) ( $stored['api_key'] ?? '' ),

		'webhook_secret' => ( defined( 'DTB_VEEQO_WEBHOOK_SECRET' ) && '' !== (string) DTB_VEEQO_WEBHOOK_SECRET )
			? (string) DTB_VEEQO_WEBHOOK_SECRET
			: (string) ( $stored['webhook_secret'] ?? '' ),

		// warehouse_id: constant takes precedence only when it is a positive integer.
		'warehouse_id' => ( defined( 'DTB_VEEQO_WAREHOUSE_ID' ) && (int) DTB_VEEQO_WAREHOUSE_ID > 0 )
			? (int) DTB_VEEQO_WAREHOUSE_ID
			: (int) ( $stored['warehouse_id'] ?? 0 ),

		// channel_id: constant takes precedence only when it is a positive integer.
		'channel_id' => ( defined( 'DTB_VEEQO_CHANNEL_ID' ) && (int) DTB_VEEQO_CHANNEL_ID > 0 )
			? (int) DTB_VEEQO_CHANNEL_ID
			: (int) ( $stored['channel_id'] ?? 0 ),

		// delivery_method_id: optional, but Veeqo documents it as required for order creation.
		'delivery_method_id' => ( defined( 'DTB_VEEQO_DELIVERY_METHOD_ID' ) && (int) DTB_VEEQO_DELIVERY_METHOD_ID > 0 )
			? (int) DTB_VEEQO_DELIVERY_METHOD_ID
			: (int) ( $stored['delivery_method_id'] ?? 0 ),
	];

	return $GLOBALS['_dtb_veeqo_config'];
}

/**
 * Return true when Veeqo integration is configured (API key present).
 */
function dtb_veeqo_enabled(): bool {
	if ( defined( 'DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED' ) && DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED ) {
		return false;
	}
	$cfg = dtb_veeqo_config();
	return '' !== $cfg['api_key'];
}

/**
 * Make an authenticated HTTP request to the Veeqo REST API.
 *
 * @param string $method   HTTP method: GET, POST, PUT, DELETE.
 * @param string $path     API path, e.g. '/orders' or '/products/123'.
 * @param array  $params   Query params for GET requests.
 * @param array  $body     Request body for POST/PUT (will be JSON-encoded).
 * @return array{ok: bool, status: int, data: mixed, error: string}
 */
function dtb_veeqo_request( string $method, string $path, array $params = [], array $body = [] ): array {
	if ( defined( 'DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED' ) && DTB_LAUNCH_EXTERNAL_INTEGRATIONS_DISABLED ) {
		return [ 'ok' => false, 'status' => 0, 'data' => null, 'error' => 'Veeqo integration is paused for launch.' ];
	}
	$cfg = dtb_veeqo_config();

	if ( '' === $cfg['api_key'] ) {
		dtb_veeqo_log( 'error', 'api_key_missing', 'Veeqo API key not configured (set DTB_VEEQO_API_KEY in wp-config.php or enter it under WooCommerce → Settings → Integrations → Drywall Toolbox Veeqo).' );
		return [ 'ok' => false, 'status' => 503, 'data' => null, 'error' => 'Veeqo not configured.' ];
	}

	$url = DTB_VEEQO_API_BASE . $path;
	if ( ! empty( $params ) ) {
		// add_query_arg already URL-encodes values — pre-encoding would cause
		// double-encoding (e.g. a space becomes %2520 instead of %20).
		$url = add_query_arg( $params, $url );
	}

	$args = [
		'method'  => strtoupper( $method ),
		'headers' => [
			'x-api-key'    => $cfg['api_key'],
			'Accept'       => 'application/json',
			'Content-Type' => 'application/json',
		],
		'timeout' => 20,
	];

	if ( ! empty( $body ) ) {
		$args['body'] = wp_json_encode( $body );
	}
	if ( 'POST' === strtoupper( $method ) && '/orders' === $path && ! empty( $body['order']['channel_order_number'] ) ) {
		$args['headers']['Idempotency-Key'] = sanitize_text_field( (string) $body['order']['channel_order_number'] );
	}

	$raw = wp_remote_request( $url, $args );

	if ( is_wp_error( $raw ) ) {
		dtb_veeqo_log( 'error', 'http_error', $raw->get_error_message(), [ 'path' => $path ] );
		return [ 'ok' => false, 'status' => 502, 'data' => null, 'error' => $raw->get_error_message() ];
	}

	$status = (int) wp_remote_retrieve_response_code( $raw );
	$data   = json_decode( wp_remote_retrieve_body( $raw ), true );

	if ( $status < 200 || $status >= 300 ) {
		$msg = 'Veeqo API error.';
		if ( is_array( $data ) ) {
			if ( ! empty( $data['error_messages'] ) ) {
				$msg = is_array( $data['error_messages'] )
					? implode( ' ', array_map( 'sanitize_text_field', $data['error_messages'] ) )
					: (string) $data['error_messages'];
			} elseif ( ! empty( $data['errors'] ) ) {
				$msg = is_array( $data['errors'] )
					? wp_json_encode( $data['errors'] )
					: (string) $data['errors'];
			} elseif ( ! empty( $data['error'] ) ) {
				$msg = (string) $data['error'];
			}
		}
		$msg = sanitize_text_field( $msg );
		dtb_veeqo_log( 'error', 'api_error', $msg, [ 'path' => $path, 'status' => $status, 'response' => $data ] );
		return [ 'ok' => false, 'status' => $status, 'data' => $data, 'error' => $msg ];
	}

	return [ 'ok' => true, 'status' => $status, 'data' => $data, 'error' => '' ];
}


// =============================================================================
// SECTION 2 — REST ROUTE REGISTRATION
// =============================================================================

add_action( 'rest_api_init', 'dtb_veeqo_register_routes', 10 );

function dtb_veeqo_register_routes(): void {
	$ns = 'dtb/v1';

	// ── GET /dtb/v1/veeqo/status — integration health check ──────────────────
	register_rest_route( $ns, '/veeqo/status', [
		'methods'             => 'GET',
		'callback'            => 'dtb_veeqo_route_status',
		'permission_callback' => 'dtb_jwt_permission',
	] );

	// ── POST /dtb/v1/veeqo/shipping-rates — real-time rate calculator ─────────
	register_rest_route( $ns, '/veeqo/shipping-rates', [
		'methods'             => 'POST',
		'callback'            => 'dtb_veeqo_route_shipping_rates',
		'permission_callback' => '__return_true',
	] );

	// ── GET /dtb/v1/veeqo/inventory — bulk inventory levels ──────────────────
	// Public: read-only stock levels. The checkout flow calls this without a
	// JWT to show availability before order submission; WooCommerce still
	// enforces stock limits server-side on order creation.
	register_rest_route( $ns, '/veeqo/inventory', [
		'methods'             => 'GET',
		'callback'            => 'dtb_veeqo_route_inventory',
		'permission_callback' => '__return_true',
	] );

	// ── POST /dtb/v1/repair-request — repair service form submission ──────────
	register_rest_route( $ns, '/repair-request', [
		'methods'             => 'POST',
		'callback'            => 'dtb_veeqo_route_repair_request',
		'permission_callback' => '__return_true',
	] );

	// ── POST /dtb/v1/veeqo/sync-order/{order_id} — admin manual order re-sync ─
	// Force a fresh Veeqo order creation / status push for a specific WC order.
	// Useful when a sync failed silently or was skipped before Veeqo was configured.
	register_rest_route( $ns, '/veeqo/sync-order/(?P<order_id>[\d]+)', [
		'methods'             => 'POST',
		'callback'            => 'dtb_veeqo_route_admin_sync_order',
		'permission_callback' => static function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
		'args' => [
			'order_id' => [
				'required'          => true,
				'type'              => 'integer',
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			],
		],
	] );

	// ── POST /dtb/v1/veeqo/inventory/pull — admin manual inventory pull ───────
	// Pull current Veeqo stock levels into WooCommerce product stock quantities.
	register_rest_route( $ns, '/veeqo/inventory/pull', [
		'methods'             => 'POST',
		'callback'            => 'dtb_veeqo_route_admin_inventory_pull',
		'permission_callback' => static function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
	] );

	// ── DELETE /dtb/v1/veeqo/webhooks/ensure — force webhook re-registration ──
	// Clears the cached webhook-registered transient so the next request will
	// verify and re-register the Veeqo webhook. Safe to call after URL changes.
	register_rest_route( $ns, '/veeqo/webhooks/ensure', [
		'methods'             => 'DELETE',
		'callback'            => 'dtb_veeqo_route_admin_reset_webhook',
		'permission_callback' => static function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
	] );

	// ── POST /dtb/v1/veeqo/map-skus — bulk map all WC product SKUs to Veeqo sellable IDs ──
	// Paginates through all WC products/variations, calls GET /products?query={sku}
	// for each unmatched SKU, and writes _veeqo_sellable_id + _veeqo_mapped_sku
	// meta. Safe to call multiple times (skips already-mapped SKUs).
	register_rest_route( $ns, '/veeqo/map-skus', [
		'methods'             => 'POST',
		'callback'            => 'dtb_veeqo_route_admin_map_skus',
		'permission_callback' => static function (): bool {
			return current_user_can( 'manage_woocommerce' );
		},
	] );
}


// =============================================================================
// SECTION 3 — ROUTE CALLBACKS
// =============================================================================

/**
 * GET /dtb/v1/veeqo/status
 *
 * Returns Veeqo connection status and account information.
 * Requires a valid JWT (admin-only).
 */
function dtb_veeqo_route_status( WP_REST_Request $request ): WP_REST_Response {
	if ( ! dtb_veeqo_enabled() ) {
		return new WP_REST_Response( [
			'connected' => false,
			'message'   => 'DTB_VEEQO_API_KEY not configured.',
		], 200 );
	}

	$result = dtb_veeqo_request( 'GET', '/warehouses' );

	if ( ! $result['ok'] ) {
		return new WP_REST_Response( [
			'connected' => false,
			'message'   => $result['error'],
		], 200 );
	}

	$cfg        = dtb_veeqo_config();
	$warehouses = is_array( $result['data'] ) ? count( $result['data'] ) : 0;

	return new WP_REST_Response( [
		'connected'          => true,
		'warehouse_id'       => $cfg['warehouse_id'],
		'channel_id'         => $cfg['channel_id'],
		'delivery_method_id' => $cfg['delivery_method_id'],
		'warehouses'         => $warehouses,
		'message'            => 'Veeqo connection verified.',
	], 200 );
}

/**
 * POST /dtb/v1/veeqo/shipping-rates
 *
 * Compatibility endpoint for the server-authoritative WooCommerce shipping
 * policy. It reads the current authenticated/session cart; request item
 * prices, weights, categories, and totals are ignored. This endpoint does not
 * call Veeqo and does not provide live carrier quotes.
 *
 * Request body:
 * {
 *   "destination": {
 *     "first_name": "...", "last_name": "...",
 *     "address1":   "...", "city": "...",
 *     "state":      "...", "zip": "...",
 *     "country":    "US"
 *   },
 *   "items": []
 * }
 *
 * Response:
 * {
 *   "rates": [
 *     { "id": "standard", "name": "Standard Shipping (5–7 days)", "price": 9.99,  "currency": "USD" },
 *     { "id": "express",  "name": "Express Shipping (2–3 days)",  "price": 19.99, "currency": "USD" },
 *     { "id": "overnight","name": "Overnight Shipping (next day)","price": 39.99, "currency": "USD" }
 *   ]
 * }
 */
function dtb_veeqo_route_shipping_rates( WP_REST_Request $request ): WP_REST_Response {
	$body  = $request->get_json_params();
	$rates = class_exists( 'DTB_CheckoutValidator' )
		? DTB_CheckoutValidator::shipping_rates_for_current_cart( is_array( $body['destination'] ?? null ) ? $body['destination'] : [] )
		: new WP_Error( 'dtb_checkout_validator_unavailable', 'Authoritative checkout shipping policy is unavailable.', [ 'status' => 503 ] );
	if ( is_wp_error( $rates ) ) {
		$status = (int) ( $rates->get_error_data()['status'] ?? 422 );
		return new WP_REST_Response( dtb_error_envelope( $rates->get_error_code(), $rates->get_error_message(), $status ), $status );
	}
	return new WP_REST_Response( [ 'rates' => $rates, 'source' => 'woocommerce-cart' ], 200 );
}

/**
* GET /dtb/v1/veeqo/inventory
 *
 * Returns Veeqo inventory levels for all (or filtered) products.
 * Requires a valid JWT.
 *
 * Query params:
 *   page     (int, default 1)
 *   per_page (int, default 100, max 100)
 */
function dtb_veeqo_route_inventory( WP_REST_Request $request ): WP_REST_Response {
	if ( ! dtb_veeqo_enabled() ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'not_configured', 'Veeqo integration is not configured.', 503 ),
			503
		);
	}

	$page     = max( 1, (int) ( $request->get_param( 'page' )     ?? 1 ) );
	$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 100 ) ) );

	// Veeqo pagination parameters are page (1-indexed) and page_size (max 100).
	$result = dtb_veeqo_request( 'GET', '/products', [
		'page'      => (string) $page,
		'page_size' => (string) $per_page,
	] );

	if ( ! $result['ok'] ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'veeqo_error', $result['error'], $result['status'] ),
			(int) $result['status']
		);
	}

	// Extract inventory summary per product.
	$inventory = [];
	$products  = is_array( $result['data'] ) ? $result['data'] : [];

	foreach ( $products as $product ) {
		$product_id    = $product['id'] ?? null;
		$title         = $product['title'] ?? '';
		$sku_variants  = $product['sellables'] ?? [];

		foreach ( $sku_variants as $sellable ) {
			$available = 0;
			$stock     = $sellable['stock_entries'] ?? [];
			foreach ( $stock as $entry ) {
				$available += (int) ( $entry['available_stock'] ?? 0 );
			}

			$inventory[] = [
				'product_id'  => $product_id,
				'product'     => $title,
				'sku'         => $sellable['sku_code'] ?? '',
				'sellable_id' => $sellable['id'] ?? null,
				'available'   => $available,
			];
		}
	}

	return new WP_REST_Response( [ 'inventory' => $inventory ], 200 );
}


// =============================================================================
// SECTION 3b — ADMIN-ONLY ROUTE CALLBACKS
// =============================================================================

/**
 * POST /dtb/v1/veeqo/sync-order/{order_id}
 *
 * Queue a canonical Veeqo retry for the given WooCommerce order.
 *
 * The existing external ID is deliberately retained. The queued integration
 * contract decides whether the provider object is created or reconciled.
 *
 * Requires: manage_woocommerce capability (admin/shop manager only).
 */
function dtb_veeqo_route_admin_sync_order( WP_REST_Request $request ): WP_REST_Response {
	$order_id = absint( $request->get_param( 'order_id' ) );
	$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

	if ( ! $order instanceof WC_Order ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'order_not_found', sprintf( 'WooCommerce order #%d not found.', $order_id ), 404 ),
			404
		);
	}

	if ( ! dtb_veeqo_enabled() ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'not_configured', 'Veeqo is not configured. Set DTB_VEEQO_API_KEY in wp-config.php.', 503 ),
			503
		);
	}

	if ( ! function_exists( 'dtb_order_enqueue_job' ) ) {
		return new WP_REST_Response( dtb_error_envelope( 'queue_unavailable', 'The canonical order queue is unavailable.', 503 ), 503 );
	}

	$reason = sanitize_text_field( (string) ( $request->get_param( 'reason' ) ?: 'admin_retry' ) );
	$job_id = dtb_order_enqueue_job( 'dtb_order_sync_veeqo', $order_id, [
		'trigger'      => 'admin_retry',
		'retry_reason' => substr( $reason, 0, 160 ),
		'operator_id'  => get_current_user_id(),
	] );
	if ( false === $job_id ) {
		return new WP_REST_Response( dtb_error_envelope( 'queue_failed', 'The Veeqo retry could not be queued.', 503 ), 503 );
	}

	$existing_id = absint( $order->get_meta( '_dtb_veeqo_order_id', true ) ?: $order->get_meta( '_veeqo_order_id', true ) );
	if ( function_exists( 'dtb_order_update_integration_state' ) ) {
		dtb_order_update_integration_state( $order_id, 'veeqo', [
			'status'       => 'queued',
			'order_id'     => $existing_id ?: null,
			'retry_reason' => substr( $reason, 0, 160 ),
			'operator_id'  => get_current_user_id(),
		] );
	}
	if ( function_exists( 'dtb_order_append_event' ) ) {
		dtb_order_append_event( $order_id, 'integration.veeqo.retry_queued', [
			'source'          => 'wp_admin',
			'actor_type'      => 'admin',
			'actor_id'        => get_current_user_id(),
			'visibility'      => 'operator',
			'idempotency_key' => 'veeqo-admin-retry:' . $order_id . ':' . get_current_user_id() . ':' . gmdate( 'Y-m-d-H-i' ),
			'payload'         => [ 'retry_reason' => substr( $reason, 0, 160 ), 'existing_veeqo_order_id' => $existing_id ?: null ],
		] );
	}

	return new WP_REST_Response( [
		'success'        => true,
		'status'         => 'queued',
		'job_id'         => $job_id,
		'veeqo_order_id' => $existing_id ?: null,
		'message'        => sprintf( 'Veeqo retry queued for order #%d.', $order_id ),
	], 202 );
}

/**
 * POST /dtb/v1/veeqo/inventory/pull
 *
 * Fetch current Veeqo stock levels and write them to WooCommerce product stock
 * quantities. Also fires the dtb_veeqo_inventory_sync scheduled event immediately
 * for on-demand reconciliation.
 *
 * Query params:
 *   page     (int, default 1)
 *   per_page (int, default 100, max 100)
 *
 * Requires: manage_woocommerce capability.
 */
function dtb_veeqo_route_admin_inventory_pull( WP_REST_Request $request ): WP_REST_Response {
	if ( ! dtb_veeqo_enabled() ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'not_configured', 'Veeqo is not configured.', 503 ),
			503
		);
	}

	$page     = max( 1, (int) ( $request->get_param( 'page' )     ?? 1 ) );
	$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?? 100 ) ) );

	$updated = dtb_veeqo_pull_inventory_into_wc( $page, $per_page );

	if ( null === $updated ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'veeqo_error', 'Failed to fetch inventory from Veeqo. Check the Veeqo log.', 502 ),
			502
		);
	}

	dtb_veeqo_log( 'info', 'admin_inventory_pull', 'Admin triggered Veeqo inventory pull.', [
		'page'         => $page,
		'per_page'     => $per_page,
		'updated_skus' => $updated,
		'admin_user'   => get_current_user_id(),
	] );

	return new WP_REST_Response( [
		'success'      => true,
		'updated_skus' => $updated,
		'message'      => sprintf( 'Veeqo inventory pull complete. %d WooCommerce product(s) updated.', $updated ),
	], 200 );
}

/**
 * DELETE /dtb/v1/veeqo/webhooks/ensure
 *
 * Clears the webhook-registered transient so the next request will re-verify
 * and re-register the Veeqo webhook endpoint. Use after:
 *   • changing the site URL / permalink structure
 *   • changing DTB_VEEQO_API_KEY
 *   • suspecting the Veeqo webhook was deleted in the Veeqo UI
 *
 * Requires: manage_woocommerce capability.
 */
function dtb_veeqo_route_admin_reset_webhook( WP_REST_Request $request ): WP_REST_Response {
	delete_transient( 'dtb_veeqo_webhook_registered' );

	if ( dtb_veeqo_enabled() ) {
		// Immediately attempt re-registration.
		dtb_veeqo_ensure_webhooks();
	}

	$webhook_url = rest_url( 'dtb/v1/veeqo/webhooks/order' );

	dtb_veeqo_log( 'info', 'admin_webhook_reset', 'Admin reset Veeqo webhook registration.', [
		'webhook_url'  => $webhook_url,
		'admin_user'   => get_current_user_id(),
	] );

	return new WP_REST_Response( [
		'success'     => true,
		'webhook_url' => $webhook_url,
		'message'     => 'Veeqo webhook re-registration triggered. Check WooCommerce → Status → Logs → veeqo-wc-integration for results.',
	], 200 );
}



/**
 * Resolve a Veeqo sellable ID for a Woo product SKU and cache it on the product.
 *
 * Veeqo order creation requires the variant/sellable ID for every line item.
 * Product IDs and null values commonly surface as a 404 "Not found" response.
 *
 * @param string     $sku     WooCommerce SKU.
 * @param WC_Product $product Woo product or variation.
 * @return int
 */
function dtb_veeqo_resolve_sellable_id_for_sku( string $sku, WC_Product $product ): int {
	$sku = trim( $sku );
	if ( '' === $sku || ! dtb_veeqo_enabled() ) {
		return 0;
	}

	$cached_sku     = (string) $product->get_meta( '_veeqo_mapped_sku', true );
	$cached_mapping = absint( $product->get_meta( '_veeqo_sellable_id', true ) );
	if ( $cached_mapping > 0 && $cached_sku === $sku ) {
		return $cached_mapping;
	}

	$transient_key = 'dtb_veeqo_sellable_' . md5( $sku );
	$transient_id  = absint( get_transient( $transient_key ) );
	if ( $transient_id > 0 ) {
		$product->update_meta_data( '_veeqo_sellable_id', $transient_id );
		$product->update_meta_data( '_veeqo_mapped_sku', $sku );
		$product->save_meta_data();
		return $transient_id;
	}

	$result = dtb_veeqo_request( 'GET', '/products', [ 'query' => $sku ] );
	if ( ! $result['ok'] || empty( $result['data'] ) ) {
		dtb_veeqo_log( 'warn', 'sellable_lookup_failed', 'Could not resolve Veeqo sellable ID for SKU during order sync.', [
			'product_id' => $product->get_id(),
			'sku'        => $sku,
			'status'     => $result['status'] ?? 0,
			'error'      => $result['error'] ?? '',
		] );
		return 0;
	}

	foreach ( (array) $result['data'] as $veeqo_product ) {
		foreach ( (array) ( $veeqo_product['sellables'] ?? [] ) as $sellable ) {
			if ( isset( $sellable['sku_code'], $sellable['id'] ) && $sellable['sku_code'] === $sku ) {
				$sellable_id = absint( $sellable['id'] );
				if ( $sellable_id > 0 ) {
					$product->update_meta_data( '_veeqo_sellable_id', $sellable_id );
					$product->update_meta_data( '_veeqo_mapped_sku', $sku );
					$product->save_meta_data();
					set_transient( $transient_key, $sellable_id, DAY_IN_SECONDS );
					dtb_veeqo_log( 'info', 'sellable_resolved_for_order', 'Resolved Veeqo sellable ID from SKU during order sync.', [
						'product_id'  => $product->get_id(),
						'sku'         => $sku,
						'sellable_id' => $sellable_id,
					] );
					return $sellable_id;
				}
			}
		}
	}

	dtb_veeqo_log( 'warn', 'sellable_not_found_for_sku', 'No exact Veeqo sellable match found for SKU during order sync.', [
		'product_id' => $product->get_id(),
		'sku'        => $sku,
	] );
	return 0;
}

/**
 * Map a WooCommerce payment method to Veeqo's accepted payment_type values.
 *
 * @param WC_Order $order Woo order.
 * @return string
 */
function dtb_veeqo_payment_type_for_order( WC_Order $order ): string {
	$method = sanitize_key( (string) $order->get_payment_method() );

	if ( str_contains( $method, 'bacs' ) || str_contains( $method, 'bank' ) ) {
		return 'bank_transfer';
	}
	if ( str_contains( $method, 'cheque' ) || str_contains( $method, 'check' ) ) {
		return 'checkmo';
	}
	if ( '' === $method || 'cod' === $method ) {
		return 'cash';
	}

	return 'credit_card';
}

/**
 * Return a redacted Veeqo order payload summary for diagnostics.
 *
 * @param array $payload Veeqo order payload.
 * @return array<string,mixed>
 */
function dtb_veeqo_order_payload_diagnostics( array $payload ): array {
	$order = is_array( $payload['order'] ?? null ) ? $payload['order'] : [];
	$lines = is_array( $order['line_items_attributes'] ?? null ) ? $order['line_items_attributes'] : [];

	return [
		'channel_id'         => absint( $order['channel_id'] ?? 0 ),
		'delivery_method_id' => absint( $order['delivery_method_id'] ?? 0 ),
		'warehouse_id'       => absint( $order['allocations_attributes'][0]['warehouse_id'] ?? 0 ),
		'line_count'         => count( $lines ),
		'sellable_ids'       => array_values( array_filter( array_map(
			static fn( $line ): int => is_array( $line ) ? absint( $line['sellable_id'] ?? 0 ) : 0,
			$lines
		) ) ),
		'order_number'       => sanitize_text_field( (string) ( $order['number'] ?? $order['channel_order_number'] ?? '' ) ),
	];
}

/**
 * Build the Veeqo order creation payload from a WooCommerce order.
 *
 * @param WC_Order $order
 * @return array|null  Payload array, or null when the order has no items.
 */
function dtb_veeqo_build_order_payload( WC_Order $order ): ?array {
	$cfg   = dtb_veeqo_config();
	$items = $order->get_items();

	if ( empty( $items ) ) {
		return null;
	}

	if ( empty( $cfg['channel_id'] ) ) {
		dtb_veeqo_log( 'error', 'order_sync_blocked_missing_channel', 'Veeqo order sync blocked: channel_id is not configured.', [
			'order_id' => $order->get_id(),
		] );
		$order->add_order_note( '[Veeqo] Sync blocked: Veeqo Channel ID is not configured. Re-save WooCommerce > Settings > Integrations > Drywall Toolbox Veeqo, or set DTB_VEEQO_CHANNEL_ID.', false, false );
		return null;
	}

	if ( empty( $cfg['delivery_method_id'] ) ) {
		dtb_veeqo_log( 'error', 'order_sync_blocked_missing_delivery_method', 'Veeqo order sync blocked: delivery_method_id is not configured.', [
			'order_id'   => $order->get_id(),
			'channel_id' => (int) $cfg['channel_id'],
		] );
		$order->add_order_note( '[Veeqo] Sync blocked: Veeqo Delivery Method ID is not configured. Set it under WooCommerce > Settings > Integrations > Drywall Toolbox Veeqo, or define DTB_VEEQO_DELIVERY_METHOD_ID.', false, false );
		return null;
	}

	$line_items          = [];
	$missing_sku_items   = [];
	$missing_id_items    = [];

	foreach ( $items as $item ) {
		/** @var WC_Order_Item_Product $item */
		$product     = $item->get_product();
		$sellable_id = $product ? absint( $product->get_meta( '_veeqo_sellable_id', true ) ) : 0;

		// SKU enforcement: Veeqo requires a sellable/variant ID per line item.
		// A SKU lets us resolve that ID when product meta is not mapped yet.
		$sku             = $product ? trim( (string) $product->get_sku() ) : '';
		$variation_id    = $item->get_variation_id();
		$is_variation    = $variation_id > 0;

		if ( '' === $sku ) {
			$missing_sku_items[] = [
				'item_name'    => $item->get_name(),
				'variation_id' => $variation_id,
				'product_id'   => $product ? $product->get_id() : 0,
				'order_id'     => $order->get_id(),
			];
			dtb_veeqo_log( 'warn', 'line_item_missing_sku', 'Line item has no SKU; Veeqo sync blocked for this item.', [
				'order_id'     => $order->get_id(),
				'item_name'    => $item->get_name(),
				'variation_id' => $variation_id,
				'product_id'   => $product ? $product->get_id() : 0,
			] );
		}

		if ( $sellable_id <= 0 && $product && '' !== $sku ) {
			$sellable_id = dtb_veeqo_resolve_sellable_id_for_sku( $sku, $product );
		}

		if ( $sellable_id <= 0 ) {
			$missing_id_items[] = [
				'item_name'    => $item->get_name(),
				'sku'          => $sku,
				'variation_id' => $variation_id,
				'product_id'   => $product ? $product->get_id() : 0,
			];
			continue;
		}

		$quantity       = max( 1, (int) $item->get_quantity() );
		$subtotal       = (float) $item->get_subtotal();
		$total_tax      = (float) $item->get_total_tax();
		$tax_rate       = $subtotal > 0 ? round( $total_tax / $subtotal, 4 ) : 0.0;
		$discount_total = max( 0.0, $subtotal - (float) $item->get_total() );

		$line_items[] = [
			'sellable_id'                 => $sellable_id,
			'quantity'                    => $quantity,
			'price_per_unit'              => round( $subtotal / $quantity, 2 ),
			'tax_rate'                    => $tax_rate,
			'taxless_discount_per_unit'   => round( $discount_total / $quantity, 2 ),
		];
	}

	// If any line items lack a SKU, log an audit event and return
	// null to block the Veeqo order creation — partial fulfilment of the wrong
	// product is worse than no fulfilment at all.
	if ( ! empty( $missing_sku_items ) ) {
		dtb_veeqo_log( 'error', 'order_sync_blocked_missing_sku', 'Veeqo order sync blocked: one or more line items have no SKU.', [
			'order_id'           => $order->get_id(),
			'missing_sku_items'  => $missing_sku_items,
		] );

		// Mark the WC order with a note so admins can identify and fix it.
		$order->add_order_note(
			'[Veeqo] Sync blocked: one or more line item(s) have no SKU. Assign SKUs and retry sync.',
			false,
			false
		);
		return null;
	}

	if ( ! empty( $missing_id_items ) ) {
		dtb_veeqo_log( 'error', 'order_sync_blocked_missing_sellable', 'Veeqo order sync blocked: one or more SKUs could not be mapped to a Veeqo sellable ID.', [
			'order_id'         => $order->get_id(),
			'missing_id_items' => $missing_id_items,
		] );

		$order->add_order_note(
			'[Veeqo] Sync blocked: one or more SKUs could not be matched to a Veeqo sellable ID. Run the Veeqo SKU mapper or verify the SKU exists in Veeqo, then retry sync.',
			false,
			false
		);
		return null;
	}

	$billing = $order->get_address( 'billing' );
	$order_number = ltrim( (string) $order->get_order_number(), '#' );

	$payload_order = [
		'channel_id'                    => (int) $cfg['channel_id'],
		'delivery_method_id'            => (int) $cfg['delivery_method_id'],
		'number'                        => $order_number,
		'channel_order_number'          => $order_number,
		'send_notification_email'       => false,
		'total_discounts'               => (float) $order->get_discount_total(),
		'customer_attributes'           => [
			'email'     => $order->get_billing_email(),
			'firstname' => $billing['first_name'],
			'lastname'  => $billing['last_name'],
			'mobile'    => $order->get_billing_phone(),
		],
		'deliver_to_attributes'         => [
			'first_name' => $order->get_shipping_first_name() ?: $billing['first_name'],
			'last_name'  => $order->get_shipping_last_name()  ?: $billing['last_name'],
			'address1'   => $order->get_shipping_address_1()  ?: $billing['address_1'],
			'address2'   => $order->get_shipping_address_2()  ?: $billing['address_2'],
			'city'       => $order->get_shipping_city()       ?: $billing['city'],
			'state'      => $order->get_shipping_state()      ?: $billing['state'],
			'zip'        => $order->get_shipping_postcode()   ?: $billing['postcode'],
			'country'    => $order->get_shipping_country()    ?: $billing['country'],
			'phone'      => $order->get_shipping_phone() ?: $order->get_billing_phone(),
			'company'    => $order->get_shipping_company() ?: $billing['company'],
		],
		'line_items_attributes'         => $line_items,
		'payment_attributes'            => [
			'payment_type'     => dtb_veeqo_payment_type_for_order( $order ),
			'reference_number' => (string) ( $order->get_transaction_id() ?: $order_number ),
		],
	];

	if ( ! empty( $cfg['warehouse_id'] ) ) {
		$payload_order['allocations_attributes'] = [
			[
				'warehouse_id' => (int) $cfg['warehouse_id'],
			],
		];
	}

	if ( '' !== (string) $order->get_customer_note() ) {
		$payload_order['customer_note_attributes'] = [
			'text' => (string) $order->get_customer_note(),
		];
	}

	// Veeqo REST API requires the payload wrapped in an "order" key,
	// with nested objects using the _attributes suffix convention.
	return [
		'order' => $payload_order,
	];
}




// =============================================================================
// SECTION 6 — INVENTORY SYNC
//
// When WooCommerce reduces stock (after payment), log the event to Veeqo
// via a stock-adjustment note for reconciliation in the Veeqo warehouse dashboard.
// This supplements Veeqo's own order-based stock management without requiring
// a second stock mutation that could cause double-counting.
// =============================================================================

add_action( 'woocommerce_reduce_order_stock', 'dtb_veeqo_log_stock_reduction', 20 );

function dtb_veeqo_log_stock_reduction( WC_Order $order ): void {
	if ( ! dtb_veeqo_enabled() ) {
		return;
	}

	$veeqo_order_id = (int) $order->get_meta( '_veeqo_order_id' );
	$items          = $order->get_items();
	$adjustments    = [];

	foreach ( $items as $item ) {
		/** @var WC_Order_Item_Product $item */
		$product = $item->get_product();
		if ( ! $product || ! $product->managing_stock() ) {
			continue;
		}

		$adjustments[] = [
			'product_name' => $item->get_name(),
			'sku'          => $product->get_sku(),
			'qty'          => $item->get_quantity(),
			'new_stock'    => $product->get_stock_quantity(),
		];
	}

	if ( ! empty( $adjustments ) ) {
		dtb_veeqo_log( 'info', 'stock_reduced', 'WooCommerce stock reduced after order.', [
			'wc_order_id'    => $order->get_id(),
			'veeqo_order_id' => $veeqo_order_id,
			'adjustments'    => $adjustments,
		] );
	}
}


// =============================================================================
// SECTION 7 — PRODUCT SKU → VEEQO SELLABLE ID MAPPING
//
// After a WooCommerce product is saved, attempt to find the matching Veeqo
// sellable by SKU and store the sellable ID as product meta (_veeqo_sellable_id).
// This enables accurate line-item construction in dtb_veeqo_build_order_payload().
//
// The lookup runs only when Veeqo is configured and a SKU exists.
// =============================================================================

add_action( 'woocommerce_update_product', 'dtb_veeqo_map_product_sku', 20 );

function dtb_veeqo_map_product_sku( int $product_id ): void {
	if ( ! dtb_veeqo_enabled() ) {
		return;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return;
	}

	$sku = $product->get_sku();
	if ( '' === $sku ) {
		return;
	}

	// Skip if mapping already done (re-map on explicit SKU change by checking meta).
	$cached_sku = $product->get_meta( '_veeqo_mapped_sku' );
	if ( $cached_sku === $sku ) {
		return;
	}

	// Use a brief transient lock to avoid hammering the API during bulk saves.
	$lock_key = 'dtb_veeqo_sku_lock_' . md5( $sku );
	if ( get_transient( $lock_key ) ) {
		return;
	}
	set_transient( $lock_key, 1, 30 );

	$result = dtb_veeqo_request( 'GET', '/products', [ 'query' => $sku ] );

	if ( ! $result['ok'] || empty( $result['data'] ) ) {
		return;
	}

	// Find the first sellable whose sku_code matches exactly.
	foreach ( (array) $result['data'] as $veeqo_product ) {
		foreach ( (array) ( $veeqo_product['sellables'] ?? [] ) as $sellable ) {
			if ( isset( $sellable['sku_code'] ) && $sellable['sku_code'] === $sku ) {
				$product->update_meta_data( '_veeqo_sellable_id', (int) $sellable['id'] );
				$product->update_meta_data( '_veeqo_mapped_sku', $sku );
				$product->save_meta_data();
				dtb_veeqo_log( 'debug', 'sku_mapped', 'WC product SKU mapped to Veeqo sellable.', [
					'product_id'  => $product_id,
					'sku'         => $sku,
					'sellable_id' => (int) $sellable['id'],
				] );
				return;
			}
		}
	}
}


// =============================================================================
// SECTION 7b — BULK SKU → SELLABLE ID MAPPING
//
// POST /dtb/v1/veeqo/map-skus  (manage_woocommerce only)
//
// Iterates all WC products and variations. For each SKU not yet mapped,
// calls GET /products?query={sku} on the Veeqo API and writes _veeqo_sellable_id.
// Run once after initial product import to unlock order sync.
// =============================================================================

function dtb_veeqo_route_admin_map_skus( WP_REST_Request $request ): WP_REST_Response {
	if ( ! dtb_veeqo_enabled() ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'not_configured', 'Veeqo integration is not configured.', 503 ),
			503
		);
	}

	$mapped   = 0;
	$skipped  = 0;
	$failed   = 0;
	$per_page = 100;
	$page     = 1;

	do {
		$products = wc_get_products( [
			'status'   => 'publish',
			'limit'    => $per_page,
			'page'     => $page,
			'type'     => [ 'simple', 'variation' ],
			'return'   => 'objects',
		] );

		foreach ( $products as $product ) {
			$sku = $product->get_sku();
			if ( '' === $sku ) {
				$skipped++;
				continue;
			}
			// Already mapped?
			if ( $product->get_meta( '_veeqo_mapped_sku' ) === $sku ) {
				$skipped++;
				continue;
			}

			$result = dtb_veeqo_request( 'GET', '/products', [ 'query' => $sku ] );
			if ( ! $result['ok'] || empty( $result['data'] ) ) {
				$failed++;
				continue;
			}

			$found = false;
			foreach ( (array) $result['data'] as $vp ) {
				foreach ( (array) ( $vp['sellables'] ?? [] ) as $sellable ) {
					if ( isset( $sellable['sku_code'] ) && $sellable['sku_code'] === $sku ) {
						$product->update_meta_data( '_veeqo_sellable_id', (int) $sellable['id'] );
						$product->update_meta_data( '_veeqo_mapped_sku', $sku );
						$product->save_meta_data();
						$mapped++;
						$found = true;
						break 2;
					}
				}
			}
			if ( ! $found ) {
				$failed++;
			}
		}

		$page++;
	} while ( count( $products ) === $per_page );

	dtb_veeqo_log( 'info', 'bulk_sku_map_complete', 'Bulk SKU → Veeqo sellable ID mapping complete.', [
		'mapped'  => $mapped,
		'skipped' => $skipped,
		'failed'  => $failed,
	] );

	return new WP_REST_Response( [
		'success' => true,
		'mapped'  => $mapped,
		'skipped' => $skipped,
		'failed'  => $failed,
	], 200 );
}

// =============================================================================
// SECTION 8 — WEBHOOK AUTO-REGISTRATION (Veeqo → WooCommerce)
//
// Ensures a dtb/v1/veeqo/webhooks/order endpoint is registered in Veeqo so
// order-status changes flow back automatically. Runs once on init; skips if
// already registered (checks a WP option).
// =============================================================================

add_action( 'init', 'dtb_veeqo_ensure_webhooks', 30 );

/**
 * Ensure the DTB order-status webhook is registered in Veeqo.
 *
 * Runs once per day (transient-gated). Also handles secret rotation: when
 * DTB_VEEQO_WEBHOOK_SECRET is rotated the stored fingerprint (wp_options key
 * dtb_veeqo_webhook_secret_hash) becomes stale, forcing this function to bypass
 * the transient gate and update (or recreate) the Veeqo webhook with the new
 * secret.
 *
 * Fingerprint gate is intentionally checked BEFORE the transient so that a
 * secret rotation is always acted on even if the daily transient is still set.
 */
function dtb_veeqo_ensure_webhooks(): void {
	if ( ! dtb_veeqo_enabled() ) {
		return;
	}

	$cfg          = dtb_veeqo_config();
	$delivery_url = rest_url( 'dtb/v1/veeqo/webhooks/order' );
	$secret       = (string) ( $cfg['webhook_secret'] ?? ( defined( 'DTB_VEEQO_WEBHOOK_SECRET' ) ? DTB_VEEQO_WEBHOOK_SECRET : '' ) );

	// Determine whether the secret has changed since last sync.
	$current_hash = '' !== $secret ? hash( 'sha256', 'dtb-veeqo:' . $secret ) : '';
	$stored_hash  = (string) get_option( 'dtb_veeqo_webhook_secret_hash', '' );
	$secret_rotated = '' !== $current_hash && ! hash_equals( $current_hash, $stored_hash );

	// Skip the daily transient ONLY when a secret rotation is detected so the
	// new secret is pushed to Veeqo immediately.
	if ( ! $secret_rotated && get_transient( 'dtb_veeqo_webhook_registered' ) ) {
		return;
	}
	set_transient( 'dtb_veeqo_webhook_registered', 1, DAY_IN_SECONDS );

	// List existing webhooks.
	$result = dtb_veeqo_request( 'GET', '/webhooks' );
	if ( ! $result['ok'] || ! is_array( $result['data'] ) ) {
		return;
	}

	// Locate an existing registration by delivery URL.
	$existing_id = null;
	foreach ( $result['data'] as $hook ) {
		if ( isset( $hook['url'] ) && rtrim( $hook['url'], '/' ) === rtrim( $delivery_url, '/' ) ) {
			$existing_id = $hook['id'] ?? null;
			break;
		}
	}

	// Build the payload — always include secret when one is configured.
	$payload = [
		'url'    => $delivery_url,
		'events' => [ 'order.status_changed' ],
	];
	if ( '' !== $secret ) {
		$payload['secret'] = $secret;
	}

	if ( null !== $existing_id ) {
		if ( ! $secret_rotated ) {
			// Already registered and secret is current — nothing to do.
			return;
		}

		// Secret rotation detected: update the existing Veeqo webhook via PUT.
		$update = dtb_veeqo_request( 'PUT', '/webhooks/' . (int) $existing_id, [], $payload );

		if ( $update['ok'] ) {
			if ( '' !== $current_hash ) {
				update_option( 'dtb_veeqo_webhook_secret_hash', $current_hash, false );
			}
			dtb_veeqo_log( 'info', 'webhook_secret_synced', 'Veeqo webhook secret updated after rotation.', [
				'webhook_id'   => $existing_id,
				'delivery_url' => $delivery_url,
			] );
			return;
		}

		// PUT failed (some Veeqo plans return 405). Fall through to DELETE + recreate.
		dtb_veeqo_log( 'warn', 'webhook_put_failed', 'Veeqo webhook PUT failed; attempting DELETE + recreate.', [
			'webhook_id' => $existing_id,
			'error'      => $update['error'],
		] );
		dtb_veeqo_request( 'DELETE', '/webhooks/' . (int) $existing_id );
	}

	// Register (or re-register after delete) the webhook.
	$register = dtb_veeqo_request( 'POST', '/webhooks', [], $payload );

	if ( $register['ok'] ) {
		if ( '' !== $current_hash ) {
			update_option( 'dtb_veeqo_webhook_secret_hash', $current_hash, false );
		}
		dtb_veeqo_log( 'info', 'webhook_registered', 'Veeqo → DTB webhook registered.', [
			'delivery_url' => $delivery_url,
			'secret_sent'  => '' !== $secret,
		] );
	} else {
		dtb_veeqo_log( 'warn', 'webhook_register_failed', 'Could not register Veeqo webhook.', [
			'error' => $register['error'],
		] );
	}
}


// =============================================================================
// SECTION 9 — STRUCTURED LOGGING
//
// All Veeqo integration events are logged via error_log() in a consistent JSON
// format for easy parsing by log aggregators (Papertrail, Loggly, CloudWatch).
//
// Format:
//   [DTB Veeqo] {"level":"info","event":"order_synced","message":"...","context":{...},"ts":"..."}
// =============================================================================

/**
 * Write a structured log entry for a Veeqo integration event.
 *
 * Log entries are written to the WooCommerce logger (source: veeqo-wc-integration)
 * so they are visible at WooCommerce → Status → Logs → veeqo-wc-integration.
 * Falls back to error_log() when WooCommerce is not yet available.
 *
 * Enable debug-level logging by adding to wp-config.php:
 *   define( 'DTB_VEEQO_DEBUG', true );
 *
 * @param string $level   Severity: debug | info | warn | error.
 * @param string $event   Machine-readable event name (snake_case).
 * @param string $message Human-readable description.
 * @param array  $context Optional additional context key-value pairs.
 */
function dtb_veeqo_log( string $level, string $event, string $message, array $context = [] ): void {
	// Suppress debug logs in production unless opt-in constant is set.
	if ( 'debug' === $level && ( ! defined( 'DTB_VEEQO_DEBUG' ) || ! DTB_VEEQO_DEBUG ) ) {
		return;
	}

	$entry = [
		'level'   => $level,
		'event'   => $event,
		'message' => $message,
		'ts'      => gmdate( 'c' ),
	];

	if ( ! empty( $context ) ) {
		$entry['context'] = $context;
	}

	// Map DTB severity levels to WooCommerce log levels.
	$wc_level_map = [
		'debug' => 'debug',
		'info'  => 'info',
		'warn'  => 'warning',
		'error' => 'error',
	];
	$wc_level = $wc_level_map[ $level ] ?? 'info';

	if ( function_exists( 'wc_get_logger' ) ) {
		// Viewable at WooCommerce → Status → Logs → veeqo-wc-integration.
		wc_get_logger()->log( $wc_level, wp_json_encode( $entry ), [ 'source' => 'veeqo-wc-integration' ] );
	} else {
		// Fallback: WooCommerce not yet loaded (e.g. very early hooks or CLI).
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[DTB Veeqo] ' . wp_json_encode( $entry ) );
	}
}


// =============================================================================
// SECTION 10 — HEALTH MONITOR
//
// Scheduled daily: verifies the Veeqo API is reachable and logs the result.
// The cron event can be extended to send admin email alerts on failure.
// =============================================================================

add_action( 'init', function (): void {
	if ( ! wp_next_scheduled( 'dtb_veeqo_health_check' ) ) {
		wp_schedule_event( time(), 'daily', 'dtb_veeqo_health_check' );
	}
} );

add_action( 'dtb_veeqo_health_check', 'dtb_veeqo_run_health_check' );

function dtb_veeqo_run_health_check(): void {
	if ( ! dtb_veeqo_enabled() ) {
		dtb_veeqo_log( 'warn', 'health_check_skipped', 'Veeqo health check skipped: API key not configured.' );
		return;
	}

	$result = dtb_veeqo_request( 'GET', '/warehouses' );

	if ( $result['ok'] ) {
		$count = is_array( $result['data'] ) ? count( $result['data'] ) : 0;
		dtb_veeqo_log( 'info', 'health_check_ok', 'Veeqo API reachable.', [ 'warehouses' => $count ] );

		// Clear any "unhealthy" transient.
		delete_transient( 'dtb_veeqo_unhealthy' );
	} else {
		dtb_veeqo_log( 'error', 'health_check_failed', 'Veeqo API unreachable.', [
			'status' => $result['status'],
			'error'  => $result['error'],
		] );

		// Track consecutive failures; alert admin on 3rd consecutive failure.
		$failures = (int) get_transient( 'dtb_veeqo_unhealthy' ) + 1;
		set_transient( 'dtb_veeqo_unhealthy', $failures, 3 * DAY_IN_SECONDS );

		if ( $failures >= 3 ) {
			dtb_veeqo_send_alert(
				'[Alert] Veeqo Integration Unreachable',
				sprintf(
					"The Veeqo API has been unreachable for %d consecutive daily health checks.\n\nLast error: %s\n\nPlease verify the DTB_VEEQO_API_KEY constant and Veeqo service status.",
					$failures,
					$result['error']
				)
			);
		}
	}
}

/**
 * Send an admin alert email (only when DTB_ADMIN_EMAIL is defined).
 */
function dtb_veeqo_send_alert( string $subject, string $body ): void {
	$to = defined( 'DTB_ADMIN_EMAIL' ) ? DTB_ADMIN_EMAIL : get_option( 'admin_email', '' );
	if ( empty( $to ) ) {
		return;
	}

	$mail_subject = '[Drywall Toolbox] ' . $subject;
	if ( function_exists( 'dtb_send_email' ) ) {
		dtb_send_email(
			[
				'to'           => (string) $to,
				'subject'      => $mail_subject,
				'message'      => $body,
				'content_type' => 'text/plain',
				'context'      => [
					'module' => 'dtb-integrations-veeqo',
					'event'  => 'admin-alert',
				],
			]
		);
		return;
	}

	wp_mail(
		$to,
		$mail_subject,
		$body,
		[ 'Content-Type: text/plain; charset=UTF-8' ]
	);
}

// =============================================================================
// OPS DASHBOARD HELPERS
// =============================================================================

/**
 * Return the count of Veeqo orders with a "awaiting_fulfillment" or "allocated"
 * (pending) repair/fulfillment status for the ops KPI panel.
 *
 * Results are cached in the dtb_ops transient store (5-min TTL).
 *
 * @return int Pending repair/fulfillment count, or 0 when Veeqo is unavailable.
 */
function dtb_veeqo_get_pending_repairs_count(): int {
	if ( ! dtb_veeqo_enabled() ) {
		return 0;
	}

	if ( function_exists( 'dtb_ops_cache_get' ) ) {
		return (int) dtb_ops_cache_get( 'repairs', 'pending_count', DTB_OPS_TTL_REPAIRS ?? 300, static function () {
			$result = dtb_veeqo_request( 'GET', '/orders', [
				'status'    => 'awaiting_fulfillment,allocated',
				'page_size' => 1,
			] );
			if ( ! $result['ok'] || ! isset( $result['data']['total_count'] ) ) {
				return 0;
			}
			return (int) $result['data']['total_count'];
		} );
	}

	$result = dtb_veeqo_request( 'GET', '/orders', [
		'status'    => 'awaiting_fulfillment,allocated',
		'page_size' => 1,
	] );

	if ( ! $result['ok'] || ! isset( $result['data']['total_count'] ) ) {
		return 0;
	}

	return (int) $result['data']['total_count'];
}

/**
 * Return a summary of inventory levels across all warehouses.
 *
 * @return array { total_skus: int, low_stock: int, out_of_stock: int }
 */
function dtb_veeqo_get_inventory_summary(): array {
	$empty = [ 'total_skus' => 0, 'low_stock' => 0, 'out_of_stock' => 0 ];

	if ( ! dtb_veeqo_enabled() ) {
		return $empty;
	}

	if ( function_exists( 'dtb_ops_cache_get' ) ) {
		return (array) dtb_ops_cache_get( 'inventory', 'summary', DTB_OPS_TTL_INVENTORY ?? 300, static function () use ( $empty ) {
			return dtb_veeqo_fetch_inventory_summary() ?: $empty;
		} );
	}

	return dtb_veeqo_fetch_inventory_summary() ?: $empty;
}

/**
 * Internal: fetch and aggregate inventory summary from Veeqo.
 *
 * @return array|null Null on API error.
 */
function dtb_veeqo_fetch_inventory_summary(): ?array {
	$total     = 0;
	$low_stock = 0;
	$oos       = 0;
	$page      = 1;
	$per_page  = 100;

	do {
		$result = dtb_veeqo_request( 'GET', '/products', [
			'page'      => (string) $page,
			'page_size' => (string) $per_page,
		] );

		if ( ! $result['ok'] || ! is_array( $result['data'] ) ) {
			return null;
		}

		$products = $result['data'];
		foreach ( $products as $product ) {
			$total++;
			$qty = isset( $product['sellable_on_hand_count'] )
				? (int) $product['sellable_on_hand_count']
				: 0;

			if ( 0 === $qty ) {
				$oos++;
			} elseif ( $qty <= 3 ) {
				$low_stock++;
			}
		}

		$page++;
	} while ( count( $products ) === $per_page );

	return [
		'total_skus'    => $total,
		'low_stock'     => $low_stock,
		'out_of_stock'  => $oos,
	];
}

/**
 * Record a sync timestamp for a named Veeqo sync operation.
 *
 * Stored in wp_options('dtb_veeqo_sync_{type}') as a Unix timestamp.
 *
 * @param string $type Sync type identifier (e.g. 'order_webhook', 'stock_sync').
 */
function dtb_veeqo_log_sync_timestamp( string $type ): void {
	$key = 'dtb_veeqo_sync_' . sanitize_key( $type );
	update_option( $key, time(), false );
}


// =============================================================================
// SECTION 10b — SCHEDULED INVENTORY PULL (VEEQO → WOOCOMMERCE)
//
// Runs every 6 hours via WP-Cron.
//
// Flow:
//   1. Fetch all products from Veeqo in API-supported pages of up to 100.
//   2. For each Veeqo sellable, extract available_stock (sum across warehouses).
//   3. Find the matching WooCommerce product/variation by SKU.
//   4. Update WC stock quantity to the Veeqo value when there is a discrepancy.
//
// Veeqo is the inventory authority. WooCommerce stores the checkout-facing
// projection and continues to perform its normal local reservation/reduction
// behavior between authoritative Veeqo reconciliations.
//
// The pull can also be triggered on-demand by admins via:
//   POST /dtb/v1/veeqo/inventory/pull
// =============================================================================

add_filter( 'cron_schedules', 'dtb_veeqo_register_cron_intervals' );

function dtb_veeqo_register_cron_intervals( array $schedules ): array {
	if ( ! isset( $schedules['dtb_six_hours'] ) ) {
		$schedules['dtb_six_hours'] = [
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 Hours (DTB Veeqo Inventory Pull)', 'woocommerce' ),
		];
	}
	return $schedules;
}

add_action( 'init', 'dtb_veeqo_schedule_inventory_pull', 25 );

function dtb_veeqo_schedule_inventory_pull(): void {
	if ( ! wp_next_scheduled( 'dtb_veeqo_inventory_sync' ) ) {
		wp_schedule_event( time(), 'dtb_six_hours', 'dtb_veeqo_inventory_sync' );
	}
}

add_action( 'dtb_veeqo_inventory_sync', 'dtb_veeqo_run_inventory_pull' );

function dtb_veeqo_run_inventory_pull(): void {
	if ( ! dtb_veeqo_enabled() ) {
		return;
	}

	$updated  = 0;
	$page     = 1;
	$per_page = 100;
	$failed   = false;

	do {
		$products_seen = 0;
		$page_updated  = dtb_veeqo_pull_inventory_into_wc( $page, $per_page, $products_seen );
		if ( null === $page_updated ) {
			$failed = true;
			break;
		}

		$updated += $page_updated;
		$page++;
	} while ( $products_seen === $per_page );

	dtb_veeqo_log(
		! $failed ? 'info' : 'warn',
		'inventory_sync_cron',
		! $failed
			? sprintf( 'Scheduled inventory pull complete. %d WooCommerce product(s) updated across %d Veeqo page(s).', $updated, $page - 1 )
			: sprintf( 'Scheduled inventory pull stopped after a Veeqo API error on page %d.', $page ),
		[
			'updated_skus'   => $updated,
			'pages_completed' => $page - 1,
		]
	);

	if ( ! $failed ) {
		dtb_veeqo_log_sync_timestamp( 'inventory_pull' );
	}
}

/**
 * Fetch one page of Veeqo products, compute available stock per SKU,
 * and write the values back to WooCommerce product/variation stock.
 *
 * @param int      $page          Veeqo page number (1-indexed).
 * @param int      $per_page      Products per page (Veeqo maximum: 100).
 * @param int|null $products_seen Receives the number of Veeqo products returned on this page.
 * @return int|null  Number of WC products updated, or null on API error.
 */
function dtb_veeqo_pull_inventory_into_wc( int $page = 1, int $per_page = 100, ?int &$products_seen = null ): ?int {
	if ( ! function_exists( 'wc_get_product_id_by_sku' ) ) {
		return null;
	}

	$result = dtb_veeqo_request( 'GET', '/products', [
		'page'      => (string) max( 1, $page ),
		'page_size' => (string) min( 100, max( 1, $per_page ) ),
	] );

	if ( ! $result['ok'] || ! is_array( $result['data'] ) ) {
		dtb_veeqo_log( 'error', 'inventory_pull_api_error', 'Failed to fetch products from Veeqo.', [
			'status' => $result['status'],
			'error'  => $result['error'],
		] );
		return null;
	}

	$products_seen = count( $result['data'] );
	$updated       = 0;
	$matched       = 0;
	$enabled       = 0;
	$unchanged     = 0;
	$unmatched     = 0;

	foreach ( (array) $result['data'] as $veeqo_product ) {
		$sellables = (array) ( $veeqo_product['sellables'] ?? [] );

		foreach ( $sellables as $sellable ) {
			$sku = trim( (string) ( $sellable['sku_code'] ?? '' ) );
			if ( '' === $sku ) {
				continue;
			}

			// Sum available_stock across all warehouse stock entries.
			$available = 0;
			foreach ( (array) ( $sellable['stock_entries'] ?? [] ) as $entry ) {
				$available += max( 0, (int) ( $entry['available_stock'] ?? 0 ) );
			}

			// Find the WooCommerce product or variation with this SKU.
			$wc_product_id = wc_get_product_id_by_sku( $sku );
			if ( ! $wc_product_id ) {
				$unmatched++;
				continue;
			}

			$wc_product = wc_get_product( $wc_product_id );
			if (
				! $wc_product
				|| trim( (string) $wc_product->get_sku() ) !== $sku
				|| ! in_array( $wc_product->get_type(), [ 'simple', 'variation' ], true )
			) {
				$unmatched++;
				continue;
			}
			$matched++;

			$sellable_id    = absint( $sellable['id'] ?? 0 );
			$mapping_changed = $wc_product->get_meta( '_veeqo_mapped_sku' ) !== $sku
				|| ( $sellable_id > 0 && (int) $wc_product->get_meta( '_veeqo_sellable_id' ) !== $sellable_id );
			if ( $mapping_changed ) {
				$wc_product->update_meta_data( '_veeqo_mapped_sku', $sku );
				if ( $sellable_id > 0 ) {
					$wc_product->update_meta_data( '_veeqo_sellable_id', $sellable_id );
				}
			}

			$stock_status = $available > 0
				? 'instock'
				: ( $wc_product->backorders_allowed() ? 'onbackorder' : 'outofstock' );

			// An exact Veeqo SKU match is sufficient authority to begin tracking the
			// WooCommerce projection. Set the flag and quantity together so a product
			// is never briefly managed with an invented/default stock value.
			if ( ! $wc_product->managing_stock() ) {
				$wc_product->set_manage_stock( true );
				$wc_product->set_stock_quantity( $available );
				$wc_product->set_stock_status( $stock_status );
				$wc_product->save();
				$enabled++;
				$updated++;

				dtb_veeqo_log( 'info', 'inventory_stock_management_enabled', 'WooCommerce stock management enabled from an exact Veeqo SKU match.', [
					'sku'       => $sku,
					'wc_id'     => $wc_product_id,
					'veeqo'     => $available,
				] );
				continue;
			}

			$current_stock = (int) $wc_product->get_stock_quantity();
			if ( $current_stock === $available && $wc_product->get_stock_status() === $stock_status ) {
				// Already in sync — no write needed.
				if ( $mapping_changed ) {
					$wc_product->save_meta_data();
				}
				$unchanged++;
				continue;
			}

			$wc_product->set_stock_quantity( $available );
			$wc_product->set_stock_status( $stock_status );
			$wc_product->save();
			$updated++;

			dtb_veeqo_log( 'debug', 'inventory_stock_updated', 'WC product stock reconciled from Veeqo.', [
				'sku'       => $sku,
				'wc_id'     => $wc_product_id,
				'wc_before' => $current_stock,
				'veeqo'     => $available,
			] );
		}
	}

	dtb_veeqo_log( 'info', 'inventory_pull_page_complete', 'Veeqo inventory page projected into WooCommerce.', [
		'page'                   => $page,
		'veeqo_products_seen'    => $products_seen,
		'wc_skus_matched'        => $matched,
		'stock_tracking_enabled' => $enabled,
		'updated'                => $updated,
		'unchanged'              => $unchanged,
		'unmatched_sellables'    => $unmatched,
	] );

	return $updated;
}


// =============================================================================
// SECTION 11 — REPAIR SERVICE REQUEST ENDPOINT
//
// POST /dtb/v1/repair-request
//
// Accepts the 5-step repair form submission from the React SPA, creates a
// WooCommerce order using the WC internal PHP API (no HTTP round-trip needed),
// optionally syncs the service order to Veeqo for fulfilment tracking, and
// emails a confirmation to the customer.
//
// Security:
//   • Rate-limited: 5 repair submissions per IP per hour.
//   • Input sanitised before any write operation.
//   • No JWT required (unauthenticated guests submit repair requests).
//
// WooCommerce order:
//   • Status: wc-pending (awaiting quote approval)
//   • Line item: custom "Repair Service — {brand} {model}" item (no WC product needed)
//   • Shipping address: customer's return address
//   • Order meta: full service details stored as _dtb_repair_* meta keys
//   • Order note: service type, priority, and issue description
//
// Veeqo sync:
//   • Only runs when DTB_VEEQO_API_KEY is configured.
//   • Uses the same dtb_veeqo_build_order_payload() builder as standard orders.
// =============================================================================

/**
 * POST /dtb/v1/repair-request
 *
 * Expected JSON body:
 * {
 *   "fullName":        "Jane Smith",
 *   "email":           "jane@example.com",
 *   "phone":           "555-000-1234",
 *   "company":         "Acme Drywall",            // optional
 *   "toolBrand":       "Columbia",
 *   "toolCategory":    "Finishing Boxes",
 *   "toolModel":       "Columbia 10-inch Flat Box",
 *   "serialNumber":    "COL-2024-XXXXX",           // optional
 *   "toolAge":         "3–5 years",                // optional
 *   "serviceType":     "General Repair",
 *   "priority":        "Standard (5–7 business days)",
 *   "issueStart":      "This week",                // optional
 *   "issueDescription":"Pump losing pressure…",
 *   "contactPreference":"email",
 *   "address":         "123 Main St",
 *   "city":            "Sacramento",
 *   "state":           "CA",
 *   "zip":             "95814",
 *   "country":         "US",
 *   "shippingRateId":  "standard",
 *   "shippingRateName":"Standard Shipping (5–7 business days)",
 *   "shippingRatePrice":0
 * }
 */
function dtb_veeqo_route_repair_request( WP_REST_Request $request ): WP_REST_Response {
	// ── Rate limit: 5 submissions per IP per hour ─────────────────────────────
	$ip      = dtb_get_client_ip();
	$rl_key  = 'dtb_repair_rl_' . md5( $ip );
	$rl_cnt  = (int) get_transient( $rl_key );
	if ( $rl_cnt >= 5 ) {
		$resp = new WP_REST_Response(
			dtb_error_envelope( 'rate_limited', 'Too many repair requests. Please try again in an hour.', 429 ),
			429
		);
		$resp->header( 'Retry-After', '3600' );
		return $resp;
	}
	set_transient( $rl_key, $rl_cnt + 1, HOUR_IN_SECONDS );

	$body = $request->get_json_params();
	if ( empty( $body ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'invalid_body', 'Request body must be valid JSON.', 400 ),
			400
		);
	}

	// ── Sanitise all inputs ───────────────────────────────────────────────────
	$full_name    = sanitize_text_field( $body['fullName']        ?? '' );
	$email        = sanitize_email(      $body['email']           ?? '' );
	$phone        = sanitize_text_field( $body['phone']           ?? '' );
	$company      = sanitize_text_field( $body['company']         ?? '' );
	$tool_brand   = sanitize_text_field( $body['toolBrand']       ?? '' );
	$tool_cat     = sanitize_text_field( $body['toolCategory']    ?? '' );
	$tool_model   = sanitize_text_field( $body['toolModel']       ?? '' );
	$serial       = sanitize_text_field( $body['serialNumber']    ?? '' );
	$tool_age     = sanitize_text_field( $body['toolAge']         ?? '' );
	$svc_type     = sanitize_text_field( $body['serviceType']     ?? '' );
	$priority     = sanitize_text_field( $body['priority']        ?? '' );
	$issue_start  = sanitize_text_field( $body['issueStart']      ?? '' );
	$issue_desc   = sanitize_textarea_field( $body['issueDescription'] ?? '' );
	$contact_pref = sanitize_text_field( $body['contactPreference'] ?? 'email' );
	$address      = sanitize_text_field( $body['address']         ?? '' );
	$city         = sanitize_text_field( $body['city']            ?? '' );
	$state        = sanitize_text_field( $body['state']           ?? '' );
	$zip          = sanitize_text_field( $body['zip']             ?? '' );
	$country      = strtoupper( sanitize_text_field( $body['country'] ?? 'US' ) );
	$rate_id      = sanitize_text_field( $body['shippingRateId']   ?? '' );
	$rate_name    = sanitize_text_field( $body['shippingRateName'] ?? '' );
	$rate_price   = (float) ( $body['shippingRatePrice'] ?? 0 );

	// ── Validate required fields ──────────────────────────────────────────────
	$required = compact( 'full_name', 'email', 'phone', 'tool_brand', 'svc_type', 'priority', 'issue_desc', 'address', 'city', 'state', 'zip' );
	foreach ( $required as $field => $value ) {
		if ( '' === trim( $value ) ) {
			return new WP_REST_Response(
				dtb_error_envelope( 'validation_error', sprintf( 'Field "%s" is required.', $field ), 422 ),
				422
			);
		}
	}

	if ( ! is_email( $email ) ) {
		return new WP_REST_Response(
			dtb_error_envelope( 'validation_error', 'A valid email address is required.', 422 ),
			422
		);
	}

	// ── Build tool description ────────────────────────────────────────────────
	$tool_parts = array_filter( [ $tool_brand, $tool_model ?: $tool_cat ] );
	$tool_desc  = implode( ' — ', $tool_parts );

	// ── Require WooCommerce ───────────────────────────────────────────────────
	if ( ! function_exists( 'wc_create_order' ) ) {
		dtb_veeqo_log( 'error', 'repair_wc_missing', 'WooCommerce not available for repair order creation.' );
		return new WP_REST_Response(
			dtb_error_envelope( 'wc_unavailable', 'Store not available. Please try again or call us directly.', 503 ),
			503
		);
	}

	// ── Create WooCommerce order ──────────────────────────────────────────────
	$wc_order = wc_create_order( [
		'status'        => 'pending',
		'customer_id'   => 0,
		'customer_note' => $issue_desc,
	] );

	if ( is_wp_error( $wc_order ) ) {
		dtb_veeqo_log( 'error', 'repair_order_failed', $wc_order->get_error_message() );
		return new WP_REST_Response(
			dtb_error_envelope( 'order_error', 'Could not create the service order. Please try again.', 500 ),
			500
		);
	}

	// Set billing address.
	$name_parts = explode( ' ', $full_name, 2 );
	$first_name = $name_parts[0] ?? '';
	$last_name  = $name_parts[1] ?? '';

	$wc_order->set_billing_first_name( $first_name );
	$wc_order->set_billing_last_name( $last_name );
	$wc_order->set_billing_company( $company );
	$wc_order->set_billing_email( $email );
	$wc_order->set_billing_phone( $phone );
	$wc_order->set_billing_address_1( $address );
	$wc_order->set_billing_city( $city );
	$wc_order->set_billing_state( $state );
	$wc_order->set_billing_postcode( $zip );
	$wc_order->set_billing_country( $country );

	// Set shipping address (same as billing — tool is shipped from and returned to this address).
	$wc_order->set_shipping_first_name( $first_name );
	$wc_order->set_shipping_last_name( $last_name );
	$wc_order->set_shipping_company( $company );
	$wc_order->set_shipping_address_1( $address );
	$wc_order->set_shipping_city( $city );
	$wc_order->set_shipping_state( $state );
	$wc_order->set_shipping_postcode( $zip );
	$wc_order->set_shipping_country( $country );

	// Add custom repair-service line item (no WC product required).
	$item = new WC_Order_Item_Fee();
	$item->set_name( sprintf( 'Repair Service — %s', $tool_desc ) );
	$item->set_amount( 0.00 );  // Quote pending; amount set after technician review.
	$item->set_total( 0.00 );
	$item->set_tax_status( 'none' );
	$wc_order->add_item( $item );

	// Add shipping line item when customer selected a rate.
	if ( '' !== $rate_id && $rate_price > 0 ) {
		$ship_item = new WC_Order_Item_Shipping();
		$ship_item->set_method_title( $rate_name ?: 'Shipping' );
		$ship_item->set_method_id( 'dtb_veeqo_rates' );
		$ship_item->set_instance_id( '0' );
		$ship_item->set_total( (string) $rate_price );
		$wc_order->add_item( $ship_item );
	}

	// Store all repair service details as order meta.
	$wc_order->update_meta_data( '_dtb_repair_tool_brand',     $tool_brand );
	$wc_order->update_meta_data( '_dtb_repair_tool_category',  $tool_cat );
	$wc_order->update_meta_data( '_dtb_repair_tool_model',     $tool_model );
	$wc_order->update_meta_data( '_dtb_repair_serial',         $serial );
	$wc_order->update_meta_data( '_dtb_repair_tool_age',       $tool_age );
	$wc_order->update_meta_data( '_dtb_repair_service_type',   $svc_type );
	$wc_order->update_meta_data( '_dtb_repair_priority',       $priority );
	$wc_order->update_meta_data( '_dtb_repair_issue_start',    $issue_start );
	$wc_order->update_meta_data( '_dtb_repair_contact_pref',   $contact_pref );
	$wc_order->update_meta_data( '_dtb_repair_shipping_rate',  $rate_id );
	$wc_order->update_meta_data( '_dtb_order_type',            'repair' );

	// Add a readable internal note.
	$wc_order->add_order_note( sprintf(
		"[Repair Service Request]\nTool: %s\nSerial: %s | Age: %s\nService: %s | Priority: %s\nIssue started: %s\nContact preference: %s\n\nDescription:\n%s",
		$tool_desc,
		$serial ?: 'N/A',
		$tool_age ?: 'Unknown',
		$svc_type,
		$priority,
		$issue_start ?: 'Not specified',
		$contact_pref,
		$issue_desc
	), false );

	// Recalculate totals and save.
	$wc_order->calculate_totals();
	$wc_order->save();

	$wc_order_id     = $wc_order->get_id();
	$wc_order_number = $wc_order->get_order_number();

	dtb_veeqo_log( 'info', 'repair_order_created', 'Repair service WC order created.', [
		'wc_order_id'     => $wc_order_id,
		'wc_order_number' => $wc_order_number,
		'tool'            => $tool_desc,
		'email_domain'    => substr( $email, strpos( $email, '@' ) ?: 0 ),
		'service_type'    => $svc_type,
		'priority'        => $priority,
	] );

	// Repair-service orders are a separate lifecycle domain and are not
	// eligible for the product fulfillment writer. They remain in WooCommerce
	// for the repair workflow; no direct Veeqo order writer runs here.
	if ( function_exists( 'dtb_order_update_integration_state' ) ) {
		dtb_order_update_integration_state( $wc_order_id, 'veeqo', [
			'status' => 'not_applicable',
			'error'  => 'Repair-service orders use the repair workflow, not product fulfillment synchronization.',
		] );
	}

	// ── Send customer confirmation email ──────────────────────────────────────
	dtb_veeqo_send_repair_confirmation( $wc_order, $tool_desc, $svc_type, $priority );

	return new WP_REST_Response( [
		'success'         => true,
		'wc_order_id'     => $wc_order_id,
		'wc_order_number' => $wc_order_number,
		'veeqo_order_id'  => $veeqo_order_id,
		'message'         => 'Your repair request has been received. We will contact you within one business day with a quote.',
	], 201 );
}

/**
 * Send the customer confirmation email for a repair request.
 *
 * @param WC_Order $order     The newly created WooCommerce order.
 * @param string   $tool_desc Human-readable tool description.
 * @param string   $svc_type  Service type string.
 * @param string   $priority  Priority string.
 */
function dtb_veeqo_send_repair_confirmation( WC_Order $order, string $tool_desc, string $svc_type, string $priority ): void {
	$to      = $order->get_billing_email();
	$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$order_n = $order->get_order_number();

	if ( empty( $to ) ) {
		return;
	}

	$subject = sprintf( '[Drywall Toolbox] Repair Request #%s Received', $order_n );

	$body = sprintf(
		"Hi %s,\n\nThank you for contacting Drywall Toolbox. We've received your repair request and will follow up within one business day.\n\nRequest Details:\n  Order #:      %s\n  Tool:         %s\n  Service Type: %s\n  Priority:     %s\n\nOur service team will review your request and send you a quote and estimated turnaround time.\n\nIf you have any questions, reply to this email or call us directly.\n\nThank you,\nDrywall Toolbox Service Team\n%s",
		$name,
		$order_n,
		$tool_desc,
		$svc_type,
		$priority,
		home_url( '/' )
	);

	$from_email = function_exists( 'dtb_platform_from_email' ) ? dtb_platform_from_email() : 'info@drywalltoolbox.com';
	$headers    = [
		'Content-Type: text/plain; charset=UTF-8',
		'From: Drywall Toolbox Service <' . $from_email . '>',
	];

	if ( function_exists( 'dtb_send_email' ) ) {
		dtb_send_email(
			[
				'to'           => (string) $to,
				'subject'      => $subject,
				'message'      => $body,
				'headers'      => $headers,
				'content_type' => 'text/plain',
				'context'      => [
					'module'        => 'dtb-integrations-veeqo',
					'event'         => 'repair-confirmation',
					'wc_order_id'   => (int) $order->get_id(),
					'wc_order_no'   => (string) $order_n,
				],
			]
		);
	} else {
		wp_mail(
			$to,
			$subject,
			$body,
			$headers
		);
	}

	// Also notify the admin.
	dtb_veeqo_send_alert(
		sprintf( 'New Repair Request #%s — %s', $order_n, $tool_desc ),
		sprintf(
			"New repair service request received.\n\nOrder #: %s\nCustomer: %s <%s>\nTool: %s\nService: %s\nPriority: %s\n\nView in WP Admin:\n%s",
			$order_n,
			$name,
			$order->get_billing_email(),
			$tool_desc,
			$svc_type,
			$priority,
			admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' )
		)
	);
}



// =============================================================================
// SECTION 12 — WOOCOMMERCE ADMIN SETTINGS INTEGRATION
//
// Registers a "Drywall Toolbox Veeqo" settings page under:
//   WooCommerce → Settings → Integrations → Drywall Toolbox Veeqo
//
// Admin-editable fields:
//   • API Key        — stored in woocommerce_dtb_veeqo_settings[api_key]
//   • Webhook Secret — stored in woocommerce_dtb_veeqo_settings[webhook_secret]
//
// Read-only display (auto-discovered on save):
//   • Channel ID   — populated from GET /channels (first Direct/Phone channel)
//   • Warehouse ID — populated from GET /warehouses (first warehouse)
//
// wp-config.php constants (DTB_VEEQO_*) still take precedence over stored
// values when defined.  See dtb_veeqo_config() for the full resolution order.
// =============================================================================

/**
 * Discover the Veeqo Direct/Phone channel_id (from GET /channels), warehouse_id
 * (from GET /warehouses), and delivery_method_id (from GET /delivery_methods)
 * using the currently-active API key, then persist the IDs to the
 * woocommerce_dtb_veeqo_settings wp_option.
 *
 * Should be called after the API key is saved so that the fresh key is used.
 * Invalidates the in-request config cache before and after the API calls.
 *
 * @return array{channel_id: int, warehouse_id: int, delivery_method_id: int, error: string}
 */
function dtb_veeqo_discover_ids(): array {
	// Clear any cached config so the latest API key (just saved) is used.
	unset( $GLOBALS['_dtb_veeqo_config'] );

	$opt                = (array) get_option( 'woocommerce_dtb_veeqo_settings', [] );
	$channel_id         = 0;
	$warehouse_id       = 0;
	$delivery_method_id = 0;
	$errors       = [];

	// ── Channel ID from GET /channels ────────────────────────────────────────
	// The correct Veeqo endpoint is /channels (GET /stores returns 404).
	// API-created orders must be created against a Direct/Phone channel.
	// WooCommerce channels are bridge-managed and Veeqo rejects API-created
	// orders with "The order is unable to be created with this channel type".
	$stores_result = dtb_veeqo_request( 'GET', '/channels' );
	if ( $stores_result['ok'] && is_array( $stores_result['data'] ) ) {
		$first_id       = 0;
		$woocommerce_id = 0;
		foreach ( $stores_result['data'] as $store ) {
			if ( ! isset( $store['id'] ) || (int) $store['id'] <= 0 ) {
				continue;
			}
			// Capture the very first valid id as a fallback.
			if ( 0 === $first_id ) {
				$first_id = (int) $store['id'];
			}
			if ( isset( $store['type_code'] ) && 'woocommerce' === $store['type_code'] && 0 === $woocommerce_id ) {
				$woocommerce_id = (int) $store['id'];
			}
			// Prefer the Direct/Phone channel required by Veeqo's order API.
			if ( isset( $store['type_code'] ) && 'direct' === $store['type_code'] ) {
				$channel_id = (int) $store['id'];
				break;
			}
		}
		// Use the fallback id only if no Direct channel was found.
		if ( 0 === $channel_id && $first_id > 0 ) {
			$channel_id = $first_id;
		}
		if ( 0 === $channel_id ) {
			$errors[] = 'GET /channels returned no channels.';
		} elseif ( $channel_id === $woocommerce_id ) {
			$errors[] = 'GET /channels returned no Direct/Phone channel; API order creation may fail.';
		}
	} else {
		$errors[] = 'GET /channels failed: ' . $stores_result['error'];
	}

	// ── Warehouse ID from GET /warehouses ─────────────────────────────────────
	$warehouses_result = dtb_veeqo_request( 'GET', '/warehouses' );
	if ( $warehouses_result['ok'] && is_array( $warehouses_result['data'] ) ) {
		foreach ( $warehouses_result['data'] as $warehouse ) {
			if ( isset( $warehouse['id'] ) && (int) $warehouse['id'] > 0 ) {
				$warehouse_id = (int) $warehouse['id'];
				break;
			}
		}
		if ( 0 === $warehouse_id ) {
			$errors[] = 'GET /warehouses returned no warehouses.';
		}
	} else {
		$errors[] = 'GET /warehouses failed: ' . $warehouses_result['error'];
	}

	// -- Delivery Method ID from GET /delivery_methods ------------------------
	$delivery_methods_result = dtb_veeqo_request( 'GET', '/delivery_methods' );
	if ( $delivery_methods_result['ok'] && is_array( $delivery_methods_result['data'] ) ) {
		foreach ( $delivery_methods_result['data'] as $method ) {
			if ( isset( $method['id'] ) && (int) $method['id'] > 0 ) {
				$delivery_method_id = (int) $method['id'];
				break;
			}
		}
		if ( 0 === $delivery_method_id ) {
			$errors[] = 'GET /delivery_methods returned no delivery methods.';
		}
	} else {
		$errors[] = 'GET /delivery_methods failed: ' . $delivery_methods_result['error'];
	}

	if ( 0 === $channel_id && ! empty( $opt['channel_id'] ) ) {
		$channel_id = (int) $opt['channel_id'];
	}
	if ( 0 === $warehouse_id && ! empty( $opt['warehouse_id'] ) ) {
		$warehouse_id = (int) $opt['warehouse_id'];
	}
	if ( 0 === $delivery_method_id && ! empty( $opt['delivery_method_id'] ) ) {
		$delivery_method_id = (int) $opt['delivery_method_id'];
	}

	// ── Persist discovered IDs to wp_options ──────────────────────────────────
	$opt['channel_id']         = $channel_id;
	$opt['warehouse_id']       = $warehouse_id;
	$opt['delivery_method_id'] = $delivery_method_id;
	update_option( 'woocommerce_dtb_veeqo_settings', $opt );

	// Invalidate the cached config so callers within this request use new IDs.
	unset( $GLOBALS['_dtb_veeqo_config'] );

	$error_string = implode( ' ', $errors );

	dtb_veeqo_log(
		'' === $error_string ? 'info' : 'warn',
		'ids_discovered',
		'Veeqo channel_id, warehouse_id, and delivery_method_id auto-discovery completed.',
		[
			'channel_id'         => $channel_id,
			'warehouse_id'       => $warehouse_id,
			'delivery_method_id' => $delivery_method_id,
			'errors'             => $errors,
		]
	);

	return [
		'channel_id'         => $channel_id,
		'warehouse_id'       => $warehouse_id,
		'delivery_method_id' => $delivery_method_id,
		'error'              => $error_string,
	];
}

/**
 * Veeqo settings (API key, webhook secret, delivery method ID, and the
 * auto-discovered channel_id/warehouse_id) are configured from System
 * Manager -> Integration Settings (dtb-platform/SystemManager/IntegrationSettingsTab.php
 * + Rest/IntegrationSettingsController.php), which reads and writes this
 * same `woocommerce_dtb_veeqo_settings` option directly and calls
 * dtb_veeqo_discover_ids() after saving -- not through a WooCommerce
 * Settings -> Integrations screen.
 */
