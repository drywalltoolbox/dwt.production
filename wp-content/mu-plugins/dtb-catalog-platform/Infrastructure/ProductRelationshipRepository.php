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

/** Decode SKU lists without hiding repeated values from the integrity audit. */
function dtb_product_mapping_audit_skus( int $product_id, string $meta_key ): array {
	$values = [];
	foreach ( (array) get_post_meta( $product_id, $meta_key, false ) as $raw ) {
		if ( is_array( $raw ) ) {
			$decoded = $raw;
		} elseif ( is_string( $raw ) && '' !== $raw ) {
			$json = json_decode( $raw, true );
			if ( is_array( $json ) && array_is_list( $json ) ) {
				$decoded = array_values( $json );
			} elseif ( preg_match( '/^\s*[\[{]/', $raw ) ) {
				$decoded = [ '[invalid:' . ( is_array( $json ) ? 'json_object' : 'json' ) . ']' ];
			} else {
				$decoded = explode( ',', $raw );
			}
		} elseif ( is_scalar( $raw ) && '' !== (string) $raw ) {
			$decoded = [ $raw ];
		} else {
			continue;
		}
		foreach ( $decoded as $value ) {
			if ( ! is_scalar( $value ) ) {
				$values[] = '[invalid:' . gettype( $value ) . ']';
				continue;
			}
			$value = (string) $value;
			$values[] = $value;
		}
	}
	return $values;
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
		if ( $related_product && (string) $related_product->get_sku() === $sku ) {
			$related[] = [
				'id'   => $related_product->get_id(),
				'name' => $related_product->get_name(),
				'sku'  => $related_product->get_sku(),
				'type' => $related_product->get_type(),
				'source' => in_array( $sku, $compatibility_skus, true ) ? 'compatible_tool_skus' : 'replacement_part_for',
			];
		} elseif ( $related_product ) {
			$unresolved[] = [
				'sku' => $sku,
				'source' => in_array( $sku, $compatibility_skus, true ) ? 'compatible_tool_skus' : 'replacement_part_for',
				'status' => 'sku_mismatch',
				'matched_sku' => (string) $related_product->get_sku(),
				'matched_name' => $related_product->get_name(),
				'matched_id' => $related_product->get_id(),
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
function dtb_product_mapping_repo_audit_page( int $after_id = 0, int $max_id = 0 ): array {
	global $wpdb;
	$page_size = 50;
	$post_types = [ 'product', 'product_variation' ];
	$post_statuses = array_values( get_post_stati( [], 'names' ) );
	$type_placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
	$status_placeholders = implode( ',', array_fill( 0, count( $post_statuses ), '%s' ) );
	$base_args = array_merge( $post_types, $post_statuses );
	if ( $max_id <= 0 ) {
		$max_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders})", $base_args ) );
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders}) AND ID <= %d", array_merge( $base_args, [ $max_id ] ) ) );
	$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders}) AND ID > %d AND ID <= %d ORDER BY ID ASC LIMIT %d", array_merge( $base_args, [ max( 0, $after_id ), $max_id, $page_size ] ) ) );
	$ids = array_values( array_map( 'absint', (array) $ids ) );
	$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type IN ({$type_placeholders}) AND post_status IN ({$status_placeholders}) AND ID > %d AND ID <= %d", array_merge( $base_args, [ max( 0, $after_id ), $max_id ] ) ) );
	$posts = $ids ? get_posts( [
		'post_type' => $post_types,
		'post_status' => $post_statuses,
		'post__in' => $ids,
		'posts_per_page' => $page_size,
		'orderby' => 'post__in',
		'no_found_rows' => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	] ) : [];
	$parent_ids = [];
	foreach ( $posts as $post ) {
		if ( 'product_variation' === $post->post_type && $post->post_parent > 0 ) {
			$parent_ids[] = (int) $post->post_parent;
		}
	}
	$parents = $parent_ids ? get_posts( [
		'post_type' => 'product',
		'post_status' => $post_statuses,
		'post__in' => array_values( array_unique( $parent_ids ) ),
		'posts_per_page' => count( array_unique( $parent_ids ) ),
		'no_found_rows' => true,
		'update_post_meta_cache' => true,
		'update_post_term_cache' => false,
	] ) : [];
	$parent_map = [];
	foreach ( $parents as $parent_post ) {
		$parent_map[ (int) $parent_post->ID ] = $parent_post;
	}
	$visibility_object_ids = array_values( array_unique( array_merge( $ids, $parent_ids ) ) );
	$visibility_by_object_id = [];
	$visibility_available = function_exists( 'taxonomy_exists' ) && taxonomy_exists( 'product_visibility' ) && function_exists( 'wp_get_object_terms' );
	if ( $visibility_available && $visibility_object_ids ) {
		$visibility_terms = wp_get_object_terms( $visibility_object_ids, 'product_visibility', [ 'fields' => 'all_with_object_id' ] );
		if ( ! is_wp_error( $visibility_terms ) && is_array( $visibility_terms ) ) {
			foreach ( $visibility_terms as $term ) {
				if ( ! is_object( $term ) || empty( $term->object_id ) || empty( $term->slug ) ) {
					continue;
				}
				$visibility_by_object_id[ (int) $term->object_id ][ (string) $term->slug ] = true;
			}
		} else {
			$visibility_available = false;
		}
	}
	$catalog_visibility = static function ( int $object_id ) use ( $visibility_available, $visibility_by_object_id ): string {
		if ( ! $visibility_available ) {
			return 'unknown';
		}
		$terms = $visibility_by_object_id[ $object_id ] ?? [];
		$exclude_catalog = isset( $terms['exclude-from-catalog'] );
		$exclude_search = isset( $terms['exclude-from-search'] );
		if ( $exclude_catalog && $exclude_search ) {
			return 'hidden';
		}
		if ( $exclude_catalog ) {
			return 'search';
		}
		if ( $exclude_search ) {
			return 'catalog';
		}
		return 'visible';
	};
	$rows = [];
	foreach ( $posts as $post ) {
		$product_id = (int) $post->ID;
		$native = static function ( string $key ) use ( $product_id ): array {
			$value = get_post_meta( $product_id, $key, true );
			if ( ! is_array( $value ) ) {
				return [];
			}
			$out = [];
			foreach ( $value as $item ) {
				if ( is_int( $item ) || ( is_string( $item ) && preg_match( '/^[0-9]+$/D', $item ) ) ) {
					$out[] = (int) $item;
				} elseif ( is_string( $item ) && '' !== trim( $item ) ) {
					$out[] = trim( $item );
				} else {
					$out[] = gettype( $item );
				}
			}
			return $out;
		};
		$fields = [
			'sku' => get_post_meta( $product_id, '_sku', true ),
			'compatible_tool_skus' => dtb_product_mapping_audit_skus( $product_id, DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ),
			'replacement_part_for' => dtb_product_mapping_audit_skus( $product_id, DTB_ProductMeta::REPLACEMENT_PART_FOR ),
			'legacy_compatible_tools' => $native( '_dtb_compatible_tools' ),
			'legacy_compatible_parts' => $native( '_dtb_compatible_parts' ),
			'crosssell_ids' => $native( '_crosssell_ids' ),
		];
		$truncated = false;
		foreach ( [ 'compatible_tool_skus', 'replacement_part_for', 'legacy_compatible_tools', 'legacy_compatible_parts', 'crosssell_ids' ] as $key ) {
			if ( count( $fields[ $key ] ) > 200 ) {
				$fields[ $key ] = array_slice( $fields[ $key ], 0, 200 );
				$truncated = true;
			}
		}
		$parent_id = 'product_variation' === get_post_type( $product_id ) ? (int) wp_get_post_parent_id( $product_id ) : 0;
		$parent = $parent_map[ $parent_id ] ?? null;
		$visibility_owner_id = $parent_id > 0 ? $parent_id : $product_id;
		$rows[] = [
			'id' => $product_id,
			'name' => (string) get_the_title( $product_id ),
			'post_type' => (string) get_post_type( $product_id ),
			'status' => (string) get_post_status( $product_id ),
			'is_part' => 'part' === (string) get_post_meta( $product_id, DTB_ProductMeta::PRODUCT_KIND, true ) || '1' === (string) get_post_meta( $product_id, DTB_ProductMeta::IS_PARTS, true ),
			'parent_id' => $parent_id,
			'parent_sku' => $parent ? (string) get_post_meta( $parent_id, '_sku', true ) : '',
			'parent_status' => $parent ? (string) $parent->post_status : '',
			'parent_is_part' => $parent_id > 0 && ( 'part' === (string) get_post_meta( $parent_id, DTB_ProductMeta::PRODUCT_KIND, true ) || '1' === (string) get_post_meta( $parent_id, DTB_ProductMeta::IS_PARTS, true ) ),
			'catalog_visibility' => $parent_id > 0 && ! $parent ? 'unknown' : $catalog_visibility( $visibility_owner_id ),
			'fields' => $fields,
			'truncated' => $truncated,
		];
	}
	return [
		'items' => $rows,
		'total' => $total,
		'max_id' => $max_id,
		'next_after_id' => $ids ? max( $ids ) : max( 0, $after_id ),
		'has_more' => $remaining > count( $ids ),
		'per_page' => $page_size,
		'consistency' => 'ID-bounded scan; relationship values can change while pages are read.',
	];
}

