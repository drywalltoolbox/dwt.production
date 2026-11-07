<?php
/**
 * DTB wp-admin asset ownership policy.
 *
 * BrikPanel owns global wp-admin chrome. DTB owns a BrikPanel-compatible,
 * page-scoped component layer for registered DTB screens. This policy prevents
 * broad stylesheet removal, preserves declared module assets, loads the LTR/RTL
 * component adapters deterministically, and emits redacted diagnostics.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

remove_action( 'admin_enqueue_scripts', 'dtb_admin_remove_custom_styles', PHP_INT_MAX );
add_action( 'admin_enqueue_scripts', 'dtb_admin_asset_policy_enforce', PHP_INT_MAX );
add_filter( 'admin_body_class', 'dtb_admin_asset_policy_body_class' );

/** Return whether the current request is a registered or migrated DTB screen. */
function dtb_admin_asset_policy_is_dtb_screen(): bool {
	$page_slug = sanitize_key( (string) ( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	return (bool) dtb_current_page_meta() || ( '' !== $page_slug && str_starts_with( $page_slug, 'dtb-' ) );
}

/**
 * Exact obsolete global-theme handles that may be removed safely.
 *
 * @return string[]
 */
function dtb_admin_asset_policy_obsolete_style_handles(): array {
	$handles = [
		'dtb-admin-legacy-theme',
		'dtb-admin-v1-theme',
		'dtb-admin-global-shell',
	];

	return array_values(
		array_unique(
			array_filter(
				array_map(
					'sanitize_key',
					(array) apply_filters( 'dtb_admin_obsolete_style_handles', $handles )
				)
			)
		)
	);
}

/**
 * Resolve declared module style handles from page metadata.
 *
 * @param array<string,mixed>|null $page_meta Current page metadata.
 * @return string[]
 */
function dtb_admin_asset_policy_declared_styles( ?array $page_meta ): array {
	$handles = [
		'dtb-admin',
		'dtb-admin-workbench',
		'dtb-admin-workbench-actions',
		'dtb-admin-bulk-actions',
		'dtb-admin-modern-polish',
	];

	if ( is_array( $page_meta ) ) {
		foreach ( (array) ( $page_meta['assets']['css'] ?? [] ) as $asset ) {
			$handle = sanitize_key( (string) ( $asset['id'] ?? '' ) );
			if ( '' !== $handle ) {
				$handles[] = $handle;
			}
		}
	}

	return array_values( array_unique( array_filter( $handles ) ) );
}

/**
 * Enqueue a local policy stylesheet with deterministic cache versioning.
 *
 * @param string   $handle       Style handle.
 * @param string   $filename     Filename under Admin/assets.
 * @param string[] $dependencies Registered dependencies.
 * @return bool Whether the style was enqueued.
 */
function dtb_admin_asset_policy_enqueue_local_style( string $handle, string $filename, array $dependencies ): bool {
	$file = __DIR__ . '/assets/' . $filename;
	if ( ! is_readable( $file ) ) {
		return false;
	}

	wp_enqueue_style(
		$handle,
		plugin_dir_url( __FILE__ ) . 'assets/' . $filename,
		array_values( array_filter( $dependencies, static fn( string $dependency ): bool => wp_style_is( $dependency, 'registered' ) ) ),
		(string) filemtime( $file )
	);

	return true;
}

/** Enforce the BrikPanel → DTB shared components → module components cascade. */
function dtb_admin_asset_policy_enforce(): void {
	if ( ! dtb_admin_asset_policy_is_dtb_screen() ) {
		return;
	}

	foreach ( dtb_admin_asset_policy_obsolete_style_handles() as $handle ) {
		if ( wp_style_is( $handle, 'enqueued' ) || wp_style_is( $handle, 'registered' ) ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
	}

	$page_meta       = dtb_current_page_meta();
	$declared_styles = dtb_admin_asset_policy_declared_styles( is_array( $page_meta ) ? $page_meta : null );
	$missing         = [];
	$active_styles   = [];

	foreach ( $declared_styles as $handle ) {
		if ( wp_style_is( $handle, 'registered' ) && ! wp_style_is( $handle, 'enqueued' ) ) {
			wp_enqueue_style( $handle );
		}

		if ( wp_style_is( $handle, 'enqueued' ) ) {
			$active_styles[] = $handle;
		} elseif ( 'dtb-admin' === $handle ) {
			$missing[] = $handle;
		}
	}

	$bridge_loaded = dtb_admin_asset_policy_enqueue_local_style(
		'dtb-brikpanel-components',
		'dtb-brikpanel-components.css',
		$active_styles
	);
	if ( ! $bridge_loaded ) {
		$missing[] = 'dtb-brikpanel-components';
	}

	$rtl_loaded = false;
	if ( is_rtl() ) {
		$rtl_loaded = dtb_admin_asset_policy_enqueue_local_style(
			'dtb-brikpanel-components-rtl',
			'dtb-brikpanel-components-rtl.css',
			$bridge_loaded ? [ 'dtb-brikpanel-components' ] : $active_styles
		);
		if ( ! $rtl_loaded ) {
			$missing[] = 'dtb-brikpanel-components-rtl';
		}
	}

	$GLOBALS['dtb_admin_asset_diagnostics'] = [
		'page'          => sanitize_key( (string) ( $page_meta['slug'] ?? ( $_GET['page'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		'declared'      => $declared_styles,
		'enqueued'      => array_values( array_unique( $active_styles ) ),
		'missing'       => array_values( array_unique( $missing ) ),
		'bridge_loaded' => $bridge_loaded,
		'rtl'           => [
			'active' => is_rtl(),
			'loaded' => $rtl_loaded,
		],
	];

	if ( wp_script_is( 'dtb-admin', 'enqueued' ) ) {
		wp_add_inline_script(
			'dtb-admin',
			'window.dtbAdminAssetDiagnostics=' . wp_json_encode( $GLOBALS['dtb_admin_asset_diagnostics'] ) . ';',
			'before'
		);
	}

	if ( $missing && class_exists( 'DTB_Logger' ) ) {
		DTB_Logger::warning(
			'DTB admin asset declaration is incomplete.',
			[
				'page'            => $GLOBALS['dtb_admin_asset_diagnostics']['page'],
				'missing_handles' => $GLOBALS['dtb_admin_asset_diagnostics']['missing'],
			]
		);
	}
}

/** Add stable ownership classes without styling global BrikPanel chrome. */
function dtb_admin_asset_policy_body_class( string $classes ): string {
	if ( dtb_admin_asset_policy_is_dtb_screen() ) {
		$classes .= ' dtb-brikpanel-components';
	}

	return $classes;
}
