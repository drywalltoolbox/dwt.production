<?php
/**
 * DTB Schematics — temporary hotspot/part diagnostics and resolver.
 *
 * Provides a bounded, operator-facing diagnostic service for unresolved
 * schematic part relationships. WooCommerce remains authoritative for
 * product identity; this service never creates products or alters protected
 * catalog identifiers. Automatic repairs are restricted to the existing
 * exact resolver contract. Review-only candidates may be surfaced for an
 * operator to explicitly link, but are never auto-applied.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_RECORDS = 25;
const DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_PARTS   = 250;
const DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_CANDIDATES = 5;

/**
 * Scan a bounded set of schematic records for unresolved hotspot parts.
 *
 * @param array $args {
 *     @type int    $schematic_id Optional single record.
 *     @type int    $page         1-based record page.
 *     @type int    $per_page     Record page size, capped at 25.
 *     @type string $search       Optional schematic title search.
 * }
 * @return array Structured diagnostic report.
 */
function dtb_schematic_hotspot_diagnostics_scan( array $args = [] ): array {
	$schematic_id = max( 0, (int) ( $args['schematic_id'] ?? 0 ) );
	$page         = max( 1, (int) ( $args['page'] ?? 1 ) );
	$per_page     = min( DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_RECORDS, max( 1, (int) ( $args['per_page'] ?? DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_RECORDS ) ) );
	$search       = sanitize_text_field( (string) ( $args['search'] ?? '' ) );

	$records = [];
	$total   = 0;
	$pages   = 1;

	if ( $schematic_id > 0 ) {
		$record = dtb_schematic_record_repo_get( $schematic_id );
		if ( $record ) {
			$records = [ $record ];
			$total   = 1;
		}
	} else {
		$query   = dtb_schematic_record_repo_query(
			[
				'page'     => $page,
				'per_page' => $per_page,
				'search'   => $search,
			]
		);
		$records = $query['items'];
		$total   = (int) $query['total'];
		$pages   = max( 1, (int) $query['pages'] );
	}

	$report = [
		'schematic_id'       => $schematic_id,
		'page'               => $page,
		'per_page'           => $per_page,
		'total_records'      => $total,
		'total_pages'        => $pages,
		'records_examined'   => 0,
		'unresolved_parts'   => 0,
		'safe_fixes'         => 0,
		'review_candidates'  => 0,
		'missing_datasets'   => 0,
		'missing_source_parts' => 0,
		'truncated'          => false,
		'items'              => [],
	];

	foreach ( $records as $record ) {
		if ( count( $report['items'] ) >= DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_PARTS ) {
			$report['truncated'] = true;
			break;
		}

		$report['records_examined']++;
		$dataset = dtb_schematic_hotspot_dataset_repo_get( $record->id );
		if ( ! $dataset ) {
			$report['missing_datasets']++;
		}

		$source_by_ref = [];
		foreach ( (array) ( $dataset['parts_catalog'] ?? [] ) as $source_part ) {
			$ref = sanitize_key( (string) ( $source_part['part_ref'] ?? '' ) );
			if ( '' !== $ref ) {
				$source_by_ref[ $ref ] = $source_part;
			}
		}

		$compatible_ids = dtb_schematic_resolve_compatible_part_product_ids( $record->linked_products );

		foreach ( $record->parts as $part ) {
			if ( count( $report['items'] ) >= DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_PARTS ) {
				$report['truncated'] = true;
				break 2;
			}
			if ( ! dtb_schematic_hotspot_part_is_unresolved( $part ) ) {
				continue;
			}

			$report['unresolved_parts']++;
			$part_ref    = sanitize_key( (string) ( $part['part_ref'] ?? '' ) );
			$source_part = $source_by_ref[ $part_ref ] ?? null;
			if ( null === $source_part ) {
				$report['missing_source_parts']++;
			}

			$item = dtb_schematic_hotspot_diagnose_part( $record, $part, $source_part, $compatible_ids );
			if ( ! empty( $item['safe_fix'] ) ) {
				$report['safe_fixes']++;
			}
			if ( ! empty( $item['candidates'] ) ) {
				$report['review_candidates']++;
			}
			$report['items'][] = $item;
		}
	}

	return $report;
}

