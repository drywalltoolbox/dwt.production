<?php
/**
 * DTB Platform — AdminCustomerContextService
 *
 * Canonical shared service that assembles the Customer 360 context block used
 * by support, returns, and repair modals.  Call the helper function instead of
 * instantiating the class directly.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Count WooCommerce orders without materializing all matching IDs.
 *
 * @param array<string,mixed> $args WooCommerce order query args.
 * @return int
 */
function dtb_admin_wc_order_count( array $args = [] ): int {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return 0;
	}

	$args = array_filter(
		$args,
		static fn( $value ): bool => null !== $value && '' !== $value && [] !== $value
	);

	if ( isset( $args['status'] ) && 1 === count( $args ) && function_exists( 'wc_orders_count' ) ) {
		$status = is_array( $args['status'] ) ? array_map( 'sanitize_key', $args['status'] ) : [ sanitize_key( (string) $args['status'] ) ];
		$total  = 0;
		foreach ( $status as $single_status ) {
			$total += (int) wc_orders_count( str_starts_with( $single_status, 'wc-' ) ? substr( $single_status, 3 ) : $single_status );
		}
		return $total;
	}

	$result = wc_get_orders( array_merge( $args, [
		'limit'    => 1,
		'paginate' => true,
		'return'   => 'ids',
	] ) );

	return is_object( $result ) && isset( $result->total ) ? (int) $result->total : 0;
}

/**
 * Compute WooCommerce order spend in bounded pages.
 *
 * @param array<string,mixed> $args      WooCommerce order query args.
 * @param int                 $max_pages Maximum pages to inspect.
 * @return array{total:float,count:int,partial:bool}
 */
function dtb_admin_wc_order_spend_summary( array $args, int $max_pages = 20 ): array {
	$args = array_merge( $args, [
		'limit'   => 50,
		'orderby' => 'date',
		'order'   => 'DESC',
		'return'  => 'objects',
	] );

	$total       = 0.0;
	$count       = 0;
	$page        = 1;
	$total_pages = 1;

	do {
		$result = wc_get_orders( array_merge( $args, [
			'paged'    => $page,
			'paginate' => true,
		] ) );
		$orders = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : (array) $result;
		foreach ( $orders as $order ) {
			if ( $order instanceof WC_Order ) {
				$total += (float) $order->get_total();
				$count++;
			}
		}
		$total_pages = is_object( $result ) && isset( $result->max_num_pages ) ? max( 1, (int) $result->max_num_pages ) : 1;
		$page++;
	} while ( $page <= $total_pages && $page <= $max_pages );

	return [
		'total'   => $total,
		'count'   => $count,
		'partial' => $page <= $total_pages,
	];
}

/**
 * Assemble customer context for a given customer resolved by email, user ID,
 * or WooCommerce order ID.
 *
 * $args keys (at least one required):
 *   customer_email  string
 *   customer_user_id int
 *   order_id        int
 *   exclude_module  string  ('support'|'returns'|'repair') — omit the calling
 *                           module's own count from "open" totals to avoid
 *                           double-counting the record currently being viewed.
 *
 * @param array $args
 * @return array{
 *   name: string,
 *   email: string,
 *   phone: string,
 *   user_id: int,
 *   customer_since: string,
 *   order_count: int,
 *   repair_count: int,
 *   return_count: int,
 *   ticket_count: int,
 *   recent_orders: array,
 *   open_tickets: int,
 *   open_repairs: int,
 *   open_returns: int,
 *   lifetime_spend: float,
 *   is_high_value: bool,
 *   risk_notes: string[],
 *   cached_at: string,
 * }
 */
function dtb_admin_get_customer_context( array $args ): array {
	static $memo = [];

	$email   = sanitize_email( (string) ( $args['customer_email'] ?? '' ) );
	$user_id = absint( $args['customer_user_id'] ?? 0 );
	$order_id = absint( $args['order_id'] ?? 0 );
	$exclude = sanitize_key( $args['exclude_module'] ?? '' );

	// Resolve user and email via WooCommerce order when only order_id is given.
	if ( $order_id && ! $email && ! $user_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$email   = sanitize_email( $order->get_billing_email() );
			$user_id = absint( $order->get_customer_id() );
		}
	}

	// Try to resolve user from email.
	if ( $email && ! $user_id ) {
		$wp_user = get_user_by( 'email', $email );
		if ( $wp_user ) {
			$user_id = $wp_user->ID;
		}
	}

	$cache_key = 'dtb_cctx_' . md5( $email . '_' . $user_id . '_' . $order_id . '_' . $exclude );
	if ( isset( $memo[ $cache_key ] ) ) {
		return $memo[ $cache_key ];
	}

	// Try object-cache (TTL 90 s — short enough to stay near-real-time).
	$cached = wp_cache_get( $cache_key, 'dtb_admin' );
	if ( false !== $cached && is_array( $cached ) ) {
		$memo[ $cache_key ] = $cached;
		return $cached;
	}

	$ctx = dtb_admin_build_customer_context( $email, $user_id, $exclude );

	wp_cache_set( $cache_key, $ctx, 'dtb_admin', 90 );
	$memo[ $cache_key ] = $ctx;

	return $ctx;
}

