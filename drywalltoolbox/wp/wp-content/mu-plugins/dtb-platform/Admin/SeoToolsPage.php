<?php
/**
 * DTB Platform — SeoToolsPage
 *
 * Renders dtb-seo-tools — SEO meta audit and sitemap tooling.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_seo_tools_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_seo_tools' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$active_tab = sanitize_key( $_GET['tab'] ?? 'audit' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$base_url   = admin_url( 'admin.php?page=dtb-seo-tools' );

	$tabs = [
		[ 'id' => 'audit',   'label' => __( 'Meta Audit', 'drywall-toolbox' ),  'active' => $active_tab === 'audit',   'url' => add_query_arg( 'tab', 'audit',   $base_url ) ],
		[ 'id' => 'sitemap', 'label' => __( 'Sitemap',    'drywall-toolbox' ),  'active' => $active_tab === 'sitemap', 'url' => add_query_arg( 'tab', 'sitemap', $base_url ) ],
	];

	dtb_admin_shell_open( [
		'title'    => __( 'SEO Tools', 'drywall-toolbox' ),
		'subtitle' => __( 'Audit product SEO meta, titles, descriptions, and sitemap status.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-seo-tools',
		'template' => 'tool',
		'icon'     => 'dashicons-search',
		'tabs'     => $tabs,
	] );

	if ( $active_tab === 'sitemap' ) {
		dtb_seo_tools_render_sitemap_tab();
	} else {
		dtb_seo_tools_render_audit_tab();
	}

	dtb_admin_shell_close();
}

function dtb_seo_tools_render_audit_tab(): void {
	// DTB ProductSeoController emits these fields on product pages, so the audit
	// must query the same source of truth instead of requiring plugin-specific
	// Yoast or Rank Math metadata that the storefront does not depend on.
	$args = [
		'post_type'   => 'product',
		'post_status' => 'publish',
		'numberposts' => 50,
		'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'relation' => 'OR',
			[
				'relation' => 'OR',
				[ 'key' => '_dtb_seo_title', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_dtb_seo_title', 'value' => '', 'compare' => '=' ],
			],
			[
				'relation' => 'OR',
				[ 'key' => '_dtb_seo_description', 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_dtb_seo_description', 'value' => '', 'compare' => '=' ],
			],
		],
	];
	$products = get_posts( $args );

	if ( empty( $products ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_empty_state(
			__( 'All products have SEO meta.', 'drywall-toolbox' ),
			__( 'No products are missing title or description meta.', 'drywall-toolbox' )
		);
		return;
	}

	ob_start();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'ID',      'drywall-toolbox' ), 'key' => 'id' ],
		[ 'label' => __( 'Product', 'drywall-toolbox' ), 'key' => 'title' ],
		[ 'label' => __( 'Missing', 'drywall-toolbox' ), 'key' => 'missing' ],
		[ 'label' => __( 'Edit',    'drywall-toolbox' ), 'key' => 'edit' ],
	], [] );

	foreach ( $products as $product ) {
		$missing = [];
		if ( '' === trim( (string) get_post_meta( $product->ID, '_dtb_seo_title', true ) ) ) {
			$missing[] = 'Title';
		}
		if ( '' === trim( (string) get_post_meta( $product->ID, '_dtb_seo_description', true ) ) ) {
			$missing[] = 'Description';
		}

		echo '<tr>';
		echo '<td>' . (int) $product->ID . '</td>';
		echo '<td>' . esc_html( $product->post_title ) . '</td>';
		echo '<td>' . esc_html( implode( ', ', $missing ) ) . '</td>';
		echo '<td><a href="' . esc_url( get_edit_post_link( $product->ID ) ) . '" target="_blank" class="dtb-btn dtb-btn--sm">' . esc_html__( 'Edit', 'drywall-toolbox' ) . '</a></td>';
		echo '</tr>';
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [
		'title' => sprintf(
			/* translators: %d = number of products */
			__( 'Products Missing SEO Meta (%d)', 'drywall-toolbox' ),
			count( $products )
		),
	] );
}

function dtb_seo_tools_render_sitemap_tab(): void {
	$sitemap_url = home_url( '/sitemap.xml' );
	$response    = wp_remote_head( $sitemap_url, [ 'timeout' => 5, 'sslverify' => true ] );
	$code        = is_wp_error( $response ) ? 0 : wp_remote_retrieve_response_code( $response );
	$ok          = $code === 200;

	ob_start();
	echo '<dl class="dtb-dl">';
	echo '<dt>' . esc_html__( 'Sitemap URL', 'drywall-toolbox' ) . '</dt>';
	echo '<dd><a href="' . esc_url( $sitemap_url ) . '" target="_blank">' . esc_html( $sitemap_url ) . '</a></dd>';
	echo '<dt>' . esc_html__( 'Status', 'drywall-toolbox' ) . '</dt>';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '<dd>' . dtb_admin_ui_badge( $ok ? '200 OK' : ( $code ? $code . ' Error' : 'Unreachable' ), $ok ? 'success' : 'danger' ) . '</dd>';
	echo '</dl>';
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Sitemap Status', 'drywall-toolbox' ) ] );
}