/**
 * Get a product's cross-sells.
 *
 * @param int $product_id Product ID.
 * @return array|WP_Error
 */
function dtb_product_mapping_repo_get_relationships( int $product_id ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || $product instanceof WC_Product_Variation ) {
		return new WP_Error( 'product_missing', 'Product not found.' );
	}

	$crosssell_ids = array_values( array_map( 'absint', $product->get_cross_sell_ids() ) );
	$family_related = dtb_product_mapping_repo_get_family_related_products( $product );
	$category_related = dtb_product_mapping_repo_get_category_related_products( $product );
	$target_ids = $crosssell_ids;
	$targets_by_id = [];
	if ( $target_ids ) {
		$targets = wc_get_products(
			[
				'include' => $target_ids,
				'limit'   => count( $target_ids ),
				'status'  => 'any',
				'return'  => 'objects',
			]
		);
		foreach ( $targets as $target ) {
			if ( $target instanceof WC_Product ) {
				$targets_by_id[ $target->get_id() ] = $target;
			}
		}
	}

	$format = static function ( array $ids ) use ( $targets_by_id ): array {
		$out = [];
		foreach ( $ids as $id ) {
			$related = $targets_by_id[ absint( $id ) ] ?? null;
			$out[] = [
				'id' => absint( $id ),
				'name' => $related ? $related->get_name() : __( 'Product record unavailable', 'drywall-toolbox' ),
				'sku' => $related ? (string) $related->get_sku() : '',
				'status' => $related ? (string) get_post_status( $related->get_id() ) : 'missing',
				'type' => $related ? (string) $related->get_type() : 'unknown',
				'catalog_visibility' => $related ? (string) $related->get_catalog_visibility() : 'unknown',
			];
		}
		return $out;
	};

	return [
		'product_id' => $product_id,
		'name'       => $product->get_name(),
		'sku'        => $product->get_sku(),
		'crosssells' => $format( $crosssell_ids ),
		'family_related' => array_map(
			static function ( WC_Product $related ): array {
				return [
					'id' => $related->get_id(),
					'name' => $related->get_name(),
					'sku' => (string) $related->get_sku(),
					'status' => (string) get_post_status( $related->get_id() ),
					'type' => (string) $related->get_type(),
					'catalog_visibility' => (string) $related->get_catalog_visibility(),
				];
			},
			$family_related
		),
		'category_related' => array_map(
			static function ( WC_Product $related ): array {
				return [
					'id' => $related->get_id(),
					'name' => $related->get_name(),
					'sku' => (string) $related->get_sku(),
					'status' => (string) get_post_status( $related->get_id() ),
					'type' => (string) $related->get_type(),
					'catalog_visibility' => (string) $related->get_catalog_visibility(),
				];
			},
			$category_related
		),
		'version'    => hash( 'sha256', wp_json_encode( $crosssell_ids ) ),
	];
}

