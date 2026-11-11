<?php
/**
 * REST — TicketAdminController: admin-only ticket list and detail endpoints.
 *
 * Routes:
 *   GET    /wp-json/dtb/v1/support/tickets
 *   GET    /wp-json/dtb/v1/support/tickets/(?P<id>\d+)
 *   PATCH  /wp-json/dtb/v1/support/tickets/(?P<id>\d+)
 *   GET    /wp-json/dtb/v1/support/kpis
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register admin ticket routes.
 */
function dtb_support_register_admin_ticket_routes(): void {
	// ── Ticket list ───────────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/tickets', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_list_tickets',
		'permission_callback' => 'dtb_support_read_permission',
		'args'                => [
			'status'   => [ 'type' => 'string',  'required' => false ],
			'type'     => [ 'type' => 'string',  'required' => false ],
			'priority' => [ 'type' => 'string',  'required' => false ],
			'queue'    => [ 'type' => 'string',  'required' => false ],
			'search'   => [ 'type' => 'string',  'required' => false ],
			'page'     => [ 'type' => 'integer', 'required' => false, 'default' => 1 ],
			'per_page' => [ 'type' => 'integer', 'required' => false, 'default' => 25 ],
			'order_by' => [ 'type' => 'string',  'required' => false, 'default' => 'created_at' ],
			'order'    => [ 'type' => 'string',  'required' => false, 'default' => 'DESC' ],
		],
	] );

	// ── Single ticket ─────────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_support_rest_get_ticket',
			'permission_callback' => 'dtb_support_read_permission',
		],
		[
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'dtb_support_rest_update_ticket',
			'permission_callback' => 'dtb_support_update_ticket_permission',
			'args'                => [
				'status'           => [ 'type' => 'string',  'required' => false ],
				'priority'         => [ 'type' => 'string',  'required' => false ],
				'note'             => [ 'type' => 'string',  'required' => false, 'default' => '' ],
			],
		],
	] );

	// ── KPI summary ───────────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/kpis', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_get_kpis',
		'permission_callback' => 'dtb_support_read_permission',
	] );

	// ── Ticket events (for inline expand) ─────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)/events', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_get_ticket_events',
		'permission_callback' => 'dtb_support_read_permission',
	] );

	// ── Queue projections ─────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/queues', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_get_queues',
		'permission_callback' => 'dtb_support_read_permission',
	] );

	// ── Workbench aggregate payload ───────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/workbench', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_get_workbench',
		'permission_callback' => 'dtb_support_read_permission',
		'args'                => [
			'status'   => [ 'type' => 'string',  'required' => false ],
			'queue'    => [ 'type' => 'string',  'required' => false ],
			'search'   => [ 'type' => 'string',  'required' => false ],
			'type'     => [ 'type' => 'string',  'required' => false ],
			'priority' => [ 'type' => 'string',  'required' => false ],
			'page'     => [ 'type' => 'integer', 'required' => false, 'default' => 1 ],
			'per_page' => [ 'type' => 'integer', 'required' => false, 'default' => 25 ],
		],
	] );

	// ── Snooze / unsnooze ─────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)/snooze', [
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_support_rest_snooze_ticket',
			'permission_callback' => 'dtb_support_status_change_permission',
			'args'                => [
				'snooze_until' => [ 'type' => 'string', 'required' => true ],
				'reason'       => [ 'type' => 'string', 'required' => false, 'default' => '' ],
			],
		],
		[
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'dtb_support_rest_unsnooze_ticket',
			'permission_callback' => 'dtb_support_status_change_permission',
		],
	] );

	// ── Follow-up due ─────────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)/followup', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_support_rest_set_followup',
		'permission_callback' => 'dtb_support_status_change_permission',
		'args'                => [
			'followup_due_at' => [ 'type' => 'string', 'required' => true ],
			'note'            => [ 'type' => 'string', 'required' => false, 'default' => '' ],
		],
	] );

	// ── Bulk actions ──────────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/bulk', [
		'methods'             => WP_REST_Server::CREATABLE,
		'callback'            => 'dtb_support_rest_bulk_action',
		'permission_callback' => 'dtb_support_bulk_action_permission',
		'args'                => [
			'ids'    => [ 'type' => 'array', 'required' => true, 'items' => [ 'type' => 'integer' ] ],
			'action' => [ 'type' => 'string', 'required' => true ],
			'value'  => [ 'type' => 'string', 'required' => false, 'default' => '' ],
		],
	] );

	// ── Macros ────────────────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/macros', [
		[
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'dtb_support_rest_list_macros',
			'permission_callback' => 'dtb_support_macro_permission',
		],
		[
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => 'dtb_support_rest_create_macro',
			'permission_callback' => 'dtb_support_macro_permission',
			'args'                => [
				'macro_name'       => [ 'type' => 'string', 'required' => false ],
				'subject_template' => [ 'type' => 'string', 'required' => false, 'default' => '' ],
				'body_template'    => [ 'type' => 'string', 'required' => false ],
				'category'         => [ 'type' => 'string', 'required' => false, 'default' => 'general' ],
				'is_active'        => [ 'type' => 'boolean', 'required' => false, 'default' => true ],
				'sort_order'       => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				'name'             => [ 'type' => 'string', 'required' => false ],
				'subject'          => [ 'type' => 'string', 'required' => false ],
				'body'             => [ 'type' => 'string', 'required' => false ],
			],
		],
	] );
	register_rest_route( 'dtb/v1', '/support/macros/(?P<id>\d+)', [
		[
			'methods'             => WP_REST_Server::EDITABLE,
			'callback'            => 'dtb_support_rest_update_macro',
			'permission_callback' => 'dtb_support_macro_permission',
			'args'                => [
				'macro_name'       => [ 'type' => 'string', 'required' => false ],
				'subject_template' => [ 'type' => 'string', 'required' => false ],
				'body_template'    => [ 'type' => 'string', 'required' => false ],
				'category'         => [ 'type' => 'string', 'required' => false ],
				'is_active'        => [ 'type' => 'boolean', 'required' => false ],
				'sort_order'       => [ 'type' => 'integer', 'required' => false ],
				'name'             => [ 'type' => 'string', 'required' => false ],
				'subject'          => [ 'type' => 'string', 'required' => false ],
				'body'             => [ 'type' => 'string', 'required' => false ],
			],
		],
		[
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => 'dtb_support_rest_delete_macro',
			'permission_callback' => 'dtb_support_macro_permission',
		],
	] );

	// ── Health / observability ────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/health', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_get_health',
		'permission_callback' => 'dtb_support_reports_permission',
	] );

	// ── Outbox status ─────────────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/outbox', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_get_outbox',
		'permission_callback' => 'dtb_support_reports_permission',
		'args'                => [
			'status'   => [ 'type' => 'string',  'required' => false, 'default' => 'failed' ],
			'per_page' => [ 'type' => 'integer', 'required' => false, 'default' => 25 ],
		],
	] );

	// ── Ticket intelligence ───────────────────────────────────────────────────
	register_rest_route( 'dtb/v1', '/support/tickets/(?P<id>\d+)/intelligence', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_get_ticket_intelligence',
		'permission_callback' => 'dtb_support_read_permission',
	] );
}
add_action( 'rest_api_init', 'dtb_support_register_admin_ticket_routes' );

