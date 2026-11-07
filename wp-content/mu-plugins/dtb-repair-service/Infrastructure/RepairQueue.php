<?php
/**
 * Infrastructure — RepairQueue: async job handlers for repair integrations.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_REPAIR_JOB_MAX_RETRIES' ) ) {
define( 'DTB_REPAIR_JOB_MAX_RETRIES', 3 );
}

if ( ! defined( 'DTB_REPAIR_JOB_RETRY_BASE' ) ) {
define( 'DTB_REPAIR_JOB_RETRY_BASE', 300 );
}

/**
 * Enqueue an async repair job.
 *
 * @param string $job_type
 * @param int    $repair_id
 * @param array  $args
 * @param int    $delay
 * @return string|int|false
 */
function dtb_repair_enqueue_job( string $job_type, int $repair_id, array $args = [], int $delay = 0 ): string|int|false {
if ( '' === $job_type || $repair_id <= 0 ) {
return false;
}

if ( 'dtb_repair_send_notification' === $job_type && empty( $args['template'] ) ) {
return false;
}

$job_args = array_merge( [ 'repair_id' => $repair_id ], $args );

if ( function_exists( 'as_schedule_single_action' ) ) {
if ( function_exists( 'as_next_scheduled_action' ) ) {
$existing = as_next_scheduled_action( $job_type, [ $repair_id, $args ], 'dtb-repairs' );
if ( false !== $existing ) {
return $existing;
}
}

return as_schedule_single_action(
time() + max( 0, $delay ),
$job_type,
[ $repair_id, $args ],
'dtb-repairs'
);
}

$timestamp = time() + max( 1, $delay );
wp_schedule_single_event( $timestamp, $job_type, [ $repair_id, $args ] );
return $timestamp;
}

/**
 * Re-enqueue a job with exponential backoff.
 *
 * @param string $job_type
 * @param int    $repair_id
 * @param array  $args
 */
function dtb_repair_retry_job( string $job_type, int $repair_id, array $args = [] ): void {
$retry_count = (int) ( $args['retry_count'] ?? 0 );

if ( $retry_count >= DTB_REPAIR_JOB_MAX_RETRIES ) {
error_log( "[DTB Repairs] Job '{$job_type}' for repair #{$repair_id} exceeded max retries ({$retry_count})." );
return;
}

$delay               = DTB_REPAIR_JOB_RETRY_BASE * (int) pow( 2, $retry_count );
$args['retry_count'] = $retry_count + 1;

dtb_repair_enqueue_job( $job_type, $repair_id, $args, $delay );

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event(
$repair_id,
'system.job_retry',
[
'visibility' => 'internal',
'payload'    => [
'job_type'    => $job_type,
'retry_count' => $args['retry_count'],
'delay'       => $delay,
],
]
);
}
}

add_action( 'dtb_repair_create_wc_order', 'dtb_repair_job_create_wc_order', 10, 2 );

