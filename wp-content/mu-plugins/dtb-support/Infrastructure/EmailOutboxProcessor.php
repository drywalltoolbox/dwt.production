<?php
/**
 * Infrastructure — EmailOutboxProcessor: WP-Cron background email sender.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register a 5-minute schedule for the outbox processor.
 */
function dtb_support_register_outbox_schedule( array $schedules ): array {
	$schedules['dtb_support_every_5_minutes'] = [
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 Minutes (DTB Support)', 'drywall-toolbox' ),
	];

	return $schedules;
}
add_filter( 'cron_schedules', 'dtb_support_register_outbox_schedule' );

/**
 * Schedule the outbox processor.
 */
function dtb_support_schedule_outbox_processor(): void {
	if ( ! wp_next_scheduled( 'dtb_support_process_outbox' ) ) {
		wp_schedule_event( time(), 'dtb_support_every_5_minutes', 'dtb_support_process_outbox' );
	}
}
add_action( 'plugins_loaded', 'dtb_support_schedule_outbox_processor' );

/**
 * Process pending outbox items. Called by WP-Cron every 5 minutes.
 */
function dtb_support_process_email_outbox(): void {
	$items = function_exists( 'dtb_support_outbox_get_pending' ) ? dtb_support_outbox_get_pending( 10 ) : [];
	if ( empty( $items ) ) {
		return;
	}
	if ( ! function_exists( 'dtb_support_outbox_claim' ) ) {
		return;
	}

	foreach ( $items as $item ) {
		if ( ! dtb_support_outbox_claim( (int) $item->id ) ) {
			continue;
		}

		$headers = [];
		if ( ! empty( $item->headers ) ) {
			$decoded = json_decode( (string) $item->headers, true );
			$headers = is_array( $decoded ) ? $decoded : [ (string) $item->headers ];
		}

		$is_html = ! empty( $item->body_html );
		if ( empty( $headers ) && function_exists( 'dtb_support_email_headers' ) ) {
			$headers = dtb_support_email_headers( $is_html ? 'text/html' : 'text/plain' );
		}

		if ( ! defined( 'DTB_SUPPORT_SENDING' ) ) {
			define( 'DTB_SUPPORT_SENDING', true );
		}

		if ( function_exists( 'dtb_send_email' ) ) {
			$sent = dtb_send_email(
				[
					'to'           => (string) $item->recipient_email,
					'subject'      => (string) $item->subject,
					'message'      => $is_html ? (string) $item->body_html : (string) $item->body_text,
					'is_html'      => $is_html,
					'content_type' => $is_html ? 'text/html' : 'text/plain',
					'alt_body'     => $is_html ? (string) $item->body_text : '',
					'headers'      => $headers,
					'context'      => [
						'module'   => 'dtb-support',
						'source'   => 'email-outbox',
						'outbox_id' => (int) $item->id,
					],
				]
			);
		} else {
			$alt_body_hook = $is_html && function_exists( 'dtb_mail_alt_body_hook' )
				? dtb_mail_alt_body_hook( (string) $item->body_text )
				: null;

			$sent = wp_mail(
				(string) $item->recipient_email,
				(string) $item->subject,
				$is_html ? (string) $item->body_html : (string) $item->body_text,
				$headers
			);

			if ( is_callable( $alt_body_hook ) ) {
				remove_action( 'phpmailer_init', $alt_body_hook );
			}
		}

		$ticket_id = ! empty( $item->ticket_id ) ? (int) $item->ticket_id : 0;
		$ticket    = $ticket_id > 0 ? dtb_support_get_ticket( $ticket_id ) : null;

		if ( $sent ) {
			if ( function_exists( 'dtb_support_outbox_mark_sent' ) ) {
				dtb_support_outbox_mark_sent( (int) $item->id );
			}
			if ( $ticket_id > 0 && function_exists( 'dtb_support_update_ticket' ) ) {
				dtb_support_update_ticket( $ticket_id, [
					'notification_status'       => 'sent',
					'notification_fail_count'   => 0,
					'notification_last_sent_at' => gmdate( 'Y-m-d H:i:s' ),
				] );
			}
			if ( $ticket_id > 0 && $ticket && function_exists( 'dtb_support_append_event' ) ) {
				dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.email_sent', [
					'actor_type' => 'system',
					'source'     => 'email_outbox',
					'visibility' => 'operator',
					'payload'    => [
						'outbox_id'        => (int) $item->id,
						'recipient_email'  => (string) $item->recipient_email,
						'notification_type'=> (string) $item->subject,
					],
				] ) );
			}
		} else {
			if ( function_exists( 'dtb_support_outbox_mark_failed' ) ) {
				dtb_support_outbox_mark_failed( (int) $item->id, 'wp_mail failed' );
			}
			if ( $ticket_id > 0 && $ticket && function_exists( 'dtb_support_update_ticket' ) ) {
				$fail_count = (int) ( $ticket->notification_fail_count ?? 0 ) + 1;
				dtb_support_update_ticket( $ticket_id, [
					'notification_status'     => $fail_count >= 5 ? 'failed' : 'pending',
					'notification_fail_count' => $fail_count,
				] );
			}
			if ( $ticket_id > 0 && function_exists( 'dtb_support_append_event' ) ) {
				dtb_support_append_event( dtb_support_build_event( $ticket_id, 'ticket.email_failed', [
					'actor_type' => 'system',
					'source'     => 'email_outbox',
					'visibility' => 'operator',
					'payload'    => [
						'outbox_id'       => (int) $item->id,
						'recipient_email' => (string) $item->recipient_email,
						'error'           => 'wp_mail failed',
					],
				] ) );
			}
		}
	}
}
add_action( 'dtb_support_process_outbox', 'dtb_support_process_email_outbox' );
