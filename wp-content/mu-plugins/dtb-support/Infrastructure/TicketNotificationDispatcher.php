<?php
/**
 * Infrastructure — TicketNotificationDispatcher: email templates and send logic.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// SECTION 1 — CONFIGURATION
// =============================================================================

/**
 * Return the from-name for support notification emails.
 *
 * Reads the saved Support Hub setting first, falls back to blog name.
 *
 * @return string
 */
function dtb_support_email_from_name(): string {
	$saved = (string) get_option( 'dtb_support_from_name', '' );
	$value = '' !== $saved ? $saved : get_bloginfo( 'name' );
	return (string) apply_filters( 'dtb_support_email_from_name', $value );
}

/**
 * Return the from-address for support notification emails.
 *
 * Reads the saved Support Hub setting first, falls back to site-derived address.
 *
 * @return string
 */
function dtb_support_email_from(): string {
	$saved = (string) get_option( 'dtb_support_from_email', '' );
	if ( '' !== $saved && is_email( $saved ) ) {
		return (string) apply_filters( 'dtb_support_email_from', $saved );
	}
	return (string) apply_filters( 'dtb_support_email_from', 'info@drywalltoolbox.com' );
}

/**
 * Return the admin/staff recipient address for notification emails.
 *
 * Reads the saved Support Hub setting first, falls back to WP admin_email.
 *
 * @return string
 */
function dtb_support_admin_email(): string {
	$saved = (string) get_option( 'dtb_support_admin_email', '' );
	$value = ( '' !== $saved && is_email( $saved ) ) ? $saved : 'info@drywalltoolbox.com';
	return (string) apply_filters( 'dtb_support_admin_email', $value );
}

// =============================================================================
// SECTION 1b — WP MAIL FROM HOOKS
// =============================================================================

/**
 * Override WordPress' default From address for all dtb-support emails.
 *
 * WP ignores From: headers unless these filters are set — this ensures our
 * configured address is actually used rather than wordpress@domain.
 */
add_filter( 'wp_mail_from',      'dtb_support_wp_mail_from'      );
add_filter( 'wp_mail_from_name', 'dtb_support_wp_mail_from_name' );

function dtb_support_wp_mail_from( string $original ): string {
	// Only override when we're inside a dtb-support send (guarded by flag).
	return defined( 'DTB_SUPPORT_SENDING' ) ? dtb_support_email_from() : $original;
}

function dtb_support_wp_mail_from_name( string $original ): string {
	return defined( 'DTB_SUPPORT_SENDING' ) ? dtb_support_email_from_name() : $original;
}

/**
 * Expand macro-style placeholders in outbound reply bodies.
 *
 * Supports both {{variable}} and {variable} tokens for compatibility with
 * historical macro content and UI-level macro insertion.
 */
function dtb_support_expand_reply_tokens( string $message, object $ticket, string $reply_link = '' ): string {
	$public_ticket_url = '' !== $reply_link ? $reply_link : dtb_support_public_status_url( $ticket );
	$values = [
		'customer_name'  => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( (string) ( $ticket->customer_name ?? '' ) ) : (string) ( $ticket->customer_name ?? '' ),
		'customer_email' => (string) ( $ticket->customer_email ?? '' ),
		'ticket_number'  => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( (string) ( $ticket->ticket_number ?? '' ) ) : (string) ( $ticket->ticket_number ?? '' ),
		'subject'        => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( (string) ( $ticket->subject ?? '' ) ) : (string) ( $ticket->subject ?? '' ),
		'order_id'       => ! empty( $ticket->order_id ) ? (string) $ticket->order_id : '',
		'site_name'      => function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( (string) get_bloginfo( 'name' ) ) : (string) get_bloginfo( 'name' ),
		'support_email'  => function_exists( 'dtb_support_email_from' ) ? dtb_support_email_from() : (string) get_option( 'admin_email', '' ),
		'ticket_url'       => $public_ticket_url,
		'admin_ticket_url' => admin_url( 'admin.php?page=dtb-support&ticket_id=' . (int) ( $ticket->id ?? 0 ) ),
	];

	$expanded = (string) preg_replace_callback(
		'/\{\{\s*([a-z0-9_]+)\s*\}\}|\{([a-z0-9_]+)\}/i',
		static function ( array $matches ) use ( $values ): string {
			$key = strtolower( (string) ( $matches[1] ?: $matches[2] ?: '' ) );
			return array_key_exists( $key, $values ) ? (string) $values[ $key ] : (string) $matches[0];
		},
		(string) $message
	);
	return function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $expanded, true ) : $expanded;
}

