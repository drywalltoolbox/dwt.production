<?php
/**
 * WooCommerce webhook manager facade for DTB integrations.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_WooWebhookManager' ) ) {
	return;
}

final class DTB_WooWebhookManager {
	/**
	 * Ensure required WooCommerce webhooks exist using the legacy-compatible
	 * implementation currently owned by WooCommerceBridge.php.
	 *
	 * @return array<string,mixed>
	 */
	public static function ensure_product_webhooks(): array {
		if ( ! function_exists( 'dtb_wc_ensure_webhooks' ) ) {
			return [
				'status'  => 'skipped',
				'reason'  => 'webhook_manager_unavailable',
				'created' => [],
			];
		}

		return (array) dtb_wc_ensure_webhooks();
	}

	/**
	 * Return webhook IDs for a delivery URL.
	 *
	 * @param string $delivery_url Delivery URL.
	 * @return int[]
	 */
	public static function get_ids_by_delivery_url( string $delivery_url ): array {
		if ( '' === $delivery_url || ! function_exists( 'dtb_wc_get_webhook_ids_by_delivery_url' ) ) {
			return [];
		}

		return array_values( array_map( 'absint', (array) dtb_wc_get_webhook_ids_by_delivery_url( $delivery_url ) ) );
	}
}

/**
 * Backward-compatible webhook ensure wrapper.
 *
 * @return array<string,mixed>
 */
function dtb_integrations_woo_ensure_webhooks(): array {
	return DTB_WooWebhookManager::ensure_product_webhooks();
}
