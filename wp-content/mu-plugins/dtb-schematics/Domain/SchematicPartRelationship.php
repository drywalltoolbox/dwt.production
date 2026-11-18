<?php
/**
 * DTB Schematics — SchematicPartRelationship value object.
 *
 * Records the relationship between a schematic part and a WooCommerce
 * product/variation identity. WooCommerce remains authoritative for the
 * product itself; this module only owns the relationship and its
 * resolution provenance.
 *
 * Resolution order (see Application/ResolveSchematicPartOccurrences.php):
 *   1. explicit WooCommerce product/variation ID;
 *   2. exact SKU;
 *   3. exact brand + protected manufacturer part number;
 *   4. unique same-brand normalized SKU (formatting-only alias);
 *   5. explicit compatibility relationship;
 *   6. unresolved.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_PART_RESOLUTION_EXPLICIT_ID    = 'explicit_id';
const DTB_SCHEMATIC_PART_RESOLUTION_EXACT_SKU      = 'exact_sku';
const DTB_SCHEMATIC_PART_RESOLUTION_BRAND_MPN      = 'brand_and_mpn';
const DTB_SCHEMATIC_PART_RESOLUTION_NORMALIZED_SKU = 'normalized_sku';
const DTB_SCHEMATIC_PART_RESOLUTION_COMPATIBILITY  = 'compatibility';
const DTB_SCHEMATIC_PART_RESOLUTION_UNRESOLVED     = 'unresolved';

const DTB_SCHEMATIC_PART_STATE_RESOLVED   = 'resolved';
const DTB_SCHEMATIC_PART_STATE_UNRESOLVED = 'unresolved';
const DTB_SCHEMATIC_PART_STATE_NOT_SOLD   = 'not_sold';

/**
 * @param array $data {
 *     @type string $part_ref           Canonical part reference (stable within the schematic).
 *     @type string $mpn                Manufacturer part number/display identifier from source.
 *     @type string $sku                Source/WooCommerce SKU, if known.
 *     @type string $brand              Brand name.
 *     @type string $title              Customer-facing title.
 *     @type int    $product_id         Resolved WooCommerce product/variation ID, 0 if unresolved.
 *     @type string $resolution_method  One of the DTB_SCHEMATIC_PART_RESOLUTION_* constants.
 *     @type string $resolution_state   One of the DTB_SCHEMATIC_PART_STATE_* constants.
 *     @type int    $occurrence_count   Number of hotspot occurrences referencing this part.
 * }
 */
function dtb_schematic_part_relationship_make( array $data ): array {
	$resolution_methods = [
		DTB_SCHEMATIC_PART_RESOLUTION_EXPLICIT_ID,
		DTB_SCHEMATIC_PART_RESOLUTION_EXACT_SKU,
		DTB_SCHEMATIC_PART_RESOLUTION_BRAND_MPN,
		DTB_SCHEMATIC_PART_RESOLUTION_NORMALIZED_SKU,
		DTB_SCHEMATIC_PART_RESOLUTION_COMPATIBILITY,
		DTB_SCHEMATIC_PART_RESOLUTION_UNRESOLVED,
	];
	$resolution_states = [
		DTB_SCHEMATIC_PART_STATE_RESOLVED,
		DTB_SCHEMATIC_PART_STATE_UNRESOLVED,
		DTB_SCHEMATIC_PART_STATE_NOT_SOLD,
	];

	$method = (string) ( $data['resolution_method'] ?? DTB_SCHEMATIC_PART_RESOLUTION_UNRESOLVED );
	if ( ! in_array( $method, $resolution_methods, true ) ) {
		$method = DTB_SCHEMATIC_PART_RESOLUTION_UNRESOLVED;
	}

	$state = (string) ( $data['resolution_state'] ?? DTB_SCHEMATIC_PART_STATE_UNRESOLVED );
	if ( ! in_array( $state, $resolution_states, true ) ) {
		$state = DTB_SCHEMATIC_PART_STATE_UNRESOLVED;
	}

	return [
		'part_ref'          => sanitize_key( (string) ( $data['part_ref'] ?? '' ) ),
		'mpn'               => sanitize_text_field( (string) ( $data['mpn'] ?? '' ) ),
		'sku'               => sanitize_text_field( (string) ( $data['sku'] ?? '' ) ),
		'brand'             => sanitize_text_field( (string) ( $data['brand'] ?? '' ) ),
		'title'             => sanitize_text_field( (string) ( $data['title'] ?? '' ) ),
		'product_id'        => max( 0, (int) ( $data['product_id'] ?? 0 ) ),
		'resolution_method' => $method,
		'resolution_state'  => $state,
		'occurrence_count'  => max( 0, (int) ( $data['occurrence_count'] ?? 0 ) ),
	];
}
