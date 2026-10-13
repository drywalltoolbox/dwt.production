<?php
/**
 * DTB readable order item provenance in the WooCommerce HPOS editor.
 *
 * Presentation-only companion to BrikPanel; WooCommerce owns order items.
 * No vendor-plugin edits and no mutation of stored item metadata.
 */
defined( 'ABSPATH' ) || exit;

function dtb_order_readability_screen(): bool {
    if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
        return false;
    }
    $screen = get_current_screen();
    return $screen && in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'shop_order' ], true );
}

function dtb_order_readability_keys(): array {
    return [
        '_dtb_ordered_product_id',
        '_dtb_ordered_name',
        '_dtb_ordered_sku',
        '_dtb_ordered_mpn',
        '_dtb_ordered_brand',
        '_dtb_ordered_product_kind',
        '_dtb_toolset_components_json',
    ];
}

add_filter( 'woocommerce_hidden_order_itemmeta', static function ( $keys ) {
    return dtb_order_readability_screen()
        ? array_values( array_unique( array_merge( (array) $keys, dtb_order_readability_keys() ) ) )
        : $keys;
}, 30 );

function dtb_order_readability_components( $raw ): array {
    if ( ! is_string( $raw ) || '' === trim( $raw ) || strlen( $raw ) > 50000 ) {
        return [];
    }
    $rows = json_decode( $raw, true );
    if ( is_string( $rows ) ) {
        $rows = json_decode( $rows, true );
    }
    if ( ! is_array( $rows ) || ! array_is_list( $rows ) ) {
        return [];
    }
    $result = [];
    foreach ( array_slice( $rows, 0, 100 ) as $row ) {
        if ( ! is_array( $row ) ) { continue; }
        $name = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
        $sku = sanitize_text_field( (string) ( $row['sku'] ?? '' ) );
        $qty = absint( $row['quantity'] ?? 0 );
        if ( '' !== $name && $qty > 0 ) {
            $result[] = [ 'name' => $name, 'sku' => $sku, 'qty' => $qty ];
        }
    }
    return $result;
}

add_action( 'woocommerce_after_order_itemmeta', static function ( $item_id, $item ) {
    if ( ! dtb_order_readability_screen() || ! $item instanceof WC_Order_Item_Product ) {
        return;
    }
    $keys = dtb_order_readability_keys();
    $values = [];
    foreach ( $keys as $key ) {
        $value = $item->get_meta( $key, true );
        if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
            $values[ $key ] = (string) $value;
        }
    }
    if ( ! $values ) { return; }

    $brand = $values['_dtb_ordered_brand'] ?? '';
    $kind = $values['_dtb_ordered_product_kind'] ?? '';
    $product_id = $values['_dtb_ordered_product_id'] ?? '';
    $mpn = $values['_dtb_ordered_mpn'] ?? '';
    $sku = $values['_dtb_ordered_sku'] ?? '';
    $components = dtb_order_readability_components( $values['_dtb_toolset_components_json'] ?? '' );
    echo '<section class="dtb-order-item-details" aria-label="' . esc_attr__( 'Order item details', 'drywall-toolbox' ) . '">';
    echo '<div class="dtb-order-item-facts">';
    if ( $brand ) { echo '<span class="dtb-order-chip">' . esc_html( $brand ) . '</span>'; }
    if ( $kind ) { echo '<span class="dtb-order-chip">' . esc_html( ucwords( str_replace( [ '-', '_' ], ' ', $kind ) ) ) . '</span>'; }
    if ( $product_id ) { echo '<span class="dtb-order-muted">' . esc_html__( 'Product ID', 'drywall-toolbox' ) . ' ' . esc_html( $product_id ) . '</span>'; }
    if ( $mpn && $mpn !== $sku ) { echo '<span class="dtb-order-muted">' . esc_html__( 'MPN', 'drywall-toolbox' ) . ' ' . esc_html( $mpn ) . '</span>'; }
    echo '</div>';

    if ( $components ) {
        $units = array_sum( array_column( $components, 'qty' ) );
        echo '<details class="dtb-order-component-list"><summary><span>' . esc_html__( 'Included components', 'drywall-toolbox' ) . '</span><span class="dtb-order-count">' . esc_html( count( $components ) ) . ' ' . esc_html__( 'types', 'drywall-toolbox' ) . ' · ' . esc_html( $units ) . ' ' . esc_html__( 'units', 'drywall-toolbox' ) . '</span></summary>';
        echo '<div class="dtb-order-component-scroll"><table><thead><tr><th scope="col">' . esc_html__( 'Component', 'drywall-toolbox' ) . '</th><th scope="col">' . esc_html__( 'SKU', 'drywall-toolbox' ) . '</th><th scope="col">' . esc_html__( 'Qty', 'drywall-toolbox' ) . '</th></tr></thead><tbody>';
        foreach ( $components as $row ) {
            echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['sku'] ) . '</td><td>' . esc_html( $row['qty'] ) . '</td></tr>';
        }
        echo '</tbody></table></div></details>';
    } elseif ( isset( $values['_dtb_toolset_components_json'] ) ) {
        echo '<p class="dtb-order-muted">' . esc_html__( 'Component data is unavailable or invalid. See technical metadata.', 'drywall-toolbox' ) . '</p>';
    }

    echo '<details class="dtb-order-tech"><summary>' . esc_html__( 'Technical metadata', 'drywall-toolbox' ) . '</summary><dl>';
    foreach ( $values as $key => $value ) {
        echo '<dt>' . esc_html( $key ) . '</dt><dd><code>' . esc_html( $value ) . '</code></dd>';
    }
    echo '</dl></details></section>';
}, 20, 2 );

