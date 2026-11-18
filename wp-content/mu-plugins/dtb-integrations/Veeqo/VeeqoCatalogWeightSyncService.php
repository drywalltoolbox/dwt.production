<?php
/**
 * WooCommerce -> Veeqo product weight projection.
 *
 * WooCommerce (the "official catalog") is authoritative for physical product
 * weight. Veeqo's public API only exposes `weight_grams` as a writable field
 * on `PUT /products/{id}` (`product_variants_attributes[].weight_grams`) —
 * physical dimensions (`measurement_attributes`: width/height/depth) are
 * read-only via the API and stay a manual Veeqo-dashboard/CSV-import concern
 * (see products/launch/official/veeqo_inventory_import_optimized.csv and
 * docs/reference/data/tapetech-upc-codes-weights-dimensions.csv). This service
 * therefore only ever pushes weight, never dimensions.
 *
 * Mirrors the reconciliation architecture of VeeqoInventoryProjectionServiceV3.php
 * (paginated, locked, chunked, Action-Scheduler-driven, retry with backoff) but
 * runs in the opposite direction: WooCommerce -> Veeqo instead of Veeqo -> WooCommerce.
 *
 * Physical weight does not drift on its own the way stock levels do, so unlike
 * the inventory projection this is NOT a frequent-polling job. The on-save hook
 * below is the primary mechanism — it pushes the instant an operator changes a
 * product's weight in wp-admin. The recurring pass here exists only as an
 * infrequent safety net for paths that bypass that hook entirely: bulk
 * imports/direct DB writes that skip `woocommerce_update_product`, a dropped
 * async single-product push, or a SKU getting mapped to Veeqo for the first
 * time. A daily cadence is more than enough for that.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_CATALOG_SYNC_HOOK            = 'dtb_veeqo_catalog_weight_sync';
const DTB_VEEQO_CATALOG_SYNC_ACTION_GROUP    = 'dtb-integrations';
const DTB_VEEQO_CATALOG_SYNC_DIAGNOSTICS     = 'dtb_veeqo_catalog_weight_sync_diagnostics';
const DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION     = 'dtb_veeqo_catalog_weight_sync_lock';
const DTB_VEEQO_CATALOG_SYNC_MAX_RETRIES     = 3;
const DTB_VEEQO_CATALOG_SYNC_PAGE_SIZE       = 100;
const DTB_VEEQO_CATALOG_SYNC_LOCK_TTL        = 20 * MINUTE_IN_SECONDS;
const DTB_VEEQO_CATALOG_SYNC_RUN_SECONDS     = 90;
const DTB_VEEQO_CATALOG_SYNC_RUN_PAGES       = 25;
const DTB_VEEQO_CATALOG_SYNC_PAGE_LIMIT      = 1000;
// Ignore sub-gram drift so float rounding never triggers a no-op API call.
const DTB_VEEQO_CATALOG_SYNC_WEIGHT_EPSILON_G = 1.0;

function dtb_veeqo_catalog_sync_readiness(): array {
	$config  = function_exists( 'dtb_veeqo_config' ) ? dtb_veeqo_config() : [];
	$missing = [];

	if ( empty( $config['api_key'] ) ) {
		$missing[] = 'api_key';
	}

	return [ 'ready' => empty( $missing ), 'missing' => $missing ];
}

/**
 * Convert a WooCommerce product's weight (in the store's configured unit)
 * to grams. Returns null when the product carries no weight at all, so
 * callers can distinguish "unset" from "zero" rather than silently pushing 0.
 */
function dtb_veeqo_catalog_sync_weight_grams( WC_Product $product ): ?float {
	$raw = $product->get_weight();
	if ( '' === $raw || null === $raw ) {
		return null;
	}

	$value = (float) $raw;
	if ( $value <= 0.0 ) {
		return null;
	}

	switch ( get_option( 'woocommerce_weight_unit', 'lbs' ) ) {
		case 'g':
			return $value;
		case 'kg':
			return $value * 1000.0;
		case 'oz':
			return $value * 28.349523125;
		case 'lbs':
		default:
			return $value * 453.59237;
	}
}

