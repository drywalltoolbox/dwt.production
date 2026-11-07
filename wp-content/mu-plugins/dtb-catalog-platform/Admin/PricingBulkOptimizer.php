<?php
/**
 * DTB Catalog Platform — whole-catalog pricing optimizer orchestration.
 *
 * Preview-first, operator-triggered, bounded catalog-wide optimization. The
 * pricing rules engine remains the sole calculation/mutation authority.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_PRICING_BULK_RUN_PREFIX = 'dtb_pricing_bulk_run_';
const DTB_PRICING_BULK_BATCH_SIZE = 50;

add_action( 'rest_api_init', 'dtb_pricing_bulk_register_routes' );
add_action( 'admin_enqueue_scripts', 'dtb_pricing_bulk_enqueue_assets', 30 );

function dtb_pricing_bulk_register_routes(): void {
	$permission = static fn(): bool => current_user_can( 'dtb_manage_catalog_pricing' );
	register_rest_route( 'dtb/v1', '/admin/pricing/optimize-all/preview', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dtb_pricing_bulk_rest_preview', 'permission_callback' => $permission ] );
	register_rest_route( 'dtb/v1', '/admin/pricing/optimize-all/apply', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'dtb_pricing_bulk_rest_apply', 'permission_callback' => $permission ] );
}

function dtb_pricing_bulk_enqueue_assets( string $hook_suffix ): void {
	if ( 'admin.php' !== $hook_suffix || 'dtb-pricing-manager' !== sanitize_key( (string) ( $_GET['page'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$base = plugin_dir_url( __FILE__ ) . 'assets/';
	wp_enqueue_style( 'dtb-pricing-bulk-optimizer', $base . 'dtb-pricing-bulk-optimizer.css', [], '2.0.1' );
	wp_enqueue_script( 'dtb-pricing-bulk-optimizer', $base . 'dtb-pricing-bulk-optimizer.js', [], '2.0.1', true );
}

function dtb_pricing_bulk_minor_units( $value ): int {
	$minor = dtb_pricing_money_minor_units( $value );
	return null === $minor ? 0 : $minor;
}

function dtb_pricing_bulk_from_minor_units( int $minor ): float {
	$scale = 10 ** max( 0, wc_get_price_decimals() );
	return $minor / $scale;
}

/** Build and persist a server-owned preview snapshot. */
function dtb_pricing_bulk_rest_preview(): WP_REST_Response {
	$rows = dtb_pricing_build_index();
	$run  = [ 'created_at' => time(), 'user_id' => get_current_user_id(), 'items' => [], 'cursor' => 0, 'result' => [ 'updated' => 0, 'conflicts' => 0, 'errors' => 0 ] ];
	$summary = [
		'total' => count( $rows ), 'with_cost' => 0, 'with_map' => 0, 'missing_cost' => 0, 'missing_map' => 0,
		'will_update' => 0, 'below_cogs' => 0, 'below_minimum' => 0, 'map_violations' => 0,
		'already_optimal' => 0, 'review' => 0, 'blocked' => 0, 'review_or_blocked' => 0,
		'estimated_regular_increase' => 0.0, 'policy' => dtb_pricing_get_policy_settings(),
	];
	$increase_minor = 0;

	foreach ( $rows as $row ) {
		if ( null !== ( $row['cost'] ?? null ) ) { ++$summary['with_cost']; } else { ++$summary['missing_cost']; }
		if ( ! empty( $row['has_map'] ) ) { ++$summary['with_map']; } else { ++$summary['missing_map']; }
		if ( 'below_cogs' === ( $row['status'] ?? '' ) ) { ++$summary['below_cogs']; }
		if ( 'below_minimum' === ( $row['status'] ?? '' ) ) { ++$summary['below_minimum']; }
		if ( ! empty( $row['map_violation'] ) ) { ++$summary['map_violations']; }

		$action = (string) ( $row['recommendation_action'] ?? '' );
		if ( 'optimize' === $action && ! empty( $row['optimizer_eligible'] ) ) {
			$current_minor   = dtb_pricing_bulk_minor_units( $row['regular_price'] ?? null );
			$suggested_minor = dtb_pricing_bulk_minor_units( $row['suggested_price'] ?? null );
			$increase_minor += max( 0, $suggested_minor - $current_minor );
			$run['items'][] = [ 'product_id' => absint( $row['id'] ?? 0 ), 'expected_regular_price' => $row['regular_price'] ?? null ];
			++$summary['will_update'];
		} elseif ( 'review' === $action ) {
			++$summary['review']; ++$summary['review_or_blocked'];
		} elseif ( 'blocked' === $action ) {
			++$summary['blocked']; ++$summary['review_or_blocked'];
		} elseif ( 'hold' === $action && 'PRICE_HEALTHY' === ( $row['reason_code'] ?? '' ) ) {
			++$summary['already_optimal'];
		}
	}

	$summary['estimated_regular_increase'] = dtb_pricing_bulk_from_minor_units( $increase_minor );
	$run['summary'] = $summary; $token = wp_generate_uuid4();
	set_transient( DTB_PRICING_BULK_RUN_PREFIX . $token, $run, 30 * MINUTE_IN_SECONDS );
	return new WP_REST_Response( [ 'run_token' => $token, 'batch_size' => DTB_PRICING_BULK_BATCH_SIZE, 'summary' => $summary ], 200 );
}

