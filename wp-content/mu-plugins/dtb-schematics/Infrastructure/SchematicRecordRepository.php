<?php
/**
 * DTB Schematics — SchematicRecordRepository
 *
 * CPT-backed persistence for the authoritative `dtb_schematic` domain record.
 * This is the only code allowed to write `_dtb_schematic_*` postmeta on the
 * `dtb_schematic` post type. All writes must go through
 * Application/ManageSchematicRecord.php — never call the `*_save()` function
 * here directly from transport/admin code.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetch a single schematic record by internal post ID.
 */
function dtb_schematic_record_repo_get( int $id ): ?DTB_Schematic_Record_Entity {
	$post = get_post( $id );
	if ( ! $post || DTB_SCHEMATIC_POST_TYPE !== $post->post_type ) {
		return null;
	}
	return DTB_Schematic_Record_Entity::from_post( $post );
}

/**
 * Fetch a single schematic record by its immutable canonical ID.
 */
function dtb_schematic_record_repo_find_by_canonical_id( string $canonical_id ): ?DTB_Schematic_Record_Entity {
	$canonical_id = sanitize_key( $canonical_id );
	if ( '' === $canonical_id ) {
		return null;
	}

	$posts = get_posts(
		[
			'post_type'      => DTB_SCHEMATIC_POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_dtb_schematic_canonical_id',
					'value' => $canonical_id,
				],
			],
			'no_found_rows'  => true,
		]
	);

	if ( empty( $posts ) ) {
		return null;
	}

	return DTB_Schematic_Record_Entity::from_post( $posts[0] );
}

/**
 * Query schematic records for admin/reconciliation use. Bounded and paginated.
 *
 * @param array $args {
 *     @type string $lifecycle Filter by lifecycle state.
 *     @type string $brand_id  Filter by brand ID.
 *     @type string $search    Free-text search (title).
 *     @type int    $page      1-based page number.
 *     @type int    $per_page  Page size, capped at 200.
 * }
 * @return array{items: DTB_Schematic_Record_Entity[], total: int, pages: int}
 */
