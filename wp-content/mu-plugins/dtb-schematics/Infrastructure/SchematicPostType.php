<?php
/**
 * DTB Schematics — SchematicPostType: `dtb_schematic` CPT registration.
 *
 * The `dtb_schematic` CPT is the sole authoritative existence signal for a
 * schematic. It is intentionally private (not public, not shown in the
 * default WP admin UI, not indexed/rewritten) — all operator interaction
 * happens through the Schematics and Hotspots control center and all
 * customer interaction happens through the public REST API (later phase),
 * never through this post type directly.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_SCHEMATIC_POST_TYPE = 'dtb_schematic';

add_action( 'init', 'dtb_schematics_register_record_post_type' );

function dtb_schematics_register_record_post_type(): void {
	register_post_type(
		DTB_SCHEMATIC_POST_TYPE,
		[
			'labels'              => [
				'name'          => __( 'Schematics', 'drywall-toolbox' ),
				'singular_name' => __( 'Schematic', 'drywall-toolbox' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => false,
			'show_in_menu'        => false,
			'show_in_nav_menus'   => false,
			'show_in_rest'        => false,
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'capabilities'        => [
				'edit_post'              => 'dtb_manage_schematics',
				'read_post'              => 'dtb_manage_schematics',
				'delete_post'            => 'dtb_manage_schematics',
				'edit_posts'             => 'dtb_manage_schematics',
				'edit_others_posts'      => 'dtb_manage_schematics',
				'publish_posts'          => 'dtb_manage_schematics',
				'read_private_posts'     => 'dtb_manage_schematics',
				'delete_posts'           => 'dtb_manage_schematics',
				'delete_private_posts'   => 'dtb_manage_schematics',
				'delete_published_posts' => 'dtb_manage_schematics',
				'delete_others_posts'    => 'dtb_manage_schematics',
				'edit_private_posts'     => 'dtb_manage_schematics',
				'edit_published_posts'   => 'dtb_manage_schematics',
				'create_posts'           => 'dtb_manage_schematics',
			],
			'map_meta_cap'        => false,
			'hierarchical'        => false,
			'supports'            => [ 'title', 'custom-fields' ],
			'has_archive'         => false,
			'exclude_from_search' => true,
			'can_export'          => false,
		]
	);
}