function dtb_support_public_status_url( object $ticket ): string {
	if ( empty( $ticket->id ) || empty( $ticket->customer_email ) || ! function_exists( 'dtb_support_generate_public_reply_token' ) ) {
		return home_url( '/support/status/' . (int) ( $ticket->id ?? 0 ) );
	}

	return add_query_arg(
		[ 'token' => dtb_support_generate_public_reply_token( (int) $ticket->id, (string) $ticket->customer_email ) ],
		home_url( '/support/status/' . (int) $ticket->id )
	);
}

function dtb_support_scrub_customer_admin_urls( string $body, string $status_url ): string {
	if ( '' === $status_url ) {
		return $body;
	}

	return (string) preg_replace(
		'#https?://[^\s<>"\']+/wp-admin/admin\.php\?page=dtb-support(?:&amp;|&)ticket_id=\d+#i',
		$status_url,
		$body
	);
}

// =============================================================================
// SECTION 2 — EMAIL BUILDER
// =============================================================================

/**
 * Build email headers array.
 *
 * @return string[]
 */
function dtb_support_email_headers( string $content_type = 'text/plain' ): array {
	return [
		'Content-Type: ' . sanitize_text_field( $content_type ) . '; charset=UTF-8',
		'From: ' . dtb_support_email_from_name() . ' <' . dtb_support_email_from() . '>',
	];
}

/**
 * Render an email template and return [subject, body] or WP_Error.
 *
 * @param string $template  Slug: 'ticket-opened-customer' | 'ticket-opened-admin' | 'ticket-reply-customer' |
 *                           'ticket-reply-staff' | 'ticket-resolved-customer' | 'ticket-reopened-customer'
 * @param array  $ctx       Template variables.
 * @return array{subject:string,body:string}|WP_Error
 */
