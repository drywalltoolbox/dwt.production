<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OpsAuditLog' ) ) {
	return;
}

final class DTB_OpsAuditLog {
	public static function write( string $event, array $context = [], int $actor_id = 0 ): void {
		if ( function_exists( 'dtb_ops_audit_log' ) ) {
			dtb_ops_audit_log( $event, $context, $actor_id );
			return;
		}

		DTB_Logger::info( $event, [ 'actor_id' => $actor_id ] + $context );
	}

	public static function recent( int $limit = 50, int $offset = 0 ): array {
		if ( function_exists( 'dtb_ops_get_audit_log' ) ) {
			return dtb_ops_get_audit_log( $limit, $offset );
		}

		return [];
	}
}