/** Whether a stored schematic part relationship is unresolved. */
function dtb_schematic_hotspot_part_is_unresolved( array $part ): bool {
	return empty( $part['product_id'] )
		&& DTB_SCHEMATIC_PART_STATE_NOT_SOLD !== (string) ( $part['resolution_state'] ?? '' );
}

/**
 * Diagnose one unresolved relationship without mutating state.
 *
 * @param array|null $source_part Normalized parts_catalog entry.
 */
function dtb_schematic_hotspot_diagnose_part( DTB_Schematic_Record_Entity $record, array $part, ?array $source_part, array $compatible_ids ): array {
	$part_ref = sanitize_key( (string) ( $part['part_ref'] ?? '' ) );
	$source   = $source_part ?: [];

	$catalog_part = [
		'part_ref'   => $part_ref,
		'display_id' => (string) ( $source['display_id'] ?? $part['mpn'] ?? $part_ref ),
		'name'       => (string) ( $source['name'] ?? $part['title'] ?? '' ),
		'sku'        => (string) ( $source['sku'] ?? $part['sku'] ?? '' ),
	];

	$exact_resolution = dtb_schematic_resolve_single_part( $record, $catalog_part, $compatible_ids );
	$safe_fix         = (int) ( $exact_resolution['product_id'] ?? 0 ) > 0 ? $exact_resolution : null;

	$candidates = [];
	if ( ! $safe_fix ) {
		$candidates = dtb_schematic_hotspot_review_candidates( $record, $catalog_part );
	}

	$issue_code = 'exact_identifier_not_found';
	$issue      = __( 'No exact WooCommerce product match was found.', 'drywall-toolbox' );

	if ( ! $source_part ) {
		$issue_code = 'source_part_missing';
		$issue      = __( 'The stored relationship is not present in the normalized hotspot parts catalog.', 'drywall-toolbox' );
	} elseif ( '' === trim( $catalog_part['sku'] ) && '' === trim( $catalog_part['display_id'] ) ) {
		$issue_code = 'identifiers_missing';
		$issue      = __( 'The hotspot source part has neither an SKU nor a manufacturer part number.', 'drywall-toolbox' );
	} elseif ( $safe_fix ) {
		$issue_code = 'safe_exact_fix_available';
		$issue      = __( 'The current catalog now contains an exact deterministic match. This relationship can be repaired safely.', 'drywall-toolbox' );
	} elseif ( ! empty( $candidates ) ) {
		$issue_code = 'operator_review_available';
		$issue      = __( 'Possible products were found for operator review, but none satisfies the automatic exact-match contract.', 'drywall-toolbox' );
	}

	return [
		'schematic_id'        => $record->id,
		'canonical_id'        => $record->canonical_id,
		'schematic_title'     => $record->title,
		'brand'               => $record->brand_name ?: $record->brand_id,
		'part_ref'            => $part_ref,
		'title'               => sanitize_text_field( (string) ( $catalog_part['name'] ?: $part['title'] ?? '' ) ),
		'sku'                 => sanitize_text_field( (string) $catalog_part['sku'] ),
		'mpn'                 => sanitize_text_field( (string) $catalog_part['display_id'] ),
		'occurrence_count'    => max( 0, (int) ( $part['occurrence_count'] ?? 0 ) ),
		'issue_code'          => $issue_code,
		'issue'               => $issue,
		'source_present'      => (bool) $source_part,
		'safe_fix'            => $safe_fix ? [
			'product_id' => (int) $safe_fix['product_id'],
			'method'     => sanitize_key( (string) $safe_fix['method'] ),
			'product'    => dtb_schematic_hotspot_describe_product( (int) $safe_fix['product_id'] ),
		] : null,
		'candidates'          => $candidates,
	];
}

/**
 * Find bounded review-only candidates. These are never auto-applied.
 *
 * Candidate discovery deliberately preserves protected identifiers. It uses
 * exact MPN lookup without brand as a diagnostic signal plus a bounded title
 * search. The operator must explicitly choose a product before any link is
 * written.
 */
