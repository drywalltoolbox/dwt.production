<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_OrderOperationsAssetManager' ) ) {
	return;
}

final class DTB_OrderOperationsAssetManager {
	public static function enqueue( string $hook ): void {
		if ( function_exists( 'dtb_oo_enqueue_assets' ) ) {
			dtb_oo_enqueue_assets( $hook );
		}
	}

	public static function ops_enqueue( string $hook ): void {
		if ( function_exists( 'dtb_ops_enqueue_assets' ) ) {
			dtb_ops_enqueue_assets( $hook );
		}
	}
}
