<?php
/**
 * Customer order-email idempotency.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_CUSTOMER_PROCESSING_EMAIL_SENT_META = '_dtb_customer_processing_email_sent_at';
const DTB_ADMIN_NEW_ORDER_EMAIL_SENT_META      = '_dtb_admin_new_order_email_sent_at';

/** Resolve the durable successful-send marker for an initial order email. */
function dtb_order_email_sent_meta_key( string $email_id ): string {
	return match ( $email_id ) {
		'customer_processing_order' => DTB_CUSTOMER_PROCESSING_EMAIL_SENT_META,
		'new_order'                  => DTB_ADMIN_NEW_ORDER_EMAIL_SENT_META,
		default                      => '',
	};
}

/** Determine whether WooCommerce reported a successful transport result. */
function dtb_order_email_was_successfully_sent( WC_Order $order, string $email_id ): bool {
	$meta_key = dtb_order_email_sent_meta_key( $email_id );
	return '' !== $meta_key && '' !== (string) $order->get_meta( $meta_key, true );
}

/**
 * Prevent an automatic processing-order email after a successful prior send.
 *
 * This is intentionally limited to the initial customer processing email.
 * Shipping, refund, customer-note, and explicitly different lifecycle emails
 * retain their own delivery policies.
 */
add_filter( 'woocommerce_email_enabled_customer_processing_order', 'dtb_order_email_processing_is_enabled', 10, 3 );
function dtb_order_email_processing_is_enabled( bool $enabled, $order, $email = null ): bool {
	if ( ! $enabled || ! $order instanceof WC_Order ) {
		return $enabled;
	}

	if ( '' === (string) $order->get_meta( DTB_CUSTOMER_PROCESSING_EMAIL_SENT_META, true ) ) {
		return true;
	}

	$order_id = (int) $order->get_id();
	$log_key  = 'dtb_processing_email_duplicate_log_' . $order_id;
	if ( false === get_transient( $log_key ) ) {
		error_log( sprintf( '[DTB Orders] Suppressed duplicate customer_processing_order email for order %d.', $order_id ) );
		set_transient( $log_key, '1', HOUR_IN_SECONDS );
	}

	return false;
}

/** Prevent an automatic admin new-order email after a successful prior send. */
add_filter( 'woocommerce_email_enabled_new_order', 'dtb_order_email_admin_new_order_is_enabled', 10, 3 );
function dtb_order_email_admin_new_order_is_enabled( bool $enabled, $order, $email = null ): bool {
	if ( ! $enabled || ! $order instanceof WC_Order ) {
		return $enabled;
	}

	return ! dtb_order_email_was_successfully_sent( $order, 'new_order' );
}

/**
 * Record only a mail transport result WooCommerce reports as successful.
 */
add_action( 'woocommerce_email_sent', 'dtb_order_email_record_successful_processing_send', 10, 3 );
function dtb_order_email_record_successful_processing_send( bool $sent, string $email_id, $email ): void {
	if ( ! $sent || ! in_array( $email_id, [ 'customer_processing_order', 'new_order' ], true ) || ! is_object( $email ) ) {
		return;
	}

	$order = $email->object ?? null;
	$meta_key = dtb_order_email_sent_meta_key( $email_id );
	if ( ! $order instanceof WC_Order || '' === $meta_key || '' !== (string) $order->get_meta( $meta_key, true ) ) {
		return;
	}

	$order->update_meta_data( $meta_key, current_time( 'mysql', true ) );
	$order->save_meta_data();
}