/**
 * GET /dtb/v1/support/tickets/{id}/intelligence
 *
 * Returns scored priority, next best action, customer context, linked records,
 * delivery health, recommended macros, and risk flags for a single ticket.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_get_ticket_intelligence( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ticket_id = (int) $request->get_param( 'id' );
	$ticket    = dtb_support_get_ticket( $ticket_id );

	if ( ! $ticket ) {
		return new WP_Error( 'not_found', __( 'Ticket not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	// ── Priority score ──────────────────────────────────────────────────────
	$priority_score = function_exists( 'dtb_support_compute_priority_score' )
		? dtb_support_compute_priority_score( $ticket )
		: (int) ( $ticket->priority_score ?? 0 );

	// ── Next best action ────────────────────────────────────────────────────
	$next_action = function_exists( 'dtb_support_compute_next_action' )
		? dtb_support_compute_next_action( $ticket )
		: [ 'action' => 'review', 'label' => 'Review ticket', 'reason' => '' ];

	// ── Risk flags ──────────────────────────────────────────────────────────
	$risk_flags = function_exists( 'dtb_support_compute_risk_flags' )
		? dtb_support_compute_risk_flags( $ticket )
		: [];

	// ── Customer context ────────────────────────────────────────────────────
	$customer_context = function_exists( 'dtb_support_get_customer_context' )
		? dtb_support_get_customer_context( $ticket )
		: [];

	// ── Linked records ──────────────────────────────────────────────────────
	$linked_records = function_exists( 'dtb_admin_get_linked_records' )
		? dtb_admin_get_linked_records( 'support', $ticket_id )
		: [
			'order_id'   => ! empty( $ticket->order_id ) ? (int) $ticket->order_id : null,
			'repair_id'  => ! empty( $ticket->repair_id ) ? (int) $ticket->repair_id : null,
			'return_id'  => ! empty( $ticket->return_id ) ? (int) $ticket->return_id : null,
		];

	// ── Delivery health ─────────────────────────────────────────────────────
	$delivery_health = [
		'status'       => (string) ( $ticket->notification_status ?? 'unknown' ),
		'fail_count'   => (int) ( $ticket->notification_fail_count ?? 0 ),
		'last_sent_at' => ! empty( $ticket->notification_last_sent_at ) ? (string) $ticket->notification_last_sent_at : null,
	];

	// ── Recommended macros ──────────────────────────────────────────────────
	$all_macros = function_exists( 'dtb_support_get_macros' ) ? (array) dtb_support_get_macros() : [];
	$type       = (string) ( $ticket->ticket_type ?? 'general' );

	$recommended_macros = array_values( array_slice(
		array_filter( $all_macros, static function ( $macro ) use ( $type ): bool {
			$category = strtolower( (string) ( $macro->category ?? 'general' ) );
			return false !== strpos( $category, $type ) || 'general' === $category;
		} ),
		0,
		4
	) );
	$recommended_macros = array_map( static function ( $macro ) use ( $ticket ): array {
		$body_template    = (string) ( $macro->body_template ?? '' );
		$subject_template = (string) ( $macro->subject_template ?? '' );
		$rendered_body    = function_exists( 'dtb_support_render_macro' )
			? dtb_support_render_macro( $body_template, $ticket )
			: $body_template;

		return [
			'id'               => (int) ( $macro->id ?? 0 ),
			'name'             => (string) ( $macro->macro_name ?? '' ),
			'macro_name'       => (string) ( $macro->macro_name ?? '' ),
			'label'            => (string) ( $macro->macro_name ?? '' ),
			'category'         => (string) ( $macro->category ?? 'general' ),
			'subject_template' => $subject_template,
			'body_template'    => $body_template,
			'body'             => wp_strip_all_tags( $rendered_body, false ),
			'text'             => wp_strip_all_tags( $rendered_body, false ),
		];
	}, $recommended_macros );

	return new WP_REST_Response( [
		'ok'                 => true,
		'ticket_id'          => $ticket_id,
		'priority_score'     => $priority_score,
		'next_action'        => $next_action,
		'customer_context'   => $customer_context,
		'linked_records'     => $linked_records,
		'delivery_health'    => $delivery_health,
		'recommended_macros' => $recommended_macros,
		'risk_flags'         => $risk_flags,
		'meta'               => [
			'updated_at' => gmdate( 'c' ),
		],
	], 200 );
}

/**
 * Permission check: user must be logged in and have manage_support capability.
 *
 * @return bool|WP_Error
 */
function dtb_support_admin_permission(): bool|WP_Error {
	return dtb_support_rest_require_capabilities(
		[ 'dtb_manage_support' ],
		__( 'You do not have permission to manage support tickets.', 'drywall-toolbox' )
	);
}

/**
 * Check whether the current user has any of the provided support capabilities.
 *
 * @param string[] $caps Capability names.
 */
