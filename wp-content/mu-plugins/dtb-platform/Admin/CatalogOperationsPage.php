<?php
/**
 * DTB Catalog Operations workspace.
 *
 * This is the operator-facing composition surface for catalog administration.
 * Domain behavior remains in its owning module; this page only presents
 * capability-filtered entry points into those bounded tools.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_notices', 'dtb_catalog_operations_render_context_navigation', 1 );
add_action( 'admin_enqueue_scripts', 'dtb_catalog_operations_enqueue_context_styles', 30 );

/** Load the shared workspace navigation styles on consolidated catalog pages. */
function dtb_catalog_operations_enqueue_context_styles(): void {
	$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pages   = [ 'dtb-catalog-operations', 'dtb-catalog-health', 'dtb-import-export', 'dtb-product-mapping', 'dtb-parts-manager', 'dtb-inventory-intelligence', 'dtb-image-sync', 'dtb-pricing-manager', 'dtb-seo-tools' ];
	if ( ! in_array( $current, $pages, true ) ) {
		return;
	}

	$file = __DIR__ . '/assets/dtb-catalog-operations.css';
	wp_enqueue_style( 'dtb-catalog-operations', plugin_dir_url( __FILE__ ) . 'assets/dtb-catalog-operations.css', [ 'dtb-admin' ], is_file( $file ) ? (string) filemtime( $file ) : '1.0.0' );
}

/**
 * Return the catalog operation cards the current operator may use.
 *
 * @return array<int, array<string, string>>
 */
function dtb_catalog_operations_cards(): array {
	$workspace_slug = current_user_can( 'dtb_manage_product_mapping' ) ? 'dtb-product-mapping' : 'dtb-parts-manager';
	$workspace_capability = current_user_can( 'dtb_manage_product_mapping' ) ? 'dtb_manage_product_mapping' : 'dtb_manage_parts';
	$cards = [
		[ 'capability' => 'dtb_manage_catalog_health', 'title' => __( 'Overview & Health', 'drywall-toolbox' ), 'description' => __( 'Audit variable products, required metadata, catalog integrity, and remediation queues.', 'drywall-toolbox' ), 'slug' => 'dtb-catalog-health', 'icon' => 'dashicons-heart' ],
		[ 'capability' => 'dtb_manage_import_export', 'title' => __( 'Import / Export', 'drywall-toolbox' ), 'description' => __( 'Validate, import, and export the supported DTB catalog CSV contract.', 'drywall-toolbox' ), 'slug' => 'dtb-import-export', 'icon' => 'dashicons-database-import' ],
		[ 'capability' => $workspace_capability, 'title' => __( 'Product & Parts Relationships', 'drywall-toolbox' ), 'description' => __( 'Review product relationships and open the authorized parts, schematic, and universal-part workflows.', 'drywall-toolbox' ), 'slug' => $workspace_slug, 'icon' => 'dashicons-networking' ],
		[ 'capability' => 'dtb_manage_inventory_intelligence', 'title' => __( 'Inventory Projections', 'drywall-toolbox' ), 'description' => __( 'Inspect WooCommerce stock projections and universal-part rollups; Veeqo remains inventory authority.', 'drywall-toolbox' ), 'slug' => 'dtb-inventory-intelligence', 'icon' => 'dashicons-chart-area' ],
		[ 'capability' => 'dtb_manage_image_sync', 'title' => __( 'Media Sync', 'drywall-toolbox' ), 'description' => __( 'Register and link catalog media from the active WordPress uploads directory.', 'drywall-toolbox' ), 'slug' => 'dtb-image-sync', 'icon' => 'dashicons-images-alt2' ],
		[ 'capability' => 'dtb_manage_catalog_pricing', 'title' => __( 'Pricing', 'drywall-toolbox' ), 'description' => __( 'Review pricing data and apply authorized WooCommerce price updates.', 'drywall-toolbox' ), 'slug' => 'dtb-pricing-manager', 'icon' => 'dashicons-money-alt' ],
		[ 'capability' => 'dtb_manage_seo_tools', 'title' => __( 'SEO Health', 'drywall-toolbox' ), 'description' => __( 'Audit product search metadata and the canonical WordPress sitemap endpoint.', 'drywall-toolbox' ), 'slug' => 'dtb-seo-tools', 'icon' => 'dashicons-search' ],
	];

	return array_values(
		array_filter(
			$cards,
			static fn( array $card ): bool => current_user_can( $card['capability'] )
		)
	);
}

/**
 * Render a consistent Catalog Operations navigation bar on the hidden domain
 * pages. The stable page slugs deliberately remain the routing contract.
 */
