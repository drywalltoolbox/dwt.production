<?php
/**
 * Services — SupportCustomerContextService
 *
 * Assembles customer context for the intelligence endpoint and the modal command sidebar.
 * Pulls order history, repair history, and prior ticket counts for the customer
 * associated with a support ticket.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build customer context for a ticket.
 *
 * Returns an array with:
 *   customer_name    string
 *   customer_email   string
 *   customer_user_id int|null
 *   order_count      int   — number of WooCommerce orders by this customer
 *   repair_count     int   — number of repair records
 *   ticket_count     int   — lifetime support ticket count (excluding current)
 *   recent_orders    array — last 3 order stubs
 *   customer_since   string|null
 *
 * @param object $ticket Raw DB row.
 * @return array<string, mixed>
 */
function dtb_support_get_customer_context( object $ticket ): array {
	$customer_email   = (string) ( $ticket->customer_email ?? '' );
	$customer_user_id = ! empty( $ticket->customer_user_id ) ? (int) $ticket->customer_user_id : null;
	$ticket_id        = (int) ( $ticket->id ?? 0 );

	$context = [
		'customer_name'    => (string) ( $ticket->customer_name ?? '' ),
		'customer_email'   => $customer_email,
		'customer_user_id' => $customer_user_id,
		'order_count'      => 0,
		'repair_count'     => 0,
		'ticket_count'     => 0,
		'recent_orders'    => [],
		'customer_since'   => null,
	];

	if ( '' === $customer_email && null === $customer_user_id ) {
		return $context;
	}

	// ── Ticket history ──────────────────────────────────────────────────────
	global $wpdb;
	$tickets_table = function_exists( 'dtb_support_tickets_table' )
		? dtb_support_tickets_table()
		: $wpdb->prefix . 'dtb_support_tickets';

	if ( '' !== $customer_email ) {
		$lifetime_tickets = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM `{$tickets_table}` WHERE customer_email = %s AND id != %d",
			$customer_email,
			$ticket_id
		) );
		$context['ticket_count'] = $lifetime_tickets;
	}

	// ── WooCommerce orders ──────────────────────────────────────────────────
	if ( function_exists( 'wc_get_orders' ) ) {
		$query_args = [
			'limit'  => 3,
			'return' => 'objects',
			'status' => array_keys( wc_get_order_statuses() ),
		];

		if ( null !== $customer_user_id ) {
			$query_args['customer_id'] = $customer_user_id;
		} elseif ( '' !== $customer_email ) {
			$query_args['billing_email'] = $customer_email;
		}

		try {
			$orders = wc_get_orders( $query_args );

			// Total count query.
			$count_args                = $query_args;
			unset( $count_args['limit'], $count_args['return'] );
			$context['order_count'] = function_exists( 'dtb_admin_wc_order_count' )
				? dtb_admin_wc_order_count( $count_args )
				: 0;

			$context['recent_orders'] = array_map( static function ( $order ): array {
				return [
					'id'         => $order->get_id(),
					'number'     => $order->get_order_number(),
					'status'     => $order->get_status(),
					'total'      => wc_price( $order->get_total() ),
					'date'       => $order->get_date_created() ? $order->get_date_created()->format( 'Y-m-d' ) : '',
					'admin_url'  => get_edit_post_link( $order->get_id() ),
				];
			}, $orders );

			// Customer since.
			if ( null !== $customer_user_id ) {
				$user = get_userdata( $customer_user_id );
				if ( $user ) {
					$context['customer_since'] = $user->user_registered;
				}
			}
		} catch ( \Exception $e ) {
			// WooCommerce unavailable — skip order context gracefully.
		}
	}

	// ── Repair history ──────────────────────────────────────────────────────
	$repairs_table = $wpdb->prefix . 'dtb_repairs';
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$repairs_table}'" ) ) {
		if ( '' !== $customer_email ) {
			$context['repair_count'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$repairs_table}` WHERE customer_email = %s",
				$customer_email
			) );
		} elseif ( null !== $customer_user_id ) {
			$context['repair_count'] = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM `{$repairs_table}` WHERE user_id = %d",
				$customer_user_id
			) );
		}
	}

	return $context;
}