/** Apply the next bounded batch from a server-owned preview snapshot. */
function dtb_pricing_bulk_rest_apply( WP_REST_Request $request ) {
	$payload = $request->get_json_params();
	$token = sanitize_text_field( (string) ( is_array( $payload ) ? ( $payload['run_token'] ?? '' ) : '' ) );
	if ( '' === $token ) { return new WP_Error( 'dtb_pricing_bulk_missing_run', __( 'Pricing optimization preview is required before applying changes.', 'drywall-toolbox' ), [ 'status' => 400 ] ); }
	$key = DTB_PRICING_BULK_RUN_PREFIX . $token; $run = get_transient( $key );
	if ( ! is_array( $run ) || absint( $run['user_id'] ?? 0 ) !== get_current_user_id() ) { return new WP_Error( 'dtb_pricing_bulk_expired_run', __( 'This pricing optimization preview has expired. Generate a new preview.', 'drywall-toolbox' ), [ 'status' => 409 ] ); }
	$items = is_array( $run['items'] ?? null ) ? $run['items'] : []; $cursor = max( 0, absint( $run['cursor'] ?? 0 ) ); $batch = array_slice( $items, $cursor, DTB_PRICING_BULK_BATCH_SIZE );
	if ( [] !== $batch ) {
		$batch_result = dtb_pricing_apply_selected( $batch );
		$run['result']['updated'] += count( $batch_result['updated'] ?? [] ); $run['result']['conflicts'] += count( $batch_result['conflicts'] ?? [] ); $run['result']['errors'] += count( $batch_result['errors'] ?? [] ); $run['cursor'] = $cursor + count( $batch );
	}
	$processed = min( count( $items ), absint( $run['cursor'] ?? 0 ) ); $complete = $processed >= count( $items );
	$response = [ 'complete' => $complete, 'processed' => $processed, 'total' => count( $items ), 'result' => $run['result'] ];
	if ( $complete ) {
		$response['summary'] = $run['summary'] ?? [];
		if ( function_exists( 'dtb_admin_audit_write' ) ) { dtb_admin_audit_write( 'catalog_pricing', 0, 'catalog_pricing.optimize_all_completed', [ 'preview' => $run['summary'] ?? [], 'result' => $run['result'], 'policy' => dtb_pricing_get_policy_settings() ], [ 'source' => 'pricing_manager' ] ); }
		delete_transient( $key );
	} else { set_transient( $key, $run, 30 * MINUTE_IN_SECONDS ); }
	return new WP_REST_Response( $response, 200 );
}
