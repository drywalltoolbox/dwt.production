<?php
/**
 * DTB_ToolFamilies
 *
 * Canonical functional tool-family taxonomy and Toolset Builder slot mapping.
 *
 * A family describes what a product is. Compatibility describes which tools a
 * product can operate with. Handles are therefore one universal family rather
 * than separate flat-box/angle-head/corner-roller families.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolFamilies {

	// ── Tool family keys ───────────────────────────────────────────────────────

	const AUTOMATIC_TAPER  = 'automatic_taper';
	const FLAT_BOX         = 'flat_box';
	const HANDLE           = 'handle';
	const ANGLE_HEAD       = 'angle_head';
	const CORNER_BOX       = 'corner_box';
	const CORNER_ROLLER    = 'corner_roller';
	const PUMP             = 'pump';
	const FILLER_ADAPTER   = 'filler_adapter';
	const GOOSENECK        = 'gooseneck';
	const SKIMMING_BLADE   = 'skimming_blade';
	const KNIFE            = 'knife';
	const STILT            = 'stilt';
	const SPRAYER          = 'sprayer';
	const REPLACEMENT_PART = 'replacement_part';
	const ACCESSORY        = 'accessory';
	const SERVICE          = 'service';

	/**
	 * Legacy handle-family values accepted only for deterministic migration.
	 *
	 * These values are not valid canonical families and must not be emitted by
	 * new writes or API DTOs.
	 *
	 * @var string[]
	 */
	const LEGACY_HANDLE_FAMILIES = [
		'flat_box_handle',
		'angle_head_handle',
		'corner_roller_handle',
	];

	/** @var string[] */
	const ALL = [
		self::AUTOMATIC_TAPER,
		self::FLAT_BOX,
		self::HANDLE,
		self::ANGLE_HEAD,
		self::CORNER_BOX,
		self::CORNER_ROLLER,
		self::PUMP,
		self::FILLER_ADAPTER,
		self::GOOSENECK,
		self::SKIMMING_BLADE,
		self::KNIFE,
		self::STILT,
		self::SPRAYER,
		self::REPLACEMENT_PART,
		self::ACCESSORY,
		self::SERVICE,
	];

	/**
	 * Toolset Builder slot → allowed canonical families.
	 *
	 * Historical slot IDs remain as transport aliases for legacy template data,
	 * but every handle-shaped slot now resolves to the universal HANDLE family.
	 * Functional compatibility is not encoded in the family taxonomy.
	 *
	 * @var array<string, string[]>
	 */
	const SLOT_FAMILIES = [
		'taper'                  => [ self::AUTOMATIC_TAPER ],
		'flatBox'                => [ self::FLAT_BOX ],
		'flatBox2'               => [ self::FLAT_BOX ],
		'handle'                 => [ self::HANDLE ],
		'handles'                => [ self::HANDLE ],
		'boxHandle'              => [ self::HANDLE ],
		'boxHandle2'             => [ self::HANDLE ],
		'angleHead'              => [ self::ANGLE_HEAD ],
		'angleHead2'             => [ self::ANGLE_HEAD ],
		'cornerBox'              => [ self::CORNER_BOX ],
		'cornerApplicator'       => [ self::CORNER_BOX ],
		'angleHeadHandle'        => [ self::HANDLE ],
		'rollerHandle'           => [ self::HANDLE ],
		'cornerBoxHandle'        => [ self::HANDLE ],
		'cornerApplicatorHandle' => [ self::HANDLE ],
		'cornerHandle'           => [ self::HANDLE ],
		'cornerRoller'           => [ self::CORNER_ROLLER ],
		'pump'                   => [ self::PUMP ],
		'fillerAdapter'          => [ self::FILLER_ADAPTER ],
		'gooseneck'              => [ self::GOOSENECK ],
	];

	/** @return string[] */
	public static function families_for_slot( string $slot_id ): array {
		return self::SLOT_FAMILIES[ $slot_id ] ?? [];
	}

	/** @return string[] */
	public static function slots_for_family( string $family ): array {
		$slots = [];
		foreach ( self::SLOT_FAMILIES as $slot => $families ) {
			if ( in_array( $family, $families, true ) ) {
				$slots[] = $slot;
			}
		}
		return $slots;
	}

	public static function is_valid( string $family ): bool {
		return in_array( $family, self::ALL, true );
	}

	public static function is_legacy_handle_family( string $family ): bool {
		return in_array( $family, self::LEGACY_HANDLE_FAMILIES, true );
	}
}
