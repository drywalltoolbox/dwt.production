<?php
/**
 * Image availability validator.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ImageValidator {

	/**
	 * @param  array $context
	 * @return array[]
	 */
	public static function validate( array $context ): array {
		$product = $context['product'] ?? null;
		$meta    = $context['meta']    ?? [];
		if ( ! $product ) {
			return [];
		}

		if ( ! $product->is_visible() ) {
			return [];
		}

		$image_id = (int) $product->get_image_id();
		if ( $image_id > 0 ) {
			return [];
		}

		// Builder-eligible products without an image will have a broken slot card
		// in the Toolset Builder UI — elevate to error.
		$builder_eligible = (string) ( $meta['_dtb_builder_eligible'] ?? '' );
		if ( '1' === $builder_eligible ) {
			return [
				[
					'severity' => 'error',
					'code'     => 'missing_builder_image',
					'message'  => 'Builder-eligible product is missing a primary image. The Toolset Builder slot card will render without a product photo.',
				],
			];
		}

		return [
			[
				'severity' => 'warning',
				'code'     => 'missing_primary_image',
				'message'  => 'Visible product is missing a primary image.',
			],
		];
	}
}
