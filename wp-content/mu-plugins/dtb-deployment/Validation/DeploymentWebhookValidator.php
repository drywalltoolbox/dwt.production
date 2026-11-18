<?php
/**
 * Validation — DeploymentWebhookValidator
 *
 * HMAC-SHA256 signature verification for inbound release lifecycle events
 * from GitHub Actions (scripts/deployment/report-release-event.sh). Mirrors
 * the verification pattern already used for the QuickBooks webhook
 * (DTB_QuickBooksWebhookController), with GitHub Actions as signer and
 * WordPress as verifier here.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * The shared HMAC secret, from wp-config.php only — never stored as a
 * WordPress option, since this secret authorizes writes to production
 * release history.
 */
function dtb_deployment_webhook_secret(): string {
	return defined( 'DTB_DEPLOYMENT_WEBHOOK_SECRET' ) ? trim( (string) DTB_DEPLOYMENT_WEBHOOK_SECRET ) : '';
}

function dtb_deployment_webhook_configured(): bool {
	return '' !== dtb_deployment_webhook_secret();
}

/**
 * Verify the `X-DTB-Signature: sha256=<hex>` header against the raw request
 * body using a timing-safe comparison.
 */
function dtb_deployment_webhook_verify_signature( string $body, string $header_value ): bool {
	$secret = dtb_deployment_webhook_secret();
	if ( '' === $secret || '' === $body ) {
		return false;
	}

	$header_value = trim( $header_value );
	if ( ! str_starts_with( $header_value, 'sha256=' ) ) {
		return false;
	}

	$provided = substr( $header_value, 7 );
	$expected = hash_hmac( 'sha256', $body, $secret );

	return '' !== $provided && hash_equals( $expected, $provided );
}