/**
 * Job handler: create a WooCommerce order for the repair.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_create_wc_order( int $repair_id, array $args = [] ): void {
try {
$state = function_exists( 'dtb_get_repair_integration_state' )
? dtb_get_repair_integration_state( $repair_id )
: [];

if ( ( $state['woocommerce']['state'] ?? '' ) === 'synced' ) {
return;
}

$result = dtb_repair_create_woocommerce_order( $repair_id );

if ( is_wp_error( $result ) ) {
throw new RuntimeException( $result->get_error_message() );
}

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state(
$repair_id,
'woocommerce',
[
'state'           => 'synced',
'order_id'        => $result,
'last_success_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
'last_error'      => null,
]
);
}

update_post_meta( $repair_id, '_repair_wc_order_id', $result );

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.wc.order_created', [
'visibility' => 'operator',
'payload'    => [ 'order_id' => $result ],
] );
}
} catch ( Throwable $e ) {
error_log( "[DTB Repairs] dtb_repair_create_wc_order failed for #{$repair_id}: " . $e->getMessage() );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'woocommerce', [
'state'      => 'error',
'last_error' => $e->getMessage(),
] );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.wc.order_failed', [
'visibility' => 'operator',
'payload'    => [ 'error' => $e->getMessage(), 'retry_count' => $args['retry_count'] ?? 0 ],
] );
}

dtb_repair_retry_job( 'dtb_repair_create_wc_order', $repair_id, $args );
}
}

add_action( 'dtb_repair_sync_veeqo', 'dtb_repair_job_sync_veeqo', 10, 2 );

/**
 * Job handler: sync repair with Veeqo.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_sync_veeqo( int $repair_id, array $args = [] ): void {
try {
$veeqo_action = sanitize_text_field( (string) ( $args['action'] ?? 'reserve_parts' ) );

if ( ! function_exists( 'dtb_veeqo_enabled' ) || ! dtb_veeqo_enabled() ) {
error_log( "[DTB Repairs] Veeqo not configured — skipping sync for repair #{$repair_id}." );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'veeqo', [
'state'           => 'not_configured',
'last_error_code' => 'veeqo_not_configured',
] );
}
return;
}

error_log( "[DTB Repairs] TODO: Veeqo {$veeqo_action} for repair #{$repair_id}. Wire dtb_veeqo_request() here." );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'veeqo', [
'state'           => 'stub_pending',
'last_success_at' => null,
'last_error_code' => null,
] );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.veeqo.synced', [
'visibility' => 'operator',
'payload'    => [ 'action' => $veeqo_action, 'stub' => true ],
] );
}
} catch ( Throwable $e ) {
error_log( "[DTB Repairs] dtb_repair_sync_veeqo failed for #{$repair_id}: " . $e->getMessage() );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'veeqo', [
'state'           => 'error',
'last_error_code' => $e->getMessage(),
] );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.veeqo.failed', [
'visibility' => 'operator',
'payload'    => [ 'error' => $e->getMessage() ],
] );
}

dtb_repair_retry_job( 'dtb_repair_sync_veeqo', $repair_id, $args );
}
}

add_action( 'dtb_repair_sync_quickbooks', 'dtb_repair_job_sync_quickbooks', 10, 2 );

/**
 * Job handler: create a QuickBooks Online invoice for the repair.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_sync_quickbooks( int $repair_id, array $args = [] ): void {
try {
$state = function_exists( 'dtb_get_repair_integration_state' )
? dtb_get_repair_integration_state( $repair_id )
: [];

if ( ( $state['quickbooks']['state'] ?? '' ) === 'synced' ) {
return;
}

if ( ! function_exists( 'dtb_qbo_enabled' ) || ! dtb_qbo_enabled() ) {
error_log( "[DTB Repairs] QuickBooks not configured — skipping invoice for repair #{$repair_id}." );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'quickbooks', [
'state'           => 'not_configured',
'last_error_code' => 'qbo_not_configured',
] );
}
return;
}

error_log( "[DTB Repairs] TODO: QuickBooks invoice for repair #{$repair_id}. Wire dtb_qbo_request() here." );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'quickbooks', [
'state'           => 'stub_pending',
'invoice_id'      => null,
'last_success_at' => null,
'last_error_code' => null,
] );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.qbo.invoice_created', [
'visibility' => 'operator',
'payload'    => [ 'stub' => true ],
] );
}
} catch ( Throwable $e ) {
error_log( "[DTB Repairs] dtb_repair_sync_quickbooks failed for #{$repair_id}: " . $e->getMessage() );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'quickbooks', [
'state'           => 'error',
'last_error_code' => $e->getMessage(),
] );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.qbo.invoice_failed', [
'visibility' => 'operator',
'payload'    => [ 'error' => $e->getMessage() ],
] );
}

dtb_repair_retry_job( 'dtb_repair_sync_quickbooks', $repair_id, $args );
}
}

add_action( 'dtb_repair_issue_rewards', 'dtb_repair_job_issue_rewards', 10, 2 );

/**
 * Job handler: issue loyalty rewards for a completed repair.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_issue_rewards( int $repair_id, array $args = [] ): void {
try {
if ( get_post_meta( $repair_id, '_repair_rewards_issued', true ) ) {
return;
}

$user_id = (int) get_post_meta( $repair_id, '_repair_customer_user_id', true );
if ( ! $user_id ) {
if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'rewards', [
'state'  => 'not_eligible',
'issued' => false,
] );
}
return;
}

error_log( "[DTB Repairs] TODO: Issue rewards for user #{$user_id} on repair #{$repair_id}. Wire WPLoyalty earn engine here." );

$now = gmdate( 'Y-m-d\TH:i:s\Z' );
update_post_meta( $repair_id, '_repair_rewards_issued', true );
update_post_meta( $repair_id, '_repair_rewards_issued_at', $now );
update_post_meta( $repair_id, '_repair_rewards_status', 'issued_stub' );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'rewards', [
'state'  => 'stub_issued',
'issued' => true,
] );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.rewards.issued', [
'visibility' => 'operator',
'payload'    => [ 'user_id' => $user_id, 'stub' => true, 'issued_at' => $now ],
] );
}
} catch ( Throwable $e ) {
error_log( "[DTB Repairs] dtb_repair_issue_rewards failed for #{$repair_id}: " . $e->getMessage() );

if ( function_exists( 'dtb_update_repair_integration_state' ) ) {
dtb_update_repair_integration_state( $repair_id, 'rewards', [
'state'  => 'error',
'issued' => false,
] );
}

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'integration.rewards.failed', [
'visibility' => 'operator',
'payload'    => [ 'error' => $e->getMessage() ],
] );
}

dtb_repair_retry_job( 'dtb_repair_issue_rewards', $repair_id, $args );
}
}

add_action( 'dtb_repair_send_notification', 'dtb_repair_job_send_notification', 10, 2 );

/**
 * Job handler: send a notification email for a repair.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_send_notification( int $repair_id, array $args = [] ): void {
$template = sanitize_text_field( (string) ( $args['template'] ?? '' ) );
if ( '' === $template ) {
return;
}

if ( function_exists( 'dtb_repair_dispatch_notification' ) ) {
dtb_repair_dispatch_notification( $repair_id, $template, $args['context'] ?? [] );
} else {
error_log( "[DTB Repairs] dtb_repair_dispatch_notification() not available — skipping '{$template}' for repair #{$repair_id}." );
}
}

add_action( 'dtb_repair_recalculate_sla', 'dtb_repair_job_recalculate_sla', 10, 2 );

/**
 * Job handler: recalculate SLA age and breach status for a repair.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_recalculate_sla( int $repair_id, array $args = [] ): void {
$submitted_at_raw = (string) get_post_meta( $repair_id, '_repair_submitted_at', true );
if ( '' === $submitted_at_raw ) {
return;
}

$submitted = strtotime( $submitted_at_raw );
if ( ! $submitted ) {
return;
}

$age_days     = (int) floor( ( time() - $submitted ) / DAY_IN_SECONDS );
$service_tier = (string) get_post_meta( $repair_id, '_repair_service_tier', true );

$sla_days_map = [
'express'  => 3,
'standard' => 10,
'warranty' => 21,
];
$sla_days = $sla_days_map[ $service_tier ] ?? 10;
$breached = $age_days > $sla_days;

update_post_meta( $repair_id, '_repair_sla_age_days', $age_days );
update_post_meta( $repair_id, '_repair_sla_breached', $breached ? '1' : '0' );
update_post_meta( $repair_id, '_repair_sla_threshold_days', $sla_days );

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'system.sla_recalculated', [
'visibility' => 'internal',
'payload'    => [
'age_days' => $age_days,
'sla_days' => $sla_days,
'breached' => $breached,
],
] );
}
}

add_action( 'dtb_repair_archive_closed', 'dtb_repair_job_archive_closed', 10, 2 );

/**
 * Job handler: archive a closed repair.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_archive_closed( int $repair_id, array $args = [] ): void {
$status = (string) get_post_meta( $repair_id, '_repair_status', true );

if ( ! in_array( $status, [ 'closed', 'cancelled', 'quote_declined' ], true ) ) {
return;
}

update_post_meta( $repair_id, '_repair_archived', '1' );
update_post_meta( $repair_id, '_repair_archived_at', gmdate( 'Y-m-d\TH:i:s\Z' ) );

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'system.archived', [
'visibility' => 'internal',
'payload'    => [ 'final_status' => $status ],
] );
}
}

add_action( 'dtb_repair_refresh_projection', 'dtb_repair_job_refresh_projection', 10, 2 );

/**
 * Job handler: refresh the integration state projection meta.
 *
 * @param int   $repair_id
 * @param array $args
 */
