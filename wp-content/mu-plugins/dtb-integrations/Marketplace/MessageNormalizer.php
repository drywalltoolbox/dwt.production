<?php
/**
 * Marketplace — MessageNormalizer
 *
 * Transforms raw Amazon/eBay message payloads into canonical internal shape
 * for storage in wp_dtb_marketplace_messages.
 *
 * Buyer PII is never stored in plain text in searchable columns.
 * Body is truncated to a safe preview; full body may be encrypted at rest.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceMessageNormalizer' ) ) {
	final class DTB_MarketplaceMessageNormalizer {

		private const PREVIEW_LENGTH = 280;

		/**
		 * Normalize an Amazon Messaging API message.
		 *
		 * @param array $raw     Raw message data.
		 * @param int   $conv_id Internal conversation_id.
		 * @return array
		 */
		public static function from_amazon( array $raw, int $conv_id ): array {
			$body = (string) ( $raw['messageBody'] ?? $raw['body'] ?? '' );

			return [
				'conversation_id'    => $conv_id,
				'external_message_id' => (string) ( $raw['messageId'] ?? $raw['id'] ?? '' ),
				'direction'          => 'inbound',
				'sender_type'        => 'buyer',
				'body_preview'       => self::redact_preview( $body ),
				'body_encrypted'     => self::encrypt_body( $body ),
				'attachment_meta_json' => self::encode_attachments( $raw['attachments'] ?? [] ),
				'message_status'     => 'received',
				'platform_action'    => '',
				'created_at'         => self::parse_dt( (string) ( $raw['createdDate'] ?? '' ) ),
			];
		}

		/**
		 * Normalize an eBay buyer message.
		 *
		 * @param array $raw     Raw message from eBay Customer Service or Messaging API.
		 * @param int   $conv_id Internal conversation_id.
		 * @return array
		 */
		public static function from_ebay( array $raw, int $conv_id ): array {
			$body = (string) ( $raw['body'] ?? $raw['messageBody'] ?? $raw['text'] ?? '' );

			return [
				'conversation_id'     => $conv_id,
				'external_message_id' => (string) ( $raw['messageId'] ?? $raw['id'] ?? '' ),
				'direction'           => 'inbound',
				'sender_type'         => 'buyer',
				'body_preview'        => self::redact_preview( $body ),
				'body_encrypted'      => self::encrypt_body( $body ),
				'attachment_meta_json' => self::encode_attachments( $raw['attachments'] ?? [] ),
				'message_status'      => 'received',
				'platform_action'     => '',
				'created_at'          => self::parse_dt( (string) ( $raw['receivedDate'] ?? $raw['creationDate'] ?? '' ) ),
			];
		}

		/**
		 * Build a draft outbound message record.
		 *
		 * @param int    $conv_id         Internal conversation_id.
		 * @param string $body            Reply body.
		 * @param string $platform_action Allowed action slug, e.g. 'SendInvoice'.
		 * @param int    $operator_id     WP user ID.
		 * @param string $idempotency_key Unique key to prevent duplicate sends.
		 * @return array
		 */
		public static function build_outbound( int $conv_id, string $body, string $platform_action, int $operator_id, string $idempotency_key ): array {
			return [
				'conversation_id'     => $conv_id,
				'external_message_id' => '',
				'direction'           => 'outbound',
				'sender_type'         => 'operator',
				'body_preview'        => self::redact_preview( $body ),
				'body_encrypted'      => self::encrypt_body( $body ),
				'attachment_meta_json' => null,
				'message_status'      => 'queued',
				'platform_action'     => sanitize_key( $platform_action ),
				'operator_id'         => $operator_id,
				'idempotency_key'     => $idempotency_key,
				'send_attempt_count'  => 0,
				'created_at'          => current_time( 'mysql', true ),
				'updated_at'          => current_time( 'mysql', true ),
			];
		}

		// ── Private helpers ───────────────────────────────────────────────────

		/**
		 * Produce a safe, redacted body preview (no email/phone/full name).
		 */
		private static function redact_preview( string $body ): string {
			$body = preg_replace( '/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', '[email]', $body ) ?? $body;
			$body = preg_replace( '/\+?[\d\s\-().]{7,}/', '[phone]', $body ) ?? $body;
			$body = wp_strip_all_tags( $body );
			return substr( trim( $body ), 0, self::PREVIEW_LENGTH );
		}

		/**
		 * Encrypt full body for at-rest storage. Returns null when body is empty.
		 */
		private static function encrypt_body( string $body ): ?string {
			if ( '' === $body ) {
				return null;
			}
			if ( class_exists( 'DTB_MarketplaceCredentialFacade' ) ) {
				$enc = DTB_MarketplaceCredentialFacade::encrypt( $body );
				return ( false !== $enc ) ? $enc : null;
			}
			return null;
		}

		/**
		 * Safely encode attachment metadata (filenames, content-type, size; no binary).
		 */
		private static function encode_attachments( array $attachments ): ?string {
			if ( empty( $attachments ) ) {
				return null;
			}
			$safe = [];
			foreach ( $attachments as $att ) {
				$safe[] = [
					'name' => sanitize_text_field( (string) ( $att['name'] ?? $att['fileName'] ?? '' ) ),
					'type' => sanitize_text_field( (string) ( $att['contentType'] ?? $att['type'] ?? '' ) ),
					'size' => (int) ( $att['size'] ?? 0 ),
				];
			}
			return wp_json_encode( $safe );
		}

		private static function parse_dt( string $value ): string {
			if ( '' === $value ) {
				return current_time( 'mysql', true );
			}
			$ts = strtotime( $value );
			return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : current_time( 'mysql', true );
		}
	}
}
