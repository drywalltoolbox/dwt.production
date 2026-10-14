<?php
/**
 * Coming Soon — referral capture for waitlist sign-ups.
 *
 * Adds a customer-facing "How did you hear about us?" value to subscriber
 * records and admin notification emails without replacing the existing
 * subscription workflow.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_COMING_SOON_REFERRAL_CONTEXT_KEY = 'dtb_coming_soon_referral_context';

/**
 * Return supported referral source labels.
 *
 * @return array<string,string>
 */
function dtb_coming_soon_referral_source_labels(): array {
	return [
		'google'       => 'Google',
		'social_media' => 'Social Media',
		'friend'       => 'Friend',
		'other'        => 'Other',
	];
}

/**
 * Normalize a referral source slug.
 *
 * @param mixed $value Raw source value.
 * @return string
 */
function dtb_coming_soon_normalize_referral_source( mixed $value ): string {
	$source = sanitize_key( (string) $value );

	$legacy_map = [
		'google_search'       => 'google',
		'contractor_referral' => 'friend',
	];

	if ( isset( $legacy_map[ $source ] ) ) {
		$source = $legacy_map[ $source ];
	}

	$labels = dtb_coming_soon_referral_source_labels();

	return isset( $labels[ $source ] ) ? $source : '';
}

/**
 * Store request-level referral context for downstream save/email hooks.
 *
 * @param array<string,string> $context Referral context.
 * @return void
 */
function dtb_coming_soon_set_referral_context( array $context ): void {
	$GLOBALS[ DTB_COMING_SOON_REFERRAL_CONTEXT_KEY ] = $context;
}

/**
 * Return request-level referral context.
 *
 * @return array<string,string>
 */
function dtb_coming_soon_get_referral_context(): array {
	$context = $GLOBALS[ DTB_COMING_SOON_REFERRAL_CONTEXT_KEY ] ?? [];
	return is_array( $context ) ? $context : [];
}

/**
 * Build sanitized referral context from request data.
 *
 * @param array<string,mixed> $data Raw request data.
 * @return array<string,string>
 */
function dtb_coming_soon_build_referral_context( array $data ): array {
	$email  = isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : '';
	$email  = '' !== $email ? $email : ( isset( $data['dtb_email'] ) ? sanitize_email( (string) $data['dtb_email'] ) : '' );
	$name   = sanitize_text_field( (string) ( $data['dtb_name'] ?? $data['name'] ?? '' ) );
	$name   = trim( preg_replace( '/\s+/', ' ', $name ) );
	$source = dtb_coming_soon_normalize_referral_source( $data['dtb_referral_source'] ?? $data['referral_source'] ?? '' );
	$labels = dtb_coming_soon_referral_source_labels();

	$detail_required_sources = [ 'social_media', 'friend', 'other' ];
	$detail                  = sanitize_textarea_field( (string) ( $data['dtb_referral_detail'] ?? $data['referral_detail'] ?? '' ) );
	$detail                  = trim( preg_replace( '/\s+/', ' ', $detail ) );

	if ( function_exists( 'mb_substr' ) ) {
		$detail = mb_substr( $detail, 0, 180 );
	} else {
		$detail = substr( $detail, 0, 180 );
	}

	if ( ! in_array( $source, $detail_required_sources, true ) ) {
		$detail = '';
	}

	$page_referrer = esc_url_raw( (string) ( $data['dtb_page_referrer'] ?? $data['page_referrer'] ?? '' ) );
	$landing_url   = esc_url_raw( (string) ( $data['dtb_landing_url'] ?? $data['landing_url'] ?? '' ) );

	return [
		'email'          => $email,
		'name'           => $name,
		'source'         => $source,
		'label'          => '' !== $source ? $labels[ $source ] : 'Not provided',
		'detail'         => $detail,
		'page_referrer'  => $page_referrer,
		'landing_url'    => $landing_url,
	];
}

/**
 * Capture referral context before the existing REST subscribe callback runs.
 *
 * @param mixed           $response Current pre-callback response.
 * @param array<callable> $handler  Route handler.
 * @param WP_REST_Request $request  REST request.
 * @return mixed
 */
function dtb_coming_soon_capture_rest_referral( mixed $response, array $handler, WP_REST_Request $request ): mixed {
	if ( '/dtb/v1/subscribe' !== $request->get_route() || 'POST' !== $request->get_method() ) {
		return $response;
	}

	$params = $request->get_json_params();
	if ( ! is_array( $params ) ) {
		$params = $request->get_body_params();
	}

	dtb_coming_soon_set_referral_context( dtb_coming_soon_build_referral_context( is_array( $params ) ? $params : [] ) );

	return $response;
}
add_filter( 'rest_request_before_callbacks', 'dtb_coming_soon_capture_rest_referral', 5, 3 );

/**
 * Capture referral context before the existing admin-post fallback handler runs.
 *
 * @return void
 */
function dtb_coming_soon_capture_admin_post_referral(): void {
	$data = array_map(
		static function ( mixed $value ): mixed {
			return is_scalar( $value ) ? wp_unslash( $value ) : $value;
		},
		$_POST // phpcs:ignore WordPress.Security.NonceVerification.Missing
	);

	dtb_coming_soon_set_referral_context( dtb_coming_soon_build_referral_context( $data ) );
}
add_action( 'admin_post_dtb_subscribe', 'dtb_coming_soon_capture_admin_post_referral', 1 );
add_action( 'admin_post_nopriv_dtb_subscribe', 'dtb_coming_soon_capture_admin_post_referral', 1 );

/**
 * Add referral metadata to the subscriber record being created.
 *
 * @param mixed  $new_value New option value.
 * @param mixed  $old_value Old option value.
 * @param string $option    Option name.
 * @return mixed
 */
function dtb_coming_soon_attach_referral_to_subscriber( mixed $new_value, mixed $old_value, string $option ): mixed {
	if ( ! defined( 'DTB_SUBSCRIBERS_OPTION' ) || DTB_SUBSCRIBERS_OPTION !== $option || ! is_array( $new_value ) ) {
		return $new_value;
	}

	$context = dtb_coming_soon_get_referral_context();
	$email   = strtolower( (string) ( $context['email'] ?? '' ) );

	if ( '' === $email ) {
		return $new_value;
	}

	for ( $index = count( $new_value ) - 1; $index >= 0; $index-- ) {
		$row_email = strtolower( (string) ( $new_value[ $index ]['email'] ?? '' ) );
		if ( $row_email !== $email ) {
			continue;
		}

		$new_value[ $index ]['referral_source'] = sanitize_key( (string) ( $context['source'] ?? '' ) );
		$new_value[ $index ]['referral_label']  = sanitize_text_field( (string) ( $context['label'] ?? 'Not provided' ) );
		$new_value[ $index ]['referral_detail'] = sanitize_text_field( (string) ( $context['detail'] ?? '' ) );
		$new_value[ $index ]['name']            = sanitize_text_field( (string) ( $context['name'] ?? '' ) );
		$new_value[ $index ]['page_referrer']   = esc_url_raw( (string) ( $context['page_referrer'] ?? '' ) );
		$new_value[ $index ]['landing_url']     = esc_url_raw( (string) ( $context['landing_url'] ?? '' ) );
		break;
	}

	return $new_value;
}
add_filter( 'pre_update_option_dtb_email_subscribers', 'dtb_coming_soon_attach_referral_to_subscriber', 10, 3 );

/** Referral details are now rendered from request-scoped data directly by dtb_notify_admin(). */
