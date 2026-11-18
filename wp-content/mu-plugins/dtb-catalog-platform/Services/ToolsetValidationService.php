<?php
/**
 * DTB_ToolsetValidationService
 *
 * Validates a set of Toolset Builder slot selections against a template
 * before they are submitted to the cart.
 *
 * Validation levels:
 *   blocking — selection cannot be added to cart
 *   warning  — selection is allowed but flagged (e.g. out-of-stock)
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetValidationService {

	/**
	 * Validate slot selections against a template.
	 *
	 * @param  array  $template    DTB_ToolsetData template array.
	 * @param  array  $selections  Map of slotId → { productId, variationId? }.
	 * @return array{ valid: bool, errors: array[], warnings: array[] }
	 */
	public static function validate( array $template, array $selections ): array {
		$errors   = [];
		$warnings = [];
		$slot_map = [];

		$template_brand = $template['brandKey'] ?? '';

		// Build slot ID → slot definition lookup.
		foreach ( $template['slots'] ?? [] as $slot ) {
			$slot_map[ $slot['id'] ] = $slot;
		}

		// 1. Check all required slots are filled.
		foreach ( $slot_map as $slot_id => $slot ) {
			if ( ! ( $slot['required'] ?? true ) ) {
				continue;
			}
			if ( ! isset( $selections[ $slot_id ] ) ) {
				$errors[] = [
					'code'    => 'required_slot_empty',
					'slot'    => $slot_id,
					'message' => sprintf( 'Required slot "%s" has no selection.', $slot['label'] ?? $slot_id ),
				];
			}
		}

		// 2. Validate each filled selection.
		foreach ( $selections as $slot_id => $selection ) {
			if ( ! isset( $slot_map[ $slot_id ] ) ) {
				$errors[] = [
					'code'    => 'unknown_slot',
					'slot'    => $slot_id,
					'message' => sprintf( 'Slot "%s" does not exist in template "%s".', $slot_id, $template['id'] ?? '' ),
				];
				continue;
			}

			$product_id   = absint( $selection['productId'] ?? 0 );
			$variation_id = absint( $selection['variationId'] ?? 0 );
			$quantity     = absint( $selection['quantity'] ?? 1 );

			if ( $quantity < 1 || $quantity > 99 ) {
				$errors[] = [
					'code'    => 'invalid_quantity',
					'slot'    => $slot_id,
					'message' => sprintf( 'Slot "%s" has an invalid quantity (%d). Must be 1–99.', $slot_id, $quantity ),
				];
			}

			if ( $product_id <= 0 ) {
				$errors[] = [
					'code'    => 'invalid_product_id',
					'slot'    => $slot_id,
					'message' => 'Selection contains no valid product ID.',
				];
				continue;
			}

			$wc_product = wc_get_product( $variation_id ?: $product_id );
			if ( ! $wc_product ) {
				$errors[] = [
					'code'    => 'product_not_found',
					'slot'    => $slot_id,
					'message' => sprintf( 'Product %d not found.', $variation_id ?: $product_id ),
				];
				continue;
			}

			// 2a. Product must be purchasable.
			if ( ! $wc_product->is_purchasable() ) {
				$errors[] = [
					'code'    => 'not_purchasable',
					'slot'    => $slot_id,
					'message' => sprintf( '"%s" is not purchasable.', $wc_product->get_name() ),
				];
			}

			// 2b. Brand must match the template brand (tamper check).
			if ( '' !== $template_brand ) {
				$product_post_id = $variation_id > 0 ? $product_id : $wc_product->get_id();
				$product_brand   = (string) get_post_meta( $product_post_id, DTB_ProductMeta::BRAND_KEY, true );
				if ( '' !== $product_brand && $product_brand !== $template_brand ) {
					$errors[] = [
						'code'    => 'brand_mismatch',
						'slot'    => $slot_id,
						'message' => sprintf(
							'"%s" belongs to brand "%s" but template requires "%s".',
							$wc_product->get_name(),
							$product_brand,
							$template_brand
						),
					];
				}
			}

			// 2d. Variation must belong to the correct parent.
			if ( $variation_id > 0 ) {
				$parent_id = method_exists( $wc_product, 'get_parent_id' ) ? $wc_product->get_parent_id() : 0;
				if ( $parent_id !== $product_id ) {
					$errors[] = [
						'code'    => 'variation_parent_mismatch',
						'slot'    => $slot_id,
						'message' => sprintf(
							'Variation %d does not belong to product %d.',
							$variation_id,
							$product_id
						),
					];
				}
			}

			// 2e. Builder eligibility check.
			// Batch-fetch all post meta for the purchasable ID in one DB call.
			$purchasable_id   = $variation_id ?: $product_id;
			$all_meta         = get_post_meta( $purchasable_id );
			$builder_slots_raw = $all_meta[ DTB_ProductMeta::BUILDER_SLOTS ][0] ?? '';
			$builder_slots     = DTB_CatalogProductNormalizer::decode_csv_or_array( $builder_slots_raw );

			// Fallback: check parent meta for variations when slot meta lives on parent.
			if ( empty( $builder_slots ) && $variation_id > 0 ) {
				$parent_meta      = get_post_meta( $product_id, DTB_ProductMeta::BUILDER_SLOTS, true );
				$builder_slots    = DTB_CatalogProductNormalizer::decode_csv_or_array( (string) $parent_meta );
			}

			if ( ! empty( $builder_slots ) && ! in_array( $slot_id, $builder_slots, true ) ) {
				$errors[] = [
					'code'    => 'slot_ineligible',
					'slot'    => $slot_id,
					'message' => sprintf(
						'"%s" is not eligible for the "%s" slot.',
						$wc_product->get_name(),
						$slot_map[ $slot_id ]['label'] ?? $slot_id
					),
				];
			}

			// 2f. Stock warning (not a blocker).
			if ( 'outofstock' === $wc_product->get_stock_status() ) {
				$warnings[] = [
					'code'    => 'out_of_stock',
					'slot'    => $slot_id,
					'message' => sprintf( '"%s" is currently out of stock.', $wc_product->get_name() ),
				];
			}
		}

		return [
			'valid'    => empty( $errors ),
			'errors'   => $errors,
			'warnings' => $warnings,
		];
	}
}
