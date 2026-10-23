<?php
/**
 * Read-only WooCommerce shipping data audit. WP-CLI only.
 *
 * wp --require=/path/to/scripts/wordpress/audit-shipping-packaging.php dtb shipping audit
 * wp --require=/path/to/scripts/wordpress/audit-shipping-packaging.php dtb shipping audit --format=csv
 *
 * No product writes, category-based guessed classifications, shipping rates,
 * external API requests, orders, shipment purchases or label mutations.
 */
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Evaluates product metadata only. WooCommerce product dimensions describe
 * products, not necessarily the packed shipping cartons.
 */
function dtb_shipping_audit_product_facts( WC_Product $product ): array {
    $fields = [
        'weight' => (string) $product->get_weight( 'edit' ),
        'length' => (string) $product->get_length( 'edit' ),
        'width'  => (string) $product->get_width( 'edit' ),
        'height' => (string) $product->get_height( 'edit' ),
    ];
    $missing = [];
    foreach ( $fields as $field => $value ) {
        if ( '' === $value || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value <= 0 ) {
            $missing[] = $field;
        }
    }
    $class = (string) $product->get_shipping_class( 'edit' );
    $issues = [];
    if ( $missing ) {
        $issues[] = 'missing_product_' . implode( '_', $missing );
    }
    if ( '' === $class ) {
        $issues[] = 'unassigned_shipping_class';
    }
    $special = in_array( $class, [ 'complete-toolsets', 'long-bulky-tools', 'oversized-freight' ], true );
    $carton = (string) $product->get_meta( '_dtb_shipping_carton_reviewed', true );
    if ( $special && 'yes' !== $carton ) {
        $issues[] = 'packaging_review_required';
    }
    if ( $product->is_type( 'variable' ) ) {
        $issues[] = 'review_variation_level_shipping';
    }
    return [
        'product_id'     => (string) $product->get_id(),
        'sku'            => (string) $product->get_sku( 'edit' ),
        'product_type'   => (string) $product->get_type(),
        'shipping_class'=> $class,
        'weight'        => $fields['weight'],
        'length'        => $fields['length'],
        'width'         => $fields['width'],
        'height'        => $fields['height'],
        'issues'        => implode( ';', $issues ),
    ];
}

final class DTB_Shipping_Packaging_Audit_Command {
    /**
     * Report missing product measurements, shipping classes and special-handling
     * review requirements. Never treats the product dimensions as a parcel box.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table or csv (default: table).
     *
     * [--status=<status>]
     * : WooCommerce product publication status (default: publish).
     *
     * @when after_wp_load
     */
    public function __invoke( $args, $assoc_args ): void {
        if ( ! function_exists( 'wc_get_products' ) ) {
            WP_CLI::error( 'WooCommerce is unavailable.' );
        }
        $format = (string) ( $assoc_args['format'] ?? 'table' );
        if ( ! in_array( $format, [ 'table', 'csv' ], true ) ) {
            WP_CLI::error( 'Allowed formats: table, csv.' );
        }
        $status = (string) ( $assoc_args['status'] ?? 'publish' );
        if ( ! in_array( $status, [ 'publish', 'draft' ], true ) ) {
            WP_CLI::error( 'Allowed product statuses: publish, draft.' );
        }

        $rows = [];
        $page = 1;
        $total = 0;
        do {
            // Bounded pages and read-only WooCommerce CRUD/HPOS-compatible APIs.
            $batch = wc_get_products( [
                'limit' => 100, 'page' => $page, 'status' => $status,
                'type' => [ 'simple', 'variable', 'variation' ],
                'orderby' => 'ID', 'order' => 'ASC',
            ] );
            foreach ( $batch as $product ) {
                if ( ! $product instanceof WC_Product || ! $product->needs_shipping() ) {
                    continue;
                }
                ++$total;
                $fact = dtb_shipping_audit_product_facts( $product );
                if ( '' !== $fact['issues'] ) {
                    $rows[] = $fact;
                }
            }
            ++$page;
        } while ( count( $batch ) === 100 && $page <= 200 );

        $columns = [ 'product_id', 'sku', 'product_type', 'shipping_class', 'weight', 'length', 'width', 'height', 'issues' ];
        if ( 'csv' === $format ) {
            WP_CLI\Utils\format_items( 'csv', $rows, $columns );
        } else {
            WP_CLI::log( sprintf( 'Reviewed %d shippable products; %d require attention. Units are WooCommerce catalog units, not verified outbound carton dimensions.', $total, count( $rows ) ) );
            if ( $rows ) {
                WP_CLI\Utils\format_items( 'table', $rows, $columns );
            }
        }
        if ( $page > 200 ) {
            WP_CLI::warning( 'Stopped at the 20,000-product safety limit; audit coverage is incomplete.' );
        }
    }
}
WP_CLI::add_command( 'dtb shipping audit', 'DTB_Shipping_Packaging_Audit_Command' );
