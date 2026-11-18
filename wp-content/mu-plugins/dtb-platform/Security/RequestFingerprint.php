<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_RequestFingerprint' ) ) {
	return;
}

final class DTB_RequestFingerprint {
	public static function ip(): string {
		return dtb_get_client_ip();
	}

	public static function ua(): string {
		return isset( $_SERVER['HTTP_USER_AGENT'] )
			? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) )
			: '';
	}

	public static function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] )
			? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) )
			: 'GET';
	}

	public static function hash( string $scope = 'default' ): string {
		return hash( 'sha256', strtolower( $scope ) . '|' . self::ip() . '|' . self::ua() . '|' . self::method() );
	}
}