/**
 * Build the customer context data (no caching — called by dtb_admin_get_customer_context).
 *
 * @param string $email
 * @param int    $user_id
 * @param string $exclude_module
 * @return array
 */
function dtb_admin_build_customer_context( string $email, int $user_id, string $exclude_module ): array {
	global $wpdb;

	$name          = '';
	$phone         = '';
	$customer_since = '';

	// Resolve display name and metadata.
	if ( $user_id ) {
		$wp_user = get_user_by( 'id', $user_id );
		if ( $wp_user ) {
			$name  = $wp_user->display_name;
			$phone = get_user_meta( $user_id, 'billing_phone', true ) ?: '';
			$customer_since = $wp_user->user_registered;
		}
		if ( ! $email ) {
			$email = $wp_user ? $wp_user->user_email : '';
		}
	}

	// ── Order counts ────────────────────────────────────────────────────────────
	$order_count    = 0;
	$lifetime_spend = 0.0;
	$lifetime_spend_partial = false;
	$recent_orders  = [];

	if ( function_exists( 'wc_get_orders' ) ) {
		$order_args = [
			'limit'  => 5,
			'status' => array_keys( wc_get_order_statuses() ),
			'orderby'=> 'date',
			'order'  => 'DESC',
		];
		if ( $user_id ) {
			$order_args['customer_id'] = $user_id;
		} elseif ( $email ) {
			$order_args['billing_email'] = $email;
		}

		if ( $user_id || $email ) {
			if ( $user_id && class_exists( 'WC_Customer' ) ) {
				try {
					$wc_customer    = new WC_Customer( $user_id );
					$order_count    = (int) $wc_customer->get_order_count();
					$lifetime_spend = (float) $wc_customer->get_total_spent();
				} catch ( Throwable $e ) {
					$order_count = dtb_admin_wc_order_count( $order_args );
					$spend       = dtb_admin_wc_order_spend_summary( $order_args );
					$lifetime_spend = (float) $spend['total'];
					$lifetime_spend_partial = (bool) $spend['partial'];
				}
			} else {
				$order_count = dtb_admin_wc_order_count( $order_args );
				$spend       = dtb_admin_wc_order_spend_summary( $order_args );
				$lifetime_spend = (float) $spend['total'];
				$lifetime_spend_partial = (bool) $spend['partial'];
			}

			$recent = wc_get_orders( array_merge( $order_args, [ 'limit' => 5 ] ) );
			if ( is_array( $recent ) ) {
				foreach ( $recent as $ord ) {
					if ( ! is_a( $ord, 'WC_Order' ) ) {
						continue;
					}
					$recent_orders[] = [
						'id'         => $ord->get_id(),
						'status'     => $ord->get_status(),
						'total'      => (float) $ord->get_total(),
						'date'       => $ord->get_date_created() ? $ord->get_date_created()->format( 'Y-m-d' ) : '',
						'edit_url'   => get_edit_post_link( $ord->get_id() ),
					];
				}
			}
		}
	}

	// ── Support ticket counts ────────────────────────────────────────────────────
	$ticket_count = 0;
	$open_tickets = 0;
	if ( 'support' !== $exclude_module ) {
		$ticket_counts = dtb_admin_count_support_tickets( $email, $user_id );
		$ticket_count  = $ticket_counts['total'];
		$open_tickets  = $ticket_counts['open'];
	}

	// ── Repair counts ───────────────────────────────────────────────────────────
	$repair_count = 0;
	$open_repairs = 0;
	if ( 'repair' !== $exclude_module ) {
		$repair_counts = dtb_admin_count_repairs( $email, $user_id );
		$repair_count  = $repair_counts['total'];
		$open_repairs  = $repair_counts['open'];
	}

	// ── Return counts ────────────────────────────────────────────────────────────
	$return_count = 0;
	$open_returns = 0;
	if ( 'returns' !== $exclude_module ) {
		$return_counts = dtb_admin_count_returns( $email, $user_id );
		$return_count  = $return_counts['total'];
		$open_returns  = $return_counts['open'];
	}

	// ── Risk notes ──────────────────────────────────────────────────────────────
	$risk_notes   = dtb_admin_customer_risk_notes( $ticket_count, $return_count, $repair_count, $lifetime_spend );
	$is_high_value = $lifetime_spend >= 500.0 || $order_count >= 5;

	return [
		'name'           => $name ?: 'Unknown Customer',
		'email'          => $email,
		'phone'          => $phone,
		'user_id'        => $user_id,
		'customer_since' => $customer_since,
		'order_count'    => $order_count,
		'repair_count'   => $repair_count,
		'return_count'   => $return_count,
		'ticket_count'   => $ticket_count,
		'recent_orders'  => $recent_orders,
		'open_tickets'   => $open_tickets,
		'open_repairs'   => $open_repairs,
		'open_returns'   => $open_returns,
		'lifetime_spend' => round( $lifetime_spend, 2 ),
		'lifetime_spend_partial' => $lifetime_spend_partial,
		'is_high_value'  => $is_high_value,
		'risk_notes'     => $risk_notes,
		'cached_at'      => gmdate( 'c' ),
	];
}

