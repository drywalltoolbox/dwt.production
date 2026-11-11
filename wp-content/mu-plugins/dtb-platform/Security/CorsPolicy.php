<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_CorsPolicy' ) ) {
	return;
}

final class DTB_CorsPolicy {
	/**
	 * @return array<string,string>
	 */
	public static function headers( ?string $origin = null ): array {
		$headers = [
			'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type, X-WP-Nonce, X-Requested-With, X-WC-Store-API-Nonce, Cart-Token',
			'Access-Control-Expose-Headers' => 'X-WC-Store-API-Nonce, Nonce, Cart-Token',
			'Access-Control-Max-Age' => '86400',
		];

		$ops_version = defined( 'DTB_OPS_VERSION' ) ? DTB_OPS_VERSION : '1.0.0';
		$headers['X-DTB-Version'] = $ops_version;

		if ( $origin && DTB_OriginAllowlist::is_allowed( $origin ) ) {
			$headers['Access-Control-Allow-Origin'] = rtrim( $origin, '/' );
			$headers['Access-Control-Allow-Credentials'] = 'true';
			$headers['Vary'] = 'Origin';
		}

		return $headers;
	}

	public static function emit( ?string $origin = null ): void {
		if ( headers_sent() ) {
			return;
		}

		foreach ( self::headers( $origin ) as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}
}
