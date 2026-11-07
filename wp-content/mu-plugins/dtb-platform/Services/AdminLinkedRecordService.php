<?php
/**
 * DTB Platform — AdminLinkedRecordService
 *
 * Resolves cross-module linked records (order ↔ ticket ↔ return ↔ repair) for
 * the shared workbench contract and exposes confidence/source metadata on every
 * link so the UI can distinguish verified from inferred relationships.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve all linked records for a given module record.
 *
 * @param string $module    'support' | 'returns' | 'repair'
 * @param int    $record_id The primary record ID in that module.
 * @return array{
 *   order_id: int|null,
 *   order_edit_url: string,
 *   order_status: string,
 *   customer_user_id: int|null,
 *   ticket_ids: int[],
 *   return_ids: int[],
 *   repair_ids: int[],
 *   veeqo_order_id: string,
 *   confidence: array<string, string>,
 *   synced_at: string,
 * }
 */
function dtb_admin_get_linked_records( string $module, int $record_id ): array {
	$result = [
		'order_id'         => null,
		'order_edit_url'   => '',
		'order_status'     => '',
		'customer_user_id' => null,
		'ticket_ids'       => [],
		'return_ids'       => [],
		'repair_ids'       => [],
		'veeqo_order_id'   => '',
		'confidence'       => [],
		'warnings'         => [],
		'mismatches'       => [],
		'customer_email'   => '',
		'synced_at'        => gmdate( 'c' ),
		'records'          => [],
	];

	switch ( $module ) {
		case 'order':
		case 'product_order':
		case 'repair_order':
			$result = dtb_admin_linked_from_order( $record_id, $result );
			break;
		case 'repair':
			$result = dtb_admin_linked_from_repair( $record_id, $result );
			break;
		case 'support':
			$result = dtb_admin_linked_from_ticket( $record_id, $result );
			break;
		case 'returns':
			$result = dtb_admin_linked_from_return( $record_id, $result );
			break;
	}

	return dtb_admin_apply_link_integrity_warnings( $module, $record_id, $result );
}

/**
 * Build linked records starting from a repair record.
 *
 * @param int   $repair_id
 * @param array $r  Result skeleton.
 * @return array
 */
function dtb_admin_linked_from_repair( int $repair_id, array $r ): array {
	$order_id  = absint( get_post_meta( $repair_id, '_repair_wc_order_id', true ) );
	if ( ! $order_id ) {
		$order_id = absint( get_post_meta( $repair_id, '_repair_order_id', true ) );
	}
	$user_id   = absint( get_post_meta( $repair_id, '_repair_customer_user_id', true ) );
	$email     = sanitize_email( (string) get_post_meta( $repair_id, '_repair_customer_email', true ) );
	$veeqo_id  = (string) get_post_meta( $repair_id, '_repair_veeqo_order_id', true );
	$r['customer_email'] = $email;

	if ( $order_id ) {
		$r['order_id']       = $order_id;
		$r['order_edit_url'] = (string) get_edit_post_link( $order_id );
		$r['confidence']['order'] = 'verified';

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$r['order_status'] = $order->get_status();
				if ( ! $user_id ) {
					$user_id = absint( $order->get_customer_id() );
				}
			if ( ! $email ) {
				$email = sanitize_email( $order->get_billing_email() );
				$r['customer_email'] = $email;
			}
			$r['records'][] = dtb_admin_linked_record_chip( 'order', $order_id, 'WooCommerce Order #' . $order_id, $r['order_edit_url'], 'verified', 'woocommerce' );
		} else {
			$r['confidence']['order'] = 'orphaned';
		}
	}
	} else {
		$r['confidence']['order'] = 'not_linked';
	}

	if ( $user_id ) {
		$r['customer_user_id'] = $user_id;
	}
	if ( $veeqo_id ) {
		$r['veeqo_order_id']       = $veeqo_id;
		$r['confidence']['veeqo']  = 'verified';
	}

	// Cross-link: find tickets, returns by email/user.
	$r = dtb_admin_cross_link_by_email_user( $email, $user_id, 'repair', $repair_id, $r );

	return $r;
}

/**
 * Build linked records starting from a support ticket.
 *
 * @param int   $ticket_id
 * @param array $r  Result skeleton.
 * @return array
 */
function dtb_admin_linked_from_ticket( int $ticket_id, array $r ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_support_tickets';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $exists ) {
		$r['confidence']['ticket'] = 'no_table';
		return $r;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, customer_email, customer_user_id FROM {$table} WHERE id = %d", $ticket_id ) );
	if ( ! $row ) {
		$r['confidence']['ticket'] = 'not_found';
		return $r;
	}

	$order_id = absint( $row->order_id ?? 0 );
	$email    = sanitize_email( $row->customer_email ?? '' );
	$user_id  = absint( $row->customer_user_id ?? 0 );
	$r['customer_email'] = $email;

	if ( $order_id ) {
		$r['order_id']       = $order_id;
		$r['order_edit_url'] = (string) get_edit_post_link( $order_id );
		$r['confidence']['order'] = 'verified';

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$r['order_status'] = $order->get_status();
				$r['records'][] = dtb_admin_linked_record_chip( 'order', $order_id, 'WooCommerce Order #' . $order_id, $r['order_edit_url'], 'verified', 'woocommerce' );
			} else {
				$r['confidence']['order'] = 'orphaned';
			}
		}
	} else {
		$r['confidence']['order'] = 'not_linked';
	}

	if ( $user_id ) {
		$r['customer_user_id'] = $user_id;
	}

	$r = dtb_admin_cross_link_by_email_user( $email, $user_id, 'support', $ticket_id, $r );

	return $r;
}