function dtb_schematic_hotspot_review_candidates( DTB_Schematic_Record_Entity $record, array $catalog_part ): array {
	$ids = [];
	$mpn = trim( (string) ( $catalog_part['display_id'] ?? '' ) );

	if ( '' !== $mpn && class_exists( 'DTB_ProductMeta' ) ) {
		$matches = get_posts(
			[
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => [ 'publish', 'private' ],
				'posts_per_page' => DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_CANDIDATES + 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					[
						'key'     => DTB_ProductMeta::MPN,
						'value'   => $mpn,
						'compare' => '=',
					],
				],
			]
		);
		$ids = array_values( array_unique( array_map( 'intval', (array) $matches ) ) );
	}

	$title = trim( (string) ( $catalog_part['name'] ?? '' ) );
	if ( count( $ids ) < DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_CANDIDATES && strlen( $title ) >= 4 ) {
		$title_matches = get_posts(
			[
				'post_type'      => [ 'product', 'product_variation' ],
				'post_status'    => [ 'publish', 'private' ],
				'posts_per_page' => DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_CANDIDATES,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				's'              => $title,
			]
		);
		$ids = array_values( array_unique( array_merge( $ids, array_map( 'intval', (array) $title_matches ) ) ) );
	}

	$candidates = [];
	foreach ( array_slice( $ids, 0, DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_CANDIDATES ) as $product_id ) {
		$described = dtb_schematic_hotspot_describe_product( $product_id );
		if ( ! $described ) {
			continue;
		}
		$expected_brand = trim( $record->brand_name ?: $record->brand_id );
		$actual_brand   = trim( (string) ( $described['brand'] ?? '' ) );
		$described['brand_matches'] = '' !== $expected_brand && '' !== $actual_brand
			? 0 === strcasecmp( $expected_brand, $actual_brand )
			: false;
		$candidates[] = $described;
	}

	return $candidates;
}

/** Return a safe, bounded operator-facing product description. */
function dtb_schematic_hotspot_describe_product( int $product_id ): ?array {
	if ( $product_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
		return null;
	}
	$product = wc_get_product( $product_id );
	if ( ! $product ) {
		return null;
	}

	$meta_source_id = $product_id;
	if ( $product->is_type( 'variation' ) && $product->get_parent_id() > 0 ) {
		$meta_source_id = $product->get_parent_id();
	}

	$brand = '';
	$mpn   = '';
	if ( class_exists( 'DTB_ProductMeta' ) ) {
		$brand = (string) get_post_meta( $product_id, DTB_ProductMeta::BRAND_LABEL, true );
		if ( '' === $brand ) {
			$brand = (string) get_post_meta( $product_id, DTB_ProductMeta::BRAND_KEY, true );
		}
		if ( '' === $brand && $meta_source_id !== $product_id ) {
			$brand = (string) get_post_meta( $meta_source_id, DTB_ProductMeta::BRAND_LABEL, true );
			if ( '' === $brand ) {
				$brand = (string) get_post_meta( $meta_source_id, DTB_ProductMeta::BRAND_KEY, true );
			}
		}
		$mpn = (string) get_post_meta( $product_id, DTB_ProductMeta::MPN, true );
		if ( '' === $mpn && $meta_source_id !== $product_id ) {
			$mpn = (string) get_post_meta( $meta_source_id, DTB_ProductMeta::MPN, true );
		}
	}

	return [
		'id'       => $product_id,
		'name'     => sanitize_text_field( $product->get_name() ),
		'sku'      => sanitize_text_field( (string) $product->get_sku() ),
		'mpn'      => sanitize_text_field( $mpn ),
		'brand'    => sanitize_text_field( $brand ),
		'type'     => sanitize_key( $product->get_type() ),
		'status'   => sanitize_key( $product->get_status() ),
		'edit_url' => get_edit_post_link( $product_id, 'raw' ) ?: '',
	];
}

