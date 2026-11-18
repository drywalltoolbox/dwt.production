<?php
/**
 * Marketplace REST — MarketplaceMessagesController
 *
 * Routes:
 *   GET  /dtb/v1/admin/marketplace/conversations           — paginated inbox
 *   GET  /dtb/v1/admin/marketplace/conversations/{id}     — thread view (with messages)
 *   GET  /dtb/v1/admin/marketplace/conversations/{id}/messages — messages only
 *
 * Capability: dtb_manage_marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceMessagesController' ) ) {
	final class DTB_MarketplaceMessagesController extends DTB_AbstractRestController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/admin/marketplace/conversations', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_conversations' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/conversations/(?P<id>\d+)', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_conversation' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/conversations/(?P<id>\d+)/messages', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'get_messages' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
		}

		public static function check_permission(): bool {
			return current_user_can( 'dtb_manage_marketplace' ) && is_user_logged_in();
		}

		public static function list_conversations( WP_REST_Request $request ): WP_REST_Response {
			$filters = [
				'channel_key'  => sanitize_key( $request->get_param( 'channel' ) ?? '' ),
				'status'       => sanitize_key( $request->get_param( 'status' ) ?? '' ),
				'needs_reply'  => (bool) $request->get_param( 'needs_reply' ),
				'sla_breach'   => (bool) $request->get_param( 'sla_breach' ),
			];
			$filters = array_filter( $filters );
			$page    = self::page_from_request( $request );
			$per     = self::per_page_from_request( $request, 25, 100 );
			$result  = DTB_MarketplaceReadModels::conversations( $filters, $page, $per );

			return new WP_REST_Response( [
				'items'       => $result['items'],
				'total'       => $result['total'],
				'page'        => $page,
				'per_page'    => $per,
				'total_pages' => (int) ceil( max( 1, $result['total'] ) / $per ),
			], 200 );
		}

		public static function get_conversation( WP_REST_Request $request ): WP_REST_Response {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_conversations';
			$id    = (int) $request->get_param( 'id' );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
			if ( ! $row ) {
				return new WP_REST_Response( [ 'error' => 'Not found.' ], 404 );
			}
			$messages = DTB_MarketplaceReadModels::messages_for_conversation( $id );
			$row['messages'] = $messages;
			return new WP_REST_Response( $row, 200 );
		}

		public static function get_messages( WP_REST_Request $request ): WP_REST_Response {
			$id       = (int) $request->get_param( 'id' );
			$messages = DTB_MarketplaceReadModels::messages_for_conversation( $id );
			return new WP_REST_Response( [ 'items' => $messages ], 200 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_MarketplaceMessagesController', 'register_routes' ] );
