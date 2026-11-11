<?php
/**
 * Keep prohibited duplicate identity fields out of WooCommerce product specs on CSV import.
 */
defined( 'ABSPATH' ) || exit;

final class DTB_CatalogSpecificationsCsv {
	/**
	 * Remove excluded rows from canonical and legacy spec metadata before WooCommerce saves imports.
	 *
	 * @param WC_Product $product Product object prepared by the native importer.
	 * @return WC_Product
	 */
	public static function pre_insert( $product, array $data ) {
		if ( ! $product instanceof WC_Product ) {
			return $product;
		}

		$excluded_indexes = [];
		foreach ( $product->get_meta_data() as $meta ) {
			$entry = $meta->get_data();
			$key   = (string) ( $entry['key'] ?? '' );
			if ( preg_match( '/^_specs_(\d+)_label$/', $key, $matches ) && DTB_CatalogSpecificationPolicy::is_excluded_label( $entry['value'] ?? '' ) ) {
				$excluded_indexes[ $matches[1] ] = true;
			}
		}

		if ( ! empty( $excluded_indexes ) ) {
			foreach ( array_keys( $excluded_indexes ) as $index ) {
				$product->delete_meta_data( '_specs_' . $index . '_label' );
				$product->delete_meta_data( '_specs_' . $index . '_value' );
			}
		}

		foreach ( [ '_dtb_specs_json', 'dtb_specs_json' ] as $canonical_key ) {
			$canonical = $product->get_meta( $canonical_key, true, 'edit' );
			if ( is_string( $canonical ) ) {
				$rows = json_decode( $canonical, true );
				if ( is_array( $rows ) ) {
					$filtered = DTB_CatalogSpecificationPolicy::filter_rows( $rows );
					if ( count( $filtered ) !== count( $rows ) ) {
						$product->update_meta_data( $canonical_key, wp_json_encode( $filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
					}
				}
			} elseif ( is_array( $canonical ) ) {
				$filtered = DTB_CatalogSpecificationPolicy::filter_rows( $canonical );
				if ( count( $filtered ) !== count( $canonical ) ) {
					$product->update_meta_data( $canonical_key, $filtered );
				}
			}
		}

		return $product;
	}
}

add_filter( 'woocommerce_product_import_pre_insert_product_object', [ DTB_CatalogSpecificationsCsv::class, 'pre_insert' ], 8, 2 );
