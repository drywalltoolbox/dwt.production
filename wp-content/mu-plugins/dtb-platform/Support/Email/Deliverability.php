<?php
/**
 * Transactional email deliverability safeguards.
 *
 * Keeps Reply-To aligned with the DTB sending domain so mailbox providers do
 * not treat legitimate store mail as a possible reply-address spoof. SMTP,
 * SPF, DKIM, DMARC, and envelope-sender authentication remain infrastructure
 * responsibilities and are intentionally not configured here.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Normalize outbound Reply-To to the DTB domain.
 *
 * Same-domain Reply-To values are preserved. Missing or cross-domain values
 * are replaced with the canonical platform mailbox.
 *
 * @param array<string,mixed> $args wp_mail() arguments.
 * @return array<string,mixed>
 */
function dtb_platform_normalize_mail_reply_to( array $args ): array {
	$headers = $args['headers'] ?? [];
	$headers = is_array( $headers ) ? $headers : preg_split( '/\r\n|\r|\n/', (string) $headers );
	$headers = array_values( array_filter( array_map( 'strval', (array) $headers ) ) );

	$canonical = function_exists( 'dtb_platform_from_email' ) ? dtb_platform_from_email() : 'info@drywalltoolbox.com';
	$name      = function_exists( 'dtb_platform_from_name' ) ? dtb_platform_from_name() : 'Drywall Toolbox';
	$domain    = strtolower( (string) substr( strrchr( $canonical, '@' ) ?: '', 1 ) );
	$reply_to  = '';
	$clean     = [];

	foreach ( $headers as $header ) {
		if ( 0 !== stripos( $header, 'Reply-To:' ) ) {
			$clean[] = $header;
			continue;
		}

		$value = trim( substr( $header, strlen( 'Reply-To:' ) ) );
		if ( preg_match( '/<([^>]+)>/', $value, $matches ) ) {
			$value = $matches[1];
		}

		$candidate        = sanitize_email( $value );
		$candidate_domain = strtolower( (string) substr( strrchr( $candidate, '@' ) ?: '', 1 ) );

		if ( is_email( $candidate ) && '' !== $domain && hash_equals( $domain, $candidate_domain ) ) {
			$reply_to = $candidate;
		}
	}

	if ( '' === $reply_to ) {
		$reply_to = $canonical;
	}

	$clean[]         = 'Reply-To: ' . $name . ' <' . $reply_to . '>';
	$args['headers'] = $clean;

	return $args;
}
add_filter( 'wp_mail', 'dtb_platform_normalize_mail_reply_to', 20, 1 );