// ---- Lock helpers (mirrors VeeqoInventoryProjectionServiceV3's option-based lease lock). ----

function dtb_veeqo_catalog_sync_delete_lock_value( string $current_raw ): bool {
	global $wpdb;

	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION,
			$current_raw
		)
	);
	wp_cache_delete( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, 'options' );

	return 1 === $deleted;
}

function dtb_veeqo_catalog_sync_acquire_lock( int $ttl = DTB_VEEQO_CATALOG_SYNC_LOCK_TTL ): string {
	$token = wp_generate_uuid4();
	$value = wp_json_encode( [ 'token' => $token, 'expires_at' => time() + max( 60, $ttl ) ] );

	if ( add_option( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, $value, '', 'no' ) ) {
		return $token;
	}

	$current_raw = (string) get_option( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, '' );
	$current     = json_decode( $current_raw, true );
	$valid       = is_array( $current )
		&& is_string( $current['token'] ?? null )
		&& '' !== (string) $current['token']
		&& is_numeric( $current['expires_at'] ?? null );

	if ( $valid && (int) $current['expires_at'] >= time() ) {
		return '';
	}

	if ( ! dtb_veeqo_catalog_sync_delete_lock_value( $current_raw ) ) {
		return '';
	}

	return add_option( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, $value, '', 'no' ) ? $token : '';
}

function dtb_veeqo_catalog_sync_refresh_lock( string $token, int $ttl = DTB_VEEQO_CATALOG_SYNC_LOCK_TTL ): bool {
	if ( '' === $token ) {
		return false;
	}

	$current_raw = (string) get_option( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, '' );
	$current     = json_decode( $current_raw, true );
	if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
		return false;
	}

	$expires_at = time() + max( 60, $ttl );
	$new_raw    = wp_json_encode( [ 'token' => $token, 'expires_at' => $expires_at ] );

	if ( $new_raw === $current_raw && (int) ( $current['expires_at'] ?? 0 ) >= time() ) {
		return true;
	}

	global $wpdb;
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new_raw,
			DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION,
			$current_raw
		)
	);
	wp_cache_delete( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, 'options' );

	if ( 1 === $updated ) {
		return true;
	}

	$stored = json_decode( (string) get_option( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, '' ), true );
	return is_array( $stored )
		&& hash_equals( (string) ( $stored['token'] ?? '' ), $token )
		&& (int) ( $stored['expires_at'] ?? 0 ) >= time();
}

function dtb_veeqo_catalog_sync_release_lock( string $token ): void {
	$current_raw = (string) get_option( DTB_VEEQO_CATALOG_SYNC_LOCK_OPTION, '' );
	$current     = json_decode( $current_raw, true );

	if ( '' === $token || ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
		return;
	}

	dtb_veeqo_catalog_sync_delete_lock_value( $current_raw );
}

/**
 * Push queued per-product weight changes to Veeqo, one PUT per Veeqo parent
 * product (batching all changed variants for that product into a single
 * request rather than one call per variant).
 *
 * @param array<int,array<int,array{id:int,weight_grams:float}>> $changes_by_veeqo_product_id
 * @return array{pushed:int,failed:array<int,string>}
 */
