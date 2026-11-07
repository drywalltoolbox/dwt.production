<?php
/**
 * Infrastructure — EmailOutboxRepository: email outbox CRUD and queue operations.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return the outbox table name.
 */
function dtb_support_outbox_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'dtb_support_email_outbox';
}

/**
 * Return the stale sending timeout in seconds.
 */
function dtb_support_outbox_sending_timeout(): int {
	return (int) apply_filters( 'dtb_support_outbox_sending_timeout', 15 * MINUTE_IN_SECONDS );
}

/**
 * Enqueue an outgoing message.
 *
 * @param array $message Message payload.
 * @return int|WP_Error
 */
function dtb_support_outbox_enqueue( array $message ): int|WP_Error {
	global $wpdb;
	$table = dtb_support_outbox_table();
	$now   = gmdate( 'Y-m-d H:i:s' );

	$recipient_email = sanitize_email( $message['recipient_email'] ?? '' );
	if ( ! is_email( $recipient_email ) ) {
		return new WP_Error( 'dtb_support_invalid_recipient', __( 'A valid recipient email is required.', 'drywall-toolbox' ) );
	}

	$headers = $message['headers'] ?? '';
	if ( is_array( $headers ) ) {
		$headers = wp_json_encode( array_values( $headers ) );
	}

	$row = [
		'ticket_id'        => ! empty( $message['ticket_id'] ) ? (int) $message['ticket_id'] : null,
		'recipient_email'  => $recipient_email,
		'recipient_name'   => sanitize_text_field( $message['recipient_name'] ?? '' ),
		'subject'          => sanitize_text_field( $message['subject'] ?? '' ),
		'body_html'        => (string) ( $message['body_html'] ?? '' ),
		'body_text'        => (string) ( $message['body_text'] ?? '' ),
		'headers'          => (string) $headers,
		'status'           => 'pending',
		'attempts'         => 0,
		'next_attempt_at'  => $now,
		'created_at'       => $now,
		'updated_at'       => $now,
	];

	$result = $wpdb->insert( $table, $row );
	if ( false === $result ) {
		return new WP_Error( 'dtb_support_outbox_insert_failed', __( 'Could not enqueue support email.', 'drywall-toolbox' ) );
	}

	return (int) $wpdb->insert_id;
}

/**
 * Returns pending items where next_attempt_at <= NOW() AND status='pending'.
 */
function dtb_support_outbox_get_pending( int $limit = 10 ): array {
	global $wpdb;
	$table = dtb_support_outbox_table();
	$limit = max( 1, min( 100, $limit ) );
	$stale_before = gmdate( 'Y-m-d H:i:s', time() - dtb_support_outbox_sending_timeout() );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$table}
			WHERE (
				(status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP()))
				OR (status = 'sending' AND updated_at <= %s)
			)
			ORDER BY COALESCE(next_attempt_at, created_at) ASC, created_at ASC
			LIMIT %d",
		$stale_before,
		$limit
	) );

	return $rows ?: [];
}

/**
 * Claim an outbox item for sending.
 */
function dtb_support_outbox_claim( int $outbox_id ): bool {
	global $wpdb;
	$table        = dtb_support_outbox_table();
	$now          = gmdate( 'Y-m-d H:i:s' );
	$stale_before = gmdate( 'Y-m-d H:i:s', time() - dtb_support_outbox_sending_timeout() );

	$result = $wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table}
				SET status = 'sending', updated_at = %s
				WHERE id = %d
				AND (
					(status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= UTC_TIMESTAMP()))
					OR (status = 'sending' AND updated_at <= %s)
				)",
			$now,
			$outbox_id,
			$stale_before
		)
	);

	return $result > 0;
}

/**
 * Mark an outbox item as sent.
 */
function dtb_support_outbox_mark_sent( int $outbox_id ): void {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->update( dtb_support_outbox_table(), [
		'status'          => 'sent',
		'sent_at'         => $now,
		'next_attempt_at' => null,
		'last_error'      => null,
		'updated_at'      => $now,
	], [ 'id' => $outbox_id ] );
}

/**
 * Mark an outbox item as failed.
 */
function dtb_support_outbox_mark_failed( int $outbox_id, string $error ): void {
	global $wpdb;
	$table = dtb_support_outbox_table();
	$item  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $outbox_id ) );
	if ( ! $item ) {
		return;
	}

	$attempts = (int) $item->attempts + 1;
	$now      = gmdate( 'Y-m-d H:i:s' );
	$backoff  = match ( $attempts ) {
		1 => 5 * MINUTE_IN_SECONDS,
		2 => 15 * MINUTE_IN_SECONDS,
		3 => HOUR_IN_SECONDS,
		4 => 4 * HOUR_IN_SECONDS,
		default => 0,
	};

	$update = [
		'attempts'    => $attempts,
		'last_error'  => sanitize_textarea_field( $error ),
		'updated_at'  => $now,
		'status'      => $attempts >= 5 ? 'failed' : 'pending',
		'next_attempt_at' => $attempts >= 5 ? null : gmdate( 'Y-m-d H:i:s', time() + $backoff ),
	];

	$wpdb->update( $table, $update, [ 'id' => $outbox_id ] );
}

/**
 * Retry an outbox item immediately.
 */
function dtb_support_outbox_retry( int $outbox_id ): void {
	global $wpdb;
	$wpdb->update( dtb_support_outbox_table(), [
		'status'          => 'pending',
		'next_attempt_at' => gmdate( 'Y-m-d H:i:s' ),
		'updated_at'      => gmdate( 'Y-m-d H:i:s' ),
	], [ 'id' => $outbox_id ] );
}

/**
 * Return outbox counts.
 */
function dtb_support_outbox_counts(): array {
	global $wpdb;
	$table = dtb_support_outbox_table();
	$today = gmdate( 'Y-m-d 00:00:00' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT
		SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
		SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
		SUM(CASE WHEN status = 'sent' AND sent_at >= %s THEN 1 ELSE 0 END) AS sent_today
	FROM {$table}", $today ), ARRAY_A );

	return [
		'pending'    => isset( $row['pending'] ) ? (int) $row['pending'] : 0,
		'failed'     => isset( $row['failed'] ) ? (int) $row['failed'] : 0,
		'sent_today' => isset( $row['sent_today'] ) ? (int) $row['sent_today'] : 0,
	];
}
