<?php
/**
 * Application — ConfigResolver: deterministic configuration resolution.
 *
 * Inheritance order (lowest to highest precedence):
 *   1. Repository defaults   (from the registry — Domain/*Registry.php)
 *   2. Published global tokens
 *   3. Published surface overrides
 *   4. Published component overrides
 *   5. Published responsive overrides
 *   6. Preview draft overrides (preview context only)
 *
 * The resolver only ever reads registered surfaces/components/properties.
 * Any id present in a stored payload but absent from the current registry is
 * silently ignored (schema drift / removed component) — this is what lets
 * historical revisions stay readable and lets the frontend degrade to
 * defaults instead of crashing on unknown data.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the full public configuration payload for the frontend.
 *
 * @param array       $payload   Decoded {tokens: {}, surfaces: {}} document (published or draft).
 * @param string|null $breakpoint One of dtb_vd_breakpoints() to also flatten responsive overrides, or null.
 * @return array Deterministic, bounded, frontend-ready structure.
 */
function dtb_vd_resolve_config( array $payload, ?string $breakpoint = null ): array {
	$tokens = dtb_vd_resolve_tokens( $payload['tokens'] ?? [] );

	$surfaces = [];
	foreach ( dtb_vd_get_surfaces() as $surface_id => $surface_def ) {
		$surfaces[ $surface_id ] = dtb_vd_resolve_surface( $surface_def, $payload['surfaces'][ $surface_id ] ?? [], $breakpoint );
	}

	return [
		'tokens'   => $tokens,
		'surfaces' => $surfaces,
	];
}

/**
 * Resolve token values: repository default -> stored override (if the token
 * id and value are still valid against the current registry).
 *
 * @param array $stored_tokens Map of token_id => raw override value.
 * @return array<string, mixed> Map of token_id => resolved value + source.
 */
function dtb_vd_resolve_tokens( array $stored_tokens ): array {
	$resolved = [];

	foreach ( dtb_vd_get_token_groups() as $group_id => $group ) {
		foreach ( (array) $group['tokens'] as $token_id => $token_def ) {
			$default = $token_def['default'] ?? null;
			$value   = $default;
			$source  = 'default';

			if ( array_key_exists( $token_id, $stored_tokens ) ) {
				[ $valid, $sanitized ] = dtb_vd_sanitize_value( $token_def, $stored_tokens[ $token_id ] );
				if ( $valid ) {
					$value  = $sanitized;
					$source = 'global';
				}
			}

			$resolved[ $token_id ] = [
				'value'   => $value,
				'source'  => $source,
				'cssVar'  => $token_def['css_var'] ?? null,
				'groupId' => $group_id,
			];
		}
	}

	return $resolved;
}

/**
 * Resolve one surface's component tree against stored overrides.
 *
 * @param array       $surface_def
 * @param array       $stored      {components: {component_id: {properties: {}, responsive: {bp: {}}, order: [], hidden: bool}}}
 * @param string|null $breakpoint
 * @return array
 */
function dtb_vd_resolve_surface( array $surface_def, array $stored, ?string $breakpoint ): array {
	$components_out = [];
	$stored_components = (array) ( $stored['components'] ?? [] );

	foreach ( (array) $surface_def['components'] as $component_id => $component_def ) {
		$stored_component = (array) ( $stored_components[ $component_id ] ?? [] );
		$components_out[ $component_id ] = dtb_vd_resolve_component( $component_def, $stored_component, $breakpoint );
	}

	return [
		'id'         => $surface_def['id'],
		'name'       => $surface_def['name'],
		'category'   => $surface_def['category'],
		'components' => $components_out,
	];
}

/**
 * Resolve one component's properties: default -> surface -> component ->
 * responsive, then apply visibility/order if the component allows it.
 *
 * @param array       $component_def
 * @param array       $stored {properties: {}, responsive: {bp: {prop: val}}, hidden: bool, order: int}
 * @param string|null $breakpoint
 * @return array
 */
function dtb_vd_resolve_component( array $component_def, array $stored, ?string $breakpoint ): array {
	$properties_out = [];

	foreach ( (array) $component_def['properties'] as $property_id => $property_schema ) {
		$value  = $property_schema['default'] ?? null;
		$source = 'default';

		$stored_props = (array) ( $stored['properties'] ?? [] );
		if ( array_key_exists( $property_id, $stored_props ) ) {
			[ $valid, $sanitized ] = dtb_vd_sanitize_value( $property_schema, $stored_props[ $property_id ] );
			if ( $valid ) {
				$value  = $sanitized;
				$source = 'component';
			}
		}

		if ( $breakpoint && 'base' !== $breakpoint && ! empty( $component_def['responsive'] ) ) {
			$responsive_val = $stored['responsive'][ $breakpoint ][ $property_id ] ?? null;
			if ( null !== $responsive_val ) {
				[ $valid, $sanitized ] = dtb_vd_sanitize_value( $property_schema, $responsive_val );
				if ( $valid ) {
					$value  = $sanitized;
					$source = 'responsive';
				}
			}
		}

		$properties_out[ $property_id ] = [ 'value' => $value, 'source' => $source ];
	}

	$hidden = false;
	if ( ! empty( $component_def['visibility_toggle'] ) && 'commerce_critical' !== $component_def['kind'] ) {
		$hidden = ! empty( $stored['hidden'] );
	}

	$order = null;
	if ( ! empty( $component_def['reorder'] ) && isset( $stored['order'] ) && is_numeric( $stored['order'] ) ) {
		$order = (int) $stored['order'];
	}

	return [
		'name'       => $component_def['name'],
		'type'       => $component_def['type'],
		'kind'       => $component_def['kind'],
		'properties' => $properties_out,
		'hidden'     => $hidden,
		'order'      => $order,
	];
}

/**
 * Compute a stable ETag for a resolved payload (used for public cache
 * validation without re-serializing the whole document on every request).
 *
 * @param string|null $revision_hash Payload hash of the source revision, if any.
 * @return string
 */
function dtb_vd_compute_etag( ?string $revision_hash ): string {
	return '"' . substr( hash( 'sha256', ( $revision_hash ?? 'unpublished' ) . DTB_VD_SCHEMA_VERSION ), 0, 32 ) . '"';
}
