<?php
/**
 * Notification template repository.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_NotificationTemplateRepository' ) ) {
	return;
}

final class DTB_NotificationTemplateRepository {
	private const OPTION_KEY = 'dtb_notification_templates';

	/**
	 * Return a template by key.
	 *
	 * @param string $key Template key.
	 * @return array{subject:string,body:string,content_type:string}
	 */
	public static function get( string $key ): array {
		$key       = sanitize_key( $key );
		$templates = self::all();
		$template  = $templates[ $key ] ?? [];

		return [
			'subject'      => sanitize_text_field( (string) ( $template['subject'] ?? self::default_subject( $key ) ) ),
			'body'         => (string) ( $template['body'] ?? self::default_body( $key ) ),
			'content_type' => sanitize_text_field( (string) ( $template['content_type'] ?? 'text/plain' ) ),
		];
	}

	/**
	 * Return all stored templates.
	 *
	 * @return array<string,array<string,string>>
	 */
	public static function all(): array {
		$templates = get_option( self::OPTION_KEY, [] );
		return is_array( $templates ) ? $templates : [];
	}

	/**
	 * Persist a template.
	 *
	 * @param string $key      Template key.
	 * @param array  $template Template data.
	 */
	public static function save( string $key, array $template ): void {
		$key = sanitize_key( $key );
		if ( '' === $key ) {
			return;
		}

		$templates         = self::all();
		$templates[ $key ] = [
			'subject'      => sanitize_text_field( (string) ( $template['subject'] ?? '' ) ),
			'body'         => wp_kses_post( (string) ( $template['body'] ?? '' ) ),
			'content_type' => sanitize_text_field( (string) ( $template['content_type'] ?? 'text/plain' ) ),
		];

		update_option( self::OPTION_KEY, $templates, false );
	}

	private static function default_subject( string $key ): string {
		return match ( $key ) {
			'order_status'  => 'Your Drywall Toolbox order update',
			'repair_status' => 'Your Drywall Toolbox repair update',
			default         => 'Drywall Toolbox notification',
		};
	}

	private static function default_body( string $key ): string {
		return match ( $key ) {
			'order_status'  => 'Your order status has been updated.',
			'repair_status' => 'Your repair status has been updated.',
			default         => 'You have a new Drywall Toolbox notification.',
		};
	}
}
