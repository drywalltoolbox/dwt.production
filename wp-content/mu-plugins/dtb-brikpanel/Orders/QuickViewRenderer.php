<?php
/**
 * Read-only composition adapter for WooCommerce's existing order preview.
 *
 * The native preview template and item table are retained. DTB only wraps the
 * already-filtered item HTML with contextual metadata and a real-data sidebar.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_admin_order_preview_get_order_details', 'dtb_order_quick_view_v2_compose', 40, 2 );

/** Add the V2 presentation around the existing native preview item table. */
function dtb_order_quick_view_v2_compose( $details, $order ) {
	if (
		! dtb_order_quick_view_v2_enabled()
		|| ! function_exists( 'dtb_order_readability_screen' )
		|| ! dtb_order_readability_screen()
		|| ! current_user_can( 'edit_shop_orders' )
		|| ! is_array( $details )
		|| ! $order instanceof WC_Order
		|| empty( $details['item_html'] )
		|| ! function_exists( 'dtb_order_readability_preview_summary' )
	) {
		return $details;
	}

	$item_count = absint( $order->get_item_count() );
	$created    = $order->get_date_created();
	$meta       = [];
	if ( $created ) {
		$meta[] = '<span>' . esc_html( wc_format_datetime( $created ) ) . '</span>';
	}
	$meta[] = '<span>' . esc_html( sprintf( _n( '%s item', '%s items', $item_count, 'woocommerce' ), number_format_i18n( $item_count ) ) ) . '</span>';

	$summary = '<section class="dtb-qv-card dtb-qv-card--summary" aria-labelledby="dtb-qv-summary-title">'
		. '<h3 id="dtb-qv-summary-title">' . esc_html__( 'Order summary', 'woocommerce' ) . '</h3>'
		. dtb_order_readability_preview_summary( $order )
		. '</section>';
	$customer = dtb_order_quick_view_customer_card( $order );
	$item_html = dtb_order_quick_view_add_product_thumbnails( (string) $details['item_html'], $order );
	$line_count = count( $order->get_items( 'line_item' ) );
	$items_label = sprintf( _n( 'Order item (%s)', 'Order items (%s)', $line_count, 'woocommerce' ), number_format_i18n( $line_count ) );
	$item_html = '<div class="dtb-qv-payload" data-order-id="' . esc_attr( (string) $order->get_id() ) . '">'
		. '<div class="dtb-qv__order-meta" aria-label="' . esc_attr__( 'Order context', 'drywall-toolbox' ) . '">' . implode( '', $meta ) . '</div>'
		. '<section class="dtb-qv-card dtb-qv-card--items" aria-labelledby="dtb-qv-items-title">'
		. '<h3 id="dtb-qv-items-title">' . esc_html( $items_label ) . '</h3>'
		. $item_html
		. '</section>' . $summary . $customer . '</div>';

	$details['item_html'] = $item_html;
	return $details;
}

/** Add the product image beside WooCommerce's genuine preview cell content. */
function dtb_order_quick_view_add_product_thumbnails( string $item_html, WC_Order $order ): string {
	foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		$row_marker = 'wc-order-preview-table__item--' . absint( $item_id );
		$row_start  = strpos( $item_html, $row_marker );
		$product    = $item->get_product();
		if ( false === $row_start || ! $product ) {
			continue;
		}

		$cell_marker = '<td class="wc-order-preview-table__column--product">';
		$cell_start  = strpos( $item_html, $cell_marker, $row_start );
		$cell_end    = false === $cell_start ? false : strpos( $item_html, '</td>', $cell_start );
		if ( false === $cell_start || false === $cell_end ) {
			continue;
		}

		$content_start = $cell_start + strlen( $cell_marker );
		$content       = substr( $item_html, $content_start, $cell_end - $content_start );
		if ( false !== strpos( $content, 'dtb-qv-product' ) ) {
			continue;
		}

		$image = wp_kses_post( $product->get_image( 'thumbnail', [ 'alt' => '', 'loading' => 'lazy' ] ) );
		$wrapped = '<div class="dtb-qv-product">'
			. '<span class="dtb-qv-product__image" aria-hidden="true">' . $image . '</span>'
			. '<div class="dtb-qv-product__content">' . $content . '</div></div>';
		$item_html = substr_replace( $item_html, $wrapped, $content_start, $cell_end - $content_start );
	}

	return $item_html;
}

/** Render customer identity from the order's existing customer/billing data. */
function dtb_order_quick_view_customer_card( WC_Order $order ): string {
	$customer_id   = absint( $order->get_customer_id() );
	$customer_name = trim( $order->get_formatted_billing_full_name() );
	$email         = sanitize_email( (string) $order->get_billing_email() );
	$order_count   = 0;
	$customer_url  = '';

	if ( $customer_id > 0 ) {
		if ( function_exists( 'wc_get_customer_order_count' ) ) {
			$order_count = absint( wc_get_customer_order_count( $customer_id ) );
		}
		if ( current_user_can( 'edit_user', $customer_id ) ) {
			$customer_url = add_query_arg( 'user_id', $customer_id, admin_url( 'user-edit.php' ) );
		}
	}

	if ( '' === $customer_name ) {
		$customer_name = $customer_id > 0 ? __( 'Registered customer', 'drywall-toolbox' ) : __( 'Guest customer', 'woocommerce' );
	}

	$html  = '<section class="dtb-qv-card dtb-qv-card--customer" aria-labelledby="dtb-qv-customer-title">';
	$html .= '<h3 id="dtb-qv-customer-title">' . esc_html__( 'Customer', 'woocommerce' ) . '</h3>';
	$html .= '<div class="dtb-qv__customer-name">' . esc_html( $customer_name ) . '</div>';
	if ( $email ) {
		$html .= '<a class="dtb-qv__customer-email" href="' . esc_url( 'mailto:' . $email ) . '">' . esc_html( $email ) . '</a>';
	}
	if ( $customer_id > 0 ) {
		$html .= '<div class="dtb-qv__customer-meta">';
		$html .= '<span>' . esc_html( sprintf( _n( '%s order', '%s orders', $order_count, 'woocommerce' ), number_format_i18n( $order_count ) ) ) . '</span>';
		if ( $customer_url ) {
			$html .= '<a href="' . esc_url( $customer_url ) . '">' . esc_html__( 'View customer', 'woocommerce' ) . '</a>';
		}
		$html .= '</div>';
	}
	return $html . '</section>';
}
