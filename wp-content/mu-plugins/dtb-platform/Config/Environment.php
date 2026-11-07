<?php
/**
 * Environment — DTB Platform
 *
 * Resolves the active WooCommerce product CSV catalog configuration.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the active WooCommerce product CSV configuration.
 *
 * Priority:
 *   1. DTB_WC_CSV_FILENAME when defined. It may contain one filename or a
 *      comma/pipe-separated list. Each entry is reduced to basename().
 *   2. Newest readable product-*.csv file in uploads/wc-imports/.
 *   3. Readable uploads/wc-imports/wp-catalog.csv fallback.
 *
 * Configured files are strict only when at least one configured file resolves.
 * If all configured filenames are stale, the resolver falls back to the newest
 * readable product-*.csv and clears the stale missing list so runtime health is
 * based on the active canonical CSV, not an obsolete import artifact.
 *
 * @return array{filename:string,filenames:string[],source:string,missing:string[]}
 */
function dtb_resolve_catalog_csv_config(): array {
	$upload_dir  = wp_upload_dir();
	$imports_dir = trailingslashit( $upload_dir['basedir'] ) . 'wc-imports/';

	$result = [
		'filename'  => '',
		'filenames' => [],
		'source'    => 'missing',
		'missing'   => [],
	];

	$configured_csv = defined( 'DTB_WC_CSV_FILENAME' ) ? trim( (string) DTB_WC_CSV_FILENAME ) : '';
	if ( '' !== $configured_csv ) {
		$requested = preg_split( '/\s*[,|]\s*/', $configured_csv ) ?: [];
		foreach ( $requested as $filename ) {
			$basename = basename( trim( (string) $filename ) );
			if ( '' === $basename ) {
				continue;
			}

			$path = $imports_dir . $basename;
			if ( is_readable( $path ) && is_file( $path ) ) {
				$result['filenames'][] = $basename;
			} else {
				$result['missing'][] = $basename;
			}
		}

		$result['filenames'] = array_values( array_unique( $result['filenames'] ) );
		if ( ! empty( $result['filenames'] ) ) {
			$result['filename'] = $result['filenames'][0];
			$result['source']   = 'configured';
			return $result;
		}
	}

	if ( is_dir( $imports_dir ) ) {
		$product_csvs = glob( $imports_dir . 'product-*.csv' ) ?: [];
		$product_csvs = array_values( array_filter( $product_csvs, static fn( $path ) => is_file( $path ) && is_readable( $path ) ) );

		if ( ! empty( $product_csvs ) ) {
			usort( $product_csvs, static function ( string $a, string $b ): int {
				$mtime_compare = filemtime( $b ) <=> filemtime( $a );
				return 0 !== $mtime_compare ? $mtime_compare : strcmp( basename( $a ), basename( $b ) );
			} );

			$result['filename']  = basename( $product_csvs[0] );
			$result['filenames'] = [ $result['filename'] ];
			$result['source']    = 'auto';
			$result['missing']   = [];
			return $result;
		}
	}

	$fallback = $imports_dir . 'wp-catalog.csv';
	if ( is_readable( $fallback ) && is_file( $fallback ) ) {
		$result['filename']  = 'wp-catalog.csv';
		$result['filenames'] = [ 'wp-catalog.csv' ];
		$result['source']    = 'fallback';
		$result['missing']   = [];
	}

	return $result;
}
