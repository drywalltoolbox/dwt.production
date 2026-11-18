<?php
/**
 * DTB_ToolsetData
 *
 * Legacy brand-template definitions used by the existing /toolsets endpoints.
 * Universal Toolset Builder reads canonical catalog families directly; these
 * templates remain supported but use the same family taxonomy.  These mirror the SET_TEMPLATES array in
 * frontend/src/data/toolsetTemplates.js but use tool-family-based slot
 * definitions instead of keyword filter functions.
 *
 * Templates are seeded into wp_options('dtb_toolset_templates') on first
 * load (idempotent — only if the option is absent).  An admin UI (Phase 6)
 * will allow runtime management; until then, updates require a code deploy.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

final class DTB_ToolsetData {

	const OPTION_KEY = 'dtb_toolset_templates';

	/** Seed option key used to track the installed version. */
	const SEED_VERSION_KEY = 'dtb_toolset_templates_seed_v';

	/** Bump this when SEED_TEMPLATES changes to force a re-seed. */
	const SEED_VERSION = 2;

	/**
	 * Canonical template definitions.
	 *
	 * Each slot uses allowedFamilies from DTB_ToolFamilies::SLOT_FAMILIES,
	 * eliminating the need for name-based keyword matching.
	 *
	 * @var array[]
	 */
	const SEED_TEMPLATES = [

		// ── TapeTech Full Set ─────────────────────────────────────────────────
		[
			'id'             => 'tapetech-full',
			'brandKey'       => 'tapetech',
			'brandLabel'     => 'TapeTech',
			'scope'          => 'full',
			'name'           => 'TapeTech® Custom Full Set',
			'description'    => 'Choose your own taper, flat boxes, angle heads, corner applicator, and handles.',
			'tagline'        => 'Everything from taping to finishing — fully configured your way.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Full-time automatic taping crews',
			'slots'          => [
				[ 'id' => 'taper',                   'label' => 'Automatic Taper',              'required' => true,  'icon' => 'taper',     'hint' => 'The taper applies tape and mud in one pass.',             'allowedFamilies' => [ 'automatic_taper' ] ],
				[ 'id' => 'flatBox',                 'label' => 'Flat Box #1',                  'required' => true,  'icon' => 'flatbox',   'hint' => 'Choose your primary flat finishing box size.',             'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',                'label' => 'Flat Box #2 (Optional)',        'required' => false, 'icon' => 'flatbox',   'hint' => 'Add a second flat box for faster two-coat finishing.',     'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle',               'label' => 'Flat Box Handle',              'required' => true,  'icon' => 'handle',    'hint' => 'Controls the angle and reach of your flat boxes.',         'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'boxHandle2',              'label' => 'Second Box Handle (Optional)', 'required' => false, 'icon' => 'handle',    'hint' => 'Match with your second flat box selection.',               'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead',               'label' => 'Angle Head',                   'required' => true,  'icon' => 'anglehead', 'hint' => 'Finishes inside angles where walls meet.',                 'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'angleHead2',              'label' => 'Second Angle Head (Optional)', 'required' => false, 'icon' => 'anglehead', 'hint' => 'Having two angle heads speeds up inside angle work.',       'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'cornerApplicator',        'label' => 'Corner Applicator',            'required' => true,  'icon' => 'cornerbox', 'hint' => 'Applies mud to outside and inside corners.',               'allowedFamilies' => [ 'corner_box' ] ],
				[ 'id' => 'angleHeadHandle',         'label' => 'Angle Head Handle',            'required' => true,  'icon' => 'handle',    'hint' => 'Extends reach for ceiling angle work.',                   'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'rollerHandle',            'label' => 'Roller Handle',                'required' => true,  'icon' => 'roller',    'hint' => 'Used with the inside corner roller.',                     'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'cornerApplicatorHandle',  'label' => 'Corner Applicator Handle',     'required' => true,  'icon' => 'handle',    'hint' => 'Provides leverage when applying corner mud.',              'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [
				'TapeTech® EasyClean® Loading Pump',
				'TapeTech® Filler Adapter',
				'TapeTech® Gooseneck Adapter',
				'TapeTech® Inside Corner Roller',
				'TapeTech® Corner Finisher Adapter',
			],
		],

		// ── TapeTech Finishing Set ─────────────────────────────────────────────
		[
			'id'             => 'tapetech-finishing',
			'brandKey'       => 'tapetech',
			'brandLabel'     => 'TapeTech',
			'scope'          => 'finishing',
			'name'           => 'TapeTech® Custom Finishing Set',
			'description'    => 'Choose your own flat boxes, angle heads, corner applicator, and handles. No taper.',
			'tagline'        => 'Perfect for dedicated finishing crews — boxes, angles, and corners.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Dedicated finishing crews',
			'slots'          => [
				[ 'id' => 'flatBox',                'label' => 'Flat Box #1',                  'required' => true,  'icon' => 'flatbox',   'hint' => 'Choose your primary flat finishing box size.',         'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',               'label' => 'Flat Box #2 (Optional)',        'required' => false, 'icon' => 'flatbox',   'hint' => 'Add a second flat box for two-coat work.',             'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle',              'label' => 'Flat Box Handle',              'required' => true,  'icon' => 'handle',    'hint' => 'Controls angle and reach of your flat boxes.',         'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'boxHandle2',             'label' => 'Second Box Handle (Optional)', 'required' => false, 'icon' => 'handle',    'hint' => 'Match with your second flat box.',                     'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead',              'label' => 'Angle Head',                   'required' => true,  'icon' => 'anglehead', 'hint' => 'Finishes inside angles where walls meet.',             'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'angleHead2',             'label' => 'Second Angle Head (Optional)', 'required' => false, 'icon' => 'anglehead', 'hint' => 'Two angle heads speeds up angle work.',                'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'cornerApplicator',       'label' => 'Corner Applicator',            'required' => true,  'icon' => 'cornerbox', 'hint' => 'Applies mud to outside and inside corners.',           'allowedFamilies' => [ 'corner_box' ] ],
				[ 'id' => 'angleHeadHandle',        'label' => 'Angle Head Handle',            'required' => true,  'icon' => 'handle',    'hint' => 'Extends reach for ceiling angle work.',               'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'rollerHandle',           'label' => 'Roller Handle',                'required' => true,  'icon' => 'roller',    'hint' => 'Used with the inside corner roller.',                 'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'cornerApplicatorHandle', 'label' => 'Corner Applicator Handle',     'required' => true,  'icon' => 'handle',    'hint' => 'Leverage when applying corner mud.',                  'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [
				'TapeTech® EasyClean® Loading Pump',
				'TapeTech® Filler Adapter',
				'TapeTech® Inside Corner Roller',
				'TapeTech® Corner Finisher Adapter',
			],
		],

		// ── TapeTech Taping Set ────────────────────────────────────────────────
		[
			'id'             => 'tapetech-taping',
			'brandKey'       => 'tapetech',
			'brandLabel'     => 'TapeTech',
			'scope'          => 'taping',
			'name'           => 'TapeTech® Custom Taping Set',
			'description'    => 'Choose your own taper, angle heads, and handles.',
			'tagline'        => 'Tape faster. Built around your taper workflow.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Taping specialists',
			'slots'          => [
				[ 'id' => 'taper',           'label' => 'Automatic Taper',   'required' => true, 'icon' => 'taper',    'hint' => 'The core of the taping set.',           'allowedFamilies' => [ 'automatic_taper' ] ],
				[ 'id' => 'angleHead',       'label' => 'Angle Head',        'required' => true, 'icon' => 'anglehead','hint' => 'Finishes inside angles.',               'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'angleHeadHandle', 'label' => 'Angle Head Handle', 'required' => true, 'icon' => 'handle',   'hint' => 'Extends reach for angle work.',         'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'rollerHandle',    'label' => 'Roller Handle',     'required' => true, 'icon' => 'roller',   'hint' => 'For use with the inside corner roller.', 'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [
				'TapeTech® EasyClean® Loading Pump',
				'TapeTech® Gooseneck Adapter',
				'TapeTech® Inside Corner Roller',
				'TapeTech® Corner Finisher Adapter',
			],
		],

		// ── Columbia Full Set ──────────────────────────────────────────────────
		[
			'id'             => 'columbia-full',
			'brandKey'       => 'columbia-taping-tools',
			'brandLabel'     => 'Columbia Taping Tools',
			'scope'          => 'full',
			'name'           => 'Columbia Custom Full Set',
			'description'    => 'Choose your own taper, flat boxes, angle heads, corner box, and handles.',
			'tagline'        => 'Columbia quality from taping to finishing — every tool, your choice.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Full-time automatic taping crews',
			'slots'          => [
				[ 'id' => 'taper',           'label' => 'Automatic Taper',              'required' => true,  'icon' => 'taper',    'hint' => 'Applies tape and mud simultaneously.',    'allowedFamilies' => [ 'automatic_taper' ] ],
				[ 'id' => 'flatBox',         'label' => 'Flat Box #1',                  'required' => true,  'icon' => 'flatbox',  'hint' => 'Choose your primary flat box size.',      'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',        'label' => 'Flat Box #2 (Optional)',        'required' => false, 'icon' => 'flatbox',  'hint' => 'Add a second flat box for two coats.',    'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle',       'label' => 'Flat Box Handle',              'required' => true,  'icon' => 'handle',   'hint' => 'Controls box angle and reach.',           'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead',       'label' => 'Angle Head',                   'required' => true,  'icon' => 'anglehead','hint' => 'Finishes wall-ceiling angles.',           'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'angleHead2',      'label' => 'Second Angle Head (Optional)', 'required' => false, 'icon' => 'anglehead','hint' => 'Speed up angle work with two heads.',     'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'cornerBox',       'label' => 'Corner Box',                   'required' => true,  'icon' => 'cornerbox','hint' => 'Finishes drywall corner joints.',         'allowedFamilies' => [ 'corner_box' ] ],
				[ 'id' => 'angleHeadHandle', 'label' => 'Angle Head Handle',            'required' => true,  'icon' => 'handle',   'hint' => 'Extension for angle head reach.',         'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'rollerHandle',    'label' => 'Roller Handle',                'required' => true,  'icon' => 'roller',   'hint' => 'For use with inside corner roller.',      'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'cornerBoxHandle', 'label' => 'Corner Box Handle',            'required' => true,  'icon' => 'handle',   'hint' => 'Provides reach for corner box work.',     'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [
				'Columbia Hot Mud Pump',
				'Columbia Box Filler',
				'Columbia Gooseneck',
				'Columbia Inside Corner Roller',
				'Columbia Angle Head Adapter',
			],
		],

		// ── Columbia Finishing Set ─────────────────────────────────────────────
		[
			'id'             => 'columbia-finishing',
			'brandKey'       => 'columbia-taping-tools',
			'brandLabel'     => 'Columbia Taping Tools',
			'scope'          => 'finishing',
			'name'           => 'Columbia Custom Finishing Set',
			'description'    => 'Choose your own flat boxes, angle heads, corner box, and handles.',
			'tagline'        => 'Dedicated finishing power — no taper needed.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Dedicated finishing crews',
			'slots'          => [
				[ 'id' => 'flatBox',         'label' => 'Flat Box #1',                  'required' => true,  'icon' => 'flatbox',  'hint' => 'Choose your primary flat box size.',  'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',        'label' => 'Flat Box #2 (Optional)',        'required' => false, 'icon' => 'flatbox',  'hint' => 'Add a second flat box for two coats.','allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle',       'label' => 'Flat Box Handle',              'required' => true,  'icon' => 'handle',   'hint' => 'Controls box angle and reach.',       'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead',       'label' => 'Angle Head',                   'required' => true,  'icon' => 'anglehead','hint' => 'Finishes wall-ceiling angles.',       'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'angleHead2',      'label' => 'Second Angle Head (Optional)', 'required' => false, 'icon' => 'anglehead','hint' => 'Speed up angle work with two heads.', 'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'cornerBox',       'label' => 'Corner Box',                   'required' => true,  'icon' => 'cornerbox','hint' => 'Finishes drywall corner joints.',     'allowedFamilies' => [ 'corner_box' ] ],
				[ 'id' => 'angleHeadHandle', 'label' => 'Angle Head Handle',            'required' => true,  'icon' => 'handle',   'hint' => 'Extension for angle head reach.',     'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'rollerHandle',    'label' => 'Roller Handle',                'required' => true,  'icon' => 'roller',   'hint' => 'For use with inside corner roller.',  'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'cornerBoxHandle', 'label' => 'Corner Box Handle',            'required' => true,  'icon' => 'handle',   'hint' => 'Provides reach for corner box work.', 'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [
				'Columbia Hot Mud Pump',
				'Columbia Box Filler',
				'Columbia Inside Corner Roller',
				'Columbia Angle Head Adapter',
			],
		],

		// ── Columbia Taping Set ────────────────────────────────────────────────
		[
			'id'             => 'columbia-taping',
			'brandKey'       => 'columbia-taping-tools',
			'brandLabel'     => 'Columbia Taping Tools',
			'scope'          => 'taping',
			'name'           => 'Columbia Custom Taping Set',
			'description'    => 'Choose your own taper, angle heads, and handles.',
			'tagline'        => 'Columbia taping power — tape faster with the right tools.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Taping specialists',
			'slots'          => [
				[ 'id' => 'taper',           'label' => 'Automatic Taper',   'required' => true, 'icon' => 'taper',    'hint' => 'The core of the set.',             'allowedFamilies' => [ 'automatic_taper' ] ],
				[ 'id' => 'angleHead',       'label' => 'Angle Head',        'required' => true, 'icon' => 'anglehead','hint' => 'Finishes wall-ceiling angles.',     'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'angleHeadHandle', 'label' => 'Angle Head Handle', 'required' => true, 'icon' => 'handle',   'hint' => 'Extension for angle head reach.',   'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'rollerHandle',    'label' => 'Roller Handle',     'required' => true, 'icon' => 'roller',   'hint' => 'For inside corner roller work.',    'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [
				'Columbia Hot Mud Pump',
				'Columbia Gooseneck',
				'Columbia Inside Corner Roller',
				'Columbia Angle Head Adapter',
			],
		],

		// ── Columbia Flat Box Set ──────────────────────────────────────────────
		[
			'id'             => 'columbia-flatbox',
			'brandKey'       => 'columbia-taping-tools',
			'brandLabel'     => 'Columbia Taping Tools',
			'scope'          => 'flatbox',
			'name'           => 'Columbia Custom Flat Box Set',
			'description'    => 'Choose your own flat boxes and flat box handle.',
			'tagline'        => 'Flat box focused — for crews that already have tapers and angle heads.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Flat box upgrade or additions',
			'slots'          => [
				[ 'id' => 'flatBox',   'label' => 'Flat Box #1',            'required' => true,  'icon' => 'flatbox', 'hint' => 'Choose your primary flat box.',  'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',  'label' => 'Flat Box #2 (Optional)', 'required' => false, 'icon' => 'flatbox', 'hint' => 'Add a second flat box size.',     'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle', 'label' => 'Flat Box Handle',        'required' => true,  'icon' => 'handle',  'hint' => 'Controls box angle and reach.',  'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [
				'Columbia Hot Mud Pump',
				'Columbia Box Filler',
			],
		],

		// ── Level 5 Full Set ───────────────────────────────────────────────────
		[
			'id'             => 'level5-full',
			'brandKey'       => 'level5',
			'brandLabel'     => 'Level 5',
			'scope'          => 'full',
			'name'           => 'Level 5 Custom Full Set',
			'description'    => 'Choose your own flat boxes, angle heads, corner tools, and handles.',
			'tagline'        => 'Precision finishing, fully configured — the Level 5 way.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Full finishing crews',
			'slots'          => [
				[ 'id' => 'flatBox',   'label' => 'Flat Box #1', 'required' => true,  'icon' => 'flatbox',   'hint' => 'Choose your primary flat box.',      'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',  'label' => 'Flat Box #2', 'required' => false, 'icon' => 'flatbox',   'hint' => 'Add a second flat box size.',          'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle', 'label' => 'Box Handle',  'required' => true,  'icon' => 'handle',    'hint' => 'Controls flat box angle and reach.',  'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead', 'label' => 'Angle Head',  'required' => true,  'icon' => 'anglehead', 'hint' => 'Finishes inside angles.',              'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'cornerBox', 'label' => 'Corner Tool', 'required' => true,  'icon' => 'cornerbox', 'hint' => 'Finishes corner joints.',              'allowedFamilies' => [ 'corner_box' ] ],
			],
			'alwaysIncluded' => [
				'Level 5 Pump & Filler',
				'Level 5 Inside Corner Roller',
			],
		],

		// ── Level 5 Finishing Set ──────────────────────────────────────────────
		[
			'id'             => 'level5-finishing',
			'brandKey'       => 'level5',
			'brandLabel'     => 'Level 5',
			'scope'          => 'finishing',
			'name'           => 'Level 5 Custom Finishing Set',
			'description'    => 'Choose your own flat boxes, angle heads, and handles.',
			'tagline'        => 'Everything you need for Level 5 finishing work.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Level 5 finishing specialists',
			'slots'          => [
				[ 'id' => 'flatBox',   'label' => 'Flat Box #1', 'required' => true,  'icon' => 'flatbox',   'hint' => 'Primary flat box selection.',        'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',  'label' => 'Flat Box #2', 'required' => false, 'icon' => 'flatbox',   'hint' => 'Optional second flat box.',           'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle', 'label' => 'Box Handle',  'required' => true,  'icon' => 'handle',    'hint' => 'Controls flat box angle and reach.', 'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead', 'label' => 'Angle Head',  'required' => true,  'icon' => 'anglehead', 'hint' => 'Finishes inside angles.',              'allowedFamilies' => [ 'angle_head' ] ],
			],
			'alwaysIncluded' => [
				'Level 5 Pump & Filler',
				'Level 5 Inside Corner Roller',
			],
		],

		// ── Level 5 Flat Box Set ───────────────────────────────────────────────
		[
			'id'             => 'level5-flatbox',
			'brandKey'       => 'level5',
			'brandLabel'     => 'Level 5',
			'scope'          => 'flatbox',
			'name'           => 'Level 5 Custom Flat Box Set',
			'description'    => 'Choose your own flat boxes and handles.',
			'tagline'        => 'Flat box focus — upgrade or expand your Level 5 flat box collection.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Flat box upgrades',
			'slots'          => [
				[ 'id' => 'flatBox',   'label' => 'Flat Box #1', 'required' => true,  'icon' => 'flatbox', 'hint' => 'Choose your primary flat box.',      'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',  'label' => 'Flat Box #2', 'required' => false, 'icon' => 'flatbox', 'hint' => 'Optional second flat box.',           'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle', 'label' => 'Box Handle',  'required' => true,  'icon' => 'handle',  'hint' => 'Controls flat box angle and reach.', 'allowedFamilies' => [ 'handle' ] ],
			],
			'alwaysIncluded' => [ 'Level 5 Pump & Filler' ],
		],

		// ── Asgard Full Set ────────────────────────────────────────────────────
		[
			'id'             => 'asgard-full',
			'brandKey'       => 'asgard',
			'brandLabel'     => 'Asgard',
			'scope'          => 'full',
			'name'           => 'Asgard Custom Full Set',
			'description'    => 'Choose your own taper, flat boxes, angle heads, corner tools, and handles.',
			'tagline'        => 'Build the ultimate Asgard setup from taping to finishing.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Full-time automatic taping crews',
			'slots'          => [
				[ 'id' => 'taper',     'label' => 'Automatic Taper', 'required' => true,  'icon' => 'taper',    'hint' => 'Core taping tool.',                  'allowedFamilies' => [ 'automatic_taper' ] ],
				[ 'id' => 'flatBox',   'label' => 'Flat Box #1',     'required' => true,  'icon' => 'flatbox',  'hint' => 'Primary flat box selection.',         'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',  'label' => 'Flat Box #2',     'required' => false, 'icon' => 'flatbox',  'hint' => 'Optional second flat box.',            'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle', 'label' => 'Box Handle',      'required' => true,  'icon' => 'handle',   'hint' => 'Controls flat box angle and reach.',  'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead', 'label' => 'Angle Head',      'required' => true,  'icon' => 'anglehead','hint' => 'Finishes inside angles.',              'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'cornerBox', 'label' => 'Corner Tool',     'required' => true,  'icon' => 'cornerbox','hint' => 'Finishes corner joints.',              'allowedFamilies' => [ 'corner_box' ] ],
			],
			'alwaysIncluded' => [
				'Asgard Loading Pump',
				'Asgard Inside Corner Roller',
			],
		],

		// ── Asgard Finishing Set ───────────────────────────────────────────────
		[
			'id'             => 'asgard-finishing',
			'brandKey'       => 'asgard',
			'brandLabel'     => 'Asgard',
			'scope'          => 'finishing',
			'name'           => 'Asgard Custom Finishing Set',
			'description'    => 'Choose your own flat boxes, angle heads, corner tools, and handles.',
			'tagline'        => 'Finishing-focused Asgard configuration.',
			'shipping'       => 'FREE',
			'savingsLabel'   => '5% off',
			'recommendedFor' => 'Dedicated finishing crews',
			'slots'          => [
				[ 'id' => 'flatBox',   'label' => 'Flat Box #1', 'required' => true,  'icon' => 'flatbox',  'hint' => 'Primary flat box.',           'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'flatBox2',  'label' => 'Flat Box #2', 'required' => false, 'icon' => 'flatbox',  'hint' => 'Optional second flat box.',    'allowedFamilies' => [ 'flat_box' ] ],
				[ 'id' => 'boxHandle', 'label' => 'Box Handle',  'required' => true,  'icon' => 'handle',   'hint' => 'Controls flat box reach.',     'allowedFamilies' => [ 'handle' ] ],
				[ 'id' => 'angleHead', 'label' => 'Angle Head',  'required' => true,  'icon' => 'anglehead','hint' => 'Finishes inside angles.',       'allowedFamilies' => [ 'angle_head' ] ],
				[ 'id' => 'cornerBox', 'label' => 'Corner Tool', 'required' => true,  'icon' => 'cornerbox','hint' => 'Finishes corner joints.',       'allowedFamilies' => [ 'corner_box' ] ],
			],
			'alwaysIncluded' => [
				'Asgard Loading Pump',
				'Asgard Inside Corner Roller',
			],
		],
	];

	/**
	 * Seed the wp_options store with SEED_TEMPLATES on first activation.
	 * Safe to call multiple times — only runs once per seed version.
	 */
	public static function maybe_seed(): void {
		$current = (int) get_option( self::SEED_VERSION_KEY, 0 );
		if ( $current >= self::SEED_VERSION ) {
			return;
		}
		update_option( self::OPTION_KEY, self::SEED_TEMPLATES, false );
		update_option( self::SEED_VERSION_KEY, self::SEED_VERSION, true );
	}

	/**
	 * Return all templates from the options store, falling back to SEED_TEMPLATES.
	 *
	 * @return array[]
	 */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_KEY );
		return is_array( $stored ) && ! empty( $stored ) ? $stored : self::SEED_TEMPLATES;
	}

	/**
	 * Return a single template by ID, or null if not found.
	 *
	 * @param string $id
	 * @return array|null
	 */
	public static function get_by_id( string $id ): ?array {
		foreach ( self::get_all() as $template ) {
			if ( ( $template['id'] ?? '' ) === $id ) {
				return $template;
			}
		}
		return null;
	}
}
