<?php
/**
 * Customer-facing support ticket history routes.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_support_register_customer_routes(): void {
	register_rest_route( 'dtb/v1', '/support/mine', [
		'methods'             => WP_REST_Server::READABLE,
		'callback'            => 'dtb_support_rest_customer_tickets',
		'permission_callback' => 'dtb_jwt_permission',
		'args'                => [
			'page'     => [ 'type' => 'integer', 'minimum' => 1, 'default' => 1 ],
			'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ],
		],
	] );
}
add_action( 'rest_api_init', 'dtb_support_register_customer_routes' );

function dtb_support_rest_customer_tickets( WP_REST_Request $request ): WP_REST_Response {
	$user = DTB_CurrentUserResolver::resolve_user();
	if ( ! $user ) {
		return new WP_REST_Response(
			[ 'code' => 'dtb_support_unauthorized', 'message' => __( 'Authentication required.', 'drywall-toolbox' ) ],
			401
		);
	}

	global $wpdb;
	$table    = dtb_support_tickets_table();
	$page     = max( 1, (int) $request->get_param( 'page' ) );
	$per_page = min( 50, max( 1, (int) $request->get_param( 'per_page' ) ) );
	$offset   = ( $page - 1 ) * $per_page;
	$email    = sanitize_email( $user->user_email );

	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$total = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE customer_email = %s AND status <> 'spam'", $email )
	);
	$tickets = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, ticket_number, status, ticket_type, priority, subject, order_id, created_at, updated_at
			FROM {$table}
			WHERE customer_email = %s AND status <> 'spam'
			ORDER BY updated_at DESC, id DESC
			LIMIT %d OFFSET %d",
			$email,
			$per_page,
			$offset
		)
	);
	// phpcs:enable

	$items = array_map(
		static function ( object $ticket ) use ( $email ): array {
			$status = (string) $ticket->status;
			return [
				'id'            => (int) $ticket->id,
				'ticket_number' => (string) $ticket->ticket_number,
				'status'        => $status,
				'status_label'  => 'pending_customer' === $status
					? __( 'Waiting on You', 'drywall-toolbox' )
					: ( 'pending_staff' === $status
						? __( 'Waiting on Support', 'drywall-toolbox' )
						: dtb_support_status_label( $status ) ),
				'ticket_type'   => (string) $ticket->ticket_type,
				'priority'      => (string) $ticket->priority,
				'subject'       => (string) $ticket->subject,
				'order_id'      => ! empty( $ticket->order_id ) ? (int) $ticket->order_id : null,
				'created_at'    => (string) $ticket->created_at,
				'updated_at'    => (string) $ticket->updated_at,
				'public_token'  => dtb_support_generate_public_reply_token(
					(int) $ticket->id,
					$email
				),
			];
		},
		(array) $tickets
	);

	return new WP_REST_Response( [
		'tickets'  => $items,
		'page'     => $page,
		'per_page' => $per_page,
		'total'    => $total,
		'has_more' => ( $page * $per_page ) < $total,
	], 200 );
}