function dtb_veeqo_catalog_sync_push_changes( array $changes_by_veeqo_product_id ): array {
	$pushed = 0;
	$failed = [];

	foreach ( $changes_by_veeqo_product_id as $veeqo_product_id => $variants ) {
		if ( empty( $variants ) ) {
			continue;
		}

		$result = dtb_veeqo_request(
			'PUT',
			'/products/' . absint( $veeqo_product_id ),
			[],
			[ 'product' => [ 'product_variants_attributes' => array_values( $variants ) ] ]
		);

		if ( empty( $result['ok'] ) ) {
			$failed[ $veeqo_product_id ] = sanitize_text_field( (string) ( $result['error'] ?? 'Veeqo product update failed.' ) );
			dtb_veeqo_log( 'error', 'catalog_weight_push_failed', 'Failed to push weight to Veeqo product.', [
				'veeqo_product_id' => $veeqo_product_id,
				'variants'         => $variants,
				'error'            => $failed[ $veeqo_product_id ],
			] );
			continue;
		}

		$pushed += count( $variants );
	}

	return [ 'pushed' => $pushed, 'failed' => $failed ];
}

function dtb_veeqo_catalog_sync_reconcile_page( int $page = 1, int $per_page = DTB_VEEQO_CATALOG_SYNC_PAGE_SIZE, bool $dry_run = false ) {
	$readiness = dtb_veeqo_catalog_sync_readiness();
	if ( empty( $readiness['ready'] ) ) {
		return new WP_Error( 'veeqo_catalog_sync_not_ready', 'Veeqo catalog weight sync is not configured.', [ 'status' => 503, 'missing' => $readiness['missing'] ] );
	}

	if ( ! function_exists( 'dtb_veeqo_request' ) || ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error( 'veeqo_client_unavailable', 'Veeqo client or WooCommerce is unavailable.', [ 'status' => 503 ] );
	}

	$result = dtb_veeqo_request( 'GET', '/products', [
		'page'      => (string) max( 1, $page ),
		'page_size' => (string) min( DTB_VEEQO_CATALOG_SYNC_PAGE_SIZE, max( 1, $per_page ) ),
	] );

	if ( empty( $result['ok'] ) || ! is_array( $result['data'] ?? null ) ) {
		return new WP_Error( 'veeqo_catalog_fetch_failed', sanitize_text_field( (string) ( $result['error'] ?? 'Veeqo product fetch failed.' ) ), [ 'status' => (int) ( $result['status'] ?? 502 ) ] );
	}

	$report = [
		'page'              => max( 1, $page ),
		'products_received' => count( $result['data'] ),
		'sellables_seen'    => 0,
		'updated'           => 0,
		'unchanged'         => 0,
		'mapped'            => 0,
		'unmapped_skus'     => [],
		'duplicate_skus'    => [],
		'push_failures'     => [],
	];

	$page_skus = [];
	foreach ( $result['data'] as $veeqo_product ) {
		if ( ! is_array( $veeqo_product ) ) {
			continue;
		}
		foreach ( (array) ( $veeqo_product['sellables'] ?? [] ) as $sellable ) {
			if ( is_array( $sellable ) && '' !== trim( (string) ( $sellable['sku_code'] ?? '' ) ) ) {
				$page_skus[] = trim( sanitize_text_field( (string) $sellable['sku_code'] ) );
			}
		}
	}

	$woo_ids_by_sku = dtb_veeqo_inventory_woo_ids_for_skus( $page_skus );
	$changes        = [];

	foreach ( $result['data'] as $veeqo_product ) {
		if ( ! is_array( $veeqo_product ) ) {
			continue;
		}

		$veeqo_product_id = absint( $veeqo_product['id'] ?? 0 );

		foreach ( (array) ( $veeqo_product['sellables'] ?? [] ) as $sellable ) {
			if ( ! is_array( $sellable ) ) {
				continue;
			}

			$report['sellables_seen']++;
			$sku = trim( sanitize_text_field( (string) ( $sellable['sku_code'] ?? '' ) ) );
			if ( '' === $sku || $veeqo_product_id <= 0 ) {
				continue;
			}

			$woo_ids = (array) ( $woo_ids_by_sku[ $sku ] ?? [] );
			if ( count( $woo_ids ) > 1 ) {
				$report['duplicate_skus'][ $sku ] = $woo_ids;
				continue;
			}

			$product_id = absint( $woo_ids[0] ?? 0 );
			if ( $product_id <= 0 ) {
				$report['unmapped_skus'][] = $sku;
				continue;
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof WC_Product || trim( (string) $product->get_sku() ) !== $sku || ! in_array( $product->get_type(), [ 'simple', 'variation' ], true ) ) {
				$report['unmapped_skus'][] = $sku;
				continue;
			}

			$woo_weight_g = dtb_veeqo_catalog_sync_weight_grams( $product );
			if ( null === $woo_weight_g ) {
				// WooCommerce has no weight on file yet; nothing authoritative to push.
				continue;
			}

			$sellable_id = absint( $sellable['id'] ?? 0 );
			$needs_meta  = $sellable_id > 0 && (
				absint( $product->get_meta( '_veeqo_sellable_id', true ) ) !== $sellable_id
				|| absint( $product->get_meta( '_veeqo_product_id', true ) ) !== $veeqo_product_id
			);

			$veeqo_weight_g = is_numeric( $sellable['weight_grams'] ?? null ) ? (float) $sellable['weight_grams'] : null;
			$needs_push     = $sellable_id > 0 && (
				null === $veeqo_weight_g
				|| abs( $veeqo_weight_g - $woo_weight_g ) > DTB_VEEQO_CATALOG_SYNC_WEIGHT_EPSILON_G
			);

			if ( $needs_meta ) {
				$report['mapped']++;
				if ( ! $dry_run ) {
					$product->update_meta_data( '_veeqo_sellable_id', $sellable_id );
					$product->update_meta_data( '_veeqo_product_id', $veeqo_product_id );
					$product->save();
				}
			}

			if ( ! $needs_push ) {
				$report['unchanged']++;
				continue;
			}

			$report['updated']++;
			if ( ! $dry_run ) {
				$changes[ $veeqo_product_id ][] = [ 'id' => $sellable_id, 'weight_grams' => round( $woo_weight_g, 1 ) ];
			}
		}
	}

	if ( ! $dry_run && ! empty( $changes ) ) {
		$push_result            = dtb_veeqo_catalog_sync_push_changes( $changes );
		$report['push_failures'] = $push_result['failed'];
	}

	foreach ( [ 'unmapped_skus' ] as $key ) {
		$report[ $key ] = array_values( array_unique( $report[ $key ] ) );
	}

	return $report;
}