function dtb_schematic_record_repo_query( array $args = [] ): array {
	$per_page = min( 200, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

	$query_args = [
		'post_type'      => DTB_SCHEMATIC_POST_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	];

	$meta_query = [];

	if ( ! empty( $args['lifecycle'] ) ) {
		$meta_query[] = [
			'key'   => '_dtb_schematic_lifecycle',
			'value' => sanitize_key( (string) $args['lifecycle'] ),
		];
	}

	if ( ! empty( $args['brand_id'] ) ) {
		$meta_query[] = [
			'key'   => '_dtb_schematic_brand_id',
			'value' => sanitize_key( (string) $args['brand_id'] ),
		];
	}

	if ( ! empty( $meta_query ) ) {
		$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = sanitize_text_field( (string) $args['search'] );
	}

	$query = new WP_Query( $query_args );

	$items = [];
	foreach ( $query->posts as $post ) {
		$items[] = DTB_Schematic_Record_Entity::from_post( $post );
	}

	return [
		'items' => $items,
		'total' => (int) $query->found_posts,
		'pages' => (int) $query->max_num_pages,
	];
}

/**
 * Persist (create or update) a schematic record.
 *
 * The canonical ID is immutable: once set on an existing record, any
 * attempt to change it is ignored (the stored value wins).
 *
 * @param array $data Fields matching DTB_Schematic_Record_Entity properties (array shape, see to_array()).
 * @return int|WP_Error New/updated post ID, or WP_Error on failure.
 */
function dtb_schematic_record_repo_save( array $data ) {
	$id = (int) ( $data['id'] ?? 0 );

	$existing_canonical_id = '';
	if ( $id ) {
		$existing = dtb_schematic_record_repo_get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'dtb_schematic_not_found', __( 'Schematic record not found.', 'drywall-toolbox' ) );
		}
		$existing_canonical_id = $existing->canonical_id;
	}

	$canonical_id = $existing_canonical_id ?: sanitize_key( (string) ( $data['canonical_id'] ?? '' ) );
	if ( '' === $canonical_id ) {
		return new WP_Error( 'dtb_schematic_missing_canonical_id', __( 'A canonical schematic ID is required.', 'drywall-toolbox' ) );
	}

	// Collision guard: canonical IDs are unique across the domain.
	if ( ! $id ) {
		$collision = dtb_schematic_record_repo_find_by_canonical_id( $canonical_id );
		if ( $collision ) {
			return new WP_Error( 'dtb_schematic_duplicate_canonical_id', __( 'A schematic with this canonical ID already exists.', 'drywall-toolbox' ) );
		}
	}

	$title = sanitize_text_field( (string) ( $data['title'] ?? $canonical_id ) );

	$post_data = [
		'post_type'   => DTB_SCHEMATIC_POST_TYPE,
		'post_status' => 'publish',
		'post_title'  => '' !== $title ? $title : $canonical_id,
		'post_name'   => $canonical_id,
	];

	if ( $id ) {
		$post_data['ID'] = $id;
		$result = wp_update_post( $post_data, true );
	} else {
		$result = wp_insert_post( $post_data, true );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$id = (int) $result;

	update_post_meta( $id, '_dtb_schematic_canonical_id', $canonical_id );

	if ( array_key_exists( 'source_schema_version', $data ) ) {
		update_post_meta( $id, '_dtb_schematic_source_schema_version', sanitize_text_field( (string) $data['source_schema_version'] ) );
	}

	if ( array_key_exists( 'lifecycle', $data ) ) {
		$lifecycle = new DTB_Schematic_Lifecycle_Status( (string) $data['lifecycle'] );
		update_post_meta( $id, '_dtb_schematic_lifecycle', $lifecycle->value() );
	} elseif ( ! $existing_canonical_id ) {
		// New record with no explicit lifecycle: starts as draft.
		update_post_meta( $id, '_dtb_schematic_lifecycle', DTB_Schematic_Lifecycle_Status::DRAFT );
	}

	$meta_map = [
		'_dtb_schematic_brand_id'      => 'brand_id',
		'_dtb_schematic_brand_name'    => 'brand_name',
		'_dtb_schematic_category_id'   => 'category_id',
		'_dtb_schematic_category_name' => 'category_name',
		'_dtb_schematic_family_id'     => 'family_id',
		'_dtb_schematic_variant_label' => 'variant_label',
		'_dtb_schematic_model'         => 'model',
		'_dtb_schematic_source_version'=> 'source_version',
	];
	foreach ( $meta_map as $meta_key => $field ) {
		if ( array_key_exists( $field, $data ) ) {
			update_post_meta( $id, $meta_key, sanitize_text_field( (string) $data[ $field ] ) );
		}
	}

	if ( array_key_exists( 'description', $data ) ) {
		update_post_meta( $id, '_dtb_schematic_description', sanitize_textarea_field( (string) $data['description'] ) );
	}

	if ( array_key_exists( 'aliases', $data ) ) {
		$aliases = array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) $data['aliases'] ) ) ) );
		update_post_meta( $id, '_dtb_schematic_aliases', $aliases );
	}

	if ( array_key_exists( 'pages', $data ) ) {
		$pages = array_map( 'dtb_schematic_page_make', (array) $data['pages'] );
		usort( $pages, fn( $a, $b ) => $a['page_number'] <=> $b['page_number'] );
		update_post_meta( $id, '_dtb_schematic_pages', $pages );
	}

	if ( array_key_exists( 'preview_policy', $data ) ) {
		update_post_meta( $id, '_dtb_schematic_preview_policy', dtb_schematic_preview_policy_make( (array) $data['preview_policy'] ) );
	}

	if ( array_key_exists( 'linked_products', $data ) ) {
		update_post_meta( $id, '_dtb_schematic_linked_products', dtb_schematic_normalize_product_ids( $data['linked_products'] ) );
	}

	if ( array_key_exists( 'hotspot_dataset', $data ) ) {
		update_post_meta( $id, '_dtb_schematic_hotspot_dataset', dtb_schematic_hotspot_dataset_ref_make( (array) $data['hotspot_dataset'] ) );
	}

	if ( array_key_exists( 'parts', $data ) ) {
		$parts = array_map( 'dtb_schematic_part_relationship_make', (array) $data['parts'] );
		update_post_meta( $id, '_dtb_schematic_parts', $parts );
	}

	if ( array_key_exists( 'source_provenance', $data ) ) {
		update_post_meta( $id, '_dtb_schematic_source_provenance', (array) $data['source_provenance'] );
	}

	if ( array_key_exists( 'publication_version', $data ) ) {
		update_post_meta( $id, '_dtb_schematic_publication_version', max( 0, (int) $data['publication_version'] ) );
	}

	return $id;
}

/**
 * Hard-delete a schematic record. Distinct from retirement (a lifecycle
 * state that preserves the administrative record) — only used for
 * operator-initiated cleanup of records created in error.
 */
function dtb_schematic_record_repo_delete( int $id ): bool {
	$post = get_post( $id );
	if ( ! $post || DTB_SCHEMATIC_POST_TYPE !== $post->post_type ) {
		return false;
	}
	return (bool) wp_delete_post( $id, true );
}
