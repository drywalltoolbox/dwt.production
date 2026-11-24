<?php
/**
 * eBay — EbayFulfillmentService
 *
 * Syncs orders from eBay Sell Fulfillment API into wp_dtb_marketplace_orders.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayFulfillmentService' ) ) {
	final class DTB_EbayFulfillmentService {

		/**
		 * Sync recent eBay orders.
		 *
		 * @param string $created_after ISO 8601 date string.
		 * @return array{imported: int, updated: int, errors: int}
		 */
		public static function sync( string $created_after = '' ): array {
			if ( ! DTB_EbayConfig::is_configured() ) {
				return [ 'imported' => 0, 'updated' => 0, 'errors' => 0 ];
			}

			if ( '' === $created_after ) {
				$last          = (int) get_option( 'dtb_ebay_last_order_sync', 0 );
				$created_after = gmdate( 'Y-m-d\TH:i:s.\0\0\0\Z', max( $last - 3600, strtotime( '-7 days' ) ) );
			}

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_STARTED, DTB_CHANNEL_EBAY, [
				'payload' => [ 'created_after' => $created_after ],
			] );

			$imported   = 0;
			$updated    = 0;
			$errors     = 0;
			$offset     = 0;
			$limit      = 50;
			$has_more   = true;
			$sync_failed = false;

			while ( $has_more ) {
				$response = DTB_EbayRestClient::request(
					'GET',
					'/sell/fulfillment/v1/order',
					[
						'filter' => 'creationdate:[' . $created_after . '..]',
						'limit'  => $limit,
						'offset' => $offset,
					]
				);

				if ( ! $response['ok'] ) {
					$errors++;
					$sync_failed = true;
					DTB_MarketplaceExceptionService::create(
						DTB_MarketplaceExceptionService::CAT_ORDER_IMPORT,
						DTB_CHANNEL_EBAY,
						'fulfillment_api_error',
						$response['error'],
						[ 'is_retryable' => ! $response['rate_limited'] ]
					);
					break;
				}

				$orders   = $response['data']['orders'] ?? [];
				$total    = (int) ( $response['data']['total'] ?? 0 );
				$has_more = ( $offset + $limit ) < $total;
				$offset  += $limit;

				foreach ( $orders as $raw_order ) {
					try {
						$existing   = DTB_MarketplaceReadModels::find_order( DTB_CHANNEL_EBAY, $raw_order['orderId'] ?? '' );
						$normalized = DTB_MarketplaceOrderNormalizer::from_ebay( $raw_order );
						$id         = DTB_MarketplaceReadModels::upsert_order( $normalized );

						if ( ! $existing ) {
							$imported++;
							DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_ORDER_IMPORTED, DTB_CHANNEL_EBAY, [
								'linked_order_id' => $id,
								'idempotency_key' => 'ebay:order:imported:' . sanitize_text_field( (string) ( $raw_order['orderId'] ?? '' ) ),
								'payload'         => [ 'ebay_order_id' => $raw_order['orderId'] ?? '' ],
							] );

							if ( class_exists( 'DTB_MarketplaceOrderMaterializationService' ) ) {
								DTB_MarketplaceOrderMaterializationService::materialize_ebay( $id, $normalized, $raw_order );
							}
						} else {
							$updated++;
						}
					} catch ( \Throwable $e ) {
						$errors++;
						$sync_failed = true;
						error_log( '[DTB][eBay][Fulfillment] Import error: ' . $e->getMessage() );
					}
				}
			}

			$sync_payload = [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ];
			if ( $sync_failed ) {
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_FAILED, DTB_CHANNEL_EBAY, [
					'payload' => $sync_payload,
				] );
			} else {
				update_option( 'dtb_ebay_last_order_sync', time(), false );
				DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_EBAY, [
					'last_sync_at'   => current_time( 'mysql', true ),
					'health_state'   => 'ok',
					'auth_state'     => 'connected',
					'last_error_msg' => '',
				] );
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_SUCCEEDED, DTB_CHANNEL_EBAY, [
					'payload' => $sync_payload,
				] );
			}

			return [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ];
		}

		/** Import one order fetched by an authenticated eBay notification. */
		public static function sync_order_by_id( string $order_id ): array {
			$order_id = sanitize_text_field( $order_id );
			if ( '' === $order_id || strlen( $order_id ) > 191 || ! DTB_EbayConfig::is_configured() ) {
				return [ 'ok' => false, 'error' => 'Invalid order identity or eBay is not configured.' ];
			}

			$response = self::get_order( $order_id );
			if ( ! $response['ok'] ) {
				return [ 'ok' => false, 'error' => $response['error'] ];
			}

			$raw_order = $response['order'];
			if ( ! is_array( $raw_order ) || $order_id !== (string) ( $raw_order['orderId'] ?? '' ) ) {
				return [ 'ok' => false, 'error' => 'eBay returned an order that did not match the requested identity.' ];
			}

			$existing   = DTB_MarketplaceReadModels::find_order( DTB_CHANNEL_EBAY, $order_id );
			$normalized = DTB_MarketplaceOrderNormalizer::from_ebay( $raw_order );
			$local_id   = DTB_MarketplaceReadModels::upsert_order( $normalized );
			if ( $local_id <= 0 ) {
				return [ 'ok' => false, 'error' => 'The eBay order could not be persisted.' ];
			}

			if ( ! $existing ) {
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_ORDER_IMPORTED, DTB_CHANNEL_EBAY, [
					'linked_order_id' => $local_id,
					'idempotency_key' => 'ebay:order:imported:' . $order_id,
					'payload'         => [ 'ebay_order_id' => $order_id ],
				] );

				if ( class_exists( 'DTB_MarketplaceOrderMaterializationService' ) ) {
					DTB_MarketplaceOrderMaterializationService::materialize_ebay( $local_id, $normalized, $raw_order );
				}
			}

			DTB_MarketplaceReadModels::upsert_channel( DTB_CHANNEL_EBAY, [
				'last_sync_at'   => current_time( 'mysql', true ),
				'health_state'   => 'ok',
				'auth_state'     => 'connected',
				'last_error_msg' => '',
			] );

			return [ 'ok' => true, 'local_order_id' => $local_id, 'imported' => ! $existing ];
		}

		/**
		 * Get a single eBay order by ID.
		 *
		 * @param string $order_id eBay order ID.
		 * @return array{ok: bool, order: array, error: string}
		 */
		public static function get_order( string $order_id ): array {
			$response = DTB_EbayRestClient::request( 'GET', '/sell/fulfillment/v1/order/' . rawurlencode( $order_id ) );
			if ( ! $response['ok'] ) {
				return [ 'ok' => false, 'order' => [], 'error' => $response['error'] ];
			}
			return [ 'ok' => true, 'order' => $response['data'] ?? [], 'error' => '' ];
		}

		/**
		 * Issue fulfillment / shipping notification for an eBay order.
		 *
		 * @param string $order_id     eBay order ID.
		 * @param array  $tracking     Tracking data: carrier, trackingNumber, shippedDate.
		 * @return array{ok: bool, error: string}
		 */
		public static function create_fulfillment( string $order_id, array $tracking ): array {
			$response = DTB_EbayRestClient::request(
				'POST',
				'/sell/fulfillment/v1/order/' . rawurlencode( $order_id ) . '/shipping_fulfillment',
				[],
				[
					'lineItems'      => $tracking['line_items'] ?? [],
					'shippedDate'    => $tracking['shipped_date'] ?? gmdate( 'c' ),
					'shippingCarrierCode' => sanitize_text_field( $tracking['carrier'] ?? '' ),
					'trackingNumber' => sanitize_text_field( $tracking['tracking_number'] ?? '' ),
				]
			);

			if ( $response['ok'] ) {
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_TRACKING_SYNCED, DTB_CHANNEL_EBAY, [
					'payload' => [ 'order_id' => $order_id, 'carrier' => $tracking['carrier'] ?? '' ],
				] );
			}

			return [ 'ok' => $response['ok'], 'error' => $response['error'] ];
		}
	}
}