function dtb_veeqo_catalog_sync_empty_aggregate( bool $dry_run ): array {
	return [
		'pages'          => 0,
		'sellables_seen' => 0,
		'updated'        => 0,
		'unchanged'      => 0,
		'mapped'         => 0,
		'unmapped_skus'  => [],
		'duplicate_skus' => [],
		'push_failures'  => [],
		'dry_run'        => $dry_run,
		'started_at'     => gmdate( 'c' ),
	];
}

function dtb_veeqo_catalog_sync_merge_report( array $aggregate, array $report ): array {
	$aggregate['pages']++;
	foreach ( [ 'sellables_seen', 'updated', 'unchanged', 'mapped' ] as $key ) {
		$aggregate[ $key ] += (int) ( $report[ $key ] ?? 0 );
	}

	$aggregate['unmapped_skus'] = array_values( array_unique( array_merge( (array) $aggregate['unmapped_skus'], (array) ( $report['unmapped_skus'] ?? [] ) ) ) );
	$aggregate['duplicate_skus'] = array_replace( (array) $aggregate['duplicate_skus'], (array) ( $report['duplicate_skus'] ?? [] ) );
	$aggregate['push_failures']  = array_replace( (array) $aggregate['push_failures'], (array) ( $report['push_failures'] ?? [] ) );

	return $aggregate;
}