/**
 * Re-run the existing exact resolver for one or more unresolved records and
 * apply only newly resolved relationships. Existing resolved relationships,
 * explicit overrides, not-sold states and protected source identifiers are
 * left untouched.
 *
 * @param int[] $schematic_ids Maximum 25 record IDs.
 * @return array{examined:int,changed:int,resolved:int,unresolved:int,errors:array}
 */
function dtb_schematic_hotspot_apply_safe_repairs( array $schematic_ids ): array {
	$schematic_ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', $schematic_ids ) ) ) ), 0, DTB_SCHEMATIC_HOTSPOT_DIAGNOSTIC_MAX_RECORDS );
	$result = [ 'examined' => 0, 'changed' => 0, 'resolved' => 0, 'unresolved' => 0, 'errors' => [] ];

	foreach ( $schematic_ids as $schematic_id ) {
		$record = dtb_schematic_record_repo_get( $schematic_id );
		if ( ! $record ) {
			$result['errors'][] = sprintf( 'Record #%d not found.', $schematic_id );
			continue;
		}
		$dataset = dtb_schematic_hotspot_dataset_repo_get( $record->id );
		if ( ! $dataset ) {
			$result['errors'][] = sprintf( '%s has no normalized hotspot dataset.', $record->canonical_id );
			continue;
		}

		$result['examined']++;
		$fresh_parts = dtb_schematic_resolve_part_occurrences_for_record( $record, $dataset );
		$fresh_by_ref = [];
		foreach ( $fresh_parts as $fresh ) {
			$fresh_by_ref[ (string) $fresh['part_ref'] ] = $fresh;
		}

		$next_parts = $record->parts;
		$record_changed = false;
		foreach ( $next_parts as $index => $existing ) {
			if ( ! dtb_schematic_hotspot_part_is_unresolved( $existing ) ) {
				continue;
			}
			$fresh = $fresh_by_ref[ (string) $existing['part_ref'] ] ?? null;
			if ( $fresh && (int) ( $fresh['product_id'] ?? 0 ) > 0 ) {
				$next_parts[ $index ]['product_id']        = (int) $fresh['product_id'];
				$next_parts[ $index ]['resolution_method'] = (string) $fresh['resolution_method'];
				$next_parts[ $index ]['resolution_state']  = DTB_SCHEMATIC_PART_STATE_RESOLVED;
				$next_parts[ $index ]['occurrence_count']  = max( 0, (int) ( $fresh['occurrence_count'] ?? $existing['occurrence_count'] ?? 0 ) );
				$record_changed = true;
				$result['resolved']++;
			} else {
				$result['unresolved']++;
			}
		}

		if ( ! $record_changed ) {
			continue;
		}

		$updated = dtb_schematic_update( $record->id, [ 'parts' => $next_parts ] );
		if ( is_wp_error( $updated ) ) {
			$result['errors'][] = $updated->get_error_message();
			continue;
		}
		$result['changed']++;
		dtb_schematic_hotspot_diagnostic_log_update( $record, sprintf( 'Hotspot resolver safely linked %d exact-match part relationship(s).', $result['resolved'] ) );
	}

	return $result;
}

/** Explicitly link one schematic part to an existing WooCommerce product. */
function dtb_schematic_hotspot_set_explicit_product( int $schematic_id, string $part_ref, int $product_id ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}
	$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : false;
	if ( ! $product ) {
		return new WP_Error( 'dtb_schematic_product_not_found', __( 'The selected WooCommerce product does not exist.', 'drywall-toolbox' ) );
	}

	$part_ref = sanitize_key( $part_ref );
	$parts    = $record->parts;
	$found    = false;
	foreach ( $parts as $index => $part ) {
		if ( sanitize_key( (string) ( $part['part_ref'] ?? '' ) ) !== $part_ref ) {
			continue;
		}
		$parts[ $index ]['product_id']        = $product_id;
		$parts[ $index ]['resolution_method'] = DTB_SCHEMATIC_PART_RESOLUTION_EXPLICIT_ID;
		$parts[ $index ]['resolution_state']  = DTB_SCHEMATIC_PART_STATE_RESOLVED;
		$found = true;
		break;
	}
	if ( ! $found ) {
		return new WP_Error( 'dtb_schematic_part_not_found', __( 'The requested schematic part relationship was not found.', 'drywall-toolbox' ) );
	}

	$updated = dtb_schematic_update( $record->id, [ 'parts' => $parts ] );
	if ( ! is_wp_error( $updated ) ) {
		dtb_schematic_hotspot_diagnostic_log_update( $record, sprintf( 'Operator explicitly linked part %s to WooCommerce product #%d.', $part_ref, $product_id ) );
	}
	return $updated;
}

