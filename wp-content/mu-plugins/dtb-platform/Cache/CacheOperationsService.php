<?php
/**
 * DTB Platform — CacheOperationsService
 *
 * Canonical cache-purge engine for every DTB operator surface.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CacheOperationsService' ) ) {
	return;
}

final class DTB_CacheOperationsService {
	private const FRONTEND_EPOCH_OPTION = 'dtb_frontend_cache_epoch';

	/**
	 * @return array<string, array{label:string,description:string,safe_for_all:bool}>
	 */
	public static function targets(): array {
		return [
			'dtb_transients' => [ 'label' => __( 'DTB Application Cache', 'drywall-toolbox' ), 'description' => __( 'Product proxy, operations, command center, system health, catalog health, and DTB-prefixed transient data.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'wordpress_transients' => [ 'label' => __( 'WordPress Transients', 'drywall-toolbox' ), 'description' => __( 'All site and network transients stored by WordPress. Persistent object cache is flushed separately.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'woocommerce_cache' => [ 'label' => __( 'WooCommerce Cache', 'drywall-toolbox' ), 'description' => __( 'WooCommerce products, orders, shipping, sessions-derived cache groups, and lookup transients through supported APIs.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'object_cache' => [ 'label' => __( 'WordPress Object Cache', 'drywall-toolbox' ), 'description' => __( 'Runtime and persistent object cache through the WordPress object-cache API.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'rewrite_rules' => [ 'label' => __( 'WordPress Rewrite Rules', 'drywall-toolbox' ), 'description' => __( 'Regenerates rewrite rules after route, endpoint, or permalink changes.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'opcache' => [ 'label' => __( 'PHP OPcache', 'drywall-toolbox' ), 'description' => __( 'Compiled PHP bytecode cache when the host permits an application-level reset.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'page_cache' => [ 'label' => __( 'SiteGround Dynamic Cache', 'drywall-toolbox' ), 'description' => __( 'SiteGround Dynamic/File cache through the active Speed Optimizer integration.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'frontend_epoch' => [ 'label' => __( 'Frontend Refresh Epoch', 'drywall-toolbox' ), 'description' => __( 'Advances the storefront refresh marker used to invalidate browser-managed DTB caches without deleting customer data.', 'drywall-toolbox' ), 'safe_for_all' => true ],
			'cdn_cache' => [ 'label' => __( 'SiteGround CDN Cache', 'drywall-toolbox' ), 'description' => __( 'Reports whether an application-level CDN purge integration exists; otherwise gives the host-owned recovery path.', 'drywall-toolbox' ), 'safe_for_all' => true ],
		];
	}

	/**
	 * @return array{available:bool,level_label:string}
	 */
	public static function page_cache_status(): array {
		return [
			'available'   => function_exists( 'sg_cachepress_purge_cache' ) || has_action( 'siteground_optimizer_flush_cache' ),
			'level_label' => function_exists( 'sg_cachepress_purge_cache' ) || has_action( 'siteground_optimizer_flush_cache' )
				? __( 'SiteGround application purge integration detected', 'drywall-toolbox' )
				: __( 'No application purge integration detected', 'drywall-toolbox' ),
		];
	}

	/**
	 * @param string[] $requested Requested target keys or all.
	 * @return array{run_id:string,results:array<int,array{key:string,label:string,status:string,message:string,duration_ms:int}>,summary:array{ok:int,skipped:int,failed:int}}
	 */
	public static function run( array $requested ): array {
		$run_id = wp_generate_uuid4();
		$lock   = DTB_CachePurgeLock::acquire();

		if ( ! $lock['acquired'] ) {
			return [
				'run_id'  => $run_id,
				'results' => [ [ 'key' => 'lock', 'label' => __( 'Purge Lock', 'drywall-toolbox' ), 'status' => 'failed', 'message' => $lock['message'], 'duration_ms' => 0 ] ],
				'summary' => [ 'ok' => 0, 'skipped' => 0, 'failed' => 1 ],
			];
		}

		try {
			$all_targets = self::targets();
			if ( in_array( 'all', $requested, true ) ) {
				$requested = array_keys( array_filter( $all_targets, static fn( array $target ): bool => $target['safe_for_all'] ) );
			}

			$requested = array_values( array_unique( array_map( 'sanitize_key', $requested ) ) );
			$results   = [];
			$summary   = [ 'ok' => 0, 'skipped' => 0, 'failed' => 0 ];

			foreach ( $requested as $key ) {
				if ( ! isset( $all_targets[ $key ] ) ) {
					$results[] = [ 'key' => $key, 'label' => $key, 'status' => 'skipped', 'message' => __( 'Unknown cache target.', 'drywall-toolbox' ), 'duration_ms' => 0 ];
					$summary['skipped']++;
					continue;
				}

				$started = microtime( true );
				try {
					$outcome = self::run_target( $key );
				} catch ( \Throwable $exception ) {
					$outcome = [ 'status' => 'failed', 'message' => $exception->getMessage() ];
				}

				$status    = in_array( $outcome['status'], [ 'ok', 'skipped', 'failed' ], true ) ? $outcome['status'] : 'failed';
				$results[] = [
					'key'         => $key,
					'label'       => $all_targets[ $key ]['label'],
					'status'      => $status,
					'message'     => $outcome['message'],
					'duration_ms' => (int) round( ( microtime( true ) - $started ) * 1000 ),
				];
				$summary[ $status ]++;
			}

			dtb_log_cache_event( 'cache_operations_run', [
				'run_id'  => $run_id,
				'targets' => $requested,
				'summary' => $summary,
				'actor'   => get_current_user_id(),
			] );

			return [ 'run_id' => $run_id, 'results' => $results, 'summary' => $summary ];
		} finally {
			DTB_CachePurgeLock::release( $lock['token'] );
		}
	}

	/**
	 * @return array{status:string,message:string}
	 */
	private static function run_target( string $key ): array {
		switch ( $key ) {
			case 'dtb_transients':
				return self::flush_dtb_application_cache();
			case 'wordpress_transients':
				return self::flush_wordpress_transients();
			case 'woocommerce_cache':
				return self::flush_woocommerce_cache();
			case 'object_cache':
				return self::flush_object_cache();
			case 'rewrite_rules':
				flush_rewrite_rules( false );
				return [ 'status' => 'ok', 'message' => __( 'WordPress rewrite rules regenerated without writing an .htaccess hard flush.', 'drywall-toolbox' ) ];
			case 'opcache':
				return self::flush_opcache();
			case 'page_cache':
				return self::flush_page_cache();
			case 'frontend_epoch':
				return self::advance_frontend_epoch();
			case 'cdn_cache':
				return self::flush_cdn_cache();
			default:
				return [ 'status' => 'skipped', 'message' => __( 'Unknown cache target.', 'drywall-toolbox' ) ];
		}
	}

	private static function flush_dtb_application_cache(): array {
		global $wpdb;
		$patterns = [
			'_transient_drywall_cache_%', '_transient_timeout_drywall_cache_%',
			'_transient_dtb_ops_%', '_transient_timeout_dtb_ops_%',
			'_transient_dtb_catalog_health_%', '_transient_timeout_dtb_catalog_health_%',
			'_transient_dtb_system_%', '_transient_timeout_dtb_system_%',
		];
		$deleted = self::delete_option_patterns( $wpdb->options, 'option_name', $patterns );

		foreach ( [ 'dtb_system_health', 'dtb_queue_health', 'dtb_integration_health', 'dtb_webhook_health', 'dtb_cron_health' ] as $transient ) {
			delete_transient( $transient );
		}
		if ( function_exists( 'dtb_command_center_flush_cache' ) ) {
			dtb_command_center_flush_cache();
		}
		if ( class_exists( 'DTB_CacheInvalidationService' ) ) {
			DTB_CacheInvalidationService::invalidate_product_cache();
			DTB_CacheInvalidationService::flush_ops_cache( '' );
		}

		return [ 'status' => 'ok', 'message' => sprintf( __( 'DTB application caches cleared; %d matching database rows removed.', 'drywall-toolbox' ), $deleted ) ];
	}

	private static function flush_wordpress_transients(): array {
		global $wpdb;
		$deleted = self::delete_option_patterns( $wpdb->options, 'option_name', [ '_transient_%', '_transient_timeout_%' ] );
		if ( is_multisite() ) {
			$deleted += self::delete_option_patterns( $wpdb->sitemeta, 'meta_key', [ '_site_transient_%', '_site_transient_timeout_%' ] );
		} else {
			$deleted += self::delete_option_patterns( $wpdb->options, 'option_name', [ '_site_transient_%', '_site_transient_timeout_%' ] );
		}
		return [ 'status' => 'ok', 'message' => sprintf( __( 'WordPress transient storage cleared; %d database rows removed.', 'drywall-toolbox' ), $deleted ) ];
	}

	private static function flush_woocommerce_cache(): array {
		if ( ! function_exists( 'wc_delete_product_transients' ) ) {
			return [ 'status' => 'skipped', 'message' => __( 'WooCommerce is not active.', 'drywall-toolbox' ) ];
		}
		wc_delete_product_transients();
		if ( function_exists( 'wc_delete_shop_order_transients' ) ) {
			wc_delete_shop_order_transients();
		}
		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::get_transient_version( 'product', true );
			WC_Cache_Helper::get_transient_version( 'shipping', true );
			WC_Cache_Helper::get_transient_version( 'order', true );
		}
		return [ 'status' => 'ok', 'message' => __( 'WooCommerce cache versions and supported product/order transients were invalidated.', 'drywall-toolbox' ) ];
	}

	private static function flush_object_cache(): array {
		if ( ! function_exists( 'wp_cache_flush' ) ) {
			return [ 'status' => 'skipped', 'message' => __( 'WordPress object cache API is unavailable.', 'drywall-toolbox' ) ];
		}
		$result = wp_cache_flush();
		return false === $result
			? [ 'status' => 'failed', 'message' => __( 'The object cache backend reported that the flush failed.', 'drywall-toolbox' ) ]
			: [ 'status' => 'ok', 'message' => __( 'WordPress runtime and persistent object cache flush completed.', 'drywall-toolbox' ) ];
	}

	private static function flush_opcache(): array {
		if ( ! function_exists( 'opcache_reset' ) ) {
			return [ 'status' => 'skipped', 'message' => __( 'PHP OPcache is unavailable to this PHP runtime.', 'drywall-toolbox' ) ];
		}
		$restricted = (string) ini_get( 'opcache.restrict_api' );
		$script     = (string) ( $_SERVER['SCRIPT_FILENAME'] ?? '' );
		if ( '' !== $restricted && 0 !== strpos( $script, $restricted ) ) {
			return [ 'status' => 'skipped', 'message' => __( 'The host restricts OPcache reset access for this application path.', 'drywall-toolbox' ) ];
		}
		return opcache_reset()
			? [ 'status' => 'ok', 'message' => __( 'PHP OPcache reset completed.', 'drywall-toolbox' ) ]
			: [ 'status' => 'failed', 'message' => __( 'PHP accepted the OPcache reset call but returned false.', 'drywall-toolbox' ) ];
	}

	private static function flush_page_cache(): array {
		try {
			if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
				sg_cachepress_purge_cache();
				return [ 'status' => 'ok', 'message' => __( 'SiteGround Dynamic/File cache purge requested through Speed Optimizer.', 'drywall-toolbox' ) ];
			}
			if ( has_action( 'siteground_optimizer_flush_cache' ) ) {
				do_action( 'siteground_optimizer_flush_cache' );
				return [ 'status' => 'ok', 'message' => __( 'SiteGround cache purge action dispatched.', 'drywall-toolbox' ) ];
			}
		} catch ( \Throwable $exception ) {
			return [ 'status' => 'failed', 'message' => $exception->getMessage() ];
		}
		return [ 'status' => 'skipped', 'message' => __( 'No supported SiteGround application purge integration is active. Purge Dynamic Cache in Site Tools.', 'drywall-toolbox' ) ];
	}

	private static function advance_frontend_epoch(): array {
		$epoch = time();
		update_option( self::FRONTEND_EPOCH_OPTION, $epoch, false );
		return [ 'status' => 'ok', 'message' => sprintf( __( 'Frontend refresh epoch advanced to %d. Storefront clients can discard DTB-managed browser caches while preserving carts, authentication, and customer data.', 'drywall-toolbox' ), $epoch ) ];
	}

	private static function flush_cdn_cache(): array {
		/**
		 * Provider adapters may return true after completing a CDN purge.
		 * No provider credentials are accepted or stored by this service.
		 */
		$purged = apply_filters( 'dtb_cache_purge_cdn', false );
		return true === $purged
			? [ 'status' => 'ok', 'message' => __( 'CDN purge completed through the registered provider adapter.', 'drywall-toolbox' ) ]
			: [ 'status' => 'skipped', 'message' => __( 'No CDN purge adapter is registered. Purge SiteGround CDN from Site Tools > Speed > CDN.', 'drywall-toolbox' ) ];
	}

	/**
	 * Delete option rows matching prepared LIKE patterns.
	 *
	 * @param string   $table    Allowlisted WordPress table name.
	 * @param string   $column   Allowlisted column name.
	 * @param string[] $patterns Raw SQL LIKE patterns.
	 */
	private static function delete_option_patterns( string $table, string $column, array $patterns ): int {
		global $wpdb;
		$allowed_tables  = [ $wpdb->options ];
		$allowed_columns = [ 'option_name' ];
		if ( is_multisite() ) {
			$allowed_tables[]  = $wpdb->sitemeta;
			$allowed_columns[] = 'meta_key';
		}
		if ( ! in_array( $table, $allowed_tables, true ) || ! in_array( $column, $allowed_columns, true ) ) {
			throw new \InvalidArgumentException( 'Unsupported cache storage table.' );
		}
		$clauses = [];
		$args    = [];
		foreach ( $patterns as $pattern ) {
			$clauses[] = "{$column} LIKE %s";
			$args[]    = $pattern;
		}
		if ( empty( $clauses ) ) {
			return 0;
		}
		$sql = "DELETE FROM {$table} WHERE " . implode( ' OR ', $clauses );
		$result = $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Database cache cleanup failed.' );
		}
		return (int) $result;
	}
}
