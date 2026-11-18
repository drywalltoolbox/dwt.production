<?php
/**
 * Validation — ContactSubmitValidator: validates incoming contact-form REST payloads.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/** Maximum message body length (characters). */
const DTB_SUPPORT_MAX_BODY_LENGTH = 5000;

/** Rate-limit: max submissions per IP per hour. */
const DTB_SUPPORT_RATE_LIMIT = 5;

/**
 * Validate a contact/support form submission payload.
 *
 * @param array $data  Raw request data (from WP_REST_Request params or $_POST).
 * @return true|WP_Error
 */
function dtb_support_validate_contact_payload( array $data ): bool|WP_Error {
	$errors = new WP_Error();

	// ── Required field checks ─────────────────────────────────────────────────
	if ( empty( trim( $data['name'] ?? '' ) ) ) {
		$errors->add( 'dtb_support_missing_name', __( 'Name is required.', 'drywall-toolbox' ) );
	}

	$email = sanitize_email( $data['email'] ?? '' );
	if ( empty( $email ) || ! is_email( $email ) ) {
		$errors->add( 'dtb_support_invalid_email', __( 'A valid email address is required.', 'drywall-toolbox' ) );
	}

	if ( empty( trim( $data['subject'] ?? '' ) ) ) {
		$errors->add( 'dtb_support_missing_subject', __( 'Subject is required.', 'drywall-toolbox' ) );
	}

	$message = trim( $data['message'] ?? '' );
	if ( empty( $message ) ) {
		$errors->add( 'dtb_support_missing_message', __( 'Message is required.', 'drywall-toolbox' ) );
	} elseif ( mb_strlen( $message ) > DTB_SUPPORT_MAX_BODY_LENGTH ) {
		$errors->add(
			'dtb_support_message_too_long',
			sprintf(
				/* translators: %d: maximum character count */
				__( 'Message must not exceed %d characters.', 'drywall-toolbox' ),
				DTB_SUPPORT_MAX_BODY_LENGTH
			)
		);
	}

	// ── Honeypot check ────────────────────────────────────────────────────────
	if ( ! empty( $data['website'] ) ) {
		// Honeypot field was filled — silently pass to avoid leaking detection.
		$errors->add( 'dtb_support_spam', __( 'Submission blocked.', 'drywall-toolbox' ) );
	}

	if ( $errors->has_errors() ) {
		return $errors;
	}

	// ── Rate-limit check (transient per IP) ───────────────────────────────────
	$ip  = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
	$key = 'dtb_support_rl_' . md5( $ip );
	$hit = (int) get_transient( $key );

	if ( $hit >= DTB_SUPPORT_RATE_LIMIT ) {
		return new WP_Error(
			'dtb_support_rate_limited',
			__( 'Too many submissions. Please try again later.', 'drywall-toolbox' ),
			[ 'status' => 429 ]
		);
	}

	set_transient( $key, $hit + 1, HOUR_IN_SECONDS );

	return true;
}
