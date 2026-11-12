<?php
/**
 * DTB_VariationReadModelService
 *
 * Fetches and normalizes all child variations for a variable product.
 * Returns an array of DTB Catalog Variation DTOs sorted by _dtb_variation_sort,
 * then by variation ID as a stable tie-breaker.
 *
 * Variation identity comes only from the WooCommerce parent-child relationship.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_VariationReadModelService {

	/** @var array<string,mixed> */
	private static array $last_diagnostics = [];

	/**
	 * Fetch and normalize all variations for a WC variable product.
	 *
	 * @param  int   $parent_id  WC product ID.
	 * @param  array $parent_wc  Raw parent WC product array (for image/meta fallback).
	 * @return array[]|WP_Error  Array of DTB variation DTOs or a temporary read failure.
	 */
	public static function get_normalized( int $parent_id, array $parent_wc, bool $include_gallery = true ): array|WP_Error {
		self::$last_diagnostics = [
			'parentId'                => $parent_id,
			'parentSku'               => (string) ( $parent_wc['sku'] ?? '' ),
			'directChildCount'        => 0,
			'normalizedCount'         => 0,
			'explicitGalleryCount'    => 0,
			'inheritedGalleryCount'   => 0,
			'galleryEnrichment'       => $include_gallery ? 'enabled' : 'disabled',
			'source'                  => 'none',
		];

		if ( $parent_id <= 0 ) {
			return [];
		}

		$raw_vars = self::fetch_direct_children( $parent_id, $parent_wc );
		if ( is_wp_error( $raw_vars ) ) {
			self::$last_diagnostics['source'] = 'read_error';
			self::$last_diagnostics['errorCode'] = $raw_vars->get_error_code();
			return $raw_vars;
		}
		self::$last_diagnostics['directChildCount'] = count( $raw_vars );
		if ( ! empty( $raw_vars ) ) {
			self::$last_diagnostics['source'] = 'direct_children';
		}

		$variations = [];
		foreach ( $raw_vars as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$raw['type'] = 'variation';
			$dto          = dtb_catalog_normalize_product( $raw, $parent_wc );
			$variations[] = $include_gallery
				? self::enrich_variation_gallery(
					$dto,
					(string) ( $parent_wc['sku'] ?? '' )
				)
				: $dto;
		}

		usort( $variations, static function ( array $a, array $b ): int {
			$rank_a = $a['variation']['sort'] ?? 0;
			$rank_b = $b['variation']['sort'] ?? 0;
			if ( $rank_a !== $rank_b ) {
				return $rank_a <=> $rank_b;
			}
			return ( $a['id'] ?? 0 ) <=> ( $b['id'] ?? 0 );
		} );

		self::$last_diagnostics['normalizedCount'] = count( $variations );
		return $variations;
	}

	/**
	 * Normalize one variation by its authoritative WooCommerce variation ID.
	 *
	 * This is the bounded cross-module read used when another DTB domain already
	 * owns an exact variation relationship and needs the same effective media,
	 * price, and inventory projection as the PDP.
	 */
	public static function get_normalized_by_id(
		int $variation_id,
		bool $include_gallery = true,
		?array $parent_wc_override = null
	): ?array {
		static $parent_cache = [];

		$product = wc_get_product( $variation_id );
		if ( ! $product instanceof WC_Product_Variation ) {
			return null;
		}

		$parent_id = (int) $product->get_parent_id();
		if ( is_array( $parent_wc_override ) ) {
			if ( $parent_id !== absint( $parent_wc_override['id'] ?? 0 ) ) {
				return null;
			}
			$parent_wc = $parent_wc_override;
		} else {
			if ( ! array_key_exists( $parent_id, $parent_cache ) ) {
				$parent_cache[ $parent_id ] = dtb_catalog_wc_fetch_product_by_id( $parent_id );
			}
			$parent_wc = $parent_cache[ $parent_id ];
		}
		if ( ! is_array( $parent_wc ) ) {
			return null;
		}

		$raw = self::wc_product_to_rest_array( $product, $parent_wc );
		$dto = dtb_catalog_normalize_product( $raw, $parent_wc );

		return $include_gallery
			? self::enrich_variation_gallery( $dto, (string) ( $parent_wc['sku'] ?? '' ) )
			: $dto;
	}

	/**
	 * Add the full SKU-specific variation gallery when the catalog image manifest
	 * knows about more images than WooCommerce persists on variation products.
	 * Variations explicitly configured to inherit always resolve the parent's
	 * ordered manifest instead of retaining stale child attachment state.
	 *
	 * @param array<string,mixed> $variation
	 * @param string              $parent_sku
	 * @return array<string,mixed>
	 */
	private static function enrich_variation_gallery( array $variation, string $parent_sku ): array {
		$inherits_parent = true === ( $variation['variation']['inheritParentImage'] ?? false );
		$gallery_sku     = $inherits_parent ? $parent_sku : (string) ( $variation['sku'] ?? '' );
		$gallery         = self::get_variation_gallery_images( $gallery_sku );

		if ( empty( $gallery ) ) {
			return $variation;
		}

		$diagnostic_key = $inherits_parent ? 'inheritedGalleryCount' : 'explicitGalleryCount';
		self::$last_diagnostics[ $diagnostic_key ] = (int) ( self::$last_diagnostics[ $diagnostic_key ] ?? 0 ) + 1;

		$media = is_array( $variation['media'] ?? null ) ? $variation['media'] : [];
		$media['variationImages'] = $gallery;

		if ( $inherits_parent || count( $gallery ) > count( (array) ( $media['images'] ?? [] ) ) ) {
			$media['images'] = $gallery;
			$media['image']  = (string) ( $gallery[0]['src'] ?? $media['image'] ?? '' );
		}

		$variation['media']                  = $media;
		$variation['variationGalleryImages'] = $gallery;

		return $variation;
	}

	/**
	 * Resolve ordered image URLs from the exact catalog CSV image list for a SKU.
	 *
	 * @param string $sku
	 * @return array<int,array{src:string}>
	 */
	private static function get_variation_gallery_images( string $sku ): array {
		static $disk_indexes = [];

		$sku_key = strtolower( trim( $sku ) );
		if ( '' === $sku_key ) {
			return [];
		}

		if (
			! function_exists( 'dtb_get_catalog_image_filenames_by_sku' )
			|| ! function_exists( 'dtb_get_image_file_index' )
			|| ! function_exists( 'dtb_image_sync_resolve_upload_directory' )
		) {
			return [];
		}

		$csv_sku_files = dtb_get_catalog_image_filenames_by_sku();
		$basenames     = $csv_sku_files[ $sku_key ] ?? [];
		if ( ! is_array( $basenames ) || empty( $basenames ) ) {
			return [];
		}

		$relative_path = defined( 'DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH' )
			? (string) DTB_IMAGE_SYNC_DEFAULT_UPLOAD_RELATIVE_PATH
			: '2026/media';
		$upload        = dtb_image_sync_resolve_upload_directory( $relative_path );
		$scan_dir      = (string) ( $upload['basedir'] ?? '' );
		$scan_url      = (string) ( $upload['baseurl'] ?? '' );

		if ( '' === $scan_dir || ! is_dir( $scan_dir ) ) {
			return [];
		}

		$cache_key = $scan_dir . '|' . $scan_url;
		if ( ! isset( $disk_indexes[ $cache_key ] ) ) {
			$extensions = [ 'webp', 'jpg', 'jpeg', 'png', 'avif', 'gif' ];
			$disk_index = [];
			foreach ( dtb_get_image_file_index( $scan_dir, $scan_url, $extensions ) as $file ) {
				if ( empty( $file['filename'] ) || empty( $file['url'] ) ) {
					continue;
				}
				$disk_index[ strtolower( (string) $file['filename'] ) ] = (string) $file['url'];
			}
			$disk_indexes[ $cache_key ] = $disk_index;
		}
		$disk_index = $disk_indexes[ $cache_key ];

		$gallery = [];
		$seen    = [];
		foreach ( $basenames as $basename ) {
			$key = strtolower( basename( (string) $basename ) );
			if ( '' === $key || empty( $disk_index[ $key ] ) ) {
				continue;
			}
			$url     = $disk_index[ $key ];
			$url_key = strtolower( rtrim( strtok( $url, '?' ) ?: $url, '/' ) );
			if ( isset( $seen[ $url_key ] ) ) {
				continue;
			}
			$seen[ $url_key ] = true;
			$gallery[]        = [ 'src' => $url ];
		}

		return $gallery;
	}

	/**
	 * Return the bounded variation projection used by configuration selectors.
	 *
	 * This intentionally skips catalog-media manifest/disk enrichment. Selectors
	 * only need authoritative identity, attributes, commerce state, and the
	 * already-persisted primary image. PDP gallery enrichment remains available
	 * through get_normalized() with its default include_gallery=true behavior.
	 *
	 * @param int                 $parent_id WC variable product ID.
	 * @param array<string,mixed> $parent_wc Raw parent product payload.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_selector_options( int $parent_id, array $parent_wc ): array|WP_Error {
		$variations = self::get_normalized( $parent_id, $parent_wc, false );
		if ( is_wp_error( $variations ) ) {
			return $variations;
		}

		return array_values( array_map(
			static function ( array $variation ): array {
				$media = is_array( $variation['media'] ?? null ) ? $variation['media'] : [];

				return [
					'id'        => absint( $variation['id'] ?? 0 ),
					'parentId'  => absint( $variation['parentId'] ?? 0 ) ?: null,
					'sku'       => (string) ( $variation['sku'] ?? '' ),
					'name'      => (string) ( $variation['name'] ?? '' ),
					'price'     => $variation['price'] ?? [
						'value'   => null,
						'regular' => null,
						'sale'    => null,
						'onSale'  => false,
					],
					'inventory' => $variation['inventory'] ?? [
						'stockStatus'   => 'instock',
						'stockQuantity' => null,
						'purchasable'   => true,
					],
					'media'     => [
						'image' => (string) ( $media['image'] ?? '' ),
					],
					'variation' => $variation['variation'] ?? [
						'axis'               => '',
						'value'              => '',
						'label'              => '',
						'sort'               => 0,
						'inheritParentImage' => false,
					],
				];
			},
			$variations
		) );
	}

	/** Return diagnostics for the last variation read. */
	public static function get_last_diagnostics(): array {
		return self::$last_diagnostics;
	}

	/**
	 * Fetch exact child IDs from the WooCommerce parent-child relationship in one bounded CRUD read.
	 *
	 * @param int   $parent_id
	 * @param array $parent_wc
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function fetch_direct_children( int $parent_id, array $parent_wc ): array|WP_Error {
		if ( 'variable' !== (string) ( $parent_wc['type'] ?? '' ) ) {
			return [];
		}

		if ( ! isset( $parent_wc['variations'] ) || ! is_array( $parent_wc['variations'] ) ) {
			return new WP_Error(
				'catalog_variations_unavailable',
				'Product variations are temporarily unavailable.',
				[ 'status' => 503 ]
			);
		}

		$children = array_values( array_unique( array_filter( array_map( 'absint', $parent_wc['variations'] ) ) ) );
		if ( empty( $children ) ) {
			return [];
		}

		global $wpdb;

		try {
			$products = wc_get_products( [
				'include' => $children,
				'parent'  => $parent_id,
				'type'    => 'variation',
				'limit'   => count( $children ),
				'return'  => 'objects',
			] );
		} catch ( Throwable $error ) {
			return new WP_Error(
				'catalog_variations_unavailable',
				'Product variations are temporarily unavailable.',
				[ 'status' => 503 ]
			);
		}

		if (
			is_wp_error( $products )
			|| ! is_array( $products )
			|| '' !== (string) ( $wpdb->last_error ?? '' )
		) {
			return new WP_Error(
				'catalog_variations_unavailable',
				'Product variations are temporarily unavailable.',
				[ 'status' => 503 ]
			);
		}

		$by_id = [];
		foreach ( $products as $product ) {
			if ( $product instanceof WC_Product_Variation && $parent_id === absint( $product->get_parent_id() ) ) {
				$by_id[ absint( $product->get_id() ) ] = $product;
			}
		}

		$raw = [];
		foreach ( $children as $child_id ) {
			if ( ! isset( $by_id[ $child_id ] ) ) {
				continue;
			}
			$raw[] = self::wc_product_to_rest_array( $by_id[ $child_id ], $parent_wc );
		}

		return $raw;
	}

	/**
	 * Convert a WC_Product object into the subset of REST-shaped fields the DTB
	 * normalizer expects.
	 *
	 * @param WC_Product          $product
	 * @param array<string,mixed> $parent_wc
	 * @return array<string,mixed>
	 */
	private static function wc_product_to_rest_array( WC_Product $product, array $parent_wc ): array {
		$images = [];
		$image_ids = array_filter( array_merge(
			[ $product->get_image_id() ],
			$product->get_gallery_image_ids()
		) );
		foreach ( $image_ids as $image_id ) {
			$src = wp_get_attachment_url( absint( $image_id ) );
			if ( $src ) {
				$images[] = [ 'id' => absint( $image_id ), 'src' => $src ];
			}
		}

		$attributes = [];
		if ( $product instanceof WC_Product_Variation ) {
			foreach ( $product->get_variation_attributes() as $name => $value ) {
				$clean_name = preg_replace( '/^attribute_/', '', (string) $name );

				// Resolve the attribute key to its display label (e.g. "pa_size-model"
				// → "Size / Model") so the frontend can match it against the product's
				// variation_attributes axis which uses the display label.
				$display_name = wc_attribute_label( $clean_name );
				if ( '' === $display_name ) {
					$display_name = $clean_name;
				}

				// Resolve slug → display label for taxonomy-backed attributes
				// (e.g. "3-5-easyroll-adjustable" → "3.5\" EasyRoll® Adjustable").
				$display_value = (string) $value;
				if ( '' !== $value && taxonomy_exists( $clean_name ) ) {
					$term = get_term_by( 'slug', $value, $clean_name );
					if ( $term instanceof WP_Term ) {
						$display_value = $term->name;
					}
				}

				$attributes[] = [
					'name'   => $display_name,
					'option' => $display_value,
				];
			}
		} else {
			foreach ( $product->get_attributes() as $name => $value ) {
				$attributes[] = [
					'name'    => (string) $name,
					'options' => is_array( $value ) ? array_values( $value ) : [ (string) $value ],
				];
			}
		}

		$meta_data = [];
		foreach ( $product->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$meta_data[] = [
				'id'    => $data['id'] ?? 0,
				'key'   => (string) ( $data['key'] ?? '' ),
				'value' => $data['value'] ?? '',
			];
		}

		return [
			'id'                => $product->get_id(),
			'parent_id'         => $product instanceof WC_Product_Variation
				? $product->get_parent_id()
				: absint( $parent_wc['id'] ?? 0 ),
			'sku'               => $product->get_sku(),
			'slug'              => $product->get_slug(),
			'name'              => $product->get_name(),
			'type'              => 'variation',
			'status'            => $product->get_status(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'on_sale'           => $product->is_on_sale(),
			'purchasable'       => $product->is_purchasable(),
			'stock_status'      => $product->get_stock_status(),
			'manage_stock'      => $product->managing_stock(),
			'stock_quantity'    => $product->get_stock_quantity(),
			'images'            => $images,
			'attributes'        => $attributes,
			'meta_data'         => $meta_data,
			'description'       => $product->get_description(),
			'short_description' => $product->get_short_description(),
			'backorders_allowed'=> $product->backorders_allowed(),
			'backordered'       => $product->is_on_backorder(),
		];
	}
}
