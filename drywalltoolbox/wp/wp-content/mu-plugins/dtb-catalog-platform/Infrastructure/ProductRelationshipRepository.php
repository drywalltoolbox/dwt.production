<?php
/**
 * Product Mapping compatibility and relationship repository.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/** Decode all legacy encodings of a SKU-list metadata field. */
function dtb_product_mapping_decode_skus( int $product_id, string $meta_key ): array {
	$values = [];
	foreach ( (array) get_post_meta( $product_id, $meta_key, false ) as $raw ) {
		$values = array_merge( $values, DTB_CatalogProductNormalizer::decode_csv_or_array( $raw ) );
	}
	$seen = [];
	$out  = [];
	foreach ( $values as $value ) {
		$value = trim( (string) $value );
		$key = strtoupper( $value );
		if ( '' === $value || isset( $seen[ $key ] ) ) {
			continue;
		}
		$seen[ $key ] = true;
		$out[] = $value;
	}
	return $out;
}

/** Acquire a connection-scoped database lock for the full relationship write. */
function dtb_product_mapping_acquire_lock( int $product_id ) {
	global $wpdb;
	$key = 'dtb_rel_' . md5( get_current_blog_id() . ':' . $product_id );
	$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $key ) );
	if ( '1' !== (string) $acquired ) {
		return new WP_Error( 'relationship_locked', 'Another relationship update is in progress. Refresh and try again.' );
	}
	return [ 'key' => $key ];
}

function dtb_product_mapping_release_lock( array $lock ): void {
	global $wpdb;
	$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock['key'] ) );
}

/**
 * Load compatibility mappings for a part or tool.
 *
 * @param int    $product_id Product ID.
 * @param string $mode part|tool.
 * @return array
 */
function dtb_product_mapping_repo_get_compatibility( int $product_id, string $mode ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || 'part' !== $mode || ( 'part' !== (string) get_post_meta( $product_id, DTB_ProductMeta::PRODUCT_KIND, true ) && '1' !== (string) get_post_meta( $product_id, DTB_ProductMeta::IS_PARTS, true ) ) ) {
		return new WP_Error( 'invalid_part', 'The selected product is not a recognized catalog part.' );
	}
	$part_id = $product_id;
	$compatibility_skus = dtb_product_mapping_decode_skus( $part_id, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS );
	$replacement_skus = dtb_product_mapping_decode_skus( $part_id, DTB_ProductMeta::REPLACEMENT_PART_FOR );
	$ids = array_values( array_unique( array_merge( $compatibility_skus, $replacement_skus ) ) );
	$legacy_ids = get_post_meta( $part_id, '_dtb_compatible_tools', true );
	$legacy_ids = is_array( $legacy_ids ) ? array_values( array_filter( array_map( 'absint', $legacy_ids ) ) ) : [];
	$legacy = [];
	foreach ( array_slice( $legacy_ids, 0, 50 ) as $legacy_id ) {
		$legacy_product = wc_get_product( $legacy_id );
		$legacy[] = [
			'id' => $legacy_id,
			'name' => $legacy_product ? $legacy_product->get_name() : 'Product record unavailable',
			'sku' => $legacy_product ? (string) $legacy_product->get_sku() : '',
			'status' => 'legacy_unverified',
			'source' => 'part_legacy_ids',
		];
	}

	$related = [];
	$unresolved = [];
	foreach ( $ids as $sku ) {
		$sku = trim( (string) $sku );
		$related_id = '' !== $sku ? wc_get_product_id_by_sku( $sku ) : 0;
		$related_product = $related_id ? wc_get_product( $related_id ) : false;
		if ( $related_product ) {
			$related[] = [
				'id'   => $related_product->get_id(),
				'name' => $related_product->get_name(),
				'sku'  => $related_product->get_sku(),
				'type' => $related_product->get_type(),
				'source' => in_array( $sku, $compatibility_skus, true ) ? 'compatible_tool_skus' : 'replacement_part_for',
			];
		} else {
			$unresolved[] = [ 'sku' => $sku, 'source' => in_array( $sku, $compatibility_skus, true ) ? 'compatible_tool_skus' : 'replacement_part_for', 'status' => 'unresolved_identity' ];
		}
	}
	$legacy_reverse_ids = get_posts( [
		'post_type' => 'product',
		'post_status' => 'publish',
		'posts_per_page' => 50,
		'fields' => 'ids',
		'no_found_rows' => true,
		'meta_query' => [ [ 'key' => '_dtb_compatible_parts', 'value' => '"' . $part_id . '"', 'compare' => 'LIKE' ] ],
	] );
	foreach ( $legacy_reverse_ids as $legacy_tool_id ) {
		$reverse = get_post_meta( (int) $legacy_tool_id, '_dtb_compatible_parts', true );
		$reverse = is_array( $reverse ) ? array_map( 'absint', $reverse ) : [];
		if ( in_array( $part_id, $reverse, true ) ) {
			$legacy_ids[] = (int) $legacy_tool_id;
			$already_listed = in_array( (int) $legacy_tool_id, array_column( $legacy, 'id' ), true );
			if ( ! $already_listed ) {
				$legacy_tool = wc_get_product( (int) $legacy_tool_id );
				$legacy[] = [ 'id' => (int) $legacy_tool_id, 'name' => $legacy_tool ? $legacy_tool->get_name() : 'Product record unavailable', 'sku' => $legacy_tool ? (string) $legacy_tool->get_sku() : '', 'status' => 'legacy_unverified', 'source' => 'tool_reverse_legacy' ];
			}
		}
	}
	$legacy_ids = array_values( array_unique( $legacy_ids ) );

	return [
		'product_id' => $product_id,
		'mode'       => $mode,
		'related'    => $related,
		'unresolved' => $unresolved,
		'version'    => hash( 'sha256', wp_json_encode( [ $compatibility_skus, $replacement_skus ] ) ),
		'legacy_unverified_count' => count( $legacy_ids ),
		'legacy_unverified' => $legacy,
	];
}

