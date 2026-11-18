<?php
/**
 * QuickBooks enterprise wp-admin workspace.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_QBO_ADMIN_PAGE_SLUG = 'dtb-quickbooks';

add_action( 'init', 'dtb_qbo_admin_register_page', 15 );
add_action( 'admin_enqueue_scripts', 'dtb_qbo_admin_enqueue_api_fetch', 5 );
add_action( 'admin_enqueue_scripts', 'dtb_qbo_admin_localize_config', 20 );
add_filter( 'admin_body_class', 'dtb_qbo_admin_body_class' );

function dtb_qbo_admin_enqueue_api_fetch(): void {
	if ( dtb_qbo_admin_is_current_screen() && current_user_can( 'manage_options' ) ) {
		wp_enqueue_script( 'wp-api-fetch' );
	}
}

function dtb_qbo_admin_register_page(): void {
	if ( ! function_exists( 'dtb_register_admin_page' ) ) {
		return;
	}
	$assets_dir = dirname( __DIR__ ) . '/assets/';
	$assets_url = content_url( 'mu-plugins/dtb-integrations/QuickBooks/assets/' );
	dtb_register_admin_page( [
		'library' => 'operations', 'slug' => DTB_QBO_ADMIN_PAGE_SLUG,
		'title' => __( 'QuickBooks', 'drywall-toolbox' ), 'menu_title' => __( 'QuickBooks', 'drywall-toolbox' ),
		'capability' => 'manage_options', 'callback' => 'dtb_qbo_admin_render_page', 'position' => 58,
		'template' => 'dashboard', 'section' => 'Integrations', 'icon' => 'dashicons-chart-area', 'menu_visible' => true,
		'assets' => [
			'css' => [ [ 'id' => 'dtb-qbo-admin', 'dir' => $assets_dir, 'url' => $assets_url, 'file' => 'quickbooks-admin.css' ] ],
			'js'  => [ [ 'id' => 'dtb-qbo-admin', 'dir' => $assets_dir, 'url' => $assets_url, 'file' => 'quickbooks-admin.js' ] ],
		],
	] );
}

function dtb_qbo_admin_is_current_screen(): bool {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return DTB_QBO_ADMIN_PAGE_SLUG === $page;
}

function dtb_qbo_admin_body_class( string $classes ): string {
	return dtb_qbo_admin_is_current_screen() ? $classes . ' dtb-qbo-admin-screen' : $classes;
}

function dtb_qbo_admin_localize_config(): void {
	if ( ! dtb_qbo_admin_is_current_screen() || ! current_user_can( 'manage_options' ) || ! wp_script_is( 'dtb-qbo-admin', 'registered' ) ) {
		return;
	}
	$config = [
		'restRoot' => esc_url_raw( rest_url() ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'basePath' => 'dtb/v1/admin/qbo',
		'environment' => dtb_qbo_environment(), 'pollInterval' => 15000, 'pageSize' => 25,
		'labels' => [
			'confirmDisconnect' => __( 'Disconnect QuickBooks from this environment? Existing WooCommerce and QuickBooks records will not be deleted.', 'drywall-toolbox' ),
			'connectionPassed' => __( 'QuickBooks connection verified.', 'drywall-toolbox' ),
			'itemsMapped' => __( 'Accounting items discovered and mapped.', 'drywall-toolbox' ),
			'copied' => __( 'Copied to clipboard.', 'drywall-toolbox' ),
		],
	];
	wp_add_inline_script( 'dtb-qbo-admin', 'window.DTBQuickBooksAdmin=' . wp_json_encode( $config ) . ';', 'before' );
}

function dtb_qbo_admin_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage QuickBooks.', 'drywall-toolbox' ) );
	}
	$tabs = [
		'overview' => [ __( 'Overview', 'drywall-toolbox' ), 'dashboard' ],
		'transactions' => [ __( 'Transactions', 'drywall-toolbox' ), 'list-view' ],
		'exceptions' => [ __( 'Exceptions', 'drywall-toolbox' ), 'warning' ],
		'tax' => [ __( 'Tax Center', 'drywall-toolbox' ), 'location-alt' ],
		'settlement' => [ __( 'Settlement', 'drywall-toolbox' ), 'money-alt' ],
		'reports' => [ __( 'Reports & Close', 'drywall-toolbox' ), 'media-spreadsheet' ],
		'rules' => [ __( 'Rules', 'drywall-toolbox' ), 'filter' ],
		'automation' => [ __( 'Automation', 'drywall-toolbox' ), 'controls-repeat' ],
		'audit' => [ __( 'Audit', 'drywall-toolbox' ), 'shield' ],
	];
	?>
	<div class="wrap dtb-qbo-admin" id="dtb-qbo-admin-root" aria-live="polite">
		<header class="dtb-qbo-appbar">
			<div class="dtb-qbo-brand"><span class="dtb-qbo-mark" aria-hidden="true">qb</span><div><h1><?php esc_html_e( 'QuickBooks Accounting', 'drywall-toolbox' ); ?></h1><p><?php esc_html_e( 'Drywall Toolbox · accounting, tax, settlement, and close controls', 'drywall-toolbox' ); ?></p></div></div>
			<div class="dtb-qbo-appbar__status"><span class="dtb-qbo-live"><i></i><?php esc_html_e( 'Projection ledger active', 'drywall-toolbox' ); ?></span><span data-qbo-last-refresh>—</span><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dtb_qbo_accounting_export' ), 'dtb_qbo_accounting_export' ) ); ?>"><?php esc_html_e( 'Export', 'drywall-toolbox' ); ?></a><button class="button button-primary" type="button" data-qbo-action="refresh"><?php esc_html_e( 'Refresh', 'drywall-toolbox' ); ?></button></div>
		</header>
		<div class="dtb-qbo-alert" data-qbo-alert hidden role="status"></div>
		<nav class="dtb-qbo-primary-nav" role="tablist" aria-label="<?php esc_attr_e( 'QuickBooks sections', 'drywall-toolbox' ); ?>">
			<?php foreach ( $tabs as $key => $tab ) : $panel_id = 'dtb-qbo-panel-' . $key; ?>
				<button id="dtb-qbo-tab-<?php echo esc_attr( $key ); ?>" type="button" role="tab" class="dtb-qbo-nav-item<?php echo 'overview' === $key ? ' is-active' : ''; ?>" data-qbo-tab="<?php echo esc_attr( $key ); ?>" aria-controls="<?php echo esc_attr( $panel_id ); ?>" aria-selected="<?php echo 'overview' === $key ? 'true' : 'false'; ?>" tabindex="<?php echo 'overview' === $key ? '0' : '-1'; ?>"><span class="dashicons dashicons-<?php echo esc_attr( $tab[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $tab[0] ); ?></button>
			<?php endforeach; ?>
		</nav>
		<main class="dtb-qbo-content">
			<div class="dtb-qbo-toolbar">
				<label><span><?php esc_html_e( 'Search', 'drywall-toolbox' ); ?></span><input type="search" data-qbo-filter="search" placeholder="<?php esc_attr_e( 'Document, source, or QBO ID', 'drywall-toolbox' ); ?>"></label>
				<label><span><?php esc_html_e( 'From', 'drywall-toolbox' ); ?></span><input type="date" data-qbo-filter="from"></label>
				<label><span><?php esc_html_e( 'To', 'drywall-toolbox' ); ?></span><input type="date" data-qbo-filter="to"></label>
				<label><span><?php esc_html_e( 'Saved view', 'drywall-toolbox' ); ?></span><select data-qbo-saved-view><option value="default"><?php esc_html_e( 'Default view', 'drywall-toolbox' ); ?></option><option value="month"><?php esc_html_e( 'This month', 'drywall-toolbox' ); ?></option><option value="attention"><?php esc_html_e( 'Needs attention', 'drywall-toolbox' ); ?></option></select></label>
				<button class="button" type="button" data-qbo-action="apply-filters"><?php esc_html_e( 'Apply', 'drywall-toolbox' ); ?></button>
				<button class="button" type="button" data-qbo-action="save-view"><?php esc_html_e( 'Save view', 'drywall-toolbox' ); ?></button>
			</div>
			<?php foreach ( array_keys( $tabs ) as $view ) : ?>
			<section id="dtb-qbo-panel-<?php echo esc_attr( $view ); ?>" class="dtb-qbo-panel<?php echo 'overview' === $view ? ' is-active' : ''; ?>" role="tabpanel" aria-labelledby="dtb-qbo-tab-<?php echo esc_attr( $view ); ?>" data-qbo-panel="<?php echo esc_attr( $view ); ?>" <?php echo 'overview' === $view ? '' : 'hidden'; ?>>
				<div class="dtb-qbo-panel__header"><div><h2 data-qbo-title><?php echo esc_html( $tabs[ $view ][0] ); ?></h2><p data-qbo-subtitle><?php echo esc_html( dtb_qbo_admin_tab_description( $view ) ); ?></p></div><div class="dtb-qbo-panel__actions" data-qbo-panel-actions="<?php echo esc_attr( $view ); ?>"></div><div class="dtb-qbo-pagination" data-qbo-pagination hidden></div></div>
				<div class="dtb-qbo-kpis" data-qbo-kpis hidden></div>
				<div data-qbo-table="<?php echo esc_attr( $view ); ?>"><div class="dtb-qbo-loading"><span class="spinner is-active"></span><?php esc_html_e( 'Loading…', 'drywall-toolbox' ); ?></div></div>
			</section>
			<?php endforeach; ?>
		</main>
	</div>
	<noscript><div class="notice notice-error"><p><?php esc_html_e( 'The QuickBooks workspace requires JavaScript in wp-admin.', 'drywall-toolbox' ); ?></p></div></noscript>
	<?php
}

function dtb_qbo_admin_tab_description( string $view ): string {
	return [
		'overview'     => __( 'Month-to-date accounting health and the latest projection activity.', 'drywall-toolbox' ),
		'transactions' => __( 'Full-range sales, refunds, and accounting documents with QBO comparison.', 'drywall-toolbox' ),
		'exceptions'   => __( 'Failed invariants, remote mismatches, and retryable operational failures.', 'drywall-toolbox' ),
		'tax'          => __( 'Jurisdiction, rate, liability, exemption, and refund-reversal visibility.', 'drywall-toolbox' ),
		'settlement'   => __( 'Historical Stripe payout records only. New imports are disabled; WooPayments is the active gateway.', 'drywall-toolbox' ),
		'reports'      => __( 'Read-only QuickBooks reports, period close, and accountant exports.', 'drywall-toolbox' ),
		'rules'        => __( 'Accountant-approved tax, item, clearing, fee, deposit, and bank mappings.', 'drywall-toolbox' ),
		'automation'   => __( 'Queue schedules, report refresh, settlement import, and health controls.', 'drywall-toolbox' ),
		'audit'        => __( 'Immutable source identity, policy version, payload hash, trace, and review history.', 'drywall-toolbox' ),
	][ $view ] ?? '';
}
