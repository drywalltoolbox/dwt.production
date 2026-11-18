<?php
/**
 * Marketplace — ChannelContract
 *
 * Defines the interface every marketplace channel adapter must implement,
 * plus the canonical channel-key constants and a static registry.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// Canonical channel-key constants.
define( 'DTB_CHANNEL_AMAZON', 'amazon' );
define( 'DTB_CHANNEL_EBAY',   'ebay' );

if ( ! interface_exists( 'DTB_MarketplaceChannelContract' ) ) {
	interface DTB_MarketplaceChannelContract {
		/** Unique lowercase channel key, e.g. 'amazon'. */
		public function channel_key(): string;

		/** Human label, e.g. 'Amazon'. */
		public function channel_label(): string;

		/** Return true when the channel is configured and enabled. */
		public function is_enabled(): bool;

		/** Return true when credentials are valid and unexpired. */
		public function is_authenticated(): bool;

		/** Return a structured health-state array for the health registry. */
		public function health_check(): array;
	}
}

if ( ! class_exists( 'DTB_MarketplaceChannelRegistry' ) ) {
	/**
	 * Static registry of all loaded marketplace channel adapters.
	 */
	final class DTB_MarketplaceChannelRegistry {
		/** @var array<string, DTB_MarketplaceChannelContract> */
		private static array $channels = [];

		/**
		 * Register a channel adapter.
		 *
		 * @param DTB_MarketplaceChannelContract $channel Channel adapter instance.
		 */
		public static function register( DTB_MarketplaceChannelContract $channel ): void {
			self::$channels[ $channel->channel_key() ] = $channel;
		}

		/**
		 * Return a registered channel by key, or null.
		 *
		 * @param string $key Channel key.
		 * @return DTB_MarketplaceChannelContract|null
		 */
		public static function get( string $key ): ?DTB_MarketplaceChannelContract {
			return self::$channels[ $key ] ?? null;
		}

		/**
		 * Return all registered channels.
		 *
		 * @return array<string, DTB_MarketplaceChannelContract>
		 */
		public static function all(): array {
			return self::$channels;
		}

		/**
		 * Return keys of all enabled channels.
		 *
		 * @return string[]
		 */
		public static function enabled_keys(): array {
			$keys = [];
			foreach ( self::$channels as $key => $ch ) {
				if ( $ch->is_enabled() ) {
					$keys[] = $key;
				}
			}
			return $keys;
		}
	}
}
