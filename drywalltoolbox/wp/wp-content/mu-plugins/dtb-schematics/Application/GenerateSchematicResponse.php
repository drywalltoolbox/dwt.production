<?php
/**
 * DTB Schematics — GenerateSchematicResponse (application service).
 *
 * Builds public-safe response DTOs from an authoritative schematic record.
 *
 * Never expose local filesystem paths, unpublished records, administrative
 * notes, raw WordPress metadata, or implementation-specific WooCommerce
 * internals.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_schematics_public_catalog_version_option_name(): string {
	return 'dtb_schematics_public_catalog_version';
}

function dtb_schematics_public_catalog_version(): int {
	$version = (int) get_option( dtb_schematics_public_catalog_version_option_name(), 1 );
	return $version > 0 ? $version : 1;
}

function dtb_schematics_bump_public_catalog_version(): int {
	$next = dtb_schematics_public_catalog_version() + 1;
	update_option( dtb_schematics_public_catalog_version_option_name(), $next, false );
	return $next;
}

/**
 * Emit a bounded, non-sensitive diagnostic when optional public enrichment
 * fails. Public response generation must not expose exception details.
 */
function dtb_schematic_log_public_projection_failure( string $schematic_id, string $stage, Throwable $error, int $product_id = 0 ): void {
	$context = [
		'schematic_id' => sanitize_key( $schematic_id ),
		'stage'        => sanitize_key( $stage ),
		'error_class'  => sanitize_text_field( get_class( $error ) ),
	];
	if ( $product_id > 0 ) {
		$context['product_id'] = $product_id;
	}

	error_log( '[DTB Schematics] Public projection degraded: ' . wp_json_encode( $context ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
}

/**
 * Normalize stored page sources before the REST transport applies versioned
 * URLs. Persisted legacy/malformed members are ignored rather than allowing a
 * typed serializer callback to fatal the entire public schematic response.
 *
 * @param mixed $sources Stored source projection.
 * @return array<int,array<string,mixed>>
 */
function dtb_schematic_public_safe_sources( $sources ): array {
	if ( ! is_array( $sources ) ) {
		return [];
	}

	$normalized = [];
	foreach ( $sources as $source ) {
		if ( ! is_array( $source ) ) {
			continue;
		}
		$normalized[] = $source;
	}
	return $normalized;
}

/**
 * Resolve optional WooCommerce storefront projections without allowing one
 * defective catalog entity to make the authoritative schematic unavailable.
 * The normal path remains one bounded batch. Per-ID reads are used only as a
 * recovery path after that batch throws, so healthy requests retain the
 * existing query profile.
 *
 * @param int[] $product_ids
 * @return array<int,array<string,mixed>>
 */
function dtb_schematic_public_product_projections( string $schematic_id, array $product_ids ): array {
	$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
	if ( empty( $product_ids ) || ! function_exists( 'dtb_catalog_lookup_storefront_projections_by_ids' ) ) {
		return [];
	}

	try {
		$result = dtb_catalog_lookup_storefront_projections_by_ids( $product_ids );
		return is_array( $result ) ? $result : [];
	} catch ( Throwable $error ) {
		dtb_schematic_log_public_projection_failure( $schematic_id, 'catalog_batch', $error );
	}

	$recovered = [];
	foreach ( $product_ids as $product_id ) {
		try {
			$result = dtb_catalog_lookup_storefront_projections_by_ids( [ $product_id ] );
			if ( is_array( $result ) && isset( $result[ $product_id ] ) && is_array( $result[ $product_id ] ) ) {
				$recovered[ $product_id ] = $result[ $product_id ];
			}
		} catch ( Throwable $error ) {
			dtb_schematic_log_public_projection_failure( $schematic_id, 'catalog_product', $error, $product_id );
		}
	}

	return $recovered;
}

/**
 * Resolve the single preview to use for a schematic.
 *
 * @return array{url:string,source:string}
 */
function dtb_schematic_resolve_preview( DTB_Schematic_Record_Entity $record ): array {
	$policy = $record->preview_policy;

	if ( DTB_SCHEMATIC_PREVIEW_MODE_EXPLICIT === $policy['mode'] && $policy['explicit_attachment_id'] > 0 ) {
		$described = dtb_schematic_attachment_repo_describe( $policy['explicit_attachment_id'] );
		if ( $described && $described['exists'] ) {
			$thumbnail_url = dtb_wp_media_get_attachment_image_url( $policy['explicit_attachment_id'], 'medium' );
			$url           = '' !== $thumbnail_url ? $thumbnail_url : $described['url'];
			if ( '' !== $url ) {
				return [ 'url' => $url, 'source' => 'explicit' ];
			}
		}
	}

	if ( ! empty( $record->linked_products ) ) {
		foreach ( $record->linked_products as $product_id ) {
			$image_url = get_the_post_thumbnail_url( $product_id, 'medium' );
			if ( $image_url ) {
				return [ 'url' => (string) $image_url, 'source' => 'product_image' ];
			}
		}
	}

	foreach ( $record->pages as $page ) {
		if ( DTB_SCHEMATIC_PAGE_STATE_MISSING_ASSET === ( $page['lifecycle_state'] ?? '' ) ) {
			continue;
		}
		$attachment_id = (int) ( $page['attachment_id'] ?? 0 );
		$described     = dtb_schematic_attachment_repo_describe( $attachment_id );
		if ( $described && $described['exists'] ) {
			$thumbnail_url = dtb_wp_media_get_attachment_image_url( $attachment_id, 'medium' );
			$url           = '' !== $thumbnail_url ? $thumbnail_url : $described['url'];
			if ( '' !== $url ) {
				return [ 'url' => $url, 'source' => 'first_page' ];
			}
		}
	}

	return [ 'url' => '', 'source' => 'unavailable' ];
}

function dtb_schematic_generate_catalog_entry( DTB_Schematic_Record_Entity $record ): array {
	$display = dtb_schematic_resolve_display_metadata( $record );
	return [
		'schema_version'  => $record->source_schema_version ?: 'v1',
		'catalog_version' => $record->publication_version,
		'id'              => $record->canonical_id,
		'title'           => $display['title'],
		'brand'           => [ 'id' => $display['brand_id'], 'name' => $display['brand_name'] ],
		'category'        => [ 'id' => $display['category_id'], 'name' => $display['category_name'] ],
		'family_id'       => $record->family_id,
		'variant_label'   => $record->variant_label,
		'preview'         => dtb_schematic_resolve_preview( $record ),
		'page_count'      => count( $record->pages ),
	];
}

/**
 * Build a customer-facing schematic detail response.
 *
 * The schematic record and normalized relationships are authoritative. Live
 * WooCommerce fields are optional read enrichment: enrichment failure must
 * degrade the affected product to null, never invalidate the schematic.
 */
function dtb_schematic_generate_detail_response( DTB_Schematic_Record_Entity $record ): array {
	$display = dtb_schematic_resolve_display_metadata( $record );
	$hotspot_dataset = null;

	if ( function_exists( 'dtb_schematic_hotspot_dataset_repo_get' ) ) {
		try {
			$candidate = dtb_schematic_hotspot_dataset_repo_get( $record->id );
			$hotspot_dataset = is_array( $candidate ) ? $candidate : null;
		} catch ( Throwable $error ) {
			dtb_schematic_log_public_projection_failure( $record->canonical_id, 'hotspot_dataset', $error );
		}
	}

	$pages = [];
	foreach ( $record->pages as $page ) {
		if ( ! is_array( $page ) ) {
			continue;
		}

		$occurrences = [];
		if ( $hotspot_dataset ) {
			try {
				$page_occurrences = dtb_schematic_hotspot_dataset_occurrences_for_page( $hotspot_dataset, (int) ( $page['page_number'] ?? 0 ) );
				foreach ( is_array( $page_occurrences ) ? $page_occurrences : [] as $occurrence ) {
					if ( ! is_array( $occurrence ) || ! dtb_schematic_hotspot_occurrence_has_valid_coordinates( $occurrence ) ) {
						continue;
					}
					$occurrences[] = [
						'hotspot_id'  => sanitize_text_field( (string) ( $occurrence['hotspot_id'] ?? '' ) ),
						'part_ref'    => sanitize_text_field( (string) ( $occurrence['part_ref'] ?? '' ) ),
						'shape_type'  => sanitize_key( (string) ( $occurrence['shape_type'] ?? '' ) ),
						'coordinates' => (array) ( $occurrence['coordinates'] ?? [] ),
						'label'       => sanitize_text_field( (string) ( $occurrence['label'] ?? '' ) ),
					];
				}
			} catch ( Throwable $error ) {
				dtb_schematic_log_public_projection_failure( $record->canonical_id, 'hotspot_page', $error );
			}
		}

		// Explicit page BOM membership owns reference-only entries; actual page
		// occurrences supplement membership without leaking other pages' catalog.
		$page_part_refs = is_array( $page['part_refs'] ?? null ) ? $page['part_refs'] : [];
		foreach ( $occurrences as $occurrence ) {
			$page_part_refs[] = $occurrence['part_ref'];
		}
		$page_part_refs = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', $page_part_refs ) ) ) );
		$page_hotspot = is_array( $page['hotspot_dataset'] ?? null ) ? $page['hotspot_dataset'] : [];
		$serialized = [
			'page_id'         => sanitize_text_field( (string) ( $page['page_id'] ?? '' ) ),
			'part_refs'       => $page_part_refs,
			'page_number'     => (int) ( $page['page_number'] ?? 0 ),
			'label'           => sanitize_text_field( (string) ( $page['label'] ?? '' ) ),
			'media_type'      => sanitize_key( (string) ( $page['media_type'] ?? '' ) ),
			'width'           => max( 0, (int) ( $page['width'] ?? 0 ) ),
			'height'          => max( 0, (int) ( $page['height'] ?? 0 ) ),
			'sources'         => dtb_schematic_public_safe_sources( $page['sources'] ?? [] ),
			'source_checksum' => sanitize_text_field( (string) ( $page['source_checksum'] ?? '' ) ),
			'hotspot_dataset' => [
				'type'           => sanitize_key( (string) ( $page_hotspot['type'] ?? 'none' ) ),
				'schema_version' => sanitize_text_field( (string) ( $hotspot_dataset['schema_version'] ?? $page_hotspot['schema_version'] ?? '' ) ),
				'checksum'       => sanitize_text_field( (string) ( $hotspot_dataset['checksum'] ?? $page_hotspot['checksum'] ?? '' ) ),
				'occurrences'    => $occurrences,
			],
			'lifecycle_state' => sanitize_key( (string) ( $page['lifecycle_state'] ?? '' ) ),
			'url'             => '',
		];

		$attachment_id = (int) ( $page['attachment_id'] ?? 0 );
		if ( $attachment_id > 0 ) {
			try {
				$described = dtb_schematic_attachment_repo_describe( $attachment_id );
				$serialized['url'] = ( $described && ! empty( $described['exists'] ) ) ? (string) ( $described['url'] ?? '' ) : '';
			} catch ( Throwable $error ) {
				dtb_schematic_log_public_projection_failure( $record->canonical_id, 'page_media', $error );
			}
		}
		$pages[] = $serialized;
	}

	$product_ids = [];
	foreach ( $record->parts as $part ) {
		if ( is_array( $part ) && (int) ( $part['product_id'] ?? 0 ) > 0 && DTB_SCHEMATIC_PART_STATE_RESOLVED === ( $part['resolution_state'] ?? '' ) ) {
			$product_ids[] = (int) $part['product_id'];
		}
	}
	$product_projections = dtb_schematic_public_product_projections( $record->canonical_id, $product_ids );

	$parts = [];
	foreach ( $record->parts as $part ) {
		if ( ! is_array( $part ) ) {
			continue;
		}
		$product_url = '';
		$product_id  = (int) ( $part['product_id'] ?? 0 );
		if ( $product_id > 0 && DTB_SCHEMATIC_PART_STATE_RESOLVED === ( $part['resolution_state'] ?? '' ) ) {
			try {
				$permalink = get_permalink( $product_id );
				$product_url = $permalink ? (string) $permalink : '';
			} catch ( Throwable $error ) {
				dtb_schematic_log_public_projection_failure( $record->canonical_id, 'product_permalink', $error, $product_id );
			}
		}

		$parts[] = [
			'part_ref'          => sanitize_text_field( (string) ( $part['part_ref'] ?? '' ) ),
			'title'             => sanitize_text_field( (string) ( $part['title'] ?? '' ) ),
			'brand'             => sanitize_text_field( (string) ( $part['brand'] ?? '' ) ),
			'mpn'               => sanitize_text_field( (string) ( $part['mpn'] ?? '' ) ),
			'sku'               => sanitize_text_field( (string) ( $part['sku'] ?? '' ) ),
			'resolution_method' => sanitize_key( (string) ( $part['resolution_method'] ?? '' ) ),
			'resolution_state'  => sanitize_key( (string) ( $part['resolution_state'] ?? '' ) ),
			'product_url'       => esc_url_raw( $product_url ),
			'product'           => $product_projections[ $product_id ] ?? null,
			'occurrence_count'  => max( 0, (int) ( $part['occurrence_count'] ?? 0 ) ),
		];
	}

	return [
		'schema_version'  => $record->source_schema_version ?: 'v1',
		'catalog_version' => $record->publication_version,
		'id'              => $record->canonical_id,
		'title'           => $display['title'],
		'brand'           => [ 'id' => $display['brand_id'], 'name' => $display['brand_name'] ],
		'category'        => [ 'id' => $display['category_id'], 'name' => $display['category_name'] ],
		'family_id'       => $record->family_id,
		'variant_label'   => $record->variant_label,
		'variant_options' => dtb_schematic_shared_variant_options( $record->canonical_id ),
		'pages'           => $pages,
		'parts'           => $parts,
	];
}

/**
 * Return deterministic catalog variations that share one schematic.
 *
 * @return array<int,array{key:string,label:string,sku:string}>
 */
function dtb_schematic_shared_variant_options( string $canonical_id ): array {
	if ( ! defined( 'DTB_SCHEMATIC_SHARED_VARIANT_MAP' ) ) {
		return [];
	}

	$options = DTB_SCHEMATIC_SHARED_VARIANT_MAP[ $canonical_id ] ?? [];
	if ( ! is_array( $options ) ) {
		return [];
	}

	$normalized = [];
	foreach ( $options as $option ) {
		$key   = sanitize_key( (string) ( $option['key'] ?? '' ) );
		$label = sanitize_text_field( (string) ( $option['label'] ?? '' ) );
		$sku   = sanitize_text_field( (string) ( $option['sku'] ?? '' ) );
		if ( '' === $key || '' === $label || '' === $sku ) {
			continue;
		}
		$normalized[] = [ 'key' => $key, 'label' => $label, 'sku' => $sku ];
	}

	return $normalized;
}
