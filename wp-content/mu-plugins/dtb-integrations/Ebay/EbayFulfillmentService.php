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
						error_log( '[DTB][eBay][Fulfillment] Import error: ' . $e->getMessage() );
					}
				}
			}

			update_option( 'dtb_ebay_last_order_sync', time(), false );

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_SUCCEEDED, DTB_CHANNEL_EBAY, [
				'payload' => [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ],
			] );

			return [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ];
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