/** Resolve exact same-family tool/toolset discovery peers without writing merchandising IDs. */
function dtb_product_mapping_repo_get_family_related_products( WC_Product $source ): array {
	$kind = strtolower( trim( (string) $source->get_meta( '_dtb_product_kind', true ) ) );
	if ( ! in_array( $kind, [ 'tool', 'toolset' ], true ) ) {
		return [];
	}
	$group = [
		'_dtb_brand_key' => trim( (string) $source->get_meta( '_dtb_brand_key', true ) ),
		'product_family' => trim( (string) $source->get_meta( 'product_family', true ) ),
	];
	if ( in_array( '', $group, true ) ) {
		return [];
	}
	$meta_query = [ 'relation' => 'AND' ];
	foreach ( $group as $key => $value ) {
		$meta_query[] = [ 'key' => $key, 'value' => $value, 'compare' => '=' ];
	}
	$meta_query[] = [ 'key' => '_dtb_product_kind', 'value' => $kind, 'compare' => '=' ];
	$candidates = wc_get_products( [
		'status' => 'publish',
		'visibility' => 'visible',
		'limit' => 50,
		'return' => 'objects',
		'exclude' => [ $source->get_id() ],
		'meta_query' => $meta_query,
	] );
	usort( $candidates, static function ( $left, $right ): int {
		$left_sku = $left instanceof WC_Product ? (string) $left->get_sku() : '';
		$right_sku = $right instanceof WC_Product ? (string) $right->get_sku() : '';
		return strnatcasecmp( $left_sku, $right_sku );
	} );

	$related = [];
	foreach ( $candidates as $candidate ) {
		if ( ! $candidate instanceof WC_Product || ! in_array( $candidate->get_type(), [ 'simple', 'variable' ], true ) || ! $candidate->is_visible() ) {
			continue;
		}
		$matches_group = strtolower( trim( (string) $candidate->get_meta( '_dtb_product_kind', true ) ) ) === $kind;
		foreach ( $group as $key => $value ) {
			$matches_group = $matches_group && trim( (string) $candidate->get_meta( $key, true ) ) === $value;
		}
		if ( ! $matches_group ) {
			continue;
		}
		$related[] = $candidate;
		if ( count( $related ) >= 10 ) {
			break;
		}
	}
	return $related;
}

