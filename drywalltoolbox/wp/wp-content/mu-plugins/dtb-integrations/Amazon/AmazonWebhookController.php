<?php
/**
 * Amazon — AmazonWebhookController
 *
 * Retired legacy HTTP notification controller.
 *
 * SP-API Notifications currently uses SQS/EventBridge delivery. This legacy
 * public SNS-over-HTTP handler is intentionally disabled until a supported,
 * authenticated transport consumer is implemented.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonWebhookController' ) ) {
	final class DTB_AmazonWebhookController {

		/**
		 * Keep the legacy public endpoint retired until a supported SP-API
		 * SQS/EventBridge consumer with authenticated delivery is implemented.
		 */
		public static function register_routes(): void {}

		/**
		 * Fail closed if a stale route or caller invokes the retired handler.
		 *
		 * @param WP_REST_Request $request Incoming request.
		 * @return WP_REST_Response
		 */
		public static function handle( WP_REST_Request $request ): WP_REST_Response {
			return new WP_REST_Response( [ 'ok' => false, 'error' => 'Amazon notification transport is not enabled.' ], 410 );
		}
	}
}

// Deliberately do not register the unauthenticated legacy SNS-over-HTTP route.
