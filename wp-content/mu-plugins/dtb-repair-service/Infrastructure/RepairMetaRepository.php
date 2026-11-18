<?php
/**
 * Infrastructure — RepairMetaRepository: registers all post meta keys.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'init', 'dtb_repair_register_meta' );

/**
 * Register all post meta keys for dtb_repair_request.
 */
function dtb_repair_register_meta(): void {
$admin_auth = function (): bool {
return current_user_can( 'dtb_manage_repairs' );
};

$string_meta = [
'_repair_status', '_repair_public_token', '_repair_idempotency_key',
'_repair_customer_email', '_repair_customer_name', '_repair_customer_phone',
'_repair_tool_brand', '_repair_model', '_repair_serial',
'_repair_service_tier', '_repair_issue', '_repair_internal_notes',
'_repair_package_id', '_repair_approval_mode', '_repair_preapproval_limit',
'_repair_warranty_requested', '_repair_purchase_date', '_repair_old_parts_return',
'_repair_inbound_shipping_method', '_repair_return_shipping_preference',
'_repair_veeqo_sync_status', '_repair_veeqo_tracking',
'_repair_quickbooks_invoice_id', '_repair_rewards_status',
'_repair_submitted_at', '_repair_reviewed_at', '_repair_completed_at', '_repair_closed_at',
'_repair_integration_state', '_repair_rewards_event_id', '_repair_rewards_issued_at',
'_repair_quote_status', '_repair_quote_currency', '_repair_quote_sent_at', '_repair_quote_expires_at',
'_repair_quote_updated_at',
];

foreach ( $string_meta as $key ) {
register_post_meta(
'dtb_repair_request',
$key,
[
'type'              => 'string',
'single'            => true,
'sanitize_callback' => 'sanitize_text_field',
'auth_callback'     => $admin_auth,
]
);
}

$int_meta = [
'_repair_customer_user_id',
'_repair_assigned_tech_id',
'_repair_wc_order_id',
];

foreach ( $int_meta as $key ) {
register_post_meta(
'dtb_repair_request',
$key,
[
'type'              => 'integer',
'single'            => true,
'sanitize_callback' => 'absint',
'auth_callback'     => $admin_auth,
]
);
}

$bool_meta = [ '_repair_rewards_issued' ];
foreach ( $bool_meta as $key ) {
register_post_meta(
'dtb_repair_request',
$key,
[
'type'              => 'boolean',
'single'            => true,
'sanitize_callback' => 'rest_sanitize_boolean',
'auth_callback'     => $admin_auth,
]
);
}

register_post_meta(
'dtb_repair_request',
'_repair_images',
[
'type'              => 'string',
'single'            => true,
'sanitize_callback' => function ( $value ) {
$decoded = is_string( $value ) ? json_decode( $value, true ) : $value;
if ( ! is_array( $decoded ) ) {
return '[]';
}
return wp_json_encode( array_values( array_map( 'absint', $decoded ) ) );
},
'auth_callback'     => $admin_auth,
]
);

register_post_meta(
'dtb_repair_request',
'_repair_quote_payload',
[
'type'              => 'string',
'single'            => true,
'sanitize_callback' => function ( $value ) {
if ( is_array( $value ) ) {
$value = wp_json_encode( $value );
}
if ( ! is_string( $value ) || '' === trim( $value ) ) {
return '';
}
$decoded = json_decode( $value, true );
if ( ! is_array( $decoded ) ) {
return '';
}
if ( function_exists( 'dtb_repair_quote_normalize_payload' ) ) {
$decoded = dtb_repair_quote_normalize_payload( $decoded );
}
return wp_json_encode( $decoded );
},
'auth_callback'     => $admin_auth,
]
);
}
