<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_Logger' ) ) {
	return;
}

final class DTB_Logger {
	public static function info( string $message, array $context = [] ): void {
		self::write( 'info', $message, $context );
	}

	public static function warning( string $message, array $context = [] ): void {
		self::write( 'warning', $message, $context );
	}

	public static function error( string $message, array $context = [] ): void {
		self::write( 'error', $message, $context );
	}

	private static function write( string $level, string $message, array $context ): void {
		$record = [
			'source'  => 'dtb-platform',
			'level'   => sanitize_key( $level ),
			'message' => sanitize_text_field( $message ),
			'context' => $context,
			'ts'      => function_exists( 'wp_date' ) ? wp_date( 'Y-m-d g:i:s A T' ) : gmdate( 'Y-m-d g:i:s A \\U\\T\\C' ),
		];

		if ( class_exists( 'DTB_FriendlyLogWriter' ) ) {
			DTB_FriendlyLogWriter::write(
				(string) $record['source'],
				(string) $record['level'],
				(string) $record['message'],
				is_array( $record['context'] ) ? $record['context'] : []
			);
		}

		error_log( wp_json_encode( $record, JSON_UNESCAPED_SLASHES ) );
	}
}
