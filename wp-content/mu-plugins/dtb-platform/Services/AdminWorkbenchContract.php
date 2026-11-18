<?php
/**
 * DTB Platform — AdminWorkbenchContract
 *
 * Documents and validates the canonical admin workbench payload shape.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return required top-level keys for Admin Workbench v2.
 *
 * @return string[]
 */
function dtb_admin_workbench_contract_required_keys(): array {
	return [
		'ok',
		'record',
		'customer',
		'linked_records',
		'workflow',
		'intelligence',
		'communication',
		'integrations',
		'timeline',
		'actions',
		'permissions',
		'meta',
	];
}

/**
 * Convert stored enum/slugs into operator-facing labels.
 *
 * This is intentionally conservative and only used for display-only fields.
 * Workflow keys/status values remain raw so JS comparisons and backend actions
 * keep using canonical machine values.
 *
 * @param mixed $value Raw value.
 * @return mixed
 */
function dtb_admin_workbench_display_label( mixed $value ): mixed {
	if ( ! is_string( $value ) ) {
		return $value;
	}

	$raw = trim( $value );
	if ( '' === $raw ) {
		return $value;
	}

	$key = strtolower( $raw );
	$known = [
		'frontend_repair_form' => 'Frontend Repair Form',
		'frontend_return_form' => 'Frontend Return Form',
		'frontend_support_form' => 'Frontend Support Form',
		'quote_required'       => 'Quote Required',
		'preapproval_required' => 'Preapproval Required',
		'preapproved'          => 'Preapproved',
		'not_sure'             => 'Not Sure',
		'yes'                  => 'Yes',
		'no'                   => 'No',
		'phone'                => 'Phone',
		'email'                => 'Email',
		'sms'                  => 'SMS',
		'return'               => 'Return',
		'discard'              => 'Discard',
		'handle_tune_up'       => 'Handle Tune-Up',
	];

	if ( isset( $known[ $key ] ) ) {
		return $known[ $key ];
	}

	// Only auto-label slug-like strings. Leave normal human text, emails, URLs,
	// IDs, SKUs, dates, and mixed-case product names untouched.
	if ( ! preg_match( '/^[a-z0-9_\-\s]+$/', $raw ) ) {
		return $value;
	}
	if ( ! preg_match( '/[_\-]/', $raw ) ) {
		return $value;
	}

	$label = preg_replace( '/[_\-]+/', ' ', $raw );
	$label = preg_replace( '/\s+/', ' ', (string) $label );
	$label = ucwords( strtolower( trim( (string) $label ) ) );

	$acronyms = [
		' Api ' => ' API ',
		' Dtb ' => ' DTB ',
		' Id '  => ' ID ',
		' Ip '  => ' IP ',
		' Sku ' => ' SKU ',
		' Url ' => ' URL ',
		' Sms ' => ' SMS ',
		' Wp '  => ' WP ',
		' Woo ' => ' Woo ',
	];

	$label = ' ' . $label . ' ';
	$label = strtr( $label, $acronyms );

	return trim( $label );
}

/**
 * Normalize known display-only fields inside workbench payloads.
 *
 * @param array $payload Payload.
 * @return array
 */
function dtb_admin_normalize_workbench_display_values( array $payload ): array {
	$record_fields = [
		'source',
		'priority',
		'service_tier',
		'package_id',
		'approval_mode',
		'warranty_requested',
		'old_parts_return',
		'tool_age',
		'issue_start',
		'contact_preference',
		'tool_category',
	];

	foreach ( $record_fields as $field ) {
		if ( array_key_exists( $field, $payload['record'] ) ) {
			$payload['record'][ $field ] = dtb_admin_workbench_display_label( $payload['record'][ $field ] );
		}
	}

	foreach ( [ 'inbound_method', 'return_preference', 'rate_name' ] as $field ) {
		if ( isset( $payload['shipping'] ) && is_array( $payload['shipping'] ) && array_key_exists( $field, $payload['shipping'] ) ) {
			$payload['shipping'][ $field ] = dtb_admin_workbench_display_label( $payload['shipping'][ $field ] );
		}
	}

	if ( isset( $payload['quote'] ) && is_array( $payload['quote'] ) && array_key_exists( 'status', $payload['quote'] ) ) {
		$payload['quote']['status'] = dtb_admin_workbench_display_label( $payload['quote']['status'] );
	}

	return $payload;
}

/**
 * Normalize a partial payload into the canonical top-level workbench shape.
 *
 * This is intentionally additive. It preserves module aliases during migration.
 *
 * @param array $payload Workbench payload.
 * @return array
 */
function dtb_admin_prepare_workbench_payload( array $payload ): array {
	$defaults = [
		'ok'             => true,
		'record'         => [],
		'customer'       => [],
		'linked_records' => [],
		'workflow'       => [],
		'intelligence'   => [],
		'communication'  => [],
		'integrations'   => [],
		'timeline'       => [],
		'actions'        => [],
		'permissions'    => [],
		'meta'           => [],
	];

	$payload = array_replace_recursive( $defaults, $payload );
	$payload = dtb_admin_normalize_workbench_display_values( $payload );

	if ( empty( $payload['meta']['fetched_at'] ) ) {
		$payload['meta']['fetched_at'] = gmdate( 'c' );
	}
	if ( empty( $payload['meta']['poll_after_ms'] ) ) {
		$payload['meta']['poll_after_ms'] = 180000;
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$errors = dtb_admin_validate_workbench_payload( $payload );
		if ( $errors ) {
			$payload['meta']['contract_errors'] = $errors;
		}
	}

	return $payload;
}

/**
 * Validate the canonical workbench payload shape.
 *
 * @param array $payload Workbench payload.
 * @return string[] Human-readable validation errors.
 */
function dtb_admin_validate_workbench_payload( array $payload ): array {
	$errors = [];

	foreach ( dtb_admin_workbench_contract_required_keys() as $key ) {
		if ( ! array_key_exists( $key, $payload ) ) {
			$errors[] = 'Missing required key: ' . $key;
		}
	}

	foreach ( [ 'record', 'customer', 'linked_records', 'workflow', 'intelligence', 'communication', 'integrations', 'permissions', 'meta' ] as $key ) {
		if ( isset( $payload[ $key ] ) && ! is_array( $payload[ $key ] ) ) {
			$errors[] = 'Expected array at key: ' . $key;
		}
	}

	foreach ( [ 'timeline', 'actions' ] as $key ) {
		if ( isset( $payload[ $key ] ) && ! is_array( $payload[ $key ] ) ) {
			$errors[] = 'Expected list/array at key: ' . $key;
		}
	}

	return $errors;
}
