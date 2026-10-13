<?php
/**
 * DTB_CatalogProductNormalizer
 *
 * Converts raw WooCommerce REST API product arrays into the canonical DTB
 * catalog product DTO.  This is the single authoritative normalizer for the
 * backend; all catalog read-model endpoints use it.
 *
 * DTB Catalog Product shape:
 *   id, type, productKind, sku, mpn, slug, name, description, shortDescription
 *   brand    { key, label, slug }
 *   category { key, label, slug }
 *   displayCategory { key, label, slug }
 *   toolFamily, toolRole, isParts, isVariable, isRepairable
 *   parentId, parentSku, defaultVariationId
 *   cardProduct { id, parentId, sku, name, price, image, stockStatus, variationLabel, addToCartType }
 *   price    { value, regular, sale, onSale }
 *   inventory { stockStatus, stockQuantity }
 *   media    { image, images }
 *   builder  { eligible, slots, workflowScopes, rank, isRequiredAccessory, isKitIncluded }
 *   compatibility { compatibleToolSkus, replacementPartFor }
 *   schematics    { brand, group, position }
 *   variation     { axis, value, label, sort, inheritParentImage }
 *   attributes, metaData
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogProductNormalizer {

	/**
	 * Normalize a raw WC product or variation array into the DTB DTO.
	 *
	 * @param  array       $wc          Raw WC REST API product array.
	 * @param  array|null  $parent_wc   Parent WC product array (for variations).
	 * @return array
	 */
	public static function normalize( array $wc, ?array $parent_wc = null ): array {
		$meta       = self::extract_meta_lookup( $wc['meta_data'] ?? [] );
		$type       = strtolower( $wc['type'] ?? 'simple' );
		$is_var     = 'variation' === $type;
		$wc_cats    = $is_var && $parent_wc ? ( $parent_wc['categories'] ?? [] ) : ( $wc['categories'] ?? [] );

		$brand       = self::extract_brand( $wc, $meta, $parent_wc );
		$category    = self::extract_category( $wc_cats, $meta );
		$display_cat = self::extract_display_category( $meta, $category );
		$is_parts    = self::extract_is_parts( $meta, $wc_cats );
		if ( $is_parts ) {
			$display_cat = [ 'key' => 'parts', 'label' => 'Parts', 'slug' => 'parts' ];
		}
		$builder     = self::extract_builder( $meta );
		$tool_family = DTB_ToolFamilyResolver::resolve(
			(string) ( $meta[ DTB_ProductMeta::TOOL_FAMILY ] ?? '' ),
			$builder['slots'],
			$category['key'],
			$display_cat['key'],
			(string) ( $wc['name'] ?? '' ),
			$is_parts
		);

		$price     = self::extract_price( $wc );
		$inventory = self::extract_inventory( $wc );
		$media     = self::extract_media( $wc, $is_var, $parent_wc );
		$variation = self::extract_variation_meta( $meta );

		$parent_id  = absint( $wc['parent_id'] ?? 0 );
		$product_id = absint( $wc['id'] ?? 0 );

		$product_kind = self::extract_product_kind( $meta, $is_parts, $type );

		$dto = [
			'id'               => $product_id,
			'type'             => $type,
			'productKind'      => $product_kind,
			'sku'              => (string) ( $wc['sku'] ?? '' ),
			'mpn'              => (string) ( $meta[ DTB_ProductMeta::MPN ] ?? $meta[ DTB_ProductMeta::MANUFACTURER_SKU ] ?? '' ),
			'upc'              => (string) ( $meta[ DTB_ProductMeta::UPC ] ?? '' ),
			'slug'             => (string) ( $wc['slug'] ?? '' ),
			'name'             => (string) ( $wc['name'] ?? '' ),
			'description'      => (string) ( $wc['description'] ?? '' ),
			'shortDescription' => (string) ( $wc['short_description'] ?? '' ),
			'brand'            => $brand,
			'category'         => $category,
			'displayCategory'  => $display_cat,
			'toolFamily'       => $tool_family,
			'toolRole'         => (string) ( $meta[ DTB_ProductMeta::TOOL_ROLE ] ?? '' ),
			'isParts'          => $is_parts,
			'isVariable'       => 'variable' === $type,
			'isRepairable'     => (bool) ( $meta[ DTB_ProductMeta::IS_REPAIRABLE ] ?? false ),
			'parentId'         => $parent_id ?: null,
			'parentSku'        => (string) ( $meta[ DTB_ProductMeta::PARENT_PRODUCT_SKU ] ?? '' ),
			'defaultVariationId'  => absint( $meta[ DTB_ProductMeta::DEFAULT_VARIATION_ID ] ?? 0 ) ?: null,
			'defaultVariationSku' => (string) ( $meta[ DTB_ProductMeta::DEFAULT_VARIATION_SKU ] ?? '' ),
			'commerceMode'        => (string) ( $meta[ DTB_ProductMeta::COMMERCE_MODE ] ?? '' ),
			'price'            => $price,
			'inventory'        => $inventory,
			'media'            => $media,
			'builder'          => $builder,
			'compatibility'    => [
				'compatibleToolSkus'  => self::decode_csv_or_array( $meta[ DTB_ProductMeta::COMPATIBLE_TOOL_SKUS ] ?? '' ),
				'replacementPartFor'  => self::decode_csv_or_array( $meta[ DTB_ProductMeta::REPLACEMENT_PART_FOR ] ?? '' ),
			],
			'schematics'       => [
				'brand'    => (string) ( $meta[ DTB_ProductMeta::SCHEMATIC_BRAND ] ?? '' ),
				'group'    => (string) ( $meta[ DTB_ProductMeta::SCHEMATIC_GROUP ] ?? '' ),
				'position' => absint( $meta[ DTB_ProductMeta::SCHEMATIC_POSITION ] ?? 0 ) ?: null,
			],
			'variation'        => $variation,
			'attributes'       => $wc['attributes'] ?? [],
			'content'          => self::extract_structured_content( $meta ),
			'metaData'         => self::extract_specs_meta_data( $wc['meta_data'] ?? [] ),
		];

		// Attach the card product DTO (self or resolved default variation placeholder).
		$dto['cardProduct'] = self::build_card_product( $dto );

		return $dto;
	}

	// ── Private helpers ────────────────────────────────────────────────────────

	/** Build a flat key → value lookup from WC meta_data array. */
	private static function extract_meta_lookup( array $meta_data ): array {
		$map = [];
		foreach ( $meta_data as $entry ) {
			if ( isset( $entry['key'] ) ) {
				$map[ $entry['key'] ] = $entry['value'];
			}
		}
		return $map;
	}

	/**
	 * Extract only specification-related metadata for frontend product detail.
	 *
	 * Keeps payloads lean while preserving compatibility with both the new
	 * canonical specs JSON workflow and legacy _specs/_includes keys.
	 *
	 * @param  array<int,array<string,mixed>> $meta_data
	 * @return array<int,array<string,mixed>>
	 */
	private static function extract_specs_meta_data( array $meta_data ): array {
		$filtered = [];

		foreach ( $meta_data as $entry ) {
			$key = isset( $entry['key'] ) ? (string) $entry['key'] : '';
			if ( '' === $key ) {
				continue;
			}

			$include = '_dtb_specs_json' === $key
				|| preg_match( '/^_specs_\d+_(label|value)$/', $key )
				|| preg_match( '/^_includes_\d+_(name|sku)$/', $key );

			if ( ! $include ) {
				continue;
			}

			$filtered[] = [
				'key'   => $key,
				'value' => $entry['value'] ?? '',
			];
		}

		return $filtered;
	}

	/**
	 * Expose only validated Features JSON. Invalid/unmigrated records are
	 * represented as unavailable so legacy description rendering stays intact.
	 */
	private static function extract_structured_content( array $meta ): array {
		$raw = $meta[ DTB_ProductMeta::FEATURES_JSON ] ?? '';
		$version = (string) ( $meta[ DTB_ProductMeta::CONTENT_SCHEMA_VERSION ] ?? '' );
		$features = [];
		if ( '2' === $version && is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) && 1 === ( $decoded['schema_version'] ?? null ) && isset( $decoded['features'] ) && is_array( $decoded['features'] ) ) {
				$valid = true;
				if ( count( $decoded['features'] ) > 50 ) {
					$valid = false;
				}
				foreach ( $decoded['features'] as $feature ) {
					if ( ! is_array( $feature ) || ! isset( $feature['label'], $feature['description'] ) ||
						! is_string( $feature['label'] ) || ! is_string( $feature['description'] ) ||
						'' === trim( $feature['label'] ) || '' === trim( $feature['description'] ) ||
						strlen( $feature['label'] ) > 160 || strlen( $feature['description'] ) > 2000 ) {
						$valid = false;
						break;
					}
					$features[] = [
						'label'       => $feature['label'],
						'description' => $feature['description'],
					];
				}
				if ( $valid ) {
					return [ 'schemaVersion' => 2, 'features' => $features, 'structured' => true ];
				}
			}
		}
		return [ 'schemaVersion' => 0, 'features' => [], 'structured' => false ];
	}

	/** Extract brand identity, trying meta → parent meta → WC attribute → WC category. */
	private static function extract_brand( array $wc, array $meta, ?array $parent_wc ): array {
		$label = (string) ( $meta[ DTB_ProductMeta::BRAND_LABEL ] ?? '' );
		$key   = (string) ( $meta[ DTB_ProductMeta::BRAND_KEY ]   ?? '' );

		// Fall back to parent meta for variations.
		if ( '' === $label && $parent_wc ) {
			$parent_meta = self::extract_meta_lookup( $parent_wc['meta_data'] ?? [] );
			$label = (string) ( $parent_meta[ DTB_ProductMeta::BRAND_LABEL ] ?? '' );
			$key   = (string) ( $parent_meta[ DTB_ProductMeta::BRAND_KEY ]   ?? '' );
		}

		if ( '' === $label ) {
			// Try WC product 'brands' field (WooCommerce Brands plugin).
			$brands_field = $wc['brands'] ?? [];
			if ( is_array( $brands_field ) && ! empty( $brands_field ) ) {
				$label = (string) ( $brands_field[0]['name'] ?? '' );
			}
		}

		if ( '' === $label ) {
			// Try WC attribute named "Brand" or "pa_brand".
			foreach ( $wc['attributes'] ?? [] as $attr ) {
				$name = strtolower( (string) ( $attr['name'] ?? '' ) );
				if ( 'brand' === $name || 'pa_brand' === $name ) {
					$options = $attr['options'] ?? [];
					if ( ! empty( $options ) ) {
						$label = is_array( $options ) ? (string) $options[0] : (string) $options;
						break;
					}
				}
			}
		}

		if ( '' !== $label ) {
			$normalized = DTB_BrandNormalizer::normalize( $label );
			// Prefer existing key over derived one.
			if ( '' !== $key ) {
				$normalized['key']  = $key;
				$normalized['slug'] = $key;
			}
			return $normalized;
		}

		return [ 'key' => $key, 'label' => '', 'slug' => $key ];
	}

	/** Extract category identity. */
	private static function extract_category( array $wc_categories, array $meta ): array {
		$explicit_key = (string) ( $meta[ DTB_ProductMeta::CATEGORY_KEY ] ?? '' );
		return DTB_CategoryNormalizer::resolve( $wc_categories, $explicit_key );
	}

	/** Extract display category identity. */
	private static function extract_display_category( array $meta, array $category ): array {
		$raw_key = (string) ( $meta[ DTB_ProductMeta::DISPLAY_CATEGORY_KEY ] ?? '' );
		if ( '' === $raw_key ) {
			return [ 'key' => '', 'label' => '', 'slug' => '' ];
		}

		// Normalize the raw meta value to a canonical slug so the facets API
		// always returns consistent keys regardless of how the product was imported.
		$canonical = DTB_CategoryNormalizer::canonical_display_slug( $raw_key );
		$label     = DTB_CategoryNormalizer::DISPLAY_CATEGORY_LABELS[ $canonical ]
			?? ucwords( str_replace( '_', ' ', $canonical ) );

		return [
			'key'   => $canonical,
			'label' => $label,
			'slug'  => str_replace( '_', '-', $canonical ),
		];
	}

	/** Determine whether this product is a replacement part. */
	private static function extract_is_parts( array $meta, array $wc_categories ): bool {
		$explicit = $meta[ DTB_ProductMeta::IS_PARTS ] ?? null;
		if ( null !== $explicit ) {
			return (bool) $explicit;
		}
		foreach ( $wc_categories as $cat ) {
			$name = strtolower( (string) ( $cat['name'] ?? '' ) );
			if ( preg_match( '/parts|repair|replacement/i', $name ) ) {
				return true;
			}
		}
		return false;
	}

	/** Extract builder/toolset metadata. */
	private static function extract_builder( array $meta ): array {
		$slots  = self::decode_csv_or_array( $meta[ DTB_ProductMeta::BUILDER_SLOTS ] ?? '' );
		$scopes = self::decode_csv_or_array( $meta[ DTB_ProductMeta::WORKFLOW_SCOPES ] ?? '' );

		$eligible_raw = $meta[ DTB_ProductMeta::BUILDER_ELIGIBLE ] ?? null;
		$eligible     = null !== $eligible_raw ? (bool) $eligible_raw : ! empty( $slots );

		return [
			'eligible'           => $eligible,
			'slots'              => $slots,
			'workflowScopes'     => $scopes,
			'rank'               => absint( $meta[ DTB_ProductMeta::BUILDER_RANK ] ?? 0 ),
			'isRequiredAccessory'=> (bool) ( $meta[ DTB_ProductMeta::BUILDER_REQUIRED_ACCESSORY ] ?? false ),
			'isKitIncluded'      => (bool) ( $meta[ DTB_ProductMeta::KIT_INCLUDED_ITEM ] ?? false ),
		];
	}

	/** Extract price data. */
	private static function extract_price( array $wc ): array {
		$price   = (float) ( $wc['price'] ?? 0 );
		$regular = '' !== ( $wc['regular_price'] ?? '' ) ? (float) $wc['regular_price'] : $price;
		$sale    = '' !== ( $wc['sale_price'] ?? '' )    ? (float) $wc['sale_price']    : null;
		return [
			'value'   => $price,
			'regular' => $regular ?: null,
			'sale'    => $sale,
			'onSale'  => (bool) ( $wc['on_sale'] ?? false ),
		];
	}

	/** Extract inventory data. */
	private static function extract_inventory( array $wc ): array {
		return [
			'stockStatus'   => (string) ( $wc['stock_status'] ?? 'instock' ),
			'stockQuantity' => isset( $wc['stock_quantity'] ) && null !== $wc['stock_quantity']
				? absint( $wc['stock_quantity'] )
				: null,
			'purchasable' => (bool) ( $wc['purchasable'] ?? true ),
		];
	}

	/** Extract media (image URL + images array). */
	private static function extract_media( array $wc, bool $is_var, ?array $parent_wc ): array {
		$images    = $wc['images'] ?? [];
		$first_url = '';
		$all_urls  = [];
		$seen      = [];

		$push_src = static function ( string $src ) use ( &$first_url, &$all_urls, &$seen ): void {
			$src = trim( $src );
			if ( '' === $src ) {
				return;
			}

			$key = strtolower( rtrim( strtok( $src, '?' ) ?: $src, '/' ) );
			if ( isset( $seen[ $key ] ) ) {
				return;
			}

			$seen[ $key ] = true;
			if ( '' === $first_url ) {
				$first_url = $src;
			}
			$all_urls[] = $src;
		};

		if ( isset( $wc['image'] ) ) {
			if ( is_array( $wc['image'] ) ) {
				$push_src( (string) ( $wc['image']['src'] ?? '' ) );
			} elseif ( is_string( $wc['image'] ) ) {
				$push_src( $wc['image'] );
			}
		}

		foreach ( $images as $img ) {
			if ( is_array( $img ) ) {
				$push_src( (string) ( $img['src'] ?? '' ) );
			} elseif ( is_string( $img ) ) {
				$push_src( $img );
			}
		}

		// Variation with no image inherits parent.
		if ( $is_var && '' === $first_url && $parent_wc ) {
			return self::extract_media( $parent_wc, false, null );
		}

		return [ 'image' => $first_url, 'images' => $all_urls ];
	}

	/** Extract variation-specific metadata. */
	private static function extract_variation_meta( array $meta ): array {
		$inherit_parent_image = $meta[ DTB_ProductMeta::INHERIT_PARENT_IMAGE ] ?? false;
		if ( is_string( $inherit_parent_image ) ) {
			$inherit_parent_image = in_array(
				strtolower( trim( $inherit_parent_image ) ),
				[ '1', 'true', 'yes', 'on' ],
				true
			);
		} else {
			$inherit_parent_image = (bool) $inherit_parent_image;
		}

		return [
			'axis'               => (string) ( $meta[ DTB_ProductMeta::VARIATION_AXIS ] ?? '' ),
			'value'              => (string) ( $meta[ DTB_ProductMeta::VARIATION_VALUE ] ?? '' ),
			'label'              => (string) ( $meta[ DTB_ProductMeta::VARIATION_LABEL ] ?? '' ),
			'sort'               => absint( $meta[ DTB_ProductMeta::VARIATION_SORT ] ?? 0 ),
			'inheritParentImage' => $inherit_parent_image,
		];
	}

	/**
	 * Determine product kind from meta and context.
	 *
	 * @param  array  $meta
	 * @param  bool   $is_parts
	 * @param  string $type
	 * @return string
	 */
	private static function extract_product_kind( array $meta, bool $is_parts, string $type ): string {
		$explicit = (string) ( $meta[ DTB_ProductMeta::PRODUCT_KIND ] ?? '' );
		if ( '' !== $explicit ) {
			return $explicit;
		}
		if ( $is_parts ) {
			return 'part';
		}
		if ( 'variation' === $type || 'variable' === $type ) {
			return 'tool';
		}
		return 'tool';
	}

	/**
	 * Build the cardProduct DTO used by product cards.
	 *
	 * For simple products: card = product itself.
	 * For variable parents: card = stub pointing to defaultVariationId (variations
	 *   must be resolved separately by VariationReadModelService).
	 * For variation: card = variation.
	 *
	 * @param  array $dto  Partially-built catalog product DTO.
	 * @return array
	 */
	private static function build_card_product( array $dto ): array {
		$add_to_cart_type = 'variable' === $dto['type'] ? 'variation' : 'simple';

		return [
			'id'             => $dto['defaultVariationId'] ?? $dto['id'],
			'parentId'       => 'variable' === $dto['type'] ? $dto['id'] : null,
			'sku'            => $dto['sku'],
			'name'           => $dto['name'],
			'price'          => $dto['price']['value'],
			'image'          => $dto['media']['image'],
			'stockStatus'    => $dto['inventory']['stockStatus'],
			'variationLabel' => $dto['variation']['label'],
			'addToCartType'  => $add_to_cart_type,
		];
	}

	/**
	 * Decode a value that may be a comma-separated string, a JSON array string,
	 * or already a PHP array.  Returns a clean string[].
	 *
	 * @param  mixed $value
	 * @return string[]
	 */
	public static function decode_csv_or_array( mixed $value ): array {
		if ( is_array( $value ) ) {
			return array_values( array_filter( array_map( 'strval', $value ) ) );
		}
		if ( ! is_string( $value ) || '' === $value ) {
			return [];
		}
		// Try JSON first.
		$decoded = json_decode( $value, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'strval', $decoded ) ) );
		}
		// Fall back to comma-separated.
		return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
	}
}
