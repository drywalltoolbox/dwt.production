<?php
/**
 * Sanitize — DTB Platform
 *
 * Input sanitization helpers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sanitize and clamp pagination inputs.
 *
 * @param mixed $page         Raw page input.
 * @param mixed $per_page     Raw per_page input.
 * @param int   $max_per_page Maximum allowed per_page value.
 * @return array{page: int, per_page: int}
 */
function dtb_sanitize_pagination( $page, $per_page, int $max_per_page = 100 ): array {
	return [
		'page'     => max( 1, absint( $page ) ),
		'per_page' => min( $max_per_page, max( 1, absint( $per_page ) ) ),
	];
}
