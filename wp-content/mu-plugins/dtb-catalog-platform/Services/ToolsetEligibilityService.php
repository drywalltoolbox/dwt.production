<?php
/**
 * DTB_ToolsetEligibilityService
 *
 * Finds products eligible for a given Toolset Builder template and slot.
 * Queries WooCommerce products using the _dtb_builder_slots meta field
 * rather than keyword-matching product names.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetEligibilityService {

	/** Cache TTL for slot options in seconds. */
	const CACHE_TTL = 300; // 5 minutes

	/** WP option key for the slot options cache generation counter. */
	const CACHE_VERSION_OPTION = 'dtb_slot_opts_cache_version';

	/**
	 * Return eligible products for every slot in a template, keyed by slot ID.
	 *
	 * @param  array $template  DTB_ToolsetData template array.
	 * @return array<string, array[]>  slotId → [ catalog product DTO[] ]
	 */
	public static function get_options_for_template( array $template ): array {
		$brand_key = $template['brandKey'] ?? '';
		$result    = [];

		foreach ( $template['slots'] ?? [] as $slot ) {
			$slot_id = $slot['id'] ?? '';
			if ( '' === $slot_id ) {
				continue;
			}
			$result[ $slot_id ] = self::get_slot_options( $slot_id, $brand_key );
		}

		return $result;
	}

	/**
	 * Return eligible products for one slot of a specific brand.
	 *
	 * Cache key includes the current cache generation so that bumping the
	 * version via invalidate_slot_options_cache() effectively discards all
	 * existing transients without needing to enumerate them individually.
	 *
	 * @param  string $slot_id   Toolset Builder slot ID (e.g. 'flatBox').
	 * @param  string $brand_key DTB brand slug key (e.g. 'tapetech').
	 * @return array[]           Array of ToolsetOption DTOs.
	 */
	public static function get_slot_options( string $slot_id, string $brand_key ): array {
		$version   = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		$cache_key = 'dtb_slot_v' . $version . '_' . md5( $slot_id . '|' . $brand_key );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$options = self::query_slot_options( $slot_id, $brand_key );
		set_transient( $cache_key, $options, self::CACHE_TTL );
		return $options;
	}

	/**
	 * Invalidate all slot option caches by bumping the cache version number.
	 * Called whenever any product is saved, updated, deleted, or imported.
	 */
	public static function invalidate_slot_options_cache(): void {
		$version = (int) get_option( self::CACHE_VERSION_OPTION, 1 );
		update_option( self::CACHE_VERSION_OPTION, $version + 1, false );
	}

	// ── Private ────────────────────────────────────────────────────────────────

	private static function query_slot_options( string $slot_id, string $brand_key ): array {
		// Query WC products where _dtb_builder_slots contains the slot ID
		// and _dtb_brand_key matches the template brand.
		$wc_params = [
			'status'   => 'publish',
			'per_page' => 100,
			'meta_query' => [
				[
					'key'     => DTB_ProductMeta::BUILDER_SLOTS,
					'value'   => $slot_id,
					'compare' => 'LIKE',
				],
			],
		];

		// Note: dtb_cached_wc_get proxies the WC REST API externally and does
		// not support meta_query params directly.  Use get_posts() instead for
		// internal queries that need meta filtering.
		$products = self::query_by_meta( $slot_id, $brand_key );

		if ( empty( $products ) ) {
			return [];
		}

		$options = [];
		foreach ( $products as $post ) {
			$wc_product = wc_get_product( $post->ID );
			if ( ! $wc_product || ! $wc_product->is_purchasable() ) {
				continue;
			}

			// build_toolset_options always returns an array of 0..N option DTOs.
			// Variable products produce one entry per eligible variation; simple
			// products produce one entry.  Merge all into the flat $options array.
			$product_options = self::build_toolset_options( $wc_product, $slot_id );
			foreach ( $product_options as $opt ) {
				$options[] = $opt;
			}
		}

		// Sort by builder rank, then by product name.
		usort( $options, static function ( array $a, array $b ): int {
			$rank_a = $a['builderRank'] ?? 0;
			$rank_b = $b['builderRank'] ?? 0;
			if ( $rank_a !== $rank_b ) {
				return $rank_a <=> $rank_b;
			}
			return strcmp( $a['name'] ?? '', $b['name'] ?? '' );
		} );

		return $options;	}

	/**
	 * Query published products with a matching builder slot and brand.
	 * Uses WP_Query with meta_query for internal DB access.
	 *
	 * @param  string $slot_id
	 * @param  string $brand_key
	 * @return WP_Post[]
	 */
	private static function query_by_meta( string $slot_id, string $brand_key ): array {
		$meta_query = [
			'relation' => 'AND',
			[
				'key'     => DTB_ProductMeta::BUILDER_SLOTS,
				'value'   => $slot_id,
				'compare' => 'LIKE',
			],
		];

		if ( '' !== $brand_key ) {
			$meta_query[] = [
				'key'     => DTB_ProductMeta::BRAND_KEY,
				'value'   => $brand_key,
				'compare' => '=',
			];
		}

		$query = new WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 100,
			'meta_query'     => $meta_query,
			'fields'         => 'all',
			'no_found_rows'  => true,
		] );

		return $query->posts;
	}

	/**
	 * Build a ToolsetOption DTO from a WC_Product object.
	 *
	 * For simple products, returns a single-item array containing the option.
	 * For variable products, returns one item per eligible variation.
	 * Returns an empty array when the product is not usable (null guard removed
	 * in favour of the empty-array contract so callers can always array_merge).
	 *
	 * @param  WC_Product $wc_product
	 * @param  string     $slot_id
	 * @return array[]    Zero or more option DTOs.
	 */
	private static function build_toolset_options( WC_Product $wc_product, string $slot_id ): array {
		$post_id     = $wc_product->get_id();
		$brand_key   = (string) get_post_meta( $post_id, DTB_ProductMeta::BRAND_KEY,   true );
		$brand_label = (string) get_post_meta( $post_id, DTB_ProductMeta::BRAND_LABEL, true );
		$tool_fam    = (string) get_post_meta( $post_id, DTB_ProductMeta::TOOL_FAMILY,  true );
		$rank        = (int)    get_post_meta( $post_id, DTB_ProductMeta::BUILDER_RANK, true );
		$slots_raw   = (string) get_post_meta( $post_id, DTB_ProductMeta::BUILDER_SLOTS, true );
		$slots       = DTB_CatalogProductNormalizer::decode_csv_or_array( $slots_raw );

		$thumb_id  = $wc_product->get_image_id();
		$image_url = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' ) : '';

		// Variable products produce one option per eligible purchasable variation.
		if ( $wc_product->is_type( 'variable' ) ) {
			return self::build_variable_options( $wc_product, $slot_id, $brand_key, $brand_label, $tool_fam, $rank, $slots, $image_url );
		}

		// Simple product — single option.
		$price = $wc_product->get_price();
		return [ [
			'productId'      => $post_id,
			'variationId'    => null,
			'sku'            => $wc_product->get_sku(),
			'parentSku'      => '',
			'name'           => $wc_product->get_name(),
			'variationLabel' => '',
			'price'          => $price ? (float) $price : null,
			'stockStatus'    => $wc_product->get_stock_status(),
			'image'          => $image_url,
			'brandKey'       => $brand_key,
			'brandLabel'     => $brand_label,
			'toolFamily'     => $tool_fam,
			'builderRank'    => $rank,
			'eligibleSlots'  => $slots,
		] ];
	}

	/**
	 * Build option DTOs from the eligible variations of a variable parent.
	 * Returns one ToolsetOption per purchasable variation.
	 */
	private static function build_variable_options(
		WC_Product $parent,
		string $slot_id,
		string $brand_key,
		string $brand_label,
		string $tool_family,
		int $rank,
		array $eligible_slots,
		string $parent_image
	): array {
		$parent_id     = $parent->get_id();
		$parent_sku    = $parent->get_sku();
		$variations    = $parent->get_available_variations( 'objects' );

		if ( empty( $variations ) ) {
			return [];
		}

		$options = [];
		foreach ( $variations as $var ) {
			if ( ! $var instanceof WC_Product_Variation ) {
				continue;
			}
			if ( ! $var->is_purchasable() ) {
				continue;
			}

			$var_id     = $var->get_id();
			$thumb_id   = $var->get_image_id();
			$image_url  = $thumb_id
				? (string) wp_get_attachment_image_url( $thumb_id, 'woocommerce_thumbnail' )
				: $parent_image;

			$sort_raw   = (int) get_post_meta( $var_id, DTB_ProductMeta::VARIATION_SORT, true );
			$var_label  = (string) get_post_meta( $var_id, DTB_ProductMeta::VARIATION_LABEL, true );
			if ( '' === $var_label ) {
				$attrs = $var->get_variation_attributes();
				$var_label = implode( ' / ', array_values( $attrs ) );
			}

			$options[] = [
				'productId'      => $parent_id,
				'variationId'    => $var_id,
				'sku'            => $var->get_sku() ?: $parent_sku,
				'parentSku'      => $parent_sku,
				'name'           => $parent->get_name(),
				'variationLabel' => $var_label,
				'price'          => $var->get_price() ? (float) $var->get_price() : null,
				'stockStatus'    => $var->get_stock_status(),
				'image'          => $image_url,
				'brandKey'       => $brand_key,
				'brandLabel'     => $brand_label,
				'toolFamily'     => $tool_family,
				'builderRank'    => $sort_raw ?: $rank,
				'eligibleSlots'  => $eligible_slots,
				'_sortKey'       => $sort_raw,
			];
		}

		if ( empty( $options ) ) {
			return [];
		}

		// Sort variations by sort key, then by SKU.
		usort( $options, static fn( $a, $b ) => ( $a['_sortKey'] <=> $b['_sortKey'] ) ?: strcmp( $a['sku'], $b['sku'] ) );

		// Remove internal sort key before returning.
		foreach ( $options as &$opt ) {
			unset( $opt['_sortKey'] );
		}
		unset( $opt );

		// Return one option DTO per variation — caller merges into flat list.
		return $options;
	}
}