/**
 * Get paged parts list and existing compatibility links.
 *
 * @param string $brand Brand filter.
 * @param string $search Search query.
 * @param int    $paged Paged number.
 * @return array
 */
function dtb_product_mapping_repo_get_parts( string $brand, string $search, int $paged ): array {

	$meta_query = [
		'relation' => 'AND',
		[
			'relation' => 'OR',
			[ 'key' => DTB_ProductMeta::IS_PARTS, 'value' => '1', 'compare' => '=' ],
			[ 'key' => DTB_ProductMeta::PRODUCT_KIND, 'value' => 'part', 'compare' => '=' ],
		],
	];
	if ( '' !== $brand ) {
		$meta_query[] = [ 'key' => DTB_ProductMeta::BRAND_LABEL, 'value' => $brand, 'compare' => '=' ];
	}
	$query = new WP_Query( [
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 30,
		'paged'          => $paged,
		's'              => $search,
		'meta_query'     => $meta_query,
	] );

	$items = [];
	foreach ( $query->posts as $post ) {
		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			continue;
		}

		$tool_ids = array_values( array_unique( array_merge( dtb_product_mapping_decode_skus( (int) $post->ID, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ), dtb_product_mapping_decode_skus( (int) $post->ID, DTB_ProductMeta::REPLACEMENT_PART_FOR ) ) ) );
		$linked   = [];

		foreach ( $tool_ids as $tool_sku ) {
			$tool_id = wc_get_product_id_by_sku( trim( (string) $tool_sku ) );
			$tool = $tool_id ? wc_get_product( $tool_id ) : false;
			if ( $tool ) {
				$linked[] = [ 'id' => $tool->get_id(), 'name' => $tool->get_name(), 'sku' => $tool->get_sku() ];
			}
		}

		$items[] = [
			'id'     => $post->ID,
			'name'   => $product->get_name(),
			'sku'    => $product->get_sku(),
		'linked' => $linked,
		];
	}

	return [
		'items' => $items,
		'total' => $query->found_posts,
		'pages' => $query->max_num_pages,
	];
}

