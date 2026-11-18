<?php
/**
 * Services — RepairIdempotencyService: idempotency key lookup and client IP detection.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Find an existing repair post by idempotency key.
 *
 * @param string $idempotency_key
 * @return int|null Post ID or null if not found.
 */
function dtb_repair_find_by_idempotency_key( string $idempotency_key ): ?int {
if ( '' === $idempotency_key ) {
return null;
}

$posts = get_posts(
[
'post_type'      => 'dtb_repair_request',
'posts_per_page' => 1,
'post_status'    => 'publish',
'meta_query'     => [
[
'key'     => '_repair_idempotency_key',
'value'   => $idempotency_key,
'compare' => '=',
],
],
'fields'         => 'ids',
]
);

return ! empty( $posts ) ? (int) $posts[0] : null;
}

/**
 * Return the client IP address (supports common proxy headers).
 *
 * @return string
 */
function dtb_repair_get_client_ip(): string {
$candidates = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ];
foreach ( $candidates as $key ) {
if ( ! empty( $_SERVER[ $key ] ) ) {
$ip    = sanitize_text_field( wp_unslash( (string) $_SERVER[ $key ] ) );
$parts = explode( ',', $ip );
$ip    = trim( $parts[0] ?? '' );
if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
return $ip;
}
}
}
return '0.0.0.0';
}
