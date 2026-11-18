<?php
/**
 * SMS gateway facade.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_SmsGateway' ) ) {
	return;
}

final class DTB_SmsGateway {
	/**
	 * Send an SMS through a configured gateway integration.
	 *
	 * This method intentionally degrades to false when no gateway adapter is
	 * configured. It centralizes sanitization and exposes a stable integration
	 * seam for a future transactional SMS provider.
	 *
	 * @param string $phone   Phone number.
	 * @param string $message Message body.
	 * @return bool
	 */
	public static function send( string $phone, string $message ): bool {
		$phone   = preg_replace( '/[^0-9+]/', '', $phone );
		$message = sanitize_textarea_field( $message );

		if ( '' === $phone || '' === $message ) {
			return false;
		}

		return (bool) apply_filters( 'dtb_sms_gateway_send', false, $phone, $message );
	}
}
