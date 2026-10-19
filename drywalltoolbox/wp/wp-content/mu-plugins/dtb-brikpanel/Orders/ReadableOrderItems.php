<?php
/**
 * DTB readable order item provenance in the WooCommerce HPOS editor.
 *
 * Presentation-only companion to BrikPanel; WooCommerce owns order items.
 * No vendor-plugin edits and no mutation of stored item metadata.
 */
defined( 'ABSPATH' ) || exit;

function dtb_order_readability_screen(): bool {
    if ( ! is_admin() ) {
        return false;
    }
    if ( function_exists( 'get_current_screen' ) ) {
        $screen = get_current_screen();
        if ( $screen && in_array( $screen->id, [ 'woocommerce_page_wc-orders', 'shop_order' ], true ) ) {
            return true;
        }
    }

    // WooCommerce renders the order preview in admin-ajax.php, where there is
    // no current screen object. Keep this narrowly scoped to its read-only
    // preview request so internal item metadata is suppressed there as well.
    return function_exists( 'wp_doing_ajax' ) && wp_doing_ajax()
        && isset( $_REQUEST['action'] )
        && 'woocommerce_get_order_details' === sanitize_key( wp_unslash( $_REQUEST['action'] ) );
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

/** Hide implementation and duplicate display metadata in WooCommerce admin. */
function dtb_order_readability_hidden_keys(): array {
    return array_merge(
        dtb_order_readability_keys(),
        [ '_product_id', '_variation_id', '_qty', '_tax_class', '_line_subtotal', '_line_subtotal_tax', '_line_total', '_line_tax', '_line_tax_data' ]
    );
}

function dtb_order_readability_cogs_enabled(): bool {
    $controller = '\\Automattic\\WooCommerce\\Internal\\CostOfGoodsSold\\CostOfGoodsSoldController';
    if ( ! function_exists( 'wc_get_container' ) || ! class_exists( $controller ) ) {
        return false;
    }
    try {
        $feature = wc_get_container()->get( $controller );
        return is_object( $feature ) && method_exists( $feature, 'feature_is_enabled' ) && $feature->feature_is_enabled();
    } catch ( Throwable $error ) {
        return false;
    }
}

add_filter( 'woocommerce_hidden_order_itemmeta', static function ( $keys ) {
    return dtb_order_readability_screen()
        ? array_values( array_unique( array_merge( (array) $keys, dtb_order_readability_hidden_keys() ) ) )
        : $keys;
}, 30 );

/**
 * WooCommerce's formatted order-item metadata is repeated in both the preview
 * and full editor. Suppress that raw dump in this admin context; the concise
 * DTB summary below presents the useful provenance once and keeps the order
 * record untouched.
 */
add_filter( 'woocommerce_order_item_get_formatted_meta_data', static function ( $formatted_meta, $item ) {
    if ( ! dtb_order_readability_screen() || ! $item instanceof WC_Order_Item_Product || ! is_array( $formatted_meta ) ) {
        return $formatted_meta;
    }

    $hidden = array_fill_keys( dtb_order_readability_hidden_keys(), true );
    foreach ( $formatted_meta as $index => $meta ) {
        $key = isset( $meta->key ) ? (string) $meta->key : '';
        if ( isset( $hidden[ $key ] ) || 'store_reference' === sanitize_key( $key ) || '' === $key || str_starts_with( $key, '_' ) || str_starts_with( $key, 'dtb_' ) ) {
            unset( $formatted_meta[ $index ] );
        }
    }

    return $formatted_meta;
}, 30, 2 );

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

/** Match the full editor's Cost and Price columns in the quick preview. */
add_filter( 'woocommerce_admin_order_preview_line_item_columns', static function ( $columns, $order ) {
    if ( ! dtb_order_readability_screen() || ! is_array( $columns ) ) {
        return $columns;
    }

    $result = [];
    foreach ( $columns as $key => $label ) {
        $result[ $key ] = $label;
        if ( 'product' === $key ) {
            if ( dtb_order_readability_cogs_enabled() ) {
                $result['item_cost_of_goods'] = __( 'Cost', 'woocommerce' );
            }
            $result['item_cost'] = __( 'Price', 'woocommerce' );
        }
    }
    return $result;
}, 30, 2 );

add_filter( 'woocommerce_admin_order_preview_line_item_column_item_cost_of_goods', static function ( $value, $item, $item_id, $order ) {
    return dtb_order_readability_cogs_enabled() && $item instanceof WC_Order_Item_Product && method_exists( $item, 'get_cogs_value_html' )
        ? $item->get_cogs_value_html()
        : $value;
}, 30, 4 );

add_filter( 'woocommerce_admin_order_preview_line_item_column_item_cost', static function ( $value, $item, $item_id, $order ) {
    if ( ! $item instanceof WC_Order_Item_Product || ! $order instanceof WC_Order ) {
        return $value;
    }
    return wc_price( $order->get_item_subtotal( $item, false, true ), [ 'currency' => $order->get_currency() ] );
}, 30, 4 );

function dtb_order_readability_render_details( WC_Order_Item_Product $item, bool $preview = false ): string {
    $keys = dtb_order_readability_keys();
    $values = [];
    foreach ( $keys as $key ) {
        $value = $item->get_meta( $key, true );
        if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
            $values[ $key ] = (string) $value;
        }
    }
    $store_references = [];
    foreach ( $item->get_meta_data() as $meta ) {
        $meta_data = is_object( $meta ) && method_exists( $meta, 'get_data' ) ? $meta->get_data() : [];
        $meta_key  = isset( $meta_data['key'] ) ? (string) $meta_data['key'] : '';
        $meta_value = $meta_data['value'] ?? null;
        if ( 'store_reference' === sanitize_key( $meta_key ) && is_scalar( $meta_value ) && '' !== trim( (string) $meta_value ) ) {
            $store_references[] = sanitize_text_field( (string) $meta_value );
        }
    }
    $store_references = array_values( array_unique( $store_references ) );
    if ( ! $values && ! $store_references ) { return ''; }

    $brand = $values['_dtb_ordered_brand'] ?? '';
    $kind = $values['_dtb_ordered_product_kind'] ?? '';
    $product_id = $values['_dtb_ordered_product_id'] ?? '';
    $mpn = $values['_dtb_ordered_mpn'] ?? '';
    $sku = $values['_dtb_ordered_sku'] ?? '';
    $components = dtb_order_readability_components( $values['_dtb_toolset_components_json'] ?? '' );
    $html = '<section class="dtb-order-item-details" aria-label="' . esc_attr__( 'Order item details', 'drywall-toolbox' ) . '">';
    $html .= '<div class="dtb-order-item-facts">';
    if ( $brand ) { $html .= '<span class="dtb-order-chip">' . esc_html( $brand ) . '</span>'; }
    if ( $kind ) { $html .= '<span class="dtb-order-chip">' . esc_html( ucwords( str_replace( [ '-', '_' ], ' ', $kind ) ) ) . '</span>'; }
    if ( $sku ) { $html .= '<span class="dtb-order-identifier"><span>' . esc_html__( 'SKU', 'drywall-toolbox' ) . '</span><code>' . esc_html( $sku ) . '</code></span>'; }
    if ( $mpn && $mpn !== $sku ) { $html .= '<span class="dtb-order-identifier"><span>' . esc_html__( 'MPN', 'drywall-toolbox' ) . '</span><code>' . esc_html( $mpn ) . '</code></span>'; }
    if ( $product_id ) { $html .= '<span class="dtb-order-muted">' . esc_html__( 'Product ID', 'drywall-toolbox' ) . ' ' . esc_html( $product_id ) . '</span>'; }
    $html .= '</div>';

    if ( $components ) {
        $units = array_sum( array_column( $components, 'qty' ) );
        $html .= '<details class="dtb-order-component-list"><summary><span>' . esc_html__( 'Included components', 'drywall-toolbox' ) . '</span><span class="dtb-order-count">' . esc_html( count( $components ) ) . ' ' . esc_html__( 'types', 'drywall-toolbox' ) . ' · ' . esc_html( $units ) . ' ' . esc_html__( 'units', 'drywall-toolbox' ) . '</span></summary>';
        $html .= '<div class="dtb-order-component-scroll"><table><thead><tr><th scope="col">' . esc_html__( 'Component', 'drywall-toolbox' ) . '</th><th scope="col">' . esc_html__( 'SKU', 'drywall-toolbox' ) . '</th><th scope="col">' . esc_html__( 'Qty', 'drywall-toolbox' ) . '</th></tr></thead><tbody>';
        foreach ( $components as $row ) {
            $html .= '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . esc_html( $row['sku'] ) . '</td><td>' . esc_html( $row['qty'] ) . '</td></tr>';
        }
        $html .= '</tbody></table></div></details>';
    } elseif ( isset( $values['_dtb_toolset_components_json'] ) ) {
        $html .= '<p class="dtb-order-muted">' . esc_html__( 'Component data is unavailable or invalid. See technical metadata.', 'drywall-toolbox' ) . '</p>';
    }

    $html .= '<details class="dtb-order-tech"><summary>' . esc_html__( 'Technical metadata', 'drywall-toolbox' ) . '</summary><dl>';
    foreach ( $values as $key => $value ) {
        $html .= '<dt>' . esc_html( $key ) . '</dt><dd><code>' . esc_html( $value ) . '</code></dd>';
    }
    foreach ( $store_references as $value ) {
        $html .= '<dt>' . esc_html__( 'Store reference', 'drywall-toolbox' ) . '</dt><dd><code>' . esc_html( $value ) . '</code></dd>';
    }
    return $html . '</dl></details></section>';
}

