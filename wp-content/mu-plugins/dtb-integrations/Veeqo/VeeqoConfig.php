<?php
/**
 * Veeqo configuration facade.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_VeeqoConfig' ) ) {
	return;
}

final class DTB_VeeqoConfig {
	/**
	 * Return normalized Veeqo configuration without exposing secrets in public payloads.
	 *
	 * @return array{api_key:string,webhook_secret:string,warehouse_id:int,channel_id:int,delivery_method_id:int}
	 */
	public static function raw(): array {
		return function_exists( 'dtb_veeqo_config' ) ? (array) dtb_veeqo_config() : [
			'api_key'            => '',
			'webhook_secret'     => '',
			'warehouse_id'       => 0,
			'channel_id'         => 0,
			'delivery_method_id' => 0,
		];
	}

	/**
	 * Return whether the integration has enough configuration to call Veeqo.
	 */
	public static function enabled(): bool {
		return function_exists( 'dtb_veeqo_enabled' ) ? (bool) dtb_veeqo_enabled() : '' !== self::raw()['api_key'];
	}

	/**
	 * Return a redacted configuration snapshot safe for admin diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public static function redacted(): array {
		$config = self::raw();

		return [
			'enabled'              => self::enabled(),
			'api_key_configured'   => '' !== (string) ( $config['api_key'] ?? '' ),
			'webhook_configured'   => '' !== (string) ( $config['webhook_secret'] ?? '' ),
			'warehouse_id'         => (int) ( $config['warehouse_id'] ?? 0 ),
			'channel_id'           => (int) ( $config['channel_id'] ?? 0 ),
			'delivery_method_id'   => (int) ( $config['delivery_method_id'] ?? 0 ),
			'settings_option_name' => 'woocommerce_dtb_veeqo_settings',
		];
	}
}

/**
 * Backward-compatible Veeqo config wrapper.
 *
 * @return array<string,mixed>
 */
function dtb_integrations_veeqo_config(): array {
	return DTB_VeeqoConfig::raw();
}
