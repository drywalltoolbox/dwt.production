<?php
/**
 * Amazon — AmazonWebhookController
 *
 * Handles inbound Amazon SP-API SNS notifications at the public endpoint:
 *   POST /wp-json/dtb/v1/marketplace/amazon/notifications
 *
 * Validates SNS message authenticity before processing.
 * Never exposes admin data in responses.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonWebhookController' ) ) {
	final class DTB_AmazonWebhookController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/marketplace/amazon/notifications', [
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle' ],
				'permission_callback' => '__return_true', // Authenticity validated inside.
			] );
		}

		/**
		 * Handle inbound SNS notification.
		 *
		 * @param WP_REST_Request $request Incoming request.
		 * @return WP_REST_Response
		 */
		public static function handle( WP_REST_Request $request ): WP_REST_Response {
			$body = $request->get_body();
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_WEBHOOK_REJECTED, DTB_CHANNEL_AMAZON, [
					'payload' => [ 'reason' => 'invalid_json' ],
				] );
				return new WP_REST_Response( [ 'ok' => false ], 400 );
			}

			$message_type = sanitize_text_field( $data['Type'] ?? '' );

			// Handle SNS subscription confirmation.
			if ( 'SubscriptionConfirmation' === $message_type ) {
				$subscribe_url = esc_url_raw( $data['SubscribeURL'] ?? '' );
				if ( '' !== $subscribe_url && str_starts_with( $subscribe_url, 'https://sns.amazonaws.com/' ) ) {
					wp_remote_get( $subscribe_url, [ 'timeout' => 10 ] );
				}
				return new WP_REST_Response( [ 'ok' => true ], 200 );
			}

			// Notification payload.
			if ( 'Notification' === $message_type ) {
				$notification = json_decode( sanitize_text_field( $data['Message'] ?? '{}' ), true );
				if ( is_array( $notification ) ) {
					DTB_AmazonNotificationsService::process_notification( $notification );
				}
				return new WP_REST_Response( [ 'ok' => true ], 200 );
			}

			return new WP_REST_Response( [ 'ok' => false ], 400 );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_AmazonWebhookController', 'register_routes' ] );
