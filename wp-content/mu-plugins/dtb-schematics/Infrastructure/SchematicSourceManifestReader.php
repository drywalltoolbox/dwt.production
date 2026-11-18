<?php
/**
 * DTB Schematics — SchematicSourceManifestReader
 *
 * Deterministic reader for the canonical schematic source assets. Two data
 * sources are supported, in priority order:
 *
 *   1. schematic_source_manifest.csv (schematic_id/brand/sku_or_alias/page/
 *      filename/checksum_sha256/size_bytes) — a git-repo-only artifact that
 *      lives at products/launch/media/schematics/ in this checkout. Used
 *      when present as an optimization/cross-check (pre-computed checksums,
 *      explicit brand/sku columns).
 *   2. Direct filesystem scan of uploaded image binaries — the ONLY data
 *      source available on a real deployed WordPress host, which has no git
 *      checkout of this repository and therefore no manifest CSV. Identity
 *      (schematic_id/page) is derived via the existing authoritative
 *      dtb_schematics_parse_upload_filename() / dtb_schematics_retired_upload_reason()
 *      (Application/RegisterSchematicUploads.php); checksums are computed
 *      live via hash_file('sha256', ...).
 *
 * Both sources are searched across every candidate source directory
 * (dtb_schematics_source_candidate_directories()), which on a production
 * host means every existing wp-content/uploads/{year}/schematics directory
 * — not a single hardcoded year — plus (only when present, e.g. a local dev
 * checkout of this repository) the repo-relative canonical source package
 * directory.
 *
 * This is a read-only infrastructure component. It never writes to the
 * source package and never writes domain state — see
 * Application/ReconcileSchematicSource.php for the engine that consumes it.
 *
 * The source package may not exist on every runtime. Callers must treat an
 * absent source directory as an explicit, reportable condition rather than
 * a fatal error.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_SOURCE_MANIFEST_FILENAME = 'schematic_source_manifest.csv';
const DTB_SCHEMATIC_PRIMARY_UPLOADS_YEAR      = '2026';

/**
 * Extensions recognized as schematic source binaries when deriving manifest
 * rows directly from uploaded files (no CSV present).
 */
const DTB_SCHEMATIC_SOURCE_IMAGE_EXTENSIONS = [ 'webp', 'jpg', 'jpeg', 'png' ];

/**
 * Resolve the primary/first canonical source package directory. Kept for
 * backward compatibility (Configuration screen, existing callers) and as the
 * highest-priority entry in dtb_schematics_source_candidate_directories().
 *
 * Overridable via the `dtb_schematics_source_package_dir` filter for hosts
 * where an explicit single directory should be used instead of the built-in
 * discovery logic below.
 */
function dtb_schematics_source_package_dir(): string {
	$default = dtb_schematics_default_source_package_dir();

	/**
	 * Filter the canonical (primary) schematic source package directory.
	 *
	 * @param string $default Computed default path.
	 */
	return (string) apply_filters( 'dtb_schematics_source_package_dir', $default );
}

/**
 * Compute the built-in default source directory with no filter applied:
 *   1. wp-content/uploads/2026/schematics, the production SiteGround source.
 *   2. Other existing uploads/{year}/schematics directories, newest first,
 *      remain read-only fallbacks for records intentionally stored elsewhere.
 *   3. The repo-relative canonical package, when this runtime has a local
 *      development checkout and no uploads source exists.
 *   4. If none exists on disk yet, the expected uploads/2026/schematics
 *      path is still returned (even though it does not yet exist) so the
 *      Configuration screen shows an actionable, self-explanatory path
 *      rather than an empty string.
 */
