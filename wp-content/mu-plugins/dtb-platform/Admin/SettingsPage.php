<?php
/**
 * DTB Platform — SettingsPage
 *
 * Renders dtb-settings — platform configuration grouped by domain.
 * Tabs: General | Orders | Repairs | Returns | Support | Notifications | Roles | Integrations | UI
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_settings_render_page(): void {
	if ( ! current_user_can( 'dtb_manage_settings' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	// Handle save.
	if ( isset( $_POST['dtb_settings_nonce'] ) && wp_verify_nonce( sanitize_key( $_POST['dtb_settings_nonce'] ), 'dtb_settings_save' ) ) {
		dtb_settings_handle_save();
	}

	$active_tab = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$base_url   = admin_url( 'admin.php?page=dtb-settings' );

	$tab_defs = [
		'general'       => __( 'General', 'drywall-toolbox' ),
		'orders'        => __( 'Orders', 'drywall-toolbox' ),
		'repairs'       => __( 'Repairs', 'drywall-toolbox' ),
		'returns'       => __( 'Returns', 'drywall-toolbox' ),
		'support'       => __( 'Support', 'drywall-toolbox' ),
		'notifications' => __( 'Notifications', 'drywall-toolbox' ),
		'roles'         => __( 'Roles & Capabilities', 'drywall-toolbox' ),
		'integrations'  => __( 'Integrations', 'drywall-toolbox' ),
		'ui'            => __( 'Admin UI', 'drywall-toolbox' ),
	];

	$tabs = [];
	foreach ( $tab_defs as $id => $label ) {
		$tabs[] = [
			'id'     => $id,
			'label'  => $label,
			'active' => $active_tab === $id,
			'url'    => add_query_arg( 'tab', $id, $base_url ),
		];
	}

	dtb_admin_shell_open( [
		'title'    => __( 'Settings', 'drywall-toolbox' ),
		'subtitle' => __( 'Configure all Drywall Toolbox platform settings.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-settings',
		'template' => 'settings',
		'icon'     => 'dashicons-admin-settings',
		'tabs'     => $tabs,
	] );

	$nonce_field = wp_nonce_field( 'dtb_settings_save', 'dtb_settings_nonce', true, false );
	echo '<form method="post" action="">' . $nonce_field;
	echo '<input type="hidden" name="dtb_settings_tab" value="' . esc_attr( $active_tab ) . '">';

	switch ( $active_tab ) {
		case 'orders':
			dtb_settings_render_orders_tab();
			break;
		case 'repairs':
			dtb_settings_render_repairs_tab();
			break;
		case 'returns':
			dtb_settings_render_returns_tab();
			break;
		case 'support':
			dtb_settings_render_support_tab();
			break;
		case 'notifications':
			dtb_settings_render_notifications_tab();
			break;
		case 'roles':
			dtb_settings_render_roles_tab();
			break;
		case 'integrations':
			dtb_settings_render_integrations_tab();
			break;
		case 'ui':
			dtb_settings_render_ui_tab();
			break;
		default:
			dtb_settings_render_general_tab();
			break;
	}

	// Save button.
	dtb_admin_ui_toolbar_open();
	dtb_admin_ui_toolbar_spacer();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_button( __( 'Save Settings', 'drywall-toolbox' ), [
		'type'    => 'primary',
		'btn_type' => 'submit',
		'icon'    => 'dashicons-saved',
		'loading' => true,
	] );
	dtb_admin_ui_toolbar_close();

	echo '</form>';

	dtb_admin_shell_close();
}

// ── Save handler ──────────────────────────────────────────────────────────────

function dtb_settings_handle_save(): void {
	$tab = sanitize_key( $_POST['dtb_settings_tab'] ?? 'general' );

	switch ( $tab ) {
		case 'general':
			update_option( 'dtb_store_name',   sanitize_text_field( $_POST['dtb_store_name'] ?? '' ) );
			update_option( 'dtb_support_email', sanitize_email( $_POST['dtb_support_email'] ?? '' ) );
			break;
		case 'orders':
			update_option( 'dtb_orders_auto_complete_virtual', (bool) ( $_POST['dtb_orders_auto_complete_virtual'] ?? false ) );
			break;
		case 'repairs':
			update_option( 'dtb_repair_sla_days', (int) ( $_POST['dtb_repair_sla_days'] ?? 7 ) );
			update_option( 'dtb_repair_notify_email', sanitize_email( $_POST['dtb_repair_notify_email'] ?? '' ) );
			break;
		case 'returns':
			update_option( 'dtb_returns_window_days', (int) ( $_POST['dtb_returns_window_days'] ?? 30 ) );
			break;
		case 'support':
			update_option( 'dtb_support_sla_hours', (int) ( $_POST['dtb_support_sla_hours'] ?? 24 ) );
			break;
		case 'notifications':
			update_option( 'dtb_notify_order_failed',    (bool) ( $_POST['dtb_notify_order_failed'] ?? true ) );
			update_option( 'dtb_notify_repair_complete', (bool) ( $_POST['dtb_notify_repair_complete'] ?? true ) );
			break;
		case 'ui':
			update_option( 'dtb_admin_items_per_page', max( 10, min( 200, (int) ( $_POST['dtb_admin_items_per_page'] ?? 25 ) ) ) );
			break;
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_alert( __( 'Settings saved.', 'drywall-toolbox' ), 'success', '', true );
}

// ── Tab renderers ─────────────────────────────────────────────────────────────

function dtb_settings_render_general_tab(): void {
	ob_start();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_input( 'dtb_store_name', get_option( 'dtb_store_name', '' ) ),
		__( 'Store Name', 'drywall-toolbox' ),
		[ 'description' => __( 'Display name used in admin UI headers.', 'drywall-toolbox' ) ]
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_input( 'dtb_support_email', get_option( 'dtb_support_email', 'info@drywalltoolbox.com' ), [ 'type' => 'email' ] ),
		__( 'Support Email', 'drywall-toolbox' ),
		[ 'description' => __( 'Primary contact email for platform notifications.', 'drywall-toolbox' ) ]
	);
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'General Settings', 'drywall-toolbox' ) ] );
}

function dtb_settings_render_orders_tab(): void {
	ob_start();
	$checked = get_option( 'dtb_orders_auto_complete_virtual', false ) ? 'checked' : '';
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		'<label><input type="checkbox" name="dtb_orders_auto_complete_virtual" value="1" ' . $checked . '> ' . esc_html__( 'Enabled', 'drywall-toolbox' ) . '</label>',
		__( 'Auto-complete virtual orders', 'drywall-toolbox' ),
		[ 'description' => __( 'Automatically mark virtual/downloadable orders as Completed on payment.', 'drywall-toolbox' ) ]
	);
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Order Settings', 'drywall-toolbox' ) ] );
}

function dtb_settings_render_repairs_tab(): void {
	ob_start();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_input( 'dtb_repair_sla_days', get_option( 'dtb_repair_sla_days', 7 ), [ 'type' => 'number', 'min' => '1' ] ),
		__( 'Repair SLA (days)', 'drywall-toolbox' ),
		[ 'description' => __( 'Number of business days before a repair is flagged as past SLA.', 'drywall-toolbox' ) ]
	);
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_input( 'dtb_repair_notify_email', get_option( 'dtb_repair_notify_email', '' ), [ 'type' => 'email' ] ),
		__( 'Repair Notification Email', 'drywall-toolbox' ),
		[]
	);
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Repair Settings', 'drywall-toolbox' ) ] );
}

function dtb_settings_render_returns_tab(): void {
	ob_start();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_input( 'dtb_returns_window_days', get_option( 'dtb_returns_window_days', 30 ), [ 'type' => 'number', 'min' => '1' ] ),
		__( 'Return Window (days)', 'drywall-toolbox' ),
		[ 'description' => __( 'How many days after delivery a return can be initiated.', 'drywall-toolbox' ) ]
	);
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Return Settings', 'drywall-toolbox' ) ] );
}

function dtb_settings_render_support_tab(): void {
	ob_start();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_input( 'dtb_support_sla_hours', get_option( 'dtb_support_sla_hours', 24 ), [ 'type' => 'number', 'min' => '1' ] ),
		__( 'Support SLA (hours)', 'drywall-toolbox' ),
		[ 'description' => __( 'Hours before an unanswered ticket is flagged as past SLA.', 'drywall-toolbox' ) ]
	);
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Support Settings', 'drywall-toolbox' ) ] );
}

function dtb_settings_render_notifications_tab(): void {
	$options = [
		'dtb_notify_order_failed'    => __( 'Notify on failed orders', 'drywall-toolbox' ),
		'dtb_notify_repair_complete' => __( 'Notify when repair is complete', 'drywall-toolbox' ),
		'dtb_notify_return_received' => __( 'Notify when return is received', 'drywall-toolbox' ),
		'dtb_notify_ticket_past_sla' => __( 'Notify when support ticket exceeds SLA', 'drywall-toolbox' ),
	];
	ob_start();
	foreach ( $options as $key => $label ) {
		$checked = get_option( $key, true ) ? 'checked' : '';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_field(
			'<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="1" ' . $checked . '> ' . esc_html__( 'Enabled', 'drywall-toolbox' ) . '</label>',
			esc_html( $label ),
			[]
		);
	}
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Notification Settings', 'drywall-toolbox' ) ] );
}

function dtb_settings_render_roles_tab(): void {
	$cap_map = dtb_admin_role_capability_map();
	ob_start();
	foreach ( $cap_map as $role => $caps ) {
		echo '<h3 class="dtb-settings-role-title">' . esc_html( $role ) . '</h3>';
		echo '<div class="dtb-badge-list">';
		foreach ( $caps as $cap ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo dtb_admin_ui_badge( $cap, 'neutral' );
		}
		echo '</div>';
	}
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [
		'title'  => __( 'Roles & Capabilities', 'drywall-toolbox' ),
		'footer' => '<p class="dtb-text-muted">' . esc_html__( 'Role capabilities are managed in code via AdminCapabilities.php.', 'drywall-toolbox' ) . '</p>',
	] );
}

function dtb_settings_render_integrations_tab(): void {
	$ih = dtb_integration_health_get();
	ob_start();
	foreach ( $ih['integrations'] as $item ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_detail_row(
			esc_html( $item['name'] ),
			dtb_admin_ui_badge( $item['ok'] ? __( 'Active', 'drywall-toolbox' ) : __( 'Missing', 'drywall-toolbox' ), $item['ok'] ? 'success' : 'danger' )
		);
	}
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Active Integrations', 'drywall-toolbox' ) ] );
}

function dtb_settings_render_ui_tab(): void {
	ob_start();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_field(
		dtb_admin_ui_input( 'dtb_admin_items_per_page', get_option( 'dtb_admin_items_per_page', 25 ), [ 'type' => 'number', 'min' => '10', 'max' => '200' ] ),
		__( 'Items per page', 'drywall-toolbox' ),
		[ 'description' => __( 'Default row count in admin queue tables (10–200).', 'drywall-toolbox' ) ]
	);
	$body = ob_get_clean();
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_card( $body, [ 'title' => __( 'Admin UI Preferences', 'drywall-toolbox' ) ] );
}
