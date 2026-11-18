<?php
/**
 * Marketplace — CredentialFacade
 *
 * Stores and retrieves marketplace credentials using WordPress options with
 * AES-256-CBC encryption keyed from AUTH_KEY + AUTH_SALT.
 *
 * NEVER returns raw secrets to REST responses or logs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DTB_MarketplaceCredentialFacade' ) ) {
	final class DTB_MarketplaceCredentialFacade {

		private const OPTION_PREFIX = 'dtb_mp_cred_';

		// ── Encryption helpers ────────────────────────────────────────────────

		/**
		 * Derive a 32-byte key from WP secret constants.
		 */
		private static function derive_key(): string {
			$raw  = ( defined( 'AUTH_KEY' )  ? AUTH_KEY  : '' )
				  . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
			return hash( 'sha256', $raw, true );
		}

		/**
		 * Encrypt a plain-text string.
		 *
		 * @param string $plain Plain text.
		 * @return string|false Base64-encoded IV+ciphertext, or false on failure.
		 */
		public static function encrypt( string $plain ): string|false {
			if ( '' === $plain ) {
				return '';
			}
			$key = self::derive_key();
			$iv  = random_bytes( 16 );
			$ct  = openssl_encrypt( $plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
			if ( false === $ct ) {
				return false;
			}
			return base64_encode( $iv . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		/**
		 * Decrypt a stored value.
		 *
		 * @param string $stored Base64-encoded IV+ciphertext.
		 * @return string|false Plain text, or false on failure.
		 */
		public static function decrypt( string $stored ): string|false {
			if ( '' === $stored ) {
				return '';
			}
			$key  = self::derive_key();
			$raw  = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return false;
			}
			$iv = substr( $raw, 0, 16 );
			$ct = substr( $raw, 16 );
			return openssl_decrypt( $ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		}

		// ── Storage ────────────────────────────────────────────────────────────

		/**
		 * Store a credential bag for a channel.
		 * Encrypts all values before persisting.
		 *
		 * @param string               $channel_key Channel key.
		 * @param array<string,string> $creds       Key-value credential pairs.
		 */
		public static function store( string $channel_key, array $creds ): void {
			$encrypted = [];
			foreach ( $creds as $k => $v ) {
				$enc = self::encrypt( (string) $v );
				if ( false !== $enc ) {
					$encrypted[ sanitize_key( $k ) ] = $enc;
				}
			}
			update_option( self::OPTION_PREFIX . sanitize_key( $channel_key ), $encrypted, false );
		}

		/**
		 * Retrieve a decrypted credential bag for a channel.
		 *
		 * @param string $channel_key Channel key.
		 * @return array<string,string>
		 */
		public static function get( string $channel_key ): array {
			$stored = (array) get_option( self::OPTION_PREFIX . sanitize_key( $channel_key ), [] );
			$out    = [];
			foreach ( $stored as $k => $v ) {
				$dec = self::decrypt( (string) $v );
				if ( false !== $dec ) {
					$out[ $k ] = $dec;
				}
			}
			return $out;
		}

		/**
		 * Retrieve a single credential field.
		 *
		 * @param string $channel_key Channel key.
		 * @param string $field       Credential field name.
		 * @return string Decrypted value or empty string.
		 */
		public static function get_field( string $channel_key, string $field ): string {
			return self::get( $channel_key )[ $field ] ?? '';
		}

		/**
		 * Return a safe status array for display (no secrets).
		 *
		 * @param string   $channel_key Channel key.
		 * @param string[] $fields      Expected field names.
		 * @return array<string,bool>
		 */
		public static function status( string $channel_key, array $fields ): array {
			$bag = self::get( $channel_key );
			$out = [];
			foreach ( $fields as $f ) {
				$out[ $f ] = isset( $bag[ $f ] ) && '' !== $bag[ $f ];
			}
			return $out;
		}

		/**
		 * Delete all credentials for a channel.
		 *
		 * @param string $channel_key Channel key.
		 */
		public static function delete( string $channel_key ): void {
			delete_option( self::OPTION_PREFIX . sanitize_key( $channel_key ) );
		}
	}
}
