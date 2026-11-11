<?php
/**
 * DTB Platform — AdminWorkflowRegistry
 *
 * Canonical workflow definitions for admin workbenches, queues, and links.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return a workflow definition by key.
 *
 * @param string $workflow Workflow key.
 * @return array
 */
function dtb_admin_get_workflow_definition( string $workflow ): array {
	$workflow = sanitize_key( $workflow );
	$defs     = dtb_admin_get_workflow_definitions();

	return $defs[ $workflow ] ?? [
		'statuses'                  => [],
		'labels'                    => [],
		'terminal_statuses'         => [],
		'allowed_transitions'       => [],
		'queue_filters'             => [],
		'next_best_action_defaults' => [],
		'risk_states'               => [],
		'aliases'                   => [],
	];
}

/**
 * Return all registered workflow definitions.
 *
 * @return array<string,array>
 */
function dtb_admin_get_workflow_definitions(): array {
	static $defs = null;

	if ( null !== $defs ) {
		return $defs;
	}

	$support_labels      = function_exists( 'dtb_support_all_statuses' ) ? dtb_support_all_statuses() : [];
	$support_transitions = function_exists( 'dtb_support_allowed_transitions' ) ? dtb_support_allowed_transitions() : [];

	$return_statuses = class_exists( 'DTB_Return_Status' ) ? DTB_Return_Status::all() : [];
	$return_labels   = class_exists( 'DTB_Return_Status' ) ? DTB_Return_Status::labels() : [];
	$return_map      = [];
	foreach ( $return_statuses as $status ) {
		$return_map[ $status ] = function_exists( 'dtb_return_get_allowed_transitions' )
			? dtb_return_get_allowed_transitions( $status )
			: [];
	}

	$repair_statuses = function_exists( 'dtb_get_all_repair_statuses' ) ? dtb_get_all_repair_statuses() : [];
	$repair_labels   = [];
	foreach ( $repair_statuses as $status ) {
		$repair_labels[ $status ] = function_exists( 'dtb_get_repair_status_label' )
			? dtb_get_repair_status_label( $status )
			: ucwords( str_replace( '_', ' ', $status ) );
	}
	$repair_transitions = function_exists( 'dtb_get_allowed_transitions' ) ? dtb_get_allowed_transitions() : [];

	$order_map         = function_exists( 'dtb_order_get_status_map' ) ? dtb_order_get_status_map() : [];
	$order_labels      = [];
	$order_transitions = [];
	foreach ( $order_map as $status => $meta ) {
		$order_labels[ $status ]      = (string) ( $meta['label'] ?? ucwords( str_replace( '-', ' ', $status ) ) );
		$order_transitions[ $status ] = function_exists( 'dtb_order_get_allowed_transitions' )
			? dtb_order_get_allowed_transitions( $status )
			: [];
	}

	$defs = [
		'support_ticket' => [
			'statuses'                  => array_keys( $support_labels ),
			'labels'                    => $support_labels,
			'terminal_statuses'         => function_exists( 'dtb_support_terminal_statuses' ) ? dtb_support_terminal_statuses() : [],
			'allowed_transitions'       => $support_transitions,
			'queue_filters'             => [
				'open'        => [
					'label'    => __( 'Open', 'drywall-toolbox' ),
					'statuses' => [ 'open', 'pending_staff', 'in_progress' ],
				],
				'needs_reply' => [
					'label'    => __( 'Needs Reply', 'drywall-toolbox' ),
					'statuses' => [ 'pending_staff', 'open' ],
				],
				'closed'      => [
					'label'    => __( 'Closed', 'drywall-toolbox' ),
					'statuses' => [ 'resolved', 'closed' ],
				],
			],
			'next_best_action_defaults' => [
				'open'          => __( 'Review and assign', 'drywall-toolbox' ),
				'pending_staff' => __( 'Reply to customer', 'drywall-toolbox' ),
				'in_progress'   => __( 'Continue resolution', 'drywall-toolbox' ),
			],
			'risk_states'               => [ 'pending_staff' ],
			'aliases'                   => [
				'needs_reply' => 'pending_staff',
			],
		],
		'return'         => [
			'statuses'                  => $return_statuses,
			'labels'                    => $return_labels,
			'terminal_statuses'         => [ 'closed', 'rejected' ],
			'allowed_transitions'       => $return_map,
			'queue_filters'             => [
				'pending_review' => [
					'label'    => __( 'Pending Review', 'drywall-toolbox' ),
					'statuses' => [ 'pending_review' ],
				],
				'inspection'     => [
					'label'    => __( 'Inspection', 'drywall-toolbox' ),
					'statuses' => [ 'awaiting_item', 'item_received' ],
				],
				'refund_pending' => [
					'label'    => __( 'Refund Pending', 'drywall-toolbox' ),
					'statuses' => [ 'item_received' ],
				],
				'closed'         => [
					'label'    => __( 'Closed', 'drywall-toolbox' ),
					'statuses' => [ 'refund_issued', 'exchange_sent', 'closed', 'rejected' ],
				],
			],
			'next_best_action_defaults' => [
				'pending_review' => __( 'Review eligibility', 'drywall-toolbox' ),
				'awaiting_item'  => __( 'Wait for item receipt', 'drywall-toolbox' ),
				'item_received'  => __( 'Choose refund or exchange', 'drywall-toolbox' ),
			],
			'risk_states'               => [ 'pending_review', 'item_received' ],
			'aliases'                   => [],
		],
		'repair'         => [
			'statuses'                  => $repair_statuses,
			'labels'                    => $repair_labels,
			'terminal_statuses'         => function_exists( 'dtb_get_terminal_repair_statuses' ) ? dtb_get_terminal_repair_statuses() : [],
			'allowed_transitions'       => $repair_transitions,
			'queue_filters'             => [
				'review'        => [
					'label'    => __( 'Review', 'drywall-toolbox' ),
					'statuses' => [ 'submitted', 'reviewed', 'awaiting_customer' ],
				],
				'quote_pending' => [
					'label'    => __( 'Quote Pending', 'drywall-toolbox' ),
					'statuses' => [ 'approved', 'quoted', 'quote_accepted' ],
				],
				'in_progress'   => [
					'label'    => __( 'In Progress', 'drywall-toolbox' ),
					'statuses' => [ 'parts_allocated', 'in_progress' ],
				],
				'ready_to_ship' => [
					'label'    => __( 'Ready to Ship', 'drywall-toolbox' ),
					'statuses' => [ 'ready_to_ship' ],
				],
				'completed'     => [
					'label'    => __( 'Completed', 'drywall-toolbox' ),
					'statuses' => [ 'completed', 'closed' ],
				],
				'cancelled'     => [
					'label'    => __( 'Cancelled', 'drywall-toolbox' ),
					'statuses' => [ 'cancelled', 'quote_declined' ],
				],
			],
			'next_best_action_defaults' => [
				'submitted'       => __( 'Review intake', 'drywall-toolbox' ),
				'reviewed'        => __( 'Prepare quote or request details', 'drywall-toolbox' ),
				'quoted'          => __( 'Wait for quote decision', 'drywall-toolbox' ),
				'in_progress'     => __( 'Complete repair work', 'drywall-toolbox' ),
				'ready_to_ship'   => __( 'Prepare shipment', 'drywall-toolbox' ),
			],
			'risk_states'               => [ 'awaiting_customer', 'quoted' ],
			'aliases'                   => [
				'awaiting_review'          => 'submitted',
				'awaiting_quote_approval'  => 'quoted',
				'in_repair'                => 'in_progress',
			],
		],
		'product_order'  => [
			'statuses'                  => array_keys( $order_map ),
			'labels'                    => $order_labels,
			'terminal_statuses'         => function_exists( 'dtb_order_terminal_statuses' ) ? dtb_order_terminal_statuses() : [],
			'allowed_transitions'       => $order_transitions,
			'queue_filters'             => [
				'attention'  => [
					'label'    => __( 'Attention', 'drywall-toolbox' ),
					'statuses' => [ 'on-hold', 'failed', 'pending' ],
				],
				'failed'     => [
					'label'    => __( 'Failed', 'drywall-toolbox' ),
					'statuses' => [ 'failed' ],
				],
				'pending'    => [
					'label'    => __( 'Pending Payment', 'drywall-toolbox' ),
					'statuses' => [ 'pending' ],
				],
				'processing' => [
					'label'    => __( 'Processing', 'drywall-toolbox' ),
					'statuses' => [ 'processing' ],
				],
				'shipped'    => [
					'label'    => __( 'Shipped', 'drywall-toolbox' ),
					'statuses' => [ 'shipped' ],
				],
				'completed'  => [
					'label'    => __( 'Completed', 'drywall-toolbox' ),
					'statuses' => [ 'completed' ],
				],
			],
			'next_best_action_defaults' => [
				'on-hold'    => __( 'Review payment or fulfillment hold', 'drywall-toolbox' ),
				'failed'     => __( 'Review failed payment', 'drywall-toolbox' ),
				'processing' => __( 'Monitor fulfillment', 'drywall-toolbox' ),
				'shipped'    => __( 'Monitor delivery', 'drywall-toolbox' ),
			],
			'risk_states'               => [ 'on-hold', 'failed' ],
			'aliases'                   => [],
		],
		'repair_order'   => [
			'statuses'                  => array_keys( $order_map ),
			'labels'                    => $order_labels,
			'terminal_statuses'         => function_exists( 'dtb_order_terminal_statuses' ) ? dtb_order_terminal_statuses() : [],
			'allowed_transitions'       => $order_transitions,
			'queue_filters'             => [],
			'next_best_action_defaults' => [],
			'risk_states'               => [ 'on-hold', 'failed' ],
			'aliases'                   => [],
		],
	];

	return $defs;
}

