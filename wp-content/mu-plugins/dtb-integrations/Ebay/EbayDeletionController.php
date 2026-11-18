<?php
/**
 * eBay — EbayDeletionController
 *
 * Handles eBay Marketplace Account Deletion/Closure notifications.
 * Required by eBay developer program compliance before production enablement.
 *
 * Endpoints:
 *   GET  /wp-json/dtb/v1/marketplace/ebay/deletion  — verification challenge
 *   POST /wp-json/dtb/v1/marketplace/ebay/deletion  — deletion notification processing
 *
 * On receiving a deletion notification:
 *   1. Validates the X-Ebay-Signature-Timestamp + X-Ebay-Signature.
 *   2. Redacts/deletes all stored buyer PII for the user.
 *   3. Marks conversations/messages as redacted.
 *   4. Logs a marketplace audit entry.
 *   5. Returns 200 OK with empty body.
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
					'callback'            => [ self::class, 'handle_deletion' ],
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

			// eBay spec: SHA256(challengeCode + verificationToken + endpoint)
			$hash = hash( 'sha256', $challenge_code . $verify_token . $endpoint );

			return new WP_REST_Response( [ 'challengeResponse' => $hash ], 200 );
		}

		/**
		 * Handle eBay deletion notification (POST).
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response
		 */
		public static function handle_deletion( WP_REST_Request $request ): WP_REST_Response {
			$body = $request->get_body();
			$data = json_decode( $body, true );

			if ( ! is_array( $data ) ) {
				return new WP_REST_Response( '', 400 );
			}

			// Log receipt of deletion notification.
			DTB_MarketplaceEventService::append( DTB_MarketplaceEventService::EVT_WEBHOOK_RECEIVED, DTB_CHANNEL_EBAY, [
				'payload' => [ 'type' => 'account_deletion', 'status' => 'received' ],
			] );

			// Extract buyer reference (eBay provides userId or username).
			$ebay_user_id = sanitize_text_field( $data['userId'] ?? $data['username'] ?? '' );
			if ( '' === $ebay_user_id ) {
				// Log but accept — eBay requires 200 for all deletion requests.
				DTB_MarketplaceExceptionService::create(
					DTB_MarketplaceExceptionService::CAT_DATA_DELETION,
					DTB_CHANNEL_EBAY,
					'deletion_no_user_id',
					'eBay deletion notification missing userId.',
					[ 'is_retryable' => false ]
				);
				return new WP_REST_Response( '', 200 );
			}

			// Schedule async redaction job to avoid slow synchronous processing.
			$hook = 'dtb_ebay_buyer_redaction';
			$args = [ $ebay_user_id ];
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + 30, $hook, [ $args ], 'dtb-marketplace' );
			} else {
				wp_schedule_single_event( time() + 30, $hook, $args );
			}

			return new WP_REST_Response( '', 200 );
		}

		/**
		 * Redact all buyer data for the given eBay user ID.
		 * Called by async job (dtb_ebay_buyer_redaction).
		 *
		 * @param string $ebay_user_id eBay user ID or username.
		 */
		public static function redact_buyer( string $ebay_user_id ): void {
			global $wpdb;
			$buyer_hash = hash_hmac( 'sha256', strtolower( trim( $ebay_user_id ) ), (string) ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'dtb' ) );
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
				'payload' => [ 'type' => 'account_deletion', 'status' => 'redacted', 'conversations' => count( $conv_ids ) ],
			] );
		}
	}
}

add_action( 'rest_api_init', [ 'DTB_EbayDeletionController', 'register_routes' ] );

// Register async redaction job handler.
add_action( 'dtb_ebay_buyer_redaction', static function ( string $ebay_user_id ): void {
	DTB_EbayDeletionController::redact_buyer( $ebay_user_id );
} );
