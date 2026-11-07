<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Schematics Manager
 *
 * Defines the shared `dtb_schematics_can_manage()` capability check used by
 * the Schematics and Hotspots control center. The
 * authoritative schematic domain record (Domain/SchematicRecordEntity.php,
 * persisted via Infrastructure/SchematicRecordRepository.php as the
 * `dtb_schematic` CPT) is the sole authority for schematic existence and
 * lifecycle state — not WP Media Library attachment flags.
 *
 * @package DrywallToolbox
 */

defined( 'ABSPATH' ) || exit;

// Only load this admin UI tool when inside wp-admin or AJAX requests.
if ( ! dtb_is_admin_or_ajax_request() ) {
	return;
}

/**
 * Canonical schematics capability check.
 */
if ( ! function_exists( 'dtb_schematics_can_manage' ) ) {
	function dtb_schematics_can_manage(): bool {
		return current_user_can( 'dtb_manage_schematics' );
	}
}


