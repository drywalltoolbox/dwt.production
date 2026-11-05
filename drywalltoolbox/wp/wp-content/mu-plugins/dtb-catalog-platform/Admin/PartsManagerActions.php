<?php
/**
 * Parts Manager admin AJAX actions.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! dtb_is_admin_or_ajax_request() ) {
	return;
}

add_action( 'wp_ajax_dtb_parts_list', 'dtb_ajax_parts_list' );
add_action( 'wp_ajax_dtb_parts_get', 'dtb_ajax_parts_get' );
add_action( 'wp_ajax_dtb_parts_save', 'dtb_ajax_parts_save' );
add_action( 'wp_ajax_dtb_parts_delete', 'dtb_ajax_parts_delete' );
add_action( 'wp_ajax_dtb_parts_import_csv', 'dtb_ajax_parts_import_csv' );
add_action( 'wp_ajax_dtb_parts_import_schematic_map', 'dtb_ajax_parts_import_schematic_map' );
add_action( 'wp_ajax_dtb_parts_export', 'dtb_ajax_parts_export' );
add_action( 'wp_ajax_dtb_parts_universal_summary', 'dtb_ajax_parts_universal_summary' );
add_action( 'wp_ajax_dtb_parts_universal_list', 'dtb_ajax_parts_universal_list' );
add_action( 'wp_ajax_dtb_parts_universal_sync', 'dtb_ajax_parts_universal_sync' );
add_action( 'wp_ajax_dtb_parts_universal_export', 'dtb_ajax_parts_universal_export' );

function dtb_parts_validate_ajax_request(): void {
	check_ajax_referer( 'dtb_parts_manager_nonce', 'nonce' );
	if ( ! current_user_can( 'dtb_manage_parts' ) ) {
		wp_send_json_error( [ 'message' => 'Unauthorized.' ], 403 );
	}
}

function dtb_parts_is_catalog_part( int $product_id ): bool {
	return 'part' === (string) get_post_meta( $product_id, DTB_ProductMeta::PRODUCT_KIND, true ) || '1' === (string) get_post_meta( $product_id, DTB_ProductMeta::IS_PARTS, true );
}

function dtb_parts_list_query_args( string $search, string $brand, string $status, string $universal_status, int $paged ): array {
	$meta_query = [
		'relation' => 'AND',
		[
			'relation' => 'OR',
			[ 'key' => DTB_ProductMeta::IS_PARTS, 'value' => '1', 'compare' => '=' ],
			[ 'key' => DTB_ProductMeta::PRODUCT_KIND, 'value' => 'part', 'compare' => '=' ],
		],
	];

	if ( '' !== $brand ) {
		$meta_query[] = [
			'key'     => DTB_ProductMeta::BRAND_LABEL,
			'value'   => $brand,
			'compare' => '=',
		];
	}

	if ( '' !== $universal_status ) {
		if ( 'none' === $universal_status ) {
			$meta_query[] = [
				'key'     => DTB_ProductMeta::UNIVERSAL_PART_ID,
				'compare' => 'NOT EXISTS',
			];
		} elseif ( in_array( $universal_status, [ 'active', 'review', 'quarantine' ], true ) ) {
			$meta_query[] = [
				'key'     => DTB_ProductMeta::UNIVERSAL_PART_STATUS,
				'value'   => $universal_status,
				'compare' => '=',
			];
		}
	}

	$args = [
		'post_type'      => 'product',
		'post_status'    => in_array( $status, [ 'publish', 'draft', 'private', 'pending' ], true ) ? $status : [ 'publish', 'draft', 'private', 'pending' ],
		'posts_per_page' => 20,
		'paged'          => max( 1, $paged ),
		'meta_query'     => $meta_query,
		's'              => $search,
		'orderby'        => 'date',
		'order'          => 'DESC',
	];

	if ( '' === $search ) {
		unset( $args['s'] );
	}

	return $args;
}

function dtb_parts_item_payload( int $post_id ): array {
	$product = wc_get_product( $post_id );
	return [
		'id'                         => $post_id,
		'title'                      => get_the_title( $post_id ),
		'sku'                        => $product ? (string) $product->get_sku() : (string) get_post_meta( $post_id, '_sku', true ),
		'brand_label'                => (string) get_post_meta( $post_id, DTB_ProductMeta::BRAND_LABEL, true ),
		'manufacturer_sku'           => (string) get_post_meta( $post_id, DTB_ProductMeta::MANUFACTURER_SKU, true ),
		'price'                      => $product ? (string) $product->get_price() : (string) get_post_meta( $post_id, '_price', true ),
		'status'                     => (string) get_post_status( $post_id ),
		'universal_part_id'          => (string) get_post_meta( $post_id, DTB_ProductMeta::UNIVERSAL_PART_ID, true ),
		'universal_part_status'      => (string) get_post_meta( $post_id, DTB_ProductMeta::UNIVERSAL_PART_STATUS, true ),
		'universal_part_confidence'  => (string) get_post_meta( $post_id, DTB_ProductMeta::UNIVERSAL_PART_CONFIDENCE, true ),
		'universal_part_family'      => (string) get_post_meta( $post_id, DTB_ProductMeta::UNIVERSAL_PART_FAMILY, true ),
		'universal_part_synced_at'   => (string) get_post_meta( $post_id, DTB_ProductMeta::UNIVERSAL_PART_SYNCED_AT, true ),
	];
}

function dtb_ajax_parts_list(): void {
	dtb_parts_validate_ajax_request();

	$search           = sanitize_text_field( $_POST['search'] ?? '' );
	$brand            = sanitize_text_field( $_POST['brand'] ?? '' );
	$status           = sanitize_text_field( $_POST['status'] ?? '' );
	$universal_status = sanitize_key( $_POST['universal_status'] ?? '' );
	$paged            = absint( $_POST['paged'] ?? 1 );

	$q     = new WP_Query( dtb_parts_list_query_args( $search, $brand, $status, $universal_status, $paged ) );
	$items = [];

	foreach ( (array) $q->posts as $post ) {
		$items[] = dtb_parts_item_payload( (int) $post->ID );
	}

	wp_send_json_success(
		[
			'items' => $items,
			'total' => (int) $q->found_posts,
			'pages' => max( 1, (int) $q->max_num_pages ),
		]
	);
}

function dtb_ajax_parts_get(): void {
	dtb_parts_validate_ajax_request();

	$id = absint( $_POST['id'] ?? 0 );
	if ( ! $id || 'product' !== get_post_type( $id ) ) {
		wp_send_json_error( [ 'message' => 'Invalid product ID.' ], 400 );
	}

	wp_send_json_success(
		array_merge(
			dtb_parts_item_payload( $id ),
			[ 'description' => (string) get_post_field( 'post_content', $id ) ]
		)
	);
}

function dtb_ajax_parts_save(): void {
	dtb_parts_validate_ajax_request();

	$id               = absint( $_POST['id'] ?? 0 );
	$title            = sanitize_text_field( $_POST['title'] ?? '' );
	$sku              = sanitize_text_field( $_POST['sku'] ?? '' );
	$brand_label      = sanitize_text_field( $_POST['brand_label'] ?? '' );
	$manufacturer_sku = sanitize_text_field( $_POST['manufacturer_sku'] ?? '' );
	$price            = wc_format_decimal( wp_unslash( $_POST['price'] ?? '' ) );
	$description      = wp_kses_post( wp_unslash( $_POST['description'] ?? '' ) );
	$status           = sanitize_key( $_POST['status'] ?? 'draft' );

	if ( '' === $title || '' === $sku ) {
		wp_send_json_error( [ 'message' => 'Title and SKU are required.' ], 400 );
	}
	if ( ! in_array( $status, [ 'draft', 'publish', 'private', 'pending' ], true ) ) {
		$status = 'draft';
	}
	$product = $id > 0 ? wc_get_product( $id ) : false;
	if ( $id > 0 && ( ! $product || $product instanceof WC_Product_Variation || strtoupper( trim( (string) $product->get_sku() ) ) !== strtoupper( trim( $sku ) ) ) ) {
		wp_send_json_error( [ 'message' => 'SKU is a protected product identity. Existing parts must be edited under their current SKU.' ], 409 );
	}
	if ( $id > 0 && ! dtb_parts_is_catalog_part( $id ) ) {
		wp_send_json_error( [ 'message' => 'This product is not classified as a part and cannot be edited in Parts Manager.' ], 409 );
	}
	$sku_owner = wc_get_product_id_by_sku( $sku );
	if ( $sku_owner > 0 && $sku_owner !== $id ) {
		wp_send_json_error( [ 'message' => 'This SKU is already assigned to another WooCommerce product or variation.' ], 409 );
	}
	if ( $id > 0 && (string) get_post_meta( $id, DTB_ProductMeta::MANUFACTURER_SKU, true ) !== $manufacturer_sku ) {
		wp_send_json_error( [ 'message' => 'Manufacturer part number is protected. Existing parts must be edited under their current manufacturer part number.' ], 409 );
	}

	try {
		if ( ! $product ) {
			$product = new WC_Product_Simple();
			$product->set_sku( $sku );
		}
		$product->set_name( $title );
		$product->set_description( $description );
		$product->set_status( $status );
		$product->set_regular_price( '' === $price ? '' : $price );
		$id = (int) $product->save();
	} catch ( Throwable $error ) {
		wp_send_json_error( [ 'message' => 'WooCommerce rejected the product update. Verify SKU uniqueness and required product fields.' ], 400 );
	}
	if ( ! $id ) {
		wp_send_json_error( [ 'message' => 'Unable to save product record through WooCommerce.' ], 500 );
	}
	update_post_meta( $id, DTB_ProductMeta::IS_PARTS, '1' );
	update_post_meta( $id, DTB_ProductMeta::PRODUCT_KIND, 'part' );
	update_post_meta( $id, DTB_ProductMeta::BRAND_LABEL, $brand_label );
	update_post_meta( $id, DTB_ProductMeta::MANUFACTURER_SKU, $manufacturer_sku );

	wp_send_json_success( [ 'id' => $id ] );
}

function dtb_ajax_parts_delete(): void {
	dtb_parts_validate_ajax_request();

	$id = absint( $_POST['id'] ?? 0 );
	if ( ! $id || 'product' !== get_post_type( $id ) || ! dtb_parts_is_catalog_part( $id ) ) {
		wp_send_json_error( [ 'message' => 'Invalid product ID.' ], 400 );
	}

	$deleted = wp_trash_post( $id );
	if ( ! $deleted ) {
		wp_send_json_error( [ 'message' => 'Unable to move part to trash.' ], 500 );
	}

	wp_send_json_success( [ 'deleted' => true ] );
}

function dtb_find_part_id_by_sku( string $sku ): int {
	if ( '' === $sku ) {
		return 0;
	}
	$ids = get_posts(
		[
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => '_sku',
					'value' => $sku,
				],
			],
		]
	);
	foreach ( $ids as $id ) {
		if ( dtb_parts_is_catalog_part( (int) $id ) ) {
			return (int) $id;
		}
	}
	return 0;
}

function dtb_find_part_id_by_manufacturer_sku( string $manufacturer_sku ): int {
	if ( '' === $manufacturer_sku ) {
		return 0;
	}
	$ids = get_posts(
		[
			'post_type'      => 'product',
			'post_status'    => 'any',
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'   => DTB_ProductMeta::MANUFACTURER_SKU,
					'value' => $manufacturer_sku,
				],
			],
		]
	);
	foreach ( $ids as $id ) {
		if ( dtb_parts_is_catalog_part( (int) $id ) ) {
			return (int) $id;
		}
	}
	return 0;
}

function dtb_parts_detect_brand_taxonomy(): string {
	foreach ( [ 'product_brand', 'wc_product_brands', 'pwb-brand' ] as $taxonomy ) {
		if ( taxonomy_exists( $taxonomy ) ) {
			return $taxonomy;
		}
	}
	return '';
}

function dtb_parts_parse_list_field( string $raw ): array {
	if ( '' === trim( $raw ) ) {
		return [];
	}
	$items = preg_split( '/\s*,\s*/', $raw );
	$items = array_map( static fn( $v ) => trim( (string) $v ), is_array( $items ) ? $items : [] );
	$items = array_values( array_filter( array_unique( $items ), static fn( $v ) => '' !== $v ) );
	return $items;
}

