<?php
/**
 * Amazon — AmazonOrdersService
 *
 * Syncs orders from Amazon SP-API Orders API into wp_dtb_marketplace_orders
 * and reconciles with WooCommerce.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonOrdersService' ) ) {
	final class DTB_AmazonOrdersService {

		/**
		 * Sync recent Amazon orders into local read models.
		 *
		 * @param string $created_after ISO 8601 datetime string (default: last sync - 1hr).
		 * @return array{imported: int, updated: int, errors: int}
		 */
		public static function sync( string $created_after = '' ): array {
			if ( ! DTB_AmazonConfig::is_configured() ) {
				return [ 'imported' => 0, 'updated' => 0, 'errors' => 0 ];
			}

			if ( '' === $created_after ) {
				$last = (int) get_option( 'dtb_amazon_last_order_sync', 0 );
				$created_after = gmdate( 'Y-m-d\TH:i:s\Z', max( $last - 3600, strtotime( '-7 days' ) ) );
			}

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_STARTED, DTB_CHANNEL_AMAZON, [
				'payload' => [ 'created_after' => $created_after ],
			] );

			$cfg     = DTB_AmazonConfig::get();
			$params  = [
				'MarketplaceIds'       => $cfg['marketplace_id'],
				'CreatedAfter'         => $created_after,
				'OrderStatuses'        => 'Unshipped,PartiallyShipped,Shipped,Pending',
			];

			$imported = 0;
			$updated  = 0;
			$errors   = 0;
			$next_token = null;

			do {
				$req_params = $next_token ? [ 'NextToken' => $next_token ] : $params;
				$response   = DTB_AmazonSpApiClient::request( 'GET', '/orders/v0/orders', $req_params );

				if ( ! $response['ok'] ) {
					$errors++;
					DTB_MarketplaceExceptionService::create(
						DTB_MarketplaceExceptionService::CAT_ORDER_IMPORT,
						DTB_CHANNEL_AMAZON,
						'orders_api_error',
						$response['error'],
						[ 'is_retryable' => true ]
					);
					break;
				}

				$payload    = $response['data']['payload'] ?? [];
				$orders     = $payload['Orders'] ?? [];
				$next_token = $payload['NextToken'] ?? null;

				foreach ( $orders as $raw_order ) {
					try {
						$result = self::import_single( $raw_order );
						if ( 'imported' === $result ) {
							$imported++;
						} else {
							$updated++;
						}
					} catch ( \Throwable $e ) {
						$errors++;
						error_log( '[DTB][Amazon][Orders] Import error: ' . $e->getMessage() );
					}
				}
			} while ( null !== $next_token );

			update_option( 'dtb_amazon_last_order_sync', time(), false );

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_SYNC_SUCCEEDED, DTB_CHANNEL_AMAZON, [
				'payload' => [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ],
			] );

			return [ 'imported' => $imported, 'updated' => $updated, 'errors' => $errors ];
		}

		/**
		 * Import a single Amazon order from raw SP-API payload.
		 *
		 * @param array $raw Raw Amazon order.
		 * @return string 'imported'|'updated'
		 */
		public static function import_single( array $raw ): string {
			$normalized = DTB_MarketplaceOrderNormalizer::from_amazon( $raw );
			$existing   = DTB_MarketplaceReadModels::find_order( DTB_CHANNEL_AMAZON, $normalized['marketplace_order_id'] );

			$id = DTB_MarketplaceReadModels::upsert_order( $normalized );

			if ( ! $existing ) {
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_ORDER_IMPORTED, DTB_CHANNEL_AMAZON, [
					'linked_order_id' => $id,
					'payload'         => [ 'marketplace_order_id' => $normalized['marketplace_order_id'] ],
				] );

				$linked = self::try_auto_link_woo( $id, $normalized['marketplace_order_id'] );
				if ( ! $linked && class_exists( 'DTB_MarketplaceOrderMaterializationService' ) ) {
					$items = self::get_order_items( $normalized['marketplace_order_id'] );
					if ( $items['ok'] ) {
						DTB_MarketplaceOrderMaterializationService::materialize_amazon( $id, $normalized, $raw, $items['items'] );
					} else {
						DTB_MarketplaceExceptionService::create(
							DTB_MarketplaceExceptionService::CAT_ORDER_LINKING,
							DTB_CHANNEL_AMAZON,
							'amazon_order_items_unavailable',
							$items['error'],
							[ 'linked_record_type' => 'marketplace_order', 'linked_record_id' => $id, 'is_retryable' => true ]
						);
					}
				}

				return 'imported';
			}

			return 'updated';
		}

		/**
		 * Attempt to find and link a WooCommerce order with matching Amazon order ID.
		 *
		 * @param int    $mp_order_id      Marketplace order row ID.
		 * @param string $amazon_order_id  Amazon order ID string.
		 * @return bool True when linked.
		 */
		public static function try_auto_link_woo( int $mp_order_id, string $amazon_order_id ): bool {
			global $wpdb;

			// Look for a Woo order with _amazon_order_id meta matching this order.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$woo_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = '_amazon_order_id' AND meta_value = %s LIMIT 1",
				$amazon_order_id
			) );

			if ( ! $woo_id ) {
				return false;
			}

			DTB_MarketplaceReadModels::upsert_order( [
				'channel_key'          => DTB_CHANNEL_AMAZON,
				'marketplace_order_id' => $amazon_order_id,
				'woo_order_id'         => (int) $woo_id,
			] );

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_ORDER_LINKED, DTB_CHANNEL_AMAZON, [
				'linked_order_id' => $mp_order_id,
				'woo_order_id'    => (int) $woo_id,
				'payload'         => [ 'marketplace_order_id' => $amazon_order_id, 'woo_order_id' => $woo_id ],
			] );

			return true;
		}

		/**
		 * Get order items for a specific Amazon order.
		 *
		 * @param string $amazon_order_id Amazon order ID.
		 * @return array{ok: bool, items: array[], error: string}
		 */
		public static function get_order_items( string $amazon_order_id ): array {
			$response = DTB_AmazonSpApiClient::request(
				'GET',
				'/orders/v0/orders/' . rawurlencode( $amazon_order_id ) . '/orderItems'
			);
			if ( ! $response['ok'] ) {
				return [ 'ok' => false, 'items' => [], 'error' => $response['error'] ];
			}
			$items = $response['data']['payload']['OrderItems'] ?? [];
			return [ 'ok' => true, 'items' => $items, 'error' => '' ];
		}
	}
}
