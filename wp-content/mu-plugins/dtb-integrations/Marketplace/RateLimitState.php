<?php
/**
 * Marketplace — RateLimitState
 *
 * Transient-backed rate-limit tracker. Tracks per-channel, per-operation
 * request windows to guard against API quota exhaustion.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceRateLimitState' ) ) {
	final class DTB_MarketplaceRateLimitState {

		private const TRANSIENT_PREFIX = 'dtb_mp_rl_';
		private const BACKOFF_KEY      = 'dtb_mp_rl_backoff_';

		/**
		 * Check whether the given operation is allowed under the rate limit.
		 * Records the attempt if allowed.
		 *
		 * @param string $channel   Channel key.
		 * @param string $operation Operation slug, e.g. 'send_message'.
		 * @param int    $limit     Max calls per $window_seconds.
		 * @param int    $window    Window size in seconds.
		 * @return bool True when the call is allowed.
		 */
		public static function allow( string $channel, string $operation, int $limit = 10, int $window = 60 ): bool {
			if ( self::in_backoff( $channel, $operation ) ) {
				return false;
			}

			$key   = self::TRANSIENT_PREFIX . sanitize_key( $channel . '_' . $operation );
			$count = (int) get_transient( $key );

			if ( $count >= $limit ) {
				return false;
			}

			// Increment or create with TTL = remaining window.
			$ttl = ( 0 === $count ) ? $window : (int) get_option( '_transient_timeout_' . $key, time() + $window ) - time();
			set_transient( $key, $count + 1, max( 1, $ttl ) );

			return true;
		}

		/**
		 * Record a rate-limit error from the API and activate exponential back-off.
		 *
		 * @param string $channel   Channel key.
		 * @param string $operation Operation slug.
		 * @param int    $retry_after Seconds to wait before retrying (from API header).
		 */
		public static function record_throttle( string $channel, string $operation, int $retry_after = 60 ): void {
			$key = self::BACKOFF_KEY . sanitize_key( $channel . '_' . $operation );
			set_transient( $key, time() + $retry_after, $retry_after + 10 );
		}

		/**
		 * Return true when back-off is active for the operation.
		 *
		 * @param string $channel   Channel key.
		 * @param string $operation Operation slug.
		 * @return bool
		 */
		public static function in_backoff( string $channel, string $operation ): bool {
			$key       = self::BACKOFF_KEY . sanitize_key( $channel . '_' . $operation );
			$until     = (int) get_transient( $key );
			return $until > time();
		}

		/**
		 * Return seconds until back-off expires (0 if not in back-off).
		 *
		 * @param string $channel   Channel key.
		 * @param string $operation Operation slug.
		 * @return int
		 */
		public static function backoff_seconds( string $channel, string $operation ): int {
			$key   = self::BACKOFF_KEY . sanitize_key( $channel . '_' . $operation );
			$until = (int) get_transient( $key );
			return max( 0, $until - time() );
		}

		/**
		 * Clear rate-limit and back-off state for an operation.
		 *
		 * @param string $channel   Channel key.
		 * @param string $operation Operation slug.
		 */
		public static function reset( string $channel, string $operation ): void {
			delete_transient( self::TRANSIENT_PREFIX . sanitize_key( $channel . '_' . $operation ) );
			delete_transient( self::BACKOFF_KEY . sanitize_key( $channel . '_' . $operation ) );
		}
	}
}
