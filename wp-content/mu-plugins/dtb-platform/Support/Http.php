<?php
/**
 * Http — DTB Platform
 *
 * HTTP request-type detection helpers and IP utilities.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// ─── Request detection ────────────────────────────────────────────────────────

/**
 * Return true when the current request is a WordPress REST API request.
 *
 * MU plugins load before WordPress always defines REST_REQUEST, so this also
 * checks the two canonical REST entry shapes early: /wp-json/... permalinks
 * and ?rest_route=/... fallback URLs.
 *
 * @return bool
 */
function dtb_is_rest_api_request(): bool {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return true;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	return false !== strpos( $request_uri, '/wp-json/' );
}

/**
 * Return true when the current request is an admin or AJAX request.
 */
function dtb_is_admin_or_ajax_request(): bool {
	if ( function_exists( 'is_admin' ) && is_admin() ) {
		return true;
	}

	if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) {
		return true;
	}

	return false;
}

/**
 * Return true when the current request is admin, AJAX, or REST API.
 */
function dtb_is_admin_or_rest_request(): bool {
	return dtb_is_admin_or_ajax_request() || dtb_is_rest_api_request();
}

// ─── IP helpers ───────────────────────────────────────────────────────────────

/**
 * Determine the real client IP, accounting for Cloudflare and common proxies.
 *
 * Header priority (first valid IP wins):
 *   1. HTTP_CF_CONNECTING_IP  — Cloudflare
 *   2. HTTP_X_FORWARDED_FOR   — load balancers / reverse proxies (first value)
 *   3. HTTP_X_REAL_IP         — Nginx-style single-IP proxy header
 *   4. REMOTE_ADDR            — direct TCP connection (final fallback)
 *
 * @return string A validated IP address string, or '0.0.0.0' if none found.
 */
function dtb_get_client_ip(): string {
	$candidates = [
		'HTTP_CF_CONNECTING_IP',
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'REMOTE_ADDR',
	];

	foreach ( $candidates as $key ) {
		if ( empty( $_SERVER[ $key ] ) ) {
			continue;
		}

		// X-Forwarded-For may carry a comma-separated list; take the first entry.
		$raw = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		$ip  = trim( explode( ',', $raw )[0] );

		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return '0.0.0.0';
}

/**
 * Anonymise an IP address before persisting it (GDPR-friendlier storage).
 *
 *   IPv4 — zeroes the last octet:       203.0.113.42  → 203.0.113.0
 *   IPv6 — keeps the first 48 bits, zeroes the remaining 80 bits.
 *
 * @param string $ip Raw IP address string.
 * @return string    Anonymised IP, or a safe placeholder on parse failure.
 */
function dtb_anonymise_ip( string $ip ): string {
	if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$bin = inet_pton( $ip );
		if ( false === $bin ) {
			return '::';
		}
		$bin = substr( $bin, 0, 6 ) . str_repeat( "\x00", 10 );
		return inet_ntop( $bin ) ?: '::';
	}

	$parts = explode( '.', $ip );
	if ( 4 === count( $parts ) ) {
		$parts[3] = '0';
		return implode( '.', $parts );
	}

	return '0.0.0.0';
}