add_action( 'woocommerce_after_order_itemmeta', static function ( $item_id, $item ) {
    if ( ! dtb_order_readability_screen() || ! $item instanceof WC_Order_Item_Product ) {
        return;
    }
    echo dtb_order_readability_render_details( $item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- renderer escapes all dynamic values.
}, 20, 2 );

/** Add the same DTB item details to WooCommerce's read-only order preview. */
add_filter( 'woocommerce_admin_order_preview_get_order_details', static function ( $details, $order ) {
    if ( ! dtb_order_readability_screen() || ! is_array( $details ) || ! $order instanceof WC_Order || empty( $details['item_html'] ) ) {
        return $details;
    }

    $item_html = (string) $details['item_html'];
    foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            continue;
        }
        $row_marker = 'wc-order-preview-table__item--' . absint( $item_id );
        $row_start = strpos( $item_html, $row_marker );
        if ( false === $row_start ) {
            continue;
        }
        $product_cell = strpos( $item_html, '<td class="wc-order-preview-table__column--product">', $row_start );
        $cell_end = false === $product_cell ? false : strpos( $item_html, '</td>', $product_cell );
        $rendered = dtb_order_readability_render_details( $item, true );
        if ( false !== $cell_end && '' !== $rendered ) {
            $item_html = substr_replace( $item_html, $rendered, $cell_end, 0 );
        }
    }
    // V2 renders this same WooCommerce-backed summary in its sidebar; keep the
    // legacy preview summary in its original location when V2 is disabled.
    $summary = function_exists( 'dtb_order_quick_view_v2_enabled' ) && dtb_order_quick_view_v2_enabled()
        ? ''
        : dtb_order_readability_preview_summary( $order );
    $details['item_html'] = $item_html . $summary;
    return $details;
}, 30, 2 );

