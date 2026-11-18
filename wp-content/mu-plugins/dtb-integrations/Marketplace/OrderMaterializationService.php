<?php
/**
 * Marketplace — Order Materialization Service.
 *
 * Converts imported Amazon/eBay marketplace read-model orders into canonical
 * WooCommerce orders, then dispatches them through the DTB order pipeline for
 * Veeqo fulfillment, QuickBooks accounting, notifications, and observability.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceOrderMaterializationService' ) ) {
	final class DTB_MarketplaceOrderMaterializationService {
		private const LOCK_TTL = 300;

		/** Materialize an Amazon order when it is safe to do so. */
		public static function materialize_amazon( int $marketplace_row_id, array $normalized, array $raw_order, array $order_items = [] ): array {
			return self::materialize( DTB_CHANNEL_AMAZON, $marketplace_row_id, $normalized, $raw_order, $order_items );
		}

		/** Materialize an eBay order when it is safe to do so. */
		public static function materialize_ebay( int $marketplace_row_id, array $normalized, array $raw_order ): array {
			$items = is_array( $raw_order['lineItems'] ?? null ) ? $raw_order['lineItems'] : [];
			return self::materialize( DTB_CHANNEL_EBAY, $marketplace_row_id, $normalized, $raw_order, $items );
		}

		private static function materialize( string $channel_key, int $marketplace_row_id, array $normalized, array $raw_order, array $raw_items ): array {
			if ( ! function_exists( 'wc_create_order' ) ) {
				return self::fail( $channel_key, $marketplace_row_id, 'woocommerce_unavailable', 'WooCommerce order creation is unavailable.', false );
			}

			$external_id = sanitize_text_field( (string) ( $normalized['marketplace_order_id'] ?? self::external_order_id( $channel_key, $raw_order ) ) );
			if ( '' === $external_id ) {
				return self::fail( $channel_key, $marketplace_row_id, 'missing_external_order_id', 'Marketplace order ID is missing.', false );
			}

			$current_link = self::current_linked_woo_order_id( $marketplace_row_id );
			if ( $current_link > 0 ) {
				return [ 'status' => 'already_linked', 'woo_order_id' => $current_link, 'message' => 'Marketplace row is already linked to a Woo order.' ];
			}

			$existing_woo_id = self::find_existing_woo_order( $channel_key, $external_id );
			if ( $existing_woo_id > 0 ) {
				self::link_marketplace_order( $channel_key, $external_id, $marketplace_row_id, $existing_woo_id );
				return [ 'status' => 'already_linked', 'woo_order_id' => $existing_woo_id, 'message' => 'Marketplace order already has a Woo order.' ];
			}

			$lock_key = self::lock_key( $channel_key, $external_id );
			if ( ! self::acquire_lock( $lock_key ) ) {
				return [ 'status' => 'locked', 'woo_order_id' => 0, 'message' => 'Marketplace order materialization is already in progress.' ];
			}

			try {
				// Re-check after acquiring lock to avoid duplicate Woo orders under concurrent imports.
				$existing_woo_id = self::find_existing_woo_order( $channel_key, $external_id );
				if ( $existing_woo_id > 0 ) {
					self::link_marketplace_order( $channel_key, $external_id, $marketplace_row_id, $existing_woo_id );
					return [ 'status' => 'already_linked', 'woo_order_id' => $existing_woo_id, 'message' => 'Marketplace order already has a Woo order.' ];
				}

				$payment_state = sanitize_key( (string) ( $normalized['payment_state'] ?? 'unknown' ) );
				if ( ! in_array( $payment_state, [ 'paid', 'pending' ], true ) ) {
					return self::fail( $channel_key, $marketplace_row_id, 'payment_state_not_materializable', 'Marketplace order payment state is not safe to materialize: ' . $payment_state, false );
				}

				$line_items = self::normalize_line_items( $channel_key, $raw_items );
				if ( empty( $line_items ) ) {
					return self::fail( $channel_key, $marketplace_row_id, 'missing_line_items', 'Marketplace order has no materializable line items.', true );
				}

				$unmapped = array_values( array_filter( $line_items, static fn( array $line ): bool => empty( $line['product_id'] ) ) );
				if ( ! empty( $unmapped ) ) {
					return self::fail(
						$channel_key,
						$marketplace_row_id,
						'sku_mapping_failed',
						'Marketplace order contains unmapped SKU(s): ' . implode( ', ', array_map( static fn( array $line ): string => $line['sku'] ?: $line['title'], $unmapped ) ),
						false,
						[ 'unmapped' => $unmapped ]
					);
				}

				$order = wc_create_order( [ 'status' => 'pending' ] );
				if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
					$message = is_wp_error( $order ) ? $order->get_error_message() : 'WooCommerce did not return an order object.';
					return self::fail( $channel_key, $marketplace_row_id, 'woo_order_create_failed', $message, true );
				}

				$order_id = (int) $order->get_id();
				self::apply_billing_shipping( $order, $channel_key, $raw_order, $external_id );

				foreach ( $line_items as $line ) {
					$product = wc_get_product( (int) $line['product_id'] );
					if ( ! $product ) {
						throw new RuntimeException( 'Mapped Woo product no longer exists for SKU: ' . $line['sku'] );
					}

					$quantity = max( 1, (int) $line['quantity'] );
					$total    = self::line_total_or_product_price( $line, $product, $quantity );
					$order->add_product( $product, $quantity, [
						'subtotal' => $total,
						'total'    => $total,
					] );
				}

				$shipping_total = self::extract_shipping_total( $channel_key, $raw_order );
				if ( $shipping_total > 0 ) {
					$shipping = new WC_Order_Item_Shipping();
					$shipping->set_method_title( ucfirst( $channel_key ) . ' marketplace shipping' );
					$shipping->set_method_id( $channel_key . '_marketplace_shipping' );
					$shipping->set_total( $shipping_total );
					$order->add_item( $shipping );
				}

				$order->set_created_via( $channel_key . '_marketplace_import' );
				self::apply_order_dates( $order, $normalized );
				self::apply_marketplace_meta( $order, $channel_key, $external_id, $marketplace_row_id, $normalized );
				$order->calculate_totals();

				$status = 'paid' === $payment_state ? 'processing' : 'on-hold';
				$order->update_status( $status, sprintf( '[DTB Marketplace] Imported %s order %s.', ucfirst( $channel_key ), $external_id ) );
				$order->save();

				self::link_marketplace_order( $channel_key, $external_id, $marketplace_row_id, $order_id );
				self::append_order_event( $order_id, $channel_key, $external_id, $marketplace_row_id, $payment_state, $line_items );
				self::dispatch_pipeline_jobs( $order_id, $payment_state );

				if ( class_exists( 'DTB_MarketplaceEventService' ) ) {
					DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_ORDER_LINKED, $channel_key, [
						'linked_order_id' => $marketplace_row_id,
						'woo_order_id'    => $order_id,
						'payload'         => [ 'marketplace_order_id' => $external_id, 'materialized' => true ],
					] );
				}

				return [ 'status' => 'materialized', 'woo_order_id' => $order_id, 'message' => 'Marketplace order materialized into WooCommerce.' ];
			} catch ( Throwable $e ) {
				return self::fail( $channel_key, $marketplace_row_id, 'materialization_exception', $e->getMessage(), true );
			} finally {
				self::release_lock( $lock_key );
			}
		}

		private static function normalize_line_items( string $channel_key, array $raw_items ): array {
			$lines = [];
			foreach ( $raw_items as $raw ) {
				if ( DTB_CHANNEL_AMAZON === $channel_key ) {
					$sku   = sanitize_text_field( (string) ( $raw['SellerSKU'] ?? '' ) );
					$title = sanitize_text_field( (string) ( $raw['Title'] ?? $sku ) );
					$qty   = max( 1, absint( $raw['QuantityOrdered'] ?? 1 ) );
					$total = self::amount_from_money( $raw['ItemPrice'] ?? [] );
				} else {
					$sku   = sanitize_text_field( (string) ( $raw['sku'] ?? $raw['legacyItemId'] ?? '' ) );
					$title = sanitize_text_field( (string) ( $raw['title'] ?? $raw['lineItemId'] ?? $sku ) );
					$qty   = max( 1, absint( $raw['quantity'] ?? 1 ) );
					$total = self::amount_from_money( $raw['lineItemCost'] ?? [] );
				}

				$product_id = self::find_product_id_by_sku( $sku );
				$lines[]    = [
					'sku'        => $sku,
					'title'      => $title ?: $sku,
					'quantity'   => $qty,
					'subtotal'   => max( 0, $total ),
					'total'      => max( 0, $total ),
					'product_id' => $product_id,
				];
			}
			return $lines;
		}

		private static function line_total_or_product_price( array $line, WC_Product $product, int $quantity ): float {
			$total = (float) ( $line['total'] ?? 0 );
			if ( $total > 0 ) {
				return (float) wc_format_decimal( $total, 2 );
			}

			$unit = (float) wc_format_decimal( (float) $product->get_price(), 2 );
			return max( 0.0, (float) wc_format_decimal( $unit * max( 1, $quantity ), 2 ) );
		}

		private static function find_product_id_by_sku( string $sku ): int {
			if ( '' === $sku || ! function_exists( 'wc_get_product_id_by_sku' ) ) {
				return 0;
			}
			return absint( wc_get_product_id_by_sku( $sku ) );
		}

		private static function amount_from_money( mixed $value ): float {
			if ( is_array( $value ) ) {
				return (float) wc_format_decimal( (float) ( $value['Amount'] ?? $value['value'] ?? $value['convertedFromValue'] ?? 0 ), 2 );
			}
			return (float) wc_format_decimal( (float) $value, 2 );
		}

		private static function extract_shipping_total( string $channel_key, array $raw_order ): float {
			if ( DTB_CHANNEL_EBAY === $channel_key ) {
				return self::amount_from_money( $raw_order['pricingSummary']['deliveryCost'] ?? [] );
			}

			return 0.0;
		}

		private static function apply_billing_shipping( WC_Order $order, string $channel_key, array $raw_order, string $external_id ): void {
			$email = self::buyer_email( $channel_key, $raw_order, $external_id );
			$name  = self::buyer_name( $channel_key, $raw_order );
			$addr  = self::shipping_address( $channel_key, $raw_order );

			$order->set_billing_first_name( $name['first_name'] );
			$order->set_billing_last_name( $name['last_name'] );
			$order->set_billing_email( $email );
			$order->set_shipping_first_name( $name['first_name'] );
			$order->set_shipping_last_name( $name['last_name'] );
			$order->set_shipping_address_1( $addr['address_1'] );
			$order->set_shipping_address_2( $addr['address_2'] );
			$order->set_shipping_city( $addr['city'] );
			$order->set_shipping_state( $addr['state'] );
			$order->set_shipping_postcode( $addr['postcode'] );
			$order->set_shipping_country( $addr['country'] ?: 'US' );
		}

		private static function buyer_email( string $channel_key, array $raw_order, string $external_id ): string {
			$email = DTB_CHANNEL_AMAZON === $channel_key
				? sanitize_email( (string) ( $raw_order['BuyerInfo']['BuyerEmail'] ?? '' ) )
				: sanitize_email( (string) ( $raw_order['buyer']['email'] ?? '' ) );

			if ( '' !== $email ) {
				return $email;
			}

			return 'marketplace+' . sanitize_key( $channel_key ) . '-' . substr( md5( $external_id ), 0, 12 ) . '@drywalltoolbox.local';
		}

		private static function buyer_name( string $channel_key, array $raw_order ): array {
			$name = DTB_CHANNEL_AMAZON === $channel_key
				? (string) ( $raw_order['BuyerInfo']['BuyerName'] ?? 'Marketplace Buyer' )
				: (string) ( $raw_order['buyer']['username'] ?? 'Marketplace Buyer' );
			$parts = preg_split( '/\s+/', trim( $name ), 2 );
			return [ 'first_name' => sanitize_text_field( $parts[0] ?? 'Marketplace' ), 'last_name' => sanitize_text_field( $parts[1] ?? 'Buyer' ) ];
		}

		private static function shipping_address( string $channel_key, array $raw_order ): array {
			$addr = DTB_CHANNEL_AMAZON === $channel_key
				? (array) ( $raw_order['ShippingAddress'] ?? [] )
				: (array) ( $raw_order['fulfillmentStartInstructions'][0]['shippingStep']['shipTo'] ?? [] );

			if ( DTB_CHANNEL_EBAY === $channel_key ) {
				$contact = (array) ( $addr['contactAddress'] ?? [] );
				return self::normalize_address( [
					'address_1' => $contact['addressLine1'] ?? '',
					'address_2' => $contact['addressLine2'] ?? '',
					'city'      => $contact['city'] ?? '',
					'state'     => $contact['stateOrProvince'] ?? '',
					'postcode'  => $contact['postalCode'] ?? '',
					'country'   => $contact['countryCode'] ?? 'US',
				] );
			}

			return self::normalize_address( [
				'address_1' => $addr['AddressLine1'] ?? '',
				'address_2' => $addr['AddressLine2'] ?? '',
				'city'      => $addr['City'] ?? '',
				'state'     => $addr['StateOrRegion'] ?? '',
				'postcode'  => $addr['PostalCode'] ?? '',
				'country'   => $addr['CountryCode'] ?? 'US',
			] );
		}

		private static function normalize_address( array $addr ): array {
			$normalized = [
				'address_1' => sanitize_text_field( (string) ( $addr['address_1'] ?? '' ) ),
				'address_2' => sanitize_text_field( (string) ( $addr['address_2'] ?? '' ) ),
				'city'      => sanitize_text_field( (string) ( $addr['city'] ?? '' ) ),
				'state'     => sanitize_text_field( (string) ( $addr['state'] ?? '' ) ),
				'postcode'  => sanitize_text_field( (string) ( $addr['postcode'] ?? '' ) ),
				'country'   => sanitize_text_field( (string) ( $addr['country'] ?? 'US' ) ),
			];

			if ( '' === $normalized['address_1'] ) {
				$normalized['address_1'] = 'Marketplace address pending';
			}

			return $normalized;
		}

		private static function apply_order_dates( WC_Order $order, array $normalized ): void {
			$placed = (string) ( $normalized['order_placed_at'] ?? '' );
			if ( '' !== $placed ) {
				$timestamp = strtotime( $placed );
				if ( $timestamp ) {
					$order->set_date_created( $timestamp );
				}
			}
		}

		private static function apply_marketplace_meta( WC_Order $order, string $channel_key, string $external_id, int $marketplace_row_id, array $normalized ): void {
			$order->update_meta_data( '_dtb_order_type', 'marketplace' );
			$order->update_meta_data( '_dtb_source_channel', $channel_key );
			$order->update_meta_data( '_dtb_marketplace_channel', $channel_key );
			$order->update_meta_data( '_dtb_marketplace_order_id', $external_id );
			$order->update_meta_data( '_dtb_marketplace_order_row_id', $marketplace_row_id );
			$order->update_meta_data( '_' . $channel_key . '_order_id', $external_id );
			$order->update_meta_data( '_dtb_marketplace_payment_state', sanitize_key( (string) ( $normalized['payment_state'] ?? 'unknown' ) ) );
			$order->update_meta_data( '_dtb_marketplace_fulfillment_state', sanitize_key( (string) ( $normalized['fulfillment_state'] ?? 'unknown' ) ) );
		}

		private static function link_marketplace_order( string $channel_key, string $external_id, int $marketplace_row_id, int $woo_order_id ): void {
			if ( class_exists( 'DTB_MarketplaceReadModels' ) ) {
				DTB_MarketplaceReadModels::upsert_order( [
					'channel_key'          => $channel_key,
					'marketplace_order_id' => $external_id,
					'woo_order_id'         => $woo_order_id,
				] );
			}
		}

		private static function append_order_event( int $order_id, string $channel_key, string $external_id, int $marketplace_row_id, string $payment_state, array $line_items ): void {
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'marketplace.order.materialized', [
					'source'          => $channel_key,
					'actor_type'      => $channel_key,
					'visibility'      => 'operator',
					'idempotency_key' => 'marketplace-materialized-' . $channel_key . '-' . $external_id,
					'payload'         => [
						'channel'              => $channel_key,
						'marketplace_order_id' => $external_id,
						'marketplace_row_id'   => $marketplace_row_id,
						'payment_state'        => $payment_state,
						'line_count'           => count( $line_items ),
					],
				] );
			}
		}

		private static function dispatch_pipeline_jobs( int $order_id, string $payment_state ): void {
			if ( ! function_exists( 'dtb_order_enqueue_job' ) ) {
				return;
			}
			dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
			if ( 'paid' === $payment_state ) {
				dtb_order_enqueue_job( 'dtb_order_sync_veeqo', $order_id, [ 'trigger' => 'marketplace_materialization' ] );
				dtb_order_enqueue_job( 'dtb_order_sync_quickbooks', $order_id, [ 'action' => 'create', 'trigger' => 'marketplace_materialization' ] );
			}
		}

		private static function find_existing_woo_order( string $channel_key, string $external_id ): int {
			if ( function_exists( 'wc_get_orders' ) ) {
				$keys = [ '_dtb_marketplace_order_id', '_' . sanitize_key( $channel_key ) . '_order_id' ];
				foreach ( array_unique( $keys ) as $key ) {
					$orders = wc_get_orders( [
						'limit'      => 1,
						'return'     => 'ids',
						'meta_query' => [
							[
								'key'   => $key,
								'value' => $external_id,
							],
						],
					] );
					if ( ! empty( $orders[0] ) ) {
						return absint( $orders[0] );
					}
				}
			}

			global $wpdb;
			$keys = [ '_dtb_marketplace_order_id', '_' . sanitize_key( $channel_key ) . '_order_id' ];
			foreach ( array_unique( $keys ) as $key ) {
				$found = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1", $key, $external_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $found ) {
					return absint( $found );
				}
			}
			return 0;
		}

		private static function current_linked_woo_order_id( int $marketplace_row_id ): int {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_orders';
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT woo_order_id FROM {$table} WHERE id = %d LIMIT 1", $marketplace_row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return absint( $found );
		}

		private static function lock_key( string $channel_key, string $external_id ): string {
			return 'dtb_marketplace_materialize_' . sanitize_key( $channel_key ) . '_' . md5( $external_id );
		}

		private static function acquire_lock( string $lock_key ): bool {
			$option_name = $lock_key . '_lock';
			if ( add_option( $option_name, (string) time(), '', 'no' ) ) {
				return true;
			}

			$locked_at = (int) get_option( $option_name, 0 );
			if ( $locked_at > 0 && ( time() - $locked_at ) < self::LOCK_TTL ) {
				return false;
			}

			global $wpdb;
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) time(), $option_name, (string) $locked_at ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 1 === $claimed;
		}

		private static function release_lock( string $lock_key ): void {
			delete_option( $lock_key . '_lock' );
		}

		private static function external_order_id( string $channel_key, array $raw_order ): string {
			return DTB_CHANNEL_AMAZON === $channel_key
				? sanitize_text_field( (string) ( $raw_order['AmazonOrderId'] ?? '' ) )
				: sanitize_text_field( (string) ( $raw_order['orderId'] ?? '' ) );
		}

		private static function fail( string $channel_key, int $marketplace_row_id, string $code, string $message, bool $retryable, array $context = [] ): array {
			if ( class_exists( 'DTB_MarketplaceExceptionService' ) ) {
				DTB_MarketplaceExceptionService::create( DTB_MarketplaceExceptionService::CAT_ORDER_LINKING, $channel_key, $code, $message, [
					'linked_record_type' => 'marketplace_order',
					'linked_record_id'   => $marketplace_row_id,
					'is_retryable'       => $retryable,
					'context'            => $context,
				] );
			}
			return [ 'status' => 'failed', 'woo_order_id' => 0, 'message' => $message ];
		}
	}
}
