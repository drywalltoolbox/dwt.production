<?php
/**
 * DTB auth cookie request and response canonicalization.
 *
 * AuthRoutes.php remains the owner of JWT generation, verification, route
 * callbacks, and the canonical cookie contract. This bounded compatibility
 * layer protects that contract while legacy host/domain cookie variants may
 * coexist in a browser:
 *
 * - normalize duplicate request cookies to the newest cryptographically valid
 *   JWT before WordPress current-user resolution or REST callbacks execute;
 * - collapse repeated dtb_auth response headers to one host-only cookie;
 * - preserve every non-DTB Set-Cookie header verbatim;
 * - expose only redacted candidate counts in request-local diagnostics.
 *
 * @package drywalltoolbox
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'rest_post_dispatch', 'dtb_auth_canonicalize_response_cookie_headers', 100, 3 );

/**
 * Select the newest valid DTB JWT from every raw request-cookie candidate.
 *
 * PHP collapses duplicate cookie names in $_COOKIE. Browsers can nevertheless
 * send both host-only and domain-scoped values, so the raw Cookie header is the
 * authoritative candidate source. Invalid values are ignored; Bearer fallback
 * remains available when no valid cookie exists.
 */
function dtb_auth_normalize_request_cookie(): void {
	if ( ! defined( 'DTB_AUTH_COOKIE' ) || ! function_exists( 'dtb_auth_request_cookie_candidates' ) || ! function_exists( 'dtb_verify_jwt' ) ) {
		return;
	}

	$candidates = dtb_auth_request_cookie_candidates( DTB_AUTH_COOKIE );
	$valid      = [];

	foreach ( $candidates as $position => $candidate ) {
		$payload = dtb_verify_jwt( $candidate );
		if ( is_wp_error( $payload ) || ! is_object( $payload ) || empty( $payload->sub ) ) {
			continue;
		}

		$valid[] = [
			'token'    => $candidate,
			'iat'      => isset( $payload->iat ) ? (int) $payload->iat : 0,
			'position' => (int) $position,
		];
	}

	usort(
		$valid,
		static function ( array $left, array $right ): int {
			if ( $left['iat'] === $right['iat'] ) {
				return $left['position'] <=> $right['position'];
			}

			return $right['iat'] <=> $left['iat'];
		}
	);

	if ( ! empty( $valid ) ) {
		$_COOKIE[ DTB_AUTH_COOKIE ] = $valid[0]['token'];
	} else {
		unset( $_COOKIE[ DTB_AUTH_COOKIE ] );
	}

	if ( isset( $GLOBALS['dtb_auth_session_handoff'] ) && is_array( $GLOBALS['dtb_auth_session_handoff'] ) ) {
		$GLOBALS['dtb_auth_session_handoff']['cookie_candidate_count']       = count( $candidates );
		$GLOBALS['dtb_auth_session_handoff']['valid_cookie_candidate_count'] = count( $valid );
		$GLOBALS['dtb_auth_session_handoff']['duplicate_cookie_detected']     = count( $candidates ) > 1;
		$GLOBALS['dtb_auth_session_handoff']['cookie_candidate_selected']     = ! empty( $valid );
	}
}

/**
 * Collapse all outgoing DTB auth cookie variants to one canonical host-only
 * header while retaining unrelated WordPress, WooCommerce, and integration
 * cookies exactly as emitted.
 *
 * @param mixed           $response REST response.
 * @param WP_REST_Server  $server   REST server.
 * @param WP_REST_Request $request  REST request.
 * @return mixed
 */
function dtb_auth_canonicalize_response_cookie_headers( $response, $server, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
	if ( ! $request instanceof WP_REST_Request || 0 !== strpos( (string) $request->get_route(), '/dtb/v1/auth/' ) ) {
		return $response;
	}

	if ( headers_sent() || ! defined( 'DTB_AUTH_COOKIE' ) ) {
		return $response;
	}

	$headers       = headers_list();
	$preserved     = [];
	$selected_dtb  = '';
	$cookie_prefix = strtolower( DTB_AUTH_COOKIE ) . '=';

	foreach ( $headers as $header_line ) {
		if ( 0 !== stripos( $header_line, 'Set-Cookie:' ) ) {
			continue;
		}

		$cookie_value = ltrim( substr( $header_line, strlen( 'Set-Cookie:' ) ) );
		if ( 0 === strpos( strtolower( $cookie_value ), $cookie_prefix ) ) {
			// Last DTB mutation wins. This preserves fail-closed clears emitted by
			// the native-session conflict guard after an earlier login cookie.
			$selected_dtb = $cookie_value;
			continue;
		}

		$preserved[] = $cookie_value;
	}

	if ( '' === $selected_dtb ) {
		return $response;
	}

	header_remove( 'Set-Cookie' );
	foreach ( $preserved as $cookie_value ) {
		header( 'Set-Cookie: ' . $cookie_value, false );
	}

	$canonical = dtb_auth_build_canonical_cookie_header( $selected_dtb );
	if ( '' !== $canonical ) {
		header( 'Set-Cookie: ' . $canonical, false );
	}

	return $response;
}

/**
 * Convert an emitted DTB cookie header into the canonical host-only contract.
 */
function dtb_auth_build_canonical_cookie_header( string $source ): string {
	$segments = array_map( 'trim', explode( ';', $source ) );
	$pair     = array_shift( $segments );

	if ( ! is_string( $pair ) || false === strpos( $pair, '=' ) ) {
		return '';
	}

	[ $name, $value ] = array_map( 'trim', explode( '=', $pair, 2 ) );
	if ( ! hash_equals( DTB_AUTH_COOKIE, $name ) ) {
		return '';
	}

	$expires = '';
	$max_age = '';
	foreach ( $segments as $segment ) {
		if ( 0 === stripos( $segment, 'Expires=' ) ) {
			$expires = substr( $segment, strlen( 'Expires=' ) );
		} elseif ( 0 === stripos( $segment, 'Max-Age=' ) ) {
			$max_age = (string) (int) substr( $segment, strlen( 'Max-Age=' ) );
		}
	}

	$is_clear  = '' === rawurldecode( $value ) || ( '' !== $max_age && (int) $max_age <= 0 );
	$same_site = function_exists( 'dtb_is_cross_origin_request' ) && dtb_is_cross_origin_request() ? 'None' : 'Lax';
	$parts     = [
		DTB_AUTH_COOKIE . '=' . $value,
	];

	if ( $is_clear ) {
		$parts[] = 'Expires=' . ( '' !== $expires ? $expires : gmdate( 'D, d M Y H:i:s', time() - DAY_IN_SECONDS ) . ' GMT' );
		$parts[] = 'Max-Age=0';
	} else {
		if ( '' !== $expires ) {
			$parts[] = 'Expires=' . $expires;
		}
		if ( '' !== $max_age ) {
			$parts[] = 'Max-Age=' . max( 0, (int) $max_age );
		}
	}

	$parts[] = 'Path=/';
	$parts[] = 'Secure';
	$parts[] = 'HttpOnly';
	$parts[] = 'SameSite=' . $same_site;

	return implode( '; ', $parts );
}

// Execute during MU-plugin bootstrap, before determine_current_user and REST
// callbacks consume the collapsed PHP cookie array.
dtb_auth_normalize_request_cookie();
