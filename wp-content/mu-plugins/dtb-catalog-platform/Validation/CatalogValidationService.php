<?php
/**
 * Catalog validation orchestrator.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CatalogValidationService {

	/**
	 * @return string[]
	 */
	private static function validator_classes(): array {
		return [
			'DTB_ProductMetaValidator',
			'DTB_ToolsetEligibilityValidator',
			'DTB_VariationValidator',
			'DTB_PricingValidator',
			'DTB_ImageValidator',
			'DTB_SeoValidator',
		];
	}

	/**
	 * Validate a single product.
	 *
	 * @param  int $product_id
	 * @return array[]
	 */
	public static function validate_product( int $product_id ): array {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return [];
		}

		$context = [
			'product_id' => $product_id,
			'product'    => $product,
			'post'       => get_post( $product_id ),
			'meta'       => self::meta_lookup( $product_id ),
			'children'   => $product->is_type( 'variable' ) ? $product->get_children() : [],
		];

		$issues = [];
		foreach ( self::validator_classes() as $validator_class ) {
			if ( ! class_exists( $validator_class ) || ! method_exists( $validator_class, 'validate' ) ) {
				continue;
			}

			$validator_issues = $validator_class::validate( $context );
			if ( ! is_array( $validator_issues ) || empty( $validator_issues ) ) {
				continue;
			}

			foreach ( $validator_issues as $issue ) {
				$issues[] = self::normalize_issue( $issue, $product );
			}
		}

		return $issues;
	}

	/**
	 * @param  int[] $product_ids
	 * @return array[]
	 */
	public static function validate_products( array $product_ids ): array {
		$issues = [];

		foreach ( $product_ids as $product_id ) {
			$issues = array_merge( $issues, self::validate_product( (int) $product_id ) );
		}

		return $issues;
	}

	/**
	 * @return array<string, string>
	 */
	private static function meta_lookup( int $product_id ): array {
		$raw = get_post_meta( $product_id );
		if ( ! is_array( $raw ) ) {
			return [];
		}

		$flat = [];
		foreach ( $raw as $key => $values ) {
			$flat[ (string) $key ] = isset( $values[0] ) ? (string) $values[0] : '';
		}

		return $flat;
	}

	/**
	 * @param  array                 $issue
	 * @param  object                $product
	 * @return array
	 */
	private static function normalize_issue( array $issue, object $product ): array {
		return [
			'product_id'   => (int) ( $issue['product_id'] ?? $product->get_id() ),
			'product_name' => (string) ( $issue['product_name'] ?? $product->get_name() ),
			'sku'          => (string) ( $issue['sku'] ?? ( $product->get_sku() ?: '(none)' ) ),
			'severity'     => (string) ( $issue['severity'] ?? 'warning' ),
			'code'         => (string) ( $issue['code'] ?? 'catalog_validation_issue' ),
			'message'      => (string) ( $issue['message'] ?? 'Catalog validation issue.' ),
		];
	}
}