function dtb_repair_job_refresh_projection( int $repair_id, array $args = [] ): void {
if ( ! function_exists( 'dtb_get_repair_integration_state' ) ) {
return;
}

$state = dtb_get_repair_integration_state( $repair_id );

$wc_order_id = (int) get_post_meta( $repair_id, '_repair_wc_order_id', true );
if ( $wc_order_id ) {
$state['woocommerce']['order_id'] = $wc_order_id;
if ( 'pending' === $state['woocommerce']['state'] ) {
$state['woocommerce']['state'] = 'synced';
}
}

$tracking = (string) get_post_meta( $repair_id, '_repair_veeqo_tracking', true );
if ( '' !== $tracking ) {
$state['veeqo']['tracking_number'] = $tracking;
}

$qb_invoice = (string) get_post_meta( $repair_id, '_repair_quickbooks_invoice_id', true );
if ( '' !== $qb_invoice ) {
$state['quickbooks']['invoice_id'] = $qb_invoice;
if ( 'pending' === $state['quickbooks']['state'] ) {
$state['quickbooks']['state'] = 'synced';
}
}

$rewards_issued = (bool) get_post_meta( $repair_id, '_repair_rewards_issued', true );
if ( $rewards_issued ) {
$state['rewards']['state']  = 'issued';
$state['rewards']['issued'] = true;
}

update_post_meta( $repair_id, '_repair_integration_state', wp_json_encode( $state ) );

if ( function_exists( 'dtb_repair_append_event' ) ) {
dtb_repair_append_event( $repair_id, 'system.projection_refresh', [
'visibility' => 'internal',
] );
}
}

