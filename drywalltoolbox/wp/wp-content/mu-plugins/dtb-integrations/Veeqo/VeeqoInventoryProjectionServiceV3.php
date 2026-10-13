<?php
/**
 * Canonical Veeqo -> WooCommerce inventory projection.
 *
 * Veeqo owns warehouse availability. WooCommerce stores the checkout-facing
 * projection. Missing or invalid warehouse data is reported and skipped; it
 * is never coerced to zero.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_INVENTORY_RECONCILE_HOOK   = 'dtb_veeqo_inventory_reconcile';
const DTB_VEEQO_INVENTORY_ACTION_GROUP     = 'dtb-integrations';
const DTB_VEEQO_INVENTORY_DIAGNOSTICS      = 'dtb_veeqo_inventory_reconciliation_diagnostics';
const DTB_VEEQO_INVENTORY_LOCK_OPTION      = 'dtb_veeqo_inventory_reconciliation_lock';
const DTB_VEEQO_INVENTORY_INTERVAL_SECONDS = 15 * MINUTE_IN_SECONDS;
const DTB_VEEQO_INVENTORY_MAX_RETRIES      = 3;
const DTB_VEEQO_INVENTORY_PAGE_SIZE        = 100;
const DTB_VEEQO_INVENTORY_LOCK_TTL         = 20 * MINUTE_IN_SECONDS;
const DTB_VEEQO_INVENTORY_RUN_SECONDS      = 90;
const DTB_VEEQO_INVENTORY_RUN_PAGES        = 25;
const DTB_VEEQO_INVENTORY_PAGE_LIMIT       = 1000;

function dtb_veeqo_inventory_readiness(): array {
	$config  = function_exists( 'dtb_veeqo_config' ) ? dtb_veeqo_config() : [];
	$missing = [];

	if ( empty( $config['api_key'] ) ) {
		$missing[] = 'api_key';
	}

	$warehouse_id = absint( $config['warehouse_id'] ?? 0 );
	if ( $warehouse_id <= 0 ) {
		$missing[] = 'warehouse_id';
	}

	return [
		'ready'        => empty( $missing ),
		'missing'      => $missing,
		'warehouse_id' => $warehouse_id,
	];
}

function dtb_veeqo_inventory_delete_lock_value( string $current_raw ): bool {
	global $wpdb;

	$deleted = $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
			DTB_VEEQO_INVENTORY_LOCK_OPTION,
			$current_raw
		)
	);
	wp_cache_delete( DTB_VEEQO_INVENTORY_LOCK_OPTION, 'options' );

	return 1 === $deleted;
}

function dtb_veeqo_inventory_acquire_lock( int $ttl = DTB_VEEQO_INVENTORY_LOCK_TTL ): string {
	$token = wp_generate_uuid4();
	$value = wp_json_encode(
		[
			'token'      => $token,
			'expires_at' => time() + max( 60, $ttl ),
		]
	);

	if ( add_option( DTB_VEEQO_INVENTORY_LOCK_OPTION, $value, '', 'no' ) ) {
		return $token;
	}

	$current_raw = (string) get_option( DTB_VEEQO_INVENTORY_LOCK_OPTION, '' );
	$current     = json_decode( $current_raw, true );
	$valid       = is_array( $current )
		&& is_string( $current['token'] ?? null )
		&& '' !== (string) $current['token']
		&& is_numeric( $current['expires_at'] ?? null );

	if ( $valid && (int) $current['expires_at'] >= time() ) {
		return '';
	}

	if ( ! dtb_veeqo_inventory_delete_lock_value( $current_raw ) ) {
		return '';
	}

	return add_option( DTB_VEEQO_INVENTORY_LOCK_OPTION, $value, '', 'no' ) ? $token : '';
}

/**
 * Refresh a token-owned inventory lease.
 *
 * MySQL reports zero affected rows when an UPDATE writes the same value. The
 * worker refreshes immediately after acquisition, so the computed expiry can
 * be identical within the same second. An unchanged, unexpired lease owned by
 * the same token is therefore success rather than loss of ownership.
 */
