<?php
defined( 'ABSPATH' ) || exit;

function dtb_integrations_qbo_run_sync(): array|WP_Error {
	if ( function_exists( 'dtb_qbo_run_sync' ) ) {
		return dtb_qbo_run_sync();
	}
	return new WP_Error( 'qbo_unavailable', 'QuickBooks sync job unavailable.' );
}
