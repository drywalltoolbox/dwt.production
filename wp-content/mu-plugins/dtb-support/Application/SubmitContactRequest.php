<?php
/**
 * Application — SubmitContactRequest: orchestrates new ticket creation from a contact-form submission.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Create a new support ticket from a validated contact form payload.
 *
 * @param array{
 *     name:    string,
 *     email:   string,
 *     subject: string,
 *     message: string,
 *     type?:   string,
 *     order_id?: int|string,
 *     product_id?: int|string,
 *     meta?:   array,
 * } $data  Validated, sanitised payload.
 * @return array{ticket_id:int, ticket_number:string}|WP_Error
 */
function dtb_support_submit_contact_request( array $data ): array|WP_Error {
	// Resolve type, defaulting to 'contact'.
	$ticket_type = ! empty( $data['type'] ) && dtb_support_is_valid_type( $data['type'] )
		? $data['type']
		: dtb_support_default_type();

	$ticket_data = [
		'ticket_type' => $ticket_type,
		'status'      => DTB_SUPPORT_STATUS_OPEN,
		'priority'    => dtb_support_default_priority(),
		'subject'     => sanitize_text_field( $data['subject'] ?? '' ),
		'customer_name'  => sanitize_text_field( $data['name']  ?? '' ),
		'customer_email' => sanitize_email( $data['email'] ?? '' ),
		'order_id'    => ! empty( $data['order_id'] )   ? absint( $data['order_id'] )   : null,
		'product_id'  => ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : null,
		'message'     => wp_kses_post( $data['message'] ?? '' ),
		'ip_address'  => sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' ),
		'user_agent'  => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' ),
		'meta'        => ! empty( $data['meta'] ) && is_array( $data['meta'] ) ? $data['meta'] : [],
		'source'      => $data['source'] ?? 'web_form',
	];

	$ticket_id = dtb_support_create_ticket( $ticket_data );

	if ( is_wp_error( $ticket_id ) ) {
		return $ticket_id;
	}

	if ( function_exists( 'dtb_support_stamp_ticket_sla' ) ) {
		dtb_support_stamp_ticket_sla( $ticket_id );
	}
	if ( function_exists( 'dtb_support_update_ticket_priority_score' ) ) {
		dtb_support_update_ticket_priority_score( $ticket_id );
	}

	$ticket = dtb_support_get_ticket( $ticket_id );
	if ( ! $ticket ) {
		return new WP_Error( 'dtb_support_not_found', __( 'Ticket could not be retrieved after creation.', 'drywall-toolbox' ) );
	}

	// Append the initial message as an event.
	dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.created', [
		'actor_type' => 'customer',
		'source'     => 'web_form',
		'visibility' => 'all',
		'body'       => $ticket_data['message'],
		'payload'    => [
			'subject'        => $ticket_data['subject'],
			'customer_name'  => $ticket_data['customer_name'],
			'customer_email' => $ticket_data['customer_email'],
		],
	] ) );

	// Notifications.
	dtb_support_notify_ticket_opened( $ticket );

	/**
	 * Fires after a new support ticket has been fully created and notified.
	 *
	 * @param int   $ticket_id
	 * @param array $ticket
	 */
	do_action( 'dtb_support_contact_request_submitted', $ticket_id, $ticket );

	return [
		'ticket_id'     => $ticket_id,
		'ticket_number' => $ticket->ticket_number,
		'public_token'  => function_exists( 'dtb_support_generate_public_reply_token' )
			? dtb_support_generate_public_reply_token( $ticket_id, (string) $ticket->customer_email )
			: '',
	];
}
