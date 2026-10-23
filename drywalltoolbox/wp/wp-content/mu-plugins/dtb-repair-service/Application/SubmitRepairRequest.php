<?php
/**
 * Application — SubmitRepairRequest: orchestrates submission, idempotency, event append, job dispatch.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the first non-empty submission value from a list of candidate keys.
 *
 * @param array       $data
 * @param string[]    $keys
 * @param string|int|float|null $default
 * @return mixed
 */
function dtb_repair_pick_submission_value( array $data, array $keys, mixed $default = '' ): mixed {
	foreach ( $keys as $key ) {
		if ( ! array_key_exists( $key, $data ) ) {
			continue;
		}

		$value = $data[ $key ];
		if ( null === $value ) {
			continue;
		}

		if ( is_string( $value ) && '' === trim( $value ) ) {
			continue;
		}

		return $value;
	}

	return $default;
}

/**
 * Normalize a text submission field from one or more candidate keys.
 *
 * @param array    $data
 * @param string[] $keys
 * @param string   $default
 * @return string
 */
function dtb_repair_pick_submission_text( array $data, array $keys, string $default = '' ): string {
	$value = dtb_repair_pick_submission_value( $data, $keys, $default );
	if ( ! is_scalar( $value ) ) {
		return $default;
	}

	return trim( (string) $value );
}

/**
 * Submit a new repair request.
 *
 * @param array $data Validated and sanitized field data.
 * @return int|WP_Error Post ID of newly created repair, or WP_Error on failure.
 */
function dtb_submit_repair_request( array $data ): int|WP_Error {
$idempotency_key = sanitize_text_field( (string) ( $data['idempotency_key'] ?? '' ) );

if ( '' !== $idempotency_key ) {
$existing_id = dtb_repair_find_by_idempotency_key( $idempotency_key );
if ( null !== $existing_id ) {
return $existing_id;
}
}

$customer_name   = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'customer_name', 'full_name', 'fullName' ] ) );
$customer_email  = sanitize_email( dtb_repair_pick_submission_text( $data, [ 'customer_email', 'email' ] ) );
$customer_phone  = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'customer_phone', 'phone' ] ) );
$company         = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'company' ] ) );
$description     = sanitize_textarea_field( dtb_repair_pick_submission_text( $data, [ 'description', 'issue', 'issueDescription' ] ) );
$item_type       = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'item_type', 'tool_category', 'toolCategory', 'item_brand', 'tool_brand', 'toolBrand' ], 'Repair Service' ) );
$item_brand      = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'item_brand', 'tool_brand', 'toolBrand' ] ) );
$item_model      = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'item_model', 'tool_model', 'toolModel' ] ) );
$serial_number   = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'serial_number', 'tool_serial', 'serialNumber' ] ) );
$service_tier    = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'service_tier', 'serviceType', 'pricingTierId' ] ) );
$package_id      = sanitize_key( dtb_repair_pick_submission_text( $data, [ 'package_id', 'packageId', 'pricingTierId' ] ) );
$approval_mode   = sanitize_key( dtb_repair_pick_submission_text( $data, [ 'approval_mode', 'approvalMode' ], 'quote_required' ) );
$preapproval_limit = (string) (float) dtb_repair_pick_submission_value( $data, [ 'preapproval_limit', 'preapprovalLimit' ], 0 );
$warranty_requested = sanitize_key( dtb_repair_pick_submission_text( $data, [ 'warranty_requested', 'warrantyRequested' ], 'no' ) );
$purchase_date   = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'purchase_date', 'purchaseDate' ] ) );
$old_parts_return = sanitize_key( dtb_repair_pick_submission_text( $data, [ 'old_parts_return', 'oldPartsReturn' ], 'discard' ) );
$inbound_shipping_method = sanitize_key( dtb_repair_pick_submission_text( $data, [ 'inbound_shipping_method', 'inboundShippingMethod' ], 'customer_pays_carrier' ) );
if ( ! in_array( $inbound_shipping_method, [ 'customer_pays_carrier', 'local_pickup_dropoff' ], true ) ) {
	$inbound_shipping_method = 'customer_pays_carrier';
}
$return_shipping_preference = sanitize_key( dtb_repair_pick_submission_text( $data, [ 'return_shipping_preference', 'returnShippingPreference' ], 'standard' ) );
$priority        = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'priority' ] ) );
$issue_start     = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'issue_start', 'issueStart' ] ) );
$contact_pref    = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'contact_preference', 'contactPreference' ], 'email' ) );
$tool_age        = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'tool_age', 'toolAge' ] ) );
$address_1       = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'address', 'address_1' ] ) );
$city            = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'city' ] ) );
$state           = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'state', 'province' ] ) );
$postcode        = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'zip', 'postcode', 'postal_code' ] ) );
$country         = strtoupper( sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'country' ], 'US' ) ) );
$shipping_rate_id    = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'shipping_rate_id', 'shippingRateId' ] ) );
$shipping_rate_name  = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'shipping_rate_name', 'shippingRateName' ] ) );
$shipping_rate_price = (string) (float) dtb_repair_pick_submission_value( $data, [ 'shipping_rate_price', 'shippingRatePrice' ], 0 );
$source          = sanitize_text_field( dtb_repair_pick_submission_text( $data, [ 'source' ], 'frontend_repair_form' ) );
$frontend_base_url = function_exists( 'dtb_repair_resolve_frontend_base_url' )
? dtb_repair_resolve_frontend_base_url( 0, $data )
: rtrim( home_url( '/' ), '/' );

