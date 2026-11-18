<?php
/**
 * DTB_ToolFamilyResolver
 *
 * Resolves canonical functional tool families from the strongest available
 * catalog evidence while preserving one authority for classification.
 *
 * Priority:
 *   1. Deterministic migration of known legacy/misclassified family values.
 *   2. Valid explicit _dtb_tool_family meta.
 *   3. Builder-slot metadata.
 *   4. Exact one-to-one display category.
 *   5. Narrow one-to-one broad category.
 *   6. Bounded product-name fallback.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolFamilyResolver {

	const DISPLAY_CATEGORY_FAMILY_HINTS = [
		'automatic_tapers'                         => DTB_ToolFamilies::AUTOMATIC_TAPER,
		'flat_boxes'                               => DTB_ToolFamilies::FLAT_BOX,
		'handles'                                  => DTB_ToolFamilies::HANDLE,
		'corner_finishers'                         => DTB_ToolFamilies::ANGLE_HEAD,
		'automatic_angle_boxes_corner_applicators'=> DTB_ToolFamilies::CORNER_BOX,
		'automatic_corner_rollers'                 => DTB_ToolFamilies::CORNER_ROLLER,
		'automatic_loading_pumps'                  => DTB_ToolFamilies::PUMP,
		'smoothing_blades'                         => DTB_ToolFamilies::SKIMMING_BLADE,
		'parts'                                    => DTB_ToolFamilies::REPLACEMENT_PART,
		'stilts'                                   => DTB_ToolFamilies::STILT,
	];

	const CATEGORY_FAMILY_HINTS = [
		'sanding'  => DTB_ToolFamilies::ACCESSORY,
		'stilts'   => DTB_ToolFamilies::STILT,
		'texture'  => DTB_ToolFamilies::SPRAYER,
		'parts'    => DTB_ToolFamilies::REPLACEMENT_PART,
		'services' => DTB_ToolFamilies::SERVICE,
	];

	const NAME_KEYWORD_HINTS = [
		'automatic taper'      => DTB_ToolFamilies::AUTOMATIC_TAPER,
		'flat box'             => DTB_ToolFamilies::FLAT_BOX,
		'finishing box'        => DTB_ToolFamilies::FLAT_BOX,
		'skimming box'         => DTB_ToolFamilies::FLAT_BOX,
		'fat boy'              => DTB_ToolFamilies::FLAT_BOX,
		'angle head'           => DTB_ToolFamilies::ANGLE_HEAD,
		'corner finisher'      => DTB_ToolFamilies::ANGLE_HEAD,
		'corner applicator'    => DTB_ToolFamilies::CORNER_BOX,
		'corner box'           => DTB_ToolFamilies::CORNER_BOX,
		'corner roller'        => DTB_ToolFamilies::CORNER_ROLLER,
		'inside corner roller' => DTB_ToolFamilies::CORNER_ROLLER,
		'loading pump'         => DTB_ToolFamilies::PUMP,
		'hot mud pump'         => DTB_ToolFamilies::PUMP,
		'gooseneck'            => DTB_ToolFamilies::GOOSENECK,
		'filler adapter'       => DTB_ToolFamilies::FILLER_ADAPTER,
		'box filler'           => DTB_ToolFamilies::FILLER_ADAPTER,
		'handle'               => DTB_ToolFamilies::HANDLE,
		'skimming blade'       => DTB_ToolFamilies::SKIMMING_BLADE,
	];

	public static function resolve(
		string $meta_family,
		array  $builder_slots,
		string $category_key,
		string $display_category_key,
		string $product_name,
		bool   $is_parts = false
	): string {
		$display_category_key = DTB_CategoryNormalizer::canonical_display_slug( $display_category_key );

		// 1. Deterministic repair of known historical values.
		$migration_target = self::canonical_migration_target(
			$meta_family,
			$display_category_key,
			$product_name
		);
		if ( '' !== $migration_target ) {
			return $migration_target;
		}

		// 2. Valid explicit meta remains authoritative.
		if ( '' !== $meta_family && DTB_ToolFamilies::is_valid( $meta_family ) ) {
			return $meta_family;
		}

		if ( $is_parts ) {
			return DTB_ToolFamilies::REPLACEMENT_PART;
		}

		// 3. Builder-slot evidence.
		if ( ! empty( $builder_slots ) ) {
			$first_slot    = (string) $builder_slots[0];
			$slot_families = DTB_ToolFamilies::families_for_slot( $first_slot );
			if ( ! empty( $slot_families ) ) {
				return $slot_families[0];
			}
		}

		// 4. Exact one-to-one display category.
		if (
			'' !== $display_category_key
			&& isset( self::DISPLAY_CATEGORY_FAMILY_HINTS[ $display_category_key ] )
		) {
			return self::DISPLAY_CATEGORY_FAMILY_HINTS[ $display_category_key ];
		}

		// These buckets are intentionally not tool-family authorities.
		if ( in_array(
			$display_category_key,
			[ 'toolsets', 'semi_automatic_tapers_banjos', 'automatic_nail_spotters', 'tool_storage_cases' ],
			true
		) ) {
			return '';
		}

		// Mixed gooseneck/filler display category: name semantics are required.
		if ( 'automatic_goosenecks_box_fillers' === $display_category_key ) {
			return self::resolve_loading_adapter_name( $product_name );
		}

		// 5. Narrow one-to-one broad category.
		if ( '' !== $category_key && isset( self::CATEGORY_FAMILY_HINTS[ $category_key ] ) ) {
			return self::CATEGORY_FAMILY_HINTS[ $category_key ];
		}

		// 6. Bounded name fallback.
		$lower = strtolower( $product_name );
		if ( preg_match( '/\bsemi[-\s]?automatic\b.*\btaper\b/i', $lower ) ) {
			return '';
		}

		foreach ( self::NAME_KEYWORD_HINTS as $keyword => $family ) {
			if ( str_contains( $lower, $keyword ) ) {
				return $family;
			}
		}

		return '';
	}

	/**
	 * True when a non-forced backfill may safely replace an existing family.
	 */
	public static function is_canonical_repair(
		string $existing_family,
		string $resolved_family,
		string $display_category_key,
		string $product_name
	): bool {
		if ( '' === $existing_family || '' === $resolved_family || $existing_family === $resolved_family ) {
			return false;
		}

		return $resolved_family === self::canonical_migration_target(
			$existing_family,
			DTB_CategoryNormalizer::canonical_display_slug( $display_category_key ),
			$product_name
		);
	}

	private static function canonical_migration_target(
		string $meta_family,
		string $display_category_key,
		string $product_name
	): string {
		if ( DTB_ToolFamilies::is_legacy_handle_family( $meta_family ) ) {
			return DTB_ToolFamilies::HANDLE;
		}

		if ( DTB_ToolFamilies::CORNER_BOX === $meta_family ) {
			if ( 'corner_finishers' === $display_category_key ) {
				return DTB_ToolFamilies::ANGLE_HEAD;
			}
			if ( 'automatic_corner_rollers' === $display_category_key ) {
				return DTB_ToolFamilies::CORNER_ROLLER;
			}
		}

		if (
			DTB_ToolFamilies::PUMP === $meta_family
			&& 'automatic_goosenecks_box_fillers' === $display_category_key
		) {
			return self::resolve_loading_adapter_name( $product_name );
		}

		return '';
	}

	private static function resolve_loading_adapter_name( string $product_name ): string {
		$lower = strtolower( $product_name );

		if ( str_contains( $lower, 'gooseneck' ) ) {
			return DTB_ToolFamilies::GOOSENECK;
		}

		if (
			str_contains( $lower, 'filler adapter' )
			|| str_contains( $lower, 'box filler' )
			|| str_contains( $lower, 'filler attachment' )
		) {
			return DTB_ToolFamilies::FILLER_ADAPTER;
		}

		return '';
	}
}
