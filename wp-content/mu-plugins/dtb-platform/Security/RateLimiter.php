<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_RateLimiter' ) ) {
	return;
}

final class DTB_RateLimiter {
	/**
	 * @return true|WP_Error
	 */
	public static function check( string $bucket, int $limit, int $window ): WP_Error|bool {
		$limit  = max( 1, $limit );
		$window = max( 1, $window );

		$key   = 'dtb_rl_' . sanitize_key( $bucket ) . '_' . substr( DTB_RequestFingerprint::hash( $bucket ), 0, 24 );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new WP_Error(
				'rate_limited',
				'Too many requests.',
				[
					'status'      => 429,
					'retry_after' => $window,
					'bucket'      => $bucket,
				]
			);
		}

		set_transient( $key, $count + 1, $window );
		return true;
	}
}

/**
 * Apply the shared DTB REST rate limit and normalize a rejection into the
 * response shape expected by the existing authentication handlers.
 *
 * @param WP_REST_Request $request Current REST request.
 * @param string          $bucket  Stable limiter bucket.
 * @return WP_REST_Response|null A 429 response when limited, otherwise null.
 */
function dtb_rate_limit( WP_REST_Request $request, string $bucket ): ?WP_REST_Response {
	$defaults = [
		'auth_login'           => [ 'limit' => 10, 'window' => 5 * MINUTE_IN_SECONDS ],
		'auth_register'        => [ 'limit' => 5, 'window' => 10 * MINUTE_IN_SECONDS ],
		'auth_forgot_password' => [ 'limit' => 5, 'window' => 15 * MINUTE_IN_SECONDS ],
		'auth_reset_password'  => [ 'limit' => 10, 'window' => 15 * MINUTE_IN_SECONDS ],
	];

	$settings = $defaults[ $bucket ] ?? [ 'limit' => 10, 'window' => 5 * MINUTE_IN_SECONDS ];
	$settings = apply_filters( 'dtb_rate_limit_settings', $settings, $bucket, $request );

	$result = DTB_RateLimiter::check(
		sanitize_key( $bucket ),
		(int) ( $settings['limit'] ?? 10 ),
		(int) ( $settings['window'] ?? 5 * MINUTE_IN_SECONDS )
	);

	if ( true === $result ) {
		return null;
	}

	$data        = $result->get_error_data();
	$status      = is_array( $data ) ? (int) ( $data['status'] ?? 429 ) : 429;
	$retry_after = is_array( $data ) ? (int) ( $data['retry_after'] ?? 0 ) : 0;

	if ( function_exists( 'dtb_security_log' ) ) {
		dtb_security_log(
			'rate_limited',
			[
				'bucket' => sanitize_key( $bucket ),
				'route'  => $request->get_route(),
			]
		);
	}

	$response = new WP_REST_Response(
		function_exists( 'dtb_error_envelope' )
			? dtb_error_envelope( 'rate_limited', 'Too many requests. Please try again later.', $status )
			: [ 'code' => 'rate_limited', 'message' => 'Too many requests. Please try again later.' ],
		$status
	);

	if ( $retry_after > 0 ) {
		$response->header( 'Retry-After', (string) $retry_after );
	}
	$response->header( 'Cache-Control', 'private, no-store' );

	return $response;
}

