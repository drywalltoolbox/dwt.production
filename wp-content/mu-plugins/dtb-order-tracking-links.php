<?php
/**
 * Plugin Name: DTB Order Tracking Links
 * Description: Routes customer-facing product orders to the React order tracking page and injects tracking links into customer emails.
 * Version: 1.0.1
 * Author: Drywall Toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_tracking_links_is_public_request' ) ) {
	function dtb_tracking_links_is_public_request(): bool {
		return ! is_admin()
			&& ! ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			&& ! ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			&& ! ( defined( 'DOING_CRON' ) && DOING_CRON )
			&& ! ( defined( 'WP_CLI' ) && WP_CLI );
	}
}

if ( ! function_exists( 'dtb_tracking_links_frontend_base_for_order' ) ) {
	function dtb_tracking_links_frontend_base_for_order( WC_Order $order ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return rtrim( home_url( '/' ), '/' );
	}
}

if ( ! function_exists( 'dtb_order_tracking_url' ) ) {
	function dtb_order_tracking_url( WC_Order $order ): string {
		$base = dtb_tracking_links_frontend_base_for_order( $order );
		$url  = $base . '/order-tracking/' . absint( $order->get_id() );

		return add_query_arg( 'order_key', rawurlencode( (string) $order->get_order_key() ), $url );
	}
}

if ( ! function_exists( 'dtb_order_tracking_checkout_complete_url' ) ) {
	function dtb_order_tracking_checkout_complete_url( WC_Order $order ): string {
		return add_query_arg( 'checkout_complete', '1', dtb_order_tracking_url( $order ) );
	}
}

/*
 * Deliberately NOT rewriting `woocommerce_get_checkout_order_received_url` and
 * NOT redirecting away from `/checkout/order-received/{id}/` at `template_redirect`.
 *
 * That URL is the provider return target for redirect-based payment methods
 * (3DS and some wallets). WooCommerce/provider order-received handling must run
 * before any customer-facing handoff so verified payment state is persisted.
 * Redirecting away before that page renders can leave an order pending until a
 * provider webhook synchronizes its authoritative status.
 *
 * Instead, redirect to the React tracking page from `woocommerce_thankyou`,
 * which WooCommerce's own order-received template fires only after it has
 * already resolved the order's real payment status. The customer still lands
 * on the tracking page; it just now reflects the correct status.
 */
add_action(
	'woocommerce_thankyou',
	static function ( $order_id ): void {
		if ( ! dtb_tracking_links_is_public_request() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}

		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $key && ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return;
		}

		$url = dtb_order_tracking_checkout_complete_url( $order );
		echo '<script>window.location.replace(' . wp_json_encode( $url ) . ');</script>';
	},
	100
);

if ( ! function_exists( 'dtb_order_tracking_cta_for_email' ) ) {
	/**
	 * Resolve the lifecycle-appropriate primary CTA (label + URL) for the
	 * shared order-tracking button below, and whether a secondary "View
	 * order details" text link should also render alongside it.
	 *
	 * Deliberately skips `customer_invoice` and `customer_failed_order`:
	 * both already render their own dedicated, more specific CTA
	 * (`dtb_email_button()` -> get_checkout_payment_url()) earlier in those
	 * templates' own intro section — a second, generic button here would
	 * compete with it rather than add anything.
	 *
	 * @param WC_Order $order Order object.
	 * @param WC_Email $email Email object (its `id` identifies which
	 *                        lifecycle email is currently sending).
	 * @return array{label:string,url:string,secondary:bool}|null Null means
	 *         render no button at all for this email.
	 */
	function dtb_order_tracking_cta_for_email( WC_Order $order, $email ): ?array {
		$email_id     = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
		$tracking_url = dtb_order_tracking_url( $order );

		if ( '' === $tracking_url ) {
			return null;
		}

		switch ( $email_id ) {
			case 'customer_invoice':
			case 'customer_failed_order':
				return null;

			case 'customer_processing_order':
				// The only meaningful "order details" view for this store is
				// the same tracking page this button already links to, so a
				// second link with a different label but an identical
				// destination would be a redundant, not a secondary, action.
				return [ 'label' => __( 'Track your order', 'drywall-toolbox' ), 'url' => $tracking_url, 'secondary' => false ];

			case 'customer_on_hold_order':
				if ( $order->needs_payment() ) {
					// Here a secondary link is genuinely useful: "Pay
					// securely" and "View order details" go to two
					// different places (checkout vs. the tracking page).
					return [ 'label' => __( 'Pay securely', 'drywall-toolbox' ), 'url' => $order->get_checkout_payment_url(), 'secondary' => true ];
				}
				return [ 'label' => __( 'View order details', 'drywall-toolbox' ), 'url' => $tracking_url, 'secondary' => false ];

			case 'customer_completed_order':
			case 'customer_cancelled_order':
			case 'customer_refunded_order':
			case 'customer_note':
				return [ 'label' => __( 'View order details', 'drywall-toolbox' ), 'url' => $tracking_url, 'secondary' => false ];

			default:
				return [ 'label' => __( 'View order details', 'drywall-toolbox' ), 'url' => $tracking_url, 'secondary' => false ];
		}
	}
}

add_action(
	'woocommerce_email_after_order_table',
	static function ( $order, $sent_to_admin, $plain_text, $email ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		$cta = dtb_order_tracking_cta_for_email( $order, $email );
		if ( null === $cta ) {
			return;
		}

		$tracking_url = dtb_order_tracking_url( $order );
		$font_stack   = function_exists( 'dtb_email_font_stack' ) ? dtb_email_font_stack() : "'Geist',Arial,sans-serif";

		if ( $plain_text ) {
			echo "\n" . esc_html( $cta['label'] ) . ': ' . esc_url_raw( $cta['url'] ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			if ( ! empty( $cta['secondary'] ) && $cta['url'] !== $tracking_url ) {
				echo esc_html__( 'View order details', 'drywall-toolbox' ) . ': ' . esc_url_raw( $tracking_url ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return;
		}

		echo '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:26px 0 4px;border-collapse:separate;">';
		echo '<tr><td align="center" style="padding:0;">';
		echo '<a href="' . esc_url( $cta['url'] ) . '" style="display:block;width:100%;box-sizing:border-box;padding:15px 22px;border-radius:8px;background:#2255ee;color:#ffffff;font-family:' . esc_attr( $font_stack ) . ';font-size:15px;font-weight:800;line-height:22px;text-align:center;text-decoration:none;">' . esc_html( $cta['label'] ) . '</a>';
		echo '</td></tr></table>';

		if ( ! empty( $cta['secondary'] ) && $cta['url'] !== $tracking_url ) {
			echo '<table role="presentation" cellspacing="0" cellpadding="0" border="0" width="100%" style="margin:0 0 18px;">';
			echo '<tr><td align="center" style="padding:0;">';
			echo '<a href="' . esc_url( $tracking_url ) . '" style="color:#2255ee;font-family:' . esc_attr( $font_stack ) . ';font-size:13px;font-weight:700;text-decoration:underline;">' . esc_html__( 'View order details', 'drywall-toolbox' ) . '</a>';
			echo '</td></tr></table>';
		}
	},
	20,
	4
);
