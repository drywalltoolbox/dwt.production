<?php
/**
 * DTB Platform — Record Cleanup.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_record_cleanup_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_system' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$notice = null;
	if ( isset( $_POST['dtb_record_cleanup_nonce'] ) ) {
		check_admin_referer( 'dtb_record_cleanup', 'dtb_record_cleanup_nonce' );
		$notice = dtb_record_cleanup_process_request();
	}

	dtb_admin_shell_open( [
		'title'    => __( 'Record Cleanup', 'drywall-toolbox' ),
		'subtitle' => __( 'Select and permanently delete operational records. This action cannot be undone.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-record-cleanup',
		'template' => 'tool',
		'icon'     => 'dashicons-trash',
	] );

	if ( is_array( $notice ) ) {
		echo dtb_admin_ui_alert( $notice['message'], $notice['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	echo '<form method="post" id="dtb-record-cleanup-form" data-dtb-record-cleanup-form>';
	echo wp_nonce_field( 'dtb_record_cleanup', 'dtb_record_cleanup_nonce', true, false );

	foreach ( dtb_record_cleanup_get_groups() as $type => $group ) {
		echo '<section class="dtb-card dtb-record-cleanup-group" data-dtb-cleanup-group="' . esc_attr( $type ) . '">';
		echo '<div class="dtb-card__header"><div><h2 class="dtb-card__title">' . esc_html( $group['label'] ) . '</h2>';
		echo '<p class="dtb-card__subtitle">' . esc_html( $group['description'] ) . '</p></div>';
		echo '<label class="dtb-record-cleanup-select-all"><input type="checkbox" data-dtb-select-group="' . esc_attr( $type ) . '"> <span>' . esc_html__( 'Select all shown', 'drywall-toolbox' ) . '</span></label></div>';
		echo '<div class="dtb-card__body">';

		if ( empty( $group['records'] ) ) {
			echo '<p>' . esc_html__( 'No records found.', 'drywall-toolbox' ) . '</p>';
		} else {
			echo '<div class="dtb-record-cleanup-table-wrap"><table class="widefat striped dtb-record-cleanup-table"><thead><tr>';
			echo '<th class="check-column"><span class="screen-reader-text">' . esc_html__( 'Select record', 'drywall-toolbox' ) . '</span></th><th>' . esc_html__( 'Record', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Customer / Subject', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Status', 'drywall-toolbox' ) . '</th><th>' . esc_html__( 'Created', 'drywall-toolbox' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $group['records'] as $record ) {
				$checkbox_id = 'dtb-cleanup-' . $type . '-' . (int) $record['id'];
				echo '<tr class="dtb-record-cleanup-row" data-dtb-cleanup-row>';
				echo '<th class="check-column"><input id="' . esc_attr( $checkbox_id ) . '" type="checkbox" data-dtb-record-group="' . esc_attr( $type ) . '" name="records[' . esc_attr( $type ) . '][]" value="' . esc_attr( (string) $record['id'] ) . '"></th>';
				echo '<td><strong>' . esc_html( $record['label'] ) . '</strong></td>';
				echo '<td>' . esc_html( $record['summary'] ) . '</td>';
				echo '<td>' . esc_html( $record['status'] ) . '</td>';
				echo '<td>' . esc_html( $record['created'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table></div>';
		}
		echo '</div></section>';
	}

	echo '<section class="dtb-card dtb-record-cleanup-confirm"><div class="dtb-card__body">';
	echo '<p><strong>' . esc_html__( 'Permanent deletion safeguards', 'drywall-toolbox' ) . '</strong></p>';
	echo '<ul style="list-style:disc;padding-left:22px">';
	echo '<li>' . esc_html__( 'Only checked records are deleted.', 'drywall-toolbox' ) . '</li>';
	echo '<li>' . esc_html__( 'Deleting a repair or return request does not delete its linked WooCommerce order.', 'drywall-toolbox' ) . '</li>';
	echo '<li>' . esc_html__( 'Associated DTB event rows are removed with the selected record.', 'drywall-toolbox' ) . '</li>';
	echo '</ul>';
	echo '<p class="dtb-record-cleanup-selection" aria-live="polite"><strong data-dtb-selected-count>0</strong> ' . esc_html__( 'record(s) selected', 'drywall-toolbox' ) . '</p>';
	echo '<label class="dtb-record-cleanup-confirmation"><strong>' . esc_html__( 'Type DELETE to confirm:', 'drywall-toolbox' ) . '</strong> ';
	echo '<input type="text" name="dtb_delete_confirmation" data-dtb-delete-confirmation autocomplete="off" required pattern="DELETE"></label>';
	echo dtb_admin_ui_button( __( 'Permanently Delete Selected', 'drywall-toolbox' ), [
		'type'    => 'danger',
		'btn_type'=> 'submit',
		'icon'    => 'dashicons-trash',
		'data'    => [ 'dtb-delete-submit' => '1' ],
		'disabled'=> true,
		'confirm' => __( 'Permanently delete the selected records? This cannot be undone.', 'drywall-toolbox' ),
		'loading' => true,
	] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div></section></form>';

	dtb_admin_shell_close();
}

function dtb_record_cleanup_get_groups(): array {
	$groups = [
		'product_order' => [
			'label'       => __( 'Product Orders', 'drywall-toolbox' ),
			'description' => __( 'Recent WooCommerce orders. Permanent deletion also removes DTB order events.', 'drywall-toolbox' ),
			'records'     => [],
		],
		'repair' => [
			'label'       => __( 'Repair Requests', 'drywall-toolbox' ),
			'description' => __( 'Repair workflow records. Linked WooCommerce orders remain intact.', 'drywall-toolbox' ),
			'records'     => [],
		],
		'return' => [
			'label'       => __( 'Return Requests', 'drywall-toolbox' ),
			'description' => __( 'Return and RMA workflow records. Linked WooCommerce orders remain intact.', 'drywall-toolbox' ),
			'records'     => [],
		],
		'support' => [
			'label'       => __( 'Support Tickets', 'drywall-toolbox' ),
			'description' => __( 'Support tickets, event history, and queued ticket emails.', 'drywall-toolbox' ),
			'records'     => [],
		],
	];

	if ( function_exists( 'wc_get_orders' ) ) {
		foreach ( wc_get_orders( [
			'limit'   => 100,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array_values( array_unique( array_merge( array_keys( wc_get_order_statuses() ), [ 'trash' ] ) ) ),
			'return'  => 'objects',
		] ) as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$groups['product_order']['records'][] = [
				'id'      => (int) $order->get_id(),
				'label'   => sprintf( 'Order #%s', $order->get_order_number() ),
				'summary' => trim( $order->get_formatted_billing_full_name() ) ?: $order->get_billing_email(),
				'status'  => wc_get_order_status_name( $order->get_status() ),
				'created' => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'M j, Y g:i a' ) : '',
			];
		}
	}

	foreach ( [ 'repair' => 'dtb_repair_request', 'return' => 'dtb_return' ] as $type => $post_type ) {
		foreach ( get_posts( [
			'post_type'      => $post_type,
			'post_status'    => [ 'publish', 'draft', 'pending', 'private', 'trash' ],
			'posts_per_page' => 100,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] ) as $post ) {
			$status_meta   = 'repair' === $type ? '_repair_status' : '_dtb_return_status';
			$customer_meta = 'repair' === $type ? '_repair_customer_name' : '_dtb_return_customer_name';
			$groups[ $type ]['records'][] = [
				'id'      => (int) $post->ID,
				'label'   => get_the_title( $post ) ?: sprintf( '#%d', $post->ID ),
				'summary' => (string) get_post_meta( $post->ID, $customer_meta, true ),
				'status'  => (string) get_post_meta( $post->ID, $status_meta, true ),
				'created' => get_the_date( 'M j, Y g:i a', $post ),
			];
		}
	}

	if ( function_exists( 'dtb_support_query_tickets' ) ) {
		$result = dtb_support_query_tickets( [ 'status' => 'all', 'per_page' => 100 ] );
		foreach ( (array) ( $result['tickets'] ?? [] ) as $ticket ) {
			$groups['support']['records'][] = [
				'id'      => (int) $ticket->id,
				'label'   => (string) $ticket->ticket_number,
				'summary' => (string) $ticket->subject,
				'status'  => (string) $ticket->status,
				'created' => mysql2date( 'M j, Y g:i a', (string) $ticket->created_at ),
			];
		}
	}

	return $groups;
}

function dtb_record_cleanup_process_request(): array {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return [ 'type' => 'danger', 'message' => __( 'You do not have permission to delete these records.', 'drywall-toolbox' ) ];
	}

	$confirmation = sanitize_text_field( wp_unslash( $_POST['dtb_delete_confirmation'] ?? '' ) );
	if ( 'DELETE' !== $confirmation ) {
		return [ 'type' => 'warning', 'message' => __( 'Deletion was not performed because the confirmation text did not match.', 'drywall-toolbox' ) ];
	}

	$submitted = isset( $_POST['records'] ) && is_array( $_POST['records'] ) ? wp_unslash( $_POST['records'] ) : [];
	$deleted   = 0;
	$failed    = 0;

	foreach ( [ 'product_order', 'repair', 'return', 'support' ] as $type ) {
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) ( $submitted[ $type ] ?? [] ) ) ) ) ), 0, 200 );
		foreach ( $ids as $id ) {
			dtb_record_cleanup_delete_record( $type, $id ) ? $deleted++ : $failed++;
		}
	}

	if ( 0 === $deleted && 0 === $failed ) {
		return [ 'type' => 'warning', 'message' => __( 'No records were selected.', 'drywall-toolbox' ) ];
	}

	return [
		'type'    => $failed > 0 ? 'warning' : 'success',
		'message' => sprintf(
			/* translators: 1: deleted count, 2: failed count */
			__( '%1$d record(s) permanently deleted. %2$d deletion(s) failed.', 'drywall-toolbox' ),
			$deleted,
			$failed
		),
	];
}

