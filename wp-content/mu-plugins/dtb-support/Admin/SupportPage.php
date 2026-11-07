<?php
/**
 * DTB Support — SupportPage
 *
 * Renders dtb-support — support ticket queue.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_support_render_page(): void {
	if ( ! current_user_can( 'dtb_read_support_tickets' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$status = sanitize_key( $_GET['status'] ?? '' );   // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $status && '' !== sanitize_key( $_GET['tab'] ?? '' ) ) {
		$status = sanitize_key( $_GET['tab'] ?? '' );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	$queue = sanitize_key( $_GET['queue'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$type  = sanitize_key( $_GET['type'] ?? '' );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$priority = sanitize_key( $_GET['priority'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$search = sanitize_text_field( $_GET['s'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$live_search = sanitize_text_field( $_GET['search'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $search && '' !== $live_search ) {
		$search = $live_search;
	}
	$paged  = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per    = (int) get_option( 'dtb_admin_items_per_page', 25 );

	$status = dtb_support_admin_normalize_status( $status );

	dtb_admin_shell_open( [
		'title'       => __( 'Support', 'drywall-toolbox' ),
		'subtitle'    => __( 'Manage customer tickets, response queues, replies, and follow-ups.', 'drywall-toolbox' ),
		'section'     => 'operations',
		'page'        => 'dtb-support',
		'template'    => 'queue',
		'icon'        => 'dashicons-format-chat',
		'tabs'        => [],
		'live_target' => 'dtb-support-workspace',
	] );

	$result = dtb_support_admin_query_tickets( $status, $search, $paged, $per, $queue, $type, $priority );

	dtb_support_render_workbench( [
		'status'   => $status,
		'queue'    => $queue,
		'search'   => $search,
		'type'     => $type,
		'priority' => $priority,
		'paged'    => $paged,
		'result'   => $result,
	] );

	dtb_admin_shell_close();
}
