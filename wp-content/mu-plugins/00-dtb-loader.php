<?php
/**
 * DTB MU-Plugins Bootstrap
 *
 * This file is intentionally named with a "00-" prefix so it sorts first
 * alphabetically and is loaded before all other mu-plugin files.
 *
 * Defines shared utility functions used across the mu-plugin suite so that
 * the allowed-origins list and origin-check logic live in exactly one place.
 *
 * Also controls the explicit load order of all DTB mu-plugins via require_once.
 * WordPress would otherwise load each file alphabetically; the require_once
 * calls here ensure dependency order is respected.  PHP's require_once
 * semantics prevent double-inclusion when WordPress later tries to load the
 * same files.
 *
 * Load order (module composition root):
 *   1. dtb-platform/bootstrap.php
 *   2. dtb-catalog-platform/bootstrap.php
 *   3. dtb-commerce/bootstrap.php
 *   4. dtb-order-platform/bootstrap.php
 *   5. dtb-schematics/bootstrap.php
 *   6. dtb-media/bootstrap.php
 *   7. dtb-marketing/bootstrap.php
 *   8. dtb-repair-service/bootstrap.php
 *   9. dtb-integrations/bootstrap.php
 *  10. dtb-support/bootstrap.php
 *  11. dtb-returns/bootstrap.php
 *  12. dtb-deployment/bootstrap.php
 *  13. dtb-visual-designer/bootstrap.php
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Start a small safety output buffer early in MU bootstrap.
 *
 * Purpose:
 * - Prevent "headers already sent" cascades if any included file accidentally
 *   emits a UTF-8 BOM or stray whitespace before header() calls.
 * - Strip a single leading UTF-8 BOM from the first buffered chunk.
 *
 * Scope is limited to admin / ajax / REST style requests where DTB emits
 * security and CORS headers.
 */
function dtb_bootstrap_start_output_buffer(): void {
	if ( headers_sent() ) {
		return;
	}

	$request_uri = isset( $_SERVER['REQUEST_URI'] )
		? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	$is_header_sensitive_request = str_contains( $request_uri, '/wp-admin/' )
		|| str_contains( $request_uri, '/wp-json/' )
		|| str_contains( $request_uri, 'admin-ajax.php' );

	if ( ! $is_header_sensitive_request ) {
		return;
	}

	ob_start(
		static function ( string $buffer ): string {
			static $first_chunk = true;

			if ( $first_chunk ) {
				$first_chunk = false;
				if ( str_starts_with( $buffer, "\xEF\xBB\xBF" ) ) {
					$buffer = substr( $buffer, 3 );
				}
			}

			return $buffer;
		}
	);
}

dtb_bootstrap_start_output_buffer();

/**
 * Central feature-flag helper for production-safe hardening rollouts.
 *
 * Define a constant as true/false to override, or filter
 * dtb_feature_enabled_{CONSTANT_NAME} from plugins/tests.
 */
function dtb_feature_enabled( string $constant_name, bool $default = true ): bool {
	if ( defined( $constant_name ) ) {
		return filter_var( constant( $constant_name ), FILTER_VALIDATE_BOOLEAN );
	}

	return (bool) apply_filters( 'dtb_feature_enabled_' . $constant_name, $default );
}

/**
 * Structured, redacted security logging for denied origins/permissions.
 */
function dtb_security_log( string $event, array $context = [] ): void {
	if ( ! dtb_feature_enabled( 'DTB_SECURITY_LOGGING', true ) ) {
		return;
	}

	$safe_context = [];

	foreach ( $context as $key => $value ) {
		if ( is_scalar( $value ) || null === $value ) {
			$safe_context[ sanitize_key( (string) $key ) ] = is_bool( $value )
				? $value
				: sanitize_text_field( (string) $value );
		}
	}

	$safe_context += [
		'user_id' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
		'method'  => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
		'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
		'origin'  => isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '',
		'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
	];

	error_log(
		wp_json_encode(
			[
				'source'  => 'dtb-security',
				'event'   => sanitize_key( $event ),
				'context' => $safe_context,
			],
			JSON_UNESCAPED_SLASHES
		)
	);
}

/**
 * Return the list of CORS-allowed origins for the Drywall Toolbox SPA.
 *
 * The production domain and local-dev origins are always included.
 * An additional origin can be declared in wp-config.php:
 *
 *   define( 'DRYWALL_ALLOWED_ORIGIN', 'https://preview.example.com' );
 *
 * Third-party code may extend the list via the dtb_allowed_origins filter.
 *
 * @return string[] List of allowed origin strings (scheme + host, no trailing slash).
 */
function dtb_normalize_origin( string $url ): string {
	$parts  = wp_parse_url( trim( $url ) );
	$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
	$host   = strtolower( (string) ( $parts['host'] ?? '' ) );

	if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
		return '';
	}

	$origin = $scheme . '://' . $host;
	if ( isset( $parts['port'] ) ) {
		$origin .= ':' . (int) $parts['port'];
	}

	return $origin;
}