/** Process one bounded chunk. This function never schedules its own continuation. */
function dtb_veeqo_catalog_sync_reconcile_chunk( bool $dry_run = false, int $start_page = 1, array $aggregate = [] ) {
	$token = dtb_veeqo_catalog_sync_acquire_lock();
	if ( '' === $token ) {
		return new WP_Error( 'veeqo_catalog_sync_locked', 'A Veeqo catalog weight sync is already running.', [ 'status' => 409 ] );
	}

	if ( empty( $aggregate ) ) {
		$aggregate = dtb_veeqo_catalog_sync_empty_aggregate( $dry_run );
	}
	$aggregate['dry_run'] = $dry_run;

	$run_started = microtime( true );
	$processed    = 0;

	try {
		for ( $page = max( 1, $start_page ); $page <= DTB_VEEQO_CATALOG_SYNC_PAGE_LIMIT; $page++ ) {
			if ( ! dtb_veeqo_catalog_sync_refresh_lock( $token ) ) {
				return new WP_Error( 'veeqo_catalog_sync_lock_lost', 'Veeqo catalog weight sync lost lock ownership.', [ 'status' => 409 ] );
			}

			$report = dtb_veeqo_catalog_sync_reconcile_page( $page, DTB_VEEQO_CATALOG_SYNC_PAGE_SIZE, $dry_run );
			if ( is_wp_error( $report ) ) {
				return $report;
			}

			$aggregate = dtb_veeqo_catalog_sync_merge_report( $aggregate, $report );
			$processed++;

			if ( (int) $report['products_received'] < DTB_VEEQO_CATALOG_SYNC_PAGE_SIZE ) {
				$aggregate['completed_at'] = gmdate( 'c' );
				$aggregate['partial']      = false;
				unset( $aggregate['next_page'], $aggregate['paused_at'] );
				update_option( DTB_VEEQO_CATALOG_SYNC_DIAGNOSTICS, $aggregate, false );

				if ( class_exists( 'DTB_VeeqoSyncJob' ) && ! $dry_run ) {
					DTB_VeeqoSyncJob::log_timestamp( 'catalog_weight' );
				}

				return $aggregate;
			}

			if ( $processed >= DTB_VEEQO_CATALOG_SYNC_RUN_PAGES || microtime( true ) - $run_started >= DTB_VEEQO_CATALOG_SYNC_RUN_SECONDS ) {
				$aggregate['partial']   = true;
				$aggregate['next_page'] = $page + 1;
				$aggregate['paused_at'] = gmdate( 'c' );
				update_option( DTB_VEEQO_CATALOG_SYNC_DIAGNOSTICS, $aggregate, false );
				return $aggregate;
			}
		}

		return new WP_Error( 'veeqo_catalog_sync_page_limit', 'Veeqo catalog weight sync exceeded the page safety limit.', [ 'status' => 500 ] );
	} finally {
		dtb_veeqo_catalog_sync_release_lock( $token );
	}
}

/** Compatibility wrapper for scheduled and system callers. */
function dtb_veeqo_catalog_sync_reconcile_all( bool $dry_run = false, int $start_page = 1, array $aggregate = [] ) {
	$result = dtb_veeqo_catalog_sync_reconcile_chunk( $dry_run, $start_page, $aggregate );
	if ( ! is_wp_error( $result ) && ! empty( $result['partial'] ) && function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action(
			DTB_VEEQO_CATALOG_SYNC_HOOK,
			[ 0, max( 1, absint( $result['next_page'] ?? 1 ) ), $result, $dry_run ],
			DTB_VEEQO_CATALOG_SYNC_ACTION_GROUP,
			true
		);
	}

	return $result;
}

// No recurring schedule. Weight/dimensions are physical constants per SKU —
// there is nothing to poll for on a timer. The on-save hook below is the only
// thing that runs by itself, and only when a weight actually changes. The
// full-catalog reconcile is triggered exactly twice in this integration's
// life: once as the initial backfill, and again any time you do a bulk
// import that bypasses `woocommerce_update_product`. It's exposed as an
// on-demand REST action rather than a cron job.
add_action( DTB_VEEQO_CATALOG_SYNC_HOOK, 'dtb_veeqo_catalog_sync_scheduled_run', 10, 4 );

