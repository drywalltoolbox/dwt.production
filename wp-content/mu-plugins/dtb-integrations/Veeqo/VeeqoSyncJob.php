<?php
/**
 * Veeqo sync job facade.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_VeeqoSyncJob' ) ) {
	return;
}

final class DTB_VeeqoSyncJob {
	/**
	 * Log a sync timestamp using the existing runtime contract.
	 *
	 * @param string $type Sync type.
	 */
	public static function log_timestamp( string $type ): void {
		$type = sanitize_key( $type );
		if ( '' === $type ) {
			$type = 'unknown';
		}

		if ( function_exists( 'dtb_veeqo_log_sync_timestamp' ) ) {
			dtb_veeqo_log_sync_timestamp( $type );
			return;
		}

		update_option( 'dtb_veeqo_sync_' . $type, time(), false );
	}

	/**
	 * Return the last sync timestamp for a type.
	 *
	 * @param string $type Sync type.
	 * @return int Unix timestamp, or zero when no sync has been recorded.
	 */
	public static function last_timestamp( string $type ): int {
		$type = sanitize_key( $type );
		if ( '' === $type ) {
			return 0;
		}

		return absint( get_option( 'dtb_veeqo_sync_' . $type, 0 ) );
	}
}

/**
 * Backward-compatible sync timestamp wrapper.
 *
 * @param string $type Sync type.
 */
function dtb_integrations_veeqo_log_sync_timestamp( string $type ): void {
	DTB_VeeqoSyncJob::log_timestamp( $type );
}
