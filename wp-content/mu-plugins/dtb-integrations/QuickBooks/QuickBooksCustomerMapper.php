<?php
defined( 'ABSPATH' ) || exit;

function dtb_integrations_qbo_customer_id_for_order( WC_Order $order ): string {
	if ( ! function_exists( 'dtb_qbo_get_or_create_customer' ) ) {
		return '';
	}
	$result = dtb_qbo_get_or_create_customer( $order );
	return is_wp_error( $result ) ? '' : (string) $result;
}