function dtb_catalog_operations_render_context_navigation(): void {
	$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$can_manage_relationships = current_user_can( 'dtb_manage_product_mapping' );
	$can_manage_parts = current_user_can( 'dtb_manage_parts' );
	$workspace_slug = $can_manage_relationships ? 'dtb-product-mapping' : 'dtb-parts-manager';
	$workspace_capability = $can_manage_relationships ? 'dtb_manage_product_mapping' : 'dtb_manage_parts';
	$pages   = [
		'dtb-catalog-operations'   => [ 'dtb_view_catalog_operations', __( 'Overview', 'drywall-toolbox' ) ],
		'dtb-catalog-health'       => [ 'dtb_manage_catalog_health', __( 'Health', 'drywall-toolbox' ) ],
		'dtb-import-export'        => [ 'dtb_manage_import_export', __( 'Data Transfer', 'drywall-toolbox' ) ],
		'dtb-inventory-intelligence' => [ 'dtb_manage_inventory_intelligence', __( 'Projections', 'drywall-toolbox' ) ],
		'dtb-image-sync'           => [ 'dtb_manage_image_sync', __( 'Media', 'drywall-toolbox' ) ],
		'dtb-pricing-manager'      => [ 'dtb_manage_catalog_pricing', __( 'Pricing', 'drywall-toolbox' ) ],
		'dtb-seo-tools'            => [ 'dtb_manage_seo_tools', __( 'SEO', 'drywall-toolbox' ) ],
	];
	if ( $can_manage_relationships || $can_manage_parts ) {
		$pages[ $workspace_slug ] = [ $workspace_capability, __( 'Relationships & Parts', 'drywall-toolbox' ) ];
	}

	$is_workspace_page = in_array( $current, [ 'dtb-product-mapping', 'dtb-parts-manager' ], true );
	$navigation_current = $is_workspace_page ? $workspace_slug : $current;
	if ( ! isset( $pages[ $navigation_current ] ) ) {
		return;
	}

	$groups = [
		[ 'label' => __( 'Products', 'drywall-toolbox' ), 'primary' => 'dtb-catalog-operations', 'items' => [ 'dtb-catalog-operations', 'dtb-catalog-health', $workspace_slug ] ],
		[ 'label' => __( 'Pricing', 'drywall-toolbox' ), 'primary' => 'dtb-pricing-manager', 'items' => [ 'dtb-pricing-manager' ] ],
		[ 'label' => __( 'Media & Content', 'drywall-toolbox' ), 'primary' => 'dtb-image-sync', 'items' => [ 'dtb-image-sync', 'dtb-seo-tools' ] ],
		[ 'label' => __( 'Data & Maintenance', 'drywall-toolbox' ), 'primary' => 'dtb-import-export', 'items' => [ 'dtb-import-export', 'dtb-inventory-intelligence' ] ],
	];

	echo '<nav class="dtb-catalog-operations-nav" aria-label="' . esc_attr__( 'Catalog Operations', 'drywall-toolbox' ) . '">';
	echo '<span class="dtb-catalog-operations-nav__heading">' . esc_html__( 'Catalog', 'drywall-toolbox' ) . '</span>';
	foreach ( $groups as $group ) {
		$allowed = array_values( array_filter( $group['items'], static fn( string $slug ): bool => isset( $pages[ $slug ] ) && current_user_can( $pages[ $slug ][0] ) ) );
		if ( ! $allowed ) {
			continue;
		}
		$target = in_array( $group['primary'], $allowed, true ) ? $group['primary'] : $allowed[0];
		$active = in_array( $navigation_current, $group['items'], true );
		echo '<a class="dtb-catalog-operations-nav__group' . ( $active ? ' is-active' : '' ) . '"' . ( $active ? ' aria-current="page"' : '' ) . ' href="' . esc_url( admin_url( 'admin.php?page=' . $target ) ) . '">' . esc_html( $group['label'] ) . '</a>';
	}
	$active_group = null;
	foreach ( $groups as $group ) {
		if ( in_array( $navigation_current, $group['items'], true ) ) {
			$active_group = $group;
			break;
		}
	}
	if ( $active_group && count( $active_group['items'] ) > 1 ) {
		echo '<div class="dtb-catalog-operations-nav__details" aria-label="' . esc_attr__( 'Related utilities', 'drywall-toolbox' ) . '">';
		foreach ( $active_group['items'] as $slug ) {
			if ( ! isset( $pages[ $slug ] ) || ! current_user_can( $pages[ $slug ][0] ) ) {
				continue;
			}
			echo '<a' . ( $navigation_current === $slug ? ' aria-current="page" class="is-active"' : '' ) . ' href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $pages[ $slug ][1] ) . '</a>';
		}
		echo '</div>';
	}
	echo '</nav>';
}

/** Render the unified catalog operations landing page. */
function dtb_catalog_operations_render_page(): void {
	$cards = dtb_catalog_operations_cards();
	if ( ! $cards ) {
		dtb_admin_shell_access_denied();
		return;
	}

	dtb_admin_shell_open( [
		'title'    => __( 'Catalog Operations', 'drywall-toolbox' ),
		'subtitle' => __( 'Find products, review pricing, manage media, and maintain catalog data from one workspace.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-catalog-operations',
		'template' => 'dashboard',
		'icon'     => 'dashicons-products',
	] );

	echo '<div class="dtb-card-grid">';
	foreach ( $cards as $card ) {
		$url = admin_url( 'admin.php?page=' . $card['slug'] );
		echo '<section class="dtb-card"><div class="dtb-card__body">';
		echo '<span class="dashicons ' . esc_attr( $card['icon'] ) . '" aria-hidden="true"></span>';
		echo '<h2 class="dtb-card__title">' . esc_html( $card['title'] ) . '</h2>';
		echo '<p>' . esc_html( $card['description'] ) . '</p>';
		echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Open tool', 'drywall-toolbox' ) . '</a></p>';
		echo '</div></section>';
	}
	echo '</div>';

	if ( current_user_can( 'dtb_manage_inventory_intelligence' ) ) {
		$veeqo_url = admin_url( 'admin.php?page=dtb-veeqo' );
		echo '<section class="dtb-card"><div class="dtb-card__body"><h2 class="dtb-card__title">' . esc_html__( 'Inventory authority', 'drywall-toolbox' ) . '</h2>';
		echo '<p>' . esc_html__( 'Veeqo owns inventory, allocation, fulfillment, shipping, and tracking. Catalog Operations manages only WooCommerce catalog data and explicit projections.', 'drywall-toolbox' ) . '</p>';
		echo '<p><a class="button" href="' . esc_url( $veeqo_url ) . '">' . esc_html__( 'Open Veeqo', 'drywall-toolbox' ) . '</a></p></div></section>';
	}

	dtb_admin_shell_close();
}