/**
 * Build linked records starting from a return.
 *
 * @param int   $return_id
 * @param array $r  Result skeleton.
 * @return array
 */
function dtb_admin_linked_from_return( int $return_id, array $r ): array {
	global $wpdb;

	$table = $wpdb->prefix . 'dtb_returns';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( ! $exists ) {
		$r['confidence']['returns'] = 'no_table';
		return $r;
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, customer_email, customer_user_id FROM {$table} WHERE id = %d", $return_id ) );
	if ( ! $row ) {
		$r['confidence']['returns'] = 'not_found';
		return $r;
	}

	$order_id = absint( $row->order_id ?? 0 );
	$email    = sanitize_email( $row->customer_email ?? '' );
	$user_id  = absint( $row->customer_user_id ?? 0 );
	$r['customer_email'] = $email;

	if ( $order_id ) {
		$r['order_id']       = $order_id;
		$r['order_edit_url'] = (string) get_edit_post_link( $order_id );
		$r['confidence']['order'] = 'verified';

		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$r['order_status'] = $order->get_status();
				$r['records'][] = dtb_admin_linked_record_chip( 'order', $order_id, 'WooCommerce Order #' . $order_id, $r['order_edit_url'], 'verified', 'woocommerce' );
			} else {
				$r['confidence']['order'] = 'orphaned';
			}
		}
	} else {
		$r['confidence']['order'] = 'not_linked';
	}

	if ( $user_id ) {
		$r['customer_user_id'] = $user_id;
	}

	$r = dtb_admin_cross_link_by_email_user( $email, $user_id, 'returns', $return_id, $r );

	return $r;
}

/**
 * Cross-link tickets, returns, and repairs by customer email/user, excluding
 * the originating module record to prevent self-reference.
 *
 * @param string $email
 * @param int    $user_id
 * @param string $exclude_module  'support'|'returns'|'repair'
 * @param int    $exclude_id
 * @param array  $r
 * @return array
 */
function dtb_admin_cross_link_by_email_user( string $email, int $user_id, string $exclude_module, int $exclude_id, array $r ): array {
	global $wpdb;

	// ── Support tickets ──────────────────────────────────────────────────────
	if ( 'support' !== $exclude_module && ( $email || $user_id ) ) {
		$tickets_table = $wpdb->prefix . 'dtb_support_tickets';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$t_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tickets_table ) );
		if ( $t_exists ) {
			if ( $email ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tickets_table} WHERE customer_email = %s", $email ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$tickets_table} WHERE customer_user_id = %d", $user_id ) );
			}
			$r['ticket_ids'] = array_values( array_map( 'absint', (array) $ids ) );
			foreach ( $r['ticket_ids'] as $id ) {
				$r['records'][] = dtb_admin_linked_record_chip( 'support', $id, 'Support Ticket #' . $id, admin_url( 'admin.php?page=dtb-support&ticket_id=' . $id ), 'customer_match', 'support' );
			}
		}
	}

	// ── Returns ──────────────────────────────────────────────────────────────
	if ( 'returns' !== $exclude_module && ( $email || $user_id ) ) {
		$returns_table = $wpdb->prefix . 'dtb_returns';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rt_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $returns_table ) );
		if ( $rt_exists ) {
			if ( $email ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$returns_table} WHERE customer_email = %s", $email ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$returns_table} WHERE customer_user_id = %d", $user_id ) );
			}
			$r['return_ids'] = array_values( array_map( 'absint', (array) $ids ) );
			foreach ( $r['return_ids'] as $id ) {
				$r['records'][] = dtb_admin_linked_record_chip( 'returns', $id, 'Return #' . $id, admin_url( 'admin.php?page=dtb-returns&return_id=' . $id ), 'customer_match', 'returns' );
			}
		}
	}

	// ── Repairs ──────────────────────────────────────────────────────────────
	if ( 'repair' !== $exclude_module && ( $email || $user_id ) ) {
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
			'posts_per_page' => 20,
			'fields'         => 'ids',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		$r['repair_ids'] = array_values( array_map( 'absint', $q->posts ) );
		foreach ( $r['repair_ids'] as $id ) {
			$r['records'][] = dtb_admin_linked_record_chip( 'repair', $id, 'Repair #' . $id, admin_url( 'admin.php?page=dtb-repairs&open_repair=' . $id ), 'customer_match', 'repair' );
		}
	}

	return $r;
}

/**
 * Build linked records starting from a WooCommerce order.
 *
 * @param int   $order_id Order ID.
 * @param array $r        Result skeleton.
 * @return array
 */