function dtb_allowed_origins(): array {
	$origins = [
		'https://drywalltoolbox.com',
		'https://elliotttmiller.github.io', // GitHub Pages dev/preview build
		'http://localhost:3000',
		'http://localhost:5173',
		'http://127.0.0.1:3000',
		'http://127.0.0.1:5173',
	];

	// Dynamically include the WordPress home and site URLs so that wp-admin
	// requests are never rejected by the origin guard, regardless of how the
	// site URL is configured in wp-config.php or the WordPress options table.
	// array_unique() in the return handles any overlap between the two values.
	foreach ( [ 'home_url', 'site_url' ] as $fn ) {
		if ( function_exists( $fn ) ) {
			$url = dtb_normalize_origin( (string) $fn() );
			if ( $url ) {
				$origins[] = $url;
			}
		}
	}

	if ( defined( 'DRYWALL_ALLOWED_ORIGIN' ) && DRYWALL_ALLOWED_ORIGIN ) {
		$origins[] = dtb_normalize_origin( (string) DRYWALL_ALLOWED_ORIGIN );
	}

	$origins = array_values( array_filter( array_map( 'dtb_normalize_origin', $origins ) ) );

	/** @var string[] $origins */
	$origins = (array) apply_filters( 'dtb_allowed_origins', array_unique( $origins ) );

	return array_values( array_unique( array_filter( array_map( 'dtb_normalize_origin', $origins ) ) ) );
}

/**
 * Check whether the current request's Origin header is in the allowlist.
 *
 * Requests without an Origin header (direct / server-to-server) are allowed by
 * default — the guard is only enforced when a browser presents an origin that is
 * not on the list.
 *
 * @return bool True when the origin is allowlisted or absent; false otherwise.
 */
function dtb_check_origin(): bool {
	$raw_origin = isset( $_SERVER['HTTP_ORIGIN'] )
		? (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		: '';

	if ( '' === $raw_origin ) {
		return true; // no Origin → not a cross-origin browser request
	}

	$normalized_origin = dtb_normalize_origin( $raw_origin );
	$allowed           = '' !== $normalized_origin && in_array( $normalized_origin, dtb_allowed_origins(), true );

	if ( ! $allowed ) {
		dtb_security_log( 'origin_denied', [ 'denied_origin' => $raw_origin ] );
	}

	return $allowed;
}

// ─── Explicit load order ──────────────────────────────────────────────────────

$_dtb_dir = __DIR__;

/**
 * Safely require a mu-plugin file.
 *
 * Uses require_once when the file exists. When it is missing (e.g. a file has
 * not yet been deployed to this server) the error is written to the PHP error
 * log and an admin-visible notice is queued — but WordPress continues to boot
 * so that wp-admin remains accessible and the missing file can be uploaded.
 *
 * @param string $path Absolute path to the file.
 */
function _dtb_require( string $path ): void {
	if ( file_exists( $path ) ) {
		require_once $path;
		return;
	}

	$filename = basename( $path );
	error_log( "[DTB] mu-plugin not found — file has not been deployed to this server: {$path}" );

	// Queue an admin notice so the missing file is visible in wp-admin.
	add_action( 'admin_notices', static function () use ( $filename ): void {
		echo '<div class="notice notice-error"><p>'
			. '<strong>Drywall Toolbox:</strong> mu-plugin file <code>'
			. esc_html( $filename )
			. '</code> is missing from the server. Restore it from the reviewed production artifact.</p></div>';
	} );
}

/**
 * Require a file by mu-plugin-root-relative path.
 *
 * @param string $relative Relative path from wp-content/mu-plugins.
 */
function dtb_module_require( string $relative ): void {
	$relative = ltrim( $relative, '/' );
	$path     = __DIR__ . '/' . $relative;

	if ( function_exists( '_dtb_require' ) ) {
		_dtb_require( $path );
		return;
	}

	if ( file_exists( $path ) ) {
		require_once $path;
		return;
	}

	error_log( "[DTB] mu-plugin module file not found: {$path}" );
}

_dtb_require( $_dtb_dir . '/dtb-platform/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-catalog-platform/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-commerce/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-order-platform/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-schematics/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-media/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-marketing/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-repair-service/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-integrations/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-support/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-returns/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-deployment/bootstrap.php' );
_dtb_require( $_dtb_dir . '/dtb-visual-designer/bootstrap.php' );

// Order Operations Dashboard — migrated to dtb-platform module (Observability/).
// _dtb_require( $_dtb_dir . '/dtb-order-operations-read-models.php' );
// _dtb_require( $_dtb_dir . '/dtb-order-operations-actions.php' );
// _dtb_require( $_dtb_dir . '/dtb-order-operations-ajax.php' );
// _dtb_require( $_dtb_dir . '/dtb-order-operations-dashboard.php' );

unset( $_dtb_dir );