function dtb_schematics_default_source_package_dir(): string {
	$uploads_years = dtb_schematics_discover_uploads_year_directories();
	if ( ! empty( $uploads_years ) ) {
		foreach ( $uploads_years as $uploads_year_dir ) {
			if ( '/' . DTB_SCHEMATIC_PRIMARY_UPLOADS_YEAR . '/schematics' === substr( str_replace( '\\', '/', $uploads_year_dir ), -16 ) ) {
				return $uploads_year_dir;
			}
		}
		return reset( $uploads_years );
	}

	if ( defined( 'ABSPATH' ) ) {
		// ABSPATH -> .../drywalltoolbox/wp/ ; repo root is two levels up.
		$wp_root   = untrailingslashit( ABSPATH );
		$repo_root = dirname( dirname( $wp_root ) );
		$repo_dir  = $repo_root . '/products/launch/media/schematics';
		if ( is_dir( $repo_dir ) ) {
			return $repo_dir;
		}
	}

	if ( function_exists( 'wp_upload_dir' ) ) {
		$uploads = wp_upload_dir();
		if ( ! is_wp_error( $uploads ) && is_array( $uploads ) && ! empty( $uploads['basedir'] ) ) {
			return untrailingslashit( (string) $uploads['basedir'] ) . '/' . DTB_SCHEMATIC_PRIMARY_UPLOADS_YEAR . '/schematics';
		}
	}

	return '';
}

/**
 * Every existing wp-content/uploads/{year}/schematics directory, newest
 * year first. Deliberately does not assume any particular year — schematics
 * may be uploaded in different years over time.
 *
 * @return string[] Absolute paths, keyed loosely by year (values only after array_values()).
 */
function dtb_schematics_discover_uploads_year_directories(): array {
	if ( ! function_exists( 'wp_upload_dir' ) ) {
		return [];
	}
	$uploads = wp_upload_dir();
	$basedir = ! is_wp_error( $uploads ) && is_array( $uploads ) && isset( $uploads['basedir'] )
		? untrailingslashit( (string) $uploads['basedir'] )
		: '';
	if ( '' === $basedir || ! is_dir( $basedir ) ) {
		return [];
	}

	$years = [];
	foreach ( ( scandir( $basedir ) ?: [] ) as $entry ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_scandir
		if ( ! preg_match( '/^\d{4}$/', $entry ) ) {
			continue;
		}
		$schematics_dir = trailingslashit( $basedir ) . $entry . '/schematics';
		if ( is_dir( $schematics_dir ) ) {
			$years[ $entry ] = $schematics_dir;
		}
	}
	krsort( $years ); // Newest year first.
	return array_values( $years );
}

/**
 * Every directory that should be searched for source binaries/manifest,
 * highest priority first: the resolved primary directory (filter-overridable
 * via dtb_schematics_source_package_dir), then every other existing
 * wp-content/uploads/{year}/schematics directory not already covered.
 *
 * @return string[]
 */
function dtb_schematics_source_candidate_directories(): array {
	$candidates = [];

	$primary = dtb_schematics_source_package_dir();
	if ( '' !== $primary && is_dir( $primary ) ) {
		$candidates[] = untrailingslashit( $primary );
	}

	foreach ( dtb_schematics_discover_uploads_year_directories() as $dir ) {
		$dir = untrailingslashit( $dir );
		if ( ! in_array( $dir, $candidates, true ) ) {
			$candidates[] = $dir;
		}
	}

	return $candidates;
}

/**
 * Source manifests may name files only, never paths.
 */
function dtb_schematics_source_filename_is_safe( string $filename ): bool {
	return '' !== $filename
		&& $filename === basename( $filename )
		&& ! preg_match( '/[\x00-\x1F\x7F]/', $filename )
		&& in_array( strtolower( (string) pathinfo( $filename, PATHINFO_EXTENSION ) ), DTB_SCHEMATIC_SOURCE_IMAGE_EXTENSIONS, true );
}

/**
 * Whether the canonical source package is reachable from this runtime —
 * either a manifest CSV or at least one recognizable image file, in any
 * candidate directory.
 */
