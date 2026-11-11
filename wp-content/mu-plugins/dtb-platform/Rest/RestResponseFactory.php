<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_RestResponseFactory' ) ) {
	return;
}

final class DTB_RestResponseFactory {
	public static function ok( array $data = [], int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( [ 'ok' => true ] + $data, $status );
	}

	public static function error( string $code, string $message, int $status = 400, array $extra = [] ): WP_REST_Response {
		$payload = dtb_error_envelope( $code, $message, $status );
		if ( ! empty( $extra ) ) {
			$payload['error']['details'] = $extra;
		}

		return new WP_REST_Response( $payload, $status );
	}
}
