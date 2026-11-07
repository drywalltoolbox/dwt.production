<?php
/**
 * Drywall Toolbox PDF document helpers.
 *
 * Scoped to the WP Overnight PDF template runtime. These helpers intentionally
 * avoid global business logic and only normalize presentation values that are
 * already available on the WooCommerce order/document object.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_pdf_document_type' ) ) {
	/**
	 * Return the current WP Overnight document type.
	 *
	 * @param object $document PDF document object.
	 * @param string $fallback Fallback type.
	 * @return string
	 */
	function dtb_pdf_document_type( $document, string $fallback = 'invoice' ): string {
		if ( is_object( $document ) && method_exists( $document, 'get_type' ) ) {
			$type = (string) $document->get_type();
			return '' !== $type ? $type : $fallback;
		}

		return $fallback;
	}
}

if ( ! function_exists( 'dtb_pdf_capture_document_method' ) ) {
	/**
	 * Capture output/return value from a WP Overnight document method.
	 *
	 * @param object $document PDF document object.
	 * @param string $method   Method name.
	 * @return string
	 */
	function dtb_pdf_capture_document_method( $document, string $method ): string {
		if ( ! is_object( $document ) || ! method_exists( $document, $method ) ) {
			return '';
		}

		ob_start();
		$result = $document->{$method}();
		$output = ob_get_clean();

		if ( is_string( $result ) && '' !== trim( $result ) ) {
			return $result;
		}

		return is_string( $output ) ? $output : '';
	}
}

if ( ! function_exists( 'dtb_pdf_clean_text' ) ) {
	/**
	 * Normalize text for compact PDF rendering.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	function dtb_pdf_clean_text( $value ): string {
		return trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'dtb_pdf_document_number' ) ) {
	/**
	 * Resolve invoice/order document number.
	 *
	 * @param object   $document PDF document object.
	 * @param WC_Order $order    WooCommerce order.
	 * @param string   $method   Preferred document method.
	 * @return string
	 */
	function dtb_pdf_document_number( $document, $order, string $method ): string {
		$value = dtb_pdf_clean_text( dtb_pdf_capture_document_method( $document, $method ) );
		if ( '' !== $value ) {
			return $value;
		}

		return $order instanceof WC_Order ? (string) $order->get_order_number() : '';
	}
}

if ( ! function_exists( 'dtb_pdf_document_date' ) ) {
	/**
	 * Resolve a formatted invoice/order date.
	 *
	 * @param object $document PDF document object.
	 * @param string $method   Preferred document method.
	 * @return string
	 */
	function dtb_pdf_document_date( $document, string $method ): string {
		return dtb_pdf_clean_text( dtb_pdf_capture_document_method( $document, $method ) );
	}
}

if ( ! function_exists( 'dtb_pdf_fallback_address' ) ) {
	/**
	 * Build an address fallback from WooCommerce order getters.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @param string   $type  billing|shipping.
	 * @return string
	 */
	function dtb_pdf_fallback_address( $order, string $type ): string {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$prefix = 'shipping' === $type ? 'shipping' : 'billing';
		$lines  = [
			trim( (string) $order->{"get_{$prefix}_first_name"}() . ' ' . (string) $order->{"get_{$prefix}_last_name"}() ),
			(string) $order->{"get_{$prefix}_company"}(),
			(string) $order->{"get_{$prefix}_address_1"}(),
			(string) $order->{"get_{$prefix}_address_2"}(),
			trim( (string) $order->{"get_{$prefix}_city"}() . ', ' . (string) $order->{"get_{$prefix}_state"}() . ' ' . (string) $order->{"get_{$prefix}_postcode"}(), " ,\t\n\r\0\x0B" ),
			(string) $order->{"get_{$prefix}_country"}(),
		];

		$lines = array_values(
			array_filter(
				array_map( 'dtb_pdf_clean_text', $lines ),
				static function ( string $line ): bool {
					return '' !== $line && ',' !== $line;
				}
			)
		);

		return implode( '<br>', array_map( 'esc_html', $lines ) );
	}
}

if ( ! function_exists( 'dtb_pdf_address_html' ) ) {
	/**
	 * Resolve billing or shipping address HTML.
	 *
	 * @param object   $document PDF document object.
	 * @param WC_Order $order    WooCommerce order.
	 * @param string   $type     billing|shipping.
	 * @return string
	 */
	function dtb_pdf_address_html( $document, $order, string $type ): string {
		$method = 'shipping' === $type ? 'shipping_address' : 'billing_address';
		$html   = trim( dtb_pdf_capture_document_method( $document, $method ) );

		if ( '' === wp_strip_all_tags( $html ) ) {
			$html = dtb_pdf_fallback_address( $order, $type );
		}

		return wp_kses_post( $html );
	}
}

