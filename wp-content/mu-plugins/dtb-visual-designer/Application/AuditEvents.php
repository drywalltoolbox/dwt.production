<?php
/**
 * Application — AuditEvents: thin, named wrapper over the shared
 * dtb_audit_log_write() helper (dtb-platform/SystemManager/AuditLogService.php).
 *
 * Records are concise and structured: action name, revision/draft ids,
 * payload hash, affected surfaces — never the full configuration payload.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * @param string $action  One of the dtb_vd_audit_* action name constants.
 * @param array  $context Structured, redacted context (ids/hashes only).
 */
function dtb_vd_audit( string $action, array $context = [] ): void {
	if ( function_exists( 'dtb_audit_log_write' ) ) {
		dtb_audit_log_write( $action, $context );
		return;
	}

	// Fail-safe: never throw from an audit call, but do not lose the event.
	error_log( '[DTB Visual Designer] audit: ' . $action . ' ' . wp_json_encode( $context ) );
}
