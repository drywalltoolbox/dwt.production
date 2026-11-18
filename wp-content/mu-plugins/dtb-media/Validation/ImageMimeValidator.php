<?php
defined( 'ABSPATH' ) || exit;

function dtb_probe_image( string $dir, string $url, string $stem, array $extensions ): array {
	$index = dtb_get_image_file_index( $dir, $url, $extensions );
	if ( empty( $index ) ) {
		return [ null, null ];
	}

	$stem_lower      = strtolower( $stem );
	$normalized_stem = str_replace( '_', '-', $stem_lower );
	$ordinal         = null;
	$base_stem       = $normalized_stem;

	if ( 1 === preg_match( '/^(.+)[_-](\d{2})$/', $stem_lower, $m ) ) {
		$base_stem = str_replace( '_', '-', $m[1] );
		$ordinal   = $m[2];
	}

	foreach ( $extensions as $ext ) {
		$ext = strtolower( $ext );
		foreach ( $index as $file ) {
			if ( $file['ext'] !== $ext ) {
				continue;
			}

			if ( $file['stem'] === $stem_lower || $file['normalized_stem'] === $normalized_stem ) {
				return [ $file['path'], $file['url'] ];
			}

			// Current catalog filenames are SEO slugs ending in -{SKU}-01.webp.
			if ( null === $ordinal && str_ends_with( $file['normalized_stem'], '-' . $base_stem . '-01' ) ) {
				return [ $file['path'], $file['url'] ];
			}

			if ( null !== $ordinal && str_ends_with( $file['normalized_stem'], '-' . $base_stem . '-' . $ordinal ) ) {
				return [ $file['path'], $file['url'] ];
			}
		}
	}

	return [ null, null ];
}

/**
 * Load exact image filenames from the active WooCommerce import CSV.
 *
 * The catalog is the source of truth for current product images. Its Images
 * column contains full URLs; this helper maps SKU => local upload file pairs
 * by basename so sync/link-only do not infer filenames from SKU conventions.
 *
 * @param string   $dir        Absolute path to the upload directory.
 * @param string   $url        Public base URL for the directory.
 * @param string[] $extensions Allowed image extensions.
 * @return array<string,array<int,array{path:string,url:string}>>
 */

