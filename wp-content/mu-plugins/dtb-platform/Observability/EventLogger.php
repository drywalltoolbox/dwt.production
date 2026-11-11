<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_EventLogger' ) ) {
	return;
}

final class DTB_EventLogger {
	public static function log( string $event, array $context = [] ): void {
		if ( function_exists( 'dtb_oo_audit' ) ) {
			dtb_oo_audit( $event, $context );
			return;
		}

		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( $event, $context );
			return;
		}

		DTB_Logger::info( $event, $context );
	}
}
