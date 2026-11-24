<?php
/**
 * Marketplace — CredentialFacade
 *
 * Stores and retrieves marketplace credentials using versioned authenticated
 * encryption in WordPress options, keyed from AUTH_KEY + AUTH_SALT.
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

		/** Derive the legacy CBC key for backward-compatible reads. */
		private static function derive_legacy_key(): string {
			$raw  = ( defined( 'AUTH_KEY' )  ? AUTH_KEY  : '' )
				  . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' );
			return hash( 'sha256', $raw, true );
		}

		/** Derive a purpose-separated authenticated-encryption key. */
		private static function derive_v2_key(): string|false {
			$raw = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' )
				. ( defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '' );
			if ( '' === $raw ) {
				return false;
			}
			return hash_hkdf( 'sha256', $raw, 32, 'dtb-marketplace-credential-v2' );
		}

		/**
		 * Encrypt a plain-text string.
		 *
		 * @param string $plain Plain text.
		 * @return string|false Versioned base64-encoded IV+tag+ciphertext, or false on failure.
		 */
		public static function encrypt( string $plain ): string|false {
			if ( '' === $plain ) {
				return '';
			}
			$key = self::derive_v2_key();
			if ( false === $key ) {
				return false;
			}
			try {
				$iv = random_bytes( 12 );
			} catch ( Throwable $e ) {
				return false;
			}
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
			if ( false === $ct || 16 !== strlen( $tag ) ) {
				return false;
			}
			return 'v2:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		/**
		 * Decrypt a stored value.
		 *
		 * @param string $stored Versioned authenticated value or legacy base64 IV+ciphertext.
		 * @return string|false Plain text, or false on failure.
		 */
		public static function decrypt( string $stored ): string|false {
			if ( '' === $stored ) {
				return '';
			}
			if ( str_starts_with( $stored, 'v2:' ) ) {
				$key = self::derive_v2_key();
				$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
				if ( false === $key || false === $raw || strlen( $raw ) < 29 ) {
					return false;
				}
				$iv  = substr( $raw, 0, 12 );
				$tag = substr( $raw, 12, 16 );
				$ct  = substr( $raw, 28 );
				return openssl_decrypt( $ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '' );
			}

			$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return false;
			}
			$iv = substr( $raw, 0, 16 );
			$ct = substr( $raw, 16 );
			return openssl_decrypt( $ct, 'AES-256-CBC', self::derive_legacy_key(), OPENSSL_RAW_DATA, $iv );
		}

		// ── Storage ────────────────────────────────────────────────────────────

		/**
		 * Store a credential bag for a channel.
		 * Encrypts all values before persisting.
		 *
		 * @param string               $channel_key Channel key.
		 * @param array<string,string> $creds       Non-empty key-value fields to add or replace.
		 * @return bool Whether the merged encrypted bag was durably stored.
		 */
		public static function store( string $channel_key, array $creds ): bool {
			$option_key = self::OPTION_PREFIX . sanitize_key( $channel_key );
			$encrypted = (array) get_option( $option_key, [] );
			foreach ( $creds as $k => $v ) {
				$enc = self::encrypt( (string) $v );
				$key = sanitize_key( (string) $k );
				if ( false === $enc || '' === $key ) {
					return false;
				}
				$encrypted[ $key ] = $enc;
			}
			update_option( $option_key, $encrypted, false );
			return $encrypted === get_option( $option_key, null );
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
