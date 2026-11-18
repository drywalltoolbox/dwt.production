<?php
/**
 * WooCommerce product webhook payload helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_WooProductWebhookHandler' ) ) {
	return;
}

final class DTB_WooProductWebhookHandler {
	/**
	 * Build the canonical webhook payload for a WooCommerce product.
	 *
	 * @param WC_Product $product Product object.
	 * @return array<string,mixed>
	 */
	public static function payload( WC_Product $product ): array {
		if ( function_exists( 'dtb_wc_product_webhook_payload' ) ) {
			return (array) dtb_wc_product_webhook_payload( $product );
		}

		return [
			'id'          => (int) $product->get_id(),
			'name'        => (string) $product->get_name(),
			'sku'         => (string) $product->get_sku(),
			'type'        => (string) $product->get_type(),
			'status'      => (string) $product->get_status(),
			'permalink'   => (string) $product->get_permalink(),
			'updated_at'  => $product->get_date_modified() ? $product->get_date_modified()->date( DATE_ATOM ) : '',
		];
	}
}

/**
 * Backward-compatible product webhook payload wrapper.
 *
 * @param WC_Product $product Product object.
 * @return array<string,mixed>
 */
function dtb_integrations_woo_product_webhook_payload( WC_Product $product ): array {
	return DTB_WooProductWebhookHandler::payload( $product );
}
