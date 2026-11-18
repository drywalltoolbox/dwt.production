<?php
/**
 * DTB auth cookie candidate parsing helpers.
 *
 * This file is intentionally side-effect free so duplicate-cookie selection can
 * be regression-tested without bootstrapping WordPress.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Extract every cookie value matching a name from a raw Cookie header.
 *
 * Browsers may send duplicate names when host-only and legacy domain-scoped
 * cookies coexist. PHP collapses those values in $_COOKIE, so authentication
 * must inspect the raw header and validate each candidate.
 *
 * @param string $cookie_header Raw Cookie request header.
 * @param string $cookie_name   Cookie name to match.
 * @return string[] Ordered, unique, non-empty decoded values.
 */
function dtb_auth_cookie_candidates_from_header( string $cookie_header, string $cookie_name ): array {
	if ( '' === trim( $cookie_header ) || '' === $cookie_name ) {
		return [];
	}

	$candidates = [];
	foreach ( explode( ';', $cookie_header ) as $pair ) {
		$separator = strpos( $pair, '=' );
		if ( false === $separator ) {
			continue;
		}

		$name = trim( substr( $pair, 0, $separator ) );
		if ( ! hash_equals( $cookie_name, $name ) ) {
			continue;
		}

		$value = rawurldecode( trim( substr( $pair, $separator + 1 ) ) );
		if ( '' === $value || in_array( $value, $candidates, true ) ) {
			continue;
		}

		$candidates[] = $value;
	}

	return $candidates;
}

/**
 * Return every request cookie candidate for the DTB auth cookie.
 *
 * @param string $cookie_name Cookie name.
 * @return string[] Ordered, unique, non-empty values.
 */
function dtb_auth_request_cookie_candidates( string $cookie_name ): array {
	$header = isset( $_SERVER['HTTP_COOKIE'] )
		? (string) wp_unslash( $_SERVER['HTTP_COOKIE'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';
	$candidates = dtb_auth_cookie_candidates_from_header( $header, $cookie_name );

	if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
		$fallback = sanitize_text_field( wp_unslash( (string) $_COOKIE[ $cookie_name ] ) );
		if ( '' !== $fallback && ! in_array( $fallback, $candidates, true ) ) {
			$candidates[] = $fallback;
		}
	}

	return $candidates;
}
