<?php
/**
 * Marketplace REST — MarketplaceExceptionsController
 *
 * Routes:
 *   GET  /dtb/v1/admin/marketplace/exceptions           — list open exceptions
 *   POST /dtb/v1/admin/marketplace/exceptions/{id}/resolve — resolve exception
 *   POST /dtb/v1/admin/marketplace/exceptions/{id}/retry   — retry (re-schedules)
 *
 * Capability: dtb_manage_marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceExceptionsController' ) ) {
	final class DTB_MarketplaceExceptionsController extends DTB_AbstractRestController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/admin/marketplace/exceptions', [
				'methods'             => 'GET',
				'callback'            => [ self::class, 'list_exceptions' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/exceptions/(?P<id>\d+)/resolve', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'resolve' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
			register_rest_route( 'dtb/v1', '/admin/marketplace/exceptions/(?P<id>\d+)/retry', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'retry' ],
				'permission_callback' => [ self::class, 'check_permission' ],
			] );
		}

		public static function check_permission(): bool {
			return current_user_can( 'dtb_manage_marketplace' ) && is_user_logged_in();
		}

		public static function list_exceptions( WP_REST_Request $request ): WP_REST_Response {
			$channel = sanitize_key( $request->get_param( 'channel' ) ?? '' );
			$items   = DTB_MarketplaceExceptionService::get_open( $channel, 200 );
			return new WP_REST_Response( [
				'items' => $items,
				'total' => count( $items ),
			], 200 );
		}

		public static function resolve( WP_REST_Request $request ): WP_REST_Response {
			$id = (int) $request->get_param( 'id' );
			DTB_MarketplaceExceptionService::resolve( $id, get_current_user_id() );
			DTB_MarketplaceAuditService::write( 'exception.resolved', 'marketplace_exception', $id, '', [
				'after' => [ 'resolved_by' => get_current_user_id() ],
			] );
			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_EXCEPTION_RESOLVED, '', [
				'payload' => [ 'exception_id' => $id, 'operator_id' => get_current_user_id() ],
			] );
			return new WP_REST_Response( [ 'ok' => true ], 200 );
		}

		public static function retry( WP_REST_Request $request ): WP_REST_Response {
			$id = (int) $request->get_param( 'id' );
			DTB_MarketplaceExceptionService::mark_retry_scheduled( $id, 10 );
			// Trigger exception retry job immediately.
			$hook = 'dtb_marketplace_exception_retry';
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 5, $hook, [], 'dtb-marketplace' );
			} else {
				wp_schedule_single_event( time() + 5, $hook );
			}
			DTB_MarketplaceAuditService::write( 'exception.retry', 'marketplace_exception', $id, '', [] );
			return new WP_REST_Response( [ 'ok' => true ], 200 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_MarketplaceExceptionsController', 'register_routes' ] );
