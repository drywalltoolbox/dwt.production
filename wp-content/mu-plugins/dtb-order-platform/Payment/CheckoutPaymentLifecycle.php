<?php

defined( 'ABSPATH' ) || exit;

final class DTB_CheckoutPaymentLifecycle {
	/** @var array<int,bool> */
	private static array $reconciling = [];

	public static function register(): void {
		add_action( 'woocommerce_store_api_checkout_order_processed', [ __CLASS__, 'reconcile_store_api_order' ], 200 );
		add_action( 'woocommerce_order_status_pending', [ __CLASS__, 'reconcile_order_status' ], 40 );
		add_action( 'woocommerce_order_status_on-hold', [ __CLASS__, 'reconcile_order_status' ], 40 );
		add_action( 'woocommerce_payment_complete', [ __CLASS__, 'complete' ], 20 );
		add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'processing' ], 20 );
		add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'completed' ], 20 );
		add_action( 'woocommerce_order_status_failed', [ __CLASS__, 'failed' ], 20 );
		add_action( 'woocommerce_order_status_cancelled', [ __CLASS__, 'cancelled' ], 20 );
		add_action( 'woocommerce_order_status_refunded', [ __CLASS__, 'refunded' ], 20 );
	}

	/** @param mixed $order Store API order object. */
	public static function reconcile_store_api_order( $order ): void {
		if ( $order instanceof WC_Order ) {
			self::reconcile_captured_payment( $order, 'store-api-checkout-processed' );
		}
	}

	/** @param mixed $order_id WooCommerce order ID. */
	public static function reconcile_order_status( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order ) {
			self::reconcile_captured_payment( $order, 'woocommerce-order-status' );
		}
	}

	public static function complete( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order && self::is_verified( $order ) ) {
			self::record( $order, 'payment_completed' );
		}
	}

	public static function processing( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order && self::is_verified( $order ) ) {
			// Authorization-only payments do not pass the captured-payment gate.
			self::record( $order, 'payment_confirmed' );
		}
	}

	public static function completed( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order && self::is_verified( $order ) ) {
			self::record( $order, 'payment_completed' );
		}
	}

	public static function failed( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order && self::is_dtb_checkout_order( $order ) ) {
			self::record( $order, 'payment_failed' );
		}
	}

	public static function cancelled( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order && self::is_dtb_checkout_order( $order ) ) {
			self::record( $order, 'payment_cancelled' );
		}
	}

	public static function refunded( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof WC_Order && self::is_dtb_checkout_order( $order ) ) {
			self::record( $order, 'payment_refunded' );
		}
	}

	private static function reconcile_captured_payment( WC_Order $order, string $source ): void {
		$order_id = (int) $order->get_id();
		$status   = sanitize_key( (string) $order->get_status() );

		if (
			$order_id <= 0
			|| isset( self::$reconciling[ $order_id ] )
			|| ! self::is_dtb_checkout_order( $order )
			|| (float) $order->get_total() <= 0
			|| ! in_array( $status, [ 'pending', 'on-hold' ], true )
		) {
			return;
		}

		/*
		 * Ask the active WooPayments observer to mirror provider-verified evidence.
		 * This does not trust redirects, browser flags, raw gateway prefixes, or DTB
		 * metadata written before WooCommerce records paid state and a reference.
		 */
		if (
			class_exists( 'DTB_WooPaymentsNativeCheckout' )
			&& is_callable( [ 'DTB_WooPaymentsNativeCheckout', 'observe_order_id' ] )
		) {
			DTB_WooPaymentsNativeCheckout::observe_order_id( $order_id );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order || ! self::is_verified( $order ) ) {
			return;
		}

		$reference = trim( (string) $order->get_meta( '_dtb_payment_ref', true ) );
		if ( '' === $reference ) {
			$reference = trim( (string) $order->get_transaction_id() );
		}
		if ( '' === $reference ) {
			return;
		}

		self::$reconciling[ $order_id ] = true;
		$before_status                  = sanitize_key( (string) $order->get_status() );

		try {
			// WooCommerce remains status authority and emits the normal idempotent hooks.
			$order->payment_complete( $reference );
			$reconciled  = wc_get_order( $order_id );
			$after_status = $reconciled instanceof WC_Order
				? sanitize_key( (string) $reconciled->get_status() )
				: $before_status;

			if ( in_array( $after_status, [ 'processing', 'completed' ], true ) ) {
				self::record_reconciliation( $order_id, $source, $before_status, $after_status );
			} else {
				self::log_reconciliation_warning( $order_id, $source, $after_status );
			}
		} finally {
			unset( self::$reconciling[ $order_id ] );
		}
	}

	private static function is_verified( WC_Order $order ): bool {
		return function_exists( 'dtb_checkout_handoff_has_captured_payment' )
			&& dtb_checkout_handoff_has_captured_payment( $order );
	}

	private static function is_dtb_checkout_order( WC_Order $order ): bool {
		return function_exists( 'dtb_checkout_handoff_is_order' )
			&& dtb_checkout_handoff_is_order( $order );
	}

	private static function record_reconciliation( int $order_id, string $source, string $before_status, string $after_status ): void {
		if ( ! function_exists( 'dtb_order_append_event' ) ) {
			return;
		}

		dtb_order_append_event(
			$order_id,
			'payment_status_reconciled',
			[
				'source'          => 'woocommerce-payment-lifecycle',
				'actor_type'      => 'system',
				'visibility'      => 'internal',
				'idempotency_key' => 'payment-status-reconciled:' . $order_id,
				'payload'         => [
					'trigger'         => sanitize_key( $source ),
					'before_status'   => sanitize_key( $before_status ),
					'after_status'    => sanitize_key( $after_status ),
					'event_timestamp' => gmdate( 'c' ),
				],
			]
		);
	}

	private static function log_reconciliation_warning( int $order_id, string $source, string $status ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->warning(
				'Captured DTB payment did not transition to a fulfillable WooCommerce status.',
				[
					'source'       => 'dtb-order-platform',
					'order_id'     => $order_id,
					'trigger'      => sanitize_key( $source ),
					'order_status' => sanitize_key( $status ),
				]
			);
		}
		self::alert_reconciliation_warning( $order_id, $source, $status );
	}

	private static function alert_reconciliation_warning( int $order_id, string $source, string $status ): void {
		if ( ! function_exists( 'dtb_send_email' ) ) {
			return;
		}

		$dedupe_key = 'dtb_recon_alert_' . $order_id;
		if ( false !== get_transient( $dedupe_key ) ) {
			return;
		}
		set_transient( $dedupe_key, 1, HOUR_IN_SECONDS );

		$to = sanitize_email( (string) apply_filters( 'dtb_checkout_reconciliation_alert_email', get_option( 'admin_email' ) ) );
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$order_edit_url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
		$site_name      = sanitize_text_field( (string) get_bloginfo( 'name' ) );
		$site_name      = '' !== $site_name ? $site_name : 'Drywall Toolbox';

		$subject = (string) apply_filters(
			'dtb_checkout_reconciliation_alert_subject',
			sprintf( '[%s] Order #%d: captured payment stuck unfulfillable', $site_name, $order_id ),
			$order_id
		);

		$message = (string) apply_filters(
			'dtb_checkout_reconciliation_alert_message',
			sprintf(
				"A %s payment for order #%d was captured but did not transition to a fulfillable WooCommerce status (processing/completed).\n\nTrigger: %s\nCurrent status: %s\n\nReview and manually reconcile: %s",
				$site_name,
				$order_id,
				sanitize_key( $source ),
				sanitize_key( $status ),
				$order_edit_url
			),
			$order_id
		);

		dtb_send_email(
			[
				'to'      => $to,
				'subject' => $subject,
				'message' => $message,
				'is_html' => false,
			]
		);
	}

	private static function record( WC_Order $order, string $event ): void {
		if ( ! self::is_dtb_checkout_order( $order ) ) {
			return;
		}

		$event_key = 'payment-lifecycle:' . $event . ':' . (int) $order->get_id();
		if ( function_exists( 'dtb_order_append_event' ) ) {
			dtb_order_append_event(
				(int) $order->get_id(),
				$event,
				[
					'source'          => 'woocommerce-payment-lifecycle',
					'actor_type'      => 'system',
					'visibility'      => 'internal',
					'idempotency_key' => $event_key,
					'payload'         => [
						'gateway'            => sanitize_key( (string) $order->get_payment_method() ),
						'provider'           => sanitize_key( (string) $order->get_meta( '_dtb_payment_provider', true ) ),
						'provider_reference' => sanitize_text_field( (string) $order->get_meta( '_dtb_payment_ref', true ) ),
						'event_timestamp'    => gmdate( 'c' ),
					],
				]
			);
		}

		if ( in_array( $event, [ 'payment_completed', 'payment_confirmed' ], true ) ) {
			if ( function_exists( 'dtb_order_dispatch_processing_jobs' ) ) {
				dtb_order_dispatch_processing_jobs( (int) $order->get_id() );
			}
			$order->delete_meta_data( '_dtb_payment_handoff_pending' );
			$order->save_meta_data();
		}
	}
}

DTB_CheckoutPaymentLifecycle::register();
