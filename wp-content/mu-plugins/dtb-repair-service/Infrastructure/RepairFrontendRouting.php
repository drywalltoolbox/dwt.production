<?php
/**
 * Infrastructure — RepairFrontendRouting: customer-facing repair URLs.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_repair_normalize_frontend_base_url' ) ) {
	/**
	 * Normalize a customer-facing frontend base URL.
	 *
	 * The frontend base is the SPA root, not the repair status route.
	 * Accepted examples:
	 * - https://drywalltoolbox.com
	 *
	 * @param string $raw Raw URL or path.
	 * @return string Normalized absolute base URL, without trailing slash.
	 */
	function dtb_repair_normalize_frontend_base_url( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$home_parts = wp_parse_url( home_url( '/' ) );
		if ( ! is_array( $home_parts ) ) {
			return '';
		}

		$home_scheme = (string) ( $home_parts['scheme'] ?? 'https' );
		$home_host   = strtolower( (string) ( $home_parts['host'] ?? '' ) );
		if ( '' === $home_host ) {
			return '';
		}

		if ( 0 === strpos( $raw, '/' ) ) {
			$raw = $home_scheme . '://' . $home_host . $raw;
		}

		$parts = wp_parse_url( esc_url_raw( $raw ) );
		if ( ! is_array( $parts ) ) {
			return '';
		}

		$scheme = (string) ( $parts['scheme'] ?? $home_scheme );
		$host   = strtolower( (string) ( $parts['host'] ?? $home_host ) );

		if ( '' === $host || $host !== $home_host || ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			return '';
		}

		return rtrim( esc_url_raw( $scheme . '://' . $host ), '/' );
	}
}

if ( ! function_exists( 'dtb_repair_frontend_base_from_request' ) ) {
	/**
	 * Resolve the frontend SPA base from request headers.
	 *
	 * @return string
	 */
	function dtb_repair_frontend_base_from_request(): string {
		$referer = wp_get_referer();
		if ( $referer ) {
			$from_referer = dtb_repair_normalize_frontend_base_url( $referer );
			if ( '' !== $from_referer ) {
				return $from_referer;
			}
		}

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		return dtb_repair_normalize_frontend_base_url( $origin );
	}
}

if ( ! function_exists( 'dtb_repair_configured_frontend_base_url' ) ) {
	/**
	 * Resolve configured frontend base.
	 *
	 * Constants/env/options may declare the canonical same-origin frontend
	 * without changing repair workflow code.
	 *
	 * @return string
	 */
	function dtb_repair_configured_frontend_base_url(): string {
		$candidates = [];

		if ( defined( 'DTB_REPAIR_FRONTEND_BASE_URL' ) ) {
			$candidates[] = (string) constant( 'DTB_REPAIR_FRONTEND_BASE_URL' );
		}

		$env = getenv( 'DTB_REPAIR_FRONTEND_BASE_URL' );
		if ( is_string( $env ) && '' !== $env ) {
			$candidates[] = $env;
		}

		$option = get_option( 'dtb_repair_frontend_base_url', '' );
		if ( is_string( $option ) && '' !== $option ) {
			$candidates[] = $option;
		}

		foreach ( $candidates as $candidate ) {
			$normalized = dtb_repair_normalize_frontend_base_url( $candidate );
			if ( '' !== $normalized ) {
				return $normalized;
			}
		}

		return '';
	}
}

if ( ! function_exists( 'dtb_repair_resolve_frontend_base_url' ) ) {
	/**
	 * Resolve the best available customer-facing frontend base URL.
	 *
	 * @param int   $repair_id Optional repair post ID.
	 * @param array $data      Optional submission payload.
	 * @return string Absolute base URL without trailing slash.
	 */
	function dtb_repair_resolve_frontend_base_url( int $repair_id = 0, array $data = [] ): string {
		$payload_base = '';
		foreach ( [ 'frontend_base_url', 'frontendBaseUrl', 'return_base_url', 'returnBaseUrl' ] as $key ) {
			if ( isset( $data[ $key ] ) && is_scalar( $data[ $key ] ) ) {
				$payload_base = dtb_repair_normalize_frontend_base_url( (string) $data[ $key ] );
				if ( '' !== $payload_base ) {
					return $payload_base;
				}
			}
		}

		if ( $repair_id > 0 ) {
			$stored_base = dtb_repair_normalize_frontend_base_url( (string) get_post_meta( $repair_id, '_repair_frontend_base_url', true ) );
			if ( '' !== $stored_base ) {
				return $stored_base;
			}
		}

		$request_base = dtb_repair_frontend_base_from_request();
		if ( '' !== $request_base ) {
			return $request_base;
		}

		$configured_base = dtb_repair_configured_frontend_base_url();
		if ( '' !== $configured_base ) {
			return $configured_base;
		}

		return rtrim( home_url( '/' ), '/' );
	}
}

if ( ! function_exists( 'dtb_repair_tracking_url' ) ) {
	/**
	 * Build a customer-facing repair status URL.
	 *
	 * @param int    $repair_id Repair post ID.
	 * @param string $public_token Public access token.
	 * @return string
	 */
	function dtb_repair_tracking_url( int $repair_id, string $public_token = '' ): string {
		$url = rtrim( dtb_repair_resolve_frontend_base_url( $repair_id ), '/' ) . '/repairs/status/' . absint( $repair_id );
		if ( '' !== $public_token ) {
			$url = add_query_arg( [ 'token' => $public_token ], $url );
		}

		return esc_url_raw( $url );
	}
}

add_filter(
	'dtb_repair_tracking_base_url',
	static function (): string {
		return rtrim( dtb_repair_resolve_frontend_base_url(), '/' ) . '/repairs/status/';
	},
	20
);
