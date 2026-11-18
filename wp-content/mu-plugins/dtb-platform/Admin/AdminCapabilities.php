<?php
/**
 * DTB Admin — AdminCapabilities
 *
 * Registers all DTB custom capabilities and assigns them to roles.
 * Called during 'init' with low priority so WooCommerce roles are available.
 *
 * This is the single canonical source for every DTB capability grant. Before
 * this consolidation, three other files (`dtb-repair-service/Admin/RepairAdminMenu.php`,
 * `dtb-platform/Observability/OrderOperationsKpiService.php`,
 * `dtb-support/Infrastructure/SupportSchemaInstaller.php`) each independently
 * re-implemented the same "check role, add_cap() if missing, on every `init`"
 * pattern for their own capability subset — three more per-request DB checks
 * doing the same conceptual repair `dtb_admin_assign_capabilities()` below
 * already does for every role, plus two capabilities (`dtb_manage_order_operations`,
 * `dtb_manage_support_automation`) that only existed in those scattered
 * functions and were never part of this canonical registry at all. Add any
 * new DTB capability here — to `dtb_admin_all_capabilities()` and, if it
 * belongs on a non-administrator role, to that role's array in
 * `dtb_admin_role_capability_map()` — instead of adding another one-off grant
 * function elsewhere.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * All DTB admin capability slugs.
 *
 * @return string[]
 */
function dtb_admin_all_capabilities(): array {
	return [
		// Operations
		'dtb_view_command_center',
		'dtb_manage_orders',
		'dtb_manage_repairs',
		'dtb_manage_returns',
		'dtb_read_support_tickets',
		'dtb_reply_support_tickets',
		'dtb_add_support_notes',
		'dtb_change_support_status',
		'dtb_change_support_priority',
		'dtb_manage_support',
		'dtb_manage_support_macros',
		'dtb_view_support_reports',
		'dtb_manage_support_settings',
		'dtb_manage_system',
		'dtb_manage_settings',
		'dtb_manage_deployments',
		'dtb_manage_order_operations',
		'dtb_manage_support_automation',
		'dtb_manage_visual_designer',

		// Marketplace
		'dtb_view_marketplace',
		'dtb_manage_marketplace',
		'dtb_manage_marketplace_settings',

		// Tool Library
		'dtb_view_catalog_operations',
		'dtb_manage_schematics',
		'dtb_manage_image_sync',
		'dtb_manage_product_mapping',
		'dtb_manage_catalog_health',
		'dtb_manage_catalog_pricing',
		'dtb_manage_cache_tools',
		'dtb_view_api_health',
		'dtb_manage_seo_tools',
		'dtb_manage_import_export',
		'dtb_view_config_reference',
		'dtb_manage_parts',
		'dtb_manage_inventory_intelligence',
	];
}

/**
 * Role capability map.
 * Keys are WP role slugs; values are arrays of DTB capability slugs.
 *
 * @return array<string, string[]>
 */
function dtb_admin_role_capability_map(): array {
	$all = dtb_admin_all_capabilities();

	return [
		'administrator' => $all,

		'dtb_operations_manager' => [
			'dtb_view_command_center',
			'dtb_manage_orders',
			'dtb_manage_repairs',
			'dtb_manage_returns',
			'dtb_read_support_tickets',
			'dtb_manage_support',
			'dtb_manage_settings',
			'dtb_view_marketplace',
			'dtb_manage_marketplace',
		],

		'dtb_support_agent' => [
			'dtb_read_support_tickets',
			'dtb_reply_support_tickets',
			'dtb_add_support_notes',
			'dtb_change_support_status',
			'dtb_change_support_priority',
		],

		'dtb_repair_manager' => [
			'dtb_manage_repairs',
			'dtb_read_support_tickets',
			'dtb_manage_orders',
		],

		'dtb_returns_manager' => [
			'dtb_manage_returns',
			'dtb_read_support_tickets',
			'dtb_manage_orders',
		],

		'dtb_catalog_manager' => [
			'dtb_view_catalog_operations',
			'dtb_manage_schematics',
			'dtb_manage_image_sync',
			'dtb_manage_product_mapping',
			'dtb_manage_catalog_health',
			'dtb_manage_catalog_pricing',
			'dtb_manage_seo_tools',
			'dtb_manage_import_export',
			'dtb_manage_parts',
			'dtb_manage_inventory_intelligence',
		],

		'dtb_technical_admin' => [
			'dtb_manage_system',
			'dtb_manage_cache_tools',
			'dtb_view_api_health',
			'dtb_view_config_reference',
			'dtb_manage_deployments',
			'dtb_manage_visual_designer',
		],
	];
}

/**
 * Assign all DTB capabilities to their respective roles.
 * Also grants administrator all capabilities unconditionally.
 */
function dtb_admin_assign_capabilities(): void {
	$map = dtb_admin_role_capability_map();

	foreach ( $map as $role_slug => $caps ) {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap ) {
			if ( ! $role->has_cap( $cap ) ) {
				$role->add_cap( $cap, true );
			}
		}
	}
}

add_action( 'init', 'dtb_admin_assign_capabilities', 5 );

/**
 * Allow WordPress administrators to pass DTB custom capability checks even if
 * role capability rows are stale after a deployment or database restore.
 *
 * @param array<string,bool> $allcaps User's resolved capabilities.
 * @param string[]           $caps    Primitive capabilities being checked.
 * @return array<string,bool>
 */
function dtb_admin_grant_custom_caps_to_site_admins( array $allcaps, array $caps ): array {
	if ( empty( $allcaps['manage_options'] ) ) {
		return $allcaps;
	}

	$dtb_caps = array_flip( dtb_admin_all_capabilities() );
	foreach ( $caps as $cap ) {
		if ( isset( $dtb_caps[ $cap ] ) ) {
			$allcaps[ $cap ] = true;
		}
	}

	return $allcaps;
}

add_filter( 'user_has_cap', 'dtb_admin_grant_custom_caps_to_site_admins', 10, 2 );

/**
 * Create custom DTB roles if they do not exist.
 * Called once on plugin activation or from admin init.
 */
function dtb_admin_register_custom_roles(): void {
	$custom_roles = [
		'dtb_operations_manager' => 'DTB Operations Manager',
		'dtb_support_agent'      => 'DTB Support Agent',
		'dtb_repair_manager'     => 'DTB Repair Manager',
		'dtb_returns_manager'    => 'DTB Returns Manager',
		'dtb_catalog_manager'    => 'DTB Catalog Manager',
		'dtb_technical_admin'    => 'DTB Technical Admin',
	];

	foreach ( $custom_roles as $slug => $name ) {
		if ( ! get_role( $slug ) ) {
			add_role( $slug, $name, [] );
		}
	}
}

add_action( 'init', 'dtb_admin_register_custom_roles', 1 );

/**
 * Check if the current user has a given DTB capability.
 * Thin wrapper around current_user_can() for consistency.
 *
 * @param string $cap
 * @return bool
 */
function dtb_current_user_can( string $cap ): bool {
	return current_user_can( $cap );
}