function dtb_schematics_source_package_available(): bool {
	foreach ( dtb_schematics_source_candidate_directories() as $dir ) {
		if ( is_file( trailingslashit( $dir ) . DTB_SCHEMATIC_SOURCE_MANIFEST_FILENAME ) ) {
			return true;
		}
		if ( dtb_schematics_directory_has_image_files( $dir ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Which data source is currently effective: 'manifest_csv' when a manifest
 * CSV is present in any candidate directory (preferred when available),
 * 'filesystem_scan' when no CSV exists anywhere but at least one candidate
 * directory has recognizable image files (the expected production path), or
 * 'unavailable' when nothing usable was found.
 */
function dtb_schematics_source_resolution_mode(): string {
	$candidates = dtb_schematics_source_candidate_directories();

	foreach ( $candidates as $dir ) {
		if ( is_file( trailingslashit( $dir ) . DTB_SCHEMATIC_SOURCE_MANIFEST_FILENAME ) ) {
			return 'manifest_csv';
		}
	}
	foreach ( $candidates as $dir ) {
		if ( dtb_schematics_directory_has_image_files( $dir ) ) {
			return 'filesystem_scan';
		}
	}
	return 'unavailable';
}

/**
 * Per-directory diagnostic summary for operator self-diagnosis (Configuration
 * Reference screen).
 *
 * @return array<int, array{directory:string, exists:bool, has_manifest_csv:bool, image_file_count:int}>
 */
function dtb_schematics_source_resolution_summary(): array {
	$rows = [];
	foreach ( dtb_schematics_source_candidate_directories() as $dir ) {
		$rows[] = [
			'directory'         => $dir,
			'exists'            => is_dir( $dir ),
			'has_manifest_csv'  => is_file( trailingslashit( $dir ) . DTB_SCHEMATIC_SOURCE_MANIFEST_FILENAME ),
			'image_file_count'  => count( dtb_schematics_scan_directory_image_files( $dir ) ),
		];
	}
	return $rows;
}

/**
 * Recognizable schematic source image filenames (not full paths) directly
 * inside a directory, sorted for determinism. Excludes the manifest CSV
 * itself.
 *
 * @return string[]
 */
function dtb_schematics_scan_directory_image_files( string $dir ): array {
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return [];
	}
	$files = [];
	foreach ( ( scandir( $dir ) ?: [] ) as $entry ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_scandir
		if ( '.' === $entry || '..' === $entry || DTB_SCHEMATIC_SOURCE_MANIFEST_FILENAME === $entry ) {
			continue;
		}
		$path = trailingslashit( $dir ) . $entry;
		if ( ! is_file( $path ) ) {
			continue;
		}
		$ext = strtolower( (string) pathinfo( $entry, PATHINFO_EXTENSION ) );
		if ( in_array( $ext, DTB_SCHEMATIC_SOURCE_IMAGE_EXTENSIONS, true ) && ! dtb_schematics_is_generated_image_derivative_filename( $entry ) ) {
			$files[] = $entry;
		}
	}
	sort( $files );
	return $files;
}

/**
 * WordPress creates responsive derivatives beside the original attachment.
 * They are projections, never independent schematic source pages.
 */
function dtb_schematics_is_generated_image_derivative_filename( string $filename ): bool {
	return 1 === preg_match( '/(?:-\d+x\d+|-scaled|-rotated)\.(?:webp|jpe?g|png)$/i', basename( $filename ) );
}

function dtb_schematics_directory_has_image_files( string $dir ): bool {
	return ! empty( dtb_schematics_scan_directory_image_files( $dir ) );
}

/**
 * Resolve the manifest source: prefer a manifest CSV if any candidate
 * directory has one (first found wins, in candidate priority order),
 * otherwise fall back to deriving an equivalent row set directly from the
 * uploaded files themselves (the required production path — no CSV is ever
 * deployed to a live WordPress host).
 *
 * @return array{ok:bool, error?:string, rows: array[], source?:string}
 */
function dtb_schematics_read_source_manifest(): array {
	$candidates = dtb_schematics_source_candidate_directories();
	if ( empty( $candidates ) ) {
		return [ 'ok' => false, 'error' => 'source_package_directory_not_found', 'rows' => [] ];
	}

	foreach ( $candidates as $dir ) {
		if ( is_file( trailingslashit( $dir ) . DTB_SCHEMATIC_SOURCE_MANIFEST_FILENAME ) ) {
			$manifest = dtb_schematics_read_manifest_file( $dir, DTB_SCHEMATIC_SOURCE_MANIFEST_FILENAME );
			if ( $manifest['ok'] ) {
				$manifest['source'] = 'manifest_csv';
				return $manifest;
			}
			// A CSV exists but failed to parse (malformed) — surface that error
			// rather than silently degrading to a filesystem scan that would
			// mask a real authoring bug in the manifest.
			return $manifest;
		}
	}

	$scanned = dtb_schematics_scan_source_directories();
	if ( $scanned['ok'] ) {
		$scanned['source'] = 'filesystem_scan';
	}
	return $scanned;
}

/**
 * Derive a manifest-row-shaped array directly from image files present in
 * the candidate directories — the no-CSV production code path. Checksums
 * are computed live via hash_file(). Identity (schematic_id/page) is left to
 * the caller's own resolution via dtb_schematics_parse_upload_filename()
 * (Application/RegisterSchematicUploads.php); the best-effort schematic_id/
 * page/brand/sku_or_alias populated here are for human-readable reporting
 * only, never for authoritative identity — see
 * Application/ReconcileSchematicSource.php, which re-derives identity from
 * `filename` regardless of what this function fills in here.
 *
 * @return array{ok:bool, error?:string, rows: array[]}
 */
function dtb_schematics_scan_source_directories(): array {
	$candidates = dtb_schematics_source_candidate_directories();
	if ( empty( $candidates ) ) {
		return [ 'ok' => false, 'error' => 'source_package_directory_not_found', 'rows' => [] ];
	}

	$rows           = [];
	$seen_filenames = [];

	foreach ( $candidates as $dir ) {
		foreach ( dtb_schematics_scan_directory_image_files( $dir ) as $filename ) {
			if ( isset( $seen_filenames[ $filename ] ) ) {
				continue; // Same filename already found in a higher-priority candidate.
			}
			$seen_filenames[ $filename ] = true;

			$path     = trailingslashit( $dir ) . $filename;
			$identity = dtb_schematics_derive_manifest_row_identity_from_filename( $filename );

			$rows[] = [
				'schematic_id'    => $identity['schematic_id'],
				'brand'           => $identity['brand'],
				'sku_or_alias'    => $identity['sku_or_alias'],
				'page'            => $identity['page'],
				'filename'        => $filename,
				'checksum_sha256' => strtolower( (string) hash_file( 'sha256', $path ) ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents
				'size_bytes'      => (int) filesize( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_filesize
			];
		}
	}

	if ( empty( $rows ) ) {
		return [ 'ok' => false, 'error' => 'source_package_empty', 'rows' => [] ];
	}

	// Same deterministic ordering as CSV-sourced rows, so batch offsets stay
	// reproducible regardless of which data source produced them.
	usort(
		$rows,
		static function ( $a, $b ) {
			return [ $a['schematic_id'], $a['page'], $a['filename'] ] <=> [ $b['schematic_id'], $b['page'], $b['filename'] ];
		}
	);

	return [ 'ok' => true, 'rows' => $rows ];
}

/**
 * Best-effort schematic_id/page/brand/sku_or_alias for reporting purposes
 * only, using the same authoritative parser the reconciliation engine itself
 * uses for identity resolution
 * (Application/RegisterSchematicUploads.php::dtb_schematics_parse_upload_filename()).
 * Guarded with function_exists() because this Infrastructure file loads
 * before Application/RegisterSchematicUploads.php in the module boot order;
 * by the time any caller actually invokes this scan at runtime both files
 * are loaded, but the guard keeps this file safely self-contained.
 *
 * @return array{schematic_id:string, brand:string, sku_or_alias:string, page:int}
 */
function dtb_schematics_derive_manifest_row_identity_from_filename( string $filename ): array {
	$schematic_id = '';
	$page         = 0;

	if ( function_exists( 'dtb_schematics_retired_upload_reason' ) && function_exists( 'dtb_schematics_parse_upload_filename' ) ) {
		if ( null === dtb_schematics_retired_upload_reason( $filename ) ) {
			$parsed = dtb_schematics_parse_upload_filename( $filename );
			if ( ! is_wp_error( $parsed ) ) {
				$schematic_id = (string) $parsed['schematic_id'];
				$page         = (int) $parsed['page'];
			}
		}
	}

	// Best-effort brand/SKU extraction from the canonical
	// {brand}_{sku-or-alias}_sch-page-{n}.ext naming convention, for display
	// only — never used for identity resolution.
	$brand        = '';
	$sku_or_alias = '';
	$name         = pathinfo( $filename, PATHINFO_FILENAME );
	if ( preg_match( '/^([a-z0-9-]+)_(.+?)_sch(?:[-_].+)?$/i', $name, $matches ) ) {
		$brand        = strtolower( $matches[1] );
		$sku_or_alias = strtoupper( $matches[2] );
	}

	return [
		'schematic_id' => $schematic_id,
		'brand'        => $brand,
		'sku_or_alias' => $sku_or_alias,
		'page'         => $page,
	];
}

/**
 * Testable core: parses a manifest CSV located in an arbitrary directory.
 * Split out from dtb_schematics_read_source_manifest() so the static
 * verification harness (scripts/catalog/reconcile_schematics_dry_run_harness.php)
 * can exercise real parsing logic without a WordPress bootstrap.
 *
 * @return array{ok:bool, error?:string, rows: array[]}
 */
function dtb_schematics_read_manifest_file( string $dir, string $filename ): array {
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return [ 'ok' => false, 'error' => 'source_package_directory_not_found', 'rows' => [] ];
	}

	$path = trailingslashit( $dir ) . $filename;
	if ( ! is_file( $path ) ) {
		return [ 'ok' => false, 'error' => 'source_manifest_file_not_found', 'rows' => [] ];
	}

	$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen
	if ( ! $handle ) {
		return [ 'ok' => false, 'error' => 'source_manifest_unreadable', 'rows' => [] ];
	}

	$header = fgetcsv( $handle, null, ',', '"', '\\' );
	if ( ! is_array( $header ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
		return [ 'ok' => false, 'error' => 'source_manifest_empty', 'rows' => [] ];
	}

	// Strip a UTF-8 BOM from the first header cell if present.
	$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $header[0] );
	$header    = array_map( 'trim', $header );

	$required = [ 'schematic_id', 'brand', 'sku_or_alias', 'page', 'filename', 'checksum_sha256', 'size_bytes' ];
	foreach ( $required as $col ) {
		if ( ! in_array( $col, $header, true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return [ 'ok' => false, 'error' => 'source_manifest_missing_column:' . $col, 'rows' => [] ];
		}
	}

	$rows = [];
	while ( false !== ( $line = fgetcsv( $handle, null, ',', '"', '\\' ) ) ) {
		if ( 1 === count( $line ) && null === $line[0] ) {
			continue; // Blank line.
		}
		$row = array_combine( $header, array_pad( $line, count( $header ), '' ) );
		if ( false === $row ) {
			continue;
		}
		$source_filename = trim( (string) $row['filename'] );
		if ( ! dtb_schematics_source_filename_is_safe( $source_filename ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose
			return [ 'ok' => false, 'error' => 'source_manifest_invalid_filename', 'rows' => [] ];
		}
		$rows[] = [
			'schematic_id'    => trim( (string) $row['schematic_id'] ),
			'brand'           => trim( (string) $row['brand'] ),
			'sku_or_alias'    => trim( (string) $row['sku_or_alias'] ),
			'page'            => (int) $row['page'],
			'filename'        => $source_filename,
			'checksum_sha256' => strtolower( trim( (string) $row['checksum_sha256'] ) ),
			'size_bytes'      => (int) $row['size_bytes'],
		];
	}
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose

	// Deterministic ordering: source-manifest identity, then page, then filename.
	usort(
		$rows,
		static function ( $a, $b ) {
			return [ $a['schematic_id'], $a['page'], $a['filename'] ] <=> [ $b['schematic_id'], $b['page'], $b['filename'] ];
		}
	);

	return [ 'ok' => true, 'rows' => $rows ];
}

/**
 * Describe the source binary for a manifest row: existence, actual size, and
 * (only when requested — checksum computation is not free) actual checksum.
 * Searches every candidate source directory, not just the primary one, so a
 * row can be located regardless of which uploads/{year}/schematics
 * directory (or the dev-only repo-relative directory) actually holds it.
 *
 * @return array{exists:bool, path:string, size_bytes:int, checksum_sha256:string}
 */
function dtb_schematics_describe_source_binary( string $filename, bool $compute_checksum = true ): array {
	if ( ! dtb_schematics_source_filename_is_safe( $filename ) ) {
		return [ 'exists' => false, 'path' => '', 'size_bytes' => 0, 'checksum_sha256' => '' ];
	}
	foreach ( dtb_schematics_source_candidate_directories() as $dir ) {
		$path = trailingslashit( $dir ) . $filename;
		if ( is_file( $path ) ) {
			return [
				'exists'          => true,
				'path'            => $path,
				'size_bytes'      => (int) filesize( $path ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_filesize
				'checksum_sha256' => $compute_checksum ? (string) hash_file( 'sha256', $path ) : '',
			];
		}
	}

	return [ 'exists' => false, 'path' => '', 'size_bytes' => 0, 'checksum_sha256' => '' ];
}

/**
 * Resolve the wp-content/uploads-relative path (e.g. "2026/schematics/foo.webp")
 * for a source filename, by locating which candidate directory actually
 * contains it and expressing that directory relative to the uploads
 * basedir. Returns null when the file was not found under any candidate
 * directory, or the directory it was found in is not under wp-content/uploads
 * at all (e.g. the dev-only repo-relative source package directory) — callers
 * should fall back to their own configured/default uploads path in that case.
 */
function dtb_schematics_relative_uploads_file_for_filename( string $filename ): ?string {
	if ( ! dtb_schematics_source_filename_is_safe( $filename ) ) {
		return null;
	}
	if ( ! function_exists( 'wp_upload_dir' ) ) {
		return null;
	}
	$uploads = wp_upload_dir();
	$basedir = ! is_wp_error( $uploads ) && is_array( $uploads ) && isset( $uploads['basedir'] )
		? untrailingslashit( str_replace( '\\', '/', (string) $uploads['basedir'] ) )
		: '';
	if ( '' === $basedir ) {
		return null;
	}

	foreach ( dtb_schematics_source_candidate_directories() as $dir ) {
		$normalized_dir = untrailingslashit( str_replace( '\\', '/', $dir ) );
		$path           = $normalized_dir . '/' . $filename;
		if ( ! is_file( $path ) ) {
			continue;
		}
		if ( 0 === strpos( $normalized_dir . '/', $basedir . '/' ) ) {
			$relative_dir = ltrim( substr( $normalized_dir, strlen( $basedir ) ), '/' );
			return '' === $relative_dir ? $filename : $relative_dir . '/' . $filename;
		}
		return null; // Found, but outside wp-content/uploads (dev repo-relative dir).
	}

	return null;
}
