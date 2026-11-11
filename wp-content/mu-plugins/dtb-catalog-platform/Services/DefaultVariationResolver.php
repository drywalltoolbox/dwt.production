<?php
/**
 * DTB_DefaultVariationResolver
 *
 * Given a parent product DTO and its normalized variation DTOs, selects the
 * "best" default variation to display on product cards and in the product modal.
 *
 * Selection priority:
 *   1. Explicit _dtb_default_variation_id meta on the parent.
 *   2. Explicit _dtb_default_variation_sku meta on the parent (stable across CSV imports).
 *   3. First variation with an in-stock status that is also purchasable.
 *   4. First variation with in-stock status (regardless of purchasable flag).
 *   5. First variation in sort order (final fallback).
 *
 * The resolved variation is embedded in the parent's cardProduct DTO so the
 * frontend does not need to make a separate variation fetch for listing pages.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_DefaultVariationResolver {

	/**
	 * Resolve the default variation for a variable product.
	 *
	 * @param  array   $product_dto   Normalized parent DTB product array.
	 * @param  array[] $variations    Normalized DTB variation arrays (sorted).
	 * @return array|null             The chosen variation DTO, or null when empty.
	 */
	public static function resolve( array $product_dto, array $variations ): ?array {
		if ( empty( $variations ) ) {
			return null;
		}

		// 1. Explicit default variation ID.
		$explicit_id = $product_dto['defaultVariationId'];
		if ( $explicit_id ) {
			foreach ( $variations as $v ) {
				if ( $v['id'] === $explicit_id ) {
					return $v;
				}
			}
		}

		// 2. Explicit default variation SKU (stable across CSV imports; preferred when ID is stale).
		$explicit_sku = (string) ( $product_dto['defaultVariationSku'] ?? '' );
		if ( '' !== $explicit_sku ) {
			foreach ( $variations as $v ) {
				if ( (string) $v['sku'] === $explicit_sku ) {
					return $v;
				}
			}
		}

		// 3. First in-stock and purchasable.
		foreach ( $variations as $v ) {
			if (
				'outofstock' !== $v['inventory']['stockStatus'] &&
				true === $v['inventory']['purchasable']
			) {
				return $v;
			}
		}

		// 4. First in-stock (any purchasable state).
		foreach ( $variations as $v ) {
			if ( 'outofstock' !== $v['inventory']['stockStatus'] ) {
				return $v;
			}
		}

		// 5. First variation.
		return $variations[0];
	}

	/**
	 * Enrich a parent product DTO's cardProduct with data from its resolved
	 * default variation.
	 *
	 * @param  array      $product_dto       Parent DTB product DTO.
	 * @param  array|null $default_variation Resolved default variation DTO.
	 * @return array                         Updated parent DTO with cardProduct set.
	 */
	public static function apply_to_card( array $product_dto, ?array $default_variation ): array {
		if ( null === $default_variation ) {
			return $product_dto;
		}

		// The listing represents the resolved variation, so its complete price
		// context must replace the variable parent's range/default price. Keeping
		// only the effective value loses the compare-at price and makes genuine
		// variation sales indistinguishable from regular pricing to the storefront.
		$variation_price = is_array( $default_variation['price'] ?? null )
			? $default_variation['price']
			: [];
		$parent_price = $product_dto['price'] ?? [];
		$product_dto['price'] = [
			'value'   => $variation_price['value'] ?? $product_dto['price']['value'] ?? null,
			'regular' => $variation_price['regular'] ?? $product_dto['price']['regular'] ?? null,
			'sale'    => $variation_price['sale'] ?? $product_dto['price']['sale'] ?? null,
			'onSale'  => (bool) ( $variation_price['onSale'] ?? $product_dto['price']['onSale'] ?? false ),
			'min'     => $parent_price['min'] ?? null,
			'max'     => $parent_price['max'] ?? null,
		];

		$product_dto['defaultVariationId'] = $default_variation['id'];
		$product_dto['cardProduct'] = [
			'id'             => $default_variation['id'],
			'parentId'       => $product_dto['id'],
			'sku'            => $default_variation['sku'] ?: $product_dto['sku'],
			'name'           => $default_variation['name'] ?: $product_dto['name'],
			'price'          => $default_variation['price']['value'],
			'regularPrice'   => $variation_price['regular'] ?? null,
			'salePrice'      => $variation_price['sale'] ?? null,
			'onSale'         => (bool) ( $variation_price['onSale'] ?? false ),
			'image'          => $default_variation['media']['image'] ?: $product_dto['media']['image'],
			'stockStatus'    => $default_variation['inventory']['stockStatus'],
			'variationLabel' => $default_variation['variation']['label'] ?: self::build_label( $default_variation ),
			'addToCartType'  => 'variation',
		];

		return $product_dto;
	}

	/**
	 * Build a readable variation label from its attribute values when
	 * _dtb_variation_label is not set.
	 *
	 * @param  array $variation
	 * @return string
	 */
	private static function build_label( array $variation ): string {
		$attrs = $variation['attributes'] ?? [];
		if ( empty( $attrs ) ) {
			return '';
		}
		$parts = [];
		foreach ( $attrs as $attr ) {
			$val = $attr['option'] ?? '';
			if ( '' !== $val ) {
				$parts[] = $val;
			}
		}
		return implode( ' / ', $parts );
	}
}