/** Read one bounded page of live relationship inputs for an operator audit. */
function dtb_product_mapping_repo_audit_page( int $page ): array {
	$page_size = 50;
	$query = new WP_Query( [
		'post_type' => [ 'product', 'product_variation' ],
		'post_status' => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
		'posts_per_page' => $page_size,
		'paged' => max( 1, $page ),
	'fields' => 'ids',
		'orderby' => 'ID',
		'order' => 'ASC',
		'no_found_rows' => false,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	] );
	$rows = [];
	foreach ( (array) $query->posts as $product_id ) {
		$product_id = (int) $product_id;
		$native = static function ( string $key ) use ( $product_id ): array {
			$value = get_post_meta( $product_id, $key, true );
			return is_array( $value ) ? array_values( array_filter( array_map( 'absint', $value ) ) ) : [];
		};
		$fields = [
			'sku' => get_post_meta( $product_id, '_sku', true ),
			'compatible_tool_skus' => dtb_product_mapping_decode_skus( $product_id, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ),
			'replacement_part_for' => dtb_product_mapping_decode_skus( $product_id, DTB_ProductMeta::REPLACEMENT_PART_FOR ),
			'legacy_compatible_tools' => $native( '_dtb_compatible_tools' ),
			'legacy_compatible_parts' => $native( '_dtb_compatible_parts' ),
			'upsell_ids' => $native( '_upsell_ids' ),
			'crosssell_ids' => $native( '_crosssell_ids' ),
		];
		$truncated = false;
		foreach ( [ 'compatible_tool_skus', 'replacement_part_for', 'legacy_compatible_tools', 'legacy_compatible_parts', 'upsell_ids', 'crosssell_ids' ] as $key ) {
			if ( count( $fields[ $key ] ) > 200 ) {
				$fields[ $key ] = array_slice( $fields[ $key ], 0, 200 );
				$truncated = true;
			}
		}
		$rows[] = [
			'id' => $product_id,
			'name' => (string) get_the_title( $product_id ),
			'post_type' => (string) get_post_type( $product_id ),
			'status' => (string) get_post_status( $product_id ),
			'is_part' => 'part' === (string) get_post_meta( $product_id, DTB_ProductMeta::PRODUCT_KIND, true ) || '1' === (string) get_post_meta( $product_id, DTB_ProductMeta::IS_PARTS, true ),
			'fields' => $fields,
			'truncated' => $truncated,
		];
	}
	return [ 'items' => $rows, 'total' => (int) $query->found_posts, 'pages' => max( 1, (int) $query->max_num_pages ), 'page' => max( 1, $page ), 'per_page' => $page_size ];
}

/**
 * Get a product's upsells and cross-sells.
 *
 * @param int $product_id Product ID.
 * @return array|WP_Error
 */
function dtb_product_mapping_repo_get_relationships( int $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || $product instanceof WC_Product_Variation ) {
		return new WP_Error( 'product_missing', 'Product not found.' );
	}

	$format = static function ( array $ids ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$related = wc_get_product( $id );
			if ( $related ) {
				$out[] = [ 'id' => $related->get_id(), 'name' => $related->get_name(), 'sku' => $related->get_sku(), 'status' => get_post_status( $related->get_id() ), 'type' => $related->get_type() ];
			}
		}
		return $out;
	};

	$upsell_ids = array_values( array_map( 'absint', $product->get_upsell_ids() ) );
	$crosssell_ids = array_values( array_map( 'absint', $product->get_cross_sell_ids() ) );
	return [
		'product_id' => $product_id,
		'name'       => $product->get_name(),
		'sku'        => $product->get_sku(),
		'upsells'    => $format( $upsell_ids ),
		'crosssells' => $format( $crosssell_ids ),
		'version'    => hash( 'sha256', wp_json_encode( [ $upsell_ids, $crosssell_ids ] ) ),
	];
}

