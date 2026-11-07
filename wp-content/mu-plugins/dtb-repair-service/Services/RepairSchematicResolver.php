<?php
/**
 * Services — Repair schematic resolver and sync metadata.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate an input schematic source URL.
 */
function dtb_repair_schematic_is_valid_source_url( string $url ): bool {
	$url = trim( $url );
	if ( '' === $url ) {
		return false;
	}
	if ( ! wp_http_validate_url( $url ) ) {
		return false;
	}
	$scheme = (string) wp_parse_url( $url, PHP_URL_SCHEME );
	return in_array( strtolower( $scheme ), [ 'http', 'https' ], true );
}

/**
 * Resolve a schematic attachment from the canonical dtb-schematics catalog.
 *
 * @return array<string,mixed>
 */
function dtb_repair_resolve_schematic_catalog_match( int $repair_id, string $schematic_ref = '', string $source_url = '', string $catalog_id = '' ): array {
	$repair_model = trim( (string) get_post_meta( $repair_id, '_repair_model', true ) );
	$repair_brand = trim( (string) get_post_meta( $repair_id, '_repair_tool_brand', true ) );

	$source_path = '';
	if ( '' !== $source_url ) {
		$source_path = strtolower( (string) wp_parse_url( $source_url, PHP_URL_PATH ) );
	}

	$candidates = [];

	if ( '' !== trim( $catalog_id ) ) {
		$candidates[] = [
			'key'     => '_dtb_schematic_id',
			'value'   => sanitize_text_field( trim( $catalog_id ) ),
			'compare' => '=',
		];
	}
	if ( '' !== trim( $schematic_ref ) ) {
		$ref = sanitize_text_field( trim( $schematic_ref ) );
		$candidates[] = [ 'key' => '_dtb_schematic_id', 'value' => $ref, 'compare' => '=' ];
		$candidates[] = [ 'key' => '_dtb_schematic_model_number', 'value' => $ref, 'compare' => '=' ];
	}
	if ( '' !== $repair_model ) {
		$candidates[] = [
			'key'     => '_dtb_schematic_model_number',
			'value'   => sanitize_text_field( $repair_model ),
			'compare' => '=',
		];
	}

	foreach ( $candidates as $meta_clause ) {
		$meta_query = [
			[
				'relation' => 'OR',
				[
					'key'     => '_dtb_is_schematic',
					'value'   => '1',
					'compare' => '=',
				],
				[
					'key'     => '_dtb_schematic_id',
					'value'   => '',
					'compare' => '!=',
				],
			],
			$meta_clause,
		];
		if ( '' !== $repair_brand ) {
			$meta_query[] = [
				'key'     => '_dtb_schematic_brand',
				'value'   => sanitize_text_field( $repair_brand ),
				'compare' => '=',
			];
		}

		$matched = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => $meta_query,
			]
		);
		if ( ! empty( $matched ) ) {
			return dtb_repair_build_schematic_catalog_payload( (int) $matched[0] );
		}
	}

	if ( '' !== $source_path ) {
		$attachments = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'relation' => 'OR',
						[
							'key'     => '_dtb_is_schematic',
							'value'   => '1',
							'compare' => '=',
						],
						[
							'key'     => '_dtb_schematic_id',
							'value'   => '',
							'compare' => '!=',
						],
					],
				],
			]
		);

		foreach ( (array) $attachments as $attachment_id ) {
			$catalog_url = (string) wp_get_attachment_url( (int) $attachment_id );
			$catalog_path = strtolower( (string) wp_parse_url( $catalog_url, PHP_URL_PATH ) );
			if ( '' !== $catalog_path && $catalog_path === $source_path ) {
				return dtb_repair_build_schematic_catalog_payload( (int) $attachment_id );
			}
		}
	}

	return [];
}

/**
 * Build normalized schematic payload from the schematics catalog.
 *
 * @return array<string,mixed>
 */
