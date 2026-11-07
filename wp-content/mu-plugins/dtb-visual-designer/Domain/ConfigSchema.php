<?php
/**
 * Domain — ConfigSchema: schema version + bounded value sanitization.
 *
 * This is the single sanitizer used by drafts, revisions, preview, and import
 * so there is exactly one place that decides what a "safe visual value" is.
 * Unknown types, out-of-range values, and any string that could carry CSS/
 * HTML/JS are rejected (fail closed to null so the resolver falls back to the
 * inherited/default value rather than persisting something unsafe).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Bump only when the *shape* of a persisted payload changes in a way that
// requires migration. Additive registry changes (new surfaces/properties) do
// not require a bump — see dtb_vd_resolve_config()'s unknown-property handling.
define( 'DTB_VD_SCHEMA_VERSION', 1 );

// Bounded persistence limits. Enforced before anything is written.
define( 'DTB_VD_MAX_PAYLOAD_BYTES', 262144 ); // 256 KB per draft/revision payload.
define( 'DTB_VD_MAX_STRING_LEN', 200 );
define( 'DTB_VD_MAX_ARRAY_ITEMS', 100 );
define( 'DTB_VD_MAX_NEST_DEPTH', 6 );
define( 'DTB_VD_MAX_NOTE_LEN', 500 );

/**
 * Sanitize a single property value against its registered schema definition.
 *
 * @param array $property_schema Registered property definition (type, allowed_values, min, max, enum...).
 * @param mixed $value           Raw candidate value.
 * @return array{0: bool, 1: mixed} [ is_valid, sanitized_value_or_null ]
 */
function dtb_vd_sanitize_value( array $property_schema, $value ): array {
	$type = (string) ( $property_schema['type'] ?? '' );

	if ( ! in_array( $type, dtb_vd_property_types(), true ) ) {
		return [ false, null ];
	}

	switch ( $type ) {
		case 'boolean':
			return [ true, (bool) $value ];

		case 'order_index':
		case 'number':
			if ( ! is_numeric( $value ) ) {
				return [ false, null ];
			}
			$num = (float) $value;
			$min = $property_schema['min'] ?? null;
			$max = $property_schema['max'] ?? null;
			if ( null !== $min && $num < (float) $min ) {
				return [ false, null ];
			}
			if ( null !== $max && $num > (float) $max ) {
				return [ false, null ];
			}
			return [ true, $num ];

		case 'spacing':
			if ( ! is_numeric( $value ) ) {
				return [ false, null ];
			}
			$num = (float) $value;
			$min = $property_schema['min'] ?? 0;
			$max = $property_schema['max'] ?? 256;
			if ( $num < (float) $min || $num > (float) $max ) {
				return [ false, null ];
			}
			return [ true, $num ];

		case 'color':
			$hex = (string) $value;
			if ( ! preg_match( '/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $hex ) ) {
				return [ false, null ];
			}
			return [ true, strtolower( $hex ) ];

		case 'aspect_ratio':
			$allowed = [ '1:1', '4:3', '16:9', '3:2', 'auto' ];
			return in_array( $value, $allowed, true ) ? [ true, $value ] : [ false, null ];

		case 'text_align':
			$allowed = [ 'left', 'center', 'right', 'justify' ];
			return in_array( $value, $allowed, true ) ? [ true, $value ] : [ false, null ];

		case 'media_ref':
			$id = absint( $value );
			if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
				return [ false, null ];
			}
			return [ true, $id ];

		case 'token_ref':
		case 'color_ref':
			$id = sanitize_key( (string) $value );
			if ( '' === $id || strlen( $id ) > DTB_VD_MAX_STRING_LEN ) {
				return [ false, null ];
			}
			if ( ! dtb_vd_get_token( $id ) ) {
				return [ false, null ];
			}
			return [ true, $id ];

		case 'enum':
			$allowed = (array) ( $property_schema['allowed_values'] ?? [] );
			$val     = sanitize_key( (string) $value );
			return in_array( $val, $allowed, true ) ? [ true, $val ] : [ false, null ];

		default:
			return [ false, null ];
	}
}

/**
 * Recursively bound an arbitrary decoded-JSON structure (depth, item count,
 * string length) before it is ever considered for further semantic
 * validation. Used by draft/import ingestion as a first structural gate.
 *
 * @param mixed $node
 * @param int   $depth
 * @return bool
 */
function dtb_vd_structurally_bounded( $node, int $depth = 0 ): bool {
	if ( $depth > DTB_VD_MAX_NEST_DEPTH ) {
		return false;
	}

	if ( is_string( $node ) ) {
		return strlen( $node ) <= DTB_VD_MAX_STRING_LEN;
	}

	if ( is_array( $node ) ) {
		if ( count( $node ) > DTB_VD_MAX_ARRAY_ITEMS ) {
			return false;
		}
		foreach ( $node as $child ) {
			if ( ! dtb_vd_structurally_bounded( $child, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	// Scalars (int/float/bool/null) are always bounded.
	return is_scalar( $node ) || null === $node;
}
