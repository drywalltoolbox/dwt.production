<?php
/**
 * Notification dispatcher.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_NotificationDispatcher' ) ) {
	return;
}

final class DTB_NotificationDispatcher {
	/**
	 * Send a rendered email notification.
	 *
	 * @param string $to           Recipient email.
	 * @param string $template_key Template key.
	 * @param array  $context      Context values.
	 * @return bool
	 */
	public static function email( string $to, string $template_key, array $context = [] ): bool {
		$to = sanitize_email( $to );
		if ( '' === $to || ! is_email( $to ) ) {
			return false;
		}

		$payload = class_exists( 'DTB_EmailTemplateRenderer' )
			? DTB_EmailTemplateRenderer::render_template( $template_key, $context )
			: [ 'subject' => 'Drywall Toolbox notification', 'body' => '', 'content_type' => 'text/plain' ];

		if ( function_exists( 'dtb_send_email' ) ) {
			return dtb_send_email(
				[
					'to'           => $to,
					'subject'      => (string) $payload['subject'],
					'message'      => (string) $payload['body'],
					'content_type' => sanitize_text_field( (string) $payload['content_type'] ),
					'is_html'      => 'text/html' === sanitize_text_field( (string) $payload['content_type'] ),
				]
			);
		}

		$headers = [ 'Content-Type: ' . sanitize_text_field( (string) $payload['content_type'] ) . '; charset=UTF-8' ];
		return (bool) wp_mail( $to, (string) $payload['subject'], (string) $payload['body'], $headers );
	}

	/**
	 * Send an SMS notification if an SMS gateway is configured.
	 *
	 * @param string $phone   Phone number.
	 * @param string $message Message.
	 * @return bool
	 */
	public static function sms( string $phone, string $message ): bool {
		return class_exists( 'DTB_SmsGateway' ) ? DTB_SmsGateway::send( $phone, $message ) : false;
	}
}
