<?php
/**
 * Canonical read-only WooCommerce HTML email preview service.
 *
 * Renders registered order-backed WC_Email classes through the same DTB
 * template resolver and WooCommerce CSS inliner used by customer delivery.
 * It never calls trigger() or send().
 *
 * @package DrywalltoolboxCommerce
 */

namespace DTB\Commerce\Email\Preview;

use Throwable;
use WC_Email;
use WC_Order;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class EmailPreviewService {
	/** @var array<string,string> */
	private const ACCOUNT_EMAILS = [
		'customer_new_account' => 'New account (synthetic preview)',
		'customer_reset_password' => 'Password reset (synthetic preview)',
	];

	private const ORDER_EMAILS = [
		'customer_processing_order' => 'Processing order',
		'customer_completed_order'  => 'Completed order',
		'customer_on_hold_order'     => 'On-hold order',
		'customer_failed_order'      => 'Failed order',
		'customer_cancelled_order'   => 'Cancelled order',
		'customer_invoice'           => 'Customer invoice / order details',
		'new_order'                  => 'Admin new order',
		'cancelled_order'            => 'Admin cancelled order',
		'failed_order'               => 'Admin failed order',
	];

	/**
	 * WooCommerce email registry keys are class names, while preview requests
	 * use each WC_Email object's stable public id. Build one normalized map and
	 * reuse it for both the manifest and rendering so the selected email cannot
	 * disappear between two independent registry scans in the same request.
	 *
	 * @return array<string,WC_Email>
	 */
	private static function registered_order_emails(): array {
		if ( ! function_exists( 'WC' ) || ! WC() ) {
			return [];
		}

		$registered = [];
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( ! $email instanceof WC_Email ) {
				continue;
			}

			$email_id = sanitize_key( (string) $email->id );
			if ( '' === $email_id || ! isset( self::ORDER_EMAILS[ $email_id ] ) && ! isset( self::ACCOUNT_EMAILS[ $email_id ] ) ) {
				continue;
			}

			$registered[ $email_id ] = $email;
		}

		return $registered;
	}

	/** @return array<string,string> */
	public static function supported_emails(): array {
		$supported = [];
		foreach ( array_keys( self::registered_order_emails() ) as $email_id ) {
			$supported[ $email_id ] = self::ORDER_EMAILS[ $email_id ] ?? self::ACCOUNT_EMAILS[ $email_id ] ?? $email_id;
		}
		return $supported;
	}

	/**
	 * @return array<int,array{id:int,number:string,status:string,date:string,customer:string,total:string}>
	 */
	public static function recent_orders( int $limit = 30 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}

		$orders = wc_get_orders( [
			'limit'   => max( 1, min( 50, $limit ) ),
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		] );

		$result = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}

			$name     = trim( $order->get_formatted_billing_full_name() );
			$result[] = [
				'id'       => $order->get_id(),
				'number'   => $order->get_order_number(),
				'status'   => wc_get_order_status_name( $order->get_status() ),
				'date'     => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
				'customer' => '' !== $name ? $name : __( 'Guest customer', 'drywall-toolbox' ),
				'total'    => wp_strip_all_tags( $order->get_formatted_order_total() ),
			];
		}

		return $result;
	}

	/**
	 * Synthetic account email previews never resolve a real customer or reset key.
	 * They use the production WooCommerce classic template resolver and CSS inliner.
	 */
	private static function render_account_fixture( string $email_id ) {
		$registered = self::registered_order_emails();
		if ( ! isset( self::ACCOUNT_EMAILS[ $email_id ], $registered[ $email_id ] ) ) {
			return new WP_Error( 'dtb_email_preview_class_missing', __( 'Selected account email is not registered.', 'drywall-toolbox' ), [ 'status' => 409 ] );
		}
		$email = clone $registered[ $email_id ];
		$fixture = [
			'email' => $email,
			'email_heading' => $email_id === 'customer_new_account' ? __( 'Welcome to Drywall Toolbox', 'drywall-toolbox' ) : __( 'Reset your password', 'drywall-toolbox' ),
			'user_login' => 'preview-account',
			'user_display_name' => 'Preview Customer',
			'user_id' => 0,
			'reset_key' => 'dtb-preview-invalid-reset-key',
			'password_generated' => false,
			'set_password_url' => '',
			'blogname' => get_bloginfo( 'name', 'display' ),
			'additional_content' => '',
		];
		$email->setup_locale();
		try {
			$html = wc_get_template_html( 'emails/' . ( $email_id === 'customer_new_account' ? 'customer-new-account.php' : 'customer-reset-password.php' ), $fixture );
			$html = $email->style_inline( $html );
			$html = apply_filters( 'woocommerce_mail_content', $html, $email );
		} catch ( Throwable $exception ) {
			return new WP_Error( 'dtb_email_preview_render_failed', __( 'The synthetic account preview could not be rendered.', 'drywall-toolbox' ), [ 'status' => 500 ] );
		} finally {
			$email->restore_locale();
		}
		if ( ! is_string( $html ) || strlen( $html ) > self::MAX_HTML_BYTES ) {
			return new WP_Error( 'dtb_email_preview_payload_invalid', __( 'Preview HTML exceeded the safety limit.', 'drywall-toolbox' ), [ 'status' => 413 ] );
		}
		return [
			'html' => $html, 'subject' => $fixture['email_heading'] . ' [synthetic preview]',
			'heading' => $fixture['email_heading'], 'emailId' => $email_id, 'orderId' => 0,
		];
	}

	/** @return array{html:string,subject:string,heading:string,emailId:string,orderId:int}|WP_Error */
	public static function render( int $order_id, string $email_id ) {
		$email_id = sanitize_key( $email_id );
		if ( isset( self::ACCOUNT_EMAILS[ $email_id ] ) ) {
			return self::render_account_fixture( $email_id );
		}
		$email_id = sanitize_key( $email_id );
		$emails   = self::registered_order_emails();

		if ( ! isset( self::ORDER_EMAILS[ $email_id ] ) ) {
			return new WP_Error(
				'dtb_email_preview_unsupported',
				__( 'That email requires lifecycle-specific data that this preview does not fabricate.', 'drywall-toolbox' ),
				[ 'status' => 422 ]
			);
		}

		if ( ! isset( $emails[ $email_id ] ) ) {
			return new WP_Error(
				'dtb_email_preview_class_missing',
				__( 'WooCommerce did not register the selected email class for this request.', 'drywall-toolbox' ),
				[ 'status' => 409 ]
			);
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'dtb_email_preview_order_missing',
				__( 'The selected WooCommerce order was not found.', 'drywall-toolbox' ),
				[ 'status' => 404 ]
			);
		}

		$email = clone $emails[ $email_id ];
		$email->set_object( $order );

		if ( method_exists( $email, 'is_customer_email' ) && $email->is_customer_email() ) {
			$email->recipient = $order->get_billing_email();
		}

		if ( is_array( $email->placeholders ) ) {
			$email->placeholders['{order_date}']   = $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '';
			$email->placeholders['{order_number}'] = $order->get_order_number();
		}

		$email->setup_locale();
		try {
			$html = $email->get_content();
			$html = $email->style_inline( $html );
			$html = apply_filters( 'woocommerce_mail_content', $html, $email );
		} catch ( Throwable $exception ) {
			return new WP_Error(
				'dtb_email_preview_render_failed',
				__( 'WooCommerce could not render this email preview.', 'drywall-toolbox' ),
				[ 'status' => 500 ]
			);
		} finally {
			$email->restore_locale();
		}

		if ( ! is_string( $html ) || strlen( $html ) > 750000 ) {
			return new WP_Error(
				'dtb_email_preview_payload_invalid',
				__( 'The rendered email exceeded the preview safety limit.', 'drywall-toolbox' ),
				[ 'status' => 413 ]
			);
		}

		return [
			'html'    => $html,
			'subject' => wp_strip_all_tags( $email->get_subject() ),
			'heading' => wp_strip_all_tags( $email->get_heading() ),
			'emailId' => $email_id,
			'orderId' => $order_id,
		];
	}
}
