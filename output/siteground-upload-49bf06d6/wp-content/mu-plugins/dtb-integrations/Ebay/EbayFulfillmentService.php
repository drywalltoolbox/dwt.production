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
		 * @param string $modified_after  ISO 8601 inclusive lower bound.
		 * @param string $modified_before ISO 8601 inclusive upper bound.
		 * @param int    $offset          Provider pagination offset.
		 * @param int    $imported        Aggregate imported count.
		 * @param int    $updated         Aggregate updated count.
		 * @param int    $errors          Aggregate error count.
		 * @return array{imported: int, updated: int, errors: int}
		 */
		public static function sync( string $modified_after = '', string $modified_before = '', int $offset = 0, int $imported = 0, int $updated = 0, int $errors = 0 ): array {
			if ( ! DTB_EbayConfig::is_configured() ) {
				return [ 'imported' => 0, 'updated' => 0, 'errors' => 0 ];
			}

			if ( '' === $modified_after || '' === $modified_before ) {
				$last            = (int) get_option( 'dtb_ebay_last_order_sync', 0 );
				$modified_after  = gmdate( 'Y-m-d\TH:i:s.\0\0\0\Z', max( $last - HOUR_IN_SECONDS, strtotime( '-7 days' ) ) );
				$modified_before = gmdate( 'Y-m-d\TH:i:s.\0\0\0\Z' );
				$offset = $imported = $updated = $errors = 0;
			}

			if ( 0 === $offset ) {
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_STARTED, DTB_CHANNEL_EBAY, [
					'payload' => [ 'modified_after' => $modified_after, 'modified_before' => $modified_before ],
				] );
			}

			$limit      = 200;
			$sync_failed = false;

			{
				$response = DTB_EbayRestClient::request(
					'GET',
					'/sell/fulfillment/v1/order',
					[
						'filter' => 'lastmodifieddate:[' . $modified_after . '..' . $modified_before . ']',
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
						[ 'is_retryable' => ! empty( $response['rate_limited'] ) || (int) ( $response['status'] ?? 0 ) >= 500 ]
					);
					DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_FAILED, DTB_CHANNEL_EBAY, [
						'payload' => [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors, 'offset' => $offset, 'reason' => 'fulfillment_api_error' ],
					] );
					return [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ];
				}

				$orders   = $response['data']['orders'] ?? [];
				$total    = (int) ( $response['data']['total'] ?? 0 );
				$next_offset = $offset + count( $orders );
				if ( $next_offset < $total && empty( $orders ) ) {
					$errors++;
					DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_FAILED, DTB_CHANNEL_EBAY, [
						'payload' => [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors, 'offset' => $offset, 'reason' => 'empty_page_before_total' ],
					] );
					return [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ];
				}

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

				if ( $next_offset < $total && count( $orders ) > 0 ) {
					$next_args = [ $modified_after, $modified_before, $next_offset, $imported, $updated, $errors ];
					$exists    = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'dtb_ebay_order_sync', $next_args, 'dtb-marketplace' );
					$action_id = $exists || ! function_exists( 'as_schedule_single_action' ) ? 0 : as_schedule_single_action( time() + 5, 'dtb_ebay_order_sync', $next_args, 'dtb-marketplace', true );
					$scheduled = $exists || (int) $action_id > 0 || ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'dtb_ebay_order_sync', $next_args, 'dtb-marketplace' ) );
					if ( ! $scheduled ) {
						$errors++;
						DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_FAILED, DTB_CHANNEL_EBAY, [
							'payload' => [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors, 'offset' => $next_offset, 'reason' => 'continuation_not_scheduled' ],
						] );
					}
					return [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors, 'queued' => $scheduled ];
				}
			}

			$sync_payload = [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ];
			if ( $sync_failed ) {
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_FAILED, DTB_CHANNEL_EBAY, [
					'payload' => $sync_payload,
				] );
			} else {
				update_option( 'dtb_ebay_last_order_sync', strtotime( $modified_before ), false );
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

		/** Queue a bounded eBay order poll from the administrative sync action. */
		public static function queue_sync(): array {
			if ( ! DTB_EbayConfig::is_configured() ) {
				return [ 'ok' => false, 'error' => 'eBay is not configured.' ];
			}
			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return [ 'ok' => false, 'error' => 'Action Scheduler is unavailable; eBay sync was not queued.' ];
			}
			$last = (int) get_option( 'dtb_ebay_last_order_sync', 0 );
			$args = [
				gmdate( 'Y-m-d\TH:i:s.\0\0\0\Z', max( $last - HOUR_IN_SECONDS, strtotime( '-7 days' ) ) ),
				gmdate( 'Y-m-d\TH:i:s.\0\0\0\Z' ), 0, 0, 0, 0,
			];
			$exists = function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'dtb_ebay_order_sync', $args, 'dtb-marketplace' );
			$id     = $exists ? 1 : as_schedule_single_action( time() + 5, 'dtb_ebay_order_sync', $args, 'dtb-marketplace', true );
			$queued = (int) $id > 0 || ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'dtb_ebay_order_sync', $args, 'dtb-marketplace' ) );
			return $queued ? [ 'ok' => true, 'queued' => true ] : [ 'ok' => false, 'error' => 'Action Scheduler did not confirm the eBay sync job.' ];
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
