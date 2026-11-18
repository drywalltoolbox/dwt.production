<?php
/**
 * Notification job runner.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_NotificationJob' ) ) {
	return;
}

final class DTB_NotificationJob {
	public const ACTION = 'dtb_integrations_send_notification';

	/** Register async job hook. */
	public static function register(): void {
		add_action( self::ACTION, [ self::class, 'handle' ], 10, 1 );
	}

	/**
	 * Queue a notification through Action Scheduler when available.
	 *
	 * @param array $payload Notification payload.
	 */
	public static function enqueue( array $payload ): void {
		$payload = self::sanitize_payload( $payload );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION, [ $payload ], 'dtb-integrations' );
			return;
		}

		self::handle( $payload );
	}

	/**
	 * Handle a queued notification payload.
	 *
	 * @param array $payload Notification payload.
	 */
	public static function handle( array $payload ): void {
		$payload = self::sanitize_payload( $payload );
		$type    = $payload['type'];

		if ( 'email' === $type && class_exists( 'DTB_NotificationDispatcher' ) ) {
			DTB_NotificationDispatcher::email( $payload['to'], $payload['template'], $payload['context'] );
			return;
		}

		if ( 'sms' === $type && class_exists( 'DTB_NotificationDispatcher' ) ) {
			DTB_NotificationDispatcher::sms( $payload['to'], (string) ( $payload['message'] ?? '' ) );
		}
	}

	/**
	 * Sanitize job payload.
	 *
	 * @param array $payload Raw payload.
	 * @return array{type:string,to:string,template:string,message:string,context:array}
	 */
	private static function sanitize_payload( array $payload ): array {
		$context = self::sanitize_context_recursive( (array) ( $payload['context'] ?? [] ) );

		return [
			'type'     => sanitize_key( (string) ( $payload['type'] ?? 'email' ) ),
			'to'       => sanitize_text_field( (string) ( $payload['to'] ?? '' ) ),
			'template' => sanitize_key( (string) ( $payload['template'] ?? 'default' ) ),
			'message'  => sanitize_textarea_field( (string) ( $payload['message'] ?? '' ) ),
			'context'  => $context,
		];
	}

	/**
	 * Recursively sanitize template context while preserving nested structures.
	 *
	 * @param array<string|int,mixed> $value
	 * @return array<string|int,mixed>
	 */
	private static function sanitize_context_recursive( array $value ): array {
		$sanitized = [];

		foreach ( $value as $key => $item ) {
			$target_key = is_int( $key ) ? $key : self::sanitize_context_key( $key );
			$sanitized[ $target_key ] = self::sanitize_context_value( $item, is_string( $target_key ) ? $target_key : '' );
		}

		return $sanitized;
	}

	/**
	 * @param string $key Raw key.
	 */
	private static function sanitize_context_key( string $key ): string {
		$key = trim( sanitize_text_field( $key ) );
		if ( '' !== $key ) {
			return $key;
		}

		return sanitize_key( $key );
	}

	/**
	 * @param mixed  $value Raw value.
	 * @param string $key   Sanitized key name.
	 * @return mixed
	 */
	private static function sanitize_context_value( mixed $value, string $key ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			if ( preg_match( '/(?:html|body|content|markup)/i', $key ) ) {
				return wp_kses_post( $value );
			}

			return sanitize_text_field( $value );
		}

		if ( is_array( $value ) ) {
			return self::sanitize_context_recursive( $value );
		}

		if ( is_object( $value ) ) {
			return self::sanitize_context_recursive( get_object_vars( $value ) );
		}

		return null;
	}
}

DTB_NotificationJob::register();