function dtb_parts_apply_brand_terms( int $post_id, string $brands_csv, string $brand_label ): void {
	$taxonomy = dtb_parts_detect_brand_taxonomy();
	if ( '' === $taxonomy ) {
		return;
	}

	$terms = dtb_parts_parse_list_field( $brands_csv );
	if ( empty( $terms ) && '' !== trim( $brand_label ) ) {
		$terms = [ trim( $brand_label ) ];
	}
	if ( empty( $terms ) ) {
		return;
	}

	wp_set_object_terms( $post_id, $terms, $taxonomy, false );
}

function dtb_parts_ensure_category_path( string $path ): ?int {
	$segments = preg_split( '/\s*>\s*/', $path );
	$segments = array_map( static fn( $v ) => trim( (string) $v ), is_array( $segments ) ? $segments : [] );
	$segments = array_values( array_filter( $segments, static fn( $v ) => '' !== $v ) );
	if ( empty( $segments ) ) {
		return null;
	}

	$parent_id = 0;
	$last_id   = 0;
	foreach ( $segments as $segment ) {
		$existing = term_exists( $segment, 'product_cat', $parent_id );
		if ( is_array( $existing ) && ! empty( $existing['term_id'] ) ) {
			$last_id   = (int) $existing['term_id'];
			$parent_id = $last_id;
			continue;
		}

		$created = wp_insert_term(
			$segment,
			'product_cat',
			[
				'parent' => $parent_id,
			]
		);
		if ( is_wp_error( $created ) || empty( $created['term_id'] ) ) {
			return null;
		}

		$last_id   = (int) $created['term_id'];
		$parent_id = $last_id;
	}

	return $last_id > 0 ? $last_id : null;
}