$descriptor_detail = $item_model ?: ( 'Repair Service' !== $item_type ? $item_type : '' );
$tool_descriptor   = trim( implode( ' — ', array_filter( [ $item_brand, $descriptor_detail ] ) ) );

$title = sprintf(
/* translators: 1: customer name, 2: item type */
__( 'Repair Request — %1$s (%2$s)', 'drywall-toolbox' ),
$customer_name,
$tool_descriptor ?: $item_type
);

$post_id = wp_insert_post(
[
'post_type'   => 'dtb_repair_request',
'post_title'  => $title,
'post_status' => 'publish',
],
true
);

if ( is_wp_error( $post_id ) ) {
return $post_id;
}

$public_token = function_exists( 'dtb_repair_ensure_public_token' )
? dtb_repair_ensure_public_token( $post_id )
: '';

$meta = [
'_repair_customer_name'        => $customer_name,
'_repair_customer_email'       => $customer_email,
'_repair_customer_phone'       => $customer_phone,
'_repair_customer_user_id'     => get_current_user_id() ?: 0,
'_repair_company'              => $company,
'_repair_tool_brand'           => $item_brand,
'_repair_tool_category'        => $item_type,
'_repair_model'                => $item_model,
'_repair_serial'               => $serial_number,
'_repair_tool_age'             => $tool_age,
'_repair_service_tier'         => $service_tier,
'_repair_package_id'           => $package_id,
'_repair_approval_mode'        => $approval_mode,
'_repair_preapproval_limit'    => $preapproval_limit,
'_repair_warranty_requested'   => $warranty_requested,
'_repair_purchase_date'        => $purchase_date,
'_repair_old_parts_return'     => $old_parts_return,
'_repair_inbound_shipping_method' => $inbound_shipping_method,
'_repair_return_shipping_preference' => $return_shipping_preference,
'_repair_priority'             => $priority,
'_repair_issue_start'          => $issue_start,
'_repair_issue'                => $description,
'_repair_contact_preference'   => $contact_pref,
'_repair_return_address_1'     => $address_1,
'_repair_return_city'          => $city,
'_repair_return_state'         => $state,
'_repair_return_postcode'      => $postcode,
'_repair_return_country'       => $country,
'_repair_shipping_rate_id'     => $shipping_rate_id,
'_repair_shipping_rate_name'   => $shipping_rate_name,
'_repair_shipping_rate_price'  => $shipping_rate_price,
'_repair_status'               => 'submitted',
'_repair_public_token'         => $public_token,
'_repair_frontend_base_url'    => $frontend_base_url,
'_repair_submitted_at'         => gmdate( 'Y-m-d\TH:i:s\Z' ),
'_repair_source'               => $source,
'_repair_idempotency_key'      => $idempotency_key,
'_repair_submission_ip'        => dtb_repair_get_client_ip(),
];

foreach ( $meta as $key => $value ) {
update_post_meta( $post_id, $key, $value );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event(
$post_id,
'repair.submitted',
[
'actor_type' => 'customer',
'actor_id'   => null,
'source'     => $source,
'payload'    => [
'customer_name'  => $customer_name,
'customer_email' => $customer_email,
'item_type'      => $item_type,
'tool_brand'     => $item_brand,
'tool_model'     => $item_model,
],
]
);
}

if ( function_exists( 'dtb_repair_enqueue_job' ) ) {
dtb_repair_enqueue_job( 'dtb_repair_send_notification', $post_id, [ 'template' => 'repair-submitted-customer' ] );
dtb_repair_enqueue_job( 'dtb_repair_send_notification', $post_id, [ 'template' => 'repair-submitted-admin' ] );
dtb_repair_enqueue_job( 'dtb_repair_create_wc_order', $post_id );
dtb_repair_enqueue_job( 'dtb_repair_refresh_projection', $post_id );
}

/**
 * Fires after a new repair request has been successfully submitted.
 *
 * @param int   $post_id
 * @param array $data
 */
do_action( 'dtb_repair_submitted', $post_id, $data );

return $post_id;
}