function dtb_veeqo_inventory_refresh_lock( string $token, int $ttl = DTB_VEEQO_INVENTORY_LOCK_TTL ): bool {
	if ( '' === $token ) {
		return false;
	}

	$current_raw = (string) get_option( DTB_VEEQO_INVENTORY_LOCK_OPTION, '' );
	$current     = json_decode( $current_raw, true );
	if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
		return false;
	}

	$expires_at = time() + max( 60, $ttl );
	$new_raw    = wp_json_encode(
		[
			'token'      => $token,
			'expires_at' => $expires_at,
		]
	);

	if ( $new_raw === $current_raw && (int) ( $current['expires_at'] ?? 0 ) >= time() ) {
		return true;
	}

	global $wpdb;
	$updated = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
			$new_raw,
			DTB_VEEQO_INVENTORY_LOCK_OPTION,
			$current_raw
		)
	);
	wp_cache_delete( DTB_VEEQO_INVENTORY_LOCK_OPTION, 'options' );

	if ( 1 === $updated ) {
		return true;
	}

	$stored = json_decode( (string) get_option( DTB_VEEQO_INVENTORY_LOCK_OPTION, '' ), true );
	return is_array( $stored )
		&& hash_equals( (string) ( $stored['token'] ?? '' ), $token )
		&& (int) ( $stored['expires_at'] ?? 0 ) >= time();
}

function dtb_veeqo_inventory_release_lock( string $token ): void {
	$current_raw = (string) get_option( DTB_VEEQO_INVENTORY_LOCK_OPTION, '' );
	$current     = json_decode( $current_raw, true );

	if ( '' === $token || ! is_array( $current ) || ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
		return;
	}

	dtb_veeqo_inventory_delete_lock_value( $current_raw );
}

function dtb_veeqo_inventory_stock_entry_for_warehouse( array $sellable, int $warehouse_id ): ?array {
	foreach ( (array) ( $sellable['stock_entries'] ?? [] ) as $entry ) {
		if ( ! is_array( $entry ) ) {
			continue;
		}

		if ( absint( $entry['warehouse_id'] ?? ( $entry['warehouse']['id'] ?? 0 ) ) === $warehouse_id ) {
			return $entry;
		}
	}

	return null;
}

function dtb_veeqo_inventory_normalize_stock_entry( array $entry ): ?array {
	if ( ! empty( $entry['infinite'] ) ) {
		return [ 'available' => 0, 'infinite' => true ];
	}

	if ( array_key_exists( 'available_stock_level', $entry ) ) {
		$value = $entry['available_stock_level'];
	} elseif ( array_key_exists( 'available_stock', $entry ) ) {
		$value = $entry['available_stock'];
	} else {
		return null;
	}

	if ( null === $value || ! is_numeric( $value ) ) {
		return null;
	}

	return [ 'available' => max( 0, (int) $value ), 'infinite' => false ];
}

function dtb_veeqo_inventory_woo_ids_for_skus( array $skus ): array {
	global $wpdb;

	$skus = array_values(
		array_unique(
			array_filter(
				array_map(
					static fn( $sku ): string => trim( (string) $sku ),
					$skus
				)
			)
		)
	);

	if ( empty( $skus ) ) {
		return [];
	}

	$lookup_table = $wpdb->prefix . 'wc_product_meta_lookup';
	$placeholders = implode( ', ', array_fill( 0, count( $skus ), '%s' ) );
	$rows         = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT sku, product_id FROM {$lookup_table} WHERE sku IN ({$placeholders}) ORDER BY sku ASC, product_id ASC",
			...$skus
		),
		ARRAY_A
	);

	$map = [];
	foreach ( (array) $rows as $row ) {
		$sku = (string) ( $row['sku'] ?? '' );
		$id  = absint( $row['product_id'] ?? 0 );
		if ( '' === $sku || $id <= 0 ) {
			continue;
		}

		$map[ $sku ] ??= [];
		if ( count( $map[ $sku ] ) < 3 ) {
			$map[ $sku ][] = $id;
		}
	}

	return $map;
}

