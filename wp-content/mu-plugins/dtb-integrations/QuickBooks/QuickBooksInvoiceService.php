<?php
defined( 'ABSPATH' ) || exit;

function dtb_integrations_qbo_sync_order( WC_Order $order ): array|WP_Error {
	if ( function_exists( 'dtb_qbo_sync_order' ) ) {
		return dtb_qbo_sync_order( $order );
	}
	return new WP_Error( 'qbo_unavailable', 'QuickBooks sync function unavailable.' );
}
