<?php
/**
 * DTB Platform — authenticated customer account settings.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_account_preferences_defaults(): array {
	return [
		'order_updates'  => true,
		'repair_updates' => true,
		'return_updates' => true,
		'marketing'      => false,
		'newsletter'     => false,
	];
}

function dtb_account_email_preference( string $email, string $preference ): bool {
	$user = get_user_by( 'email', sanitize_email( $email ) );
	if ( ! $user ) {
		return true;
	}
	$preferences = array_merge(
		dtb_account_preferences_defaults(),
		(array) get_user_meta( $user->ID, '_dtb_account_preferences', true )
	);
	return ! array_key_exists( $preference, $preferences ) || rest_sanitize_boolean( $preferences[ $preference ] );
}

function dtb_account_sync_newsletter( string $email, bool $subscribed ): void {
	if ( ! defined( 'DTB_SUBSCRIBERS_OPTION' ) ) {
		return;
	}
	$subscribers = (array) get_option( DTB_SUBSCRIBERS_OPTION, [] );
	$subscribers = array_values( array_filter(
		$subscribers,
		static fn( array $row ): bool => strtolower( (string) ( $row['email'] ?? '' ) ) !== strtolower( $email )
	) );
	if ( $subscribed ) {
		$subscribers[] = [
			'email' => $email,
			'date'  => gmdate( 'c' ),
			'ip'    => '',
		];
	}
	update_option( DTB_SUBSCRIBERS_OPTION, $subscribers, false );
}

function dtb_account_user_payload( WP_User $user ): array {
	$preferences = array_merge(
		dtb_account_preferences_defaults(),
		(array) get_user_meta( $user->ID, '_dtb_account_preferences', true )
	);

	return [
		'id'           => $user->ID,
		'email'        => $user->user_email,
		'display_name' => $user->display_name,
		'first_name'   => (string) get_user_meta( $user->ID, 'first_name', true ),
		'last_name'    => (string) get_user_meta( $user->ID, 'last_name', true ),
		'company'      => (string) get_user_meta( $user->ID, 'billing_company', true ),
		'phone'        => (string) get_user_meta( $user->ID, 'billing_phone', true ),
		'roles'        => array_values( (array) $user->roles ),
		'registered'   => $user->user_registered,
		'preferences'  => array_map( 'rest_sanitize_boolean', $preferences ),
	];
}

function dtb_account_current_user_or_error() {
	$user = DTB_CurrentUserResolver::resolve_user();
	if ( ! $user ) {
		return new WP_Error( 'dtb_account_unauthorized', 'Authentication required.', [ 'status' => 401 ] );
	}
	return $user;
}

function dtb_account_get(): WP_REST_Response {
	$user = dtb_account_current_user_or_error();
	if ( is_wp_error( $user ) ) {
		return new WP_REST_Response( [ 'code' => $user->get_error_code(), 'message' => $user->get_error_message() ], 401 );
	}

	$response = new WP_REST_Response( [ 'user' => dtb_account_user_payload( $user ) ], 200 );
	$response->header( 'Cache-Control', 'private, no-store' );
	return $response;
}

function dtb_account_update( WP_REST_Request $request ): WP_REST_Response {
	$user = dtb_account_current_user_or_error();
	if ( is_wp_error( $user ) ) {
		return new WP_REST_Response( [ 'code' => $user->get_error_code(), 'message' => $user->get_error_message() ], 401 );
	}

	$payload     = $request->get_json_params() ?: [];
	$old_email   = $user->user_email;
	$first_name = sanitize_text_field( (string) ( $payload['first_name'] ?? get_user_meta( $user->ID, 'first_name', true ) ) );
	$last_name  = sanitize_text_field( (string) ( $payload['last_name'] ?? get_user_meta( $user->ID, 'last_name', true ) ) );
	$company    = sanitize_text_field( (string) ( $payload['company'] ?? get_user_meta( $user->ID, 'billing_company', true ) ) );
	$phone      = sanitize_text_field( (string) ( $payload['phone'] ?? get_user_meta( $user->ID, 'billing_phone', true ) ) );
	$email      = sanitize_email( (string) ( $payload['email'] ?? $user->user_email ) );

	if ( ! is_email( $email ) ) {
		return new WP_REST_Response( [ 'code' => 'invalid_email', 'message' => 'Please provide a valid email address.' ], 422 );
	}

	$existing_user_id = email_exists( $email );
	if ( $existing_user_id && (int) $existing_user_id !== (int) $user->ID ) {
		return new WP_REST_Response( [ 'code' => 'email_exists', 'message' => 'That email address is already in use.' ], 409 );
	}

	$display_name = trim( $first_name . ' ' . $last_name ) ?: $email;
	$result       = wp_update_user( [
		'ID'           => $user->ID,
		'user_email'   => $email,
		'first_name'   => $first_name,
		'last_name'    => $last_name,
		'display_name' => $display_name,
	] );

	if ( is_wp_error( $result ) ) {
		return new WP_REST_Response( [ 'code' => 'account_update_failed', 'message' => $result->get_error_message() ], 500 );
	}

	update_user_meta( $user->ID, 'billing_company', $company );
	update_user_meta( $user->ID, 'billing_phone', $phone );

	if ( isset( $payload['preferences'] ) && is_array( $payload['preferences'] ) ) {
		$preferences = dtb_account_preferences_defaults();
		foreach ( array_keys( $preferences ) as $key ) {
			if ( array_key_exists( $key, $payload['preferences'] ) ) {
				$preferences[ $key ] = rest_sanitize_boolean( $payload['preferences'][ $key ] );
			}
		}
		update_user_meta( $user->ID, '_dtb_account_preferences', $preferences );
		if ( strtolower( $old_email ) !== strtolower( $email ) ) {
			dtb_account_sync_newsletter( $old_email, false );
		}
		dtb_account_sync_newsletter(
			$email,
			(bool) $preferences['newsletter'] || (bool) $preferences['marketing']
		);
	}

	if ( class_exists( 'WC_Customer' ) ) {
		try {
			$customer = new WC_Customer( $user->ID );
			$customer->set_email( $email );
			$customer->set_first_name( $first_name );
			$customer->set_last_name( $last_name );
			$customer->set_billing_email( $email );
			$customer->set_billing_first_name( $first_name );
			$customer->set_billing_last_name( $last_name );
			$customer->set_billing_company( $company );
			$customer->set_billing_phone( $phone );
			$customer->save();
		} catch ( Exception $exception ) {
			error_log( '[DTB] Account WooCommerce sync failed for user ' . $user->ID . ': ' . $exception->getMessage() );
		}
	}

	$updated = get_user_by( 'id', $user->ID );
	return new WP_REST_Response( [
		'success' => true,
		'message' => 'Account settings saved.',
		'user'    => dtb_account_user_payload( $updated ),
	], 200 );
}

function dtb_account_change_password( WP_REST_Request $request ): WP_REST_Response {
	$user = dtb_account_current_user_or_error();
	if ( is_wp_error( $user ) ) {
		return new WP_REST_Response( [ 'code' => $user->get_error_code(), 'message' => $user->get_error_message() ], 401 );
	}

	$current_password = (string) $request->get_param( 'current_password' );
	$new_password     = (string) $request->get_param( 'new_password' );

	if ( ! wp_check_password( $current_password, $user->user_pass, $user->ID ) ) {
		return new WP_REST_Response( [ 'code' => 'invalid_current_password', 'message' => 'Your current password is incorrect.' ], 422 );
	}

	if ( strlen( $new_password ) < 8 ) {
		return new WP_REST_Response( [ 'code' => 'weak_password', 'message' => 'New password must be at least 8 characters.' ], 422 );
	}

	wp_set_password( $new_password, $user->ID );
	dtb_clear_auth_cookie();

	return new WP_REST_Response( [
		'success'       => true,
		'reauth_required' => true,
		'message'       => 'Password updated. Please sign in again.',
	], 200 );
}

function dtb_account_register_routes(): void {
	register_rest_route( 'dtb/v1', '/account', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_account_get',
			'permission_callback' => 'dtb_jwt_permission',
		],
		[
			'methods'             => 'PATCH',
			'callback'            => 'dtb_account_update',
			'permission_callback' => 'dtb_jwt_permission',
		],
	] );

	register_rest_route( 'dtb/v1', '/account/password', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_account_change_password',
		'permission_callback' => 'dtb_jwt_permission',
	] );
}

add_action( 'rest_api_init', 'dtb_account_register_routes', 20 );

foreach ( [
	'customer_processing_order',
	'customer_completed_order',
	'customer_refunded_order',
	'customer_on_hold_order',
	'customer_invoice',
] as $email_id ) {
	add_filter(
		'woocommerce_email_enabled_' . $email_id,
		static function ( bool $enabled, $object ): bool {
			if ( ! $enabled || ! $object || ! method_exists( $object, 'get_billing_email' ) ) {
				return $enabled;
			}
			return dtb_account_email_preference( (string) $object->get_billing_email(), 'order_updates' );
		},
		10,
		2
	);
}
