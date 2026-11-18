<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_FriendlyLogWriter' ) ) {
	return;
}

final class DTB_FriendlyLogWriter {
	private const MAX_BYTES = 2097152; // 2 MB cap per friendly log file.

	public static function write( string $source, string $level, string $message, array $context = [] ): void {
		$source = sanitize_key( $source );
		$level  = strtoupper( sanitize_key( $level ) );

		if ( '' === $source ) {
			$source = 'dtb-platform';
		}

		$line = self::format_line( $source, $level, $message, $context );
		if ( '' === $line ) {
			return;
		}

		$path = self::resolve_log_path( $source );
		if ( '' === $path ) {
			return;
		}

		self::prepend_line( $path, $line );
	}

	private static function format_line( string $source, string $level, string $message, array $context ): string {
		$timestamp = function_exists( 'wp_date' )
			? wp_date( 'Y-m-d g:i:s A T' )
			: gmdate( 'Y-m-d g:i:s A \\U\\T\\C' );

		$safe_context = [];
		foreach ( $context as $key => $value ) {
			$safe_key = sanitize_key( (string) $key );
			if ( '' === $safe_key ) {
				continue;
			}

			if ( is_scalar( $value ) || null === $value ) {
				$safe_context[ $safe_key ] = is_bool( $value ) ? $value : sanitize_text_field( (string) $value );
				continue;
			}

			$encoded = wp_json_encode( $value, JSON_UNESCAPED_SLASHES );
			if ( false !== $encoded ) {
				$safe_context[ $safe_key ] = $encoded;
			}
		}

		$message      = sanitize_text_field( $message );
		$context_json = empty( $safe_context ) ? '' : wp_json_encode( $safe_context, JSON_UNESCAPED_SLASHES );
		$context_part = $context_json ? ' | context=' . $context_json : '';

		return sprintf( "[%s] [%s] [%s] %s%s\n", $timestamp, $source, $level, $message, $context_part );
	}

	private static function resolve_log_path( string $source ): string {
		if ( ! function_exists( 'wp_get_upload_dir' ) || ! function_exists( 'wp_mkdir_p' ) ) {
			return '';
		}

		$uploads = wp_get_upload_dir();
		$base    = isset( $uploads['basedir'] ) ? (string) $uploads['basedir'] : '';
		if ( '' === $base ) {
			return '';
		}

		$dir = trailingslashit( $base ) . 'wc-logs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return '';
		}

		$filename = sanitize_file_name( $source . '-friendly.log' );
		if ( '' === $filename ) {
			$filename = 'dtb-platform-friendly.log';
		}

		return trailingslashit( $dir ) . $filename;
	}

	private static function prepend_line( string $path, string $line ): void {
		$handle = fopen( $path, 'c+' );
		if ( false === $handle ) {
			return;
		}

		if ( ! flock( $handle, LOCK_EX ) ) {
			fclose( $handle );
			return;
		}

		rewind( $handle );
		$existing = stream_get_contents( $handle );
		if ( false === $existing ) {
			$existing = '';
		}

		$new_content = $line . $existing;
		if ( strlen( $new_content ) > self::MAX_BYTES ) {
			$new_content  = substr( $new_content, 0, self::MAX_BYTES );
			$last_newline = strrpos( $new_content, "\n" );
			if ( false !== $last_newline ) {
				$new_content = substr( $new_content, 0, $last_newline + 1 );
			}
		}

		ftruncate( $handle, 0 );
		rewind( $handle );
		fwrite( $handle, $new_content );
		fflush( $handle );
		flock( $handle, LOCK_UN );
		fclose( $handle );
	}
}