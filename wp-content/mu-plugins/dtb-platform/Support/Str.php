<?php
/**
 * Str — DTB Platform
 *
 * String utility helpers (extension point).
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_str_decode_entities' ) ) {
	/**
	 * Decode HTML entities and normalize known display glyphs.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	function dtb_str_decode_entities( string $value ): string {
		$decoded = html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		$decoded = str_replace(
			[
				"\u{00A0}", // nbsp
				"\u{200B}", // zero-width space
				"\u{200C}",
				"\u{200D}",
				"\u{FEFF}", // BOM
				"\u{2032}", // prime
				"\u{2033}", // double-prime
			],
			[
				' ',
				'',
				'',
				'',
				'',
				"'",
				'"',
			],
			$decoded
		);

		return wp_check_invalid_utf8( $decoded );
	}
}

if ( ! function_exists( 'dtb_str_normalize_display' ) ) {
	/**
	 * Normalize a text value for UI/display surfaces.
	 *
	 * @param string $value             Raw value.
	 * @param bool   $preserve_newlines Keep line breaks for multiline content.
	 * @return string
	 */
	function dtb_str_normalize_display( string $value, bool $preserve_newlines = false ): string {
		$clean = dtb_str_decode_entities( $value );

		if ( $preserve_newlines ) {
			$clean = str_replace( [ "\r\n", "\r" ], "\n", $clean );
			$lines = array_map(
				static function ( string $line ): string {
					$line = preg_replace( '/[ \t]+/u', ' ', $line );
					return trim( (string) $line );
				},
				explode( "\n", $clean )
			);
			$clean = preg_replace( "/\n{3,}/", "\n\n", implode( "\n", $lines ) );
			return trim( (string) $clean );
		}

		$clean = preg_replace( '/\s+/u', ' ', $clean );
		return trim( (string) $clean );
	}
}

if ( ! function_exists( 'dtb_str_normalize_display_mixed' ) ) {
	/**
	 * Recursively normalize strings in arrays/objects for display.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	function dtb_str_normalize_display_mixed( $value ) {
		if ( is_string( $value ) ) {
			return dtb_str_normalize_display( $value, false );
		}
		if ( is_array( $value ) ) {
			$out = [];
			foreach ( $value as $k => $v ) {
				$out[ $k ] = dtb_str_normalize_display_mixed( $v );
			}
			return $out;
		}
		return $value;
	}
}
