<?php
/**
 * Product metadata validator.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ProductMetaValidator {

	/**
	 * @param  array $context
	 * @return array[]
	 */
	public static function validate( array $context ): array {
		$product = $context['product'] ?? null;
		$meta    = $context['meta'] ?? [];
		if ( ! $product || ! is_array( $meta ) ) {
			return [];
		}

		$issues = [];

		$brand_key    = (string) ( $meta['_dtb_brand_key'] ?? '' );
		$category_key = (string) ( $meta['_dtb_category_key'] ?? '' );
		$product_kind = (string) ( $meta['_dtb_product_kind'] ?? '' );

		if ( '' === $brand_key ) {
			$issues[] = [
				'severity' => 'error',
				'code'     => 'dtb_missing_brand_key',
				'message'  => 'Product is published but has no _dtb_brand_key. Catalog filters and facets can omit it.',
			];
		}

		if ( '' === $category_key ) {
			$issues[] = [
				'severity' => 'error',
				'code'     => 'dtb_missing_category_key',
				'message'  => 'Product is published but has no _dtb_category_key. Category routes and filtering will be inconsistent.',
			];
		}

		if ( '' === $product_kind ) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'dtb_missing_product_kind',
				'message'  => 'Product is missing _dtb_product_kind. Toolset and catalog behavior may rely on fallback inference.',
			];
		}

		$commerce_mode = (string) ( $meta['_dtb_commerce_mode'] ?? '' );
		if ( '' === $commerce_mode ) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'dtb_missing_commerce_mode',
				'message'  => 'Product is missing _dtb_commerce_mode. Pricing, visibility, and cart behavior depend on this field. Expected: purchasable, quote_only, hidden_reference, repair_only, or included_item.',
			];
		}

		return $issues;
	}
}
