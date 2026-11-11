<?php
/**
 * Amazon — AmazonNotificationsService
 *
 * Manages SP-API Notifications API subscriptions and processes
 * inbound SNS notification payloads.
 *
 * Supported notification types:
 *   - ORDER_CHANGE  — order status changes
 *   - MESSAGING_ACTIONS — available messaging actions changed
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_AmazonNotificationsService' ) ) {
	final class DTB_AmazonNotificationsService {

		private const SUPPORTED_TYPES = [
			'ORDER_CHANGE',
			'MESSAGING_ACTIONS',
		];

		/**
		 * List active notification subscriptions.
		 *
		 * @param string $notification_type Notification type slug.
		 * @return array{ok: bool, data: mixed, error: string}
		 */
		public static function get_subscription( string $notification_type ): array {
			return DTB_AmazonSpApiClient::request(
				'GET',
				'/notifications/v2/subscriptions/' . rawurlencode( $notification_type )
			);
		}

		/**
		 * Create a subscription for the given notification type.
		 *
		 * @param string $notification_type  Notification type.
		 * @param string $destination_id     SP-API destination ID (linked to SNS topic).
		 * @return array{ok: bool, data: mixed, error: string}
		 */
		public static function create_subscription( string $notification_type, string $destination_id ): array {
			if ( ! in_array( $notification_type, self::SUPPORTED_TYPES, true ) ) {
				return [ 'ok' => false, 'data' => null, 'error' => 'Unsupported notification type: ' . $notification_type, 'rate_limited' => false ];
			}

			return DTB_AmazonSpApiClient::request(
				'POST',
				'/notifications/v2/subscriptions/' . rawurlencode( $notification_type ),
				[],
				[ 'destinationId' => $destination_id, 'processingDirective' => [ 'eventFilter' => [ 'eventFilterType' => 'ANY' ] ] ]
			);
		}

		/**
		 * Create an SNS destination for receiving notifications.
		 *
		 * @param string $marketplace_id Amazon marketplace ID.
		 * @param string $sns_topic_arn  ARN of the SNS topic.
		 * @return array{ok: bool, data: mixed, error: string}
		 */
		public static function create_destination( string $marketplace_id, string $sns_topic_arn ): array {
			return DTB_AmazonSpApiClient::request(
				'POST',
				'/notifications/v2/destinations',
				[],
				[
					'name'              => 'dtb-marketplace-' . sanitize_key( $marketplace_id ),
					'resourceSpecification' => [
						'sqs' => [ 'arn' => $sns_topic_arn ],
					],
				]
			);
		}

		/**
		 * Process an inbound SNS notification payload (called from webhook controller).
		 *
		 * @param array $payload Decoded JSON SNS notification.
		 */
		public static function process_notification( array $payload ): void {
			$notification_type = sanitize_text_field( $payload['NotificationType'] ?? '' );
			$notification_data = $payload['Payload'] ?? [];
			$ext_id            = sanitize_text_field( $payload['NotificationId'] ?? '' );

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_WEBHOOK_RECEIVED, DTB_CHANNEL_AMAZON, [
				'external_event_id' => $ext_id,
				'payload' => [
					'type'   => $notification_type,
					'status' => 'processing',
				],
			] );

			switch ( $notification_type ) {
				case 'ORDER_CHANGE':
					$order_change = $notification_data['OrderChangeNotification'] ?? [];
					$amazon_order_id = sanitize_text_field( $order_change['AmazonOrderId'] ?? '' );
					if ( '' !== $amazon_order_id ) {
						// Queue a single-order sync.
						$hook = 'dtb_amazon_order_sync_single';
						if ( function_exists( 'as_schedule_single_action' ) ) {
							as_schedule_single_action( time() + 10, $hook, [ [ $amazon_order_id ] ], 'dtb-marketplace' );
						} else {
							wp_schedule_single_event( time() + 10, $hook, [ $amazon_order_id ] );
						}
					}
					break;

				case 'MESSAGING_ACTIONS':
					// Messaging actions changed — let next order-scoped fetch pick them up.
					break;

				default:
					error_log( '[DTB][Amazon][Notifications] Unhandled notification type: ' . $notification_type );
					break;
			}
		}
	}
}