/**
 * Normalize an alias or filter status into a canonical workflow status.
 *
 * @param string $workflow Workflow key.
 * @param string $status   Status or alias.
 * @return string
 */
function dtb_admin_normalize_workflow_status( string $workflow, string $status ): string {
	$status = sanitize_key( $status );
	$def    = dtb_admin_get_workflow_definition( $workflow );

	return (string) ( $def['aliases'][ $status ] ?? $status );
}

/**
 * Return canonical queue filters for a workflow.
 *
 * @param string $workflow Workflow key.
 * @return array<string,array{label:string,statuses:array<int,string>}>
 */
function dtb_admin_get_workflow_queue_filters( string $workflow ): array {
	$def     = dtb_admin_get_workflow_definition( $workflow );
	$filters = [];

	foreach ( (array) ( $def['queue_filters'] ?? [] ) as $key => $meta ) {
		$key = sanitize_key( (string) $key );
		if ( '' === $key ) {
			continue;
		}

		if ( is_array( $meta ) && isset( $meta['statuses'] ) ) {
			$statuses = array_values( array_filter( array_map( 'sanitize_key', (array) $meta['statuses'] ) ) );
			$label    = (string) ( $meta['label'] ?? ucwords( str_replace( '_', ' ', $key ) ) );
		} else {
			$statuses = array_values( array_filter( array_map( 'sanitize_key', (array) $meta ) ) );
			$label    = ucwords( str_replace( '_', ' ', $key ) );
		}

		$filters[ $key ] = [
			'label'    => $label,
			'statuses' => $statuses,
		];
	}

	return $filters;
}

