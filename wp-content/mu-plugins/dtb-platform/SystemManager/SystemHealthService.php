<?php
/**
 * DTB Platform — SystemHealthService
 *
 * Aggregates server / PHP / WP environment health signals.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/*
 * Probe-version guard — invalidates the dtb_system_health transient when
 * the probe functions change so stale "Missing" statuses are cleared on next
 * page load.  Bump DTB_HEALTH_PROBE_VER whenever probe logic is updated.
 */
add_action(
	'init',
	static function (): void {
		// phpcs:ignore WordPress.WP.CapitalPDangit.Misspelled
		$version = 'dtb-probe-v2-2025-06-02';
		if ( get_option( 'dtb_health_probe_ver' ) !== $version ) {
			delete_transient( 'dtb_system_health' );
			update_option( 'dtb_health_probe_ver', $version, false );
		}
	},
	1
);

/**
 * Returns a summary of system health indicators.
 *
 * @return array{
 *   php_version: string,
 *   php_ok: bool,
 *   wp_version: string,
 *   wp_debug: bool,
 *   wp_debug_log: bool,
 *   memory_limit: string,
 *   max_execution_time: int,
 *   upload_max_filesize: string,
 *   ssl_active: bool,
 *   multisite: bool,
 * }
 */
function dtb_system_health_get(): array {
	$transient = get_transient( 'dtb_system_health' );
	if ( is_array( $transient ) ) {
		return $transient;
	}

	global $wp_version;

	$data = [
		'php_version'         => PHP_VERSION,
		'php_ok'              => version_compare( PHP_VERSION, '8.0', '>=' ),
		'wp_version'          => $wp_version ?? 'unknown',
		'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
		'wp_debug_log'        => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
		'memory_limit'        => ini_get( 'memory_limit' ),
		'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
		'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
		'ssl_active'          => is_ssl(),
		'multisite'           => is_multisite(),
		'site_url'            => get_site_url(),
		'admin_email'         => get_option( 'admin_email' ),
		'timezone'            => get_option( 'timezone_string' ) ?: get_option( 'gmt_offset' ) . ' UTC',
		'php_log'             => dtb_system_php_log_summary(),
		'rest'                => dtb_system_rest_health_summary(),
		'cache'               => dtb_system_cache_health_summary(),
		'projections'         => dtb_system_projection_health_summary(),
		'catalog'             => dtb_system_catalog_health_summary(),
		'media'               => dtb_system_media_health_summary(),
		'schematics'          => dtb_system_schematic_health_summary(),
	];

	set_transient( 'dtb_system_health', $data, 5 * MINUTE_IN_SECONDS );

	return $data;
}

function dtb_system_php_log_summary(): array {
	$paths = [];
	if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
		$paths[] = WP_DEBUG_LOG;
	}
	if ( defined( 'WP_CONTENT_DIR' ) ) {
		$paths[] = WP_CONTENT_DIR . '/debug.log';
	}

	$path = '';
	foreach ( array_unique( array_filter( $paths ) ) as $candidate ) {
		if ( is_readable( $candidate ) ) {
			$path = $candidate;
			break;
		}
	}

	return [
		'available'    => '' !== $path,
		'path'         => $path ? wp_normalize_path( $path ) : '',
		'size_bytes'   => $path ? (int) filesize( $path ) : 0,
		'modified_gmt' => $path ? gmdate( 'c', (int) filemtime( $path ) ) : '',
	];
}

/**
 * Read the last lines from the active PHP/WP debug log.
 *
 * @param int $max_lines Maximum lines to return.
 * @return array{available:bool,path:string,size_bytes:int,modified_gmt:string,lines:string[],error:string}
 */
function dtb_system_php_log_tail( int $max_lines = 200 ): array {
	$summary = dtb_system_php_log_summary();
	$path    = (string) ( $summary['path'] ?? '' );

	$result = [
		'available'    => (bool) ( $summary['available'] ?? false ),
		'path'         => $path,
		'size_bytes'   => (int) ( $summary['size_bytes'] ?? 0 ),
		'modified_gmt' => (string) ( $summary['modified_gmt'] ?? '' ),
		'lines'        => [],
		'error'        => '',
	];

	if ( '' === $path || ! is_readable( $path ) ) {
		$result['available'] = false;
		$result['error']     = __( 'No readable WP debug/PHP log file was found.', 'drywall-toolbox' );
		return $result;
	}

	$max_lines  = max( 20, min( 500, $max_lines ) );
	$max_bytes  = 256 * 1024;
	$size_bytes = (int) filesize( $path );
	$offset     = max( 0, $size_bytes - $max_bytes );
	$handle     = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

	if ( false === $handle ) {
		$result['available'] = false;
		$result['error']     = __( 'The log file exists but could not be opened.', 'drywall-toolbox' );
		return $result;
	}

	if ( $offset > 0 ) {
		fseek( $handle, $offset );
		fgets( $handle ); // discard a partial first line.
	}

	$contents = stream_get_contents( $handle );
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

	if ( false === $contents || '' === $contents ) {
		return $result;
	}

	$lines = preg_split( '/\R/', $contents );
	if ( ! is_array( $lines ) ) {
		return $result;
	}

	$lines           = array_values( array_filter( $lines, static fn( string $line ): bool => '' !== trim( $line ) ) );
	$result['lines'] = array_slice( $lines, -$max_lines );

	return $result;
}

