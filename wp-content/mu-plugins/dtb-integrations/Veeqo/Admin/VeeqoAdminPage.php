<?php
/**
 * Veeqo control-center wp-admin page and asset registration.
 *
 * Registered through the shared AdminPageRegistry (dtb_register_admin_page())
 * so this page participates in the same central AdminAssets enqueue pipeline,
 * design tokens, and shared integration control-center layer as the other DTB
 * operational screens.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VEEQO_ADMIN_PAGE_SLUG = 'dtb-veeqo-control-center';

add_action( 'init', 'dtb_veeqo_admin_register_page', 15 );
add_action( 'admin_enqueue_scripts', 'dtb_veeqo_admin_enqueue_api_fetch', 5 );
add_action( 'admin_enqueue_scripts', 'dtb_veeqo_admin_localize_config', 20 );

function dtb_veeqo_admin_enqueue_api_fetch(): void {
	if ( ! dtb_veeqo_admin_is_current_screen() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	wp_enqueue_script( 'wp-api-fetch' );
}

function dtb_veeqo_admin_browser_locale(): string {
	$locale = str_replace( '_', '-', (string) get_user_locale() );
	if ( 1 !== preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $locale ) ) {
		return 'en-US';
	}
	return $locale;
}

add_action(
	'admin_init',
	static function (): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'dtb-veeqo-operations' !== $page || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . DTB_VEEQO_ADMIN_PAGE_SLUG ) );
		exit;
	},
	5
);

function dtb_veeqo_admin_register_page(): void {
	if ( ! function_exists( 'dtb_register_admin_page' ) ) {
		return;
	}

	$assets_dir = dirname( __DIR__ ) . '/assets/';
	$assets_url = content_url( 'mu-plugins/dtb-integrations/Veeqo/assets/' );
	$shared_dir = dirname( dirname( __DIR__ ) ) . '/assets/';
	$shared_url = content_url( 'mu-plugins/dtb-integrations/assets/' );

	dtb_register_admin_page(
		[
			'library'      => 'operations',
			'slug'         => DTB_VEEQO_ADMIN_PAGE_SLUG,
			'title'        => __( 'Veeqo Control Center', 'drywall-toolbox' ),
			'menu_title'   => __( 'Veeqo', 'drywall-toolbox' ),
			'capability'   => 'manage_woocommerce',
			'callback'     => 'dtb_veeqo_admin_render_page',
			'position'     => 59,
			'template'     => 'dashboard',
			'section'      => 'Integrations',
			'icon'         => 'dashicons-store',
			'menu_visible' => true,
			'assets'       => [
				'css' => [
					[
						'id'   => 'dtb-veeqo-admin',
						'dir'  => $assets_dir,
						'url'  => $assets_url,
						'file' => 'veeqo-admin.css',
					],
					[
						'id'   => 'dtb-veeqo-inventory-workspace',
						'dir'  => $assets_dir,
						'url'  => $assets_url,
						'file' => 'veeqo-inventory-workspace.css',
					],
					[
						'id'   => 'dtb-integration-control-center',
						'dir'  => $shared_dir,
						'url'  => $shared_url,
						'file' => 'integration-control-center.css',
					],
				],
				'js'  => [
					[
						'id'   => 'dtb-veeqo-admin',
						'dir'  => $assets_dir,
						'url'  => $assets_url,
						'file' => 'veeqo-admin.js',
					],
					[
						'id'   => 'dtb-veeqo-inventory-workspace',
						'dir'  => $assets_dir,
						'url'  => $assets_url,
						'file' => 'veeqo-inventory-workspace.js',
					],
					[
						'id'   => 'dtb-integration-control-center',
						'dir'  => $shared_dir,
						'url'  => $shared_url,
						'file' => 'integration-control-center.js',
					],
				],
			],
		]
	);
}

function dtb_veeqo_admin_is_current_screen(): bool {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return DTB_VEEQO_ADMIN_PAGE_SLUG === $page;
}

/**
 * Localize runtime config onto the `dtb-veeqo-admin` script handle enqueued
 * by the central AdminAssets pipeline.
 */
function dtb_veeqo_admin_localize_config(): void {
	if ( ! dtb_veeqo_admin_is_current_screen() || ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	if ( ! wp_script_is( 'dtb-veeqo-admin', 'enqueued' ) && ! wp_script_is( 'dtb-veeqo-admin', 'registered' ) ) {
		return;
	}

	$config = [
		'restRoot'  => esc_url_raw( site_url( '/wp-json/' ) ),
		'basePath'  => '/dtb/v1/veeqo/admin/control-center',
		'pageUrl'   => esc_url_raw( admin_url( 'admin.php?page=' . DTB_VEEQO_ADMIN_PAGE_SLUG ) ),
		'currency'  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD',
		'locale'    => dtb_veeqo_admin_browser_locale(),
		'pageSize'  => 50,
		'pollMs'    => 3000,
		'pollLimit' => 100,
		'labels'    => [
			'confirmReconcile' => __( 'Apply configured-warehouse Veeqo stock to WooCommerce?', 'drywall-toolbox' ),
			'confirmRetry'     => __( 'Queue a Veeqo retry for this WooCommerce order?', 'drywall-toolbox' ),
		],
	];

	wp_add_inline_script( 'dtb-veeqo-admin', 'window.DTBVeeqoAdmin=' . wp_json_encode( $config ) . ';', 'before' );
}

function dtb_veeqo_admin_render_page(): void {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		wp_die( esc_html__( 'You do not have permission to manage Veeqo operations.', 'drywall-toolbox' ) );
	}
	?>
	<div class="wrap dtb-veeqo-admin-wrap">
		<div id="dtb-veeqo-admin-root" class="dtb-veeqo-admin" aria-live="polite">
			<div class="dtb-veeqo-boot">
				<span class="spinner is-active" aria-hidden="true"></span>
				<p><?php esc_html_e( 'Loading Veeqo Control Center…', 'drywall-toolbox' ); ?></p>
			</div>
		</div>
		<noscript><div class="notice notice-error"><p><?php esc_html_e( 'Veeqo Control Center requires JavaScript in wp-admin.', 'drywall-toolbox' ); ?></p></div></noscript>
	</div>
	<?php
}