/** Mark one unresolved part as intentionally not sold. */
function dtb_schematic_hotspot_mark_not_sold( int $schematic_id, string $part_ref ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}
	$part_ref = sanitize_key( $part_ref );
	$parts    = $record->parts;
	$found    = false;
	foreach ( $parts as $index => $part ) {
		if ( sanitize_key( (string) ( $part['part_ref'] ?? '' ) ) !== $part_ref ) {
			continue;
		}
		$parts[ $index ]['product_id']        = 0;
		$parts[ $index ]['resolution_method'] = DTB_SCHEMATIC_PART_RESOLUTION_UNRESOLVED;
		$parts[ $index ]['resolution_state']  = DTB_SCHEMATIC_PART_STATE_NOT_SOLD;
		$found = true;
		break;
	}
	if ( ! $found ) {
		return new WP_Error( 'dtb_schematic_part_not_found', __( 'The requested schematic part relationship was not found.', 'drywall-toolbox' ) );
	}
	$updated = dtb_schematic_update( $record->id, [ 'parts' => $parts ] );
	if ( ! is_wp_error( $updated ) ) {
		dtb_schematic_hotspot_diagnostic_log_update( $record, sprintf( 'Operator marked schematic part %s as intentionally not sold.', $part_ref ) );
	}
	return $updated;
}

/** Reset one operator decision back to unresolved for future exact resolution. */
function dtb_schematic_hotspot_reset_resolution( int $schematic_id, string $part_ref ) {
	$record = dtb_schematic_record_repo_get( $schematic_id );
	if ( ! $record ) {
		return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
	}
	$part_ref = sanitize_key( $part_ref );
	$parts    = $record->parts;
	$found    = false;
	foreach ( $parts as $index => $part ) {
		if ( sanitize_key( (string) ( $part['part_ref'] ?? '' ) ) !== $part_ref ) {
			continue;
		}
		$parts[ $index ]['product_id']        = 0;
		$parts[ $index ]['resolution_method'] = DTB_SCHEMATIC_PART_RESOLUTION_UNRESOLVED;
		$parts[ $index ]['resolution_state']  = DTB_SCHEMATIC_PART_STATE_UNRESOLVED;
		$found = true;
		break;
	}
	if ( ! $found ) {
		return new WP_Error( 'dtb_schematic_part_not_found', __( 'The requested schematic part relationship was not found.', 'drywall-toolbox' ) );
	}
	$updated = dtb_schematic_update( $record->id, [ 'parts' => $parts ] );
	if ( ! is_wp_error( $updated ) ) {
		dtb_schematic_hotspot_diagnostic_log_update( $record, sprintf( 'Operator reset schematic part %s to unresolved.', $part_ref ) );
	}
	return $updated;
}

/** Log resolver writes through the existing schematic activity authority. */
function dtb_schematic_hotspot_diagnostic_log_update( DTB_Schematic_Record_Entity $record, string $summary ): void {
	if ( ! function_exists( 'dtb_schematic_activity_log' ) ) {
		return;
	}
	dtb_schematic_activity_log(
		[
			'operation_type'         => 'record_update',
			'schematic_id'           => $record->id,
			'schematic_canonical_id' => $record->canonical_id,
			'dry_run'                => false,
			'result'                 => 'ok',
			'examined'               => 1,
			'changed'                => 1,
			'summary'                => $summary,
			'detail'                 => [ 'source' => 'temporary_hotspot_resolver' ],
		]
	);
}