// ---- On-demand trigger (wp-admin action or manual REST call) — replaces the
// recurring cron. Runs one bounded chunk synchronously and self-chains through
// remaining pages in the background via Action Scheduler when the catalog is
// larger than one chunk, so a manual "run the backfill" click never times out
// the HTTP request. ----

add_action( 'rest_api_init', 'dtb_veeqo_catalog_sync_register_routes' );

function dtb_veeqo_catalog_sync_register_routes(): void {
	register_rest_route( 'dtb/v1', '/veeqo/catalog-weight/run', [
		'methods'             => 'POST',
		'callback'            => 'dtb_veeqo_catalog_sync_route_run',
		'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
		'args'                => [
			'dry_run' => [ 'type' => 'boolean', 'default' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ],
		],
	] );

	register_rest_route( 'dtb/v1', '/veeqo/catalog-weight/status', [
		'methods'             => 'GET',
		'callback'            => 'dtb_veeqo_catalog_sync_route_status',
		'permission_callback' => static fn(): bool => current_user_can( 'manage_woocommerce' ),
	] );
}

function dtb_veeqo_catalog_sync_route_run( WP_REST_Request $request ): WP_REST_Response {
	$dry_run = rest_sanitize_boolean( $request->get_param( 'dry_run' ) );
	$result  = dtb_veeqo_catalog_sync_reconcile_all( $dry_run );

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( [ 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ], (int) ( $result->get_error_data()['status'] ?? 500 ) );
	}

	if ( function_exists( 'dtb_veeqo_log' ) ) {
		dtb_veeqo_log( 'info', 'catalog_weight_sync_requested', 'Operator requested an on-demand Veeqo catalog weight sync.', [
			'operator_id' => get_current_user_id(),
			'dry_run'     => $dry_run,
		] );
	}

	return new WP_REST_Response( $result, 200 );
}

function dtb_veeqo_catalog_sync_route_status(): WP_REST_Response {
	return new WP_REST_Response( [
		'last_run_at' => class_exists( 'DTB_VeeqoSyncJob' ) ? DTB_VeeqoSyncJob::last_timestamp( 'catalog_weight' ) : 0,
		'diagnostics' => dtb_veeqo_catalog_sync_diagnostics(),
	], 200 );
}

function dtb_veeqo_catalog_sync_scheduled_run( int $attempt = 0, int $start_page = 1, array $aggregate = [], bool $dry_run = false ): void {
	$result = dtb_veeqo_catalog_sync_reconcile_all( $dry_run, $start_page, $aggregate );
	if ( ! is_wp_error( $result ) ) {
		return;
	}

	$data      = $result->get_error_data();
	$status    = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	$retryable = 0 === $status || 408 === $status || 425 === $status || 429 === $status || $status >= 500;

	if ( $retryable && $attempt < DTB_VEEQO_CATALOG_SYNC_MAX_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
		$delay = min( HOUR_IN_SECONDS, ( 2 ** $attempt ) * 5 * MINUTE_IN_SECONDS );
		as_schedule_single_action(
			time() + $delay,
			DTB_VEEQO_CATALOG_SYNC_HOOK,
			[ $attempt + 1, $start_page, $aggregate, $dry_run ],
			DTB_VEEQO_CATALOG_SYNC_ACTION_GROUP,
			true
		);
	}

	if ( function_exists( 'dtb_veeqo_log' ) ) {
		dtb_veeqo_log( 'error', 'catalog_weight_sync_failed', 'Veeqo catalog weight sync failed.', [
			'attempt'   => $attempt,
			'retryable' => $retryable,
			'status'    => $status,
			'error'     => sanitize_text_field( $result->get_error_message() ),
		] );
	}
}

// =============================================================================
// Immediate single-product push on manual save — closes the gap between the
// hourly reconciliation pass and an operator editing a weight in wp-admin.
// Best-effort only: failures here never block the WooCommerce product save,
// and are picked up regardless by the next scheduled reconciliation.
// =============================================================================

