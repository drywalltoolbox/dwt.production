<?php
/**
 * DTB Order Detail Controller — REST handler for single order endpoint.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build canonical order workbench action metadata.
 *
 * @param WC_Order $order        Order.
 * @param string   $workflow_key Workflow key.
 * @param array    $permissions  Permission flags.
 * @return array<int,array<string,mixed>>
 */
function dtb_order_rest_build_admin_actions( WC_Order $order, string $workflow_key, array $permissions ): array {
	$status       = sanitize_key( (string) $order->get_status() );
	$order_type   = function_exists( 'dtb_order_resolve_type' ) ? dtb_order_resolve_type( $order ) : 'product';
	$workflow_def = function_exists( 'dtb_admin_get_workflow_definition' )
		? dtb_admin_get_workflow_definition( $workflow_key )
		: [];
	$labels       = (array) ( $workflow_def['labels'] ?? [] );
	$transitions  = function_exists( 'dtb_admin_get_allowed_workflow_transitions' )
		? dtb_admin_get_allowed_workflow_transitions( $workflow_key, $status )
		: [];
	$actions      = [];

	if ( ! empty( $permissions['can_refresh'] ) ) {
		$actions[] = [
			'id'          => 'refresh_snapshot',
			'type'        => 'server_action',
			'action_type' => 'refresh_snapshot',
			'group'       => 'Operations',
			'label'       => __( 'Refresh Snapshot', 'drywall-toolbox' ),
			'description' => __( 'Refresh the order workbench snapshot, tracking projection, and linked operational state.', 'drywall-toolbox' ),
		];
		$actions[] = [
			'id'          => 'refresh_tracking',
			'type'        => 'server_action',
			'action_type' => 'refresh_tracking',
			'group'       => 'Operations',
			'label'       => __( 'Refresh Tracking', 'drywall-toolbox' ),
			'description' => __( 'Queue a tracking projection refresh from fulfillment integrations.', 'drywall-toolbox' ),
		];
	}

	if ( ! empty( $permissions['can_manage_status'] ) ) {
		foreach ( $transitions as $to_status ) {
			$to_status = sanitize_key( (string) $to_status );
			if ( '' === $to_status ) {
				continue;
			}

			$actions[] = [
				'id'            => 'transition_' . $to_status,
				'type'          => 'transition',
				'action_type'   => 'transition',
				'target_status' => $to_status,
				'group'         => 'Workflow',
				'label'         => sprintf(
					/* translators: %s: destination status label. */
					__( 'Move to %s', 'drywall-toolbox' ),
					(string) ( $labels[ $to_status ] ?? ucwords( str_replace( [ '_', '-' ], ' ', $to_status ) ) )
				),
				'description'   => __( 'Update the WooCommerce order lifecycle status and record the operator action.', 'drywall-toolbox' ),
				'confirm'       => true,
			];
		}

		$actions[] = [
			'id'          => 'resend_confirm',
			'type'        => 'server_action',
			'action_type' => 'resend_confirm',
			'group'       => 'Customer Communication',
			'label'       => __( 'Resend Order Confirmation', 'drywall-toolbox' ),
			'description' => __( 'Queue a fresh order confirmation email for the customer.', 'drywall-toolbox' ),
			'confirm'     => true,
		];
		$actions[] = [
			'id'          => 'resend_shipped',
			'type'        => 'server_action',
			'action_type' => 'resend_shipped',
			'group'       => 'Customer Communication',
			'label'       => __( 'Resend Shipping Email', 'drywall-toolbox' ),
			'description' => __( 'Queue a shipping notification using the current tracking state.', 'drywall-toolbox' ),
			'confirm'     => true,
		];

		if ( 'product' === $order_type ) {
			$actions[] = [
				'id'          => 'recalc_rewards',
				'type'        => 'server_action',
				'action_type' => 'recalc_rewards',
				'group'       => 'Accounting',
				'label'       => __( 'Recalculate Rewards', 'drywall-toolbox' ),
				'description' => __( 'Queue rewards issuance/reconciliation for this product order.', 'drywall-toolbox' ),
			];
		}
	}

	if ( ! empty( $permissions['can_retry_sync'] ) ) {
		$actions[] = [
			'id'          => 'retry_veeqo',
			'type'        => 'server_action',
			'action_type' => 'retry_veeqo',
			'group'       => 'Integrations',
			'label'       => __( 'Retry Veeqo Sync', 'drywall-toolbox' ),
			'description' => __( 'Re-queue fulfillment sync for Veeqo.', 'drywall-toolbox' ),
			'confirm'     => true,
		];
		$actions[] = [
			'id'          => 'retry_quickbooks',
			'type'        => 'server_action',
			'action_type' => 'retry_quickbooks',
			'group'       => 'Integrations',
			'label'       => __( 'Retry QuickBooks Sync', 'drywall-toolbox' ),
			'description' => __( 'Re-queue accounting sync for QuickBooks.', 'drywall-toolbox' ),
			'confirm'     => true,
		];
	}

	if ( ! empty( $permissions['can_open_wc_order'] ) ) {
		$actions[] = [
			'id'          => 'open_wc_order',
			'type'        => 'link',
			'group'       => 'Fallback',
			'label'       => __( 'Open WooCommerce Order', 'drywall-toolbox' ),
			'description' => __( 'Open the native WooCommerce order editor in a new tab.', 'drywall-toolbox' ),
			'href'        => admin_url( 'post.php?post=' . absint( $order->get_id() ) . '&action=edit' ),
		];
	}

	return $actions;
}

