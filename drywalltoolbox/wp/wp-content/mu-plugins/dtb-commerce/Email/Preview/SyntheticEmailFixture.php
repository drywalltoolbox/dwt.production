<?php
/**
 * In-memory-only fixtures for operator HTML email previews.
 * Never creates an order, user, refund, fulfillment, or password reset token.
 *
 * @package DrywalltoolboxCommerce
 */
namespace DTB\Commerce\Email\Preview;

defined( 'ABSPATH' ) || exit;

final class SyntheticPreviewOrder extends \WC_Order {
	public function get_order_number(): string {
		return 'DTB-PREVIEW';
	}
	public function get_view_order_url(): string {
		return 'https://example.invalid/preview-order';
	}
	public function get_checkout_payment_url( $on_checkout = false ): string {
		unset( $on_checkout );
		return 'https://example.invalid/preview-payment';
	}
}

final class SyntheticEmailFixture {
	/** Public-facing email types, with admin and account variants included. */
	public const TEMPLATES = [
		'customer_processing_order' => [ 'Processing order', 'customer-processing-order.php' ],
		'customer_completed_order' => [ 'Completed order', 'customer-completed-order.php' ],
		'customer_on_hold_order' => [ 'On-hold order', 'customer-on-hold-order.php' ],
		'customer_failed_order' => [ 'Failed order', 'customer-failed-order.php' ],
		'customer_cancelled_order' => [ 'Cancelled order', 'customer-cancelled-order.php' ],
		'customer_refunded_order' => [ 'Refunded order', 'customer-refunded-order.php' ],
		'customer_invoice' => [ 'Invoice / payment details', 'customer-invoice.php' ],
		'customer_note' => [ 'Customer note', 'customer-note.php' ],
		'customer_fulfillment_created' => [ 'Shipment created', 'customer-fulfillment-created.php' ],
		'customer_fulfillment_updated' => [ 'Shipment updated', 'customer-fulfillment-updated.php' ],
		'customer_new_account' => [ 'New account', 'customer-new-account.php' ],
		'customer_reset_password' => [ 'Password reset', 'customer-reset-password.php' ],
		'new_order' => [ 'Admin new order', 'admin-new-order.php' ],
		'cancelled_order' => [ 'Admin cancelled order', 'admin-cancelled-order.php' ],
		'failed_order' => [ 'Admin failed order', 'admin-failed-order.php' ],
	];

	public static function order( string $email_id ): SyntheticPreviewOrder {
		$order = new SyntheticPreviewOrder();
		$statuses = [
			'customer_on_hold_order' => 'on-hold',
			'customer_failed_order' => 'failed',
			'failed_order' => 'failed',
			'customer_cancelled_order' => 'cancelled',
			'cancelled_order' => 'cancelled',
			'customer_completed_order' => 'completed',
			'customer_invoice' => 'pending',
		];
		$order->set_status( $statuses[ $email_id ] ?? 'processing' );
		$order->set_currency( 'USD' );
		$order->set_date_created( gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) );
		$order->set_billing_first_name( 'Preview' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_email( 'preview@example.invalid' );
		$order->set_billing_address_1( '100 Sample Street' );
		$order->set_billing_city( 'Sample City' );
		$order->set_billing_state( 'MN' );
		$order->set_billing_postcode( '00000' );
		$order->set_billing_country( 'US' );
		$order->set_shipping_first_name( 'Preview' );
		$order->set_shipping_last_name( 'Customer' );
		$order->set_shipping_address_1( '100 Sample Street' );
		$order->set_shipping_city( 'Sample City' );
		$order->set_shipping_state( 'MN' );
		$order->set_shipping_postcode( '00000' );
		$order->set_shipping_country( 'US' );
		$order->set_payment_method_title( 'Sample payment method' );
		$item = new \WC_Order_Item_Product();
		$item->set_name( 'Professional drywall finishing tool — sample' );
		$item->set_quantity( 2 );
		$item->set_subtotal( '129.00' );
		$item->set_total( '129.00' );
		$order->add_item( $item );
		$order->set_shipping_total( '12.00' );
		$order->set_total( '141.00' );
		return $order;
	}

	/** Render a real mapped HTML template against synthetic, unsaved objects. */
	public static function render( string $email_id, \WC_Email $email ): string {
		$account = in_array( $email_id, [ 'customer_new_account', 'customer_reset_password' ], true );
		$order = $account ? null : self::order( $email_id );
		if ( $order ) {
			$email->set_object( $order );
			$email->recipient = 'preview@example.invalid';
		}
		$email->id = $email_id;
		$heading = self::TEMPLATES[ $email_id ][0];
		$vars = [
			'email' => $email,
			'email_heading' => $heading,
			'order' => $order,
			'sent_to_admin' => in_array( $email_id, [ 'new_order', 'cancelled_order', 'failed_order' ], true ),
			'plain_text' => false,
			'additional_content' => '',
			'partial_refund' => true,
			'customer_note' => 'This is a simulated customer note for design review.',
			'user_login' => 'preview-account',
			'user_display_name' => 'Preview Customer',
			'user_id' => 0,
			'reset_key' => 'dtb-preview-nonfunctional',
			'password_generated' => false,
			'set_password_url' => '',
			'blogname' => get_bloginfo( 'name', 'display' ),
		];

		// Fulfillment templates require a real WooCommerce Fulfillment entity for
		// their extension hooks. Do not fabricate an object accepted by providers
		// or pretend that an illustrative panel exercises those hooks.
		if ( in_array( $email_id, [ 'customer_fulfillment_created', 'customer_fulfillment_updated' ], true ) ) {
			return self::render_fulfillment_visual( $order, $email, $heading );
		}
		return wc_get_template_html( 'emails/' . self::TEMPLATES[ $email_id ][1], $vars );
	}

	/** Explicit visual-only shipment simulation; no provider/fulfillment hooks. */
	private static function render_fulfillment_visual( SyntheticPreviewOrder $order, \WC_Email $email, string $heading ): string {
		ob_start();
		do_action( 'woocommerce_email_header', $heading, $email );
		if ( function_exists( '\\DTB\\Commerce\\Email\\dtb_email_v4_order_hero' ) ) {
			echo \DTB\Commerce\Email\dtb_email_v4_order_hero( $order, $email ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '<table role="presentation" width="87%" align="center" style="margin:24px auto;background:#f4f7fa;border:1px solid #dce3ed;border-radius:8px"><tr><td style="padding:24px;color:#071b45;font:14px Arial,sans-serif">'
			. '<strong>Shipment preview — simulated</strong><p style="color:#65738f">Carrier: Sample Carrier</p><p style="color:#65738f">Tracking: PREVIEW-ONLY</p>'
			. '<p style="color:#65738f">Fulfillment-specific hooks and item allocation require a separate isolated fixture test.</p></td></tr></table>';
		do_action( 'woocommerce_email_footer', $email );
		return (string) ob_get_clean();
	}
}