function dtb_support_get_email_template( string $template, array $ctx ): array|WP_Error {
	$site_raw = sanitize_text_field( (string) ( $ctx['site_name'] ?? get_bloginfo( 'name' ) ) );
	$name_raw = sanitize_text_field( (string) ( $ctx['customer_name'] ?? 'Customer' ) );
	$tnum_raw = sanitize_text_field( (string) ( $ctx['ticket_number'] ?? '' ) );
	$subj_raw = sanitize_text_field( (string) ( $ctx['subject'] ?? 'Your enquiry' ) );
	$site = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $site_raw ) : $site_raw;
	$name = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $name_raw ) : $name_raw;
	$tnum = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $tnum_raw ) : $tnum_raw;
	$subj = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $subj_raw ) : $subj_raw;
	$theme = (string) apply_filters( 'dtb_support_email_theme', 'auto', $template, $ctx );
	$aurl   = esc_url_raw( (string) ( $ctx['admin_url'] ?? admin_url( 'admin.php?page=dtb-support' ) ) );
	$status_url = esc_url_raw( (string) ( $ctx['status_url'] ?? $ctx['reply_link'] ?? '' ) );

	switch ( $template ) {

		case 'ticket-opened-customer':
			$body = sprintf(
				"Hi %1\$s,\n\nThank you for reaching out to us. We've received your message and a member of our team will be in touch shortly.\n\nTicket Reference: %2\$s\nSubject: %3\$s\n\nTrack or reply to this ticket:\n%4\$s\n\nThanks,\n%5\$s Support Team",
				$name, $tnum, $subj, $status_url, $site
			);
			return [
				'subject' => sprintf( '[%1$s] We received your message — Ticket %2$s', $site, $tnum ),
				'body'    => $body,
				'html'    => dtb_render_branded_email(
					[
						'title'       => 'We received your message',
						'preheader'   => sprintf( 'Ticket %s has been opened with Drywall Toolbox support.', $tnum ),
						'greeting'    => sprintf( 'Hi %s,', $name ),
						'intro'       => 'Thanks for reaching out. Your message is in our support queue and a team member will follow up shortly.',
						'details'     => [
							[ 'label' => 'Ticket reference', 'value' => $tnum ],
							[ 'label' => 'Subject', 'value' => $subj ],
						],
						'cta_url'     => $status_url,
						'cta_label'   => $status_url ? 'Track this ticket' : '',
						'signoff'     => $site . ' Support Team',
						'footer_note' => 'You can reply directly to this email to add more information to your ticket.',
					]
				),
			];

		case 'ticket-opened-admin':
			return [
				'subject' => sprintf( '[%1$s] New support ticket %2$s — %3$s', $site, $tnum, $name ),
				'body'    => sprintf(
					"A new support ticket has been submitted.\n\nTicket: %1\$s\nCustomer: %2\$s\nEmail: %3\$s\nType: %4\$s\nPriority: %5\$s\nSubject: %6\$s\n\nMessage:\n%7\$s\n\nManage in WP-Admin:\n%8\$s",
					$tnum,
					$name,
					esc_html( $ctx['customer_email'] ?? '' ),
					esc_html( $ctx['ticket_type']    ?? 'contact' ),
					esc_html( $ctx['priority']       ?? 'normal' ),
					$subj,
					esc_html( wp_strip_all_tags( $ctx['message'] ?? '' ) ),
					$aurl
				),
			];

		case 'ticket-reply-customer':
			$reply_body_raw  = wp_strip_all_tags( (string) ( $ctx['reply_body'] ?? '' ) );
			$reply_body = function_exists( 'dtb_str_normalize_display' ) ? dtb_str_normalize_display( $reply_body_raw, true ) : $reply_body_raw;
			$reply_link  = esc_url_raw( (string) ( $ctx['reply_link'] ?? '' ) );
			$reply_body  = dtb_support_scrub_customer_admin_urls( $reply_body, $reply_link ?: $status_url );
			$reply_cta   = $reply_link ? "\n\nReply to this ticket:\n" . $reply_link : "\n\nReply directly to this email to continue the conversation.";
			$body        = sprintf(
				"Hi %1\$s,\n\nA member of our support team has replied to your ticket (%2\$s).\n\n---\n%3\$s\n---\n%4\$s\n\n%5\$s Support Team",
				$name,
				$tnum,
				$reply_body,
				$reply_cta,
				$site
			);
			return [
				'subject' => sprintf( 'Re: [%1$s] %2$s (Ticket %3$s)', $site, $subj, $tnum ),
				'body'    => $body,
				'html'    => dtb_render_branded_email(
					[
						'title'       => 'You have a new support reply',
						'preheader'   => sprintf( 'A Drywall Toolbox team member replied to ticket %s.', $tnum ),
						'greeting'    => sprintf( 'Hi %s,', $name ),
						'intro'       => sprintf( 'A member of our support team replied to your ticket %s.', esc_html( $tnum ) ),
						'details'     => [
							[ 'label' => 'Ticket reference', 'value' => $tnum ],
							[ 'label' => 'Subject', 'value' => $subj ],
						],
						'body_html'   => '<div style="white-space:pre-wrap;">' . nl2br( esc_html( $reply_body ) ) . '</div>',
						'cta_url'     => $reply_link,
						'cta_label'   => $reply_link ? 'Reply to this ticket' : '',
						'signoff'     => $site . ' Support Team',
						'footer_note' => 'Reply directly to this email or click the button above to continue the conversation.',
					]
				),
			];

		case 'ticket-reply-staff':
			return [
				'subject' => sprintf( '[%1$s] Customer replied to ticket %2$s', $site, $tnum ),
				'body'    => sprintf(
					"A customer has replied to ticket %1\$s.\n\nCustomer: %2\$s\nSubject: %3\$s\n\nMessage:\n%4\$s\n\nOpen ticket:\n%5\$s",
					$tnum,
					$name,
					$subj,
					esc_html( wp_strip_all_tags( $ctx['reply_body'] ?? '' ) ),
					$aurl
				),
			];

		case 'ticket-resolved-customer':
			$body = sprintf(
				"Hi %1\$s,\n\nWe're happy to let you know that your support ticket (%2\$s - %3\$s) has been marked as resolved.\n\nTrack or reply to this ticket:\n%4\$s\n\nIf you feel the issue is not fully resolved, please reply to this email and we'll reopen it for you.\n\n%5\$s Support Team",
				$name, $tnum, $subj, $status_url, $site
			);
			return [
				'subject' => sprintf( '[%1$s] Your ticket %2$s has been resolved', $site, $tnum ),
				'body'    => $body,
				'html'    => dtb_render_branded_email(
					[
						'title'       => 'Your ticket has been resolved',
						'preheader'   => sprintf( 'Ticket %s has been marked as resolved.', $tnum ),
						'greeting'    => sprintf( 'Hi %s,', $name ),
						'intro'       => 'Your support ticket has been marked as resolved.',
						'details'     => [
							[ 'label' => 'Ticket reference', 'value' => $tnum ],
							[ 'label' => 'Subject', 'value' => $subj ],
						],
						'body_html'   => '<p style="margin:0;">If the issue is not fully resolved, reply to this email and we will reopen it for you.</p>',
						'cta_url'     => $status_url,
						'cta_label'   => $status_url ? 'View ticket status' : '',
						'signoff'     => $site . ' Support Team',
						'footer_note' => 'Reply directly to this email if you need this ticket reopened.',
					]
				),
			];

		case 'ticket-reopened-customer':
			$body = sprintf(
				"Hi %1\$s,\n\nYour support ticket (%2\$s) has been reopened and our team will continue looking into it.\n\nTrack or reply to this ticket:\n%3\$s\n\n%4\$s Support Team",
				$name, $tnum, $status_url, $site
			);
			return [
				'subject' => sprintf( '[%1$s] Your ticket %2$s has been reopened', $site, $tnum ),
				'body'    => $body,
				'html'    => dtb_render_branded_email(
					[
						'title'       => 'Your ticket has been reopened',
						'preheader'   => sprintf( 'Ticket %s is open again with Drywall Toolbox support.', $tnum ),
						'greeting'    => sprintf( 'Hi %s,', $name ),
						'intro'       => 'Your support ticket has been reopened and our team will continue looking into it.',
						'details'     => [
							[ 'label' => 'Ticket reference', 'value' => $tnum ],
							[ 'label' => 'Subject', 'value' => $subj ],
						],
						'cta_url'     => $status_url,
						'cta_label'   => $status_url ? 'View ticket status' : '',
						'signoff'     => $site . ' Support Team',
						'footer_note' => 'Reply directly to this email to add more information to your ticket.',
					]
				),
			];

		default:
			return new WP_Error( 'dtb_support_unknown_template', "Unknown email template: {$template}" );
	}
}