function dtb_system_rest_health_summary(): array {
	$routes = rest_get_server()->get_routes();
	$dtb    = array_filter( array_keys( $routes ), static fn( string $route ): bool => str_starts_with( $route, '/dtb/v1/' ) );

	return [
		'total_routes' => count( $routes ),
		'dtb_routes'   => count( $dtb ),
		'namespaces'   => rest_get_server()->get_namespaces(),
	];
}

function dtb_system_cache_health_summary(): array {
	global $wpdb;
	$expired = 0;
	if ( $wpdb instanceof wpdb ) {
		$expired = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_dtb_%' AND option_value < UNIX_TIMESTAMP()"
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	return [
		'external_object_cache' => wp_using_ext_object_cache(),
		'expired_dtb_transients'=> $expired,
	];
}

function dtb_system_projection_health_summary(): array {
	$keys = [
		'dtb_cc_orders_summary',
		'dtb_cc_repairs_summary',
		'dtb_cc_returns_summary',
		'dtb_cc_support_summary',
	];
	$stale = [];
	foreach ( $keys as $key ) {
		if ( false === get_transient( $key ) ) {
			$stale[] = $key;
		}
	}

	return [
		'tracked' => count( $keys ),
		'stale'   => count( $stale ),
		'keys'    => $stale,
	];
}

function dtb_system_catalog_health_summary(): array {
	/*
	 * Probe current catalog-platform symbols.
	 * Legacy stubs dtb_catalog_get_product / dtb_catalog_get_products were
	 * removed when catalog-platform was refactored. Probe the current
	 * canonical surface instead.
	 */
	$dtb_catalog_available =
		function_exists( 'dtb_catalog_lookup_product_detail_by_id' ) ||
		function_exists( 'dtb_catalog_lookup_product_detail_by_slug' ) ||
		function_exists( 'dtb_catalog_platform_register_routes' ) ||
		class_exists( 'DTB_CatalogProductsController' ) ||
		class_exists( 'DTB_ProductDetailController' );

	// Route-level check: safe only after rest_api_init has fired.
	$catalog_routes_registered = false;
	if ( did_action( 'rest_api_init' ) && function_exists( 'rest_get_server' ) ) {
		$routes                    = rest_get_server()->get_routes();
		$catalog_routes_registered = isset( $routes['/dtb/v1/catalog/products'] );
	}

	return [
		'product_lookup_available'  => function_exists( 'wc_get_products' ),
		'dtb_catalog_available'     => $dtb_catalog_available,
		'catalog_routes_registered' => $catalog_routes_registered,
		'parts_available'           => post_type_exists( 'dtb_part' ) || post_type_exists( 'product' ),
	];
}

function dtb_system_media_health_summary(): array {
	/*
	 * Probe current image-sync symbols.
	 * Legacy dtb_image_sync_status() was replaced by dtb_image_sync_get_status()
	 * and the route callback dtb_route_sync_images_status(). Probe the current
	 * canonical surface instead.
	 */
	$image_sync_available =
		function_exists( 'dtb_image_sync_register_routes' ) ||
		function_exists( 'dtb_build_image_sync_snapshot' ) ||
		function_exists( 'dtb_image_sync_get_status' ) ||
		function_exists( 'dtb_route_sync_images_status' ) ||
		function_exists( 'dtb_route_link_registered_images' ) ||
		function_exists( 'dtb_register_image_attachment' ) ||
		function_exists( 'dtb_link_images_to_product' );

	// Route-level check.
	$sync_routes_registered = false;
	if ( did_action( 'rest_api_init' ) && function_exists( 'rest_get_server' ) ) {
		$routes                 = rest_get_server()->get_routes();
		$sync_routes_registered =
			isset( $routes['/dtb/v1/sync-images'] ) &&
			isset( $routes['/dtb/v1/sync-images/status'] );
	}

	return [
		'uploads_writable'        => wp_is_writable( wp_get_upload_dir()['basedir'] ?? WP_CONTENT_DIR ),
		'image_sync_available'    => $image_sync_available,
		'sync_routes_registered'  => $sync_routes_registered,
		'attachment_post_type'    => post_type_exists( 'attachment' ),
	];
}

function dtb_system_schematic_health_summary(): array {
	/*
	 * Probe current schematics symbols. The authoritative source of truth is
	 * the `dtb_schematic` CPT domain record (Infrastructure/
	 * SchematicRecordRepository.php), not attachment meta. Probe the current
	 * canonical surface: the public API route registrar, the domain record
	 * repository query function, and the exact-identity part resolver.
	 */
	$schematics_available =
		function_exists( 'dtb_register_schematics_public_api_routes' ) ||
		function_exists( 'dtb_schematic_record_repo_query' ) ||
		function_exists( 'dtb_schematics_resolve_product_ids_for_schematic' ) ||
		function_exists( 'dtb_schematic_is_supported_brand' );

	// Route-level check.
	$schematic_routes_registered = false;
	if ( did_action( 'rest_api_init' ) && function_exists( 'rest_get_server' ) ) {
		$routes                      = rest_get_server()->get_routes();
		$schematic_routes_registered =
			isset( $routes['/dtb/v1/schematics'] ) ||
			isset( $routes['/dtb/v1/schematics/(?P<schematic_id>[a-zA-Z0-9_-]+)'] );
	}

	return [
		'schematics_available'       => $schematics_available,
		'schematic_routes_registered'=> $schematic_routes_registered,
	];
}
