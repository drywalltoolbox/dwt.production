<?php
/**
 * Marketplace — ActionPolicyValidator
 *
 * Enforces which platform actions are available for each channel,
 * blocking operators from attempting unsupported actions.
 *
 * Amazon: actions are order-scoped and must be fetched from the Messaging API
 *         (GetMessagingActionsForOrder) at runtime. Operator UI must only
 *         present actions returned by that endpoint.
 *
 * eBay: buyer-message replies must include a buyer username, item ID, and
 *       order ID. Rate-limit guard must pass before sending.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceActionPolicyValidator' ) ) {
	final class DTB_MarketplaceActionPolicyValidator {

		// Known Amazon message action slugs (not exhaustive; runtime check is authoritative).
		private const AMAZON_KNOWN_ACTIONS = [
			'SendInvoice',
			'ConfirmCustomizationDetails',
			'CreateConfirmOrderDetails',
			'CreateConfirmServiceDetails',
			'CreateDigitalAccessKey',
			'CreateUnexpectedProblem',
			'CreateWarranty',
			'GetAttributes',
			'SendAmazonMotors',
		];

		/**
		 * Validate an Amazon outbound action against the dynamically-fetched allowed list.
		 *
		 * @param string   $action          Action slug, e.g. 'SendInvoice'.
		 * @param string[] $allowed_actions List returned by GetMessagingActionsForOrder.
		 * @return array{ok: bool, reason: string}
		 */
		public static function validate_amazon( string $action, array $allowed_actions ): array {
			if ( empty( $allowed_actions ) ) {
				return [
					'ok'     => false,
					'reason' => 'No messaging actions are available for this Amazon order. The SP-API did not return any allowed actions.',
				];
			}

			$action_slugs = array_map( static function ( $a ) {
				return is_array( $a ) ? ( $a['actionName'] ?? (string) $a ) : (string) $a;
			}, $allowed_actions );

			if ( ! in_array( $action, $action_slugs, true ) ) {
				return [
					'ok'     => false,
					'reason' => sprintf(
						'Action "%s" is not available for this Amazon order. Available actions: %s',
						esc_html( $action ),
						implode( ', ', array_map( 'esc_html', $action_slugs ) )
					),
				];
			}

			return [ 'ok' => true, 'reason' => '' ];
		}

		/**
		 * Validate an eBay outbound reply.
		 *
		 * @param string $buyer_username eBay buyer username.
		 * @param string $item_id        eBay item ID.
		 * @param string $order_id       eBay order ID.
		 * @param string $body           Reply body.
		 * @param string $channel_key    Channel key for rate-limit check.
		 * @return array{ok: bool, reason: string}
		 */
		public static function validate_ebay_reply(
			string $buyer_username,
			string $item_id,
			string $order_id,
			string $body,
			string $channel_key
		): array {
			if ( '' === $buyer_username ) {
				return [ 'ok' => false, 'reason' => 'eBay reply requires buyer username.' ];
			}
			if ( '' === $item_id && '' === $order_id ) {
				return [ 'ok' => false, 'reason' => 'eBay reply requires item ID or order ID context.' ];
			}
			if ( '' === trim( $body ) ) {
				return [ 'ok' => false, 'reason' => 'Reply body cannot be empty.' ];
			}
			if ( strlen( $body ) > 2000 ) {
				return [ 'ok' => false, 'reason' => 'eBay reply body exceeds 2000-character limit.' ];
			}

			// Local rate-limit guard.
			if ( class_exists( 'DTB_MarketplaceRateLimitState' ) ) {
				if ( ! DTB_MarketplaceRateLimitState::allow( $channel_key, 'send_message', 5, 60 ) ) {
					$wait = DTB_MarketplaceRateLimitState::backoff_seconds( $channel_key, 'send_message' );
					return [
						'ok'     => false,
						'reason' => sprintf( 'eBay message rate limit reached. Retry in %d seconds.', $wait > 0 ? $wait : 60 ),
					];
				}
			}

			return [ 'ok' => true, 'reason' => '' ];
		}

		/**
		 * Return all known Amazon action slugs (for UI reference).
		 *
		 * @return string[]
		 */
		public static function amazon_known_actions(): array {
			return self::AMAZON_KNOWN_ACTIONS;
		}
	}
}
