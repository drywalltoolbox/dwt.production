<?php
/**
 * DTB Admin — AdminAssets
 *
 * Responsible for:
 *  - Detecting current DTB admin page via AdminPageRegistry.
 *  - Enqueuing DTB behavior scripts only on DTB pages.
 *  - Removing DTB-owned admin styles so the active admin-theme plugin owns all
 *    wp-admin presentation.
 *  - Conditionally loading ApexCharts on dashboard-template pages.
 *  - Localizing the dtbAdminConfig JS object.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', 'dtb_admin_assets_enqueue' );
add_action( 'admin_enqueue_scripts', 'dtb_admin_remove_custom_styles', PHP_INT_MAX );
add_action( 'admin_enqueue_scripts', 'dtb_admin_order_ui_assets', 1000 );
add_filter( 'admin_body_class', 'dtb_admin_assets_body_class' );

/**
 * Keep DTB admin behavior while delegating all wp-admin presentation to the
 * active admin-theme plugin (BrikPanel in production).
 *
 * Several bounded modules register their own stylesheet handles. Removing by
 * DTB MU-plugin source URL at the end of the enqueue phase creates one durable
 * ownership boundary without coupling this repository to a regular plugin's
 * implementation or handle names.
 */
function dtb_admin_remove_custom_styles(): void {
	$styles = wp_styles();
	foreach ( (array) $styles->queue as $handle ) {
		// Schematics owns only its control-center content layout. The active
		// admin theme continues to own global wp-admin chrome and base tokens.
		if ( in_array( $handle, [ 'dtb-schematics-workspace', 'dtb-order-admin-polish' ], true ) ) {
			continue;
		}
		$registered = $styles->registered[ $handle ] ?? null;
		$source     = is_object( $registered ) ? (string) $registered->src : '';
		$is_dtb_css = 'dtb-fonts' === $handle
			|| false !== strpos( $source, '/mu-plugins/dtb-' );

		if ( $is_dtb_css ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
	}
}

/**
 * Load the scoped order editor and preview polish after BrikPanel styles.
 *
 * @param string $hook_suffix Current admin hook.
 */
function dtb_admin_order_ui_assets( string $hook_suffix ): void {
	$screen = get_current_screen();
	if ( ! $screen ) {
		return;
	}

	$page = sanitize_key( (string) ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_orders_page = 'wc-orders' === $page;
	$is_legacy_order_screen = 'shop_order' === ( $screen->post_type ?? '' )
		&& in_array( $screen->base, [ 'post', 'edit' ], true );

	if ( ! $is_orders_page && ! $is_legacy_order_screen ) {
		return;
	}

	$css_file = __DIR__ . '/assets/dtb-order-admin-polish.css';
	if ( ! file_exists( $css_file ) ) {
		return;
	}

	$style_deps = [];
	$styles     = wp_styles();
	foreach ( (array) $styles->queue as $handle ) {
		$registered = $styles->registered[ $handle ] ?? null;
		$source     = is_object( $registered ) ? (string) $registered->src : '';
		if (
			false !== strpos( $source, '/brikpanel-order.css' )
			|| false !== strpos( $source, '/brikpanel-order-tabs.css' )
			|| false !== strpos( $source, '/brikpanel-orders.css' )
		) {
			$style_deps[] = $handle;
		}
	}

	wp_enqueue_style(
		'dtb-order-admin-polish',
		plugin_dir_url( __FILE__ ) . 'assets/dtb-order-admin-polish.css',
		array_values( array_unique( $style_deps ) ),
		(string) filemtime( $css_file )
	);
}

function dtb_admin_assets_enqueue(): void {
	$page_meta   = dtb_current_page_meta();
	$page_slug   = sanitize_key( (string) ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_dtb_slug = '' !== $page_slug && str_starts_with( $page_slug, 'dtb-' );

	if ( ! $page_meta && ! $is_dtb_slug ) {
		return;
	}

	// Fallback runtime metadata for migrated pages if registry lookup failed.
	if ( ! is_array( $page_meta ) ) {
		$page_meta = [
			'slug'     => $page_slug,
			'template' => in_array( $page_slug, [ 'dtb-command-center', 'dtb-system-manager' ], true ) ? 'dashboard' : 'tool',
		];
	}

	$assets_dir = __DIR__ . '/assets/';
	$assets_url = plugin_dir_url( __FILE__ ) . 'assets/';

	// ── Web Fonts (Inter + Plus Jakarta Sans via Bunny Fonts — privacy-respecting CDN) ──
	wp_enqueue_style(
		'dtb-fonts',
		'https://fonts.bunny.net/css?family=inter:400,500,600,700|plus-jakarta-sans:400,500,600,700&display=swap',
		[],
		null
	);

	// ── Shared CSS ──
	$css_file = $assets_dir . 'dtb-admin.css';
	$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : '2.0.0';

	wp_enqueue_style(
		'dtb-admin',
		$assets_url . 'dtb-admin.css',
		[ 'dtb-fonts' ],
		$css_ver
	);

	// ── Workbench shared CSS (loads after dtb-admin) ──
	$wb_css_file = $assets_dir . 'dtb-admin-workbench.css';
	if ( file_exists( $wb_css_file ) ) {
		wp_enqueue_style(
			'dtb-admin-workbench',
			$assets_url . 'dtb-admin-workbench.css',
			[ 'dtb-admin' ],
			(string) filemtime( $wb_css_file )
		);
	}

	// ── Workbench action/menu + modal hero CSS ──
	$wb_actions_css_file = $assets_dir . 'dtb-admin-workbench-actions.css';
	if ( file_exists( $wb_actions_css_file ) ) {
		$wb_actions_css_deps = [ 'dtb-admin' ];
		if ( wp_style_is( 'dtb-admin-workbench', 'enqueued' ) ) {
			$wb_actions_css_deps[] = 'dtb-admin-workbench';
		}
		wp_enqueue_style(
			'dtb-admin-workbench-actions',
			$assets_url . 'dtb-admin-workbench-actions.css',
			$wb_actions_css_deps,
			(string) filemtime( $wb_actions_css_file )
		);
	}

	// ── Shared bulk-select and remove actions for operational queues ──
	$bulk_actions_css_file = $assets_dir . 'dtb-admin-bulk-actions.css';
	if ( file_exists( $bulk_actions_css_file ) ) {
		$bulk_actions_css_deps = [ 'dtb-admin' ];
		if ( wp_style_is( 'dtb-admin-workbench', 'enqueued' ) ) {
			$bulk_actions_css_deps[] = 'dtb-admin-workbench';
		}
		if ( wp_style_is( 'dtb-admin-workbench-actions', 'enqueued' ) ) {
			$bulk_actions_css_deps[] = 'dtb-admin-workbench-actions';
		}
		wp_enqueue_style(
			'dtb-admin-bulk-actions',
			$assets_url . 'dtb-admin-bulk-actions.css',
			$bulk_actions_css_deps,
			(string) filemtime( $bulk_actions_css_file )
		);
	}

	// ── Shared JS ──
	$js_file = $assets_dir . 'dtb-admin.js';
	$js_ver  = file_exists( $js_file ) ? (string) filemtime( $js_file ) : '2.0.0';

	wp_enqueue_script(
		'dtb-admin',
		$assets_url . 'dtb-admin.js',
		[],
		$js_ver,
		true
	);

	// ── Workbench shared JS (loads after dtb-admin, before module scripts) ──
	$wb_js_file = $assets_dir . 'dtb-admin-workbench.js';
	if ( file_exists( $wb_js_file ) ) {
		wp_enqueue_script(
			'dtb-admin-workbench',
			$assets_url . 'dtb-admin-workbench.js',
			[ 'dtb-admin' ],
			(string) filemtime( $wb_js_file ),
			true
		);
	}

	// ── Workbench action/menu + modal hero JS (progressive enhancement) ──
	$wb_actions_js_file = $assets_dir . 'dtb-admin-workbench-actions.js';
	if ( file_exists( $wb_actions_js_file ) ) {
		$wb_actions_js_deps = [ 'dtb-admin' ];
		if ( wp_script_is( 'dtb-admin-workbench', 'enqueued' ) ) {
			$wb_actions_js_deps[] = 'dtb-admin-workbench';
		}
		wp_enqueue_script(
			'dtb-admin-workbench-actions',
			$assets_url . 'dtb-admin-workbench-actions.js',
			$wb_actions_js_deps,
			(string) filemtime( $wb_actions_js_file ),
			true
		);
	}

	// ── Shared bulk-select and remove actions JS ──
	$bulk_actions_js_file = $assets_dir . 'dtb-admin-bulk-actions.js';
	if ( file_exists( $bulk_actions_js_file ) ) {
		$bulk_actions_js_deps = [ 'dtb-admin' ];
		if ( wp_script_is( 'dtb-admin-workbench', 'enqueued' ) ) {
			$bulk_actions_js_deps[] = 'dtb-admin-workbench';
		}
		wp_enqueue_script(
			'dtb-admin-bulk-actions',
			$assets_url . 'dtb-admin-bulk-actions.js',
			$bulk_actions_js_deps,
			(string) filemtime( $bulk_actions_js_file ),
			true
		);
	}

	// ── Shared admin chat component ──
	$admin_chat_js_file = $assets_dir . 'dtb-admin-chat.js';
	if ( file_exists( $admin_chat_js_file ) ) {
		$admin_chat_js_deps = [ 'dtb-admin' ];
		if ( wp_script_is( 'dtb-admin-workbench', 'enqueued' ) ) {
			$admin_chat_js_deps[] = 'dtb-admin-workbench';
		}
		wp_enqueue_script(
			'dtb-admin-chat',
			$assets_url . 'dtb-admin-chat.js',
			$admin_chat_js_deps,
			(string) filemtime( $admin_chat_js_file ),
			true
		);
	}

	$current_user = wp_get_current_user();

	// ── Module-specific CSS (declared by page metadata) ──
	$page_slug          = $page_meta['slug'] ?? '';
	$module_css_handles = [];

	foreach ( (array) ( $page_meta['assets']['css'] ?? [] ) as $mod ) {
		$mod_file = $mod['dir'] . $mod['file'];
		$mod_ver  = file_exists( $mod_file ) ? (string) filemtime( $mod_file ) : '1.0.0';

		// Build CSS deps: always dtb-admin; add workbench/action CSS if available.
		$css_deps = [ 'dtb-admin' ];
		if ( file_exists( $assets_dir . 'dtb-admin-workbench.css' ) ) {
			$css_deps[] = 'dtb-admin-workbench';
		}
		if ( file_exists( $assets_dir . 'dtb-admin-workbench-actions.css' ) ) {
			$css_deps[] = 'dtb-admin-workbench-actions';
		}

		wp_enqueue_style(
			$mod['id'],
			$mod['url'] . $mod['file'],
			$css_deps,
			$mod_ver
		);
		$module_css_handles[] = $mod['id'];
	}

	// ── Module-specific JS (declared by page metadata) ──
	foreach ( (array) ( $page_meta['assets']['js'] ?? [] ) as $mod_js ) {
		$mod_js_file = $mod_js['dir'] . $mod_js['file'];
		$mod_js_ver  = file_exists( $mod_js_file ) ? (string) filemtime( $mod_js_file ) : '1.0.0';

		if ( file_exists( $mod_js_file ) ) {
			// Build deps: always include dtb-admin; add workbench/action enhancers if enqueued.
			$js_deps = [ 'dtb-admin' ];
			if ( wp_script_is( 'dtb-admin-workbench', 'enqueued' ) ) {
				$js_deps[] = 'dtb-admin-workbench';
			}
			if ( wp_script_is( 'dtb-admin-workbench-actions', 'enqueued' ) ) {
				$js_deps[] = 'dtb-admin-workbench-actions';
			}
			if ( wp_script_is( 'dtb-admin-chat', 'enqueued' ) ) {
				$js_deps[] = 'dtb-admin-chat';
			}

			wp_enqueue_script(
				$mod_js['id'],
				$mod_js['url'] . $mod_js['file'],
				$js_deps,
				$mod_js_ver,
				true
			);
		}
	}

	// ── Shared hardening stylesheet for inline-legacy Tool Library pages ──
	$legacy_tool_pages       = [
		'dtb-parts-manager',
		'dtb-product-mapping',
	];
	$legacy_tool_css_handle = null;
	if ( in_array( $page_slug, $legacy_tool_pages, true ) ) {
		$tools_file = $assets_dir . 'dtb-tool-library-modern.css';
		if ( file_exists( $tools_file ) ) {
			$legacy_tool_css_handle = 'dtb-tool-library-modern';
			wp_enqueue_style(
				$legacy_tool_css_handle,
				$assets_url . 'dtb-tool-library-modern.css',
				[ 'dtb-admin' ],
				(string) filemtime( $tools_file )
			);
		}
	}

	// ── Final shared visual polish override. Loads last so no DTB page falls back to generic WP styling. ──
	$modern_polish_css_file = $assets_dir . 'dtb-admin-modern-polish.css';
	if ( file_exists( $modern_polish_css_file ) ) {
		$modern_polish_deps = array_values( array_filter( array_merge(
			[ 'dtb-admin' ],
			wp_style_is( 'dtb-admin-workbench', 'enqueued' ) ? [ 'dtb-admin-workbench' ] : [],
			wp_style_is( 'dtb-admin-workbench-actions', 'enqueued' ) ? [ 'dtb-admin-workbench-actions' ] : [],
			wp_style_is( 'dtb-admin-bulk-actions', 'enqueued' ) ? [ 'dtb-admin-bulk-actions' ] : [],
			$module_css_handles,
			$legacy_tool_css_handle ? [ $legacy_tool_css_handle ] : []
		) ) );

		wp_enqueue_style(
			'dtb-admin-modern-polish',
			$assets_url . 'dtb-admin-modern-polish.css',
			$modern_polish_deps,
			(string) filemtime( $modern_polish_css_file )
		);
	}

	// ── ApexCharts (dashboard pages only) ──
	if ( in_array( $page_meta['template'] ?? '', [ 'dashboard' ], true ) ) {
		$apex_file = $assets_dir . 'vendor/apexcharts.min.js';
		$apex_ver  = file_exists( $apex_file ) ? (string) filemtime( $apex_file ) : '3.44.0';

		if ( file_exists( $apex_file ) ) {
			wp_enqueue_script(
				'dtb-apexcharts',
				$assets_url . 'vendor/apexcharts.min.js',
				[],
				$apex_ver,
				true
			);
		}
	}

	// ── Localized config ──
	wp_localize_script(
		'dtb-admin',
		'dtbAdminConfig',
		[
			'restUrl'         => esc_url_raw( rest_url() ),
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'wp_rest' ),
			'adminUrl'        => admin_url( 'admin.php' ),
			'currentUserId'   => get_current_user_id(),
			'currentUserName' => $current_user->display_name,
			'currencySymbol'  => get_woocommerce_currency_symbol(),
			'siteName'        => get_bloginfo( 'name' ),
			'page'            => $page_meta,
			'capabilities'    => dtb_admin_assets_cap_map(),
			'featureFlags'    => dtb_admin_assets_feature_flags(),
		]
	);
}

/**
 * Add a stable body class while rendering DTB admin pages.
 *
 * The class scopes DTB-owned components only. WordPress and third-party admin
 * chrome remain owned by the active admin-theme plugin.
 *
 * @param string $classes Existing admin body class string.
 * @return string
 */
function dtb_admin_assets_body_class( string $classes ): string {
	$page_meta   = dtb_current_page_meta();
	$page_slug   = sanitize_key( (string) ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$is_dtb_slug = '' !== $page_slug && str_starts_with( $page_slug, 'dtb-' );

	if ( $page_meta || $is_dtb_slug ) {
		$classes .= ' dtb-admin-screen';
	}

	return $classes;
}

/**
 * Build a capability map for JavaScript UI toggle decisions.
 *
 * @return array<string, bool>
 */
function dtb_admin_assets_cap_map(): array {
	$caps = dtb_admin_all_capabilities();
	$map  = [];

	foreach ( $caps as $cap ) {
		$map[ $cap ] = current_user_can( $cap );
	}

	return $map;
}

/**
 * Expose feature flags to JavaScript.
 *
 * @return array<string, bool>
 */
function dtb_admin_assets_feature_flags(): array {
	return [
		'adminV2'        => (bool) dtb_feature_enabled( 'DTB_ADMIN_V2_ENABLED', true ),
		'commandCenter'  => (bool) dtb_feature_enabled( 'DTB_COMMAND_CENTER_ENABLED', true ),
		'systemManager'  => (bool) dtb_feature_enabled( 'DTB_SYSTEM_MANAGER_ENABLED', true ),
		'returns'        => (bool) dtb_feature_enabled( 'DTB_RETURNS_ENABLED', true ),
		'modernizedUi'   => (bool) dtb_feature_enabled( 'DTB_MODERNIZED_UI_ENABLED', true ),
	];
}
