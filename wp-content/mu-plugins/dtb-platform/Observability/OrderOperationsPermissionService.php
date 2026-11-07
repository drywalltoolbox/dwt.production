<?php
/**
 * OrderOperationsPermissionService — DTB Platform
 *
 * Capability gate for the Operations dashboard and related AJAX/REST endpoints.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'dtb_ops_can' ) ) {
	/**
	 * Return true when the current user holds manage_options or any DTB custom cap.
	 *
	 * @param string $fallback_cap Standard WP capability to check first.
	 * @return bool
	 */
	function dtb_ops_can( string $fallback_cap = 'manage_options' ): bool {
		if ( ! function_exists( 'current_user_can' ) ) {
			return false;
		}

		if ( current_user_can( $fallback_cap ) ) {
			return true;
		}

		$dtb_caps = [];
		foreach ( [ 'DTB_CAP_OPS_ADMIN', 'DTB_CAP_ACCOUNTING', 'DTB_CAP_SUPPORT', 'DTB_CAP_CATALOG' ] as $const ) {
			if ( defined( $const ) ) {
				$dtb_caps[] = constant( $const );
			}
		}

		foreach ( $dtb_caps as $cap ) {
			if ( current_user_can( $cap ) ) {
				return true;
			}
		}

		return false;
	}
}
