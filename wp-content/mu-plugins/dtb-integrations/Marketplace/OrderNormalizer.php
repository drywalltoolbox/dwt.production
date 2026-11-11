<?php
/**
 * Marketplace — OrderNormalizer
 *
 * Transforms raw Amazon/eBay order payloads into a canonical internal shape
 * for storage in wp_dtb_marketplace_orders and Woo reconciliation.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceOrderNormalizer' ) ) {
	final class DTB_MarketplaceOrderNormalizer {

		/**
		 * Normalize an Amazon order payload.
		 *
		 * @param array $raw Raw payload from SP-API Orders API.
		 * @return array Normalized order.
		 */
		public static function from_amazon( array $raw ): array {
			$order_id  = (string) ( $raw['AmazonOrderId'] ?? '' );
			$buyer_email = (string) ( $raw['BuyerInfo']['BuyerEmail'] ?? '' );

			return [
				'channel_key'           => DTB_CHANNEL_AMAZON,
				'marketplace_order_id'  => $order_id,
				'buyer_ref_hash'        => self::hash_buyer( $buyer_email ?: $order_id ),
				'payment_state'         => self::normalize_amazon_payment( (string) ( $raw['PaymentMethodDetails'] ?? $raw['OrderStatus'] ?? '' ), (string) ( $raw['OrderStatus'] ?? '' ) ),
				'fulfillment_state'     => self::normalize_amazon_fulfillment( (string) ( $raw['OrderStatus'] ?? '' ), (string) ( $raw['FulfillmentChannel'] ?? '' ) ),
				'order_placed_at'       => self::parse_dt( (string) ( $raw['PurchaseDate'] ?? '' ) ),
				'raw_payload_hash'      => hash( 'sha256', wp_json_encode( $raw ) ),
				'sla_due_at'            => self::amazon_sla( (string) ( $raw['LatestShipDate'] ?? '' ) ),
				'_raw'                  => $raw,
			];
		}

		/**
		 * Normalize an eBay order payload.
		 *
		 * @param array $raw Raw payload from eBay Sell Fulfillment API.
		 * @return array Normalized order.
		 */
		public static function from_ebay( array $raw ): array {
			$order_id  = (string) ( $raw['orderId'] ?? '' );
			$buyer_ref = (string) ( $raw['buyer']['username'] ?? '' );

			return [
				'channel_key'           => DTB_CHANNEL_EBAY,
				'marketplace_order_id'  => $order_id,
				'buyer_ref_hash'        => self::hash_buyer( $buyer_ref ?: $order_id ),
				'payment_state'         => self::normalize_ebay_payment( (string) ( $raw['orderPaymentStatus'] ?? '' ) ),
				'fulfillment_state'     => self::normalize_ebay_fulfillment( (string) ( $raw['orderFulfillmentStatus'] ?? '' ) ),
				'order_placed_at'       => self::parse_dt( (string) ( $raw['creationDate'] ?? '' ) ),
				'raw_payload_hash'      => hash( 'sha256', wp_json_encode( $raw ) ),
				'sla_due_at'            => null,
				'_raw'                  => $raw,
			];
		}

		// ── Private helpers ───────────────────────────────────────────────────

		/**
		 * One-way hash of buyer identifier for indexing (no PII in DB indexes).
		 */
		private static function hash_buyer( string $identifier ): string {
			return hash_hmac( 'sha256', strtolower( trim( $identifier ) ), (string) ( defined( 'AUTH_SALT' ) ? AUTH_SALT : 'dtb' ) );
		}

		private static function normalize_amazon_payment( string $details, string $status ): string {
			return match ( strtoupper( $status ) ) {
				'PENDING'              => 'pending',
				'UNSHIPPED', 'SHIPPED' => 'paid',
				'CANCELED'             => 'canceled',
				default                => 'unknown',
			};
		}

		private static function normalize_amazon_fulfillment( string $status, string $channel ): string {
			return match ( strtoupper( $status ) ) {
				'UNSHIPPED'  => 'unshipped',
				'PARTSHIPPED' => 'partial',
				'SHIPPED'    => 'shipped',
				'CANCELED'   => 'canceled',
				default      => 'unknown',
			};
		}

		private static function normalize_ebay_payment( string $status ): string {
			return match ( strtoupper( $status ) ) {
				'FULLY_REFUNDED'  => 'refunded',
				'PAID'            => 'paid',
				'FAILED'          => 'failed',
				'PENDING'         => 'pending',
				default           => 'unknown',
			};
		}

		private static function normalize_ebay_fulfillment( string $status ): string {
			return match ( strtoupper( $status ) ) {
				'FULFILLED'         => 'shipped',
				'IN_PROGRESS'       => 'partial',
				'NOT_STARTED'       => 'unshipped',
				default             => 'unknown',
			};
		}

		private static function parse_dt( string $value ): ?string {
			if ( '' === $value ) {
				return null;
			}
			$ts = strtotime( $value );
			return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
		}

		private static function amazon_sla( string $latest_ship_date ): ?string {
			return self::parse_dt( $latest_ship_date );
		}
	}
}
