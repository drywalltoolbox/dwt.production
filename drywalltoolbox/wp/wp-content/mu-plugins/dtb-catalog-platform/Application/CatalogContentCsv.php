<?php
/**
 * Validated opt-in WooCommerce CSV Features metadata bridge.
 *
 * WooCommerce remains the product CRUD/import/export authority. This adapter
 * validates only present v2 metadata and keeps stored JSON a scalar CSV-safe
 * value. No changes to prices, inventory, SKU or variation associations.
 */
defined( 'ABSPATH' ) || exit;

final class DTB_CatalogContentCsv {
    public const FEATURES = '_dtb_features_json';
    public const VERSION = '_dtb_content_schema_version';

    /** Return validated canonical JSON, or WP_Error without mutating input. */
    public static function normalize_features( string $value ) {
        if ( strlen( $value ) > 65536 ) {
            return new WP_Error( 'dtb_features_size', 'Features metadata exceeds the size limit.' );
        }
        $doc = json_decode( $value, true );
        if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $doc )
            || array_keys( $doc ) !== [ 'schema_version', 'features' ]
            || 1 !== ( $doc['schema_version'] ?? null )
            || ! isset( $doc['features'] ) || ! is_array( $doc['features'] )
            || ! array_is_list( $doc['features'] ) || count( $doc['features'] ) > 50 ) {
            return new WP_Error( 'dtb_features_schema', 'Invalid Features JSON schema.' );
        }
        $features = [];
        foreach ( $doc['features'] as $item ) {
            if ( ! is_array( $item ) || array_keys( $item ) !== [ 'label', 'description' ]
                || ! is_string( $item['label'] ?? null ) || ! is_string( $item['description'] ?? null ) ) {
                return new WP_Error( 'dtb_features_entry', 'Invalid Features entry shape.' );
            }
            $label = trim( $item['label'] );
            $description = trim( $item['description'] );
            if ( '' === $label || '' === $description || strlen( $label ) > 160 || strlen( $description ) > 2000
                || $label !== wp_strip_all_tags( $label ) || $description !== wp_strip_all_tags( $description ) ) {
                return new WP_Error( 'dtb_features_value', 'Invalid Features entry content.' );
            }
            $features[] = [ 'label' => $label, 'description' => $description ];
        }
        return wp_json_encode( [ 'schema_version' => 1, 'features' => $features ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
    }

    /**
     * Validate importer metadata before WooCommerce saves the WC_Product.
     * Missing fields are left untouched during update imports.
     * An empty field means no update rather than clearing approved content.
     */
    public static function pre_insert( $product, array $data ) {
        if ( ! $product instanceof WC_Product ) {
            return $product;
        }
        $meta = $data['meta_data'] ?? [];
        if ( ! is_array( $meta ) ) {
            return $product;
        }
        $present = [];
        foreach ( $meta as $entry ) {
            if ( is_array( $entry ) && isset( $entry['key'] )
                && in_array( $entry['key'], [ self::FEATURES, self::VERSION ], true ) ) {
                $key = $entry['key'];
                if ( array_key_exists( $key, $present ) ) {
                    throw new RuntimeException( 'Duplicate DTB content meta field in import row.' );
                }
                $present[ $key ] = $entry['value'] ?? '';
            }
        }
        if ( ! array_key_exists( self::FEATURES, $present ) && ! array_key_exists( self::VERSION, $present ) ) {
            return $product;
        }
        $raw_features = $present[ self::FEATURES ] ?? '';
        $raw_version = $present[ self::VERSION ] ?? '';
        if ( ! is_scalar( $raw_features ) || ! is_scalar( $raw_version ) ) {
            throw new RuntimeException( 'DTB content meta must be scalar CSV text.' );
        }
        // Blank metadata is intentionally non-destructive on existing products.
        if ( '' === trim( (string) $raw_features ) && '' === trim( (string) $raw_version ) ) {
            return $product;
        }
        if ( '2' !== trim( (string) $raw_version ) || '' === trim( (string) $raw_features ) ) {
            throw new RuntimeException( 'Features JSON and content schema version 2 must be supplied together.' );
        }
        $normalized = self::normalize_features( (string) $raw_features );
        if ( is_wp_error( $normalized ) ) {
            throw new RuntimeException( $normalized->get_error_message() );
        }
        // This is a WC CRUD object; WooCommerce executes its own save only after this filter.
        $product->update_meta_data( self::FEATURES, $normalized );
        $product->update_meta_data( self::VERSION, '2' );
        return $product;
    }

    /** Export canonical scalar representations through WooCommerce's native metadata exporter. */
    public static function export_meta_value( $value, $meta, $product ) {
        if ( ! is_object( $meta ) || ! in_array( $meta->key ?? null, [ self::FEATURES, self::VERSION ], true ) ) {
            return $value;
        }
        return is_scalar( $value ) ? (string) $value : '';
    }
}

add_filter( 'woocommerce_product_import_pre_insert_product_object', [ DTB_CatalogContentCsv::class, 'pre_insert' ], 9, 2 );
