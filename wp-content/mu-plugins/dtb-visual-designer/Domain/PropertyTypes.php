<?php
/**
 * Domain — PropertyTypes: bounded vocabulary for editable visual properties.
 *
 * Every registered property must declare a `type` from this list. The editor
 * generates controls from `type` + constraints; the resolver/sanitizer
 * enforces bounds from the same source. No property type allows arbitrary
 * CSS, HTML, JS, or executable content — see dtb_vd_sanitize_value().
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * All allowed property value types.
 *
 * @return string[]
 */
function dtb_vd_property_types(): array {
	return [
		'enum',          // one of a fixed allowed_values list
		'token_ref',      // reference to a registered design-token id
		'number',         // bounded numeric (min/max/step)
		'spacing',        // bounded numeric + unit, resolved against spacing scale
		'boolean',
		'color',          // strict #hex color literal — token repository-default values only
		'color_ref',      // reference to a registered color token (not raw hex)
		'text_align',     // enum shorthand: left|center|right|justify
		'aspect_ratio',   // enum shorthand: 1:1|4:3|16:9|3:2|auto
		'media_ref',      // WordPress media library attachment ID only
		'order_index',    // bounded integer used only by reorderable containers
	];
}

/**
 * Allowed responsive breakpoint keys. Mirrors the frontend's own breakpoint
 * system (see frontend/src/styles) rather than inventing new ones.
 *
 * @return string[]
 */
function dtb_vd_breakpoints(): array {
	return [ 'base', 'desktop', 'tablet', 'mobile' ];
}

/**
 * Allowed component semantic kinds. Commerce-critical components can never be
 * removed, hidden, or moved outside their allowed parents by the editor.
 *
 * @return string[]
 */
function dtb_vd_component_kinds(): array {
	return [ 'structural', 'content', 'commerce_critical', 'presentation' ];
}

/**
 * Allowed surface categories (used for grouping in the surface navigator).
 *
 * @return string[]
 */
function dtb_vd_surface_categories(): array {
	return [
		'global',
		'commerce',
		'catalog',
		'account',
		'repair-service',
		'calculators',
		'search',
		'system-state',
	];
}