add_action( 'admin_enqueue_scripts', static function () {
    if ( ! dtb_order_readability_screen() ) { return; }
    wp_register_style( 'dtb-readable-order-items', false, [], '1.0.0' );
    wp_enqueue_style( 'dtb-readable-order-items' );
    wp_add_inline_style( 'dtb-readable-order-items', '
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-item-details{max-width:760px;margin:14px 0 4px;font-size:12px;line-height:1.5;color:#303641}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-item-facts{display:flex;flex-wrap:wrap;gap:6px 9px;align-items:center;margin-bottom:11px}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-chip{padding:3px 9px;border:1px solid #e0e6eb;border-radius:999px;background:#f5f7f9;font-weight:600;color:#374151}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-muted{font-size:11px;color:#687484}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-list{border:1px solid #e3e8ee;border-radius:10px;background:#fff;overflow:hidden}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-list>summary{display:flex;gap:12px;justify-content:space-between;align-items:center;cursor:pointer;padding:10px 13px;font-weight:650;list-style-position:inside}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-count{color:#64748b;font-size:11px;font-weight:500;white-space:nowrap}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-scroll{max-height:350px;overflow:auto;border-top:1px solid #e3e8ee}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-scroll table{width:100%;border-collapse:collapse;min-width:330px;margin:0}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-scroll th,.brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-scroll td{padding:8px 12px;text-align:left;border-bottom:1px solid #edf0f3;background:transparent;font-size:12px;vertical-align:top;overflow-wrap:anywhere}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-scroll th{background:#f8fafc;color:#64748b;font-size:11px;font-weight:600}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-scroll th:last-child,.brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-scroll td:last-child{text-align:right;width:44px}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-tech{margin-top:9px;color:#64748b;font-size:11px}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-tech>summary{cursor:pointer;width:max-content;max-width:100%}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-tech dl{margin:8px 0;padding:10px;background:#f8fafc;border-radius:8px;overflow-wrap:anywhere}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-tech dt{font-weight:650;margin:8px 0 2px}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-tech dd{margin:0}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-tech code{white-space:pre-wrap;word-break:break-word;font-size:11px}
        .brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-list>summary:focus-visible,.brikpanel-modern-edit #woocommerce-order-items .dtb-order-tech>summary:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
        @media(max-width:700px){.brikpanel-modern-edit #woocommerce-order-items .dtb-order-item-details{max-width:100%}.brikpanel-modern-edit #woocommerce-order-items .dtb-order-component-list>summary{flex-wrap:wrap}}
    ' );
}, 100 );