/**
 * Create a WooCommerce order for a repair request.
 *
 * @param int $repair_id
 * @return int|WP_Error WooCommerce order ID on success, WP_Error on failure.
 */
function dtb_repair_create_woocommerce_order( int $repair_id ): int|WP_Error {
if ( ! function_exists( 'wc_create_order' ) ) {
return new WP_Error( 'wc_unavailable', __( 'WooCommerce is not active.', 'drywall-toolbox' ) );
}

$customer_email = sanitize_email( (string) get_post_meta( $repair_id, '_repair_customer_email', true ) );
$customer_name  = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_customer_name', true ) );
$customer_phone = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_customer_phone', true ) );
$company        = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_company', true ) );
$service_tier   = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_service_tier', true ) );
$brand          = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_tool_brand', true ) );
$tool_category  = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_tool_category', true ) );
$model          = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_model', true ) );
$serial         = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_serial', true ) );
$issue          = sanitize_textarea_field( (string) get_post_meta( $repair_id, '_repair_issue', true ) );
$priority       = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_priority', true ) );
$issue_start    = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_issue_start', true ) );
$contact_pref   = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_contact_preference', true ) );
$address_1      = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_return_address_1', true ) );
$city           = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_return_city', true ) );
$state          = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_return_state', true ) );
$postcode       = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_return_postcode', true ) );
$country        = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_return_country', true ) );
$shipping_rate_name  = sanitize_text_field( (string) get_post_meta( $repair_id, '_repair_shipping_rate_name', true ) );
$shipping_rate_price = (float) get_post_meta( $repair_id, '_repair_shipping_rate_price', true );
$user_id        = (int) get_post_meta( $repair_id, '_repair_customer_user_id', true );

$tier_prices = (array) apply_filters(
'dtb_repair_service_tier_prices',
[
'standard' => 0.00,
'express'  => 0.00,
'warranty' => 0.00,
],
$service_tier,
$repair_id
);
$line_amount = (float) ( $tier_prices[ $service_tier ] ?? 0.00 );

$order_args = [];
if ( $user_id ) {
$order_args['customer_id'] = $user_id;
}

$order = wc_create_order( $order_args );

if ( is_wp_error( $order ) ) {
return $order;
}

$name_parts = explode( ' ', $customer_name, 2 );
$order->set_billing_first_name( $name_parts[0] ?? '' );
$order->set_billing_last_name( $name_parts[1] ?? '' );
$order->set_billing_company( $company );
$order->set_billing_email( $customer_email );
$order->set_billing_phone( $customer_phone );
$order->set_billing_address_1( $address_1 );
$order->set_billing_city( $city );
$order->set_billing_state( $state );
$order->set_billing_postcode( $postcode );
$order->set_billing_country( $country );

$order->set_shipping_first_name( $name_parts[0] ?? '' );
$order->set_shipping_last_name( $name_parts[1] ?? '' );
$order->set_shipping_company( $company );
$order->set_shipping_address_1( $address_1 );
$order->set_shipping_city( $city );
$order->set_shipping_state( $state );
$order->set_shipping_postcode( $postcode );
$order->set_shipping_country( $country );

$repair_item_description = $model ?: ( $tool_category ?: __( 'General Repair', 'drywall-toolbox' ) );

$item = new WC_Order_Item_Fee();
$item->set_name(
sprintf(
/* translators: 1: brand, 2: model, 3: service tier */
__( 'Repair Service (%1$s %2$s — %3$s)', 'drywall-toolbox' ),
$brand,
$repair_item_description,
ucfirst( $service_tier )
)
);
$item->set_amount( $line_amount );
$item->set_total( $line_amount );
$item->add_meta_data( '_dtb_repair_service_tier', $service_tier );
$order->add_item( $item );

if ( $shipping_rate_price > 0 ) {
	$shipping_item = new WC_Order_Item_Shipping();
	$shipping_item->set_method_title( $shipping_rate_name ?: __( 'Return Shipping', 'drywall-toolbox' ) );
	$shipping_item->set_method_id( 'dtb_repair_return_shipping' );
	$shipping_item->set_instance_id( '0' );
	$shipping_item->set_total( (string) $shipping_rate_price );
	$order->add_item( $shipping_item );
}

$order->update_meta_data( '_dtb_is_repair_order', '1' );
$order->update_meta_data( '_dtb_order_type', 'repair' );
$order->update_meta_data( '_dtb_repair_id', $repair_id );
$order->update_meta_data( '_dtb_repair_tool_brand', $brand );
$order->update_meta_data( '_dtb_repair_tool_category', $tool_category );
$order->update_meta_data( '_dtb_repair_tool_model', $model );
$order->update_meta_data( '_dtb_repair_serial', $serial );
$order->update_meta_data( '_dtb_repair_service_tier', $service_tier );
$order->update_meta_data( '_dtb_repair_priority', $priority );
$order->update_meta_data( '_dtb_repair_issue_start', $issue_start );
$order->update_meta_data( '_dtb_repair_contact_pref', $contact_pref );

$order->set_status( 'pending' );
$line_break = PHP_EOL;
$order->add_order_note(
	sprintf(
		/* translators: 1: issue description, 2: serial number, 3: issue start, 4: contact preference */
		__( 'Repair request details:%5$sIssue: %1$s%5$sSerial: %2$s%5$sIssue start: %3$s%5$sContact preference: %4$s', 'drywall-toolbox' ),
		$issue ?: __( 'Not provided', 'drywall-toolbox' ),
		$serial ?: __( 'N/A', 'drywall-toolbox' ),
		$issue_start ?: __( 'Not provided', 'drywall-toolbox' ),
		$contact_pref ?: __( 'email', 'drywall-toolbox' ),
		$line_break
	),
	false
);
$order->calculate_totals();
$order->save();

return $order->get_id();
}
