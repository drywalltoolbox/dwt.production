<?php
/**
 * Application — ImportExportService: controlled, sanitized configuration
 * portability. Export never includes credentials, customer/order data,
 * preview tokens, or audit history. Import always lands in the draft, never
 * directly in published state, and only accepts values that pass the same
 * per-property sanitizer used everywhere else.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Export the currently published configuration as a portable document.
 *
 * @param string $draft_key
 * @return array
 */
function dtb_vd_export_published( string $draft_key = DTB_VD_DEFAULT_DRAFT_KEY ): array {
	$published = dtb_vd_get_published_revision( $draft_key );

	return [
		'exportFormat'  => 'dtb-visual-designer',
		'schemaVersion' => DTB_VD_SCHEMA_VERSION,
		'exportedAt'    => current_time( 'mysql', true ),
		'sourceRevisionId' => $published['id'],
		'payload'       => $published['payload'],
	];
}

/**
 * Validate and stage an imported document into the draft (never published
 * state). Every token/property value is re-validated against the live
 * registry exactly as if it had come from the editor UI; anything that does
 * not validate is dropped and reported, not silently coerced.
 *
 * @param array $document Decoded import document.
 * @param string $draft_key
 * @param int    $editor_id
 * @return array{success:bool, errors:string[], draft:array|null}
 */
function dtb_vd_import_document( array $document, string $draft_key, int $editor_id ): array {
	$errors = [];

	if ( ( $document['exportFormat'] ?? '' ) !== 'dtb-visual-designer' ) {
		return [ 'success' => false, 'errors' => [ 'unrecognized_format' ], 'draft' => null ];
	}

	$schema_version = (int) ( $document['schemaVersion'] ?? 0 );
	if ( $schema_version !== DTB_VD_SCHEMA_VERSION ) {
		return [ 'success' => false, 'errors' => [ 'unsupported_schema_version' ], 'draft' => null ];
	}

	$payload = $document['payload'] ?? null;
	if ( ! is_array( $payload ) || ! dtb_vd_structurally_bounded( $payload ) ) {
		return [ 'success' => false, 'errors' => [ 'malformed_or_oversized_payload' ], 'draft' => null ];
	}

	$sanitized = [ 'tokens' => [], 'surfaces' => [] ];

	foreach ( (array) ( $payload['tokens'] ?? [] ) as $token_id => $value ) {
		$token_def = dtb_vd_get_token( (string) $token_id );
		if ( ! $token_def ) {
			$errors[] = "unknown_token:{$token_id}";
			continue;
		}
		[ $valid, $sanitized_value ] = dtb_vd_sanitize_value( $token_def, $value );
		if ( ! $valid ) {
			$errors[] = "invalid_token_value:{$token_id}";
			continue;
		}
		$sanitized['tokens'][ $token_id ] = $sanitized_value;
	}

	foreach ( (array) ( $payload['surfaces'] ?? [] ) as $surface_id => $surface_payload ) {
		$surface_def = dtb_vd_get_surface( (string) $surface_id );
		if ( ! $surface_def ) {
			$errors[] = "unknown_surface:{$surface_id}";
			continue;
		}

		foreach ( (array) ( $surface_payload['components'] ?? [] ) as $component_id => $component_payload ) {
			$component_def = dtb_vd_get_component( (string) $surface_id, (string) $component_id );
			if ( ! $component_def ) {
				$errors[] = "unknown_component:{$surface_id}.{$component_id}";
				continue;
			}

			$clean_component = [];

			foreach ( (array) ( $component_payload['properties'] ?? [] ) as $property_id => $value ) {
				$property_schema = dtb_vd_get_property_schema( (string) $surface_id, (string) $component_id, (string) $property_id );
				if ( ! $property_schema ) {
					$errors[] = "unknown_property:{$surface_id}.{$component_id}.{$property_id}";
					continue;
				}
				[ $valid, $sanitized_value ] = dtb_vd_sanitize_value( $property_schema, $value );
				if ( $valid ) {
					$clean_component['properties'][ $property_id ] = $sanitized_value;
				} else {
					$errors[] = "invalid_value:{$surface_id}.{$component_id}.{$property_id}";
				}
			}

			if ( ! empty( $component_payload['hidden'] ) && $component_def['visibility_toggle'] && 'commerce_critical' !== $component_def['kind'] ) {
				$clean_component['hidden'] = true;
			}

			if ( $clean_component ) {
				$sanitized['surfaces'][ $surface_id ]['components'][ $component_id ] = $clean_component;
			}
		}
	}

	$current = dtb_vd_get_or_create_draft( $draft_key );
	[ $success ] = dtb_vd_update_draft( $draft_key, $sanitized, $current['revision_seq'], $editor_id );

	if ( $success ) {
		dtb_vd_audit( 'design.import.applied', [ 'draft_key' => $draft_key, 'errors' => count( $errors ) ] );
	} else {
		dtb_vd_audit( 'design.import.rejected', [ 'draft_key' => $draft_key, 'reason' => 'concurrency' ] );
	}

	return [ 'success' => $success, 'errors' => $errors, 'draft' => dtb_vd_get_or_create_draft( $draft_key ) ];
}
