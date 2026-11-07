<?php
/**
 * Json — DTB Platform
 *
 * Uniform JSON response envelope helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build the uniform JSON error envelope used by all DTB REST endpoints.
 *
 * Shape:
 * {
 *   "success": false,
 *   "code":    "snake_case_error_code",
 *   "message": "Human-readable explanation.",
 *   "data":    { "status": 400 }
 * }
 *
 * @param string $code    Machine-readable error code (snake_case).
 * @param string $message Human-readable explanation shown to the client.
 * @param int    $status  HTTP status code to accompany the response.
 * @return array<string,mixed>
 */
function dtb_error_envelope( string $code, string $message, int $status ): array {
	return [
		'success' => false,
		'code'    => $code,
		'message' => $message,
		'data'    => [ 'status' => $status ],
	];
}
