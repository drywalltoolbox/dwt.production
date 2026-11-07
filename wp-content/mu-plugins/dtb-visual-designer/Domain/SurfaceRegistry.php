<?php
/**
 * Domain — SurfaceRegistry: declarative registered-surface/component model.
 *
 * This is the ONLY way a React surface or component becomes editable. There
 * is no DOM discovery, no CSS-selector inference, and no identity derived
 * from array index or generated DOM id. Registration happens in PHP
 * (Registrations/SurfaceDefinitions.php) and is mirrored on the frontend by
 * a matching `data-dtb-surface` / `data-dtb-component` id placed explicitly
 * by the component author (see frontend/src/designer/useEditableComponent.js).
 *
 * Unknown surface/component/property ids are always treated as "not
 * editable" and fail closed to repository defaults — see ConfigResolver.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$GLOBALS['_dtb_vd_surfaces'] = [];

/**
 * Register a storefront surface.
 *
 * Required keys:
 *   id            (string) stable id, e.g. 'global-header'
 *   name          (string) human-readable name
 *   route         (string) primary frontend route/entry point (informational)
 *   category      (string) one of dtb_vd_surface_categories()
 *   components    (array<string, array>) keyed by stable component id, see below
 *
 * Optional keys:
 *   description   (string)
 *   schema_version (int) defaults to DTB_VD_SCHEMA_VERSION
 *   commerce_critical (bool)
 *
 * Each component definition supports:
 *   name            (string)
 *   type            (string) free-form label, e.g. 'header' | 'rail' | 'form-region'
 *   kind            (string) one of dtb_vd_component_kinds()
 *   parent          (string|null) stable id of parent component within the same surface
 *   properties      (array<string, array>) keyed by property id -> schema (see PropertyTypes)
 *   responsive      (bool) whether properties may carry per-breakpoint overrides
 *   reorder         (array|null) {allowed_siblings, movable, fixed} — only present on
 *                    containers whose children may be reordered
 *   visibility_toggle (bool) whether the component may be hidden
 *   allowed_children (string[]|null) restricts nesting for structural containers
 */
function dtb_vd_register_surface( array $definition ): void {
	$id = (string) ( $definition['id'] ?? '' );

	if ( '' === $id || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $id ) ) {
		error_log( '[DTB Visual Designer] Rejected surface registration with invalid id.' );
		return;
	}

	if ( isset( $GLOBALS['_dtb_vd_surfaces'][ $id ] ) ) {
		error_log( "[DTB Visual Designer] Duplicate surface id '{$id}' ignored." );
		return;
	}

	$category = (string) ( $definition['category'] ?? '' );
	if ( ! in_array( $category, dtb_vd_surface_categories(), true ) ) {
		error_log( "[DTB Visual Designer] Surface '{$id}' has unknown category '{$category}'." );
		return;
	}

	$components = [];
	foreach ( (array) ( $definition['components'] ?? [] ) as $component_id => $component ) {
		if ( ! is_string( $component_id ) || ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $component_id ) ) {
			continue;
		}
		$kind = (string) ( $component['kind'] ?? 'presentation' );
		if ( ! in_array( $kind, dtb_vd_component_kinds(), true ) ) {
			$kind = 'presentation';
		}
		$components[ $component_id ] = wp_parse_args( $component, [
			'name'              => $component_id,
			'type'              => 'component',
			'kind'              => $kind,
			'parent'            => null,
			'properties'        => [],
			'responsive'        => false,
			'reorder'           => null,
			'visibility_toggle' => false,
			'allowed_children'  => null,
		] );
	}

	$GLOBALS['_dtb_vd_surfaces'][ $id ] = wp_parse_args( $definition, [
		'id'                => $id,
		'name'              => $id,
		'route'             => '',
		'category'          => $category,
		'description'       => '',
		'schema_version'    => DTB_VD_SCHEMA_VERSION,
		'commerce_critical' => false,
		'components'        => $components,
	] );

	$GLOBALS['_dtb_vd_surfaces'][ $id ]['components'] = $components;
}

/**
 * Get every registered surface.
 *
 * @return array<string, array>
 */
function dtb_vd_get_surfaces(): array {
	return $GLOBALS['_dtb_vd_surfaces'] ?? [];
}

/**
 * Get one registered surface by id, or null if unknown (fail closed).
 *
 * @param string $surface_id
 * @return array|null
 */
function dtb_vd_get_surface( string $surface_id ): ?array {
	return $GLOBALS['_dtb_vd_surfaces'][ $surface_id ] ?? null;
}

/**
 * Get one registered component within a surface, or null if unknown.
 *
 * @param string $surface_id
 * @param string $component_id
 * @return array|null
 */
function dtb_vd_get_component( string $surface_id, string $component_id ): ?array {
	$surface = dtb_vd_get_surface( $surface_id );
	return $surface['components'][ $component_id ] ?? null;
}

/**
 * Get one registered property schema for a component, or null if unknown.
 *
 * @param string $surface_id
 * @param string $component_id
 * @param string $property_id
 * @return array|null
 */
function dtb_vd_get_property_schema( string $surface_id, string $component_id, string $property_id ): ?array {
	$component = dtb_vd_get_component( $surface_id, $component_id );
	return $component['properties'][ $property_id ] ?? null;
}
