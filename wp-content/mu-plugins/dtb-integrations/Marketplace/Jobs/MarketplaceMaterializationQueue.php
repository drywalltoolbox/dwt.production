<?php
/**
 * Marketplace Materialization Queue.
 *
 * Retries Woo order materialization for unlinked marketplace orders, including
 * records imported before the materialization service existed.
 *
 * Safety contract:
 * - Marketplace imports may build read models by default.
 * - Marketplace read models must NOT materialize into WooCommerce orders unless
 *   DTB_MARKETPLACE_MATERIALIZATION_ENABLED is explicitly enabled.
 * - WooCommerce order creation is a side-effect boundary and must remain
 *   feature-gated because Veeqo/API2Cart/marketplace polling can otherwise
 *   create recurring duplicate order batches.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_MARKETPLACE_MATERIALIZATION_ENABLED' ) ) {
	define( 'DTB_MARKETPLACE_MATERIALIZATION_ENABLED', false );
}

if ( ! defined( 'DTB_MARKETPLACE_MATERIALIZATION_BATCH_LIMIT' ) ) {
	define( 'DTB_MARKETPLACE_MATERIALIZATION_BATCH_LIMIT', 10 );
}

if ( ! function_exists( 'dtb_marketplace_materialization_enabled' ) ) {
	/** Return whether marketplace read models may be converted into Woo orders. */
	function dtb_marketplace_materialization_enabled(): bool {
		return filter_var( DTB_MARKETPLACE_MATERIALIZATION_ENABLED, FILTER_VALIDATE_BOOLEAN );
	}
}

add_action( 'dtb_marketplace_materialize_unlinked', 'dtb_marketplace_materialize_unlinked_orders' );
add_action( 'dtb_marketplace_reconcile', 'dtb_marketplace_materialize_unlinked_orders', 20 );
add_action( 'wp', 'dtb_marketplace_schedule_materialization_jobs' );

if ( ! function_exists( 'dtb_marketplace_schedule_materialization_jobs' ) ) {
	/** Schedule recurring materialization retry job only when explicit side effects are enabled. */
	function dtb_marketplace_schedule_materialization_jobs(): void {
		if ( ! dtb_marketplace_materialization_enabled() ) {
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( 'dtb_marketplace_materialize_unlinked' );
			}
			return;
		}

		if ( ! wp_next_scheduled( 'dtb_marketplace_materialize_unlinked' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'dtb_marketplace_materialize_unlinked' );
		}
	}
}

if ( ! function_exists( 'dtb_marketplace_materialization_log_disabled' ) ) {
	/** Log a disabled materialization attempt once per hour. */
	function dtb_marketplace_materialization_log_disabled( string $source ): void {
		$transient_key = 'dtb_marketplace_materialization_disabled_notice_' . sanitize_key( $source );
		if ( get_transient( $transient_key ) ) {
			return;
		}
		set_transient( $transient_key, 1, HOUR_IN_SECONDS );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'Marketplace order materialization skipped because DTB_MARKETPLACE_MATERIALIZATION_ENABLED is false.',
				[ 'source' => 'dtb-marketplace-materialization', 'trigger' => $source ]
			);
		}
	}
}

