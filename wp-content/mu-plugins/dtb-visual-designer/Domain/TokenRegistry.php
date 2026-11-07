<?php
/**
 * Domain — TokenRegistry: reusable global design tokens.
 *
 * Tokens are the canonical source for colors, spacing, typography, radii,
 * shadows, density, breakpoints, and content widths. They map 1:1 onto the
 * CSS custom properties already defined in
 * frontend/src/styles/storefront-tokens.css so the designer never invents a
 * second, parallel token system — it only lets an operator override the
 * *value* the frontend already reads through that variable name.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['_dtb_vd_token_groups'] = [];

/**
 * Register a token group.
 *
 * @param array $group {
 *   id          (string) e.g. 'brand-colors'
 *   name        (string)
 *   description (string)
 *   tokens      (array<string, array>) keyed by token id -> {
 *       name          (string)
 *       type          (string) one of dtb_vd_property_types() (usually 'color_ref' target type is n/a — tokens
 *                     themselves use 'number' | 'spacing' | 'enum' | raw color handled via 'color' below)
 *       css_var       (string) the CSS custom property this token controls, e.g. '--dtb-primary'
 *       default       (mixed) repository default value (immutable)
 *       min/max/allowed_values as applicable
 *   }
 * }
 */
function dtb_vd_register_token_group( array $group ): void {
	$id = (string) ( $group['id'] ?? '' );
	if ( '' === $id || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $id ) ) {
		error_log( '[DTB Visual Designer] Rejected token group with invalid id.' );
		return;
	}
	if ( isset( $GLOBALS['_dtb_vd_token_groups'][ $id ] ) ) {
		error_log( "[DTB Visual Designer] Duplicate token group '{$id}' ignored." );
		return;
	}

	$GLOBALS['_dtb_vd_token_groups'][ $id ] = wp_parse_args( $group, [
		'id'          => $id,
		'name'        => $id,
		'description' => '',
		'tokens'      => [],
	] );
}

/**
 * All registered token groups.
 *
 * @return array<string, array>
 */
function dtb_vd_get_token_groups(): array {
	return $GLOBALS['_dtb_vd_token_groups'] ?? [];
}

/**
 * Find a single token definition by id across all groups. Token ids are
 * globally unique (namespaced by convention, e.g. `color-brand-primary`).
 *
 * @param string $token_id
 * @return array|null {group_id, token_id, ...token definition} or null.
 */
function dtb_vd_get_token( string $token_id ): ?array {
	foreach ( dtb_vd_get_token_groups() as $group_id => $group ) {
		if ( isset( $group['tokens'][ $token_id ] ) ) {
			return array_merge( $group['tokens'][ $token_id ], [
				'group_id' => $group_id,
				'token_id' => $token_id,
			] );
		}
	}
	return null;
}
