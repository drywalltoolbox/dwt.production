<?php
/**
 * DTB Platform — ConfigReferencePage
 *
 * Renders dtb-config-reference — read-only DTB option, constant, and
 * capability reference for operators and developers.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_config_reference_render_page(): void {
	if ( ! current_user_can( 'dtb_view_config_reference' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$active_tab = sanitize_key( $_GET['tab'] ?? 'options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$base_url   = admin_url( 'admin.php?page=dtb-config-reference' );

	$tabs = [
		[ 'id' => 'options',  'label' => __( 'Options',      'drywall-toolbox' ), 'active' => $active_tab === 'options',  'url' => add_query_arg( 'tab', 'options',  $base_url ) ],
		[ 'id' => 'constants','label' => __( 'Constants',    'drywall-toolbox' ), 'active' => $active_tab === 'constants','url' => add_query_arg( 'tab', 'constants',$base_url ) ],
		[ 'id' => 'caps',     'label' => __( 'Capabilities', 'drywall-toolbox' ), 'active' => $active_tab === 'caps',     'url' => add_query_arg( 'tab', 'caps',     $base_url ) ],
	];

	dtb_admin_shell_open( [
		'title'    => __( 'Config Reference', 'drywall-toolbox' ),
		'subtitle' => __( 'Read-only reference of all DTB options, constants, and capabilities.', 'drywall-toolbox' ),
		'section'  => 'tools',
		'page'     => 'dtb-config-reference',
		'template' => 'tool',
		'icon'     => 'dashicons-book',
		'tabs'     => $tabs,
	] );

	switch ( $active_tab ) {
		case 'constants':
			dtb_config_reference_render_constants_tab();
			break;
		case 'caps':
			dtb_config_reference_render_caps_tab();
			break;
		default:
			dtb_config_reference_render_options_tab();
			break;
	}

	dtb_admin_shell_close();
}

function dtb_config_reference_render_options_tab(): void {
	$option_keys = [
		'dtb_general_store_name',
		'dtb_general_default_timezone',
		'dtb_orders_auto_complete_paid',
		'dtb_orders_low_stock_threshold',
		'dtb_repairs_default_sla_days',
		'dtb_repairs_notify_customer_on_status',
		'dtb_returns_rma_prefix',
		'dtb_returns_window_days',
		'dtb_support_default_sla_hours',
		'dtb_support_auto_close_days',
		'dtb_notifications_admin_email',
		'dtb_notifications_from_name',
		'dtb_image_sync_cdn_base_url',
		'dtb_image_sync_auto_on_publish',
		'dtb_admin_ui_theme',
		'dtb_admin_ui_items_per_page',
	];

	ob_start();
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'Option Key', 'drywall-toolbox' ),   'key' => 'key' ],
		[ 'label' => __( 'Current Value', 'drywall-toolbox' ),'key' => 'val' ],
	], [] );

	foreach ( $option_keys as $key ) {
		$val = get_option( $key, '' );
		echo '<tr>';
		echo '<td><code>' . esc_html( $key ) . '</code></td>';
		echo '<td>' . esc_html( is_array( $val ) ? wp_json_encode( $val ) : (string) $val ) . '</td>';
		echo '</tr>';
	}

	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'DTB Options', 'drywall-toolbox' ) ] );
}

function dtb_config_reference_render_constants_tab(): void {
	$constants = [
		'DTB_VERSION'            => defined( 'DTB_VERSION' )            ? DTB_VERSION            : null,
		'DTB_PLUGIN_DIR'         => defined( 'DTB_PLUGIN_DIR' )         ? DTB_PLUGIN_DIR         : null,
		'DTB_PLUGIN_URL'         => defined( 'DTB_PLUGIN_URL' )         ? DTB_PLUGIN_URL         : null,
		'DTB_ASSET_VERSION'      => defined( 'DTB_ASSET_VERSION' )      ? DTB_ASSET_VERSION      : null,
		'DTB_DEBUG'              => defined( 'DTB_DEBUG' )              ? ( DTB_DEBUG ? 'true' : 'false' ) : null,
		'DTB_IMAGE_CDN'          => defined( 'DTB_IMAGE_CDN' )          ? DTB_IMAGE_CDN          : null,
		'ABSPATH'                => defined( 'ABSPATH' )                ? ABSPATH                : null,
		'WP_DEBUG'               => defined( 'WP_DEBUG' )               ? ( WP_DEBUG ? 'true' : 'false' ) : null,
		'WPMU_PLUGIN_DIR'        => defined( 'WPMU_PLUGIN_DIR' )        ? WPMU_PLUGIN_DIR        : null,
	];

	ob_start();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_table_open( [
		[ 'label' => __( 'Constant', 'drywall-toolbox' ), 'key' => 'const' ],
		[ 'label' => __( 'Value',    'drywall-toolbox' ), 'key' => 'val' ],
		[ 'label' => __( 'Defined',  'drywall-toolbox' ), 'key' => 'def' ],
	], [] );

	foreach ( $constants as $name => $value ) {
		echo '<tr>';
		echo '<td><code>' . esc_html( $name ) . '</code></td>';
		echo '<td>' . ( $value !== null ? '<code>' . esc_html( $value ) . '</code>' : '<em class="dtb-text-muted">—</em>' ) . '</td>';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<td>' . dtb_admin_ui_badge( $value !== null ? 'Yes' : 'No', $value !== null ? 'success' : 'neutral' ) . '</td>';
		echo '</tr>';
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_table_close();
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'PHP Constants', 'drywall-toolbox' ) ] );
}

function dtb_config_reference_render_caps_tab(): void {
	if ( ! function_exists( 'dtb_admin_role_capability_map' ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_alert( __( 'AdminCapabilities not loaded.', 'drywall-toolbox' ), 'warning' );
		return;
	}

	$map = dtb_admin_role_capability_map();

	foreach ( $map as $role => $caps ) {
		ob_start();
		echo '<ul class="dtb-list">';
		foreach ( $caps as $cap ) {
			echo '<li><code>' . esc_html( $cap ) . '</code></li>';
		}
		echo '</ul>';
		$body = ob_get_clean();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_card( $body, [ 'title' => esc_html( ucwords( str_replace( '_', ' ', $role ) ) ) ] );
	}
}
