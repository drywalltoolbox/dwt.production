<?php
/**
 * Marketplace REST — MarketplaceOrdersController
 *
 * Routes:
 *   GET    /dtb/v1/admin/marketplace/orders          — paginated order list
 *   POST   /dtb/v1/admin/marketplace/orders/sync     — trigger sync for channel
 *   POST   /dtb/v1/admin/marketplace/orders/link     — link marketplace order to Woo order
 *   GET    /dtb/v1/admin/marketplace/orders/{id}     — single order detail
 *
 * Capability: dtb_manage_marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceOrdersController' ) ) {
	final class DTB_MarketplaceOrdersController extends DTB_AbstractRestController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/admin/marketplace/orders', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_orders' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/orders/sync', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'sync' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/orders/link', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'link_order' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/orders/(?P<id>\d+)', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_order' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
		}

		public static function check_permission(): bool {
			return current_user_can( 'dtb_manage_marketplace' ) && is_user_logged_in();
		}

		public static function list_orders( WP_REST_Request $request ): WP_REST_Response {
			$filters = [
				'channel_key'       => sanitize_key( $request->get_param( 'channel' ) ?? '' ),
				'fulfillment_state' => sanitize_key( $request->get_param( 'fulfillment_state' ) ?? '' ),
				'payment_state'     => sanitize_key( $request->get_param( 'payment_state' ) ?? '' ),
			];
			$filters = array_filter( $filters );
			$page    = self::page_from_request( $request );
			$per     = self::per_page_from_request( $request, 25, 100 );
			$result  = DTB_MarketplaceReadModels::orders( $filters, $page, $per );

			return new WP_REST_Response( [
				'items'       => $result['items'],
				'total'       => $result['total'],
				'page'        => $page,
				'per_page'    => $per,
				'total_pages' => (int) ceil( $result['total'] / $per ),
			], 200 );
		}

		public static function sync( WP_REST_Request $request ): WP_REST_Response {
			$channel = sanitize_key( $request->get_param( 'channel' ) ?? '' );

			$result = match ( $channel ) {
				DTB_CHANNEL_AMAZON => class_exists( 'DTB_AmazonOrdersService' )
					? DTB_AmazonOrdersService::sync()
					: [ 'error' => 'Amazon module not loaded.' ],
				DTB_CHANNEL_EBAY   => class_exists( 'DTB_EbayFulfillmentService' )
					? DTB_EbayFulfillmentService::sync()
					: [ 'error' => 'eBay module not loaded.' ],
				default            => [ 'error' => 'Unknown channel: ' . $channel ],
			};

			DTB_MarketplaceAuditService::write( 'orders.sync', 'marketplace_channel', 0, $channel, [
				'after' => $result,
			] );

			return new WP_REST_Response( $result, 200 );
		}

		public static function link_order( WP_REST_Request $request ): WP_REST_Response {
			$marketplace_order_id = sanitize_text_field( $request->get_param( 'marketplace_order_id' ) ?? '' );
			$woo_order_id         = (int) $request->get_param( 'woo_order_id' );
			$channel_key          = sanitize_key( $request->get_param( 'channel' ) ?? '' );

			if ( '' === $marketplace_order_id || ! $woo_order_id || '' === $channel_key ) {
				return new WP_REST_Response( [ 'ok' => false, 'error' => 'Missing required fields.' ], 400 );
			}

			DTB_MarketplaceReadModels::upsert_order( [
				'channel_key'          => $channel_key,
				'marketplace_order_id' => $marketplace_order_id,
				'woo_order_id'         => $woo_order_id,
			] );

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_ORDER_LINKED, $channel_key, [
				'payload' => [
					'marketplace_order_id' => $marketplace_order_id,
					'woo_order_id'         => $woo_order_id,
					'operator_id'          => get_current_user_id(),
				],
			] );

			DTB_MarketplaceAuditService::write( 'order.linked', 'marketplace_order', 0, $channel_key, [
				'after' => [ 'marketplace_order_id' => $marketplace_order_id, 'woo_order_id' => $woo_order_id ],
			] );

			return new WP_REST_Response( [ 'ok' => true ], 200 );
		}

		public static function get_order( WP_REST_Request $request ): WP_REST_Response {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_orders';
			$id    = (int) $request->get_param( 'id' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			if ( ! $row ) {
				return new WP_REST_Response( [ 'error' => 'Not found.' ], 404 );
			}
			return new WP_REST_Response( $row, 200 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_MarketplaceOrdersController', 'register_routes' ] );