add_action( 'woocommerce_update_product', 'dtb_veeqo_catalog_sync_on_product_save', 20, 1 );

function dtb_veeqo_catalog_sync_on_product_save( int $product_id ): void {
	if ( empty( dtb_veeqo_catalog_sync_readiness()['ready'] ) ) {
		return;
	}

	if ( function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action( 'dtb_veeqo_catalog_weight_sync_single', [ $product_id ], DTB_VEEQO_CATALOG_SYNC_ACTION_GROUP, true );
		return;
	}

	dtb_veeqo_catalog_sync_push_single_product( $product_id );
}

add_action( 'dtb_veeqo_catalog_weight_sync_single', 'dtb_veeqo_catalog_sync_push_single_product', 10, 1 );

function dtb_veeqo_catalog_sync_push_single_product( int $product_id ): void {
	if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'dtb_veeqo_request' ) ) {
		return;
	}

	$product = wc_get_product( $product_id );
	if ( ! $product instanceof WC_Product || ! in_array( $product->get_type(), [ 'simple', 'variation' ], true ) ) {
		return;
	}

	$sku = trim( (string) $product->get_sku() );
	if ( '' === $sku ) {
		return;
	}

	$woo_weight_g = dtb_veeqo_catalog_sync_weight_grams( $product );
	if ( null === $woo_weight_g ) {
		return;
	}

	// Veeqo's SKU search endpoint (`GET /products?query=`) matches by name or
	// SKU code substring, so the returned sellable list is filtered exactly
	// against this product's SKU before anything is trusted or pushed.
	$result = dtb_veeqo_request( 'GET', '/products', [ 'query' => $sku, 'page_size' => '10' ] );
	if ( empty( $result['ok'] ) || ! is_array( $result['data'] ?? null ) ) {
		return;
	}

	foreach ( $result['data'] as $veeqo_product ) {
		if ( ! is_array( $veeqo_product ) ) {
			continue;
		}

		$veeqo_product_id = absint( $veeqo_product['id'] ?? 0 );

		foreach ( (array) ( $veeqo_product['sellables'] ?? [] ) as $sellable ) {
			if ( ! is_array( $sellable ) || trim( (string) ( $sellable['sku_code'] ?? '' ) ) !== $sku ) {
				continue;
			}

			$sellable_id    = absint( $sellable['id'] ?? 0 );
			$veeqo_weight_g = is_numeric( $sellable['weight_grams'] ?? null ) ? (float) $sellable['weight_grams'] : null;

			if ( $sellable_id <= 0 || $veeqo_product_id <= 0 ) {
				continue;
			}

			if ( absint( $product->get_meta( '_veeqo_sellable_id', true ) ) !== $sellable_id
				|| absint( $product->get_meta( '_veeqo_product_id', true ) ) !== $veeqo_product_id ) {
				$product->update_meta_data( '_veeqo_sellable_id', $sellable_id );
				$product->update_meta_data( '_veeqo_product_id', $veeqo_product_id );
				$product->save();
			}

			if ( null !== $veeqo_weight_g && abs( $veeqo_weight_g - $woo_weight_g ) <= DTB_VEEQO_CATALOG_SYNC_WEIGHT_EPSILON_G ) {
				return;
			}

			dtb_veeqo_catalog_sync_push_changes( [
				$veeqo_product_id => [ [ 'id' => $sellable_id, 'weight_grams' => round( $woo_weight_g, 1 ) ] ],
			] );

			return;
		}
	}
}

/**
 * Return the last stored diagnostics snapshot for admin/read-model consumers.
 *
 * @return array<string,mixed>
 */
function dtb_veeqo_catalog_sync_diagnostics(): array {
	return (array) get_option( DTB_VEEQO_CATALOG_SYNC_DIAGNOSTICS, [] );
}