/** Resolve exact same-category non-part discovery peers without writing merchandising IDs. */
function dtb_product_mapping_repo_get_category_related_products( WC_Product $source ): array {
	$kind = strtolower( trim( (string) $source->get_meta( '_dtb_product_kind', true ) ) );
	if ( ! in_array( $kind, [ 'tool', 'toolset', 'stilt', 'accessory' ], true ) ) {
		return [];
	}
	$group = [
		'_dtb_brand_key' => trim( (string) $source->get_meta( '_dtb_brand_key', true ) ),
		'_dtb_display_category_key' => trim( (string) $source->get_meta( '_dtb_display_category_key', true ) ),
	];
	if ( in_array( '', $group, true ) ) {
		return [];
	}
	$meta_query = [ 'relation' => 'AND' ];
	foreach ( $group as $key => $value ) {
		$meta_query[] = [ 'key' => $key, 'value' => $value, 'compare' => '=' ];
	}
	$meta_query[] = [ 'key' => '_dtb_product_kind', 'value' => $kind, 'compare' => '=' ];
	$candidates = wc_get_products( [
		'status' => 'publish',
		'visibility' => 'visible',
		'limit' => 50,
		'return' => 'objects',
		'exclude' => [ $source->get_id() ],
		'meta_query' => $meta_query,
	] );
	usort( $candidates, static function ( $left, $right ): int {
		return strnatcasecmp(
			$left instanceof WC_Product ? (string) $left->get_sku() : '',
			$right instanceof WC_Product ? (string) $right->get_sku() : ''
		);
	} );

	$related = [];
	foreach ( $candidates as $candidate ) {
		if ( ! $candidate instanceof WC_Product || ! in_array( $candidate->get_type(), [ 'simple', 'variable' ], true ) || ! $candidate->is_visible() ) {
			continue;
		}
		$matches_group = strtolower( trim( (string) $candidate->get_meta( '_dtb_product_kind', true ) ) ) === $kind;
		foreach ( $group as $key => $value ) {
			$matches_group = $matches_group && trim( (string) $candidate->get_meta( $key, true ) ) === $value;
		}
		if ( ! $matches_group ) {
			continue;
		}
		$related[] = $candidate;
		if ( count( $related ) >= 10 ) {
			break;
		}
	}
	return $related;
}

