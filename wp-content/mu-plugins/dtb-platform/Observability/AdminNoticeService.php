<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_AdminNoticeService' ) ) {
	return;
}

final class DTB_AdminNoticeService {
	public static function add_error( string $message ): void {
		self::queue( 'error', $message );
	}

	private static function queue( string $type, string $message ): void {
		add_action(
			'admin_notices',
			static function () use ( $type, $message ): void {
				printf(
					'<div class="notice notice-%1$s"><p>%2$s</p></div>',
					esc_attr( $type ),
					esc_html( $message )
				);
			}
		);
	}
}