/**
 * Save upsell/cross-sell relationships.
 *
 * @param int          $product_id Product ID.
 * @param string|array $upsell_raw Upsell IDs raw input.
 * @param string|array $cross_raw Cross-sell IDs raw input.
 * @return array|WP_Error
 */
function dtb_product_mapping_repo_save_relationships( int $product_id, $upsell_raw, $cross_raw, string $expected_version = '' ) {
	if ( $product_id <= 0 ) {
		return new WP_Error( 'product_missing', 'Product not found.' );
	}
	if ( ! preg_match( '/^[a-f0-9]{64}$/i', $expected_version ) ) {
		return new WP_Error( 'relationship_version_required', 'Reload the current relationships before saving.' );
	}
	$lock = dtb_product_mapping_acquire_lock( $product_id );
	if ( is_wp_error( $lock ) ) {
		return $lock;
	}
	try {
		return dtb_product_mapping_repo_save_relationships_locked( $product_id, $upsell_raw, $cross_raw, $expected_version );
	} finally {
		dtb_product_mapping_release_lock( $lock );
	}
}

function dtb_product_mapping_repo_save_relationships_locked( int $product_id, $upsell_raw, $cross_raw, string $expected_version ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || $product instanceof WC_Product_Variation || 'publish' !== get_post_status( $product_id ) ) {
		return new WP_Error( 'product_missing', 'Product not found.' );
	}
	$current_version = hash( 'sha256', wp_json_encode( [ array_values( array_map( 'absint', $product->get_upsell_ids() ) ), array_values( array_map( 'absint', $product->get_cross_sell_ids() ) ) ] ) );
	if ( ! hash_equals( $current_version, $expected_version ) ) {
		return new WP_Error( 'relationship_conflict', 'Relationships changed since they were loaded. Refresh and review the current selections.' );
	}

	$parse_ids = static function ( $raw ) {
		if ( is_array( $raw ) ) {
			$values = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$values = explode( ',', $raw );
		} else {
			$values = [];
		}
		if ( count( $values ) > 50 ) {
			return new WP_Error( 'relationship_limit', 'A relationship list may contain at most 50 products.' );
		}
		$ids = array_map( 'absint', $values );
		if ( count( $ids ) !== count( array_unique( $ids ) ) || in_array( 0, $ids, true ) ) {
			return new WP_Error( 'relationship_duplicates', 'Remove duplicate or invalid product selections.' );
		}
		return $ids;
	};
	$upsells = $parse_ids( $upsell_raw );
	$crosses = $parse_ids( $cross_raw );
	if ( is_wp_error( $upsells ) || is_wp_error( $crosses ) ) {
		return is_wp_error( $upsells ) ? $upsells : $crosses;
	}
	if ( array_intersect( $upsells, $crosses ) ) {
		return new WP_Error( 'relationship_type_conflict', 'A product cannot be both an upsell and a cross-sell for the same item.' );
	}
	foreach ( array_merge( $upsells, $crosses ) as $target_id ) {
		$target = wc_get_product( $target_id );
		if ( ! $target || $target_id === $product_id || 'publish' !== get_post_status( $target_id ) || $target->is_type( 'variation' ) ) {
			return new WP_Error( 'relationship_target_invalid', 'Relationships require distinct, published product records.' );
		}
	}
	$product->set_upsell_ids( $upsells );
	$product->set_cross_sell_ids( $crosses );
	$product->save();
	$verified = wc_get_product( $product_id );
	if ( ! $verified || array_values( $verified->get_upsell_ids() ) !== $upsells || array_values( $verified->get_cross_sell_ids() ) !== $crosses ) {
		return new WP_Error( 'relationship_write_failed', 'The saved relationships could not be verified. Reload before retrying.' );
	}
	return [ 'message' => 'Relationships saved.', 'version' => hash( 'sha256', wp_json_encode( [ $upsells, $crosses ] ) ) ];
}