function dtb_parts_apply_category_terms( int $post_id, string $categories_csv ): void {
	if ( ! taxonomy_exists( 'product_cat' ) ) {
		return;
	}

	$paths = dtb_parts_parse_list_field( $categories_csv );
	if ( empty( $paths ) ) {
		return;
	}

	$term_ids = [];
	foreach ( $paths as $path ) {
		$term_id = dtb_parts_ensure_category_path( $path );
		if ( null !== $term_id ) {
			$term_ids[] = $term_id;
		}
	}

	$term_ids = array_values( array_unique( array_filter( $term_ids ) ) );
	if ( ! empty( $term_ids ) ) {
		wp_set_object_terms( $post_id, $term_ids, 'product_cat', false );
	}
}

function dtb_append_schematic_map_to_part( int $part_id, array $entry ): bool {
	$current = get_post_meta( $part_id, '_dtb_schematic_part_map', true );
	if ( ! is_array( $current ) ) {
		$current = [];
	}

	$fingerprint = implode(
		'|',
		[
			(string) ( $entry['schematic_id'] ?? '' ),
			(string) ( $entry['part_id'] ?? '' ),
			(string) ( $entry['source_sku'] ?? '' ),
		]
	);

	foreach ( $current as $row ) {
		$existing_fingerprint = implode(
			'|',
			[
				(string) ( $row['schematic_id'] ?? '' ),
				(string) ( $row['part_id'] ?? '' ),
				(string) ( $row['source_sku'] ?? '' ),
			]
		);
		if ( $existing_fingerprint === $fingerprint ) {
			return false;
		}
	}

	$current[] = $entry;
	update_post_meta( $part_id, '_dtb_schematic_part_map', $current );
	return true;
}