function dtb_support_user_can_any( array $caps ): bool {
	if ( current_user_can( 'manage_options' ) ) {
		return true;
	}

	foreach ( $caps as $cap ) {
		if ( current_user_can( $cap ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Shared REST capability gate for support routes.
 *
 * @param string[] $caps Capability names.
 */
function dtb_support_rest_require_capabilities( array $caps, string $message ): bool|WP_Error {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_forbidden', __( 'Authentication required.', 'drywall-toolbox' ), [ 'status' => 401 ] );
	}

	if ( ! dtb_support_user_can_any( $caps ) ) {
		return new WP_Error( 'rest_forbidden', $message, [ 'status' => 403 ] );
	}

	return true;
}

/**
 * Read access to support tickets.
 */
function dtb_support_read_permission(): bool|WP_Error {
	return dtb_support_rest_require_capabilities(
		[ 'dtb_read_support_tickets', 'dtb_manage_support' ],
		__( 'You do not have permission to view support tickets.', 'drywall-toolbox' )
	);
}

/**
 * Read access to reporting and health endpoints.
 */
function dtb_support_reports_permission(): bool|WP_Error {
	return dtb_support_rest_require_capabilities(
		[ 'dtb_view_support_reports', 'dtb_manage_support' ],
		__( 'You do not have permission to view support reporting.', 'drywall-toolbox' )
	);
}

/**
 * Macro management access.
 */
function dtb_support_macro_permission(): bool|WP_Error {
	return dtb_support_rest_require_capabilities(
		[ 'dtb_manage_support_macros', 'dtb_manage_support' ],
		__( 'You do not have permission to manage support macros.', 'drywall-toolbox' )
	);
}

/**
 * Status-style operations used for snooze and follow-up actions.
 */
function dtb_support_status_change_permission(): bool|WP_Error {
	return dtb_support_rest_require_capabilities(
		[ 'dtb_change_support_status', 'dtb_manage_support' ],
		__( 'You do not have permission to change support ticket status.', 'drywall-toolbox' )
	);
}

/**
 * Check granular patch permissions based on requested fields.
 */
function dtb_support_update_ticket_permission( WP_REST_Request $request ): bool|WP_Error {
	$result = dtb_support_read_permission();
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$checks = [];
	if ( null !== $request->get_param( 'status' ) ) {
		$checks['dtb_change_support_status'] = __( 'You do not have permission to change support ticket status.', 'drywall-toolbox' );
	}
	if ( null !== $request->get_param( 'priority' ) ) {
		$checks['dtb_change_support_priority'] = __( 'You do not have permission to change support ticket priority.', 'drywall-toolbox' );
	}

	foreach ( $checks as $cap => $message ) {
		$allowed = dtb_support_rest_require_capabilities( [ $cap, 'dtb_manage_support' ], $message );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
	}

	return true;
}

/**
 * Check granular bulk action permissions.
 */
function dtb_support_bulk_action_permission( WP_REST_Request $request ): bool|WP_Error {
	$result = dtb_support_read_permission();
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	$map = [
		'status'      => [ 'dtb_change_support_status', __( 'You do not have permission to change support ticket status.', 'drywall-toolbox' ) ],
		'set_status'  => [ 'dtb_change_support_status', __( 'You do not have permission to change support ticket status.', 'drywall-toolbox' ) ],
		'priority'    => [ 'dtb_change_support_priority', __( 'You do not have permission to change support ticket priority.', 'drywall-toolbox' ) ],
		'set_priority'=> [ 'dtb_change_support_priority', __( 'You do not have permission to change support ticket priority.', 'drywall-toolbox' ) ],
		'close'       => [ 'dtb_change_support_status', __( 'You do not have permission to change support ticket status.', 'drywall-toolbox' ) ],
		'spam'        => [ 'dtb_change_support_status', __( 'You do not have permission to change support ticket status.', 'drywall-toolbox' ) ],
		'snooze'      => [ 'dtb_change_support_status', __( 'You do not have permission to change support ticket status.', 'drywall-toolbox' ) ],
		'unsnooze'    => [ 'dtb_change_support_status', __( 'You do not have permission to change support ticket status.', 'drywall-toolbox' ) ],
	];

	if ( isset( $map[ $action ] ) ) {
		[ $cap, $message ] = $map[ $action ];
		return dtb_support_rest_require_capabilities( [ $cap, 'dtb_manage_support' ], $message );
	}

	return dtb_support_admin_permission();
}

/**
 * Return a human label for a support event type.
 */
function dtb_support_rest_event_label( string $event_type ): string {
	$labels = [
		'ticket.created'         => 'Ticket Created',
		'ticket.status_changed'  => 'Status Changed',
		'ticket.priority_changed'=> 'Priority Changed',
		'ticket.note_added'      => 'Internal Note',
		'ticket.reply_customer'  => 'Staff Reply',
		'ticket.reply_staff'     => 'Customer Reply',
		'ticket.snoozed'         => 'Snoozed',
		'ticket.unsnoozed'       => 'Unsnoozed',
		'ticket.email_sent'      => 'Email Sent',
		'ticket.email_failed'    => 'Email Failed',
		'ticket.tag_added'       => 'Tag Added',
		'ticket.tag_removed'     => 'Tag Removed',
		'ticket.resolved'        => 'Resolved',
		'ticket.reopened'        => 'Reopened',
		'ticket.closed'          => 'Closed',
		'ticket.merged'          => 'Merged',
		'ticket.spam_flagged'    => 'Marked as Spam',
		'ticket.macro_applied'   => 'Macro Applied',
		'ticket.automation_applied' => 'Automation Applied',
		'ticket.bulk_updated'    => 'Bulk Updated',
		'ticket.followup_set'    => 'Follow-up Set',
		'ticket.score_updated'   => 'Priority Score Updated',
	];

	if ( isset( $labels[ $event_type ] ) ) {
		return $labels[ $event_type ];
	}

	$event_type = str_replace( 'ticket.', '', $event_type );
	$event_type = str_replace( [ '.', '_' ], ' ', $event_type );
	return ucwords( trim( $event_type ) );
}

/**
 * Group event types for timeline filtering.
 */
function dtb_support_rest_event_group( string $event_type, string $visibility = '' ): string {
	if ( 'ticket.note_added' === $event_type || 'operator' === $visibility ) {
		return 'internal';
	}

	if ( in_array( $event_type, [ 'ticket.reply_customer', 'ticket.reply_staff' ], true ) ) {
		return 'message';
	}

	if ( in_array( $event_type, [
		'ticket.status_changed',
		'ticket.resolved',
		'ticket.reopened',
		'ticket.closed',
		'ticket.spam_flagged',
		'ticket.priority_changed',
		'ticket.snoozed',
		'ticket.unsnoozed',
		'ticket.followup_set',
	], true ) ) {
		return 'workflow';
	}

	if ( in_array( $event_type, [ 'ticket.email_sent', 'ticket.email_failed' ], true ) ) {
		return 'delivery';
	}

	return 'system';
}

/**
 * Build a readable summary for event cards.
 */
function dtb_support_rest_event_summary( array $event ): string {
	$body = trim( (string) ( $event['body'] ?? '' ) );
	if ( function_exists( 'dtb_str_normalize_display' ) ) {
		$body = dtb_str_normalize_display( $body, true );
	}
	if ( '' !== $body ) {
		return $body;
	}

	$event_type  = (string) ( $event['event_type'] ?? '' );
	$from_status = (string) ( $event['from_status'] ?? '' );
	$to_status   = (string) ( $event['to_status'] ?? '' );
	$payload     = is_array( $event['payload'] ?? null ) ? $event['payload'] : [];

	switch ( $event_type ) {
		case 'ticket.status_changed':
		case 'ticket.resolved':
		case 'ticket.reopened':
		case 'ticket.closed':
		case 'ticket.spam_flagged':
			if ( '' !== $from_status || '' !== $to_status ) {
				$from_label = '' !== $from_status ? dtb_support_status_label( $from_status ) : 'Unknown';
				$to_label   = '' !== $to_status ? dtb_support_status_label( $to_status ) : 'Unknown';
				return sprintf( 'Status changed from %s to %s.', $from_label, $to_label );
			}
			break;

		case 'ticket.snoozed':
			$until  = (string) ( $payload['snooze_until'] ?? '' );
			$reason = trim( (string) ( $payload['reason'] ?? '' ) );
			if ( '' !== $until && '' !== $reason ) {
				return sprintf( 'Snoozed until %s. Reason: %s', $until, $reason );
			}
			if ( '' !== $until ) {
				return sprintf( 'Snoozed until %s.', $until );
			}
			break;

		case 'ticket.unsnoozed':
			$previous = (string) ( $payload['was_snooze_until'] ?? '' );
			return '' !== $previous ? sprintf( 'Snooze removed (was %s).', $previous ) : 'Snooze removed.';

		case 'ticket.followup_set':
			$followup = (string) ( $payload['followup_due_at'] ?? '' );
			return '' !== $followup ? sprintf( 'Follow-up due at %s.', $followup ) : 'Follow-up schedule updated.';

		case 'ticket.score_updated':
			$old_score = isset( $payload['old_score'] ) ? (int) $payload['old_score'] : null;
			$new_score = isset( $payload['new_score'] ) ? (int) $payload['new_score'] : null;
			if ( null !== $old_score && null !== $new_score ) {
				return sprintf( 'Priority score changed from %d to %d.', $old_score, $new_score );
			}
			break;

		case 'ticket.email_sent':
			$recipient = (string) ( $payload['recipient_email'] ?? '' );
			return '' !== $recipient ? sprintf( 'Email sent to %s.', $recipient ) : 'Email sent successfully.';

		case 'ticket.email_failed':
			$error = trim( (string) ( $payload['error'] ?? '' ) );
			return '' !== $error ? sprintf( 'Email delivery failed: %s', $error ) : 'Email delivery failed.';
	}

	if ( '' !== $from_status || '' !== $to_status ) {
		$from = '' !== $from_status ? $from_status : 'unknown';
		$to   = '' !== $to_status ? $to_status : 'unknown';
		return sprintf( '%s -> %s', $from, $to );
	}

	return dtb_support_rest_event_label( $event_type );
}

/**
 * Normalize and enrich event rows for operator-facing UIs.
 *
 * @param int      $ticket_id Ticket ID.
 * @param object[] $events    Raw event rows.
 * @return array[]
 */
function dtb_support_rest_prepare_ticket_events( int $ticket_id, array $events ): array {
	$ticket = dtb_support_get_ticket( $ticket_id );
	$now    = time();
	$out    = [];

	foreach ( $events as $ev ) {
		$event = is_object( $ev ) ? get_object_vars( $ev ) : (array) $ev;

		if ( ! isset( $event['payload'] ) ) {
			$decoded = [];
			if ( ! empty( $event['payload_json'] ) ) {
				$decoded = json_decode( (string) $event['payload_json'], true );
			}
			$event['payload'] = is_array( $decoded ) ? $decoded : [];
		}

		// Backfill legacy ticket.created events that stored message only on the ticket row.
		if (
			'ticket.created' === (string) ( $event['event_type'] ?? '' ) &&
			'' === trim( (string) ( $event['body'] ?? '' ) ) &&
			$ticket &&
			! empty( $ticket->message )
		) {
			$event['body'] = (string) $ticket->message;
		}

		$actor_type = (string) ( $event['actor_type'] ?? '' );
		if ( 'customer' === $actor_type ) {
			$event['actor_label'] = $ticket ? (string) $ticket->customer_name : 'Customer';
		} elseif ( ! empty( $event['actor_id'] ) ) {
			$user = get_userdata( (int) $event['actor_id'] );
			$event['actor_label'] = $user ? (string) $user->display_name : 'Staff';
		} else {
			$event['actor_label'] = 'System';
		}

		$event['event_label'] = dtb_support_rest_event_label( (string) ( $event['event_type'] ?? '' ) );
		$event['event_group'] = dtb_support_rest_event_group(
			(string) ( $event['event_type'] ?? '' ),
			(string) ( $event['visibility'] ?? '' )
		);
		$event['summary'] = dtb_support_rest_event_summary( $event );
		if ( function_exists( 'dtb_str_normalize_display' ) ) {
			$event['actor_label'] = dtb_str_normalize_display( (string) ( $event['actor_label'] ?? '' ) );
			$event['summary'] = dtb_str_normalize_display( (string) ( $event['summary'] ?? '' ), true );
			if ( isset( $event['body'] ) && is_string( $event['body'] ) ) {
				$event['body'] = dtb_str_normalize_display( $event['body'], true );
			}
		}

		$created_at = (string) ( $event['created_at'] ?? '' );
		if ( '' !== $created_at ) {
			$timestamp = strtotime( $created_at );
			if ( false !== $timestamp ) {
				$event['age_label'] = dtb_support_age_label( max( 0, $now - $timestamp ) );
				$event['created_at_iso'] = gmdate( 'c', $timestamp );
			} else {
				$event['age_label'] = '';
				$event['created_at_iso'] = null;
			}
		} else {
			$event['age_label'] = '';
			$event['created_at_iso'] = null;
		}

		$out[] = $event;
	}

	return $out;
}

/**
 * GET /dtb/v1/support/tickets
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function dtb_support_rest_list_tickets( WP_REST_Request $request ): WP_REST_Response {
	$params = $request->get_params();

	$query_args = [
		'status'   => $params['status']   ?? '',
		'type'     => $params['type']     ?? '',
		'priority' => $params['priority'] ?? '',
		'search'   => $params['search']   ?? '',
		'page'     => (int) ( $params['page']     ?? 1 ),
		'per_page' => (int) ( $params['per_page'] ?? 25 ),
		'order_by' => $params['order_by'] ?? 'created_at',
		'order'    => strtoupper( $params['order'] ?? 'DESC' ) === 'ASC' ? 'ASC' : 'DESC',
	];

	$queue = ! empty( $params['queue'] ) ? sanitize_key( (string) $params['queue'] ) : '';
	$smart_queues = [
		'needs_reply',
		'overdue',
		'due_soon',
		'urgent',
		'snoozed',
		'in_progress',
		'waiting_on_customer',
		'resolved_pending_close',
		'all_active',
	];

	if ( in_array( $queue, $smart_queues, true ) ) {
		$result = dtb_support_query_queue( $queue, $query_args );
	} else {
		$result = dtb_support_query_tickets( $query_args );
	}
	$projected = array_map( 'dtb_support_project_ticket', $result['tickets'] );

	return new WP_REST_Response( [
		'tickets'    => $projected,
		'total'      => $result['total'],
		'page'       => $result['page'],
		'per_page'   => $result['per_page'],
		'page_count' => $result['page_count'],
	], 200 );
}

/**
 * GET /dtb/v1/support/tickets/{id}
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_get_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ticket_id = (int) $request->get_param( 'id' );
	$ticket    = dtb_support_get_ticket( $ticket_id );

	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$events = dtb_support_get_events( $ticket_id, 'operator' );
	$events = dtb_support_rest_prepare_ticket_events( $ticket_id, (array) $events );
	$detail = dtb_support_build_workbench_detail_payload( $ticket, $events );

	return new WP_REST_Response( $detail, 200 );
}

/**
 * PATCH /dtb/v1/support/tickets/{id}
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_update_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ticket_id = (int) $request->get_param( 'id' );
	$ticket    = dtb_support_get_ticket( $ticket_id );

	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$actor_id = get_current_user_id();

	// Status transition.
	if ( ! empty( $request['status'] ) && $request['status'] !== $ticket->status ) {
		$next_status = sanitize_key( (string) $request['status'] );
		$note        = sanitize_textarea_field( (string) ( $request['note'] ?? '' ) );
		if ( in_array( $next_status, [ 'resolved', 'resolved_pending_close', 'closed' ], true ) && '' === trim( $note ) ) {
			return new WP_REST_Response( [
				'success' => false,
				'message' => __( 'A resolution note is required before closing or resolving a support ticket.', 'drywall-toolbox' ),
			], 422 );
		}

		$result = dtb_support_do_transition( $ticket_id, $next_status, $note, $actor_id );
		if ( is_wp_error( $result ) ) {
			return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 422 );
		}
	}

	// Priority update.
	if ( ! empty( $request['priority'] ) && dtb_support_is_valid_priority( $request['priority'] ) ) {
		dtb_support_update_ticket( $ticket_id, [ 'priority' => $request['priority'] ] );
	}

	$fresh = dtb_support_get_ticket( $ticket_id );
	$events = dtb_support_get_events( $ticket_id, 'operator' );
	$events = dtb_support_rest_prepare_ticket_events( $ticket_id, (array) $events );
	$detail = $fresh ? dtb_support_build_workbench_detail_payload( $fresh, $events ) : [];

	return new WP_REST_Response( [
		'success' => true,
		'ticket'  => dtb_support_project_ticket( $fresh ),
		'detail'  => $detail,
	], 200 );
}

/**
 * Build the canonical support workbench detail payload while preserving aliases.
 *
 * @param object $ticket Ticket row/entity.
 * @param array  $events Prepared operator events.
 * @return array
 */
function dtb_support_build_admin_actions( object $ticket, array $workflow_def, array $allowed, array $permissions ): array {
	$labels  = (array) ( $workflow_def['labels'] ?? [] );
	$actions = [];

	if ( ! empty( $permissions['can_transition'] ) ) {
		foreach ( $allowed as $target ) {
			$target = sanitize_key( (string) $target );
			if ( '' === $target ) {
				continue;
			}
			$actions[] = [
				'id'            => 'support_transition_' . $target,
				'type'          => 'transition',
				'action_type'   => 'transition',
				'target_status' => $target,
				'group'         => 'Workflow',
				'label'         => sprintf(
					/* translators: %s: destination status label. */
					__( 'Move to %s', 'drywall-toolbox' ),
					(string) ( $labels[ $target ] ?? ucwords( str_replace( '_', ' ', $target ) ) )
				),
				'description'   => __( 'Update ticket status and preserve the operator timeline.', 'drywall-toolbox' ),
				'confirm'       => in_array( $target, [ 'closed', 'spam' ], true ),
			];
		}
		$actions[] = [
			'id'          => 'set_priority',
			'type'        => 'form_action',
			'action_type' => 'set_priority',
			'group'       => 'Triage',
			'label'       => __( 'Set Priority', 'drywall-toolbox' ),
			'description' => __( 'Adjust operational priority for queue routing and SLA visibility.', 'drywall-toolbox' ),
		];
		$actions[] = [
			'id'          => 'set_followup',
			'type'        => 'form_action',
			'action_type' => 'set_followup',
			'group'       => 'Triage',
			'label'       => __( 'Set Follow-Up', 'drywall-toolbox' ),
			'description' => __( 'Schedule a follow-up date and note for staff queues.', 'drywall-toolbox' ),
		];
		$actions[] = [
			'id'          => 'snooze_ticket',
			'type'        => 'form_action',
			'action_type' => 'snooze',
			'group'       => 'Triage',
			'label'       => __( 'Snooze Ticket', 'drywall-toolbox' ),
			'description' => __( 'Temporarily suppress the ticket until a specific date or customer dependency.', 'drywall-toolbox' ),
		];
		if ( ! empty( $ticket->snooze_until ) ) {
			$actions[] = [
				'id'          => 'unsnooze_ticket',
				'type'        => 'server_action',
				'action_type' => 'unsnooze',
				'group'       => 'Triage',
				'label'       => __( 'Unsnooze Ticket', 'drywall-toolbox' ),
				'description' => __( 'Return this ticket to the active support queue.', 'drywall-toolbox' ),
			];
		}
	}

	if ( ! empty( $permissions['can_reply'] ) ) {
		$actions[] = [
			'id'          => 'reply_customer',
			'type'        => 'form_action',
			'action_type' => 'reply',
			'group'       => 'Communication',
			'label'       => __( 'Reply to Customer', 'drywall-toolbox' ),
			'description' => __( 'Send a staff response and notify the customer.', 'drywall-toolbox' ),
		];
	}

	if ( ! empty( $permissions['can_note'] ) ) {
		$actions[] = [
			'id'          => 'internal_note',
			'type'        => 'form_action',
			'action_type' => 'internal_note',
			'group'       => 'Communication',
			'label'       => __( 'Add Internal Note', 'drywall-toolbox' ),
			'description' => __( 'Record operator-only context without notifying the customer.', 'drywall-toolbox' ),
		];
		$actions[] = [
			'id'          => 'apply_macro',
			'type'        => 'form_action',
			'action_type' => 'macro',
			'group'       => 'Communication',
			'label'       => __( 'Use Macro', 'drywall-toolbox' ),
			'description' => __( 'Insert a saved support response template for faster handling.', 'drywall-toolbox' ),
		];
	}

	return $actions;
}

function dtb_support_build_workbench_detail_payload( object $ticket, array $events ): array {
	$ticket_id = (int) ( $ticket->id ?? 0 );
	$record    = dtb_support_project_ticket( $ticket );
	$status    = sanitize_key( (string) ( $ticket->status ?? $record['status'] ?? '' ) );

	$workflow_def = function_exists( 'dtb_admin_get_workflow_definition' )
		? dtb_admin_get_workflow_definition( 'support_ticket' )
		: [];
	$allowed = function_exists( 'dtb_admin_get_allowed_workflow_transitions' )
		? dtb_admin_get_allowed_workflow_transitions( 'support_ticket', $status )
		: ( function_exists( 'dtb_support_allowed_transitions' ) ? ( dtb_support_allowed_transitions()[ $status ] ?? [] ) : [] );

	$customer = function_exists( 'dtb_admin_get_customer_context' )
		? dtb_admin_get_customer_context( [
			'customer_email'   => sanitize_email( (string) ( $ticket->customer_email ?? '' ) ),
			'customer_user_id' => absint( $ticket->customer_user_id ?? 0 ),
			'order_id'         => absint( $ticket->order_id ?? 0 ),
			'exclude_module'   => 'support',
		] )
		: [];
	$linked = function_exists( 'dtb_admin_get_linked_records' )
		? dtb_admin_get_linked_records( 'support', $ticket_id )
		: [];

	$intel_request = new WP_REST_Request( 'GET', '/dtb/v1/support/tickets/' . $ticket_id . '/intelligence' );
	$intel_request->set_param( 'id', $ticket_id );
	$intel_response = dtb_support_rest_get_ticket_intelligence( $intel_request );
	$intel_data = $intel_response instanceof WP_REST_Response ? $intel_response->get_data() : [];
	$integrations = function_exists( 'dtb_admin_get_integration_state' )
		? dtb_admin_get_integration_state( 'support', $ticket_id, [
			'order_id'                   => absint( $ticket->order_id ?? 0 ),
			'notification_status'        => (string) ( $ticket->notification_status ?? '' ),
			'notification_fail_count'    => absint( $ticket->notification_fail_count ?? 0 ),
			'notification_last_sent_at'  => $ticket->notification_last_sent_at ?? null,
		] )
		: [ 'email' => $intel_data['delivery_health'] ?? [] ];
	$timeline = function_exists( 'dtb_admin_get_timeline' )
		? dtb_admin_get_timeline( 'support', $ticket_id, [ 'events' => $events ] )
		: $events;

	$permissions = [
		'can_transition' => dtb_support_user_can_any( [ 'dtb_change_support_status', 'dtb_manage_support' ] ),
		'can_reply'      => dtb_support_user_can_any( [ 'dtb_reply_support_tickets', 'dtb_manage_support' ] ),
		'can_note'       => dtb_support_user_can_any( [ 'dtb_add_support_notes', 'dtb_manage_support' ] ),
	];

	$payload = [
		'ok'             => true,
		'record'         => $record,
		'ticket'         => $record, // TODO: remove after support JS reads record only.
		'customer'       => $customer,
		'customer_context' => $intel_data['customer_context'] ?? $customer, // TODO: remove after support JS reads customer only.
		'linked_records' => $linked,
		'workflow'       => [
			'key'                 => 'support_ticket',
			'status'              => $status,
			'label'               => (string) ( $workflow_def['labels'][ $status ] ?? ( function_exists( 'dtb_support_status_label' ) ? dtb_support_status_label( $status ) : $status ) ),
			'all_statuses'        => array_values( (array) ( $workflow_def['statuses'] ?? [] ) ),
			'labels'              => (array) ( $workflow_def['labels'] ?? [] ),
			'terminal_statuses'   => array_values( (array) ( $workflow_def['terminal_statuses'] ?? [] ) ),
			'allowed_transitions' => array_values( (array) $allowed ),
		],
		'intelligence'   => [
			'priority_score'     => $intel_data['priority_score'] ?? null,
			'next_action'        => $intel_data['next_action'] ?? null,
			'recommended_macros' => $intel_data['recommended_macros'] ?? [],
			'risk_flags'         => $intel_data['risk_flags'] ?? [],
		],
		'communication'  => [
			'delivery_health' => $intel_data['delivery_health'] ?? [],
		],
		'integrations'   => $integrations,
		'timeline'       => $timeline,
		'events'         => $events, // TODO: remove after support JS reads timeline only.
		'actions'        => dtb_support_build_admin_actions( $ticket, $workflow_def, (array) $allowed, $permissions ),
		'permissions'    => $permissions,
		'meta'           => [
			'fetched_at'    => gmdate( 'c' ),
			'poll_after_ms' => 60000,
		],
	];

	return function_exists( 'dtb_admin_prepare_workbench_payload' )
		? dtb_admin_prepare_workbench_payload( $payload )
		: $payload;
}

/**
 * Build a fresh support detail payload for mutation responses.
 */
function dtb_support_rest_fresh_ticket_detail( int $ticket_id ): array {
	$fresh = dtb_support_get_ticket( $ticket_id );
	if ( ! $fresh ) {
		return [];
	}

	$events = dtb_support_get_events( $ticket_id, 'operator' );
	$events = dtb_support_rest_prepare_ticket_events( $ticket_id, (array) $events );

	return dtb_support_build_workbench_detail_payload( $fresh, $events );
}

/**
 * GET /dtb/v1/support/kpis
 *
 * @return WP_REST_Response
 */
function dtb_support_rest_get_kpis(): WP_REST_Response {
	return new WP_REST_Response( dtb_support_get_kpis(), 200 );
}

/**
 * GET /dtb/v1/support/tickets/{id}/events
 *
 * Returns the operator-visible event stream for inline expand.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_get_ticket_events( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$ticket_id = (int) $request->get_param( 'id' );

	if ( ! dtb_support_get_ticket( $ticket_id ) ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$events = dtb_support_get_events( $ticket_id, 'operator' );
	$events = dtb_support_rest_prepare_ticket_events( $ticket_id, (array) $events );

	return new WP_REST_Response( $events, 200 );
}

// ── v2 endpoint handlers ──────────────────────────────────────────────────────

/**
 * GET /dtb/v1/support/queues
 *
 * Returns named queue counts. Keys use operator-friendly names:
 * "overdue" (not "sla_breached"), "due_soon" (not "sla_at_risk").
 *
 * @return WP_REST_Response
 */
function dtb_support_rest_get_queues(): WP_REST_Response {
	$raw = function_exists( 'dtb_support_get_queue_counts' )
		? dtb_support_get_queue_counts()
		: [];
	$counts = function_exists( 'dtb_support_normalize_queue_counts' )
		? dtb_support_normalize_queue_counts( $raw )
		: $raw;

	$payload = $counts;
	$payload['counts'] = $counts;

	return new WP_REST_Response( $payload, 200 );
}

/**
 * GET /dtb/v1/support/workbench
 *
 * Aggregate payload for Support Admin shell queue + context synchronisation.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function dtb_support_rest_get_workbench( WP_REST_Request $request ): WP_REST_Response {
	$status = sanitize_key( (string) ( $request->get_param( 'status' ) ?? '' ) );
	$status = function_exists( 'dtb_support_admin_normalize_status' )
		? dtb_support_admin_normalize_status( $status )
		: $status;
	$queue = sanitize_key( (string) ( $request->get_param( 'queue' ) ?? '' ) );
	$search = sanitize_text_field( (string) ( $request->get_param( 'search' ) ?? '' ) );
	$type = sanitize_key( (string) ( $request->get_param( 'type' ) ?? '' ) );
	$priority = sanitize_key( (string) ( $request->get_param( 'priority' ) ?? '' ) );
	$page = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
	$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );

	$query_args = [
		'search'   => $search,
		'type'     => $type,
		'priority' => $priority,
		'page'     => $page,
		'per_page' => $per_page,
		'order_by' => 'created_at',
		'order'    => 'DESC',
	];

	if ( 'closed' === $queue ) {
		$query_args['status'] = 'closed';
		$result = dtb_support_query_tickets( $query_args );
	} elseif ( '' !== $queue ) {
		$result = dtb_support_query_queue( $queue, $query_args );
	} elseif ( 'needs-reply' === $status ) {
		$result = dtb_support_query_queue( 'needs_reply', $query_args );
	} elseif ( 'past-sla' === $status ) {
		$result = dtb_support_query_queue( 'overdue', $query_args );
	} elseif ( '' === $status || 'open' === $status ) {
		$result = dtb_support_query_queue( 'all_active', $query_args );
	} else {
		$query_args['status'] = '' !== $status ? $status : 'all';
		$result = dtb_support_query_tickets( $query_args );
	}

	$tickets = array_map(
		static function ( $ticket ): array {
			return is_object( $ticket ) ? dtb_support_project_ticket( $ticket ) : [];
		},
		(array) ( $result['tickets'] ?? [] )
	);

	$queues = function_exists( 'dtb_support_normalize_queue_counts' )
		? dtb_support_normalize_queue_counts( (array) dtb_support_get_queue_counts() )
		: (array) dtb_support_get_queue_counts();
	$status_counts = dtb_support_count_by_status();
	$queues['closed'] = (int) ( $status_counts['closed'] ?? 0 );
	$kpis = dtb_support_get_kpis();
	$macros = function_exists( 'dtb_support_get_macros' ) ? dtb_support_get_macros() : [];

	$macro_payload = array_map(
		static function ( $macro ): array {
			return [
				'id'       => (int) ( $macro->id ?? 0 ),
				'name'     => (string) ( $macro->macro_name ?? '' ),
				'category' => (string) ( $macro->category ?? 'general' ),
			];
		},
		is_array( $macros ) ? $macros : []
	);

	return new WP_REST_Response( [
		'queues'  => $queues,
		'kpis'    => $kpis,
		'tickets' => $tickets,
		'macros'  => $macro_payload,
		'meta'    => [
			'status'    => $status,
			'queue'     => $queue,
			'search'    => $search,
			'type'      => $type,
			'priority'  => $priority,
			'page'      => (int) ( $result['page'] ?? $page ),
			'per_page'  => (int) ( $result['per_page'] ?? $per_page ),
			'total'     => (int) ( $result['total'] ?? 0 ),
			'page_count'=> (int) ( $result['page_count'] ?? 1 ),
		],
	], 200 );
}

/**
 * POST /dtb/v1/support/tickets/{id}/snooze
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_snooze_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
$ticket_id = (int) $request->get_param( 'id' );
if ( ! dtb_support_get_ticket( $ticket_id ) ) {
return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
}

if ( ! function_exists( 'dtb_support_snooze_ticket' ) ) {
return new WP_Error( 'dtb_support_unavailable', __( 'Snooze service not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
}

$until  = sanitize_text_field( $request->get_param( 'snooze_until' ) );
$reason = sanitize_text_field( $request->get_param( 'reason' ) ?? '' );

$result = dtb_support_snooze_ticket( $ticket_id, $until, [
'reason'     => $reason,
'actor_id'   => get_current_user_id(),
'actor_type' => 'staff',
] );

if ( is_wp_error( $result ) ) {
return new WP_REST_Response( [ 'success' => false, 'message' => $result->get_error_message() ], 422 );
}

return new WP_REST_Response( [
'success' => true,
'ticket'  => dtb_support_project_ticket( dtb_support_get_ticket( $ticket_id ) ),
'detail'  => dtb_support_rest_fresh_ticket_detail( $ticket_id ),
], 200 );
}

/**
 * DELETE /dtb/v1/support/tickets/{id}/snooze
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_unsnooze_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
$ticket_id = (int) $request->get_param( 'id' );
if ( ! dtb_support_get_ticket( $ticket_id ) ) {
return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
}

if ( function_exists( 'dtb_support_unsnooze_ticket' ) ) {
dtb_support_unsnooze_ticket( $ticket_id, [
'actor_id'   => get_current_user_id(),
'actor_type' => 'staff',
'source'     => 'manual',
] );
}

return new WP_REST_Response( [
'success' => true,
'ticket'  => dtb_support_project_ticket( dtb_support_get_ticket( $ticket_id ) ),
'detail'  => dtb_support_rest_fresh_ticket_detail( $ticket_id ),
], 200 );
}

/**
 * POST /dtb/v1/support/tickets/{id}/followup
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_set_followup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
$ticket_id = (int) $request->get_param( 'id' );
if ( ! dtb_support_get_ticket( $ticket_id ) ) {
return new WP_Error( 'dtb_support_not_found', __( 'Ticket not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
}

$due_at = sanitize_text_field( $request->get_param( 'followup_due_at' ) );
$note   = sanitize_text_field( $request->get_param( 'note' ) ?? '' );

if ( function_exists( 'dtb_support_set_followup' ) ) {
dtb_support_set_followup( $ticket_id, $due_at, [
'note'       => $note,
'actor_id'   => get_current_user_id(),
'actor_type' => 'staff',
] );
} else {
dtb_support_update_ticket( $ticket_id, [ 'followup_due_at' => $due_at ] );
}

return new WP_REST_Response( [
'success' => true,
'ticket'  => dtb_support_project_ticket( dtb_support_get_ticket( $ticket_id ) ),
'detail'  => dtb_support_rest_fresh_ticket_detail( $ticket_id ),
], 200 );
}

/**
 * POST /dtb/v1/support/bulk
 *
 * Supported actions: status, priority, snooze, close, spam, delete.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_bulk_action( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$raw_ids = $request->get_param( 'ids' );
	if ( null === $raw_ids ) {
		$raw_ids = $request->get_param( 'ticket_ids' );
	}

	$ids    = array_filter( array_map( 'absint', (array) $raw_ids ) );
	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	$value  = sanitize_text_field( $request->get_param( 'value' ) ?? '' );

	if ( 'set_status' === $action ) {
		$action = 'status';
	} elseif ( 'set_priority' === $action ) {
		$action = 'priority';
	}

	if ( empty( $ids ) ) {
		return new WP_Error( 'dtb_support_invalid', __( 'No ticket IDs provided.', 'drywall-toolbox' ), [ 'status' => 400 ] );
	}

	$actor_id = get_current_user_id();
	$updated  = [];
	$errors   = [];

	foreach ( $ids as $ticket_id ) {
		$ticket = dtb_support_get_ticket( $ticket_id );
		if ( ! $ticket ) {
			$errors[] = $ticket_id;
			continue;
		}

		switch ( $action ) {
			case 'status':
				if ( dtb_support_is_valid_status( $value ) ) {
					$r = dtb_support_do_transition( $ticket_id, $value, '', $actor_id );
					if ( ! is_wp_error( $r ) ) {
						$updated[] = $ticket_id;
					} else {
						$errors[] = $ticket_id;
					}
				}
				break;

			case 'priority':
				if ( dtb_support_is_valid_priority( $value ) ) {
					dtb_support_update_ticket( $ticket_id, [ 'priority' => $value ] );
					if ( function_exists( 'dtb_support_update_ticket_priority_score' ) ) {
						dtb_support_update_ticket_priority_score( $ticket_id );
					}
					$updated[] = $ticket_id;
				}
				break;

			case 'close':
				$r = dtb_support_do_transition( $ticket_id, 'closed', '', $actor_id );
				if ( ! is_wp_error( $r ) ) {
					$updated[] = $ticket_id;
				} else {
					$errors[] = $ticket_id;
				}
				break;

			case 'spam':
				$r = dtb_support_do_transition( $ticket_id, 'spam', '', $actor_id );
				if ( ! is_wp_error( $r ) ) {
					$updated[] = $ticket_id;
				} else {
					$errors[] = $ticket_id;
				}
				break;

			case 'delete':
				$from_status = (string) ( $ticket->status ?? '' );
				$r = dtb_support_update_ticket( $ticket_id, [
					'status'    => 'deleted',
					'closed_at' => gmdate( 'Y-m-d H:i:s' ),
				] );
				if ( ! is_wp_error( $r ) ) {
					if ( function_exists( 'dtb_support_append_event' ) && function_exists( 'dtb_support_build_event' ) ) {
						dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.deleted', [
							'from_status' => $from_status,
							'to_status'   => 'deleted',
							'actor_type'  => 'staff',
							'actor_id'    => $actor_id,
							'source'      => 'bulk_action',
							'visibility'  => 'operator',
							'body'        => __( 'Ticket moved to trash by admin.', 'drywall-toolbox' ),
						] ) );
					}
					$updated[] = $ticket_id;
				} else {
					$errors[] = $ticket_id;
				}
				break;

			case 'unsnooze':
				if ( function_exists( 'dtb_support_unsnooze_ticket' ) ) {
					dtb_support_unsnooze_ticket( $ticket_id, [
						'actor_id'   => $actor_id,
						'actor_type' => 'staff',
						'source'     => 'bulk_action',
					] );
				}
				$updated[] = $ticket_id;
				break;

			default:
				$errors[] = $ticket_id;
		}
	}

	return new WP_REST_Response( [
		'success'   => count( $errors ) === 0,
		'updated'   => $updated,
		'processed' => $updated,
		'errors'    => $errors,
	], 200 );
}

/**
 * Normalize macro payloads to the schema-backed service contract.
 */
function dtb_support_rest_macro_payload( WP_REST_Request $request, ?object $existing = null ): array {
	$data = [];

	$macro_name = $request->get_param( 'macro_name' );
	if ( null === $macro_name ) {
		$macro_name = $request->get_param( 'name' );
	}
	if ( null !== $macro_name ) {
		$data['macro_name'] = sanitize_text_field( (string) $macro_name );
	} elseif ( $existing ) {
		$data['macro_name'] = (string) $existing->macro_name;
	}

	$subject_template = $request->get_param( 'subject_template' );
	if ( null === $subject_template ) {
		$subject_template = $request->get_param( 'subject' );
	}
	if ( null !== $subject_template ) {
		$data['subject_template'] = sanitize_text_field( (string) $subject_template );
	} elseif ( $existing ) {
		$data['subject_template'] = (string) $existing->subject_template;
	}

	$body_template = $request->get_param( 'body_template' );
	if ( null === $body_template ) {
		$body_template = $request->get_param( 'body' );
	}
	if ( null !== $body_template ) {
		$data['body_template'] = wp_kses_post( (string) $body_template );
	} elseif ( $existing ) {
		$data['body_template'] = (string) $existing->body_template;
	}

	if ( null !== $request->get_param( 'category' ) ) {
		$data['category'] = sanitize_text_field( (string) $request->get_param( 'category' ) );
	} elseif ( $existing ) {
		$data['category'] = (string) $existing->category;
	}

	if ( null !== $request->get_param( 'is_active' ) ) {
		$data['is_active'] = (bool) $request->get_param( 'is_active' );
	} elseif ( $existing ) {
		$data['is_active'] = (bool) $existing->is_active;
	}

	if ( null !== $request->get_param( 'sort_order' ) ) {
		$data['sort_order'] = (int) $request->get_param( 'sort_order' );
	} elseif ( $existing ) {
		$data['sort_order'] = (int) $existing->sort_order;
	}

	return $data;
}

/**
 * Format a macro row for REST responses.
 */
function dtb_support_rest_prepare_macro( object $macro ): array {
	$variables = json_decode( (string) ( $macro->variables ?? '[]' ), true );

	return [
		'id'               => (int) $macro->id,
		'macro_name'       => (string) $macro->macro_name,
		'subject_template' => (string) $macro->subject_template,
		'body_template'    => (string) $macro->body_template,
		'variables'        => is_array( $variables ) ? array_values( $variables ) : [],
		'category'         => (string) $macro->category,
		'is_active'        => ! empty( $macro->is_active ),
		'sort_order'       => (int) $macro->sort_order,
		'created_by'       => isset( $macro->created_by ) ? (int) $macro->created_by : null,
		'created_at'       => $macro->created_at ?? null,
		'updated_at'       => $macro->updated_at ?? null,
	];
}

/**
 * GET /dtb/v1/support/macros
 *
 * @return WP_REST_Response
 */
function dtb_support_rest_list_macros(): WP_REST_Response {
	$macros = function_exists( 'dtb_support_get_macros' ) ? dtb_support_get_macros() : [];
	$macros = array_map( 'dtb_support_rest_prepare_macro', $macros );
	return new WP_REST_Response( [ 'macros' => $macros ], 200 );
}

/**
 * POST /dtb/v1/support/macros
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_create_macro( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	if ( ! function_exists( 'dtb_support_save_macro' ) ) {
		return new WP_Error( 'dtb_support_unavailable', __( 'Macro service not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	}

	$macro_id = dtb_support_save_macro( dtb_support_rest_macro_payload( $request ) );
	if ( is_wp_error( $macro_id ) ) {
		return $macro_id;
	}

	$macro = function_exists( 'dtb_support_get_macro' ) ? dtb_support_get_macro( (int) $macro_id ) : null;
	if ( ! $macro ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Macro not found after save.', 'drywall-toolbox' ), [ 'status' => 500 ] );
	}

	return new WP_REST_Response( [ 'success' => true, 'macro' => dtb_support_rest_prepare_macro( $macro ) ], 201 );
}

/**
 * PUT /dtb/v1/support/macros/{id}
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_update_macro( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$macro_id = (int) $request->get_param( 'id' );
	$existing = function_exists( 'dtb_support_get_macro' ) ? dtb_support_get_macro( $macro_id ) : null;
	if ( ! $existing ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Macro not found.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	$result = function_exists( 'dtb_support_save_macro' )
		? dtb_support_save_macro( dtb_support_rest_macro_payload( $request, $existing ), $macro_id )
		: new WP_Error( 'dtb_support_unavailable', __( 'Macro service not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$macro = dtb_support_get_macro( $macro_id );
	return new WP_REST_Response( [ 'success' => true, 'macro' => dtb_support_rest_prepare_macro( $macro ) ], 200 );
}

/**
 * DELETE /dtb/v1/support/macros/{id}
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response|WP_Error
 */
function dtb_support_rest_delete_macro( WP_REST_Request $request ): WP_REST_Response|WP_Error {
	$macro_id = (int) $request->get_param( 'id' );
	$result   = function_exists( 'dtb_support_delete_macro' )
		? dtb_support_delete_macro( $macro_id )
		: new WP_Error( 'dtb_support_unavailable', __( 'Macro service not available.', 'drywall-toolbox' ), [ 'status' => 503 ] );

	if ( is_wp_error( $result ) ) {
		return 'dtb_support_not_found' === $result->get_error_code()
			? new WP_Error( 'dtb_support_not_found', $result->get_error_message(), [ 'status' => 404 ] )
			: $result;
	}

	return new WP_REST_Response( [ 'success' => true, 'deleted' => $macro_id ], 200 );
}

/**
 * GET /dtb/v1/support/health
 *
 * Observability endpoint: queue counts, overdue/due-soon tickets, email failures,
 * oldest active ticket, schema version, cron health.
 *
 * @return WP_REST_Response
 */
function dtb_support_rest_get_health(): WP_REST_Response {
	global $wpdb;
	$table = dtb_support_tickets_table();

	$kpis = dtb_support_get_kpis();

	$queues = function_exists( 'dtb_support_get_queue_counts' )
		? dtb_support_get_queue_counts()
		: [];
	$queue_out = function_exists( 'dtb_support_normalize_queue_counts' )
		? dtb_support_normalize_queue_counts( $queues )
		: $queues;

	$oldest = $wpdb->get_var(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT created_at FROM {$table} WHERE status NOT IN ('resolved','closed','spam','deleted') ORDER BY created_at ASC LIMIT 1"
	);
	$oldest_seconds = $oldest ? ( time() - strtotime( $oldest ) ) : null;

	$outbox_failed = 0;
	if ( ! empty( $wpdb->prefix ) ) {
		$outbox_table  = $wpdb->prefix . 'dtb_support_email_outbox';
		$outbox_failed = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$outbox_table} WHERE status = 'failed'"
		);
	}

	$next_scan = 0;
	foreach ( [ 'dtb_support_hourly_sla_scan', 'dtb_support_sla_hourly_scan', 'dtb_support_sla_scan_hourly' ] as $scan_hook ) {
		$scheduled = wp_next_scheduled( $scan_hook );
		if ( $scheduled ) {
			$next_scan = (int) $scheduled;
			break;
		}
	}
	$last_scan = get_option( 'dtb_support_last_sla_scan', '' );

	return new WP_REST_Response( [
		'schema_version'                  => $kpis['schema_version'] ?? '0',
		'total_open'                      => $kpis['active_total'] ?? 0,
		'needs_reply'                     => $kpis['needs_reply'] ?? 0,
		'overdue_count'                   => $kpis['overdue_count'] ?? 0,
		'due_soon_count'                  => $kpis['due_soon_count'] ?? 0,
		'email_failures'                  => $kpis['email_failures'] ?? 0,
		'outbox_failed'                   => $outbox_failed,
		'oldest_active_ticket_age_seconds'=> $oldest_seconds,
		'oldest_active_ticket_at'         => $oldest,
		'queues'                          => $queue_out,
		'cron'                            => [
			'operational_target_scan' => [
				'label'       => __( 'Operational Target Scan', 'drywall-toolbox' ),
				'next_run_at' => $next_scan ? gmdate( 'c', $next_scan ) : null,
				'last_run_at' => $last_scan ? gmdate( 'c', strtotime( (string) $last_scan ) ) : null,
			],
		],
		'generated_at'                    => gmdate( 'c' ),
	], 200 );
}

/**
 * GET /dtb/v1/support/outbox
 *
 * Returns outbox items filtered by status (default: failed).
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function dtb_support_rest_get_outbox( WP_REST_Request $request ): WP_REST_Response {
	global $wpdb;
	$table    = $wpdb->prefix . 'dtb_support_email_outbox';
	$status   = sanitize_key( $request->get_param( 'status' ) ?? 'failed' );
	$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

	$rows = $wpdb->get_results( $wpdb->prepare(
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		"SELECT id, ticket_id, recipient_email, recipient_name, subject, status, attempts, next_attempt_at, sent_at, last_error, created_at, updated_at
			FROM {$table}
			WHERE status = %s
			ORDER BY created_at DESC
			LIMIT %d",
		$status,
		$per_page
	) );

	return new WP_REST_Response( [ 'items' => $rows ?? [] ], 200 );
}
