<?php
/**
 * Infrastructure: Order Write Boundary.
 *
 * Owns the production safety boundary around WooCommerce order creation and
 * downstream order side effects. This file intentionally lives in the order
 * platform, not in a transient hotfix plugin, because duplicate-order
 * containment, external REST write blocking, email suppression, and integration
 * job gating are core order-domain invariants.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_ORDER_WRITE_BOUNDARY_ENABLED' ) ) {
	define( 'DTB_ORDER_WRITE_BOUNDARY_ENABLED', true );
}
if ( ! defined( 'DTB_ORDER_WRITE_BOUNDARY_DUPLICATE_WINDOW' ) ) {
	define( 'DTB_ORDER_WRITE_BOUNDARY_DUPLICATE_WINDOW', 6 * HOUR_IN_SECONDS );
}
if ( ! defined( 'DTB_ORDER_WRITE_BOUNDARY_RECENT_SCAN_LIMIT' ) ) {
	define( 'DTB_ORDER_WRITE_BOUNDARY_RECENT_SCAN_LIMIT', 75 );
}
if ( ! defined( 'DTB_ORDER_WRITE_BOUNDARY_AUTO_CANCEL_DUPLICATES' ) ) {
	define( 'DTB_ORDER_WRITE_BOUNDARY_AUTO_CANCEL_DUPLICATES', true );
}
if ( ! defined( 'DTB_ORDER_WRITE_BOUNDARY_BLOCK_WC_REST_ORDER_CREATION' ) ) {
	define( 'DTB_ORDER_WRITE_BOUNDARY_BLOCK_WC_REST_ORDER_CREATION', true );
}
if ( ! defined( 'DTB_ORDER_WRITE_BOUNDARY_DISABLE_LEGACY_VEEQO_DIRECT_SYNC' ) ) {
	define( 'DTB_ORDER_WRITE_BOUNDARY_DISABLE_LEGACY_VEEQO_DIRECT_SYNC', true );
}
if ( ! defined( 'DTB_ORDER_WRITE_BOUNDARY_DISABLE_MARKETPLACE_MATERIALIZATION' ) ) {
	define( 'DTB_ORDER_WRITE_BOUNDARY_DISABLE_MARKETPLACE_MATERIALIZATION', true );
}

if ( ! function_exists( 'dtb_order_write_boundary_bool_constant' ) ) {
	/** Resolve a boolean feature constant with a safe default. */
	function dtb_order_write_boundary_bool_constant( string $constant_name, bool $default ): bool {
		if ( ! defined( $constant_name ) ) {
			return $default;
		}
		return filter_var( constant( $constant_name ), FILTER_VALIDATE_BOOLEAN );
	}
}

if ( ! function_exists( 'dtb_order_write_boundary_enabled' ) ) {
	function dtb_order_write_boundary_enabled(): bool {
		return dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_ENABLED', true );
	}
}

// Backward-compatible API preserved for the previous emergency containment file.
if ( ! function_exists( 'dtb_order_loop_containment_enabled' ) ) {
	function dtb_order_loop_containment_enabled(): bool {
		return dtb_order_write_boundary_enabled();
	}
}