/**
 * Count support tickets for a customer.
 *
 * @param string $email
 * @param int    $user_id
 * @return array{total: int, open: int}
 */
function dtb_admin_count_support_tickets( string $email, int $user_id ): array {
	global $wpdb;

	if ( ! $email && ! $user_id ) {
		return [ 'total' => 0, 'open' => 0 ];
	}

	// Check if the support schema table exists.
	$table = $wpdb->prefix . 'dtb_support_tickets';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $exists ) {
		return [ 'total' => 0, 'open' => 0 ];
	}

	$where = '1=1';
	$vals  = [];
	if ( $email ) {
		$where .= ' AND customer_email = %s';
		$vals[]  = $email;
	} elseif ( $user_id ) {
		$where .= ' AND customer_user_id = %d';
		$vals[]  = $user_id;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_results( $wpdb->prepare( "SELECT status FROM {$table} WHERE {$where}", ...$vals ) );
	$total = is_array( $rows ) ? count( $rows ) : 0;
	$open  = 0;
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			if ( in_array( $row->status, [ 'open', 'needs_reply', 'in_progress', 'snoozed' ], true ) ) {
				++$open;
			}
		}
	}

	return [ 'total' => $total, 'open' => $open ];
}

/**
 * Count repair requests for a customer.
 *
 * @param string $email
 * @param int    $user_id
 * @return array{total: int, open: int}
 */
function dtb_admin_count_repairs( string $email, int $user_id ): array {
	if ( ! $email && ! $user_id ) {
		return [ 'total' => 0, 'open' => 0 ];
	}

	$meta_query = [ 'relation' => 'OR' ];
	if ( $email ) {
		$meta_query[] = [ 'key' => '_repair_customer_email', 'value' => $email ];
	}
	if ( $user_id ) {
		$meta_query[] = [ 'key' => '_repair_customer_user_id', 'value' => $user_id, 'type' => 'NUMERIC' ];
	}

	$q = new WP_Query( [
		'post_type'      => 'dtb_repair_request',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
	] );

	$total    = $q->found_posts;
	$terminal = [ 'closed', 'cancelled', 'quote_declined', 'completed' ];
	$open     = 0;

	foreach ( $q->posts as $pid ) {
		$s = (string) get_post_meta( $pid, '_repair_status', true );
		if ( ! in_array( $s, $terminal, true ) ) {
			++$open;
		}
	}

	return [ 'total' => $total, 'open' => $open ];
}

/**
 * Count returns for a customer.
 *
 * @param string $email
 * @param int    $user_id
 * @return array{total: int, open: int}
 */
function dtb_admin_count_returns( string $email, int $user_id ): array {
	global $wpdb;

	if ( ! $email && ! $user_id ) {
		return [ 'total' => 0, 'open' => 0 ];
	}

	$table  = $wpdb->prefix . 'dtb_returns';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $exists ) {
		return [ 'total' => 0, 'open' => 0 ];
	}

	$where = '1=1';
	$vals  = [];
	if ( $email ) {
		$where .= ' AND customer_email = %s';
		$vals[]  = $email;
	} elseif ( $user_id ) {
		$where .= ' AND customer_user_id = %d';
		$vals[]  = $user_id;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT status FROM {$table} WHERE {$where}", ...$vals ) );
	$total = is_array( $rows ) ? count( $rows ) : 0;
	$open  = 0;
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			if ( ! in_array( $row->status, [ 'refund_issued', 'exchange_sent', 'closed', 'rejected' ], true ) ) {
				++$open;
			}
		}
	}

	return [ 'total' => $total, 'open' => $open ];
}

/**
 * Derive human-readable risk notes from lifetime activity.
 *
 * @param int   $tickets
 * @param int   $returns
 * @param int   $repairs
 * @param float $spend
 * @return string[]
 */
function dtb_admin_customer_risk_notes( int $tickets, int $returns, int $repairs, float $spend ): array {
	$notes = [];
	if ( $returns >= 3 ) {
		$notes[] = __( 'High return frequency', 'drywall-toolbox' );
	}
	if ( $tickets >= 5 ) {
		$notes[] = __( 'High support contact frequency', 'drywall-toolbox' );
	}
	if ( $repairs >= 3 ) {
		$notes[] = __( 'Multiple repair submissions', 'drywall-toolbox' );
	}
	if ( $spend >= 1000 ) {
		$notes[] = __( 'High-value customer', 'drywall-toolbox' );
	}
	return $notes;
}