/**
 * Normalize a queue filter key while preserving status aliases for old URLs.
 *
 * @param string $workflow Workflow key.
 * @param string $filter   Queue filter, status, or alias.
 * @return string
 */
function dtb_admin_normalize_workflow_queue_filter( string $workflow, string $filter ): string {
	$filter  = sanitize_key( str_replace( '-', '_', $filter ) );
	$filters = dtb_admin_get_workflow_queue_filters( $workflow );

	if ( isset( $filters[ $filter ] ) ) {
		return $filter;
	}

	$legacy_map = [
		'repair' => [
			'awaiting_review'          => 'review',
			'awaiting_quote'           => 'quote_pending',
			'awaiting_quote_approval'  => 'quote_pending',
			'in_repair'                => 'in_progress',
		],
		'return' => [
			'item_received' => 'inspection',
			'refund_issued' => 'closed',
		],
	];

	if ( isset( $legacy_map[ $workflow ][ $filter ] ) ) {
		return (string) $legacy_map[ $workflow ][ $filter ];
	}

	$status = dtb_admin_normalize_workflow_status( $workflow, $filter );
	foreach ( $filters as $key => $meta ) {
		if ( in_array( $status, (array) ( $meta['statuses'] ?? [] ), true ) ) {
			return $key;
		}
	}

	return '';
}

/**
 * Return statuses for a canonical queue filter.
 *
 * @param string $workflow Workflow key.
 * @param string $filter   Queue filter.
 * @return string[]
 */
function dtb_admin_get_workflow_queue_filter_statuses( string $workflow, string $filter ): array {
	$filter  = dtb_admin_normalize_workflow_queue_filter( $workflow, $filter );
	$filters = dtb_admin_get_workflow_queue_filters( $workflow );

	return array_values( (array) ( $filters[ $filter ]['statuses'] ?? [] ) );
}

/**
 * Return the display label for a workflow queue filter.
 *
 * @param string $workflow Workflow key.
 * @param string $filter   Queue filter.
 * @return string
 */
function dtb_admin_get_workflow_queue_filter_label( string $workflow, string $filter ): string {
	$filter  = dtb_admin_normalize_workflow_queue_filter( $workflow, $filter );
	$filters = dtb_admin_get_workflow_queue_filters( $workflow );

	return (string) ( $filters[ $filter ]['label'] ?? ucwords( str_replace( '_', ' ', $filter ) ) );
}

/**
 * Return allowed next statuses for a workflow status.
 *
 * @param string $workflow Workflow key.
 * @param string $status   Current status.
 * @return string[]
 */
function dtb_admin_get_allowed_workflow_transitions( string $workflow, string $status ): array {
	$status = dtb_admin_normalize_workflow_status( $workflow, $status );
	$def    = dtb_admin_get_workflow_definition( $workflow );

	return array_values( (array) ( $def['allowed_transitions'][ $status ] ?? [] ) );
}
