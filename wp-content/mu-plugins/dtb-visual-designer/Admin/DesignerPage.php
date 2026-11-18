<?php
/**
 * DTB Visual Designer wp-admin surface.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_VD_ADMIN_PAGE_SLUG = 'dtb-visual-designer';

add_action( 'init', 'dtb_vd_admin_register_page', 15 );
add_action( 'admin_enqueue_scripts', 'dtb_vd_admin_localize_config', 20 );

function dtb_vd_admin_is_current_screen(): bool {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return DTB_VD_ADMIN_PAGE_SLUG === $page;
}

function dtb_vd_admin_current_studio(): string {
	$studio = isset( $_GET['studio'] ) ? sanitize_key( wp_unslash( $_GET['studio'] ) ) : 'storefront'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return 'email' === $studio ? 'email' : 'storefront';
}

function dtb_vd_admin_register_page(): void {
	if ( ! function_exists( 'dtb_register_admin_page' ) ) {
		return;
	}

	$assets_dir = __DIR__ . '/assets/';
	$assets_url = plugin_dir_url( __FILE__ ) . 'assets/';

	dtb_register_admin_page( [
		'library'      => 'tools',
		'slug'         => DTB_VD_ADMIN_PAGE_SLUG,
		'title'        => __( 'Visual Designer', 'drywall-toolbox' ),
		'menu_title'   => __( 'Visual Designer', 'drywall-toolbox' ),
		'capability'   => DTB_VD_CAPABILITY,
		'callback'     => 'dtb_vd_admin_render_page',
		'position'     => 15,
		'template'     => 'tool',
		'section'      => 'Storefront Presentation',
		'icon'         => 'dashicons-art',
		'menu_visible' => true,
		'assets'       => [
			'css' => [
				[ 'id' => 'dtb-visual-designer', 'dir' => $assets_dir, 'url' => $assets_url, 'file' => 'dtb-visual-designer.css' ],
				[ 'id' => 'dtb-email-studio', 'dir' => $assets_dir, 'url' => $assets_url, 'file' => 'dtb-email-studio.css' ],
			],
			'js'  => [
				[ 'id' => 'dtb-visual-designer', 'dir' => $assets_dir, 'url' => $assets_url, 'file' => 'dtb-visual-designer.js' ],
				[ 'id' => 'dtb-email-studio', 'dir' => $assets_dir, 'url' => $assets_url, 'file' => 'dtb-email-studio.js' ],
			],
		],
	] );
}

function dtb_vd_admin_render_page(): void {
	if ( ! current_user_can( DTB_VD_CAPABILITY ) ) {
		wp_die( esc_html__( 'You do not have permission to access the Visual Designer.', 'drywall-toolbox' ) );
	}

	$studio = dtb_vd_admin_current_studio();
	$base   = admin_url( 'admin.php?page=' . DTB_VD_ADMIN_PAGE_SLUG );
	?>
	<div class="wrap dtb-admin dtb-vd-shell">
		<nav class="dtb-vd-studio-nav" aria-label="<?php esc_attr_e( 'Visual Designer studios', 'drywall-toolbox' ); ?>">
			<a class="<?php echo 'storefront' === $studio ? 'is-active' : ''; ?>" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( 'Storefront Studio', 'drywall-toolbox' ); ?></a>
			<a class="<?php echo 'email' === $studio ? 'is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'studio', 'email', $base ) ); ?>"><?php esc_html_e( 'Email Studio', 'drywall-toolbox' ); ?></a>
		</nav>

		<?php if ( 'email' === $studio ) : ?>
			<div class="dtb-email-studio" id="dtb-email-studio" data-dtb-email-studio>
				<div class="dtb-vd__loading"><div class="dtb-vd__spinner" aria-hidden="true"></div><p><?php esc_html_e( 'Loading Email Studio…', 'drywall-toolbox' ); ?></p></div>
			</div>
		<?php else : ?>
			<div class="dtb-admin dtb-vd" id="dtb-vd-root" data-dtb-vd-root>
				<div class="dtb-vd__loading"><div class="dtb-vd__spinner" aria-hidden="true"></div><p><?php esc_html_e( 'Loading Visual Designer…', 'drywall-toolbox' ); ?></p></div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

function dtb_vd_admin_localize_config(): void {
	if ( ! dtb_vd_admin_is_current_screen() || ! current_user_can( DTB_VD_CAPABILITY ) ) {
		return;
	}

	$surfaces = [];
	foreach ( dtb_vd_get_surfaces() as $surface_id => $surface ) {
		$surfaces[] = [
			'id'       => $surface_id,
			'name'     => $surface['name'],
			'category' => $surface['category'],
			'route'    => $surface['route'],
		];
	}

	wp_localize_script( 'dtb-visual-designer', 'dtbVisualDesignerConfig', [
		'storefrontOrigin' => untrailingslashit( home_url() ),
		'surfaces'         => $surfaces,
		'breakpoints'      => dtb_vd_breakpoints(),
		'studio'           => dtb_vd_admin_current_studio(),
	] );
}
