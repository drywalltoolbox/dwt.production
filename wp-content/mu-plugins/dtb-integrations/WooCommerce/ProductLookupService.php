<?php
/**
 * WooCommerce product lookup helpers for DTB integrations.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_WooCommerceProductLookupService' ) ) {
	return;
}

final class DTB_WooCommerceProductLookupService {
	/**
	 * Find a product or variation ID by SKU.
	 *
	 * @param string $sku Product SKU.
	 * @return int|null
	 */
	public static function find_id_by_sku( string $sku ): ?int {
		$sku = trim( $sku );
		if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
			return null;
		}

		$product_id = (int) wc_get_product_id_by_sku( $sku );
		return $product_id > 0 ? $product_id : null;
	}

	/**
	 * Find a product object by SKU.
	 *
	 * @param string $sku Product SKU.
	 * @return WC_Product|null
	 */
	public static function find_product_by_sku( string $sku ): ?WC_Product {
		$product_id = self::find_id_by_sku( $sku );
		if ( null === $product_id || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Resolve line-item product identity for external sync systems.
	 *
	 * @param WC_Order_Item_Product $item Order item.
	 * @return array{product_id:int,variation_id:int,sku:string,name:string,quantity:int}
	 */
	public static function order_item_identity( WC_Order_Item_Product $item ): array {
		$product = $item->get_product();

		return [
			'product_id'   => (int) $item->get_product_id(),
			'variation_id' => (int) $item->get_variation_id(),
			'sku'          => $product instanceof WC_Product ? (string) $product->get_sku() : '',
			'name'         => $product instanceof WC_Product ? (string) $product->get_name() : (string) $item->get_name(),
			'quantity'     => max( 1, (int) $item->get_quantity() ),
		];
	}
}

/**
 * Backward-compatible functional lookup wrapper.
 *
 * @param string $sku Product SKU.
 * @return int|null
 */
function dtb_integrations_woo_find_product_by_sku( string $sku ): ?int {
	return DTB_WooCommerceProductLookupService::find_id_by_sku( $sku );
}
