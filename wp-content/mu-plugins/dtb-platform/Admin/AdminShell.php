<?php
/**
 * DTB Admin — AdminShell
 *
 * Owns the opening and closing of the DTB admin page shell.
 * Every DTB admin page should call:
 *
 *   dtb_admin_shell_open( $args )
 *   // ... page content ...
 *   dtb_admin_shell_close()
 *
 * Also renders the shared drawer overlay and toast container.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Open the DTB admin page shell.
 *
 * @param array $args {
 *   @type string $title      Page title (shown in header).
 *   @type string $subtitle   Optional subtitle/description.
 *   @type string $section    Library section: 'operations' | 'tools'.
 *   @type string $page       Page slug.
 *   @type string $template   Template: 'dashboard' | 'queue' | 'tool' | 'settings'.
 *   @type array  $actions    Array of action HTML strings for the page header right area.
 *   @type array  $tabs       Optional tab definitions for section nav.
 *                             Each entry: [ 'id' => '', 'label' => '', 'active' => bool, 'url' => '' ]
 *   @type string $icon       Optional dashicon class (e.g. 'dashicons-hammer').
 * }
 */
function dtb_admin_shell_open( array $args ): void {
	$args = wp_parse_args( $args, [
		'title'       => '',
		'subtitle'    => '',
		'section'     => 'operations',
		'page'        => '',
		'template'    => 'dashboard',
		'actions'     => [],
		'tabs'        => [],
		'icon'        => '',
		'live_target' => '', // ID of [data-dtb-live-region] that shell tabs should navigate
	] );

	echo '<div class="wrap dtb-admin-page">';
	echo '<div class="dtb-admin" '
		. 'data-dtb-section="' . esc_attr( $args['section'] ) . '" '
		. 'data-dtb-page="'    . esc_attr( $args['page'] ) . '" '
		. 'data-dtb-template="' . esc_attr( $args['template'] ) . '">';

	// Drawer overlay (shared, hidden by default).
	echo '<div class="dtb-drawer-overlay" aria-hidden="true"></div>';

	// Toast container.
	echo '<div class="dtb-toast-container" role="region" aria-label="Notifications" aria-live="polite"></div>';

	echo '<div class="dtb-admin-frame">';
	echo '<div class="dtb-admin-frame__inner">';

	// Page header.
	dtb_admin_shell_render_header( $args );

	echo '<main class="dtb-page-body">';
	echo '<div class="dtb-page-body__inner">';
}

/**
 * Render the page header section.
 *
 * @param array $args Same as dtb_admin_shell_open args.
 */
function dtb_admin_shell_render_header( array $args ): void {
	echo '<header class="dtb-page-header">';
	echo '<div class="dtb-page-header__left">';

	// Title.
	echo '<h1 class="dtb-page-title">';
	if ( ! empty( $args['icon'] ) ) {
		echo '<span class="dashicons ' . esc_attr( $args['icon'] ) . '" aria-hidden="true"></span>';
	}
	echo esc_html( $args['title'] );
	echo '</h1>';

	// Subtitle.
	if ( ! empty( $args['subtitle'] ) ) {
		echo '<p class="dtb-page-subtitle">' . esc_html( $args['subtitle'] ) . '</p>';
	}

	echo '</div>'; // .__left

	// Actions.
	if ( ! empty( $args['actions'] ) ) {
		echo '<div class="dtb-page-header__right"><div class="dtb-page-actions">';
		foreach ( $args['actions'] as $action_html ) {
			echo $action_html; // Already escaped by callers using dtb_admin_ui_*
		}
		echo '</div></div>';
	}

	echo '</header>'; // .dtb-page-header

	// Section nav / tabs.
	if ( ! empty( $args['tabs'] ) ) {
		dtb_admin_shell_render_tabs( $args['tabs'], $args['live_target'] );
	}
}

/**
 * Render section navigation tabs.
 *
 * @param array $tabs Each: [ 'id' => string, 'label' => string, 'active' => bool, 'url' => string ]
 */