function dtb_record_cleanup_delete_record( string $type, int $id ): bool {
	global $wpdb;

	if ( $id <= 0 ) {
		return false;
	}

	switch ( $type ) {
		case 'product_order':
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : false;
			if ( ! $order instanceof WC_Order ) {
				return false;
			}
			$order->delete( true );
			if ( class_exists( 'WC_Cache_Helper' ) ) {
				WC_Cache_Helper::invalidate_cache_group( 'orders' );
			}
			clean_post_cache( $id );
			wp_cache_delete( $id, 'orders' );
			wp_cache_delete( 'order-' . $id, 'orders' );
			if ( wc_get_order( $id ) ) {
				return false;
			}
			$wpdb->delete( $wpdb->prefix . 'dtb_order_events', [ 'order_id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_transient( 'dtb_order_tracking_' . $id );
			delete_transient( 'dtb_order_tracking_v2_' . $id );
			return true;

		case 'repair':
			$post = get_post( $id );
			if ( ! $post || 'dtb_repair_request' !== $post->post_type || ! wp_delete_post( $id, true ) ) {
				return false;
			}
			$wpdb->delete( $wpdb->prefix . 'dtb_repair_events', [ 'repair_id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			clean_post_cache( $id );
			return null === get_post( $id );

		case 'return':
			$post = get_post( $id );
			if ( ! $post || 'dtb_return' !== $post->post_type || ! wp_delete_post( $id, true ) ) {
				return false;
			}
			clean_post_cache( $id );
			return null === get_post( $id );

		case 'support':
			if ( ! function_exists( 'dtb_support_get_ticket' ) || ! dtb_support_get_ticket( $id ) ) {
				return false;
			}
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$events_deleted = $wpdb->delete( $wpdb->prefix . 'dtb_support_events', [ 'ticket_id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$outbox_deleted = $wpdb->delete( $wpdb->prefix . 'dtb_support_email_outbox', [ 'ticket_id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ticket_deleted = $wpdb->delete( $wpdb->prefix . 'dtb_support_tickets', [ 'id' => $id ], [ '%d' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $events_deleted || false === $outbox_deleted || 1 !== $ticket_deleted ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return false;
			}
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return true;
	}

	return false;
}