function dtb_admin_linked_from_order( int $order_id, array $r ): array {
	$email   = '';
	$user_id = 0;

	$r['order_id']       = $order_id ?: null;
	$r['order_edit_url'] = $order_id ? (string) get_edit_post_link( $order_id ) : '';
	$r['confidence']['order'] = $order_id ? 'verified' : 'not_linked';

	if ( $order_id && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
		if ( $order instanceof WC_Order ) {
			$r['order_status'] = $order->get_status();
			$user_id = absint( $order->get_customer_id() );
			$email   = sanitize_email( $order->get_billing_email() );
			$r['customer_email'] = $email;
			$r['records'][] = dtb_admin_linked_record_chip( 'order', $order_id, 'WooCommerce Order #' . $order_id, $r['order_edit_url'], 'verified', 'woocommerce' );
		} else {
			$r['confidence']['order'] = 'orphaned';
		}
	}

	if ( $user_id ) {
		$r['customer_user_id'] = $user_id;
	}

	return dtb_admin_cross_link_by_email_user( $email, $user_id, 'order', $order_id, $r );
}

/**
 * Add deterministic integrity warnings to linked-record payloads.
 *
 * @param string $module    Source module.
 * @param int    $record_id Source record ID.
 * @param array  $r         Linked record payload.
 * @return array
 */
function dtb_admin_apply_link_integrity_warnings( string $module, int $record_id, array $r ): array {
	$order_id = absint( $r['order_id'] ?? 0 );
	$email    = sanitize_email( (string) ( $r['customer_email'] ?? '' ) );
	$user_id  = absint( $r['customer_user_id'] ?? 0 );

	if ( 'order' !== $module && ! $order_id ) {
		$r['warnings'][] = [
			'code'    => 'missing_linked_order',
			'label'   => __( 'Missing linked order', 'drywall-toolbox' ),
			'message' => __( 'This record is not linked to a WooCommerce order.', 'drywall-toolbox' ),
			'severity'=> 'warning',
		];
	}

	if ( $order_id && function_exists( 'wc_get_order' ) ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			$r['warnings'][] = [
				'code'    => 'orphaned_wc_order',
				'label'   => __( 'Orphaned WooCommerce order', 'drywall-toolbox' ),
				'message' => sprintf(
					/* translators: %d = order ID */
					__( 'Linked order #%d no longer resolves in WooCommerce.', 'drywall-toolbox' ),
					$order_id
				),
				'severity'=> 'danger',
			];
		} else {
			$order_email = sanitize_email( $order->get_billing_email() );
			$order_user  = absint( $order->get_customer_id() );
			if ( $email && $order_email && strtolower( $email ) !== strtolower( $order_email ) ) {
				$r['mismatches'][] = [
					'code'     => 'email_order_conflict',
					'label'    => __( 'Email/order mismatch', 'drywall-toolbox' ),
					'expected' => $email,
					'actual'   => $order_email,
					'severity' => 'warning',
				];
			}
			if ( $user_id && $order_user && $user_id !== $order_user ) {
				$r['mismatches'][] = [
					'code'     => 'customer_order_conflict',
					'label'    => __( 'Customer/order mismatch', 'drywall-toolbox' ),
					'expected' => $user_id,
					'actual'   => $order_user,
					'severity' => 'warning',
				];
			}
		}
	}

	$unverified = array_filter(
		(array) ( $r['records'] ?? [] ),
		static fn( array $record ): bool => ! in_array( (string) ( $record['confidence'] ?? '' ), [ 'verified' ], true )
	);
	if ( $unverified ) {
		$r['warnings'][] = [
			'code'    => 'unverified_links',
			'label'   => __( 'Unverified linked records', 'drywall-toolbox' ),
			'message' => __( 'Some linked records were inferred from customer context rather than explicit IDs.', 'drywall-toolbox' ),
			'severity'=> 'info',
			'count'   => count( $unverified ),
		];
	}

	if ( ! empty( $r['mismatches'] ) ) {
		$r['warnings'][] = [
			'code'    => 'linked_record_mismatch',
			'label'   => __( 'Linked-record mismatch', 'drywall-toolbox' ),
			'message' => __( 'Customer, email, or order data conflicts across linked records.', 'drywall-toolbox' ),
			'severity'=> 'warning',
			'count'   => count( $r['mismatches'] ),
		];
	}

	$r['warnings']   = array_values( $r['warnings'] );
	$r['mismatches'] = array_values( $r['mismatches'] );

	return $r;
}

/**
 * Build a normalized linked-record row for workbench payloads.
 *
 * @param string $module     Module slug.
 * @param int    $id         Record ID.
 * @param string $label      Human label.
 * @param string $url        Admin URL.
 * @param string $confidence Link confidence.
 * @param string $source     Link source.
 * @return array
 */
function dtb_admin_linked_record_chip( string $module, int $id, string $label, string $url, string $confidence, string $source ): array {
	return [
		'module'           => $module,
		'id'               => $id,
		'label'            => $label,
		'url'              => $url,
		'source'           => $source,
		'confidence'       => $confidence,
		'last_verified_at' => gmdate( 'c' ),
	];
}