if ( ! function_exists( 'dtb_order_write_boundary_log' ) ) {
	/** Write structured write-boundary diagnostics. */
	function dtb_order_write_boundary_log( string $level, string $event, string $message, array $context = [] ): void {
		$context = array_merge(
			[
				'event' => sanitize_key( $event ),
				'ts'    => gmdate( 'c' ),
			],
			$context
		);

		if ( function_exists( 'wc_get_logger' ) ) {
			$map = [
				'debug' => 'debug',
				'info'  => 'info',
				'warn'  => 'warning',
				'error' => 'error',
			];
			wc_get_logger()->log(
				$map[ $level ] ?? 'info',
				$message,
				array_merge( [ 'source' => 'dtb-order-write-boundary' ], $context )
			);
			return;
		}

		error_log( '[DTB Order Write Boundary] ' . wp_json_encode( array_merge( [ 'level' => $level, 'message' => $message ], $context ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

if ( ! function_exists( 'dtb_order_write_boundary_remove_legacy_hooks' ) ) {
	/** Disable legacy side-effect paths superseded by the canonical queue pipeline. */
	function dtb_order_write_boundary_remove_legacy_hooks(): void {
		if ( ! dtb_order_write_boundary_enabled() ) {
			return;
		}

		if ( dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_DISABLE_MARKETPLACE_MATERIALIZATION', true ) ) {
			remove_action( 'dtb_marketplace_materialize_unlinked', 'dtb_marketplace_materialize_unlinked_orders' );
			remove_action( 'dtb_marketplace_reconcile', 'dtb_marketplace_materialize_unlinked_orders', 20 );
			remove_action( 'wp', 'dtb_marketplace_schedule_materialization_jobs' );
			if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( 'dtb_marketplace_materialize_unlinked' );
			}
		}
	}
}

add_action( 'plugins_loaded', 'dtb_order_write_boundary_remove_legacy_hooks', PHP_INT_MAX );
add_action( 'init', 'dtb_order_write_boundary_remove_legacy_hooks', PHP_INT_MAX );
add_action( 'wp', 'dtb_order_write_boundary_remove_legacy_hooks', PHP_INT_MAX );

if ( ! function_exists( 'dtb_order_write_boundary_secret_allows_external_write' ) ) {
	/**
	 * Permit a reviewed external order writer only through the private header.
	 *
	 * Query-string/body fallbacks are deliberately not supported because shared
	 * secrets in URLs can leak through access logs, caches, analytics, and history.
	 */
	function dtb_order_write_boundary_secret_allows_external_write( WP_REST_Request $request ): bool {
		if ( ! defined( 'DTB_EXTERNAL_ORDER_WRITE_SECRET' ) || '' === trim( (string) DTB_EXTERNAL_ORDER_WRITE_SECRET ) ) {
			return false;
		}

		$provided = trim( (string) $request->get_header( 'x-dtb-external-order-secret' ) );
		return '' !== $provided && hash_equals( (string) DTB_EXTERNAL_ORDER_WRITE_SECRET, $provided );
	}
}

if ( ! function_exists( 'dtb_order_write_boundary_is_raw_wc_order_create_request' ) ) {
	/** Detect direct WooCommerce REST order creation outside the DTB pipeline. */
	function dtb_order_write_boundary_is_raw_wc_order_create_request( WP_REST_Request $request ): bool {
		if ( 'POST' !== strtoupper( (string) $request->get_method() ) ) {
			return false;
		}

		return (bool) preg_match( '#^/wc/v[1-3]/orders/?$#', (string) $request->get_route() );
	}
}

add_filter(
	'rest_pre_dispatch',
	static function ( $result, $server, WP_REST_Request $request ) {
		if ( null !== $result || ! dtb_order_write_boundary_enabled() ) {
			return $result;
		}
		if ( ! dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_BLOCK_WC_REST_ORDER_CREATION', true ) ) {
			return $result;
		}
		if ( ! dtb_order_write_boundary_is_raw_wc_order_create_request( $request ) ) {
			return $result;
		}
		if ( dtb_order_write_boundary_secret_allows_external_write( $request ) ) {
			dtb_order_write_boundary_log(
				'warn',
				'external_wc_order_write_allowed',
				'External WooCommerce REST order creation allowed by DTB_EXTERNAL_ORDER_WRITE_SECRET.',
				[ 'route' => (string) $request->get_route() ]
			);
			return $result;
		}

		dtb_order_write_boundary_log(
			'error',
			'external_wc_order_write_blocked',
			'Blocked raw WooCommerce REST order creation. Orders must enter through the DTB checkout/order pipeline.',
			[
				'route'      => (string) $request->get_route(),
				'user_id'    => get_current_user_id(),
				'user_agent' => substr( sanitize_text_field( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ), 0, 240 ),
			]
		);

		return new WP_REST_Response(
			[
				'code'    => 'dtb_external_order_creation_blocked',
				'message' => 'External WooCommerce REST order creation is disabled. Use the DTB checkout/order pipeline or configure DTB_EXTERNAL_ORDER_WRITE_SECRET for a reviewed integration exception.',
			],
			403
		);
	},
	-100,
	3
);

if ( ! function_exists( 'dtb_order_loop_order_statuses' ) ) {
	/** Statuses eligible to serve as canonical/duplicate candidates. */
	function dtb_order_loop_order_statuses(): array {
		return [ 'pending', 'on-hold', 'processing', 'completed', 'failed' ];
	}
}

if ( ! function_exists( 'dtb_order_loop_diagnostic_statuses' ) ) {
	/**
	 * Preserve the legacy order-loop diagnostics status set exactly.
	 *
	 * Cancelled duplicates remain visible while refunded records remain outside
	 * this operational diagnostic, matching the original containment endpoint.
	 */
	function dtb_order_loop_diagnostic_statuses(): array {
		return [ 'cancelled', 'pending', 'on-hold', 'processing', 'completed', 'failed' ];
	}
}

if ( ! function_exists( 'dtb_order_loop_line_signature' ) ) {
	/** Build a deterministic line-item signature for duplicate detection. */
	function dtb_order_loop_line_signature( WC_Order $order ): array {
		$lines = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			$sku     = $product ? (string) $product->get_sku() : '';
			$lines[] = [
				'product_id'   => absint( $item->get_product_id() ),
				'variation_id' => absint( $item->get_variation_id() ),
				'sku'          => sanitize_text_field( $sku ),
				'quantity'     => max( 1, absint( $item->get_quantity() ) ),
				'total'        => function_exists( 'wc_format_decimal' )
					? wc_format_decimal( (string) $item->get_total(), 2 )
					: (string) round( (float) $item->get_total(), 2 ),
			];
		}

		usort(
			$lines,
			static function ( array $a, array $b ): int {
				return ( $a['product_id'] <=> $b['product_id'] )
					?: ( $a['variation_id'] <=> $b['variation_id'] )
					?: strcmp( (string) $a['sku'], (string) $b['sku'] )
					?: ( $a['quantity'] <=> $b['quantity'] )
					?: strcmp( (string) $a['total'], (string) $b['total'] );
			}
		);

		return $lines;
	}
}

if ( ! function_exists( 'dtb_order_loop_fingerprint' ) ) {
	/** Build a stable duplicate-detection fingerprint for a WooCommerce order. */
	function dtb_order_loop_fingerprint( WC_Order $order ): string {
		$lines = dtb_order_loop_line_signature( $order );
		if ( empty( $lines ) ) {
			return '';
		}

		$payload = [
			'billing_email' => strtolower( sanitize_email( (string) $order->get_billing_email() ) ),
			'currency'      => sanitize_text_field( (string) $order->get_currency() ),
			'total'         => function_exists( 'wc_format_decimal' )
				? wc_format_decimal( (string) $order->get_total(), 2 )
				: (string) round( (float) $order->get_total(), 2 ),
			'payment'       => sanitize_key( (string) $order->get_payment_method() ),
			'billing'       => [
				'first_name' => strtolower( sanitize_text_field( (string) $order->get_billing_first_name() ) ),
				'last_name'  => strtolower( sanitize_text_field( (string) $order->get_billing_last_name() ) ),
				'postcode'   => strtoupper( sanitize_text_field( (string) $order->get_billing_postcode() ) ),
				'country'    => strtoupper( sanitize_text_field( (string) $order->get_billing_country() ) ),
			],
			'shipping'      => [
				'first_name' => strtolower( sanitize_text_field( (string) $order->get_shipping_first_name() ) ),
				'last_name'  => strtolower( sanitize_text_field( (string) $order->get_shipping_last_name() ) ),
				'postcode'   => strtoupper( sanitize_text_field( (string) $order->get_shipping_postcode() ) ),
				'country'    => strtoupper( sanitize_text_field( (string) $order->get_shipping_country() ) ),
			],
			'lines'         => $lines,
		];

		return 'dtb-order:' . hash( 'sha256', wp_json_encode( $payload ) ?: '' );
	}
}

if ( ! function_exists( 'dtb_order_loop_write_fingerprint' ) ) {
	/** Persist a duplicate-detection fingerprint when it changed. */
	function dtb_order_loop_write_fingerprint( WC_Order $order, string $fingerprint ): void {
		if ( '' === $fingerprint || (string) $order->get_meta( '_dtb_order_loop_fingerprint', true ) === $fingerprint ) {
			return;
		}

		$order->update_meta_data( '_dtb_order_loop_fingerprint', $fingerprint );
		$order->update_meta_data( '_dtb_order_loop_scanned_at', gmdate( 'c' ) );
		$order->save_meta_data();
	}
}

if ( ! function_exists( 'dtb_order_loop_find_existing_duplicate' ) ) {
	/** Find an older order representing the same checkout/cart payload. */
	function dtb_order_loop_find_existing_duplicate( WC_Order $order ): ?WC_Order {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}

		$fingerprint = dtb_order_loop_fingerprint( $order );
		if ( '' === $fingerprint ) {
			return null;
		}

		$current_id = absint( $order->get_id() );
		$email      = strtolower( sanitize_email( (string) $order->get_billing_email() ) );
		$window     = max( HOUR_IN_SECONDS, (int) DTB_ORDER_WRITE_BOUNDARY_DUPLICATE_WINDOW );

		// Canonical checkout orders are deduplicated only by their server-issued
		// session/idempotency identities. A content fingerprint is intentionally
		// insufficient: a customer may legitimately place the same order twice.
		if ( function_exists( 'dtb_checkout_handoff_is_order' ) && dtb_checkout_handoff_is_order( $order ) ) {
			$identity_query = [ 'relation' => 'OR' ];
			foreach ( [ '_dtb_checkout_session_id', '_dtb_checkout_idempotency_key' ] as $meta_key ) {
				$value = trim( (string) $order->get_meta( $meta_key, true ) );
				if ( '' !== $value ) {
					$identity_query[] = [ 'key' => $meta_key, 'value' => $value, 'compare' => '=' ];
				}
			}
			if ( count( $identity_query ) === 1 ) {
				return null;
			}
			$identity_matches = wc_get_orders( [
				'limit'      => 2,
				'orderby'    => 'date',
				'order'      => 'ASC',
				'status'     => dtb_order_loop_order_statuses(),
				'meta_query' => $identity_query,
			] );
			foreach ( $identity_matches as $candidate ) {
				if ( $candidate instanceof WC_Order && absint( $candidate->get_id() ) !== $current_id ) {
					return $candidate;
				}
			}
			return null;
		}

		$meta_matches = wc_get_orders( [
			'limit'        => 10,
			'orderby'      => 'date',
			'order'        => 'ASC',
			'status'       => dtb_order_loop_order_statuses(),
			'date_created' => '>' . gmdate( 'Y-m-d H:i:s', time() - $window ),
			'meta_query'   => [
				[
					'key'     => '_dtb_order_loop_fingerprint',
					'value'   => $fingerprint,
					'compare' => '=',
				],
			],
		] );

		foreach ( $meta_matches as $candidate ) {
			if ( $candidate instanceof WC_Order && absint( $candidate->get_id() ) !== $current_id ) {
				return $candidate;
			}
		}

		$args = [
			'limit'        => max( 10, (int) DTB_ORDER_WRITE_BOUNDARY_RECENT_SCAN_LIMIT ),
			'orderby'      => 'date',
			'order'        => 'ASC',
			'status'       => dtb_order_loop_order_statuses(),
			'date_created' => '>' . gmdate( 'Y-m-d H:i:s', time() - $window ),
		];
		if ( '' !== $email ) {
			$args['billing_email'] = $email;
		}

		foreach ( wc_get_orders( $args ) as $candidate ) {
			if ( ! $candidate instanceof WC_Order || absint( $candidate->get_id() ) === $current_id ) {
				continue;
			}

			$candidate_fingerprint = dtb_order_loop_fingerprint( $candidate );
			dtb_order_loop_write_fingerprint( $candidate, $candidate_fingerprint );
			if ( $candidate_fingerprint === $fingerprint ) {
				return $candidate;
			}
		}

		return null;
	}
}

if ( ! function_exists( 'dtb_order_loop_cancel_scheduled_order_jobs' ) ) {
	/** Unschedule known DTB side-effect jobs for a contained duplicate order. */
	function dtb_order_loop_cancel_scheduled_order_jobs( int $order_id ): void {
		if ( $order_id <= 0 || ! function_exists( 'as_unschedule_all_actions' ) ) {
			return;
		}

		$hooks = [
			'dtb_order_sync_veeqo',
			'dtb_order_sync_veeqo_status',
			'dtb_order_sync_quickbooks',
			'dtb_order_issue_rewards',
			'dtb_order_send_notification',
			'dtb_order_refresh_tracking_projection',
			'dtb_order_archive_completed',
			'dtb_order_handle_refund',
		];

		foreach ( $hooks as $hook ) {
			as_unschedule_all_actions( $hook, [ $order_id, [] ], 'dtb-orders' );
			as_unschedule_all_actions( $hook, [ $order_id ], 'dtb-orders' );
		}
	}
}

if ( ! function_exists( 'dtb_order_loop_mark_duplicate' ) ) {
	/** Mark, suppress, and optionally cancel a duplicate order. */
	function dtb_order_loop_mark_duplicate( WC_Order $order, WC_Order $existing_order, string $source = 'runtime' ): void {
		static $running = false;

		if ( $running || absint( $order->get_id() ) === absint( $existing_order->get_id() ) ) {
			return;
		}

		$fingerprint = dtb_order_loop_fingerprint( $order );
		dtb_order_loop_write_fingerprint( $order, $fingerprint );
		if ( absint( $order->get_meta( '_dtb_duplicate_of_order_id', true ) ) === absint( $existing_order->get_id() ) ) {
			return;
		}

		$order->update_meta_data( '_dtb_duplicate_of_order_id', absint( $existing_order->get_id() ) );
		$order->update_meta_data( '_dtb_order_loop_duplicate_source', sanitize_key( $source ) );
		$order->update_meta_data( '_dtb_order_loop_duplicate_detected_at', gmdate( 'c' ) );
		$order->update_meta_data( '_dtb_order_loop_fingerprint', $fingerprint );
		$order->update_meta_data( '_dtb_order_loop_suppress_emails', '1' );
		$order->update_meta_data( '_dtb_skip_integrations', '1' );
		$order->add_order_note(
			sprintf(
				'[DTB Order Write Boundary] Duplicate order contained. Existing canonical order: #%d. Source: %s.',
				absint( $existing_order->get_id() ),
				sanitize_key( $source )
			),
			false,
			false
		);
		$order->save_meta_data();

		dtb_order_loop_cancel_scheduled_order_jobs( (int) $order->get_id() );
		dtb_order_write_boundary_log(
			'error',
			'duplicate_order_contained',
			'Duplicate WooCommerce order contained.',
			[
				'order_id'     => (int) $order->get_id(),
				'duplicate_of' => (int) $existing_order->get_id(),
				'source'       => sanitize_key( $source ),
			]
		);

		if ( ! dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_AUTO_CANCEL_DUPLICATES', true ) ) {
			return;
		}
		if ( in_array( (string) $order->get_status(), [ 'cancelled', 'refunded' ], true ) ) {
			return;
		}

		$running = true;
		try {
			$order->update_status(
				'cancelled',
				sprintf( '[DTB Order Write Boundary] Auto-cancelled duplicate of order #%d.', absint( $existing_order->get_id() ) ),
				false
			);
		} finally {
			$running = false;
		}
	}
}

if ( ! function_exists( 'dtb_order_loop_tag_order' ) ) {
	/** Fingerprint an order and contain it when an older duplicate exists. */
	function dtb_order_loop_tag_order( WC_Order $order, string $source = 'runtime' ): ?WC_Order {
		if ( ! dtb_order_write_boundary_enabled() ) {
			return null;
		}

		$fingerprint = dtb_order_loop_fingerprint( $order );
		if ( '' === $fingerprint ) {
			return null;
		}

		dtb_order_loop_write_fingerprint( $order, $fingerprint );
		$existing = dtb_order_loop_find_existing_duplicate( $order );
		if ( $existing instanceof WC_Order ) {
			dtb_order_loop_mark_duplicate( $order, $existing, $source );
			return $existing;
		}

		return null;
	}
}

if ( ! function_exists( 'dtb_order_loop_is_duplicate' ) ) {
	/** Backward-compatible duplicate predicate used by legacy callers. */
	function dtb_order_loop_is_duplicate( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		if ( absint( $order->get_meta( '_dtb_duplicate_of_order_id', true ) ) > 0 ) {
			return true;
		}
		return dtb_order_loop_tag_order( $order, 'duplicate_predicate' ) instanceof WC_Order;
	}
}

/**
 * WooPayments test orders are never eligible for external order side effects.
 * Persisted WooPayments order mode remains authoritative after test mode is
 * turned off. Missing/unknown mode is fail-closed until a persisted production
 * value is available.
 */
if ( ! function_exists( 'dtb_order_is_woopayments_test_order' ) ) {
	function dtb_order_is_woopayments_test_order( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		$gateway = sanitize_key( (string) $order->get_payment_method() );
		if ( 'woocommerce_payments' !== $gateway && ! str_starts_with( $gateway, 'woocommerce_payments_' ) ) {
			return false;
		}

		$mode = sanitize_key( (string) $order->get_meta( '_wcpay_mode', true ) );
		if ( 'test' === $mode ) {
			return true;
		}
		if ( 'prod' === $mode || 'production' === $mode || 'live' === $mode ) {
			return false;
		}
		// An unmarked WooPayments order is NOT safe for provider writes: mode
		// metadata can arrive asynchronously, or an old job can run after mode
		// has changed. Only an explicit persisted production value opts in.
		return true;
	}
}

if ( ! function_exists( 'dtb_order_write_boundary_order_should_skip_side_effects' ) ) {
	/** Determine whether all downstream side effects must be suppressed. */
	function dtb_order_write_boundary_order_should_skip_side_effects( $order ): bool {
		if ( ! $order instanceof WC_Order ) {
			return false;
		}
		// Test-payment isolation is unconditional, even if legacy duplicate
		// containment is explicitly disabled by an operator.
		if ( dtb_order_is_woopayments_test_order( $order ) ) {
			return true;
		}
		if ( ! dtb_order_write_boundary_enabled() ) {
			return false;
		}
		if ( '1' === (string) $order->get_meta( '_dtb_skip_integrations', true ) ) {
			return true;
		}
		if ( absint( $order->get_meta( '_dtb_duplicate_of_order_id', true ) ) > 0 ) {
			return true;
		}
		return dtb_order_loop_tag_order( $order, 'side_effect_gate' ) instanceof WC_Order;
	}
}

if ( ! function_exists( 'dtb_order_write_boundary_should_block_job' ) ) {
	/** Gate queue scheduling/execution for duplicate-sensitive jobs. */
	function dtb_order_write_boundary_should_block_job( string $job_type, int $order_id, array $args = [] ): bool {
		$side_effect_jobs = [
			'dtb_order_sync_veeqo',
			'dtb_order_sync_veeqo_status',
			'dtb_order_sync_quickbooks',
			'dtb_order_issue_rewards',
			'dtb_order_send_notification',
			'dtb_order_refresh_tracking_projection',
			'dtb_order_archive_completed',
			'dtb_order_handle_refund',
		];

		if ( ! in_array( $job_type, $side_effect_jobs, true ) || $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		return $order instanceof WC_Order && dtb_order_write_boundary_order_should_skip_side_effects( $order );
	}
}

if ( ! function_exists( 'dtb_order_loop_email_guard' ) ) {
	/** Suppress WooCommerce order emails for contained duplicates. */
	function dtb_order_loop_email_guard( bool $enabled, $order ): bool {
		if ( ! $enabled || ! dtb_order_write_boundary_enabled() || ! $order instanceof WC_Order ) {
			return $enabled;
		}
		if ( '1' === (string) $order->get_meta( '_dtb_order_loop_suppress_emails', true ) ) {
			return false;
		}
		return dtb_order_write_boundary_order_should_skip_side_effects( $order ) ? false : $enabled;
	}
}

foreach ( [
	'woocommerce_email_enabled_new_order',
	'woocommerce_email_enabled_customer_processing_order',
	'woocommerce_email_enabled_customer_completed_order',
	'woocommerce_email_enabled_customer_on_hold_order',
	'woocommerce_email_enabled_customer_invoice',
	'woocommerce_email_enabled_customer_invoice_paid',
	'woocommerce_email_enabled_customer_note',
	'woocommerce_email_enabled_failed_order',
	'woocommerce_email_enabled_cancelled_order',
] as $dtb_order_write_boundary_email_filter ) {
	add_filter( $dtb_order_write_boundary_email_filter, 'dtb_order_loop_email_guard', 0, 2 );
}
unset( $dtb_order_write_boundary_email_filter );

add_action(
	'woocommerce_new_order',
	static function ( int $order_id, $order = null ): void {
		if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			dtb_order_loop_tag_order( $order, 'woocommerce_new_order' );
		}
	},
	0,
	2
);

add_action(
	'woocommerce_checkout_order_processed',
	static function ( int $order_id, array $posted_data = [], $order = null ): void {
		if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			dtb_order_loop_tag_order( $order, 'woocommerce_checkout_order_processed' );
		}
	},
	0,
	3
);

add_action(
	'woocommerce_store_api_checkout_order_processed',
	static function ( $order ): void {
		if ( $order instanceof WC_Order ) {
			dtb_order_loop_tag_order( $order, 'store_api_checkout_order_processed' );
		}
	},
	0,
	1
);

add_action(
	'woocommerce_order_status_changed',
	static function ( int $order_id, string $old_status, string $new_status, $order ): void {
		if ( ! $order instanceof WC_Order && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( $order instanceof WC_Order ) {
			dtb_order_loop_tag_order( $order, 'order_status_changed' );
		}
	},
	0,
	4
);

add_filter(
	'pre_as_schedule_single_action',
	static function ( $pre, int $timestamp, string $hook, array $args, string $group ) {
		if ( 'dtb-orders' !== $group ) {
			return $pre;
		}
		$order_id = absint( $args[0] ?? 0 );
		$job_args = is_array( $args[1] ?? null ) ? $args[1] : [];
		return dtb_order_write_boundary_should_block_job( $hook, $order_id, $job_args ) ? false : $pre;
	},
	0,
	5
);

if ( ! function_exists( 'dtb_order_loop_run_sweep' ) ) {
	/** Sweep recent orders and return duplicate-containment statistics. */
	function dtb_order_loop_run_sweep(): array {
		$stats = [ 'scanned' => 0, 'duplicates' => 0 ];
		if ( ! dtb_order_write_boundary_enabled() || ! function_exists( 'wc_get_orders' ) ) {
			return $stats;
		}

		$orders = wc_get_orders( [
			'limit'        => max( 10, (int) DTB_ORDER_WRITE_BOUNDARY_RECENT_SCAN_LIMIT ),
			'orderby'      => 'date',
			'order'        => 'DESC',
			'status'       => dtb_order_loop_order_statuses(),
			'date_created' => '>' . gmdate( 'Y-m-d H:i:s', time() - max( HOUR_IN_SECONDS, (int) DTB_ORDER_WRITE_BOUNDARY_DUPLICATE_WINDOW ) ),
		] );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$stats['scanned']++;
			if ( dtb_order_loop_tag_order( $order, 'sweep' ) instanceof WC_Order ) {
				$stats['duplicates']++;
			}
		}

		return $stats;
	}
}

add_action(
	'shutdown',
	static function (): void {
		if ( get_transient( 'dtb_order_write_boundary_shutdown_sweep' ) ) {
			return;
		}
		set_transient( 'dtb_order_write_boundary_shutdown_sweep', 1, 5 * MINUTE_IN_SECONDS );
		dtb_order_loop_run_sweep();
	},
	PHP_INT_MAX
);

if ( ! function_exists( 'dtb_order_loop_diagnostic_query_args' ) ) {
	/**
	 * Return the exact legacy duplicate-diagnostic query contract.
	 *
	 * The explicit meta_query/status set avoids a silent result-set change and
	 * remains compatible with the pre-migration endpoint behavior.
	 */
	function dtb_order_loop_diagnostic_query_args(): array {
		return [
			'limit'      => 20,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'status'     => dtb_order_loop_diagnostic_statuses(),
			'meta_query' => [
				[
					'key'     => '_dtb_duplicate_of_order_id',
					'compare' => 'EXISTS',
				],
			],
		];
	}
}

add_action(
	'rest_api_init',
	static function (): void {
		register_rest_route( 'dtb/v1', '/order-loop/status', [
			'methods'             => 'GET',
			'permission_callback' => static fn (): bool => current_user_can( 'manage_woocommerce' ),
			'callback'            => static function (): WP_REST_Response {
				$recent_duplicates = [];
				if ( function_exists( 'wc_get_orders' ) ) {
					foreach ( wc_get_orders( dtb_order_loop_diagnostic_query_args() ) as $order ) {
						if ( ! $order instanceof WC_Order ) {
							continue;
						}
						$recent_duplicates[] = [
							'order_id'     => (int) $order->get_id(),
							'duplicate_of' => absint( $order->get_meta( '_dtb_duplicate_of_order_id', true ) ),
							'status'       => (string) $order->get_status(),
							'source'       => (string) $order->get_meta( '_dtb_order_loop_duplicate_source', true ),
						];
					}
				}

				$disable_marketplace = dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_DISABLE_MARKETPLACE_MATERIALIZATION', true );
				$disable_veeqo       = dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_DISABLE_LEGACY_VEEQO_DIRECT_SYNC', true );

				return new WP_REST_Response( [
					'enabled'                               => dtb_order_write_boundary_enabled(),
					'block_wc_rest_order_creation'          => dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_BLOCK_WC_REST_ORDER_CREATION', true ),
					'auto_cancel_duplicates'                => dtb_order_write_boundary_bool_constant( 'DTB_ORDER_WRITE_BOUNDARY_AUTO_CANCEL_DUPLICATES', true ),
					'disable_legacy_veeqo_direct_sync'      => $disable_veeqo,
					'disable_marketplace_materialization'   => $disable_marketplace,
					'duplicate_window_seconds'               => (int) DTB_ORDER_WRITE_BOUNDARY_DUPLICATE_WINDOW,
					'recent_duplicates'                      => $recent_duplicates,
					// Compatibility aliases retained for operators/scripts using the hotfix response shape.
					'disable_legacy_veeqo_direct'            => $disable_veeqo,
					'disable_marketplace_materialize'        => $disable_marketplace,
				], 200 );
			},
		] );

		register_rest_route( 'dtb/v1', '/order-loop/sweep', [
			'methods'             => 'POST',
			'permission_callback' => static fn (): bool => current_user_can( 'manage_woocommerce' ),
			'callback'            => static fn (): WP_REST_Response => new WP_REST_Response(
				[ 'success' => true, 'stats' => dtb_order_loop_run_sweep() ],
				200
			),
		] );
	},
	20
);
