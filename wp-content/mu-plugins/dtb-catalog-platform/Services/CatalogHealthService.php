<?php
/**
 * Catalog Health service.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogHealthService {
	/**
	 * Inspect a variable product for catalog-health issues.
	 *
	 * @param WC_Product_Variable $product Variable product.
	 * @return array[]
	 */
	public static function inspect_variable_product( WC_Product_Variable $product ): array {
		$issues       = [];
		$product_id   = (int) $product->get_id();
		$product_name = $product->get_name();
		$parent_sku   = (string) $product->get_sku();
		$children     = $product->get_children();

		if ( empty( $children ) ) {
			return [
				DTB_CatalogHealthIssue::make(
					$product_id,
					$product_name,
					$parent_sku,
					DTB_CatalogHealthIssue::SEVERITY_ERROR,
					'variable_parent_no_children',
					'Variable product has no child variations.'
				),
			];
		}

		$has_purchasable_in_stock = false;
		$parent_attributes        = $product->get_variation_attributes();

		foreach ( $children as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( ! $variation instanceof WC_Product_Variation ) {
				continue;
			}

			$var_sku         = (string) $variation->get_sku();
			$var_stock       = (string) $variation->get_stock_status();
			$var_purchasable = (bool) $variation->is_purchasable();
			$var_price       = $variation->get_price();
			$var_attrs       = $variation->get_attributes();
			$display_sku     = $var_sku ?: "var#{$variation_id}";

			if ( $var_purchasable && 'instock' === $var_stock ) {
				$has_purchasable_in_stock = true;
			}

			if ( '' === $var_sku ) {
				$issues[] = DTB_CatalogHealthIssue::make(
					$product_id,
					$product_name,
					"var#{$variation_id}",
					DTB_CatalogHealthIssue::SEVERITY_ERROR,
					'variation_missing_sku',
					"Variation #{$variation_id} has no SKU. Veeqo/QuickBooks sync will be blocked."
				);
			}

			if ( $var_purchasable && ( '' === $var_price || null === $var_price ) ) {
				$issues[] = DTB_CatalogHealthIssue::make(
					$product_id,
					$product_name,
					$display_sku,
					DTB_CatalogHealthIssue::SEVERITY_ERROR,
					'variation_missing_price',
					"Variation #{$variation_id} ({$var_sku}) is purchasable but has no price."
				);
			}

			if ( empty( $var_attrs ) && ! empty( $parent_attributes ) ) {
				$issues[] = DTB_CatalogHealthIssue::make(
					$product_id,
					$product_name,
					$display_sku,
					DTB_CatalogHealthIssue::SEVERITY_ERROR,
					'variation_missing_attribute_values',
					"Variation #{$variation_id} ({$var_sku}) has no attribute values assigned."
				);
			}
		}

		if ( ! $has_purchasable_in_stock ) {
			$issues[] = DTB_CatalogHealthIssue::make(
				$product_id,
				$product_name,
				$parent_sku,
				DTB_CatalogHealthIssue::SEVERITY_WARNING,
				'no_purchasable_variations',
				'Variable product has no purchasable in-stock variations.'
			);
		}

		if ( '' === $parent_sku ) {
			$issues[] = DTB_CatalogHealthIssue::make(
				$product_id,
				$product_name,
				'(none)',
				DTB_CatalogHealthIssue::SEVERITY_WARNING,
				'parent_missing_sku',
				'Variable parent product has no SKU.'
			);
		}

		return $issues;
	}

	/**
	 * Run DTB meta validation against product IDs.
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return array[]
	 */
	public static function inspect_dtb_meta( array $product_ids ): array {
		return DTB_CatalogValidationService::validate_products( array_map( 'intval', $product_ids ) );
	}
}