function dtb_repair_build_schematic_catalog_payload( int $attachment_id ): array {
	$attachment_id = absint( $attachment_id );
	if ( $attachment_id <= 0 ) {
		return [];
	}

	$url          = (string) wp_get_attachment_url( $attachment_id );
	$schematic_id = (string) get_post_meta( $attachment_id, '_dtb_schematic_id', true );
	$brand        = (string) get_post_meta( $attachment_id, '_dtb_schematic_brand', true );
	$model_number = (string) get_post_meta( $attachment_id, '_dtb_schematic_model_number', true );
	$model_name   = (string) get_post_meta( $attachment_id, '_dtb_schematic_model_name', true );
	$part_count   = (int) get_post_meta( $attachment_id, '_dtb_schematic_part_count', true );
	$file_path    = (string) get_attached_file( $attachment_id );
	$checksum     = ( '' !== $file_path && file_exists( $file_path ) ) ? hash_file( 'sha256', $file_path ) : hash( 'sha256', $url );
	$version      = get_post_modified_time( 'Y-m-d\TH:i:s\Z', true, $attachment_id );

	return [
		'attachment_id' => $attachment_id,
		'schematic_id'  => $schematic_id,
		'brand'         => $brand,
		'model_number'  => $model_number,
		'model_name'    => $model_name,
		'part_count'    => $part_count,
		'url'           => $url,
		'checksum'      => $checksum,
		'version'       => $version,
	];
}

/**
 * Persist schematic sync metadata on a repair.
 */
function dtb_repair_sync_schematic_metadata(
	int $repair_id,
	string $source_url,
	string $schematic_ref,
	string $schematic_revision,
	string $catalog_id = ''
): array {
	$source_url = trim( $source_url );
	$source_host = '';
	if ( dtb_repair_schematic_is_valid_source_url( $source_url ) ) {
		$source_host = (string) wp_parse_url( $source_url, PHP_URL_HOST );
	}

	$resolved = dtb_repair_resolve_schematic_catalog_match( $repair_id, $schematic_ref, $source_url, $catalog_id );

	$snapshot = [
		'resolver_version'   => '1',
		'synced_at_gmt'      => gmdate( 'c' ),
		'source_url'         => $source_url,
		'source_host'        => $source_host,
		'user_ref'           => $schematic_ref,
		'user_revision'      => $schematic_revision,
		'catalog_requested'  => $catalog_id,
		'catalog_attachment' => (int) ( $resolved['attachment_id'] ?? 0 ),
		'catalog_id'         => (string) ( $resolved['schematic_id'] ?? '' ),
		'catalog_url'        => (string) ( $resolved['url'] ?? '' ),
		'catalog_brand'      => (string) ( $resolved['brand'] ?? '' ),
		'catalog_model'      => (string) ( $resolved['model_number'] ?? '' ),
		'catalog_version'    => (string) ( $resolved['version'] ?? '' ),
		'catalog_checksum'   => (string) ( $resolved['checksum'] ?? '' ),
	];

	update_post_meta( $repair_id, '_repair_schematic_sync_snapshot', wp_json_encode( $snapshot ) );
	update_post_meta( $repair_id, '_repair_schematic_catalog_attachment_id', (int) $snapshot['catalog_attachment'] );
	update_post_meta( $repair_id, '_repair_schematic_catalog_id', $snapshot['catalog_id'] );
	update_post_meta( $repair_id, '_repair_schematic_catalog_url', $snapshot['catalog_url'] );

	$history = get_post_meta( $repair_id, '_repair_schematic_sync_history', true );
	$history = is_array( $history ) ? $history : [];
	$history[] = $snapshot;
	if ( count( $history ) > 25 ) {
		$history = array_slice( $history, -25 );
	}
	update_post_meta( $repair_id, '_repair_schematic_sync_history', $history );

	return $snapshot;
}

/**
 * Decode the latest schematic sync snapshot for admin rendering.
 *
 * @return array<string,mixed>
 */
function dtb_repair_get_schematic_sync_snapshot( int $repair_id ): array {
	$raw = (string) get_post_meta( $repair_id, '_repair_schematic_sync_snapshot', true );
	if ( '' === $raw ) {
		return [];
	}
	$decoded = json_decode( $raw, true );
	return is_array( $decoded ) ? $decoded : [];
}
