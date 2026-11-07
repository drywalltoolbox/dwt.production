<?php
/**
 * Plugin Name: DTB Order Loop Containment Guard — Compatibility Shim
 * Description: Deprecated loader retained for live-server compatibility. Permanent order write protection now lives in dtb-order-platform/Infrastructure/OrderWriteBoundary.php.
 * Version: 2.0.0
 * Author: Drywall Toolbox
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$dtb_order_write_boundary = __DIR__ . '/dtb-order-platform/Infrastructure/OrderWriteBoundary.php';

if ( ! function_exists( 'dtb_order_write_boundary_enabled' ) && file_exists( $dtb_order_write_boundary ) ) {
	require_once $dtb_order_write_boundary;
}

unset( $dtb_order_write_boundary );