if ( ! function_exists( 'dtb_marketplace_materialize_unlinked_orders' ) ) {
	/** Retry materialization for unlinked marketplace orders. */
	function dtb_marketplace_materialize_unlinked_orders(): void {
		if ( ! dtb_marketplace_materialization_enabled() ) {
			dtb_marketplace_materialization_log_disabled( 'scheduled_retry' );
			return;
		}

		if ( ! class_exists( 'DTB_MarketplaceOrderMaterializationService' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dtb_marketplace_orders';
		$limit = max( 1, min( 25, (int) DTB_MARKETPLACE_MATERIALIZATION_BATCH_LIMIT ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orders = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE woo_order_id IS NULL
				   AND payment_state IN ('paid','pending')
				 ORDER BY created_at ASC
				 LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		foreach ( $orders as $row ) {
			$channel = sanitize_key( (string) ( $row['channel_key'] ?? '' ) );
			$ext_id  = sanitize_text_field( (string) ( $row['marketplace_order_id'] ?? '' ) );
			$row_id  = absint( $row['id'] ?? 0 );
			if ( '' === $channel || '' === $ext_id || $row_id <= 0 ) {
				continue;
			}

			try {
				if ( defined( 'DTB_CHANNEL_AMAZON' ) && DTB_CHANNEL_AMAZON === $channel ) {
					dtb_marketplace_materialize_amazon_order_by_id( $row_id, $ext_id );
				} elseif ( defined( 'DTB_CHANNEL_EBAY' ) && DTB_CHANNEL_EBAY === $channel ) {
					dtb_marketplace_materialize_ebay_order_by_id( $row_id, $ext_id );
				}
			} catch ( Throwable $e ) {
				if ( class_exists( 'DTB_MarketplaceExceptionService' ) ) {
					DTB_MarketplaceExceptionService::create(
						DTB_MarketplaceExceptionService::CAT_ORDER_LINKING,
						$channel,
						'materialization_retry_failed',
						$e->getMessage(),
						[ 'linked_record_type' => 'marketplace_order', 'linked_record_id' => $row_id, 'is_retryable' => true ]
					);
				}
			}
		}
	}
}

if ( ! function_exists( 'dtb_marketplace_materialize_amazon_order_by_id' ) ) {
	/**
	 * Fetch and materialize one Amazon order.
	 *
	 * @param int    $row_id Marketplace row ID.
	 * @param string $amazon_order_id Amazon order ID.
	 */
	function dtb_marketplace_materialize_amazon_order_by_id( int $row_id, string $amazon_order_id ): void {
		if ( ! dtb_marketplace_materialization_enabled() ) {
			dtb_marketplace_materialization_log_disabled( 'amazon_direct' );
			return;
		}

		if ( ! class_exists( 'DTB_AmazonSpApiClient' ) || ! class_exists( 'DTB_MarketplaceOrderNormalizer' ) ) {
			return;
		}

		$order_response = DTB_AmazonSpApiClient::request( 'GET', '/orders/v0/orders/' . rawurlencode( $amazon_order_id ) );
		if ( empty( $order_response['ok'] ) || empty( $order_response['data']['payload'] ) ) {
			throw new RuntimeException( (string) ( $order_response['error'] ?? 'Amazon order fetch failed.' ) );
		}

		$items = class_exists( 'DTB_AmazonOrdersService' ) ? DTB_AmazonOrdersService::get_order_items( $amazon_order_id ) : [ 'ok' => false, 'items' => [], 'error' => 'Amazon order item service unavailable.' ];
		if ( empty( $items['ok'] ) ) {
			throw new RuntimeException( (string) ( $items['error'] ?? 'Amazon order items fetch failed.' ) );
		}

		$raw        = (array) $order_response['data']['payload'];
		$normalized = DTB_MarketplaceOrderNormalizer::from_amazon( $raw );
		DTB_MarketplaceOrderMaterializationService::materialize_amazon( $row_id, $normalized, $raw, (array) $items['items'] );
	}
}

if ( ! function_exists( 'dtb_marketplace_materialize_ebay_order_by_id' ) ) {
	/**
	 * Fetch and materialize one eBay order.
	 *
	 * @param int    $row_id Marketplace row ID.
	 * @param string $ebay_order_id eBay order ID.
	 */
	function dtb_marketplace_materialize_ebay_order_by_id( int $row_id, string $ebay_order_id ): void {
		if ( ! dtb_marketplace_materialization_enabled() ) {
			dtb_marketplace_materialization_log_disabled( 'ebay_direct' );
			return;
		}

		if ( ! class_exists( 'DTB_EbayFulfillmentService' ) || ! class_exists( 'DTB_MarketplaceOrderNormalizer' ) ) {
			return;
		}

		$response = DTB_EbayFulfillmentService::get_order( $ebay_order_id );
		if ( empty( $response['ok'] ) || empty( $response['order'] ) ) {
			throw new RuntimeException( (string) ( $response['error'] ?? 'eBay order fetch failed.' ) );
		}

		$raw        = (array) $response['order'];
		$normalized = DTB_MarketplaceOrderNormalizer::from_ebay( $raw );
		DTB_MarketplaceOrderMaterializationService::materialize_ebay( $row_id, $normalized, $raw );
	}
}
