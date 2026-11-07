<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OrderOperationsQueueInspector' ) ) {
	return;
}

final class DTB_OrderOperationsQueueInspector {
	public static function list_jobs( array $args = [] ): array {
		if ( function_exists( 'dtb_oo_get_local_queue' ) ) {
			return dtb_oo_get_local_queue( $args );
		}

		return [
			'items'      => [],
			'pagination' => [ 'page' => 1, 'per_page' => 20, 'total' => 0, 'pages' => 1 ],
		];
	}
}