function dtb_admin_shell_render_tabs( array $tabs, string $live_target = '' ): void {
	echo '<nav class="dtb-section-nav" aria-label="Page sections">';
	foreach ( $tabs as $tab ) {
		$active_class = ! empty( $tab['active'] ) ? ' dtb-section-nav__tab--active' : '';
		$href         = $tab['url'] ?? '#';
		$is_page_link = ! empty( $tab['url'] ) && '#' !== $tab['url'];
		$data_tab     = ! $is_page_link && ! empty( $tab['id'] ) ? ' data-dtb-tab="' . esc_attr( $tab['id'] ) . '"' : '';
		$aria_sel     = ! empty( $tab['active'] ) ? 'true' : 'false';
		$role         = empty( $tab['url'] ) || $tab['url'] === '#' ? ' role="tab"' : '';
		// Live navigation attributes — emitted only when a live_target region ID is provided.
		$live_attrs = '';
		if ( $live_target && isset( $tab['id'] ) ) {
			// Include 'all' tabs — the JS normalises 'all' → empty status.
			$tab_id = (string) $tab['id'];
			if ( '' !== $tab_id || 'all' === strtolower( $tab['label'] ?? '' ) ) {
				$emit_id    = ( '' === $tab_id ) ? 'all' : $tab_id;
				$live_attrs = ' data-dtb-live-tab="' . esc_attr( $emit_id ) . '"'
							. ' data-dtb-live-target="' . esc_attr( $live_target ) . '"';
			}
		}

		printf(
			'<a href="%s" class="dtb-section-nav__tab%s" aria-selected="%s"%s%s%s>%s</a>',
			esc_url( $href ),
			esc_attr( $active_class ),
			esc_attr( $aria_sel ),
			$data_tab,
			$role,
			$live_attrs,
			esc_html( $tab['label'] ?? '' )
		);
	}
	echo '</nav>';
}

/**
 * Close the DTB admin page shell.
 */
function dtb_admin_shell_close(): void {
	echo '</div>';  // .dtb-page-body__inner
	echo '</main>'; // .dtb-page-body
	echo '</div>';  // .dtb-admin-frame__inner
	echo '</div>';  // .dtb-admin-frame
	echo '</div>';  // .dtb-admin
	echo '</div>';  // .wrap.dtb-admin-page
}

/**
 * Render a full DTB page access-denied screen.
 */
function dtb_admin_shell_access_denied(): void {
	dtb_admin_shell_open( [
		'title'   => __( 'Access Denied', 'drywall-toolbox' ),
		'section' => 'operations',
		'icon'    => 'dashicons-lock',
	] );

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo dtb_admin_ui_alert(
		__( 'You do not have permission to view this page.', 'drywall-toolbox' ),
		'danger',
		__( 'Access Denied', 'drywall-toolbox' )
	);

	dtb_admin_shell_close();
}

/**
 * Open a live region workspace container.
 *
 * Outputs the opening div for a [data-dtb-live-region] block used by the
 * Live Interaction Layer (DtbAdmin.initLiveRegions / liveNavigate).
 *
 * @param array{
 *   id:       string,
 *   module:   string,
 *   endpoint: string,
 *   interval: int,
 *   class:    string,
 * } $args
 */
function dtb_admin_shell_live_region_open( array $args ): void {
	$defaults = [
		'id'       => '',
		'module'   => '',
		'endpoint' => '',
		'interval' => 0,
		'class'    => '',
	];
	$a = wp_parse_args( $args, $defaults );

	$id_attr       = $a['id']       ? ' id="' . esc_attr( $a['id'] ) . '"' : '';
	$module_attr   = $a['module']   ? ' data-dtb-live-module="' . esc_attr( $a['module'] ) . '"' : '';
	$endpoint_attr = $a['endpoint'] ? ' data-dtb-endpoint="' . esc_url( $a['endpoint'] ) . '"' : '';
	$interval_attr = $a['interval'] ? ' data-dtb-refresh-interval="' . (int) $a['interval'] . '"' : '';
	$class_extra   = $a['class']    ? ' ' . esc_attr( $a['class'] ) : '';
	$live_id       = $a['id']       ? esc_attr( $a['id'] ) : ( $a['module'] ? esc_attr( $a['module'] ) . '-region' : '' );

	printf(
		'<div class="dtb-live-region%s" data-dtb-live-region="%s" aria-live="polite" aria-atomic="false"%s%s%s%s>',
		esc_attr( $class_extra ),
		esc_attr( $live_id ),
		$id_attr,
		$module_attr,
		$endpoint_attr,
		$interval_attr
	);
}

/**
 * Close a live region workspace container.
 */
function dtb_admin_shell_live_region_close(): void {
	echo '</div><!-- /.dtb-live-region -->';
}