if ( ! function_exists( 'dtb_pdf_contact_lines' ) ) {
	/**
	 * Customer contact lines for the invoice address block.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	function dtb_pdf_contact_lines( $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$lines = [];
		$email = sanitize_email( (string) $order->get_billing_email() );
		$phone = dtb_pdf_clean_text( $order->get_billing_phone() );

		if ( '' !== $email ) {
			$lines[] = esc_html( $email );
		}
		if ( '' !== $phone ) {
			$lines[] = esc_html( $phone );
		}

		return implode( '<br>', $lines );
	}
}

if ( ! function_exists( 'dtb_pdf_product_brand' ) ) {
	/**
	 * Resolve brand from common WooCommerce brand taxonomies/attributes.
	 *
	 * @param WC_Product|null $product Product.
	 * @return string
	 */
	function dtb_pdf_product_brand( $product ): string {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$product_ids = [ $product->get_id() ];
		if ( $product->get_parent_id() ) {
			$product_ids[] = $product->get_parent_id();
		}

		foreach ( [ 'product_brand', 'pa_brand', 'brand' ] as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			foreach ( array_unique( array_filter( $product_ids ) ) as $product_id ) {
				$terms = get_the_terms( $product_id, $taxonomy );
				if ( is_array( $terms ) && ! empty( $terms ) && ! is_wp_error( $terms ) ) {
					return dtb_pdf_clean_text( $terms[0]->name ?? '' );
				}
			}
		}

		foreach ( [ 'pa_brand', 'brand' ] as $attribute ) {
			$value = dtb_pdf_clean_text( $product->get_attribute( $attribute ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'dtb_pdf_item_product' ) ) {
	/**
	 * Resolve WooCommerce product for a document item.
	 *
	 * @param WC_Order $order   WooCommerce order.
	 * @param mixed    $item_id Order item ID.
	 * @return WC_Product|null
	 */
	function dtb_pdf_item_product( $order, $item_id ) {
		if ( ! $order instanceof WC_Order ) {
			return null;
		}

		$order_item = $order->get_item( $item_id );
		if ( $order_item instanceof WC_Order_Item_Product ) {
			$product = $order_item->get_product();
			return $product instanceof WC_Product ? $product : null;
		}

		return null;
	}
}

if ( ! function_exists( 'dtb_pdf_item_identity' ) ) {
	/**
	 * Product identity chips for invoice/packing rows.
	 *
	 * @param WC_Order $order   WooCommerce order.
	 * @param mixed    $item_id Order item ID.
	 * @param array    $item    WP Overnight normalized item.
	 * @return string[]
	 */
	function dtb_pdf_item_identity( $order, $item_id, array $item ): array {
		$product = dtb_pdf_item_product( $order, $item_id );
		$chips   = [];

		$sku = dtb_pdf_clean_text( $item['sku'] ?? '' );
		if ( '' === $sku && $product instanceof WC_Product ) {
			$sku = dtb_pdf_clean_text( $product->get_sku() );
		}
		if ( '' !== $sku ) {
			$chips[] = 'SKU: ' . $sku;
		}

		$brand = dtb_pdf_product_brand( $product );
		if ( '' !== $brand ) {
			$chips[] = 'Brand: ' . $brand;
		}

		$mpn = '';
		if ( $product instanceof WC_Product ) {
			foreach ( [ '_mpn', 'mpn', '_dtb_mpn', 'manufacturer_part_number', '_manufacturer_part_number' ] as $key ) {
				$mpn = dtb_pdf_clean_text( $product->get_meta( $key, true ) );
				if ( '' !== $mpn ) {
					break;
				}
			}
		}
		if ( '' !== $mpn ) {
			$chips[] = 'MPN: ' . $mpn;
		}

		return array_values( array_unique( $chips ) );
	}
}

if ( ! function_exists( 'dtb_pdf_item_meta_html' ) ) {
	/**
	 * Item meta HTML returned by WP Overnight.
	 *
	 * @param array $item Normalized document item.
	 * @return string
	 */
	function dtb_pdf_item_meta_html( array $item ): string {
		$meta = $item['meta'] ?? '';
		if ( is_array( $meta ) ) {
			$meta = implode( ' ', array_map( 'dtb_pdf_clean_text', $meta ) );
		}

		return wp_kses_post( (string) $meta );
	}
}

if ( ! function_exists( 'dtb_pdf_item_thumbnail_html' ) ) {
	/**
	 * Safe item thumbnail HTML if WP Overnight provides it.
	 *
	 * @param array $item Normalized document item.
	 * @return string
	 */
	function dtb_pdf_item_thumbnail_html( array $item ): string {
		$thumbnail = $item['thumbnail'] ?? '';
		if ( ! is_string( $thumbnail ) || '' === trim( $thumbnail ) ) {
			return '';
		}

		return wp_kses_post( $thumbnail );
	}
}

if ( ! function_exists( 'dtb_pdf_formatted_order_note' ) ) {
	/**
	 * Customer note for packing slips.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	function dtb_pdf_formatted_order_note( $order ): string {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		return wp_kses_post( nl2br( esc_html( (string) $order->get_customer_note() ) ) );
	}
}

if ( ! function_exists( 'dtb_pdf_support_url' ) ) {
	/**
	 * Public support/tracking URL for document footer.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	function dtb_pdf_support_url( $order ): string {
		$order_number = $order instanceof WC_Order ? rawurlencode( (string) $order->get_order_number() ) : '';
		return esc_url( home_url( $order_number ? '/order-tracking/' . $order_number : '/contact' ) );
	}
}