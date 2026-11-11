<?php
defined( 'ABSPATH' ) || exit;

function dtb_get_catalog_image_pairs_by_sku( string $dir, string $url, array $extensions ): array {
	static $cache = [];

	$cache_key = md5( trailingslashit( $dir ) . '|' . trailingslashit( $url ) . '|' . implode( ',', $extensions ) );
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$cache[ $cache_key ] = [];
	if ( ! function_exists( 'dtb_get_config' ) || ! is_dir( $dir ) ) {
		return $cache[ $cache_key ];
	}

	$config    = dtb_get_config();
	$filenames = $config['csv_filenames'] ?? [];
	if ( empty( $filenames ) ) {
		return $cache[ $cache_key ];
	}

	$upload_dir = wp_upload_dir();
	$imports_dir = trailingslashit( $upload_dir['basedir'] ) . 'wc-imports/';
	$file_index  = dtb_get_image_file_index( $dir, $url, $extensions );
	$by_basename = [];

	foreach ( $file_index as $file ) {
		$by_basename[ strtolower( $file['filename'] ) ] = [
			'path' => $file['path'],
			'url'  => $file['url'],
		];
	}

	foreach ( $filenames as $filename ) {
		$csv_path = $imports_dir . basename( (string) $filename );
		if ( ! is_readable( $csv_path ) ) {
			continue;
		}
		$parsed_pairs = dtb_parse_catalog_csv_image_pairs( $csv_path, $by_basename, $file_index );
		foreach ( $parsed_pairs as $sku => $pairs ) {
			$cache[ $cache_key ][ $sku ] = $pairs;
		}
	}

	// Only use wp-catalog.csv when no product-wc-*.csv import file is configured
	// or discovered. This enforces exact image filenames from the active
	// WooCommerce import CSV and prevents legacy fallback from mixing in an
	// older wp-catalog.csv import file.
	if ( empty( $filenames ) ) {
		$fallback = $imports_dir . 'wp-catalog.csv';
		if ( is_readable( $fallback ) ) {
			$parsed_pairs = dtb_parse_catalog_csv_image_pairs( $fallback, $by_basename, $file_index );
			foreach ( $parsed_pairs as $sku => $pairs ) {
				$cache[ $cache_key ][ $sku ] = $pairs;
			}
		}
	}

	return $cache[ $cache_key ];
}

/**
 * Parse a single product CSV for SKU => image pairs.
 *
 * @param string $csv_path Absolute CSV path.
 * @param array<string,array{path:string,url:string}> $by_basename Index of filenames by lowercase basename.
 * @param array<int,array{path:string,url:string,filename:string,stem:string,normalized_stem:string,ext:string}> $file_index Indexed files from the scan directory.
 * @return array<string,array<int,array{path:string,url:string}>>
 */
function dtb_parse_catalog_csv_image_pairs( string $csv_path, array $by_basename, array $file_index = [] ): array {
	$result = [];
	$handle = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $handle ) {
		return $result;
	}

	$header = fgetcsv( $handle );
	if ( ! is_array( $header ) ) {
		fclose( $handle );
		return $result;
	}

	$sku_index    = array_search( 'SKU', $header, true );
	$images_index = array_search( 'Images', $header, true );
	if ( false === $sku_index || false === $images_index ) {
		fclose( $handle );
		return $result;
	}

	while ( false !== ( $row = fgetcsv( $handle ) ) ) {
		$sku = isset( $row[ $sku_index ] ) ? strtolower( trim( (string) $row[ $sku_index ] ) ) : '';
		if ( '' === $sku ) {
			continue;
		}

		$image_field = isset( $row[ $images_index ] ) ? trim( (string) $row[ $images_index ] ) : '';
		if ( '' === $image_field ) {
			continue;
		}

		$pairs = [];
		foreach ( dtb_split_catalog_image_field( $image_field ) as $image_url ) {
			$pair = dtb_find_catalog_image_pair( $image_url, $sku, $by_basename, $file_index );
			if ( null === $pair ) {
				continue;
			}
			$pairs[] = $pair;
		}

		if ( ! empty( $pairs ) ) {
			$result[ $sku ] = $pairs;
		}
	}

	fclose( $handle );

	return $result;
}

/**
 * Split a WooCommerce Images field into individual image URL/path values.
 *
 * Current DTB catalogs use a pipe separator. Older or manually-exported
 * WooCommerce files may use comma-separated image values, so handle both.
 *
 * @param string $image_field Raw Images column value.
 * @return string[]
 */

function dtb_get_catalog_image_filenames_by_sku(): array {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$cache = [];
	if ( ! function_exists( 'dtb_get_config' ) ) {
		return $cache;
	}

	$config    = dtb_get_config();
	$filenames = $config['csv_filenames'] ?? [];

	$upload_dir  = wp_upload_dir();
	$imports_dir = trailingslashit( $upload_dir['basedir'] ) . 'wc-imports/';

	foreach ( $filenames as $filename ) {
		$csv_path = $imports_dir . basename( (string) $filename );
		if ( ! is_readable( $csv_path ) ) {
			continue;
		}
		$parsed_filenames = dtb_parse_catalog_csv_image_filenames( $csv_path );
		foreach ( $parsed_filenames as $sku => $images ) {
			$cache[ $sku ] = $images;
		}
	}

	if ( empty( $filenames ) ) {
		$fallback = $imports_dir . 'wp-catalog.csv';
		if ( is_readable( $fallback ) ) {
			$parsed_filenames = dtb_parse_catalog_csv_image_filenames( $fallback );
			foreach ( $parsed_filenames as $sku => $images ) {
				$cache[ $sku ] = $images;
			}
		}
	}

	return $cache;
}

