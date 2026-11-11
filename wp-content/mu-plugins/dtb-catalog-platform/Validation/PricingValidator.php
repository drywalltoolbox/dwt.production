<?php
/**
 * Pricing validator.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_PricingValidator {

	/**
	 * @param  array $context
	 * @return array[]
	 */
	public static function validate( array $context ): array {
		$product = $context['product'] ?? null;
		$meta    = $context['meta'] ?? [];
		if ( ! $product ) {
			return [];
		}

		$issues        = [];
		$commerce_mode = (string) ( $meta['_dtb_commerce_mode'] ?? '' );

		// Products with commerce_mode=quote_only should never have a price requirement.
		// Warn if they are also flagged purchasable — that is a conflicting configuration.
		if ( 'quote_only' === $commerce_mode ) {
			if ( $product->is_purchasable() ) {
				$issues[] = [
					'severity' => 'warning',
					'code'     => 'quote_only_flagged_purchasable',
					'message'  => 'Product has _dtb_commerce_mode=quote_only but WooCommerce reports is_purchasable()=true. Remove the price or mark the product non-purchasable to prevent unintended cart adds.',
				];
			}
			return $issues;
		}

		if ( $product->is_type( 'simple' ) && $product->is_purchasable() ) {
			$price = $product->get_price();
			if ( '' === (string) $price || null === $price ) {
				$issues[] = [
					'severity' => 'error',
					'code'     => 'simple_missing_price',
					'message'  => 'Simple product is purchasable but has no price.',
				];
			}
		}

		if ( $product->is_type( 'variation' ) && $product->is_purchasable() ) {
			$price = $product->get_price();
			if ( '' === (string) $price || null === $price ) {
				$issues[] = [
					'severity' => 'error',
					'code'     => 'variation_missing_price',
					'message'  => 'Variation is purchasable but has no price.',
				];
			}
		}

		return $issues;
	}
}
