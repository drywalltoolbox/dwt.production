<?php
/**
 * Email template renderer.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_EmailTemplateRenderer' ) ) {
	return;
}

final class DTB_EmailTemplateRenderer {
	/**
	 * Render a named notification template.
	 *
	 * @param string $template_key Template key.
	 * @param array  $context      Context values.
	 * @return array
	 */
	public static function render_template( string $template_key, array $context = [] ): array {
		$template = class_exists( 'DTB_NotificationTemplateRepository' )
			? DTB_NotificationTemplateRepository::get( $template_key )
			: [ 'subject' => 'Drywall Toolbox notification', 'body' => '', 'content_type' => 'text/plain' ];

		return [
			'subject'      => self::render_text( (string) $template['subject'], $context ),
			'body'         => self::render_text( (string) $template['body'], $context ),
			'content_type' => sanitize_text_field( (string) $template['content_type'] ),
		];
	}

	/**
	 * Render scalar context values into bracketed tokens.
	 *
	 * @param string $text    Raw text.
	 * @param array  $context Context values.
	 * @return string
	 */
	public static function render_text( string $text, array $context = [] ): string {
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) || null === $value ) {
				$token = '{' . sanitize_key( (string) $key ) . '}';
				$text  = str_replace( $token, sanitize_text_field( (string) $value ), $text );
			}
		}

		return $text;
	}
}
