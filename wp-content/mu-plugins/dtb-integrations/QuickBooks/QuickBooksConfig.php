<?php
/**
 * QuickBooks configuration facade.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_QuickBooksConfig' ) ) {
	return;
}

final class DTB_QuickBooksConfig {
	public static function raw(): array {
		return function_exists( 'dtb_qbo_config' ) ? (array) dtb_qbo_config() : [];
	}

	public static function enabled(): bool {
		return function_exists( 'dtb_qbo_enabled' ) && dtb_qbo_enabled();
	}

	public static function diagnostics(): array {
		return function_exists( 'dtb_qbo_status' ) ? dtb_qbo_status() : [
			'environment'            => null,
			'credentials_configured' => false,
			'connected'              => false,
			'company_verified'       => false,
		];
	}
}

function dtb_integrations_qbo_config(): array {
	return DTB_QuickBooksConfig::raw();
}