function dtb_ajax_parts_import_csv(): void {
	dtb_parts_validate_ajax_request();
	if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
		wp_send_json_error( [ 'message' => 'CSV file is required.' ], 400 );
	}

	$fp = fopen( $_FILES['file']['tmp_name'], 'r' );
	if ( false === $fp ) {
		wp_send_json_error( [ 'message' => 'Unable to read uploaded CSV.' ], 400 );
	}

	$header = fgetcsv( $fp );
	if ( ! is_array( $header ) ) {
		fclose( $fp );
		wp_send_json_error( [ 'message' => 'CSV header row is missing.' ], 400 );
	}
	$header = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $header );
	$map    = array_flip( $header );
	foreach ( [ 'sku', 'title' ] as $required ) {
		if ( ! isset( $map[ $required ] ) ) {
			fclose( $fp );
			wp_send_json_error( [ 'message' => sprintf( 'Missing required column: %s', $required ) ], 400 );
		}
	}

	$row_num  = 1;
	$imported = 0;
	$errors   = [];

	while ( ( $row = fgetcsv( $fp ) ) !== false ) {
		$row_num++;
		if ( empty( array_filter( $row, static fn( $v ) => '' !== trim( (string) $v ) ) ) ) {
			continue;
		}

		$id_raw           = isset( $map['id'] ) ? absint( $row[ $map['id'] ] ?? 0 ) : 0;
		$sku              = sanitize_text_field( (string) ( $row[ $map['sku'] ] ?? '' ) );
		$title            = sanitize_text_field( (string) ( $row[ $map['title'] ] ?? '' ) );
		$brand_label      = isset( $map['brand_label'] ) ? sanitize_text_field( (string) ( $row[ $map['brand_label'] ] ?? '' ) ) : '';
		$manufacturer_sku = isset( $map['manufacturer_sku'] ) ? sanitize_text_field( (string) ( $row[ $map['manufacturer_sku'] ] ?? '' ) ) : '';
		$price            = isset( $map['price'] ) ? wc_format_decimal( (string) ( $row[ $map['price'] ] ?? '' ) ) : '';
		$brands_csv       = isset( $map['brands'] ) ? sanitize_text_field( (string) ( $row[ $map['brands'] ] ?? '' ) ) : '';
		$categories_csv   = isset( $map['categories'] ) ? sanitize_text_field( (string) ( $row[ $map['categories'] ] ?? '' ) ) : '';
		if ( '' === trim( $categories_csv ) ) {
			$categories_csv = 'Parts';
		}
		$status      = isset( $map['status'] ) ? sanitize_key( (string) ( $row[ $map['status'] ] ?? 'draft' ) ) : 'draft';
		$description = isset( $map['description'] ) ? wp_kses_post( (string) ( $row[ $map['description'] ] ?? '' ) ) : '';
		$universal_fields = [ 'universal_part_id', 'universal_part_status', 'universal_part_confidence', 'universal_part_family', 'universal_part_signature' ];
		$has_universal_projection = false;
		foreach ( $universal_fields as $field ) {
			if ( isset( $map[ $field ] ) && '' !== trim( (string) ( $row[ $map[ $field ] ] ?? '' ) ) ) {
				$has_universal_projection = true;
				break;
			}
		}
		if ( $has_universal_projection ) {
			$errors[] = "Row {$row_num}: universal-part values are read-only projections. Update the universal seed records, then run the reviewed sync.";
			continue;
		}

		if ( '' === $sku || '' === $title ) {
			$errors[] = "Row {$row_num}: sku and title are required.";
			continue;
		}
		if ( ! in_array( $status, [ 'draft', 'publish', 'private', 'pending' ], true ) ) {
			$status = 'draft';
		}

		$id = $id_raw;
		if ( $id <= 0 ) {
			$id = dtb_find_part_id_by_sku( $sku );
		}
		$existing_product = $id > 0 ? wc_get_product( $id ) : false;
		if ( $id > 0 && ( ! $existing_product || $existing_product instanceof WC_Product_Variation || strtoupper( trim( (string) $existing_product->get_sku() ) ) !== strtoupper( trim( $sku ) ) ) ) {
			$errors[] = "Row {$row_num}: existing product SKU does not exactly match; protected identity was not changed.";
			continue;
		}
		if ( $id > 0 && ! dtb_parts_is_catalog_part( $id ) ) {
			$errors[] = "Row {$row_num}: existing product is not classified as a part; no changes were made.";
			continue;
		}
		$sku_owner = wc_get_product_id_by_sku( $sku );
		if ( $sku_owner > 0 && ( $sku_owner !== $id || ( ! $existing_product && $sku_owner > 0 ) ) ) {
			$errors[] = "Row {$row_num}: SKU is already assigned to another product or variation.";
			continue;
		}
		if ( $id > 0 && (string) get_post_meta( $id, DTB_ProductMeta::MANUFACTURER_SKU, true ) !== $manufacturer_sku ) {
			$errors[] = "Row {$row_num}: existing manufacturer part number does not exactly match; protected identity was not changed.";
			continue;
		}

		try {
			$product = $existing_product ?: new WC_Product_Simple();
			if ( ! $existing_product ) {
				$product->set_sku( $sku );
			}
			$product->set_name( $title );
			$product->set_description( $description );
			$product->set_status( $status );
			$product->set_regular_price( '' === $price ? '' : $price );
			$id = (int) $product->save();
		} catch ( Throwable $error ) {
			$errors[] = "Row {$row_num}: WooCommerce rejected the part update; verify SKU uniqueness and required fields.";
			continue;
		}
		if ( $id <= 0 ) {
			$errors[] = "Row {$row_num}: failed to save part record.";
			continue;
		}

		update_post_meta( $id, DTB_ProductMeta::IS_PARTS, '1' );
		update_post_meta( $id, DTB_ProductMeta::PRODUCT_KIND, 'part' );
		update_post_meta( $id, DTB_ProductMeta::BRAND_LABEL, $brand_label );
		update_post_meta( $id, DTB_ProductMeta::MANUFACTURER_SKU, $manufacturer_sku );

		dtb_parts_apply_brand_terms( $id, $brands_csv, $brand_label );
		dtb_parts_apply_category_terms( $id, $categories_csv );
		$imported++;
	}
	fclose( $fp );

	wp_send_json_success(
		[
			'imported' => $imported,
			'errors'   => $errors,
			'message'  => sprintf( 'Imported %d parts rows.', $imported ),
		]
	);
}

