<?php
/**
 * Operator-only WooCommerce HTML email preview using synthetic fixtures.
 *
 * No real users, orders, payments, refunds, fulfillment records, customer
 * notes, password reset tokens, or outbound messages are accessed/created.
 * Shares the actual DTB template resolver and WooCommerce CSS inlining.
 *
 * @package DrywalltoolboxCommerce
 */
namespace DTB\Commerce\Email\Preview;

use Throwable;
use WC_Email;
use WP_Error;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/SyntheticEmailFixture.php';

final class EmailPreviewService {
	public const TEST_RECIPIENT = '';
	private const MAX_HTML_BYTES = 750000;

	/** All recognized production customer and operator notification templates. */
	public static function supported_emails(): array {
		$supported = [];
		foreach ( SyntheticEmailFixture::TEMPLATES as $id => $definition ) {
			$supported[ $id ] = $definition[0];
		}
		return $supported;
	}

	/** No real orders are queried for Studio design reviews. */
	public static function recent_orders( int $limit = 30 ): array {
		unset( $limit );
		return [];
	}

	/** @return array{html:string,subject:string,heading:string,emailId:string,orderId:int,fixture:string,sourceFidelity:string}|WP_Error */
	public static function render( int $order_id, string $email_id ) {
		unset( $order_id );
		$email_id = sanitize_key( $email_id );
		if ( ! isset( SyntheticEmailFixture::TEMPLATES[ $email_id ] ) ) {
			return new WP_Error( 'dtb_email_preview_unsupported', __( 'Unrecognized WooCommerce email template.', 'drywall-toolbox' ), [ 'status' => 422 ] );
		}
		if ( ! function_exists( 'WC' ) || ! WC() || ! class_exists( WC_Email::class ) || ! function_exists( 'wc_get_template_html' ) ) {
			return new WP_Error( 'dtb_email_preview_unavailable', __( 'WooCommerce email rendering is unavailable.', 'drywall-toolbox' ), [ 'status' => 503 ] );
		}

		$registered = [];
		foreach ( WC()->mailer()->get_emails() as $existing ) {
			if ( $existing instanceof WC_Email ) {
				$registered[ sanitize_key( (string) $existing->id ) ] = $existing;
			}
		}
		$email = isset( $registered[ $email_id ] ) ? clone $registered[ $email_id ] : new WC_Email();
		$email->id = $email_id;
		$email->setup_locale();
		try {
			$html = SyntheticEmailFixture::render( $email_id, $email );
			$html = $email->style_inline( $html );
			$html = apply_filters( 'woocommerce_mail_content', $html, $email );
		} catch ( Throwable $exception ) {
			// Never return stack traces or potentially sensitive plugin data.
			return new WP_Error( 'dtb_email_preview_render_failed', __( 'Synthetic fixture failed to render this email. Review server diagnostics.', 'drywall-toolbox' ), [ 'status' => 500 ] );
		} finally {
			$email->restore_locale();
		}

		if ( ! is_string( $html ) || '' === $html || strlen( $html ) > self::MAX_HTML_BYTES ) {
			return new WP_Error( 'dtb_email_preview_payload_invalid', __( 'Rendered email is empty or exceeds the preview safety limit.', 'drywall-toolbox' ), [ 'status' => 413 ] );
		}
		$visual_only = in_array( $email_id, [ 'customer_fulfillment_created', 'customer_fulfillment_updated' ], true );
		return [
			'html' => $html,
			'subject' => '[Synthetic preview] ' . SyntheticEmailFixture::TEMPLATES[ $email_id ][0],
			'heading' => SyntheticEmailFixture::TEMPLATES[ $email_id ][0],
			'emailId' => $email_id,
			'orderId' => 0,
			'fixture' => 'synthetic',
			'sourceFidelity' => $visual_only ? 'visual-only-fulfillment' : 'production-template',
		];
	}

	/** Synthetic fixtures are never sent, even to the fixed review inbox. */
	public static function send_test( int $order_id, string $email_id ) {
		unset( $order_id, $email_id );
		return new WP_Error( 'dtb_email_preview_send_disabled', __( 'Synthetic Email Studio previews are non-deliverable. No email was sent.', 'drywall-toolbox' ), [ 'status' => 403 ] );
	}
}