/**
 * Parse a single product CSV for SKU => image filename lists.
 *
 * @param string $csv_path Absolute CSV path.
 * @return array<string,string[]>
 */
function dtb_parse_catalog_csv_image_filenames( string $csv_path ): array {
	$result = [];
	$handle = fopen( $csv_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
	if ( false === $handle ) {
		return $result;
	}

	$header = fgetcsv( $handle );
	if ( ! is_array( $header ) ) {
		fclose( $handle );
		return $result;
	}

	$sku_index    = array_search( 'SKU', $header, true );
	$images_index = array_search( 'Images', $header, true );
	if ( false === $sku_index || false === $images_index ) {
		fclose( $handle );
		return $result;
	}

	while ( false !== ( $row = fgetcsv( $handle ) ) ) {
		$sku = isset( $row[ $sku_index ] ) ? strtolower( trim( (string) $row[ $sku_index ] ) ) : '';
		if ( '' === $sku ) {
			continue;
		}

		$image_field = isset( $row[ $images_index ] ) ? trim( (string) $row[ $images_index ] ) : '';
		if ( '' === $image_field ) {
			continue;
		}

		$image_filenames = [];
		foreach ( dtb_split_catalog_image_field( $image_field ) as $image_url ) {
			$basename = basename( strtok( trim( $image_url ), '?' ) ?: '' );
			if ( '' !== $basename ) {
				$image_filenames[] = $basename;
			}
		}

		if ( ! empty( $image_filenames ) ) {
			$result[ $sku ] = $image_filenames;
		}
	}

	fclose( $handle );

	return $result;
}

/**
 * Return catalog image basenames that are expected but absent from disk.
 *
 * @param string   $dir        Absolute path to the upload directory.
 * @param string   $url        Public base URL for the directory.
 * @param string[] $extensions Allowed image extensions.
 * @return array<string,string[]>
 */
function dtb_get_catalog_missing_image_filenames_by_sku( string $dir, string $url, array $extensions ): array {
	$expected = dtb_get_catalog_image_filenames_by_sku();
	if ( empty( $expected ) ) {
		return [];
	}

	$present = [];
	$file_index = dtb_get_image_file_index( $dir, $url, $extensions );
	$by_basename = [];
	foreach ( $file_index as $file ) {
		$present[ strtolower( $file['filename'] ) ] = true;
		$by_basename[ strtolower( $file['filename'] ) ] = [
			'path' => $file['path'],
			'url'  => $file['url'],
		];
	}

	$missing = [];
	foreach ( $expected as $sku => $filenames ) {
		foreach ( $filenames as $filename ) {
			$filename_lower = strtolower( $filename );
			if (
				! isset( $present[ $filename_lower ] )
				&& null === dtb_find_catalog_image_pair( $filename, $sku, $by_basename, $file_index )
			) {
				$missing[ $sku ][] = $filename;
			}
		}
	}

	return $missing;
}

/**
 * Build a request-local index of top-level image files in a directory.
 *
 * @param string   $dir        Absolute path to the upload directory.
 * @param string   $url        Public base URL for the directory.
 * @param string[] $extensions Allowed image extensions.
 * @return array<int,array{path:string,url:string,filename:string,stem:string,normalized_stem:string,ext:string}>
 */
function dtb_get_image_file_index( string $dir, string $url, array $extensions ): array {
	static $cache = [];

	if ( ! is_dir( $dir ) ) {
		return [];
	}

	$extensions = array_map( 'strtolower', $extensions );
	$cache_key  = md5( trailingslashit( $dir ) . '|' . trailingslashit( $url ) . '|' . implode( ',', $extensions ) );
	if ( isset( $cache[ $cache_key ] ) ) {
		return $cache[ $cache_key ];
	}

	$files    = [];
	$base_dir = trailingslashit( $dir );
	$base_url = trailingslashit( $url );
	$it       = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $it as $file ) {
		if ( ! $file->isFile() ) {
			continue;
		}

		$ext = strtolower( $file->getExtension() );
		if ( ! in_array( $ext, $extensions, true ) ) {
			continue;
		}

		$stem = strtolower( $file->getBasename( '.' . $ext ) );
		if ( preg_match( '/-\d+x\d+$/', $stem ) ) {
			continue;
		}
		$relative_path = str_replace( '\\', '/', substr( $file->getPathname(), strlen( $base_dir ) ) );

		$files[] = [
			'path'            => $file->getPathname(),
			'url'             => $base_url . $relative_path,
			'filename'        => $file->getFilename(),
			'stem'            => $stem,
			'normalized_stem' => str_replace( '_', '-', $stem ),
			'ext'             => $ext,
		];
	}

	usort( $files, static fn( $a, $b ) => strcmp( $a['path'], $b['path'] ) );
	$cache[ $cache_key ] = $files;

	return $files;
}

/**
 * Find all files matching {sku}_{hash}.{ext} in a directory.
 *
 * Handles the Platinum Drywall Tools naming convention where images are stored
 * as {sku}_{8-char-hex}.{ext} (e.g. pt-10fb_7316520b.webp). Unlike the numeric
 * _01/_02 gallery suffix convention used by most brands, Platinum images have an
 * opaque hash suffix. This function scans the directory for any file whose stem
 * begins with "{sku}_" and whose suffix is hex-only ([0-9a-f]{6,16}).
 *
 * Returns an array of [ 'path' => ..., 'url' => ... ] sorted by filename so the
 * ordering is deterministic across runs. Caller should treat index 0 as primary.
 *
 * @param string   $dir        Absolute path to the upload year/month directory.
 * @param string   $url        Public base URL for the same directory.
 * @param string   $sku_lower  Lower-case SKU (e.g. 'pt-10fb').
 * @param string[] $extensions Allowed image extensions.
 * @return array<int, array{path: string, url: string}>
 */