/** Resolve bounded same-brand discovery peers when no narrower discovery group is populated. */
function dtb_product_mapping_repo_get_brand_related_products( WC_Product $source ): array {
	$brand_key = trim( (string) $source->get_meta( '_dtb_brand_key', true ) );
	$kind      = strtolower( trim( (string) $source->get_meta( '_dtb_product_kind', true ) ) );
	if ( '' === $brand_key || ! in_array( $kind, [ 'tool', 'toolset', 'stilt', 'accessory' ], true ) ) {
		return [];
	}
	$candidates = wc_get_products( [
		'status'     => 'publish',
		'visibility' => 'visible',
		'limit'      => 50,
		'return'     => 'objects',
		'exclude'    => [ $source->get_id() ],
		'meta_query' => [
			'relation' => 'AND',
			[ 'key' => '_dtb_brand_key', 'value' => $brand_key, 'compare' => '=' ],
			[ 'key' => '_dtb_product_kind', 'value' => $kind, 'compare' => '=' ],
		],
	] );
	usort( $candidates, static fn( $left, $right ): int => strnatcasecmp(
		$left instanceof WC_Product ? (string) $left->get_sku() : '',
		$right instanceof WC_Product ? (string) $right->get_sku() : ''
	) );
	$related = [];
	foreach ( $candidates as $candidate ) {
		if ( ! $candidate instanceof WC_Product || ! in_array( $candidate->get_type(), [ 'simple', 'variable' ], true ) || ! $candidate->is_visible() ) {
			continue;
		}
		if ( trim( (string) $candidate->get_meta( '_dtb_brand_key', true ) ) !== $brand_key
			|| strtolower( trim( (string) $candidate->get_meta( '_dtb_product_kind', true ) ) ) !== $kind ) {
			continue;
		}
		$related[] = $candidate;
		if ( count( $related ) >= 10 ) {
			break;
		}
	}
	return $related;
}

/**
 * Save cross-sell relationships.
 *
 * @param int          $product_id Product ID.
 * @param string|array $cross_raw Cross-sell IDs raw input.
 * @return array|WP_Error
 */
function dtb_product_mapping_repo_save_relationships( int $product_id, $cross_raw, string $expected_version = '' ) {
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
		return dtb_product_mapping_repo_save_relationships_locked( $product_id, $cross_raw, $expected_version );
	} finally {
		dtb_product_mapping_release_lock( $lock );
	}
}