function dtb_ajax_parts_import_schematic_map(): void {
	dtb_parts_validate_ajax_request();
	if ( empty( $_FILES['file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['file']['tmp_name'] ) ) {
		wp_send_json_error( [ 'message' => 'CSV file is required.' ], 400 );
	}

	$fp = fopen( $_FILES['file']['tmp_name'], 'r' );
	if ( false === $fp ) {
		wp_send_json_error( [ 'message' => 'Unable to read uploaded CSV.' ], 400 );
	}

	$header = fgetcsv( $fp );
	if ( ! is_array( $header ) ) {
		fclose( $fp );
		wp_send_json_error( [ 'message' => 'CSV header row is missing.' ], 400 );
	}

	$header = array_map( static fn( $h ) => strtolower( trim( (string) $h ) ), $header );
	$map    = array_flip( $header );
	foreach ( [ 'schematic_id', 'part_id', 'part_name', 'qty', 'source_sku' ] as $required ) {
		if ( ! isset( $map[ $required ] ) ) {
			fclose( $fp );
			wp_send_json_error( [ 'message' => sprintf( 'Missing required column: %s', $required ) ], 400 );
		}
	}

	$row_num   = 1;
	$linked    = 0;
	$matched   = 0;
	$unmatched = 0;
	$errors    = [];

	while ( ( $row = fgetcsv( $fp ) ) !== false ) {
		$row_num++;
		if ( empty( array_filter( $row, static fn( $v ) => '' !== trim( (string) $v ) ) ) ) {
			continue;
		}

		$schematic_id = sanitize_text_field( (string) ( $row[ $map['schematic_id'] ] ?? '' ) );
		$part_id      = sanitize_text_field( (string) ( $row[ $map['part_id'] ] ?? '' ) );
		$part_name    = sanitize_text_field( (string) ( $row[ $map['part_name'] ] ?? '' ) );
		$qty          = sanitize_text_field( (string) ( $row[ $map['qty'] ] ?? '' ) );
		$source_sku   = sanitize_text_field( (string) ( $row[ $map['source_sku'] ] ?? '' ) );

		if ( '' === $schematic_id && '' === $part_id && '' === $part_name && '' === $source_sku ) {
			continue;
		}

		$target_part_id = dtb_find_part_id_by_sku( $source_sku );
		if ( $target_part_id <= 0 ) {
			$target_part_id = dtb_find_part_id_by_manufacturer_sku( $source_sku );
		}

		if ( $target_part_id <= 0 ) {
			$unmatched++;
			$errors[] = sprintf( 'Row %d: no part match for source_sku "%s".', $row_num, $source_sku );
			continue;
		}
		$matched++;

		$did_add = dtb_append_schematic_map_to_part(
			$target_part_id,
			[
				'schematic_id' => $schematic_id,
				'part_id'      => $part_id,
				'part_name'    => $part_name,
				'qty'          => $qty,
				'source_sku'   => $source_sku,
				'imported_at'  => gmdate( 'c' ),
			]
		);

		if ( $did_add ) {
			$linked++;
		}
	}
	fclose( $fp );

	wp_send_json_success(
		[
			'linked'    => $linked,
			'matched'   => $matched,
			'unmatched' => $unmatched,
			'errors'    => $errors,
			'message'   => sprintf(
				'Processed schematic-parts map. %d linked, %d matched, %d unmatched.',
				$linked,
				$matched,
				$unmatched
			),
		]
	);
}

function dtb_ajax_parts_export(): void {
	dtb_parts_validate_ajax_request();
	$format = sanitize_key( $_POST['format'] ?? 'csv' );
	if ( ! in_array( $format, [ 'csv', 'json' ], true ) ) {
		$format = 'csv';
	}

	$ids = get_posts(
		[
			'post_type'      => 'product',
			'post_status'    => [ 'publish', 'draft', 'private', 'pending' ],
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [
				[
					'key'     => DTB_ProductMeta::IS_PARTS,
					'value'   => '1',
					'compare' => '=',
				],
			],
		]
	);

	$rows = [];
	foreach ( (array) $ids as $id ) {
		$id   = (int) $id;
		$row  = dtb_parts_item_payload( $id );
		$rows[] = array_merge(
			$row,
			[
				'description'              => (string) get_post_field( 'post_content', $id ),
				'universal_part_signature' => (string) get_post_meta( $id, DTB_ProductMeta::UNIVERSAL_PART_SIGNATURE, true ),
			]
		);
	}

	if ( 'json' === $format ) {
		wp_send_json_success(
			[
				'filename' => 'dtb-parts-export-' . gmdate( 'Ymd-His' ) . '.json',
				'mime'     => 'application/json;charset=utf-8',
				'content'  => wp_json_encode( $rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
			]
		);
	}

	$headers = [ 'id', 'sku', 'title', 'brand_label', 'manufacturer_sku', 'price', 'status', 'description' ];
	$csv     = implode( ',', $headers ) . "\n";
	foreach ( $rows as $row ) {
		$line = [];
		foreach ( $headers as $h ) {
			$val    = (string) ( $row[ $h ] ?? '' );
			$line[] = '"' . str_replace( '"', '""', $val ) . '"';
		}
		$csv .= implode( ',', $line ) . "\n";
	}

	wp_send_json_success(
		[
			'filename' => 'dtb-parts-export-' . gmdate( 'Ymd-His' ) . '.csv',
			'mime'     => 'text/csv;charset=utf-8',
			'content'  => $csv,
		]
	);
}

function dtb_parts_universal_data_dir(): string {
	return ( new DTB_UniversalPartsProjectionService() )->data_dir();
}

function dtb_parts_universal_file_path( string $type ): string {
	$files = [
		'parts'         => 'parts.csv',
		'members'       => 'members.csv',
		'compatibility' => 'compatibility.csv',
	];
	return dtb_parts_universal_data_dir() . '/' . ( $files[ $type ] ?? '' );
}

function dtb_parts_read_csv_assoc( string $path ): array {
	if ( ! is_readable( $path ) ) {
		return [];
	}
	$fp = fopen( $path, 'r' );
	if ( false === $fp ) {
		return [];
	}
	$header = fgetcsv( $fp );
	if ( ! is_array( $header ) ) {
		fclose( $fp );
		return [];
	}
	$header = array_map( static fn( $h ) => trim( (string) $h ), $header );
	$rows   = [];
	while ( ( $row = fgetcsv( $fp ) ) !== false ) {
		$assoc = [];
		foreach ( $header as $idx => $key ) {
			$assoc[ $key ] = (string) ( $row[ $idx ] ?? '' );
		}
		$rows[] = $assoc;
	}
	fclose( $fp );
	return $rows;
}

function dtb_parts_universal_stats(): array {
	$parts         = dtb_parts_read_csv_assoc( dtb_parts_universal_file_path( 'parts' ) );
	$members       = dtb_parts_read_csv_assoc( dtb_parts_universal_file_path( 'members' ) );
	$compatibility = dtb_parts_read_csv_assoc( dtb_parts_universal_file_path( 'compatibility' ) );

	$counts = [
		'parts'         => count( $parts ),
		'members'       => count( $members ),
		'compatibility' => count( $compatibility ),

		// Status counts.
		'active'     => 0,
		'review'     => 0,
		'quarantine' => 0,

		// Confidence counts.
		'verified' => 0,
		'high'     => 0,
		'medium'   => 0,
		'low'      => 0,
	];

	foreach ( $parts as $row ) {
		$status = (string) ( $row['status'] ?? '' );
		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ]++;
		}
		$confidence = (string) ( $row['confidence'] ?? '' );
		if ( isset( $counts[ $confidence ] ) ) {
			$counts[ $confidence ]++;
		}
	}

	$seed_dir = dtb_parts_universal_data_dir();
	return [
		'counts'       => $counts,
		'seed_dir'     => $seed_dir,
		'seed_exists'  => is_dir( $seed_dir ),
		'files'        => [
			'parts'         => is_readable( dtb_parts_universal_file_path( 'parts' ) ),
			'members'       => is_readable( dtb_parts_universal_file_path( 'members' ) ),
			'compatibility' => is_readable( dtb_parts_universal_file_path( 'compatibility' ) ),
		],
	];
}

function dtb_ajax_parts_universal_summary(): void {
	dtb_parts_validate_ajax_request();
	wp_send_json_success( dtb_parts_universal_stats() );
}

function dtb_ajax_parts_universal_list(): void {
	dtb_parts_validate_ajax_request();
	$status = sanitize_key( $_POST['status'] ?? '' );
	$search = strtolower( sanitize_text_field( $_POST['search'] ?? '' ) );
	$paged  = max( 1, absint( $_POST['paged'] ?? 1 ) );
	$limit  = 20;

	$rows = dtb_parts_read_csv_assoc( dtb_parts_universal_file_path( 'parts' ) );
	$rows = array_values( array_filter( $rows, static function ( array $row ) use ( $status, $search ): bool {
		if ( '' !== $status && (string) ( $row['status'] ?? '' ) !== $status ) {
			return false;
		}
		if ( '' !== $search ) {
			$haystack = strtolower( implode( ' ', [ $row['universal_part_id'] ?? '', $row['canonical_name'] ?? '', $row['brands'] ?? '', $row['catalog_skus'] ?? '' ] ) );
			return false !== strpos( $haystack, $search );
		}
		return true;
	} ) );

	$total = count( $rows );
	$pages = max( 1, (int) ceil( $total / $limit ) );
	$slice = array_slice( $rows, ( $paged - 1 ) * $limit, $limit );

	wp_send_json_success(
		[
			'items' => $slice,
			'total' => $total,
			'pages' => $pages,
		]
	);
}

function dtb_ajax_parts_universal_sync(): void {
	dtb_parts_validate_ajax_request();
	$mode = sanitize_key( $_POST['mode'] ?? 'dry_run' );
	if ( ! in_array( $mode, [ 'dry_run', 'apply' ], true ) ) {
		$mode = 'dry_run';
	}
	$projection = new DTB_UniversalPartsProjectionService();
	$result = $projection->sync_members( $mode );
	if ( ! empty( $result['error'] ) ) {
		wp_send_json_error( [ 'code' => $result['error'], 'message' => $result['message'] ], 503 );
		return;
	}
	wp_send_json_success( $result );
}

function dtb_ajax_parts_universal_export(): void {
	dtb_parts_validate_ajax_request();
	$type = sanitize_key( $_POST['type'] ?? 'parts' );
	if ( ! in_array( $type, [ 'parts', 'members', 'compatibility' ], true ) ) {
		$type = 'parts';
	}
	$path = dtb_parts_universal_file_path( $type );
	if ( ! is_readable( $path ) ) {
		wp_send_json_error( [ 'message' => 'Universal seed file is not readable.' ], 404 );
	}

	wp_send_json_success(
		[
			'filename' => 'dtb-universal-' . $type . '-' . gmdate( 'Ymd-His' ) . '.csv',
			'mime'     => 'text/csv;charset=utf-8',
			'content'  => (string) file_get_contents( $path ),
		]
	);
}
