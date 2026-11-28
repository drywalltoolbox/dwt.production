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
		private const CREATED_VIA_PREFIX = 'dtb_mp_';
		private const CONTRACT_VERSION   = '2';

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
			if ( ! function_exists( 'wc_get_orders' ) ) {
				return self::fail( $channel_key, $marketplace_row_id, 'woocommerce_order_lookup_unavailable', 'HPOS-compatible WooCommerce order lookup is unavailable.', false );
			}
			if ( ! class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
				|| ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			) {
				return self::fail( $channel_key, $marketplace_row_id, 'woocommerce_hpos_lookup_unavailable', 'Marketplace order lookup requires the configured HPOS order store.', false );
			}

			$external_id = sanitize_text_field( (string) ( $normalized['marketplace_order_id'] ?? self::external_order_id( $channel_key, $raw_order ) ) );
			if ( $marketplace_row_id <= 0 ) {
				return self::fail( $channel_key, $marketplace_row_id, 'missing_marketplace_row_id', 'Marketplace row identity is missing.', false );
			}
			if ( '' === $external_id ) {
				return self::fail( $channel_key, $marketplace_row_id, 'missing_external_order_id', 'Marketplace order ID is missing.', false );
			}

			if ( ! function_exists( 'dtb_marketplace_materialization_enabled' ) || ! dtb_marketplace_materialization_enabled() ) {
				if ( function_exists( 'dtb_marketplace_materialization_log_disabled' ) ) {
					dtb_marketplace_materialization_log_disabled( $channel_key . '_direct' );
				}
				return [ 'status' => 'disabled', 'woo_order_id' => 0, 'message' => 'Marketplace order materialization is disabled.' ];
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
			foreach ( $line_items as &$line_item ) {
				$product = wc_get_product( (int) $line_item['product_id'] );
				if ( ! $product instanceof WC_Product ) {
					return self::fail( $channel_key, $marketplace_row_id, 'mapped_product_unavailable', 'A mapped WooCommerce product is unavailable.', true );
				}
				$line_item['total']    = self::line_total_or_product_price( $line_item, $product, max( 1, (int) $line_item['quantity'] ) );
				$line_item['subtotal'] = $line_item['total'];
			}
			unset( $line_item );
			$shipping_total = self::extract_shipping_total( $channel_key, $raw_order );
			$fingerprint    = self::materialization_fingerprint( $line_items, $shipping_total, $payment_state );

			$lock_key = self::lock_key( $channel_key, $external_id );
			if ( ! self::acquire_lock( $lock_key ) ) {
				return [ 'status' => 'locked', 'woo_order_id' => 0, 'message' => 'Marketplace order materialization is already in progress.' ];
			}

			try {
				$current_link = self::current_linked_woo_order_id( $marketplace_row_id );
				if ( $current_link > 0 ) {
					if ( ! self::woo_order_matches_marketplace_identity( $current_link, $channel_key, $external_id ) ) {
						return self::fail( $channel_key, $marketplace_row_id, 'marketplace_woo_link_identity_mismatch', 'The linked WooCommerce order does not match the marketplace channel and order ID.', false );
					}
					$order = wc_get_order( $current_link );
					if ( $order instanceof WC_Order && self::is_contract_order( $order ) ) {
						return self::reconcile_complete_order( $order, $channel_key, $external_id, $marketplace_row_id, $payment_state, $line_items, $shipping_total, $fingerprint );
					}
					return [ 'status' => 'already_linked', 'woo_order_id' => $current_link, 'message' => 'Legacy marketplace row is already linked; automatic downstream replay is not permitted.' ];
				}

				// Re-check after acquiring the database mutex to avoid duplicate Woo orders.
				$existing_woo_id = self::find_existing_woo_order( $channel_key, $external_id );
				if ( $existing_woo_id < 0 ) {
					return self::fail( $channel_key, $marketplace_row_id, 'ambiguous_woo_marketplace_identity', 'Multiple WooCommerce orders match this marketplace channel and order ID; automatic linking is blocked.', false );
				}
				if ( $existing_woo_id > 0 ) {
					$order = wc_get_order( $existing_woo_id );
					if ( $order instanceof WC_Order && self::is_contract_order( $order ) ) {
						return self::reconcile_complete_order( $order, $channel_key, $external_id, $marketplace_row_id, $payment_state, $line_items, $shipping_total, $fingerprint );
					}
					if ( ! self::link_marketplace_order( $channel_key, $external_id, $marketplace_row_id, $existing_woo_id ) ) {
						throw new RuntimeException( 'The legacy marketplace row link could not be verified.' );
					}
					return [ 'status' => 'already_linked', 'woo_order_id' => $existing_woo_id, 'message' => 'Legacy marketplace order linked without replaying downstream work.' ];
				}

				if ( ! class_exists( 'DTB_MarketplaceEventService' ) ) {
					throw new RuntimeException( 'Marketplace event ledger is unavailable for durable reservation.' );
				}
				$reservation = DTB_MarketplaceEventService::claim_materialization_reservation( $channel_key, $external_id, $marketplace_row_id );
				if ( 'error' === ( $reservation['status'] ?? '' ) || empty( $reservation['token'] ) ) {
					throw new RuntimeException( 'Marketplace materialization reservation could not be durably established.' );
				}
				$reservation_token = (string) $reservation['token'];
				$correlated_order  = self::find_woo_order_by_reservation( $reservation_token );
				if ( $correlated_order < 0 ) {
					return self::fail( $channel_key, $marketplace_row_id, 'ambiguous_materialization_reservation', 'Multiple WooCommerce orders carry the same marketplace reservation token.', false );
				}
				if ( 'held' === ( $reservation['status'] ?? '' ) ) {
					if ( $correlated_order <= 0 ) {
						return self::fail( $channel_key, $marketplace_row_id, 'materialization_recovery_required', 'A durable reservation exists but no correlated WooCommerce order can be proven. Operator recovery is required.', false, [ 'reservation_event_id' => absint( $reservation['event_id'] ?? 0 ) ] );
					}
					$order = wc_get_order( $correlated_order );
					if ( $order instanceof WC_Order
						&& 'complete' === sanitize_key( (string) $order->get_meta( '_dtb_marketplace_materialization_state', true ) )
						&& self::woo_order_matches_marketplace_identity( $correlated_order, $channel_key, $external_id )
					) {
						return self::reconcile_complete_order( $order, $channel_key, $external_id, $marketplace_row_id, $payment_state, $line_items, $shipping_total, $fingerprint );
					}
					$state = $order instanceof WC_Order ? sanitize_key( (string) $order->get_meta( '_dtb_marketplace_materialization_state', true ) ) : 'unknown';
					return self::fail(
						$channel_key,
						$marketplace_row_id,
						'materialization_recovery_required',
						'A reservation-correlated WooCommerce order is incomplete; automatic rebuilding is blocked.',
						false,
						[ 'woo_order_id' => $correlated_order, 'materialization_state' => $state ?: 'unidentified' ]
					);
				}
				if ( $correlated_order > 0 ) {
					return self::fail( $channel_key, $marketplace_row_id, 'materialization_reservation_collision', 'A newly won reservation already resolves to a WooCommerce order; automatic creation is blocked.', false, [ 'woo_order_id' => $correlated_order ] );
				}

				$order = wc_create_order( [
					'status'      => 'pending',
					'created_via' => self::reservation_created_via( $reservation_token ),
				] );
				if ( is_wp_error( $order ) || ! $order instanceof WC_Order ) {
					$message = is_wp_error( $order ) ? $order->get_error_message() : 'WooCommerce did not return an order object.';
					return self::fail( $channel_key, $marketplace_row_id, 'woo_order_create_failed', $message, true );
				}

				$order_id = (int) $order->get_id();
				self::apply_marketplace_meta( $order, $channel_key, $external_id, $marketplace_row_id, $normalized );
				$order->update_meta_data( '_dtb_marketplace_reservation_token', $reservation_token );
				$order->update_meta_data( '_dtb_marketplace_identity_hash', (string) $reservation['identity_hash'] );
				$order->update_meta_data( '_dtb_marketplace_materialization_contract', self::CONTRACT_VERSION );
				$order->update_meta_data( '_dtb_marketplace_materialization_fingerprint', $fingerprint );
				$order->update_meta_data( '_dtb_marketplace_orchestration_state', 'building' );
				$order->update_meta_data( '_dtb_marketplace_materialization_state', 'building' );
				$order->save();

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

				if ( $shipping_total > 0 ) {
					$shipping = new WC_Order_Item_Shipping();
					$shipping->set_method_title( ucfirst( $channel_key ) . ' marketplace shipping' );
					$shipping->set_method_id( $channel_key . '_marketplace_shipping' );
					$shipping->set_total( $shipping_total );
					$order->add_item( $shipping );
				}

				self::apply_order_dates( $order, $normalized );
				$order->calculate_totals();

				$order->update_meta_data( '_dtb_marketplace_materialization_state', 'complete' );
				$order->update_meta_data( '_dtb_marketplace_orchestration_state', 'dispatch_pending' );
				$order->save();
				return self::reconcile_complete_order( $order, $channel_key, $external_id, $marketplace_row_id, $payment_state, $line_items, $shipping_total, $fingerprint );
			} catch ( Throwable $e ) {
				if ( isset( $order ) && $order instanceof WC_Order && $order->get_id() > 0 ) {
					try {
						$current_order = wc_get_order( (int) $order->get_id() );
						if ( $current_order instanceof WC_Order ) {
							$order = $current_order;
						}
						$structural_state = sanitize_key( (string) $order->get_meta( '_dtb_marketplace_materialization_state', true ) );
						$orchestration_state = sanitize_key( (string) $order->get_meta( '_dtb_marketplace_orchestration_state', true ) );
						if ( 'complete' !== $structural_state ) {
							$order->update_meta_data( '_dtb_marketplace_materialization_state', 'failed' );
						}
						if ( 'complete' !== $orchestration_state ) {
							$order->update_meta_data( '_dtb_marketplace_orchestration_state', 'recovery_required' );
							$order->update_meta_data( '_dtb_marketplace_materialization_error_code', 'materialization_exception' );
						} else {
							// A retry failure cannot downgrade verified structural completion.
							// Queue/recovery receipts retain the separate dispatch failure evidence.
							$order->update_meta_data( '_dtb_marketplace_dispatch_error_code', 'reconciliation_exception' );
						}
						$order->save();
					} catch ( Throwable $recovery_error ) {
						// Keep the original failure primary; recovery persistence is best-effort.
					}
				}
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

		private static function link_marketplace_order( string $channel_key, string $external_id, int $marketplace_row_id, int $woo_order_id ): bool {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_orders';
			// Compare-and-set prevents this recovery path from replacing a different
			// Woo link or linking the wrong marketplace identity.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET woo_order_id = %d, updated_at = %s
				 WHERE id = %d AND channel_key = %s AND marketplace_order_id = %s
				 AND (woo_order_id IS NULL OR woo_order_id = 0 OR woo_order_id = %d)",
				$woo_order_id,
				current_time( 'mysql', true ),
				$marketplace_row_id,
				$channel_key,
				$external_id,
				$woo_order_id
			) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$stored = $wpdb->get_var( $wpdb->prepare(
				"SELECT woo_order_id FROM {$table} WHERE id = %d AND channel_key = %s AND marketplace_order_id = %s LIMIT 1",
				$marketplace_row_id,
				$channel_key,
				$external_id
			) );
			return $woo_order_id === absint( $stored );
		}

		private static function append_order_event( int $order_id, string $channel_key, string $external_id, int $marketplace_row_id, string $payment_state, array $line_items ): bool {
			if ( function_exists( 'dtb_order_append_event' ) && function_exists( 'dtb_order_event_idempotency_exists' ) ) {
				$key = 'marketplace-materialized:v2:' . DTB_MarketplaceEventService::materialization_identity_hash( $channel_key, $external_id );
				dtb_order_append_event( $order_id, 'marketplace.order.materialized', [
					'source'          => $channel_key,
					'actor_type'      => $channel_key,
					'visibility'      => 'operator',
					'idempotency_key' => $key,
					'payload'         => [
						'channel'              => $channel_key,
						'marketplace_order_id' => $external_id,
						'marketplace_row_id'   => $marketplace_row_id,
						'payment_state'        => $payment_state,
						'line_count'           => count( $line_items ),
					],
				] );
				return dtb_order_event_idempotency_exists( $key );
			}
			return false;
		}

		private static function dispatch_pipeline_jobs( int $order_id, string $payment_state ): array {
			if ( ! function_exists( 'dtb_order_enqueue_job_with_receipt' ) ) {
				return [ [ 'status' => 'schedule_failed', 'message' => 'Structured order queue receipt API is unavailable.' ] ];
			}
			$results   = [];
			$results[] = dtb_order_enqueue_job_with_receipt( 'dtb_order_refresh_tracking_projection', $order_id, [ 'trigger' => 'marketplace_materialization' ] );
			if ( 'paid' === $payment_state ) {
				if ( function_exists( 'dtb_order_veeqo_order_sync_enabled' ) && dtb_order_veeqo_order_sync_enabled() ) {
					$results[] = dtb_order_enqueue_job_with_receipt( 'dtb_order_sync_veeqo', $order_id, [ 'trigger' => 'marketplace_materialization' ] );
				} else {
					$results[] = dtb_order_enqueue_job_with_receipt( 'dtb_order_sync_veeqo', $order_id, [], 0, [ 'not_applicable_reason' => 'veeqo_sync_disabled' ] );
				}
				$results[] = dtb_order_enqueue_job_with_receipt( 'dtb_order_sync_quickbooks', $order_id, [ 'action' => 'create', 'trigger' => 'marketplace_materialization' ] );
			} else {
				$results[] = dtb_order_enqueue_job_with_receipt( 'dtb_order_sync_veeqo', $order_id, [], 0, [ 'not_applicable_reason' => 'payment_pending' ] );
				$results[] = dtb_order_enqueue_job_with_receipt( 'dtb_order_sync_quickbooks', $order_id, [], 0, [ 'not_applicable_reason' => 'payment_pending' ] );
			}
			return $results;
		}

		private static function materialization_fingerprint( array $line_items, float $shipping_total, string $payment_state ): string {
			$canonical = [];
			foreach ( $line_items as $line ) {
				$canonical[] = [
					'product_id' => absint( $line['product_id'] ?? 0 ),
					'sku'        => (string) ( $line['sku'] ?? '' ),
					'quantity'   => max( 1, (int) ( $line['quantity'] ?? 1 ) ),
					'total'      => (string) wc_format_decimal( (float) ( $line['total'] ?? 0 ), 2 ),
				];
			}
			return hash( 'sha256', (string) wp_json_encode( [
				'lines'          => $canonical,
				'shipping_total' => (string) wc_format_decimal( $shipping_total, 2 ),
				'payment_state'  => $payment_state,
			] ) );
		}

		private static function expected_total( array $line_items, float $shipping_total ): float {
			$total = $shipping_total;
			foreach ( $line_items as $line ) {
				$total += (float) ( $line['total'] ?? 0 );
			}
			return (float) wc_format_decimal( $total, 2 );
		}

		private static function is_contract_order( WC_Order $order ): bool {
			return self::CONTRACT_VERSION === (string) $order->get_meta( '_dtb_marketplace_materialization_contract', true )
				&& '' !== (string) $order->get_meta( '_dtb_marketplace_reservation_token', true );
		}

		private static function reservation_created_via( string $token ): string {
			return self::CREATED_VIA_PREFIX . $token;
		}

		private static function find_woo_order_by_reservation( string $token ): int {
			$created_via = self::reservation_created_via( $token );
			$ids = wc_get_orders( [
				'created_via' => $created_via,
				'limit'       => 3,
				'return'      => 'ids',
			] );
			if ( ! is_array( $ids ) ) {
				return -1;
			}
			$matches = [];
			foreach ( array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) ) as $id ) {
				$order = wc_get_order( $id );
				if ( ! $order instanceof WC_Order || ! hash_equals( $created_via, (string) $order->get_created_via() ) ) {
					return -1;
				}
				$stored_token = (string) $order->get_meta( '_dtb_marketplace_reservation_token', true );
				if ( '' !== $stored_token && ! hash_equals( $token, $stored_token ) ) {
					return -1;
				}
				$matches[] = $id;
			}
			return count( $matches ) > 1 ? -1 : ( $matches[0] ?? 0 );
		}

		private static function reconcile_complete_order( WC_Order $order, string $channel_key, string $external_id, int $marketplace_row_id, string $payment_state, array $line_items, float $shipping_total, string $fingerprint ): array {
			$order_id = (int) $order->get_id();
			$token    = (string) $order->get_meta( '_dtb_marketplace_reservation_token', true );
			if ( ! self::is_contract_order( $order )
				|| 'complete' !== sanitize_key( (string) $order->get_meta( '_dtb_marketplace_materialization_state', true ) )
				|| ! self::woo_order_matches_marketplace_identity( $order_id, $channel_key, $external_id )
				|| ! hash_equals( self::reservation_created_via( $token ), (string) $order->get_created_via() )
				|| ! hash_equals( $fingerprint, (string) $order->get_meta( '_dtb_marketplace_materialization_fingerprint', true ) )
				|| count( $order->get_items( 'line_item' ) ) !== count( $line_items )
				|| abs( (float) $order->get_total() - self::expected_total( $line_items, $shipping_total ) ) > 0.01
			) {
				throw new RuntimeException( 'WooCommerce materialization readback does not match the reserved marketplace order.' );
			}

			// Keep a completed orchestration state monotonic across reconciliations.
			// The shared identity mutex prevents workers from racing this transition.
			$orchestration_state = sanitize_key( (string) $order->get_meta( '_dtb_marketplace_orchestration_state', true ) );
			if ( 'recovery_required' === $orchestration_state ) {
				throw new RuntimeException( 'Marketplace order requires audited operator recovery; automatic reconciliation is blocked.' );
			}
			if ( 'complete' === $orchestration_state ) {
				$prior_hash = (string) $order->get_meta( '_dtb_marketplace_identity_hash', true );
				$prior_key  = 'marketplace-materialization-complete:v2:' . $prior_hash;
				if ( '' === $prior_hash || ! DTB_MarketplaceEventService::idempotent_event_matches( $prior_key, DTB_MarketplaceEventService::EVT_MATERIALIZATION_COMPLETED, $channel_key, $marketplace_row_id, $order_id ) ) {
					throw new RuntimeException( 'Completed marketplace order is missing its exact durable completion event; operator recovery is required.' );
				}
			} elseif ( 'dispatch_pending' !== $orchestration_state ) {
				$order->update_meta_data( '_dtb_marketplace_orchestration_state', 'dispatch_pending' );
				$order->save();
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof WC_Order || 'dispatch_pending' !== (string) $order->get_meta( '_dtb_marketplace_orchestration_state', true ) ) {
					throw new RuntimeException( 'Marketplace dispatch-pending state readback failed.' );
				}
			}

			if ( ! self::link_marketplace_order( $channel_key, $external_id, $marketplace_row_id, $order_id ) ) {
				throw new RuntimeException( 'Marketplace order link compare-and-set could not be verified.' );
			}
			if ( ! self::append_order_event( $order_id, $channel_key, $external_id, $marketplace_row_id, $payment_state, $line_items ) ) {
				throw new RuntimeException( 'Order materialization event could not be verified.' );
			}

			$identity_hash = DTB_MarketplaceEventService::materialization_identity_hash( $channel_key, $external_id );
			$link_key      = 'marketplace-order-linked:v2:' . $identity_hash;
			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_ORDER_LINKED, $channel_key, [
				'linked_order_id'  => $marketplace_row_id,
				'woo_order_id'     => $order_id,
				'idempotency_key'  => $link_key,
				'external_event_id'=> $identity_hash,
				'payload'          => [ 'marketplace_row_id' => $marketplace_row_id, 'woo_order_id' => $order_id, 'materialized' => true ],
			] );
			if ( ! DTB_MarketplaceEventService::idempotent_event_matches( $link_key, DTB_MarketplaceEventService::EVT_ORDER_LINKED, $channel_key, $marketplace_row_id, $order_id ) ) {
				throw new RuntimeException( 'Marketplace link event could not be verified.' );
			}

			$target_status = 'paid' === $payment_state ? 'processing' : 'on-hold';
			if ( $target_status !== $order->get_status() ) {
				// Orchestration is still blocked here, so the status transition cannot
				// emit email, stock or generic downstream side effects.
				$order->update_status( $target_status, sprintf( '[DTB Marketplace] Imported %s order %s.', ucfirst( $channel_key ), $external_id ) );
			}
			$status_readback = wc_get_order( $order_id );
			if ( ! $status_readback instanceof WC_Order || $target_status !== $status_readback->get_status() ) {
				throw new RuntimeException( 'WooCommerce marketplace status transition readback failed.' );
			}
			$order = $status_readback;
			$dispatch = self::dispatch_pipeline_jobs( $order_id, $payment_state );
			foreach ( $dispatch as $receipt ) {
				if ( ! in_array( (string) ( $receipt['status'] ?? '' ), [ 'scheduled', 'already_scheduled', 'already_completed', 'intentionally_not_applicable' ], true ) ) {
					throw new RuntimeException( 'Required downstream dispatch is not durably reconciled: ' . sanitize_key( (string) ( $receipt['status'] ?? 'unknown' ) ) );
				}
				$recovery_key = 'marketplace-dispatch-recovery:v2:' . $identity_hash . ':' . (string) ( $receipt['identity'] ?? '' );
				$fallback_key = 'dtb_marketplace_dispatch_recovery_' . hash( 'sha256', $order_id . ':' . (string) ( $receipt['identity'] ?? '' ) );
				if ( ! function_exists( 'dtb_order_event_idempotency_exists' ) || dtb_order_event_idempotency_exists( $recovery_key ) || is_array( get_option( $fallback_key, null ) ) ) {
					throw new RuntimeException( 'A downstream dispatch entered recovery before marketplace finalization.' );
				}
			}

			$complete_key = 'marketplace-materialization-complete:v2:' . $identity_hash;
			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_MATERIALIZATION_COMPLETED, $channel_key, [
				'linked_order_id'   => $marketplace_row_id,
				'woo_order_id'      => $order_id,
				'idempotency_key'   => $complete_key,
				'external_event_id' => $identity_hash,
				'payload'           => [ 'marketplace_row_id' => $marketplace_row_id, 'woo_order_id' => $order_id, 'dispatch_count' => count( $dispatch ) ],
			] );
			if ( ! DTB_MarketplaceEventService::idempotent_event_matches( $complete_key, DTB_MarketplaceEventService::EVT_MATERIALIZATION_COMPLETED, $channel_key, $marketplace_row_id, $order_id ) ) {
				throw new RuntimeException( 'Marketplace completion marker could not be verified.' );
			}

			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				throw new RuntimeException( 'Marketplace Woo order disappeared before final completion.' );
			}
			$final_state = sanitize_key( (string) $order->get_meta( '_dtb_marketplace_orchestration_state', true ) );
			if ( 'dispatch_pending' === $final_state ) {
				$order->update_meta_data( '_dtb_marketplace_orchestration_state', 'complete' );
				$order->delete_meta_data( '_dtb_marketplace_materialization_error_code' );
				$order->save();
			} elseif ( 'complete' !== $final_state ) {
				throw new RuntimeException( 'Marketplace orchestration left its expected state before completion; recovery state is preserved.' );
			}

			$readback = wc_get_order( $order_id );
			if ( ! $readback instanceof WC_Order
				|| 'complete' !== (string) $readback->get_meta( '_dtb_marketplace_orchestration_state', true )
				|| $target_status !== $readback->get_status()
			) {
				throw new RuntimeException( 'Marketplace completion readback failed.' );
			}
			foreach ( $dispatch as $receipt ) {
				$recovery_key = 'marketplace-dispatch-recovery:v2:' . $identity_hash . ':' . (string) ( $receipt['identity'] ?? '' );
				$fallback_key = 'dtb_marketplace_dispatch_recovery_' . hash( 'sha256', $order_id . ':' . (string) ( $receipt['identity'] ?? '' ) );
				if ( ! function_exists( 'dtb_order_event_idempotency_exists' ) || dtb_order_event_idempotency_exists( $recovery_key ) || is_array( get_option( $fallback_key, null ) ) ) {
					throw new RuntimeException( 'A downstream dispatch entered recovery during marketplace finalization.' );
				}
			}

			return [ 'status' => 'materialized', 'woo_order_id' => $order_id, 'message' => 'Marketplace order materialization and durable dispatch are complete.' ];
		}

		public static function find_existing_woo_order( string $channel_key, string $external_id ): int {
			if ( ! function_exists( 'wc_get_orders' )
				|| ! class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
				|| ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			) {
				return -1;
			}
			$channel_key = sanitize_key( $channel_key );
			$channel_meta = [
				'relation' => 'OR',
				[ 'key' => '_dtb_marketplace_channel', 'value' => $channel_key ],
				[ 'key' => '_dtb_source_channel', 'value' => $channel_key ],
			];
			$meta_query = [
				'relation' => 'OR',
				[
					'relation' => 'AND',
					[ 'key' => '_dtb_marketplace_order_id', 'value' => $external_id ],
					$channel_meta,
				],
				[
					'relation' => 'AND',
					[ 'key' => '_' . $channel_key . '_order_id', 'value' => $external_id ],
					$channel_meta,
				],
			];
			$orders = wc_get_orders( [
				'limit'      => 2,
				'return'     => 'ids',
				'meta_query' => $meta_query,
			] );
			if ( ! is_array( $orders ) ) {
				return -1;
			}
			$orders = array_values( array_unique( array_filter( array_map( 'absint', $orders ) ) ) );
			if ( count( $orders ) > 1 ) {
				return -1;
			}

			// Inspect legacy aliases even when a canonical match exists, so duplicate Woo orders cannot hide behind a valid result.
			$legacy_orders = wc_get_orders( [
				'limit'      => 3,
				'return'     => 'ids',
				'meta_query' => [ [ 'key' => '_' . $channel_key . '_order_id', 'value' => $external_id ] ],
			] );
			if ( ! is_array( $legacy_orders ) ) {
				return -1;
			}
			if ( count( $legacy_orders ) >= 3 ) {
				return -1;
			}
			$generic_orders = wc_get_orders( [
				'limit'      => 3,
				'return'     => 'ids',
				'meta_query' => [ [ 'key' => '_dtb_marketplace_order_id', 'value' => $external_id ] ],
			] );
			if ( ! is_array( $generic_orders ) ) {
				return -1;
			}
			if ( count( $generic_orders ) >= 3 ) {
				return -1;
			}
			$matches = [];
			foreach ( array_values( array_unique( array_filter( array_map( 'absint', array_merge( $orders, $legacy_orders ) ) ) ) ) as $candidate_id ) {
				if ( self::woo_order_matches_marketplace_identity( $candidate_id, $channel_key, $external_id ) ) {
					$matches[] = $candidate_id;
				} else {
					return -1;
				}
			}
			foreach ( array_values( array_unique( array_filter( array_map( 'absint', $generic_orders ) ) ) ) as $candidate_id ) {
				if ( self::woo_order_matches_marketplace_identity( $candidate_id, $channel_key, $external_id ) ) {
					$matches[] = $candidate_id;
				} elseif ( ! self::woo_order_has_other_valid_marketplace_identity( $candidate_id, $channel_key, $external_id ) ) {
					// An incomplete or contradictory generic candidate cannot safely be classified as a miss.
					return -1;
				}
			}
			$matches = array_values( array_unique( $matches ) );
			if ( count( $matches ) > 1 ) {
				return -1;
			}
			return $matches[0] ?? 0;
		}

		/** A generic-ID candidate from another channel is harmless only when its own composite identity is consistent. */
		private static function woo_order_has_other_valid_marketplace_identity( int $woo_order_id, string $channel_key, string $external_id ): bool {
			if ( ! function_exists( 'wc_get_order' ) ) {
				return false;
			}
			$order = wc_get_order( $woo_order_id );
			if ( ! $order || ! is_callable( [ $order, 'get_meta' ] ) ) {
				return false;
			}
			$other_channels = array_values( array_unique( array_filter( array_map( 'sanitize_key', [
				(string) $order->get_meta( '_dtb_marketplace_channel', true ),
				(string) $order->get_meta( '_dtb_source_channel', true ),
			] ) ) ) );
			if ( 1 !== count( $other_channels ) || $channel_key === $other_channels[0] ) {
				return false;
			}
			return self::woo_order_matches_marketplace_identity( $woo_order_id, $other_channels[0], $external_id );
		}

		/** Confirm a linked order carries this exact provider/channel identity. */
		public static function woo_order_matches_marketplace_identity( int $woo_order_id, string $channel_key, string $external_id ): bool {
			if ( $woo_order_id < 1 || ! function_exists( 'wc_get_order' ) ) {
				return false;
			}
			$order = wc_get_order( $woo_order_id );
			if ( ! $order || ! is_callable( [ $order, 'get_meta' ] ) ) {
				return false;
			}
			$materialization_state = sanitize_key( (string) $order->get_meta( '_dtb_marketplace_materialization_state', true ) );
			if ( '' !== $materialization_state && 'complete' !== $materialization_state ) {
				return false;
			}
			$channel = sanitize_key( $channel_key );
			$stored_channels = array_filter( array_map( 'sanitize_key', [
				(string) $order->get_meta( '_dtb_marketplace_channel', true ),
				(string) $order->get_meta( '_dtb_source_channel', true ),
			] ) );
			$stored_ids = array_filter( array_map( 'strval', [
				$order->get_meta( '_dtb_marketplace_order_id', true ),
				$order->get_meta( '_' . $channel . '_order_id', true ),
			] ) );
			if ( empty( $stored_channels ) || empty( $stored_ids ) ) {
				return false;
			}
			foreach ( $stored_channels as $stored_channel ) {
				if ( $channel !== $stored_channel ) {
					return false;
				}
			}
			foreach ( $stored_ids as $stored_id ) {
				if ( ! hash_equals( $external_id, $stored_id ) ) {
					return false;
				}
			}
			return true;
		}

		private static function current_linked_woo_order_id( int $marketplace_row_id ): int {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_orders';
			$found = $wpdb->get_var( $wpdb->prepare( "SELECT woo_order_id FROM {$table} WHERE id = %d LIMIT 1", $marketplace_row_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return absint( $found );
		}

		private static function lock_key( string $channel_key, string $external_id ): string {
			return DTB_MarketplaceEventService::materialization_lock_name( $channel_key, $external_id );
		}

		private static function acquire_lock( string $lock_key ): bool {
			global $wpdb;
			// MySQL named locks are connection-owned and never become stale leases.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_key ) );
		}

		private static function release_lock( string $lock_key ): void {
			global $wpdb;
			// Release on the same request connection used by GET_LOCK.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_key ) );
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
