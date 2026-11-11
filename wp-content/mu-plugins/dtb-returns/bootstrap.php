<?php
/**
 * DTB Returns — bootstrap
 *
 * Loaded by 00-dtb-loader.php. Registers all sub-components.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$_dtb_returns_dir = __DIR__;

$_dtb_returns_files = [
	'Domain/ReturnStatus.php',
	'Domain/ReturnEntity.php',
	'Infrastructure/ReturnRepository.php',
	'Services/ReturnWorkflowTransitionMap.php',
	'Services/ReturnService.php',
	'Services/PublicReturnAccessService.php',
	'Services/ReturnSubmissionGuard.php',
	'Admin/ReturnsPage.php',
	'Rest/ReturnsController.php',
	'Rest/PublicReturnsController.php',
	'Rest/ReturnsAdminQueueController.php',
];

// A deployment may update the module tree over several filesystem writes.
// Load all dependencies or none so an incomplete tree cannot crash WordPress
// or register a partially functional returns domain.
foreach ( $_dtb_returns_files as $_dtb_returns_file ) {
	if ( ! is_file( $_dtb_returns_dir . '/' . $_dtb_returns_file ) ) {
		dtb_module_require( 'dtb-returns/' . $_dtb_returns_file );
		unset( $_dtb_returns_dir, $_dtb_returns_files, $_dtb_returns_file );
		return;
	}
}

foreach ( $_dtb_returns_files as $_dtb_returns_file ) {
	require_once $_dtb_returns_dir . '/' . $_dtb_returns_file;
}

unset( $_dtb_returns_dir, $_dtb_returns_files, $_dtb_returns_file );

add_action( 'rest_api_init', 'dtb_returns_rest_register_routes' );
add_action( 'rest_api_init', 'dtb_returns_public_rest_register_routes' );
add_action( 'init',          'dtb_returns_register_post_type' );