/**
 * Transition a WooCommerce order through the admin workbench.
 *
 * @param WC_Order $order        Order.
 * @param string   $workflow_key Workflow key.
 * @param string   $to_status    Destination WC status slug.
 * @return bool|WP_Error
 */
function dtb_order_rest_transition_order_status( WC_Order $order, string $workflow_key, string $to_status ): bool|WP_Error {
	$to_status      = sanitize_key( $to_status );
	$current_status = sanitize_key( (string) $order->get_status() );
	$order_id       = absint( $order->get_id() );

	if ( '' === $to_status ) {
		return new WP_Error( 'dtb_invalid_action', __( 'A destination order status is required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	if ( $to_status === $current_status ) {
		return new WP_Error( 'dtb_invalid_transition', __( 'Order is already in that status.', 'drywall-toolbox' ), [ 'status' => 409 ] );
	}

	$allowed = function_exists( 'dtb_admin_get_allowed_workflow_transitions' )
		? dtb_admin_get_allowed_workflow_transitions( $workflow_key, $current_status )
		: ( function_exists( 'dtb_order_get_allowed_transitions' ) ? dtb_order_get_allowed_transitions( $current_status ) : [] );

	if ( ! in_array( $to_status, $allowed, true ) ) {
		return new WP_Error(
			'dtb_invalid_transition',
			sprintf(
				/* translators: 1: current status, 2: destination status. */
				__( 'Order cannot move from %1$s to %2$s.', 'drywall-toolbox' ),
				$current_status,
				$to_status
			),
			[ 'status' => 409 ]
		);
	}

	if ( function_exists( 'wc_get_order_statuses' ) ) {
		$wc_statuses = wc_get_order_statuses();
		if ( ! isset( $wc_statuses[ 'wc-' . $to_status ] ) ) {
			return new WP_Error( 'dtb_invalid_status', __( 'Destination order status is not registered in WooCommerce.', 'drywall-toolbox' ), [ 'status' => 400 ] );
		}
	}

	try {
		$order->update_status(
			$to_status,
			sprintf(
				/* translators: 1: source status, 2: destination status. */
				__( 'DTB admin workflow transition: %1$s to %2$s.', 'drywall-toolbox' ),
				$current_status,
				$to_status
			),
			true
		);
	} catch ( Throwable $e ) {
		return new WP_Error( 'dtb_transition_failed', $e->getMessage(), [ 'status' => 500 ] );
	}

	if ( function_exists( 'dtb_order_append_event' ) ) {
		dtb_order_append_event( $order_id, 'order.admin_status_transitioned', [
			'from_status' => $current_status,
			'to_status'   => $to_status,
			'source'      => 'admin_workbench',
			'actor_type'  => 'admin',
			'actor_id'    => get_current_user_id(),
			'visibility'  => 'operator',
			'payload'     => [
				'workflow' => $workflow_key,
			],
		] );
	}

	return true;
}

function dtb_order_rest_get_order( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$order_id = (int) $request->get_param( 'id' );
	$user_id  = dtb_order_rest_resolve_request_user_id( $request );
	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}
	$user_id  = (int) $user_id;
	$order    = wc_get_order( $order_id );

	if ( ! $order ) {
		return new WP_Error( 'dtb_not_found', 'Order not found.', [ 'status' => 404 ] );
	}

	if ( ! current_user_can( 'manage_woocommerce' ) && (int) $order->get_customer_id() !== $user_id ) {
		return new WP_Error( 'dtb_forbidden', 'You do not have access to this order.', [ 'status' => 403 ] );
	}

	if ( ! current_user_can( 'manage_woocommerce' ) && function_exists( 'dtb_payment_is_incomplete_checkout_order' ) && dtb_payment_is_incomplete_checkout_order( $order ) ) {
		return new WP_Error( 'dtb_not_found', 'Order not found.', [ 'status' => 404 ] );
	}

	return new WP_REST_Response( dtb_order_format_detail( $order ), 200 );
}

/**
 * GET /dtb/v1/admin/orders/{id}/detail
 *
 * Canonical admin workbench payload for WooCommerce product/repair orders.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function dtb_order_rest_get_admin_detail( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$order_id = absint( $request->get_param( 'id' ) );

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'dtb_woocommerce_unavailable', __( 'WooCommerce is not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'dtb_not_found', __( 'Order not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$record     = dtb_order_format_detail( $order );
	$order_type = function_exists( 'dtb_order_resolve_type' ) ? dtb_order_resolve_type( $order ) : 'product';
	$workflow_key = 'repair' === $order_type ? 'repair_order' : 'product_order';
	$status = sanitize_key( (string) $order->get_status() );
	$workflow_def = function_exists( 'dtb_admin_get_workflow_definition' )
		? dtb_admin_get_workflow_definition( $workflow_key )
		: [];

	$customer = function_exists( 'dtb_admin_get_customer_context' )
		? dtb_admin_get_customer_context( [
			'customer_email'   => sanitize_email( $order->get_billing_email() ),
			'customer_user_id' => absint( $order->get_customer_id() ),
			'order_id'         => $order_id,
		] )
		: [];
	$linked = function_exists( 'dtb_admin_get_linked_records' )
		? dtb_admin_get_linked_records( 'order', $order_id )
		: [];
	$timeline = function_exists( 'dtb_admin_get_timeline' )
		? dtb_admin_get_timeline( 'order', $order_id, [ 'events' => (array) ( $record['timeline'] ?? [] ) ] )
		: ( function_exists( 'dtb_order_get_operator_timeline' )
			? dtb_order_get_operator_timeline( $order_id )
			: (array) ( $record['timeline'] ?? [] ) );
	$next_best_action_defaults = (array) ( $workflow_def['next_best_action_defaults'] ?? [] );
	$integrations = function_exists( 'dtb_admin_get_integration_state' )
		? dtb_admin_get_integration_state( 'order', $order_id )
		: [];

	$permissions = [
		'can_refresh'        => current_user_can( 'dtb_manage_orders' ) || current_user_can( 'manage_woocommerce' ),
		'can_manage_status'  => current_user_can( 'dtb_manage_orders' ) || current_user_can( 'manage_woocommerce' ),
		'can_retry_sync'     => current_user_can( 'dtb_manage_integrations' ) || current_user_can( 'manage_woocommerce' ),
		'can_open_wc_order'  => current_user_can( 'manage_woocommerce' ),
	];

	$payload = [
		'ok'             => true,
		'record'         => $record,
		'order'          => $record, // TODO: remove after orders JS reads record only.
		'customer'       => $customer,
		'linked_records' => $linked,
		'workflow'       => [
			'key'                 => $workflow_key,
			'status'              => $status,
			'label'               => (string) ( $workflow_def['labels'][ $status ] ?? ( function_exists( 'dtb_order_get_status_label' ) ? dtb_order_get_status_label( $status ) : $status ) ),
			'all_statuses'        => array_values( (array) ( $workflow_def['statuses'] ?? [] ) ),
			'labels'              => (array) ( $workflow_def['labels'] ?? [] ),
			'terminal_statuses'   => array_values( (array) ( $workflow_def['terminal_statuses'] ?? [] ) ),
			'allowed_transitions' => function_exists( 'dtb_admin_get_allowed_workflow_transitions' )
				? array_values( dtb_admin_get_allowed_workflow_transitions( $workflow_key, $status ) )
				: [],
		],
		'intelligence'   => [
			'next_best_action' => (string) ( $next_best_action_defaults[ $status ] ?? '' ),
			'risk_flags'       => in_array( $status, (array) ( $workflow_def['risk_states'] ?? [] ), true ) ? [ 'status_risk' ] : [],
		],
		'communication'  => [],
		'integrations'   => $integrations,
		'timeline'       => $timeline,
		'actions'        => dtb_order_rest_build_admin_actions( $order, $workflow_key, $permissions ),
		'permissions'    => $permissions,
		'meta'           => [
			'fetched_at'    => gmdate( 'c' ),
			'poll_after_ms' => 60000,
		],
	];

	if ( function_exists( 'dtb_admin_prepare_workbench_payload' ) ) {
		$payload = dtb_admin_prepare_workbench_payload( $payload );
	}

	return new WP_REST_Response( $payload, 200 );
}

/**
 * POST /dtb/v1/admin/orders/{id}/actions
 *
 * Safe order workbench actions that queue background work and return refreshed
 * canonical detail payloads.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function dtb_order_rest_admin_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$order_id = absint( $request->get_param( 'id' ) );
	$action   = sanitize_key( (string) $request->get_param( 'action_type' ) );

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'dtb_woocommerce_unavailable', __( 'WooCommerce is not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$order = wc_get_order( $order_id );
	if ( ! $order instanceof WC_Order ) {
		return new WP_Error( 'dtb_not_found', __( 'Order not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$integration_action = in_array( $action, [ 'retry_veeqo', 'retry_quickbooks' ], true );
	if ( $integration_action && ! ( current_user_can( 'dtb_manage_integrations' ) || current_user_can( 'manage_woocommerce' ) ) ) {
		return new WP_Error( 'dtb_forbidden', __( 'You do not have permission to retry integrations.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}
	$order_action = in_array( $action, [ 'refresh_snapshot', 'refresh_tracking', 'resend_confirm', 'resend_shipped', 'recalc_rewards' ], true );
	if ( $order_action && ! ( current_user_can( 'dtb_manage_orders' ) || current_user_can( 'manage_woocommerce' ) ) ) {
		return new WP_Error( 'dtb_forbidden', __( 'You do not have permission to run order actions.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}
	if ( ( $order_action || $integration_action ) && ! function_exists( 'dtb_order_enqueue_job' ) ) {
		return new WP_Error( 'dtb_queue_unavailable', __( 'Order queue is not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$idempotency = sanitize_text_field( (string) ( $request->get_header( 'X-DTB-Idempotency-Key' ) ?: $request->get_param( 'idempotency_key' ) ) );
	if ( '' !== $idempotency ) {
		$lock_key = 'dtb_order_action_' . md5( $order_id . '|' . $action . '|' . $idempotency );
		if ( get_transient( $lock_key ) ) {
			$detail = dtb_order_rest_get_admin_detail( $request );
			$data   = $detail instanceof WP_REST_Response ? $detail->get_data() : [];
			return new WP_REST_Response( [ 'ok' => true, 'duplicate' => true, 'detail' => $data ], 200 );
		}
		set_transient( $lock_key, 1, MINUTE_IN_SECONDS );
	}

	switch ( $action ) {
		case 'refresh_snapshot':
			dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'order.admin_refreshed', [
					'source'     => 'admin',
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'visibility' => 'operator',
				] );
			}
			$message = __( 'Order snapshot refresh queued.', 'drywall-toolbox' );
			break;

		case 'refresh_tracking':
			dtb_order_enqueue_job( 'dtb_order_refresh_tracking_projection', $order_id );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'integration.tracking_refresh_queued', [
					'source'     => 'admin',
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'visibility' => 'operator',
				] );
			}
			$message = __( 'Tracking refresh queued.', 'drywall-toolbox' );
			break;

		case 'retry_veeqo':
			dtb_order_enqueue_job( 'dtb_order_sync_veeqo', $order_id );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'integration.veeqo.queued', [
					'source'     => 'admin',
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'visibility' => 'operator',
				] );
			}
			$message = __( 'Veeqo sync re-queued.', 'drywall-toolbox' );
			break;

		case 'retry_quickbooks':
			dtb_order_enqueue_job( 'dtb_order_sync_quickbooks', $order_id, [ 'action' => 'create' ] );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'integration.quickbooks.queued', [
					'source'     => 'admin',
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'visibility' => 'operator',
				] );
			}
			$message = __( 'QuickBooks sync re-queued.', 'drywall-toolbox' );
			break;

		case 'resend_confirm':
			dtb_order_release_notification_send( $order_id, 'order-confirmation' );
			dtb_order_enqueue_job( 'dtb_order_send_notification', $order_id, [ 'template' => 'order-confirmation' ] );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'notification.order_confirmation_queued', [
					'source'     => 'admin',
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'visibility' => 'operator',
				] );
			}
			$message = __( 'Order confirmation re-queued.', 'drywall-toolbox' );
			break;

		case 'resend_shipped':
			dtb_order_enqueue_job( 'dtb_order_send_notification', $order_id, [ 'template' => 'order-shipped' ] );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'notification.order_shipped_queued', [
					'source'     => 'admin',
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'visibility' => 'operator',
				] );
			}
			$message = __( 'Shipping email re-queued.', 'drywall-toolbox' );
			break;

		case 'recalc_rewards':
			dtb_order_enqueue_job( 'dtb_order_issue_rewards', $order_id );
			if ( function_exists( 'dtb_order_append_event' ) ) {
				dtb_order_append_event( $order_id, 'rewards.recalculation_queued', [
					'source'     => 'admin',
					'actor_type' => 'admin',
					'actor_id'   => get_current_user_id(),
					'visibility' => 'operator',
				] );
			}
			$message = __( 'Rewards recalculation queued.', 'drywall-toolbox' );
			break;

		case 'transition':
			if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'dtb_manage_orders' ) ) {
				return new WP_Error( 'dtb_forbidden', __( 'You do not have permission to transition this order.', 'drywall-toolbox' ), [ 'status' => 403 ] );
			}
			$to_status = sanitize_key( (string) $request->get_param( 'to_status' ) );
			if ( ! $to_status ) {
				return new WP_Error( 'dtb_invalid_action', __( 'to_status is required.', 'drywall-toolbox' ), [ 'status' => 400 ] );
			}
			$order_type   = function_exists( 'dtb_order_resolve_type' ) ? dtb_order_resolve_type( $order ) : 'product';
			$workflow_key = 'repair' === $order_type ? 'repair_order' : 'product_order';
			$result       = dtb_order_rest_transition_order_status( $order, $workflow_key, $to_status );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$message = __( 'Order status updated.', 'drywall-toolbox' );
			break;

		default:
			return new WP_Error( 'dtb_invalid_action', __( 'Unsupported order action.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$detail = dtb_order_rest_get_admin_detail( $request );
	$data   = $detail instanceof WP_REST_Response ? $detail->get_data() : [];
	return new WP_REST_Response( [ 'ok' => true, 'message' => $message ?? __( 'Order action queued.', 'drywall-toolbox' ), 'detail' => $data ], 200 );
}

/**
 * POST /dtb/v1/admin/orders/bulk
 *
 * Bulk admin operations for the order queue.
 *
 * @param WP_REST_Request $request REST request.
 * @return WP_REST_Response|WP_Error
 */
function dtb_order_rest_admin_bulk_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	$ids    = array_values( array_filter( array_map( 'absint', (array) $request->get_param( 'ids' ) ) ) );

	if ( 'delete' !== $action ) {
		return new WP_Error( 'dtb_invalid_action', __( 'Unsupported bulk order action.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	if ( empty( $ids ) ) {
		return new WP_Error( 'dtb_invalid_request', __( 'No order IDs provided.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	if ( ! function_exists( 'wc_get_order' ) ) {
		return new WP_Error( 'dtb_woocommerce_unavailable', __( 'WooCommerce is not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$processed = [];
	$errors    = [];

	foreach ( array_unique( $ids ) as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			$errors[] = $order_id;
			continue;
		}

		if ( function_exists( 'dtb_order_append_event' ) ) {
			dtb_order_append_event( $order_id, 'order.admin_moved_to_trash', [
				'source'     => 'admin',
				'actor_type' => 'admin',
				'actor_id'   => get_current_user_id(),
				'visibility' => 'operator',
			] );
		}

		$deleted = $order->delete( false );
		if ( ! $deleted ) {
			$errors[] = $order_id;
			continue;
		}

		if ( class_exists( 'WC_Cache_Helper' ) ) {
			WC_Cache_Helper::invalidate_cache_group( 'orders' );
		}
		clean_post_cache( $order_id );
		wp_cache_delete( $order_id, 'orders' );
		wp_cache_delete( 'order-' . $order_id, 'orders' );
		$processed[] = $order_id;
	}

	return new WP_REST_Response( [
		'ok'        => empty( $errors ),
		'processed' => $processed,
		'deleted'   => $processed,
		'errors'    => $errors,
	], 200 );
}
