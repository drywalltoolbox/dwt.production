<?php
/**
 * Variation integrity validator.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_VariationValidator {

	/**
	 * @param  array $context
	 * @return array[]
	 */
	public static function validate( array $context ): array {
		$product  = $context['product'] ?? null;
		$meta     = $context['meta'] ?? [];
		$children = $context['children'] ?? [];
		if ( ! $product || ! is_array( $meta ) ) {
			return [];
		}

		if ( ! $product->is_type( 'variable' ) ) {
			return [];
		}

		$issues = [];

		// Fetch children from context or product if context didn't pass them.
		if ( empty( $children ) ) {
			$children = $product->get_children();
		}

		if ( empty( $children ) ) {
			$issues[] = [
				'severity' => 'error',
				'code'     => 'variable_parent_no_children',
				'message'  => 'Variable parent has no child variations.',
			];
			return $issues;
		}

		// ── Default variation ID checks ──────────────────────────────────────
		$default_var_id = (int) ( $meta['_dtb_default_variation_id'] ?? 0 );
		if ( $default_var_id <= 0 ) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'dtb_missing_default_var',
				'message'  => 'Variable parent is missing _dtb_default_variation_id. UI will rely on fallback selection.',
			];
		} elseif ( ! in_array( $default_var_id, $children, true ) ) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'dtb_invalid_default_var',
				'message'  => sprintf( '_dtb_default_variation_id (%d) does not belong to this parent.', $default_var_id ),
			];
		}

		// ── Per-child variation integrity checks ─────────────────────────────
		foreach ( $children as $child_id ) {
			$child_id = (int) $child_id;
			$variation = wc_get_product( $child_id );
			if ( ! $variation ) {
				$issues[] = [
					'product_id' => $child_id,
					'severity'   => 'error',
					'code'       => 'variation_not_loadable',
					'message'    => sprintf( 'Child variation ID %d could not be loaded via wc_get_product().', $child_id ),
				];
				continue;
			}

			$child_meta = get_post_meta( $child_id );
			$child_flat = [];
			if ( is_array( $child_meta ) ) {
				foreach ( $child_meta as $k => $v ) {
					$child_flat[ $k ] = isset( $v[0] ) ? (string) $v[0] : '';
				}
			}

			// SKU
			$child_sku = $variation->get_sku();
			if ( '' === (string) $child_sku ) {
				$issues[] = [
					'product_id' => $child_id,
					'severity'   => 'error',
					'code'       => 'variation_missing_sku',
					'message'    => sprintf( 'Child variation ID %d is missing a SKU.', $child_id ),
				];
			}

			// Price (only required when variation is purchasable)
			if ( $variation->is_purchasable() ) {
				$commerce_mode = (string) ( $child_flat['_dtb_commerce_mode'] ?? '' );
				if ( 'quote_only' !== $commerce_mode ) {
					$price = $variation->get_price();
					if ( '' === (string) $price || null === $price ) {
						$issues[] = [
							'product_id' => $child_id,
							'severity'   => 'error',
							'code'       => 'variation_missing_price',
							'message'    => sprintf( 'Child variation ID %d is purchasable but has no price.', $child_id ),
						];
					}
				}
			}

			// DTB variation axis meta
			$axis  = (string) ( $child_flat['_dtb_variation_axis']  ?? '' );
			$value = (string) ( $child_flat['_dtb_variation_value'] ?? '' );
			$label = (string) ( $child_flat['_dtb_variation_label'] ?? '' );

			if ( '' === $axis ) {
				$issues[] = [
					'product_id' => $child_id,
					'severity'   => 'error',
					'code'       => 'variation_missing_dtb_variation_axis',
					'message'    => sprintf( 'Child variation ID %d is missing _dtb_variation_axis.', $child_id ),
				];
			}
			if ( '' === $value ) {
				$issues[] = [
					'product_id' => $child_id,
					'severity'   => 'error',
					'code'       => 'variation_missing_dtb_variation_value',
					'message'    => sprintf( 'Child variation ID %d is missing _dtb_variation_value.', $child_id ),
				];
			}
			if ( '' === $label ) {
				$issues[] = [
					'product_id' => $child_id,
					'severity'   => 'warning',
					'code'       => 'variation_missing_dtb_variation_label',
					'message'    => sprintf( 'Child variation ID %d is missing _dtb_variation_label. UI will fall back to raw value.', $child_id ),
				];
			}

			// Parent SKU back-reference
			$parent_sku_ref = (string) ( $child_flat['_dtb_parent_product_sku'] ?? '' );
			$parent_sku     = $product->get_sku();
			if ( '' !== $parent_sku && '' !== $parent_sku_ref && $parent_sku_ref !== $parent_sku ) {
				$issues[] = [
					'product_id' => $child_id,
					'severity'   => 'error',
					'code'       => 'variation_parent_sku_mismatch',
					'message'    => sprintf(
						'Child variation ID %d has _dtb_parent_product_sku "%s" but parent SKU is "%s".',
						$child_id,
						$parent_sku_ref,
						$parent_sku
					),
				];
			}
		}

		return $issues;
	}
}
