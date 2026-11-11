<?php
/**
 * DTB Schematics — SchematicPreviewPolicy value object.
 *
 * Encodes the preview-selection priority described in the schematics
 * platform spec:
 *   1. explicit curated preview;
 *   2. appropriate linked product image;
 *   3. first published diagram page;
 *   4. clearly labeled preview-unavailable state.
 *
 * This module only records the policy and its explicit override; resolving
 * an actual preview URL against product images/pages is an application-layer
 * concern (see Application/GenerateSchematicResponse.php).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_PREVIEW_MODE_EXPLICIT      = 'explicit';
const DTB_SCHEMATIC_PREVIEW_MODE_PRODUCT_IMAGE = 'product_image';
const DTB_SCHEMATIC_PREVIEW_MODE_FIRST_PAGE    = 'first_page';
const DTB_SCHEMATIC_PREVIEW_MODE_UNAVAILABLE   = 'unavailable';

/**
 * @param array $data {
 *     @type string $mode                 Preferred mode; falls back automatically if unmet.
 *     @type int    $explicit_attachment_id Attachment ID to use when mode is 'explicit'.
 * }
 */
function dtb_schematic_preview_policy_make( array $data ): array {
	$valid_modes = [
		DTB_SCHEMATIC_PREVIEW_MODE_EXPLICIT,
		DTB_SCHEMATIC_PREVIEW_MODE_PRODUCT_IMAGE,
		DTB_SCHEMATIC_PREVIEW_MODE_FIRST_PAGE,
		DTB_SCHEMATIC_PREVIEW_MODE_UNAVAILABLE,
	];

	$mode = (string) ( $data['mode'] ?? DTB_SCHEMATIC_PREVIEW_MODE_FIRST_PAGE );
	if ( ! in_array( $mode, $valid_modes, true ) ) {
		$mode = DTB_SCHEMATIC_PREVIEW_MODE_FIRST_PAGE;
	}

	return [
		'mode'                   => $mode,
		'explicit_attachment_id' => max( 0, (int) ( $data['explicit_attachment_id'] ?? 0 ) ),
	];
}
