<?php
/**
 * Infrastructure — RepairPostType: CPT constants and registration.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'DTB_REPAIR_ALLOWED_BRANDS' ) ) {
define( 'DTB_REPAIR_ALLOWED_BRANDS', [ 'TapeTech', 'Columbia Tools', 'Asgard', 'Other' ] );
}

if ( ! defined( 'DTB_REPAIR_SERVICE_TIERS' ) ) {
define( 'DTB_REPAIR_SERVICE_TIERS', [ 'standard', 'express', 'warranty' ] );
}

if ( ! defined( 'DTB_REPAIR_RATE_LIMIT_WINDOW' ) ) {
define( 'DTB_REPAIR_RATE_LIMIT_WINDOW', 3600 );
}

if ( ! defined( 'DTB_REPAIR_RATE_LIMIT_MAX' ) ) {
define( 'DTB_REPAIR_RATE_LIMIT_MAX', 5 );
}

if ( ! defined( 'DTB_REPAIR_ALLOWED_MIME_TYPES' ) ) {
define( 'DTB_REPAIR_ALLOWED_MIME_TYPES', [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ] );
}

if ( ! defined( 'DTB_REPAIR_MAX_MEDIA_FILES' ) ) {
define( 'DTB_REPAIR_MAX_MEDIA_FILES', 5 );
}

if ( ! defined( 'DTB_REPAIR_MAX_MEDIA_SIZE' ) ) {
define( 'DTB_REPAIR_MAX_MEDIA_SIZE', 5 * 1024 * 1024 );
}

add_action( 'init', 'dtb_repair_register_cpt' );

/**
 * Register the dtb_repair_request custom post type.
 */
function dtb_repair_register_cpt(): void {
$labels = [
'name'               => __( 'Repair Requests', 'drywall-toolbox' ),
'singular_name'      => __( 'Repair Request', 'drywall-toolbox' ),
'menu_name'          => __( 'Repairs', 'drywall-toolbox' ),
'add_new'            => __( 'Add New', 'drywall-toolbox' ),
'add_new_item'       => __( 'Add New Repair Request', 'drywall-toolbox' ),
'edit_item'          => __( 'Edit Repair Request', 'drywall-toolbox' ),
'new_item'           => __( 'New Repair Request', 'drywall-toolbox' ),
'view_item'          => __( 'View Repair Request', 'drywall-toolbox' ),
'search_items'       => __( 'Search Repair Requests', 'drywall-toolbox' ),
'not_found'          => __( 'No repair requests found.', 'drywall-toolbox' ),
'not_found_in_trash' => __( 'No repair requests found in trash.', 'drywall-toolbox' ),
];

register_post_type(
'dtb_repair_request',
[
'labels'              => $labels,
'public'              => false,
'publicly_queryable'  => false,
'show_ui'             => true,
'show_in_menu'        => false,
'show_in_nav_menus'   => false,
'show_in_rest'        => false,
'query_var'           => false,
'rewrite'             => false,
'capability_type'     => 'post',
'capabilities'        => [
'edit_post'              => 'dtb_manage_repairs',
'read_post'              => 'dtb_manage_repairs',
'delete_post'            => 'dtb_manage_repairs',
'edit_posts'             => 'dtb_manage_repairs',
'edit_others_posts'      => 'dtb_manage_repairs',
'publish_posts'          => 'dtb_manage_repairs',
'read_private_posts'     => 'dtb_manage_repairs',
'delete_posts'           => 'dtb_manage_repairs',
'delete_private_posts'   => 'dtb_manage_repairs',
'delete_published_posts' => 'dtb_manage_repairs',
'delete_others_posts'    => 'dtb_manage_repairs',
'edit_private_posts'     => 'dtb_manage_repairs',
'edit_published_posts'   => 'dtb_manage_repairs',
'create_posts'           => 'dtb_manage_repairs',
],
'map_meta_cap'        => false,
'hierarchical'        => false,
'supports'            => [ 'title', 'editor', 'custom-fields', 'thumbnail' ],
'has_archive'         => false,
'exclude_from_search' => true,
'can_export'          => false,
]
);
}
