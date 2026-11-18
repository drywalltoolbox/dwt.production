<?php
/**
 * Toolset eligibility validator.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetEligibilityValidator {

	/**
	 * Commerce modes that are incompatible with builder eligibility.
	 * A product in one of these modes cannot be meaningfully added to a toolset slot.
	 */
	private const INCOMPATIBLE_COMMERCE_MODES = [
		'hidden_reference',
		'included_item',
	];

	/**
	 * @param  array $context
	 * @return array[]
	 */
	public static function validate( array $context ): array {
		$meta = $context['meta'] ?? [];
		if ( ! is_array( $meta ) ) {
			return [];
		}

		$issues = [];

		$builder_eligible = (string) ( $meta['_dtb_builder_eligible'] ?? '' );
		$tool_family      = (string) ( $meta['_dtb_tool_family']      ?? '' );
		$builder_slots    = (string) ( $meta['_dtb_builder_slots']    ?? '' );
		$commerce_mode    = (string) ( $meta['_dtb_commerce_mode']    ?? '' );

		if ( '1' !== $builder_eligible ) {
			return [];
		}

		if ( '' === $tool_family ) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'dtb_builder_missing_family',
				'message'  => 'Product is marked builder eligible but has no _dtb_tool_family.',
			];
		}

		if ( '' === $builder_slots ) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'dtb_builder_missing_slots',
				'message'  => 'Product is marked builder eligible but has no _dtb_builder_slots.',
			];
		} else {
			// Validate each slot entry is a non-empty string (no blank/null entries in the CSV list).
			$slot_list = array_map( 'trim', explode( ',', $builder_slots ) );
			foreach ( $slot_list as $slot ) {
				if ( '' === $slot ) {
					$issues[] = [
						'severity' => 'warning',
						'code'     => 'dtb_builder_slot_empty_entry',
						'message'  => '_dtb_builder_slots contains a blank entry. Trim trailing commas or whitespace in the slot list.',
					];
					break;
				}
			}
		}

		// Commerce mode conflict: hidden/included items cannot be Toolset Builder slots.
		if ( '' !== $commerce_mode && in_array( $commerce_mode, self::INCOMPATIBLE_COMMERCE_MODES, true ) ) {
			$issues[] = [
				'severity' => 'warning',
				'code'     => 'dtb_builder_commerce_mode_conflict',
				'message'  => sprintf(
					'Product is marked builder eligible but has _dtb_commerce_mode="%s". Products with this mode are not user-selectable in the Toolset Builder. Consider removing builder eligibility or changing the commerce mode.',
					$commerce_mode
				),
			];
		}

		return $issues;
	}
}