function dtb_veeqo_inventory_reconcile_page( int $page = 1, int $per_page = DTB_VEEQO_INVENTORY_PAGE_SIZE, bool $dry_run = false ) {
	$readiness = dtb_veeqo_inventory_readiness();
	if ( empty( $readiness['ready'] ) ) {
		return new WP_Error(
			'veeqo_inventory_not_ready',
			'Veeqo inventory projection is not configured.',
			[ 'status' => 503, 'missing' => $readiness['missing'] ]
		);
	}

	if ( ! function_exists( 'dtb_veeqo_request' ) ) {
		return new WP_Error( 'veeqo_client_unavailable', 'Veeqo client is unavailable.', [ 'status' => 503 ] );
	}

	if ( ! function_exists( 'wc_get_product' ) ) {
		return new WP_Error( 'woocommerce_unavailable', 'WooCommerce product APIs are unavailable.', [ 'status' => 503 ] );
	}

	$result = dtb_veeqo_request(
		'GET',
		'/products',
		[
			'page'      => (string) max( 1, $page ),
			'page_size' => (string) min( DTB_VEEQO_INVENTORY_PAGE_SIZE, max( 1, $per_page ) ),
		]
	);

	if ( empty( $result['ok'] ) || ! is_array( $result['data'] ?? null ) ) {
		return new WP_Error(
			'veeqo_inventory_fetch_failed',
			sanitize_text_field( (string) ( $result['error'] ?? 'Veeqo inventory fetch failed.' ) ),
			[ 'status' => (int) ( $result['status'] ?? 502 ) ]
		);
	}

	$report = [
		'page'                      => max( 1, $page ),
		'products_received'         => count( $result['data'] ),
		'sellables_seen'            => 0,
		'updated'                   => 0,
		'unchanged'                 => 0,
		'mapped'                    => 0,
		'unmapped_skus'             => [],
		'duplicate_skus'            => [],
		'missing_warehouse_entries' => [],
		'invalid_stock_entries'     => [],
		'parent_ids'                => [],
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
	$warehouse_id   = (int) $readiness['warehouse_id'];

	foreach ( $result['data'] as $veeqo_product ) {
		if ( ! is_array( $veeqo_product ) ) {
			continue;
		}

		foreach ( (array) ( $veeqo_product['sellables'] ?? [] ) as $sellable ) {
			if ( ! is_array( $sellable ) ) {
				continue;
			}

			$report['sellables_seen']++;
			$sku = trim( sanitize_text_field( (string) ( $sellable['sku_code'] ?? '' ) ) );
			if ( '' === $sku ) {
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

			$sellable_id = absint( $sellable['id'] ?? 0 );
			if ( $sellable_id <= 0 ) {
				$report['unmapped_skus'][] = $sku;
				continue;
			}

			// Product identity is established by the exact, unique SKU match above.
			// A missing/invalid warehouse row blocks stock projection only; it must
			// not block saving the independently verified Veeqo sellable identity.
			$needs_meta = absint( $product->get_meta( '_veeqo_sellable_id', true ) ) !== $sellable_id
				|| (string) $product->get_meta( '_veeqo_mapped_sku', true ) !== $sku;

			$entry = dtb_veeqo_inventory_stock_entry_for_warehouse( $sellable, $warehouse_id );
			if ( null === $entry ) {
				$report['missing_warehouse_entries'][] = $sku;
				$stock = null;
			} else {
				$stock = dtb_veeqo_inventory_normalize_stock_entry( $entry );
				if ( null === $stock ) {
					$report['invalid_stock_entries'][] = $sku;
				}
			}

			$target_status = null === $stock
				? null
				: ( $stock['infinite'] || $stock['available'] > 0
					? 'instock'
					: ( $product->backorders_allowed() ? 'onbackorder' : 'outofstock' ) );
			$needs_stock   = null !== $stock && ( $stock['infinite']
				? ( $product->managing_stock() || ! $product->is_in_stock() )
				: ( ! $product->managing_stock() || (int) $product->get_stock_quantity() !== $stock['available'] || $product->get_stock_status() !== $target_status ) );

			if ( $needs_meta ) {
				$report['mapped']++;
			}

			if ( ! $needs_meta && ! $needs_stock ) {
				if ( null === $stock ) {
					continue;
				}
				$report['unchanged']++;
				continue;
			}

			$report['updated']++;
			if ( $dry_run ) {
				continue;
			}

			if ( $needs_meta ) {
				$product->update_meta_data( '_veeqo_sellable_id', $sellable_id );
				$product->update_meta_data( '_veeqo_mapped_sku', $sku );
			}

			if ( null !== $stock && $stock['infinite'] ) {
				$product->set_manage_stock( false );
				$product->set_stock_status( 'instock' );
			} elseif ( null !== $stock ) {
				$product->set_manage_stock( true );
				$product->set_stock_quantity( $stock['available'] );
				$product->set_stock_status( $target_status );
			}

			$product->save();
			wc_delete_product_transients( $product_id );

			$parent_id = absint( $product->get_parent_id() );
			if ( $needs_stock && $parent_id > 0 ) {
				$report['parent_ids'][ $parent_id ] = $parent_id;
			}
		}
	}

	if ( ! $dry_run && class_exists( 'WC_Product_Variable' ) ) {
		foreach ( array_values( $report['parent_ids'] ) as $parent_id ) {
			WC_Product_Variable::sync( $parent_id );
			wc_delete_product_transients( $parent_id );
		}
	}

	$report['parent_ids'] = array_values( $report['parent_ids'] );
	foreach ( [ 'unmapped_skus', 'missing_warehouse_entries', 'invalid_stock_entries' ] as $key ) {
		$report[ $key ] = array_values( array_unique( $report[ $key ] ) );
	}

	return $report;
}

function dtb_veeqo_inventory_empty_aggregate( bool $dry_run ): array {
	return [
		'pages'                     => 0,
		'sellables_seen'            => 0,
		'updated'                   => 0,
		'unchanged'                 => 0,
		'mapped'                    => 0,
		'unmapped_skus'             => [],
		'duplicate_skus'            => [],
		'missing_warehouse_entries' => [],
		'invalid_stock_entries'     => [],
		'dry_run'                   => $dry_run,
		'started_at'                => gmdate( 'c' ),
	];
}

function dtb_veeqo_inventory_merge_report( array $aggregate, array $report ): array {
	$aggregate['pages']++;
	foreach ( [ 'sellables_seen', 'updated', 'unchanged', 'mapped' ] as $key ) {
		$aggregate[ $key ] += (int) ( $report[ $key ] ?? 0 );
	}

	foreach ( [ 'unmapped_skus', 'missing_warehouse_entries', 'invalid_stock_entries' ] as $key ) {
		$aggregate[ $key ] = array_values(
			array_unique(
				array_merge( (array) $aggregate[ $key ], (array) ( $report[ $key ] ?? [] ) )
			)
		);
	}

	$aggregate['duplicate_skus'] = array_replace(
		(array) $aggregate['duplicate_skus'],
		(array) ( $report['duplicate_skus'] ?? [] )
	);

	return $aggregate;
}

/** Process one bounded chunk. This function never schedules its own continuation. */
function dtb_veeqo_inventory_reconcile_chunk( bool $dry_run = false, int $start_page = 1, array $aggregate = [] ) {
	$token = dtb_veeqo_inventory_acquire_lock();
	if ( '' === $token ) {
		return new WP_Error( 'veeqo_inventory_locked', 'A Veeqo inventory reconciliation is already running.', [ 'status' => 409 ] );
	}

	if ( empty( $aggregate ) ) {
		$aggregate = dtb_veeqo_inventory_empty_aggregate( $dry_run );
	}
	$aggregate['dry_run'] = $dry_run;

	$run_started = microtime( true );
	$processed   = 0;

	try {
		for ( $page = max( 1, $start_page ); $page <= DTB_VEEQO_INVENTORY_PAGE_LIMIT; $page++ ) {
			if ( ! dtb_veeqo_inventory_refresh_lock( $token ) ) {
				return new WP_Error( 'veeqo_inventory_lock_lost', 'Veeqo inventory reconciliation lost lock ownership.', [ 'status' => 409 ] );
			}

			$report = dtb_veeqo_inventory_reconcile_page( $page, DTB_VEEQO_INVENTORY_PAGE_SIZE, $dry_run );
			if ( is_wp_error( $report ) ) {
				return $report;
			}

			$aggregate = dtb_veeqo_inventory_merge_report( $aggregate, $report );
			$processed++;

			if ( (int) $report['products_received'] < DTB_VEEQO_INVENTORY_PAGE_SIZE ) {
				$aggregate['completed_at'] = gmdate( 'c' );
				$aggregate['partial']      = false;
				unset( $aggregate['next_page'], $aggregate['paused_at'] );
				update_option( DTB_VEEQO_INVENTORY_DIAGNOSTICS, $aggregate, false );

				if ( class_exists( 'DTB_VeeqoSyncJob' ) && ! $dry_run ) {
					DTB_VeeqoSyncJob::log_timestamp( 'inventory' );
				}

				return $aggregate;
			}

			if ( $processed >= DTB_VEEQO_INVENTORY_RUN_PAGES || microtime( true ) - $run_started >= DTB_VEEQO_INVENTORY_RUN_SECONDS ) {
				$aggregate['partial']   = true;
				$aggregate['next_page'] = $page + 1;
				$aggregate['paused_at'] = gmdate( 'c' );
				update_option( DTB_VEEQO_INVENTORY_DIAGNOSTICS, $aggregate, false );
				return $aggregate;
			}
		}

		return new WP_Error( 'veeqo_inventory_page_limit', 'Veeqo inventory reconciliation exceeded the page safety limit.', [ 'status' => 500 ] );
	} finally {
		dtb_veeqo_inventory_release_lock( $token );
	}
}

/** Compatibility wrapper for scheduled and system callers. */
function dtb_veeqo_inventory_reconcile_all( bool $dry_run = false, int $start_page = 1, array $aggregate = [] ) {
	$result = dtb_veeqo_inventory_reconcile_chunk( $dry_run, $start_page, $aggregate );
	if ( ! is_wp_error( $result ) && ! empty( $result['partial'] ) && function_exists( 'as_enqueue_async_action' ) ) {
		as_enqueue_async_action(
			DTB_VEEQO_INVENTORY_RECONCILE_HOOK,
			[ 0, max( 1, absint( $result['next_page'] ?? 1 ) ), $result, $dry_run ],
			DTB_VEEQO_INVENTORY_ACTION_GROUP,
			true
		);
	}

	return $result;
}

function dtb_veeqo_inventory_schedule_recurring(): void {
	if ( empty( dtb_veeqo_inventory_readiness()['ready'] ) || ! function_exists( 'as_schedule_recurring_action' ) ) {
		return;
	}

	$already = function_exists( 'as_has_scheduled_action' )
		? as_has_scheduled_action( DTB_VEEQO_INVENTORY_RECONCILE_HOOK, [], DTB_VEEQO_INVENTORY_ACTION_GROUP )
		: false;

	if ( ! $already ) {
		as_schedule_recurring_action(
			time() + 60,
			DTB_VEEQO_INVENTORY_INTERVAL_SECONDS,
			DTB_VEEQO_INVENTORY_RECONCILE_HOOK,
			[],
			DTB_VEEQO_INVENTORY_ACTION_GROUP,
			true
		);
	}
}

add_action( 'init', 'dtb_veeqo_inventory_schedule_recurring', 40 );
add_action( DTB_VEEQO_INVENTORY_RECONCILE_HOOK, 'dtb_veeqo_inventory_scheduled_run', 10, 4 );

function dtb_veeqo_inventory_scheduled_run( int $attempt = 0, int $start_page = 1, array $aggregate = [], bool $dry_run = false ): void {
	$result = dtb_veeqo_inventory_reconcile_all( $dry_run, $start_page, $aggregate );
	if ( ! is_wp_error( $result ) ) {
		return;
	}

	$data      = $result->get_error_data();
	$status    = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
	$retryable = 0 === $status || 408 === $status || 425 === $status || 429 === $status || $status >= 500;
	$queued    = false;
	$next_attempt = $attempt + 1;

	if ( $retryable && $attempt < DTB_VEEQO_INVENTORY_MAX_RETRIES && function_exists( 'as_schedule_single_action' ) ) {
		$delay = min( HOUR_IN_SECONDS, ( 2 ** $attempt ) * 5 * MINUTE_IN_SECONDS );
		$next_args = [ $next_attempt, $start_page, $aggregate, $dry_run ];
		$action_id = as_schedule_single_action(
			time() + $delay,
			DTB_VEEQO_INVENTORY_RECONCILE_HOOK,
			$next_args,
			DTB_VEEQO_INVENTORY_ACTION_GROUP,
			true
		);
		$queued = is_numeric( $action_id ) && (int) $action_id > 0;
		if ( ! $queued && function_exists( 'as_has_scheduled_action' ) ) {
			$queued = (bool) as_has_scheduled_action( DTB_VEEQO_INVENTORY_RECONCILE_HOOK, $next_args, DTB_VEEQO_INVENTORY_ACTION_GROUP );
		}
	}

	if ( function_exists( 'dtb_veeqo_log' ) ) {
		dtb_veeqo_log(
			$queued ? 'warning' : 'error',
			$queued ? 'inventory_reconciliation_retry_queued' : 'inventory_reconciliation_failed_terminal',
			$queued ? 'Veeqo inventory reconciliation failed; a bounded retry is scheduled.' : 'Veeqo inventory reconciliation failed without a scheduled retry.',
			[
				'attempt' => $attempt,
				'next_attempt' => $queued ? $next_attempt : null,
				'retryable' => $retryable,
				'retry_queued' => $queued,
				'status' => $status,
				'error_code' => $result->get_error_code(),
				'error' => sanitize_text_field( $result->get_error_message() ),
			]
		);
	}

	// Action Scheduler distinguishes terminal provider failures from completed
	// callbacks. A scheduled retry is an expected handoff, not a terminal failure.
	if ( ! $queued ) {
		throw new RuntimeException( 'Veeqo inventory reconciliation terminal failure (' . sanitize_key( $result->get_error_code() ) . ', HTTP ' . $status . ').' );
	}
}