function dtb_product_mapping_repo_save_relationships_locked( int $product_id, $cross_raw, string $expected_version ) {
	$product = wc_get_product( $product_id );
	if ( ! $product || $product instanceof WC_Product_Variation || 'publish' !== get_post_status( $product_id ) ) {
		return new WP_Error( 'product_missing', 'Product not found.' );
	}
	$current_version = hash( 'sha256', wp_json_encode( array_values( array_map( 'absint', $product->get_cross_sell_ids() ) ) ) );
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
		$ids = [];
		foreach ( $values as $value ) {
			if ( is_int( $value ) ) {
				$id = $value;
			} elseif ( is_string( $value ) && preg_match( '/^[0-9]+$/D', $value ) ) {
				$id = (int) $value;
			} else {
				return new WP_Error( 'relationship_id_invalid', 'Each relationship must use a positive product ID.' );
			}
			if ( $id <= 0 ) {
				return new WP_Error( 'relationship_id_invalid', 'Each relationship must use a positive product ID.' );
			}
			$ids[] = $id;
		}
		if ( count( $ids ) !== count( array_unique( $ids ) ) ) {
			return new WP_Error( 'relationship_duplicates', 'Remove duplicate or invalid product selections.' );
		}
		return $ids;
	};
	$crosses = $parse_ids( $cross_raw );
	if ( is_wp_error( $crosses ) ) {
		return $crosses;
	}
	$target_ids = $crosses;
	$targets_by_id = [];
	if ( $target_ids ) {
		try {
			$targets = wc_get_products(
				[
					'include' => $target_ids,
					'limit'   => count( $target_ids ),
					'status'  => 'any',
					'return'  => 'objects',
				]
			);
		} catch ( Throwable $error ) {
			if ( class_exists( 'DTB_Logger' ) ) {
				DTB_Logger::error( 'Product merchandising target validation failed', [
					'product_id' => $product_id,
					'exception'  => get_class( $error ),
				] );
			}
			return new WP_Error( 'relationship_target_unverified', 'WooCommerce could not validate the selected products. Reload before retrying.' );
		}
		foreach ( $targets as $target ) {
			if ( $target instanceof WC_Product ) {
				$targets_by_id[ $target->get_id() ] = $target;
			}
		}
	}
	foreach ( $target_ids as $target_id ) {
		$target = $targets_by_id[ $target_id ] ?? null;
		if ( ! $target || $target_id === $product_id || 'publish' !== get_post_status( $target_id ) || $target->is_type( 'variation' ) ) {
			return new WP_Error( 'relationship_target_invalid', 'Relationships require distinct, published product records.' );
		}
	}
	// Target validation can take long enough for a non-workbench WooCommerce writer
	// to change these fields. Re-read immediately before saving to narrow that race.
	try {
		$fresh_product = wc_get_product( $product_id );
	} catch ( Throwable $error ) {
		if ( class_exists( 'DTB_Logger' ) ) {
			DTB_Logger::error( 'Product merchandising relationship pre-save read failed', [
				'product_id' => $product_id,
				'exception'  => get_class( $error ),
			] );
		}
		return new WP_Error( 'relationship_write_unverified', 'WooCommerce could not verify the current relationships before saving. Reload before retrying.' );
	}
	if ( ! $fresh_product || $fresh_product instanceof WC_Product_Variation || 'publish' !== get_post_status( $product_id ) ) {
		return new WP_Error( 'product_missing', 'Product not found.' );
	}
	$fresh_version = hash( 'sha256', wp_json_encode( array_values( array_map( 'absint', $fresh_product->get_cross_sell_ids() ) ) ) );
	if ( ! hash_equals( $fresh_version, $expected_version ) ) {
		return new WP_Error( 'relationship_conflict', 'Relationships changed while the selections were being validated. Refresh and review the current selections.' );
	}
	$product = $fresh_product;
	$save_error = null;
	try {
		$product->set_cross_sell_ids( $crosses );
		$product->save();
	} catch ( Throwable $error ) {
		$save_error = $error;
		if ( class_exists( 'DTB_Logger' ) ) {
			DTB_Logger::error( 'Product merchandising relationship save raised an exception', [
				'product_id' => $product_id,
				'exception'  => get_class( $error ),
			] );
		}
	}
	try {
		$verified = wc_get_product( $product_id );
	} catch ( Throwable $error ) {
		if ( class_exists( 'DTB_Logger' ) ) {
			DTB_Logger::error( 'Product merchandising relationship read-back failed', [
				'product_id' => $product_id,
				'exception'  => get_class( $error ),
			] );
		}
		return new WP_Error( 'relationship_write_unverified', 'WooCommerce could not verify the saved relationships. Reload the product before retrying.' );
	}
	if ( ! $verified || array_values( $verified->get_cross_sell_ids() ) !== $crosses ) {
		return new WP_Error( 'relationship_write_failed', $save_error ? 'WooCommerce reported a save error and the requested relationships were not verified. Reload the product before retrying.' : 'The saved relationships could not be verified. Reload before retrying.' );
	}
	return [ 'message' => 'Cross-sells saved.', 'version' => hash( 'sha256', wp_json_encode( $crosses ) ) ];
}
