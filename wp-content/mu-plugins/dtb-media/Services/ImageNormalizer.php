<?php
defined( 'ABSPATH' ) || exit;

function dtb_image_sync_parse_gallery_ids( string $gallery_meta ): array {
	$ids = array_map( 'absint', explode( ',', $gallery_meta ) );
	return array_values( array_filter( $ids ) );
}

/**
 * Map a count-based health state to a stable label for UI rendering.
 *
 * @param int  $errors   Hard failures.
 * @param int  $warnings Soft drift or cleanup signals.
 * @param bool $running  Whether a sync is actively running.
 * @param bool $blocked  Whether the state is blocked by a stuck lock.
 * @return string
 */
function dtb_image_sync_health_label( int $errors, int $warnings, bool $running = false, bool $blocked = false ): string {
	if ( $blocked ) {
		return 'blocked';
	}
	if ( $running ) {
		return 'running';
	}
	if ( $errors > 0 ) {
		return 'error';
	}
	if ( $warnings > 0 ) {
		return 'warning';
	}
	return 'healthy';
}

/**
 * Build a full end-to-end reconciliation snapshot for the image sync dashboard.
 *
 * The snapshot reconciles:
 *   1. active catalog CSV image expectations
 *   2. files physically present on disk
 *   3. registered media attachments
 *   4. WooCommerce product image links
 *
 * @param string $relative_path Relative directory under wp-content/uploads/.
 * @return array<string,mixed>
 */

function dtb_split_catalog_image_field( string $image_field ): array {
	$image_field = trim( $image_field );
	if ( '' === $image_field ) {
		return [];
	}

	$delimiter = str_contains( $image_field, '|' ) ? '/\s*\|\s*/' : '/\s*,\s*/';
	$parts     = preg_split( $delimiter, $image_field ) ?: [];

	return array_values( array_filter( array_map( 'trim', $parts ), static fn( $value ) => '' !== $value ) );
}

/**
 * Resolve a catalog image URL/path to a physical file found in the scan dir.
 *
 * Exact basename matching ONLY. The basename of $image_url (after URL-decoding
 * and case-folding) must match a key in $by_basename — no fuzzy SKU-stem
 * guessing, no ordinal suffix scanning.
 *
 * @param string $image_url  Catalog image URL or path.
 * @param string $sku_lower  Lower-case product SKU (unused; retained for signature compatibility).
 * @param array<string,array{path:string,url:string}> $by_basename Index of exact basenames.
 * @param array<int,array<string,string>> $file_index Unused; retained for signature compatibility.
 * @return array{path:string,url:string}|null
 */
function dtb_find_catalog_image_pair( string $image_url, string $sku_lower, array $by_basename, array $file_index = [] ): ?array {
	$path_part = strtok( trim( $image_url ), '?' );
	$basename  = strtolower( rawurldecode( basename( false !== $path_part ? $path_part : '' ) ) );
	if ( '' === $basename ) {
		return null;
	}
	return $by_basename[ $basename ] ?? null;
}

/**
 * Return exact image basenames from the active import CSV, keyed by SKU.
 *
 * @return array<string,string[]>
 */

