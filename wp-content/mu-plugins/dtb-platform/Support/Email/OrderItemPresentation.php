<?php
/**
 * Shared WooCommerce order-item presentation for branded transactional emails.
 *
 * Reused by dtb-commerce's email-order-items.php and email-fulfillment-items.php
 * template overrides via the standard woocommerce_order_item_thumbnail /
 * woocommerce_order_item_name filters, so thumbnail/name markup exists once.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_email_order_item_thumbnail_url' ) ) {
	/**
	 * Resolve a stable product thumbnail URL for a WooCommerce order item.
	 *
	 * @param mixed $item Order item (WC_Order_Item_Product or similar).
	 * @return string
	 */
	function dtb_email_order_item_thumbnail_url( $item ): string {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_product' ) ) {
			return '';
		}

		$product = $item->get_product();
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$image_id = (int) $product->get_image_id();

		if ( ! $image_id && $product->is_type( 'variation' ) && function_exists( 'wc_get_product' ) ) {
			$parent_id = (int) $product->get_parent_id();
			$parent    = $parent_id > 0 ? wc_get_product( $parent_id ) : null;
			if ( $parent instanceof WC_Product ) {
				$image_id = (int) $parent->get_image_id();
			}
		}

		$url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
		if ( ! $url && function_exists( 'wc_placeholder_img_src' ) ) {
			$url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
		}

		return $url ? esc_url_raw( $url ) : '';
	}
}

if ( ! function_exists( 'dtb_email_render_item_thumbnail' ) ) {
	/**
	 * Render a branded product thumbnail cell for a WooCommerce email order/fulfillment item row.
	 *
	 * Intended for the `woocommerce_order_item_thumbnail` filter. Returns an
	 * empty string (no thumbnail markup) when no image is resolvable, so the
	 * caller's row layout degrades gracefully.
	 *
	 * @param mixed $item Order item.
	 * @return string
	 */
	function dtb_email_render_item_thumbnail( $item ): string {
		$src = dtb_email_order_item_thumbnail_url( $item );
		if ( '' === $src ) {
			return '';
		}

		$name = is_object( $item ) && method_exists( $item, 'get_name' ) ? (string) $item->get_name() : 'Product image';
		$alt  = sprintf( '%s thumbnail', wp_strip_all_tags( $name ) );

		return '<img src="' . esc_url( $src ) . '" width="66" height="66" alt="' . esc_attr( $alt ) . '" style="display:block;width:66px;height:66px;max-width:66px;border:1px solid #e4eaf2;border-radius:8px;background:#ffffff;object-fit:contain;" />';
	}
}

if ( ! function_exists( 'dtb_email_render_item_name' ) ) {
	/**
	 * Render a branded product name for a WooCommerce email order/fulfillment item row.
	 *
	 * Intended for the `woocommerce_order_item_name` filter.
	 *
	 * @param string $item_name Rendered item name (already filtered by core).
	 * @return string
	 */
	function dtb_email_render_item_name( string $item_name ): string {
		return '<span style="display:block;color:#101828;font-family:' . dtb_email_font_stack() . ';font-size:15px;font-weight:800;line-height:21px;overflow-wrap:anywhere;word-break:break-word;">' . wp_kses_post( $item_name ) . '</span>';
	}
}