// =============================================================================
// SECTION 3 — DISPATCH
// =============================================================================

/**
 * Send a support notification email.
 *
 * @param string   $to       Recipient address.
 * @param string   $template Template slug.
 * @param array    $ctx      Template context.
 * @return bool|WP_Error
 */
function dtb_support_send_email( string $to, string $template, array $ctx ): bool|WP_Error {
	$result = dtb_support_get_email_template( $template, $ctx );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	if ( ! defined( 'DTB_SUPPORT_SENDING' ) ) {
		define( 'DTB_SUPPORT_SENDING', true );
	}

	$is_html       = ! empty( $result['html'] );
	$message       = $is_html ? (string) $result['html'] : (string) $result['body'];
	$content_type  = $is_html ? 'text/html' : 'text/plain';

	if ( function_exists( 'dtb_send_email' ) ) {
		$sent = dtb_send_email(
			[
				'to'           => $to,
				'subject'      => (string) $result['subject'],
				'message'      => $message,
				'is_html'      => $is_html,
				'content_type' => $content_type,
				'headers'      => dtb_support_email_headers( $content_type ),
				'alt_body'     => $is_html ? (string) $result['body'] : '',
				'context'      => [
					'module'   => 'dtb-support',
					'template' => $template,
				],
			]
		);
	} else {
		$alt_body_hook = $is_html && function_exists( 'dtb_mail_alt_body_hook' )
			? dtb_mail_alt_body_hook( (string) $result['body'] )
			: null;

		$sent = wp_mail(
			$to,
			$result['subject'],
			$message,
			dtb_support_email_headers( $content_type )
		);

		if ( is_callable( $alt_body_hook ) ) {
			remove_action( 'phpmailer_init', $alt_body_hook );
		}
	}

	if ( ! $sent ) {
		return new WP_Error( 'dtb_support_mail_failed', "wp_mail failed for template {$template} to {$to}" );
	}

	return true;
}

/**
 * Queue a support email in the outbox when available, falling back to direct delivery.
 */
