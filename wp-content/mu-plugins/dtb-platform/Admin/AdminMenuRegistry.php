<?php
/**
 * DTB Admin — AdminMenuRegistry
 *
 * Owns all add_menu_page() and add_submenu_page() calls for DTB admin pages.
 * Modules register metadata via dtb_register_admin_page(); this class
 * consumes that registry at admin_menu time and renders the two library menus.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'dtb_admin_menu_registry_init', 20 );

/**
 * Build both top-level menus from the page registry.
 */
function dtb_admin_menu_registry_init(): void {
	dtb_register_operations_library_menu();
	dtb_register_tool_library_menu();
}

/**
 * Register the "Drywall Toolbox" operations menu.
 */
function dtb_register_operations_library_menu(): void {
	$pages = dtb_get_sorted_pages( 'operations' );

	// Determine top-level landing page (lowest position first = Command Center).
	$first = reset( $pages );
	if ( ! $first ) {
		return;
	}

	// Top-level parent slug.
	$parent = $first['slug'];

	add_menu_page(
		__( 'Drywall Toolbox', 'drywall-toolbox' ),
		__( 'Drywall Toolbox', 'drywall-toolbox' ),
		$first['capability'],
		$parent,
		$first['callback'],
		'dashicons-store',
		25
	);

	foreach ( $pages as $page ) {
		if ( ! current_user_can( $page['capability'] ) ) {
			continue;
		}

		if ( $page['slug'] === $parent ) {
			// First entry doubles as the parent — add as submenu with display title.
			add_submenu_page(
				$parent,
				$page['title'],
				$page['menu_title'],
				$page['capability'],
				$page['slug'],
				$page['callback']
			);
			continue;
		}

		$submenu_parent = empty( $page['menu_visible'] ) ? null : $parent;

		add_submenu_page(
			$submenu_parent,
			$page['title'],
			$page['menu_title'],
			$page['capability'],
			$page['slug'],
			$page['callback']
		);
	}
}

/**
 * Register the "DTB Tool Library" menu.
 */
function dtb_register_tool_library_menu(): void {
	$pages = dtb_get_sorted_pages( 'tools' );

	$first = reset( $pages );
	if ( ! $first ) {
		return;
	}

	$parent = $first['slug'];

	add_menu_page(
		__( 'DTB Tool Library', 'drywall-toolbox' ),
		__( 'DTB Tool Library', 'drywall-toolbox' ),
		$first['capability'],
		$parent,
		$first['callback'],
		'dashicons-admin-tools',
		26
	);

	foreach ( $pages as $page ) {
		if ( ! current_user_can( $page['capability'] ) ) {
			continue;
		}

		$submenu_parent = empty( $page['menu_visible'] ) ? null : $parent;

		add_submenu_page(
			$submenu_parent,
			$page['title'],
			$page['menu_title'],
			$page['capability'],
			$page['slug'],
			$page['callback']
		);
	}
}

/**
 * Hide legacy DTB top-level menus that have been migrated into the new registry.
 * Add stale slugs here during migration.
 */
add_action( 'admin_menu', 'dtb_remove_legacy_dtb_menus', 999 );

function dtb_remove_legacy_dtb_menus(): void {
	$legacy_slugs = [
		'dtb-ops',          // old Ops/OpsDashboard top-level
		'dtb-support',      // old Support Hub top-level (now under Drywall Toolbox)
		'dtb-repairs',      // old Repairs top-level
		'dtb-toolbox',      // old DTB Tools top-level
		'dtb-catalog-tools',
	];

	foreach ( $legacy_slugs as $slug ) {
		remove_menu_page( $slug );
	}
}
