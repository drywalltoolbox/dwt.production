<?php
/**
 * DTB_CategoryNormalizer
 *
 * Compatibility normalization for DTB category/display metadata.
 *
 * WooCommerce product_cat is the storefront navigation authority. This class
 * normalizes compatibility facets only; it must not recreate the product_cat
 * hierarchy or infer taxonomy from brand/product-family names.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_CategoryNormalizer {

	const CATEGORY_LABELS = [
		'taping'      => 'Taping Tools',
		'finishing'   => 'Finishing Tools',
		'corner'      => 'Corner Tools',
		'handles'     => 'Handles & Extensions',
		'mudboxes'    => 'Pumps & Loading',
		'accessories' => 'Accessories',
		'sanding'     => 'Sanding Tools',
		'stilts'      => 'Stilts',
		'texture'     => 'Texture Tools',
		'parts'       => 'Replacement Parts',
		'hand_tools'  => 'Drywall Hand Tools',
		'fasteners'   => 'Drywall Screws & Fasteners',
		'sealants'    => 'Drywall Sealants',
		'services'    => 'Repair Services',
	];

	/** Functional product-class names only. Generic/family labels are omitted. */
	const LEGACY_CATEGORY_MAP = [
		'automatic tapers'                     => 'taping',
		'semi-automatic tapers'                => 'taping',
		'semi-automatic tapers & banjos'       => 'taping',
		'nail spotters'                        => 'taping',
		'flat boxes'                           => 'finishing',
		'finishing boxes'                      => 'finishing',
		'smoothing blades'                     => 'finishing',
		'angle heads'                          => 'corner',
		'corner finishers'                     => 'corner',
		'corner applicators & angle boxes'     => 'corner',
		'angle boxes & corner applicators'     => 'corner',
		'angle boxes'                          => 'corner',
		'corner boxes'                         => 'corner',
		'corner rollers'                       => 'corner',
		'corner flushers'                      => 'corner',
		'applicator heads'                     => 'corner',
		'powered compound applicators'         => 'corner',
		'compound applicators'                 => 'corner',
		'compound tubes'                       => 'corner',
		'handles & extensions'                 => 'handles',
		'flat box handles'                     => 'handles',
		'extendable handles'                   => 'handles',
		'fixed handles'                        => 'handles',
		'corner tool handles'                  => 'handles',
		'loading & compound pumps'             => 'mudboxes',
		'loading pumps'                        => 'mudboxes',
		'compound pumps'                       => 'mudboxes',
		'goosenecks, box fillers & adapters'   => 'mudboxes',
		'box fillers'                          => 'mudboxes',
		'goosenecks'                           => 'mudboxes',
		'tool sets & kits'                     => 'taping',
		'automatic taping tool sets'           => 'taping',
		'tool sets'                            => 'taping',
		'semi-automatic taping tool sets'      => 'taping',
		'tool storage & cases'                 => 'accessories',
		'tool cases'                           => 'accessories',
		'taping tool accessories'              => 'accessories',
		'accessories & adapters'               => 'accessories',
		'accessories and adapters'             => 'accessories',
		'stilts'                               => 'stilts',
		'parts'                                => 'parts',
		'replacement parts'                    => 'parts',
		'drywall hand tools'                  => 'hand_tools',
		'drywall screws & fasteners'          => 'fasteners',
		'drywall sealants'                    => 'sealants',
		'bead tools'                          => 'finishing',
	];

	const CATEGORY_KEY_ALIASES = [
		'corner_tools'     => 'corner',
		'corner-tools'     => 'corner',
		'finishing_boxes'  => 'finishing',
		'finishing-boxes'  => 'finishing',
		'nail_spotters'    => 'taping',
		'nail-spotters'    => 'taping',
		'pumps'            => 'mudboxes',
		'compound_tubes'   => 'corner',
		'compound-tubes'   => 'corner',
		'automatic_tapers' => 'taping',
	];

	const DISPLAY_CATEGORY_LABELS = [
		'automatic_tapers'                          => 'Automatic Tapers',
		'flat_boxes'                                => 'Flat Boxes',
		'corner_finishers'                          => 'Corner Finishers',
		'automatic_angle_boxes_corner_applicators' => 'Corner Applicators & Angle Boxes',
		'compound_tubes'                            => 'Compound Tubes',
		'powered_compound_applicators'              => 'Powered Compound Applicators',
		'applicator_heads'                          => 'Applicator Heads',
		'automatic_corner_flushers'                 => 'Corner Flushers',
		'automatic_corner_rollers'                  => 'Corner Rollers',
		'automatic_nail_spotters'                   => 'Nail Spotters',
		'automatic_loading_pumps'                   => 'Loading & Compound Pumps',
		'automatic_goosenecks_box_fillers'          => 'Goosenecks, Box Fillers & Adapters',
		'automatic_continuous_flow_tools'           => 'Continuous Flow Tools',
		'handles'                                   => 'Handles & Extensions',
		'toolsets'                                  => 'Tool Sets & Kits',
		'semi_automatic_tapers_banjos'              => 'Semi-Automatic Tapers & Banjos',
		'tool_storage_cases'                        => 'Tool Storage & Cases',
		'parts'                                     => 'Parts',
		'bead_tools'                                => 'Bead Tools',
		'drywall_hand_tools'                        => 'Drywall Hand Tools',
		'drywall_screws_fasteners'                  => 'Drywall Screws & Fasteners',
		'drywall_sealants'                          => 'Drywall Sealants',
		'stilts'                                    => 'Stilts',
		// Legacy compatibility value retained until all historical product meta is migrated.
		'automatic_compound_applicators'            => 'Compound Applicators',
		'accessories'                               => 'Accessories',
		'smoothing_blades'                          => 'Smoothing Blades',
	];

	const DISPLAY_CATEGORY_ALIASES = [
		'automatic_tapers'                          => 'automatic_tapers',
		'automatic_taper'                           => 'automatic_tapers',
		'auto_taper'                                => 'automatic_tapers',
		'flat_boxes'                                => 'flat_boxes',
		'flat_box'                                  => 'flat_boxes',
		'finishing_boxes'                           => 'flat_boxes',
		'finishing_box'                             => 'flat_boxes',
		'automatic_angle_heads'                     => 'corner_finishers',
		'automatic_corner_finishers'                => 'corner_finishers',
		'angle_heads'                               => 'corner_finishers',
		'angle_head'                                => 'corner_finishers',
		'anglehead'                                 => 'corner_finishers',
		'corner_finishers'                          => 'corner_finishers',
		'corner_finisher'                           => 'corner_finishers',
		'automatic_angle_boxes_corner_applicators' => 'automatic_angle_boxes_corner_applicators',
		'corner_applicators_angle_boxes'            => 'automatic_angle_boxes_corner_applicators',
		'corner_applicators'                        => 'automatic_angle_boxes_corner_applicators',
		'angle_boxes'                               => 'automatic_angle_boxes_corner_applicators',
		'corner_boxes'                              => 'automatic_angle_boxes_corner_applicators',
		'automatic_compound_tubes'                  => 'compound_tubes',
		'semi_compound_tubes'                       => 'compound_tubes',
		'compound_tubes'                            => 'compound_tubes',
		'compound_tube'                             => 'compound_tubes',
		'powered_compound_applicators'              => 'powered_compound_applicators',
		'powered_compound_applicator'               => 'powered_compound_applicators',
		'applicator_heads'                          => 'applicator_heads',
		'applicator_head'                           => 'applicator_heads',
		'mud_heads'                                 => 'applicator_heads',
		'mud_head'                                  => 'applicator_heads',
		'automatic_compound_applicators'            => 'automatic_compound_applicators',
		'compound_applicators'                      => 'automatic_compound_applicators',
		'automatic_corner_flushers'                 => 'automatic_corner_flushers',
		'semi_corner_flushers'                      => 'automatic_corner_flushers',
		'corner_flushers'                           => 'automatic_corner_flushers',
		'automatic_corner_rollers'                  => 'automatic_corner_rollers',
		'corner_rollers'                            => 'automatic_corner_rollers',
		'automatic_nail_spotters'                   => 'automatic_nail_spotters',
		'nail_spotters'                             => 'automatic_nail_spotters',
		'nail_spotter'                              => 'automatic_nail_spotters',
		'nailspotters'                              => 'automatic_nail_spotters',
		'nailspotter'                               => 'automatic_nail_spotters',
		'automatic_loading_pumps'                   => 'automatic_loading_pumps',
		'loading_pumps'                             => 'automatic_loading_pumps',
		'loading_compound_pumps'                    => 'automatic_loading_pumps',
		'compound_pumps'                            => 'automatic_loading_pumps',
		'automatic_goosenecks_box_fillers'          => 'automatic_goosenecks_box_fillers',
		'goosenecks_box_fillers_adapters'           => 'automatic_goosenecks_box_fillers',
		'goosenecks_box_fillers'                    => 'automatic_goosenecks_box_fillers',
		'automatic_continuous_flow_tools'           => 'automatic_continuous_flow_tools',
		'continuous_flow_tools'                     => 'automatic_continuous_flow_tools',
		'automatic_handles_extensions'              => 'handles',
		'semi_handles_extensions'                   => 'handles',
		'handles'                                   => 'handles',
		'handle'                                    => 'handles',
		'handles_extensions'                        => 'handles',
		'handles_and_extensions'                    => 'handles',
		'automatic_tool_sets'                       => 'toolsets',
		'semi_tool_sets'                            => 'toolsets',
		'toolsets'                                  => 'toolsets',
		'toolset'                                   => 'toolsets',
		'tool_sets_kits'                            => 'toolsets',
		'tool_sets_and_kits'                        => 'toolsets',
		'semi_automatic_tools'                      => 'semi_automatic_tapers_banjos',
		'semi_automatic_tapers'                     => 'semi_automatic_tapers_banjos',
		'semi_automatic_taper'                      => 'semi_automatic_tapers_banjos',
		'semi_automatic_tapers_banjos'              => 'semi_automatic_tapers_banjos',
		'banjos'                                    => 'semi_automatic_tapers_banjos',
		'banjo'                                     => 'semi_automatic_tapers_banjos',
		'tool_storage_cases'                        => 'tool_storage_cases',
		'accessories'                               => 'accessories',
		'accessory'                                 => 'accessories',
		'smoothing_blades'                          => 'smoothing_blades',
		'smoothing_blade'                           => 'smoothing_blades',
		'skimming_blade'                            => 'smoothing_blades',
		'parts'                                     => 'parts',
		'bead_tools'                                => 'bead_tools',
		'drywall_hand_tools'                        => 'drywall_hand_tools',
		'drywall_screws_fasteners'                  => 'drywall_screws_fasteners',
		'drywall_sealants'                          => 'drywall_sealants',
		'part'                                      => 'parts',
		'replacement_parts'                         => 'parts',
		'stilts'                                    => 'stilts',
		'stilt'                                     => 'stilts',
	];

	public static function resolve( array $wc_categories, string $meta_key = '' ): array {
		$key = self::canonical_category_key( $meta_key );
		if ( '' !== $key ) {
			return self::from_key( $key );
		}

		foreach ( $wc_categories as $cat ) {
			$name = strtolower( trim( (string) ( $cat['name'] ?? '' ) ) );
			if ( isset( self::LEGACY_CATEGORY_MAP[ $name ] ) ) {
				return self::from_key( self::LEGACY_CATEGORY_MAP[ $name ] );
			}
		}

		return [ 'key' => '', 'label' => '', 'slug' => '' ];
	}

	public static function canonical_category_key( string $raw ): string {
		$key = strtolower( trim( $raw ) );
		if ( '' === $key ) {
			return '';
		}
		$key = str_replace( '-', '_', sanitize_title_with_dashes( $key ) );
		$key = self::CATEGORY_KEY_ALIASES[ $key ] ?? $key;
		return isset( self::CATEGORY_LABELS[ $key ] ) ? $key : '';
	}

	public static function from_key( string $key ): array {
		$canonical = self::canonical_category_key( $key );
		if ( '' === $canonical ) {
			return [ 'key' => '', 'label' => '', 'slug' => '' ];
		}
		return [
			'key'   => $canonical,
			'label' => self::CATEGORY_LABELS[ $canonical ],
			'slug'  => $canonical,
		];
	}

	public static function is_valid_key( string $key ): bool {
		return '' !== self::canonical_category_key( $key );
	}

	public static function canonical_display_slug( string $raw ): string {
		if ( '' === trim( $raw ) ) {
			return '';
		}
		$lookup = strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '_', $raw ) ?? $raw, '_' ) );
		return self::DISPLAY_CATEGORY_ALIASES[ $lookup ] ?? '';
	}

	public static function display_category_raw_forms( string $canonical_slug ): array {
		$canonical = self::canonical_display_slug( $canonical_slug );
		if ( '' === $canonical ) {
			return [];
		}
		$forms = [ $canonical, str_replace( '_', ' ', $canonical ), ucwords( str_replace( '_', ' ', $canonical ) ) ];
		foreach ( self::DISPLAY_CATEGORY_ALIASES as $raw => $target ) {
			if ( $target === $canonical ) {
				$forms[] = $raw;
				$forms[] = str_replace( '_', ' ', $raw );
			}
		}
		return array_values( array_unique( array_filter( $forms ) ) );
	}
}
