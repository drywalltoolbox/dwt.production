<?php
/**
 * Marketplace Admin — MarketplaceOverviewPage
 *
 * Renders the Marketplace → Overview dashboard page.
 * Page slug: dtb-marketplace
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

function dtb_marketplace_render_overview_page(): void {
	if ( ! current_user_can( 'dtb_view_marketplace' ) ) {
		dtb_admin_shell_access_denied();
		return;
	}

	$overview   = DTB_MarketplaceReadModels::overview();
	$channels   = DTB_MarketplaceReadModels::channels();
	$exc_count  = DTB_MarketplaceExceptionService::count_open();

	dtb_admin_shell_open( [
		'title'    => __( 'Marketplace', 'drywall-toolbox' ),
		'subtitle' => __( 'Amazon & eBay marketplace health, orders, messages, and compliance.', 'drywall-toolbox' ),
		'section'  => 'operations',
		'page'     => 'dtb-marketplace',
		'template' => 'dashboard',
		'icon'     => 'dashicons-store',
		'tabs'     => [
			[ 'id' => 'overview',   'label' => __( 'Overview', 'drywall-toolbox' ),   'url' => admin_url( 'admin.php?page=dtb-marketplace' ),           'active' => true ],
			[ 'id' => 'orders',     'label' => __( 'Orders', 'drywall-toolbox' ),     'url' => admin_url( 'admin.php?page=dtb-marketplace-orders' ) ],
			[ 'id' => 'messages',   'label' => __( 'Messages', 'drywall-toolbox' ),   'url' => admin_url( 'admin.php?page=dtb-marketplace-messages' ) ],
			[ 'id' => 'exceptions', 'label' => __( 'Exceptions', 'drywall-toolbox' ), 'url' => admin_url( 'admin.php?page=dtb-marketplace-exceptions' ) ],
			[ 'id' => 'settings',   'label' => __( 'Settings', 'drywall-toolbox' ),   'url' => admin_url( 'admin.php?page=dtb-marketplace-settings' ) ],
		],
		'actions' => [
			dtb_admin_ui_button( __( 'Refresh', 'drywall-toolbox' ), [
				'type' => 'secondary',
				'icon' => 'dashicons-update',
				'size' => 'sm',
				'attr' => 'data-dtb-live-refresh="dtb-marketplace-overview"',
			] ),
		],
	] );

	dtb_admin_shell_live_region_open( [
		'id'       => 'dtb-marketplace-overview',
		'module'   => 'marketplace',
		'endpoint' => rest_url( 'dtb/v1/admin/marketplace/overview' ),
		'interval' => 120000,
	] );

	// Exception alert.
	if ( $exc_count > 0 ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_alert(
			sprintf(
				/* translators: %d = exception count */
				_n( '%d marketplace exception needs attention.', '%d marketplace exceptions need attention.', $exc_count, 'drywall-toolbox' ),
				$exc_count
			),
			'warning',
			__( 'Open Exceptions', 'drywall-toolbox' ),
			false
		);
	}

	// Channel cards.
	echo '<div class="dtb-grid dtb-grid--3">';

	$channel_by_key = [];
	foreach ( $channels as $ch ) {
		$channel_by_key[ $ch['channel_key'] ] = $ch;
	}

	foreach ( [ DTB_CHANNEL_AMAZON => 'Amazon', DTB_CHANNEL_EBAY => 'eBay' ] as $key => $label ) {
		$ch      = $channel_by_key[ $key ] ?? [];
		$health  = $ch['health_state'] ?? 'unknown';
		$auth    = $ch['auth_state']   ?? 'disconnected';
		$orders  = $overview['by_channel'][ $key ]['orders'] ?? [];
		$convs   = $overview['by_channel'][ $key ]['conversations'] ?? [];

		$badge_class = match ( $health ) {
			'ok'      => 'dtb-badge--success',
			'error'   => 'dtb-badge--danger',
			'degraded' => 'dtb-badge--warning',
			default   => 'dtb-badge--muted',
		};

		$body  = '<div class="dtb-stat-grid">';
		$body .= '<div class="dtb-stat"><span class="dtb-stat__value">' . esc_html( (string) ( $orders['total'] ?? 0 ) ) . '</span><span class="dtb-stat__label">' . esc_html__( 'Orders', 'drywall-toolbox' ) . '</span></div>';
		$body .= '<div class="dtb-stat"><span class="dtb-stat__value">' . esc_html( (string) ( $orders['unshipped'] ?? 0 ) ) . '</span><span class="dtb-stat__label">' . esc_html__( 'Unshipped', 'drywall-toolbox' ) . '</span></div>';
		$body .= '<div class="dtb-stat"><span class="dtb-stat__value">' . esc_html( (string) ( $convs['open_count'] ?? 0 ) ) . '</span><span class="dtb-stat__label">' . esc_html__( 'Open Msgs', 'drywall-toolbox' ) . '</span></div>';
		$body .= '<div class="dtb-stat"><span class="dtb-stat__value">' . esc_html( (string) ( $convs['sla_breached'] ?? 0 ) ) . '</span><span class="dtb-stat__label">' . esc_html__( 'SLA Breach', 'drywall-toolbox' ) . '</span></div>';
		$body .= '</div>';

		$body .= '<p class="dtb-card__meta">';
		$body .= '<span class="dtb-badge ' . esc_attr( $badge_class ) . '">' . esc_html( ucfirst( $health ) ) . '</span> ';
		$body .= '<span class="dtb-badge dtb-badge--muted">' . esc_html( ucfirst( $auth ) ) . '</span>';
		$body .= '</p>';

		if ( '' !== ( $ch['last_error_msg'] ?? '' ) ) {
			$body .= '<p class="dtb-text--danger dtb-text--sm">' . esc_html( $ch['last_error_msg'] ) . '</p>';
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo dtb_admin_ui_card( $body, [
			'title'  => $label,
			'icon'   => 'dashicons-store',
			'modifier' => 'dtb-channel-card',
		] );
	}

	echo '</div>'; // .dtb-grid

	dtb_admin_shell_live_region_close();
	dtb_admin_shell_close();
}