function dtb_support_queue_email_notification( ?object $ticket, string $to, string $recipient_name, string $template, array $ctx ): bool|WP_Error {
	if ( $ticket && ! str_contains( $template, '-admin' ) && empty( $ctx['status_url'] ) ) {
		$ctx['status_url'] = dtb_support_public_status_url( $ticket );
	}

	$result = dtb_support_get_email_template( $template, $ctx );
	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$is_html = ! empty( $result['html'] );
	if ( function_exists( 'dtb_support_outbox_enqueue' ) ) {
		$enqueued = dtb_support_outbox_enqueue( [
			'ticket_id'       => $ticket ? (int) $ticket->id : null,
			'recipient_email' => $to,
			'recipient_name'  => $recipient_name,
			'subject'         => (string) $result['subject'],
			'body_html'       => $is_html ? (string) $result['html'] : '',
			'body_text'       => (string) $result['body'],
			'headers'         => dtb_support_email_headers( $is_html ? 'text/html' : 'text/plain' ),
		] );

		return is_wp_error( $enqueued ) ? $enqueued : true;
	}

	return dtb_support_send_email( $to, $template, $ctx );
}

/**
 * Fire all standard notifications for a newly-opened ticket.
 *
 * @param object $ticket  Full ticket row from the DB.
 */
function dtb_support_notify_ticket_opened( object $ticket ): void {
	$admin_url = admin_url( 'admin.php?page=dtb-support&ticket_id=' . $ticket->id );

	$ctx = [
		'ticket_number'  => $ticket->ticket_number,
		'subject'        => $ticket->subject,
		'customer_name'  => $ticket->customer_name,
		'customer_email' => $ticket->customer_email,
		'ticket_type'    => $ticket->ticket_type,
		'priority'       => $ticket->priority,
		'message'        => $ticket->message,
		'admin_url'      => $admin_url,
		'status_url'     => dtb_support_public_status_url( $ticket ),
	];

	// Notify customer.
	if ( is_email( $ticket->customer_email ) ) {
		dtb_support_queue_email_notification( $ticket, $ticket->customer_email, (string) $ticket->customer_name, 'ticket-opened-customer', $ctx );
	}

	// Notify the single support admin mailbox.
	$staff_email = dtb_support_admin_email();
	$staff_name  = __( 'Support Team', 'drywall-toolbox' );

	if ( is_email( $staff_email ) ) {
		dtb_support_queue_email_notification( $ticket, $staff_email, $staff_name, 'ticket-opened-admin', $ctx );
	}
}

/**
 * Fire all standard notifications for a staff reply.
 *
 * @param object $ticket
 * @param string $reply_body  Plain-text reply body.
 */
function dtb_support_notify_staff_reply( object $ticket, string $reply_body ): void {
	if ( ! is_email( $ticket->customer_email ) ) {
		return;
	}

	// Generate an expiring token so the customer can reply via the REST endpoint
	// from their email client without needing to be logged in.
	$reply_token = dtb_support_generate_public_reply_token( (int) $ticket->id, $ticket->customer_email );
	// The ticket ID is already in the REST URL path; only the token is a query parameter.
	$reply_link  = add_query_arg(
		[ 'token' => $reply_token ],
		home_url( '/support/status/' . (int) $ticket->id )
	);

	$reply_body = dtb_support_expand_reply_tokens( $reply_body, $ticket, esc_url_raw( $reply_link ) );

	$ctx = [
		'ticket_number'  => $ticket->ticket_number,
		'subject'        => $ticket->subject,
		'customer_name'  => $ticket->customer_name,
		'customer_email' => $ticket->customer_email,
		'reply_body'     => $reply_body,
		'reply_link'     => esc_url_raw( $reply_link ),
	];

	dtb_support_queue_email_notification( $ticket, $ticket->customer_email, (string) $ticket->customer_name, 'ticket-reply-customer', $ctx );
}

/**
 * Fire all standard notifications for a customer reply.
 *
 * @param object $ticket
 * @param string $reply_body
 */
function dtb_support_notify_customer_reply( object $ticket, string $reply_body ): void {
	$staff_email = dtb_support_admin_email();
	$staff_name  = __( 'Support Team', 'drywall-toolbox' );

	if ( ! is_email( $staff_email ) ) {
		return;
	}

	$admin_url = admin_url( 'admin.php?page=dtb-support&ticket_id=' . $ticket->id );
	$ctx = [
		'ticket_number' => $ticket->ticket_number,
		'subject'       => $ticket->subject,
		'customer_name' => $ticket->customer_name,
		'reply_body'    => $reply_body,
		'admin_url'     => $admin_url,
	];

	dtb_support_queue_email_notification( $ticket, $staff_email, $staff_name, 'ticket-reply-staff', $ctx );
}