/** Compact order totals and operational context shown beneath preview items. */
function dtb_order_readability_preview_summary( WC_Order $order ): string {
    $currency = [ 'currency' => $order->get_currency() ];
    $rows = [
        [ __( 'Items subtotal', 'woocommerce' ), wc_price( $order->get_subtotal(), $currency ) ],
    ];
    if ( $order->get_discount_total() > 0 ) {
        $rows[] = [ __( 'Discount', 'woocommerce' ), '−' . wc_price( $order->get_discount_total(), $currency ) ];
    }
    $rows[] = [ __( 'Shipping', 'woocommerce' ), wc_price( $order->get_shipping_total(), $currency ) ];
    $rows[] = [ __( 'Tax', 'woocommerce' ), wc_price( $order->get_total_tax(), $currency ) ];
    $rows[] = [ __( 'Order total', 'woocommerce' ), wc_price( $order->get_total(), $currency ) ];
    if ( dtb_order_readability_cogs_enabled() && method_exists( $order, 'get_cogs_total_value_html' ) ) {
        $rows[] = [ __( 'Cost total', 'woocommerce' ), $order->get_cogs_total_value_html() ];
    }
    $date_paid = $order->get_date_paid();
    $payment_state = $date_paid ? __( 'Paid', 'woocommerce' ) : __( 'Payment not recorded', 'drywall-toolbox' );
    if ( $date_paid ) {
        $payment_state .= ' · ' . wc_format_datetime( $date_paid );
    }
    if ( $order->get_payment_method_title() ) {
        $payment_state .= ' · ' . $order->get_payment_method_title();
    }
    $rows[] = [ __( 'Payment', 'woocommerce' ), $payment_state ];

    $refunded = abs( (float) $order->get_total_refunded() );
    if ( $refunded > 0 ) {
        $rows[] = [ __( 'Refunded', 'woocommerce' ), wc_price( $refunded, $currency ) ];
    }

    $transaction_fee = $order->get_meta( '_wcpay_transaction_fee', true );
    if ( is_scalar( $transaction_fee ) && is_numeric( $transaction_fee ) && (float) $transaction_fee > 0 ) {
        $rows[] = [ __( 'Transaction fee', 'woocommerce' ), wc_price( -abs( (float) $transaction_fee ), $currency ) ];
    }

    $html = '<section class="dtb-order-preview-summary" aria-label="' . esc_attr__( 'Order totals and context', 'drywall-toolbox' ) . '"><dl>';
    foreach ( $rows as $index => $row ) {
        $class = 'order-total' === sanitize_title( wp_strip_all_tags( $row[0] ) ) ? ' class="dtb-order-preview-summary-total"' : '';
        $html .= '<div' . $class . '><dt>' . esc_html( $row[0] ) . '</dt><dd>' . wp_kses_post( $row[1] ) . '</dd></div>';
    }

    if ( function_exists( 'brikpanel_order_weight_summary' ) && function_exists( 'brikpanel_order_weight_text' ) ) {
        $weight = brikpanel_order_weight_summary( $order );
        if ( ! empty( $weight['lines'] ) ) {
            $html .= '<div><dt>' . esc_html__( 'Total weight', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( brikpanel_order_weight_text( $weight['total'] ) ) . '</dd></div>';
        }
        if ( ! empty( $weight['missing'] ) ) {
            $html .= '<div class="dtb-order-preview-summary-note"><dt>' . esc_html__( 'Weight note', 'drywall-toolbox' ) . '</dt><dd>' . esc_html( sprintf( _n( '%s product has no weight', '%s products have no weight', absint( $weight['missing'] ), 'brikpanel' ), number_format_i18n( absint( $weight['missing'] ) ) ) ) . '</dd></div>';
        }
    }
    return $html . '</dl></section>';
}

add_action( 'admin_enqueue_scripts', static function () {
    if ( ! dtb_order_readability_screen() ) { return; }
    // Quick View V2 has a dedicated stylesheet. Do not print the legacy modal
    // rules on the orders list where they could compete with its layout.
    if ( function_exists( 'dtb_order_quick_view_v2_enabled' )
        && dtb_order_quick_view_v2_enabled()
        && function_exists( 'dtb_order_quick_view_is_orders_list_screen' )
        && dtb_order_quick_view_is_orders_list_screen() ) {
        return;
    }
    wp_register_style( 'dtb-readable-order-items', false, [], '1.0.0' );
    wp_enqueue_style( 'dtb-readable-order-items' );
    wp_add_inline_style( 'dtb-readable-order-items', '
        .wc-backbone-modal.wc-order-preview .wc-backbone-modal-content{width:min(1180px,calc(100vw - 48px));max-width:calc(100vw - 48px)}
        .wc-backbone-modal.wc-order-preview .wc-backbone-modal-main{max-height:calc(100vh - 48px)}
        .wc-backbone-modal.wc-order-preview article{max-height:calc(100vh - 168px);overflow:auto}
        .wc-order-preview .wc-order-preview-addresses{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:14px 20px 10px}
        .wc-order-preview .wc-order-preview-addresses .wc-order-preview-address{float:none;width:auto;padding:14px 16px 12px;border:1px solid #e6eaf0;border-radius:8px;background:#f8fafc;font-size:12px;line-height:1.45}
        .wc-order-preview .wc-order-preview-address h2{margin:0 0 9px;font-size:14px;font-weight:650;color:#202b38}
        .wc-order-preview .wc-order-preview-address strong{margin-top:10px;font-size:11px;color:#526174}
        .wc-order-preview .wc-order-preview-address strong:first-child{margin-top:0}
        .wc-order-preview-table-wrapper{padding:0 20px 12px}
        .wc-order-preview .wc-order-preview-table{border:1px solid #e4e8ee;border-radius:8px;border-spacing:0;overflow:hidden}
        .wc-order-preview .wc-order-preview-table th{padding:10px 12px;background:#f5f7fa;color:#64748b;font-size:10px;font-weight:700;letter-spacing:.045em;text-transform:uppercase;border-bottom:1px solid #e2e7ed}
        .wc-order-preview .wc-order-preview-table td{padding:12px;border-bottom:1px solid #edf0f3;font-size:12px;line-height:1.45}
        .wc-order-preview .wc-order-preview-table td:first-child,.wc-order-preview .wc-order-preview-table th:first-child{padding-left:16px}
        .wc-order-preview .wc-order-preview-table td:last-child,.wc-order-preview .wc-order-preview-table th:last-child{padding-right:16px}
        .wc-order-preview .wc-order-preview-table tbody tr:last-child td{border-bottom:0}
        .wc-order-preview-summary{max-width:560px;margin:0 20px 18px auto;padding:12px 16px;border:1px solid #e4e8ee;border-radius:8px;background:#f8fafc}
        .wc-order-preview-summary dl{display:grid;gap:6px;margin:0}
        .wc-order-preview-summary dl>div{display:flex;justify-content:space-between;align-items:baseline;gap:20px;font-size:12px;line-height:1.4}
        .wc-order-preview-summary dt{color:#64748b}
        .wc-order-preview-summary dd{margin:0;text-align:right;color:#334155;font-variant-numeric:tabular-nums}
        .wc-order-preview-summary .dtb-order-preview-summary-total{padding-top:8px;border-top:1px solid #d8dee7;font-size:14px;font-weight:700}
        .wc-order-preview-summary .dtb-order-preview-summary-total dt,.wc-order-preview-summary .dtb-order-preview-summary-total dd{color:#17212f}
        .wc-order-preview-summary .dtb-order-preview-summary-note{padding-top:5px;border-top:1px solid #e3e8ef;color:#687484;font-size:11px}
        #woocommerce-order-items .dtb-order-item-details{max-width:760px;margin:10px 0 4px;font-size:12px;line-height:1.5;color:#303641}
        .wc-order-preview-table .dtb-order-item-details{max-width:520px;margin:8px 0 2px;font-size:12px;line-height:1.5;color:#303641}
        .wc-order-preview-table .dtb-order-preview-context{display:flex;gap:8px;align-items:center;margin:7px 0;color:#64748b;font-size:11px}
        .wc-order-preview-table .dtb-order-preview-image{display:flex;width:34px;height:34px;flex:0 0 34px;align-items:center;justify-content:center;border:1px solid #e2e8f0;border-radius:4px;background:#fff;overflow:hidden}
        .wc-order-preview-table .dtb-order-preview-image img{display:block;width:100%;height:100%;object-fit:contain}
        .wc-order-preview-table .dtb-order-preview-context-facts{display:flex;flex-wrap:wrap;gap:3px 10px}
        .wc-order-preview-table .dtb-order-preview-context-facts strong{color:#475569;font-weight:600}
        .wc-order-preview-table .dtb-order-item-facts{display:flex;flex-wrap:wrap;gap:5px 7px;align-items:center;margin:7px 0}
        .wc-order-preview-table .dtb-order-chip{padding:2px 7px;border:1px solid #e0e6eb;border-radius:999px;background:#f5f7f9;font-weight:600;color:#374151}
        .wc-order-preview-table .dtb-order-identifier{display:inline-flex;gap:4px;align-items:center;color:#687484;font-size:11px}
        .wc-order-preview-table .dtb-order-identifier code{padding:2px 5px;border-radius:4px;background:#f1f5f9;color:#334155;font-size:11px}
        .wc-order-preview-table .dtb-order-muted{font-size:11px;color:#687484}
        .wc-order-preview-table .dtb-order-component-list{border:1px solid #e3e8ee;border-radius:7px;background:#fff;overflow:hidden;margin-top:6px}
        .wc-order-preview-table .dtb-order-component-list>summary{display:flex;gap:8px;justify-content:space-between;align-items:center;cursor:pointer;padding:7px 9px;font-weight:650;list-style-position:inside}
        .wc-order-preview-table .dtb-order-count{color:#64748b;font-size:10px;font-weight:500;white-space:nowrap}
        .wc-order-preview-table .dtb-order-component-scroll{max-height:220px;overflow:auto;border-top:1px solid #e3e8ee}
        .wc-order-preview-table .dtb-order-component-scroll table{width:100%;border-collapse:collapse;min-width:240px;margin:0}
        .wc-order-preview-table .dtb-order-component-scroll th,.wc-order-preview-table .dtb-order-component-scroll td{padding:5px 8px;text-align:left;border-bottom:1px solid #edf0f3;background:transparent;font-size:11px;vertical-align:top;overflow-wrap:anywhere}
        .wc-order-preview-table .dtb-order-component-scroll th{background:#f8fafc;color:#64748b;font-size:10px;font-weight:600}
        .wc-order-preview-table .dtb-order-component-scroll th:last-child,.wc-order-preview-table .dtb-order-component-scroll td:last-child{text-align:right;width:36px}
        .wc-order-preview-table .dtb-order-tech{margin-top:6px;color:#64748b;font-size:10px}
        .wc-order-preview-table .dtb-order-tech>summary{cursor:pointer;width:max-content;max-width:100%}
        .wc-order-preview-table .dtb-order-tech dl{margin:6px 0;padding:8px;background:#f8fafc;border-radius:5px;overflow-wrap:anywhere}
        .wc-order-preview-table .dtb-order-tech dt{font-weight:650;margin:6px 0 2px}
        .wc-order-preview-table .dtb-order-tech dd{margin:0}
        .wc-order-preview-table .dtb-order-tech code{white-space:pre-wrap;word-break:break-word;font-size:10px}
        .wc-order-preview-table .dtb-order-component-list>summary:focus-visible,.wc-order-preview-table .dtb-order-tech>summary:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
        .wc-order-preview-table .wc-order-item-sku{margin-top:3px;color:#475569;font-size:11px;font-weight:600}
        .wc-order-preview-table .wc-order-item-meta{margin:5px 0 0;border:0;border-collapse:collapse}
        .wc-order-preview-table .wc-order-item-meta th,.wc-order-preview-table .wc-order-item-meta td{padding:1px 8px 1px 0;border:0;background:transparent;font-size:11px;line-height:1.4;vertical-align:top}
        .wc-order-preview-table .wc-order-item-meta th{color:#64748b;font-weight:600}
        .wc-order-preview-table .wc-order-item-meta td{color:#334155}
        #woocommerce-order-items .dtb-order-item-facts{display:flex;flex-wrap:wrap;gap:6px 9px;align-items:center;margin:0 0 9px}
        #woocommerce-order-items .dtb-order-chip{padding:3px 9px;border:1px solid #e0e6eb;border-radius:999px;background:#f5f7f9;font-weight:600;color:#374151}
        #woocommerce-order-items .dtb-order-identifier{display:inline-flex;gap:5px;align-items:center;color:#687484;font-size:11px}
        #woocommerce-order-items .dtb-order-identifier code{padding:2px 5px;border-radius:4px;background:#f1f5f9;color:#334155;font-size:11px}
        #woocommerce-order-items .dtb-order-muted{font-size:11px;color:#687484}
        #woocommerce-order-items .dtb-order-component-list{border:1px solid #e3e8ee;border-radius:8px;background:#fff;overflow:hidden}
        #woocommerce-order-items .dtb-order-component-list>summary{display:flex;gap:12px;justify-content:space-between;align-items:center;cursor:pointer;padding:9px 12px;font-weight:650;list-style-position:inside}
        #woocommerce-order-items .dtb-order-count{color:#64748b;font-size:11px;font-weight:500;white-space:nowrap}
        #woocommerce-order-items .dtb-order-component-scroll{max-height:350px;overflow:auto;border-top:1px solid #e3e8ee}
        #woocommerce-order-items .dtb-order-component-scroll table{width:100%;border-collapse:collapse;min-width:330px;margin:0}
        #woocommerce-order-items .dtb-order-component-scroll th,#woocommerce-order-items .dtb-order-component-scroll td{padding:8px 12px;text-align:left;border-bottom:1px solid #edf0f3;background:transparent;font-size:12px;vertical-align:top;overflow-wrap:anywhere}
        #woocommerce-order-items .dtb-order-component-scroll th{background:#f8fafc;color:#64748b;font-size:11px;font-weight:600}
        #woocommerce-order-items .dtb-order-component-scroll th:last-child,#woocommerce-order-items .dtb-order-component-scroll td:last-child{text-align:right;width:44px}
        #woocommerce-order-items .dtb-order-tech{margin-top:8px;color:#64748b;font-size:11px}
        #woocommerce-order-items .dtb-order-tech>summary{cursor:pointer;width:max-content;max-width:100%}
        #woocommerce-order-items .dtb-order-tech dl{margin:8px 0;padding:10px;background:#f8fafc;border-radius:6px;overflow-wrap:anywhere}
        #woocommerce-order-items .dtb-order-tech dt{font-weight:650;margin:8px 0 2px}
        #woocommerce-order-items .dtb-order-tech dd{margin:0}
        #woocommerce-order-items .dtb-order-tech code{white-space:pre-wrap;word-break:break-word;font-size:11px}
        #woocommerce-order-items .dtb-order-component-list>summary:focus-visible,#woocommerce-order-items .dtb-order-tech>summary:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
        @media(max-width:782px){.wc-backbone-modal.wc-order-preview .wc-backbone-modal-content{width:calc(100vw - 24px);max-width:calc(100vw - 24px)}.wc-backbone-modal.wc-order-preview .wc-order-preview-addresses{grid-template-columns:1fr;padding:10px 12px;gap:8px}.wc-backbone-modal.wc-order-preview .wc-order-preview-addresses .wc-order-preview-address{width:auto;float:none;padding:11px 12px}.wc-backbone-modal.wc-order-preview article{max-height:calc(100vh - 150px)}.wc-order-preview-table-wrapper{padding:0 8px 8px}.wc-order-preview .wc-order-preview-table td,.wc-order-preview .wc-order-preview-table th{padding:9px 6px}.wc-order-preview .wc-order-preview-table td:first-child,.wc-order-preview .wc-order-preview-table th:first-child{padding-left:9px}.wc-order-preview .wc-order-preview-table td:last-child,.wc-order-preview .wc-order-preview-table th:last-child{padding-right:9px}.wc-order-preview-summary{max-width:none;margin:0 8px 12px;padding:10px 12px}#woocommerce-order-items .dtb-order-item-details{max-width:100%}#woocommerce-order-items .dtb-order-component-list>summary{flex-wrap:wrap}}
    ' );
}, 100 );
