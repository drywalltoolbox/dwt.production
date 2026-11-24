<?php
/**
 * eBay — EbayDeletionController
 *
		 * Handles signed eBay Notification API and Account Deletion notifications.
 * Required by eBay developer program compliance before production enablement.
 *
 * Endpoints:
 *   GET  /wp-json/dtb/v1/marketplace/ebay/deletion  — verification challenge
 *   POST /wp-json/dtb/v1/marketplace/ebay/deletion  — deletion notification processing
 *
 * On receiving a deletion notification:
 *   1. Validates X-EBAY-SIGNATURE against eBay's Notification API public key.
 *   2. Validates the topic and nested notification identity.
 *   3. Queues idempotent buyer conversation redaction in Action Scheduler.
 *   4. Returns 204 only after the job is durable or already queued.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_EbayDeletionController' ) ) {
	final class DTB_EbayDeletionController {

		public static function register_routes(): void {
			register_rest_route( 'dtb/v1', '/marketplace/ebay/deletion', [
				[
					'methods'             => 'GET',
					'callback'            => [ self::class, 'handle_challenge' ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => 'POST',
					'callback'            => [ self::class, 'handle_notification' ],
					'permission_callback' => '__return_true',
				],
			] );
		}

		/**
		 * Handle eBay verification challenge (GET).
		 *
		 * eBay sends a challenge_code query param; respond with SHA256 hash.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function handle_challenge( WP_REST_Request $request ): WP_REST_Response {
			$challenge_code = sanitize_text_field( $request->get_param( 'challenge_code' ) ?? '' );
			if ( '' === $challenge_code ) {
				return new WP_REST_Response( [ 'ok' => false ], 400 );
			}

			$cfg              = DTB_EbayConfig::get();
			$verify_token     = $cfg['deletion_verify_token'];
			$endpoint         = rest_url( 'dtb/v1/marketplace/ebay/deletion' );
			if ( ! preg_match( '/\A[A-Za-z0-9_-]{32,80}\z/', $verify_token ) ) {
				return new WP_REST_Response( [ 'ok' => false ], 503 );
			}

			// eBay spec: SHA256(challengeCode + verificationToken + endpoint)
			$hash = hash( 'sha256', $challenge_code . $verify_token . $endpoint );

			return new WP_REST_Response( [ 'challengeResponse' => $hash ], 200 );
		}

		/**
		 * Handle signed eBay notification deliveries (POST).
		 *
		 * Verify authenticity before scheduling a bounded redaction job. Never
		 * derive a destructive action from an unauthenticated request body.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function handle_notification( WP_REST_Request $request ): WP_REST_Response {
			$verified = DTB_EbayNotificationSignatureVerifier::verify( $request->get_body(), (string) $request->get_header( 'x-ebay-signature' ) );
			if ( is_wp_error( $verified ) ) {
				$error_data = $verified->get_error_data();
				$status     = 'ebay_signature_invalid' === $verified->get_error_code()
					? 412
					: ( is_array( $error_data ) ? (int) ( $error_data['status'] ?? 400 ) : 400 );
				return new WP_REST_Response( '', $status );
			}

			$metadata        = is_array( $verified['metadata'] ?? null ) ? $verified['metadata'] : [];
			$notification    = is_array( $verified['notification'] ?? null ) ? $verified['notification'] : [];
			$data            = is_array( $notification['data'] ?? null ) ? $notification['data'] : [];
			$notification_id = sanitize_text_field( (string) ( $notification['notificationId'] ?? '' ) );
			$topic           = sanitize_key( (string) ( $metadata['topic'] ?? '' ) );
			if ( '' === $notification_id || strlen( $notification_id ) > 191 || '' === $topic ) {
				return new WP_REST_Response( '', 400 );
			}
			if ( self::has_received_notification( $notification_id ) ) {
				return new WP_REST_Response( '', 204 );
			}

			if ( 'order_confirmation' === $topic ) {
				$order = is_array( $data['order'] ?? null ) ? $data['order'] : [];
				$order_id = sanitize_text_field( (string) ( $order['orderId'] ?? '' ) );
				if ( '' === $order_id || strlen( $order_id ) > 191 ) {
					return new WP_REST_Response( '', 400 );
				}
				if ( ! function_exists( 'as_schedule_single_action' ) ) {
					return new WP_REST_Response( '', 503 );
				}

				$job_args = [ $order_id ];
				if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'dtb_ebay_order_sync_single', $job_args, 'dtb-marketplace' ) ) {
					$action_id = as_schedule_single_action(
						time() + 5,
						'dtb_ebay_order_sync_single',
						$job_args,
						'dtb-marketplace',
						true
					);
					if ( 0 === (int) $action_id && ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'dtb_ebay_order_sync_single', $job_args, 'dtb-marketplace' ) ) ) {
						return new WP_REST_Response( '', 503 );
					}
				}

				DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_WEBHOOK_RECEIVED, DTB_CHANNEL_EBAY, [
					'external_event_id' => $notification_id,
					'payload'           => [ 'type' => 'order_confirmation', 'status' => 'queued', 'order_id' => $order_id ],
				] );

				return new WP_REST_Response( '', 204 );
			}

			if ( 'new_message' === $topic ) {
				if ( ! class_exists( 'DTB_EbayMessageService' ) ) {
					return new WP_REST_Response( '', 503 );
				}
				$result = DTB_EbayMessageService::receive_notification( $data, $notification_id );
				if ( empty( $result['ok'] ) ) {
					DTB_MarketplaceExceptionService::create(
						DTB_MarketplaceExceptionService::CAT_MESSAGE_RECEIVE,
						DTB_CHANNEL_EBAY,
						'ebay_message_receive_failed',
						sanitize_text_field( (string) ( $result['error'] ?? 'The eBay message could not be persisted.' ) ),
						[
							'is_retryable'      => false,
							'linked_record_type' => 'marketplace_conversation',
							'linked_record_id'   => (int) ( $result['conversation_id'] ?? 0 ),
							'context'            => [ 'notification_id' => $notification_id ],
						]
					);
					return new WP_REST_Response( '', 503 );
				}
				return new WP_REST_Response( '', 204 );
			}

			if ( 'marketplace_account_deletion' !== $topic ) {
				return new WP_REST_Response( '', 204 );
			}

			$username        = sanitize_text_field( (string) ( $data['username'] ?? '' ) );
			$buyer_id        = '' !== $username ? $username : sanitize_text_field( (string) ( $data['userId'] ?? '' ) );

			if ( '' === $notification_id || '' === $buyer_id || strlen( $notification_id ) > 160 ) {
				return new WP_REST_Response( '', 400 );
			}

			if ( ! function_exists( 'as_schedule_single_action' ) ) {
				return new WP_REST_Response( '', 503 );
			}

			$buyer_hash = hash_hmac(
				'sha256',
				strtolower( trim( $buyer_id ) ),
				(string) ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'dtb' )
			);
			if ( self::has_processed_notification( $notification_id ) ) {
				return new WP_REST_Response( '', 204 );
			}

			$job_args = [ $notification_id, $buyer_hash ];
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'dtb_ebay_buyer_redaction', $job_args, 'dtb-marketplace' ) ) {
				return new WP_REST_Response( '', 204 );
			}

			$action_id = as_schedule_single_action(
				time() + 5,
				'dtb_ebay_buyer_redaction',
				$job_args,
				'dtb-marketplace',
				true
			);
			if ( 0 === (int) $action_id ) {
				if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'dtb_ebay_buyer_redaction', $job_args, 'dtb-marketplace' ) ) {
					return new WP_REST_Response( '', 204 );
				}
				return new WP_REST_Response( '', 503 );
			}

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_WEBHOOK_RECEIVED, DTB_CHANNEL_EBAY, [
				'external_event_id' => $notification_id,
				'payload'           => [ 'type' => 'account_deletion', 'status' => 'verified_and_queued' ],
			] );

			return new WP_REST_Response( '', 204 );
		}

		/** Check whether the verified notification was durably accepted. */
		private static function has_received_notification( string $notification_id ): bool {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_events';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE channel_key = %s AND event_type = %s AND external_event_id = %s LIMIT 1",
				DTB_CHANNEL_EBAY,
				DTB_MarketplaceEventService::EVT_WEBHOOK_RECEIVED,
				$notification_id
			) );
		}

		/** Check whether the verified notification's redaction was completed. */
		private static function has_processed_notification( string $notification_id ): bool {
			global $wpdb;
			$table = $wpdb->prefix . 'dtb_marketplace_events';
			$key   = 'ebay:account-deletion:processed:' . $notification_id;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE idempotency_key = %s LIMIT 1",
				$key
			) );
		}

		/**
		 * Redact marketplace conversation data by the already-derived buyer hash.
		 * Called only by the verified notification's Action Scheduler job.
		 *
		 * @param string $notification_id eBay notification identity.
		 * @param string $buyer_hash HMAC of the eBay buyer identifier.
		 */
		public static function redact_buyer( string $notification_id, string $buyer_hash ): void {
			global $wpdb;
			if ( ! preg_match( '/\A[a-f0-9]{64}\z/i', $buyer_hash ) || '' === $notification_id ) {
				return;
			}
			$now        = current_time( 'mysql', true );

			// Redact conversations.
			$conv_table = $wpdb->prefix . 'dtb_marketplace_conversations';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$conv_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$conv_table} WHERE channel_key = %s AND buyer_ref_hash = %s",
				DTB_CHANNEL_EBAY, $buyer_hash
			) );

			if ( ! empty( $conv_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $conv_ids ), '%d' ) );
				$msg_table    = $wpdb->prefix . 'dtb_marketplace_messages';

				// Redact message bodies.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$msg_table} SET body_preview = '[redacted]', body_encrypted = NULL, updated_at = %s
					 WHERE conversation_id IN ({$placeholders})",
					...[ $now, ...$conv_ids ]
				) );

				// Mark conversations as redacted.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->query( $wpdb->prepare(
					"UPDATE {$conv_table} SET status = 'redacted', buyer_ref_hash = '', updated_at = %s
					 WHERE id IN ({$placeholders})",
					...[ $now, ...$conv_ids ]
				) );
			}

			// Audit the redaction.
			DTB_MarketplaceAuditService::write(
				'buyer.redacted',
				'ebay_buyer',
				0,
				DTB_CHANNEL_EBAY,
				[ 'after' => [ 'buyer_hash' => $buyer_hash, 'redacted_at' => $now ], 'actor_id' => 0 ]
			);

			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_WEBHOOK_RECEIVED, DTB_CHANNEL_EBAY, [
				'external_event_id' => $notification_id,
				'idempotency_key'   => 'ebay:account-deletion:processed:' . $notification_id,
				'payload'           => [ 'type' => 'account_deletion', 'status' => 'redacted', 'conversations' => count( $conv_ids ) ],
			] );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_EbayDeletionController', 'register_routes' ] );

// Register async redaction job handler.
add_action( 'dtb_ebay_buyer_redaction', static function ( string $notification_id = '', string $buyer_hash = '' ): void {
	DTB_EbayDeletionController::redact_buyer( $notification_id, $buyer_hash );
} );
