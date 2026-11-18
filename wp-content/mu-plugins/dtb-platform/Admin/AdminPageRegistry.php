<?php
/**
 * DTB Admin — AdminPageRegistry
 *
 * Central registry for all DTB admin pages.
 * Modules register metadata; this registry owns the actual
 * add_menu_page / add_submenu_page calls via AdminMenuRegistry.
 *
 * Registration keys:
 *   library     (string) 'operations' | 'tools'
 *   slug        (string) Unique page slug.
 *   title       (string) Browser/page title.
 *   menu_title  (string) Menu label.
 *   capability  (string) Required capability.
 *   callback    (callable) Render function.
 *   position    (int) Submenu sort position.
 *   template    (string) 'dashboard' | 'queue' | 'tool' | 'settings'
 *   section     (string) Optional grouping label.
 *   icon        (string) Optional dashicon for top-level items only.
 *   menu_visible (bool) Whether to show this page in the left admin menu.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Global page registry storage.
 *
 * @var array<string, array>
 */
$GLOBALS['_dtb_page_registry'] = [];

/**
 * Register a DTB admin page.
 *
 * @param array $meta Page metadata.
 */
function dtb_register_admin_page( array $meta ): void {
	$slug = $meta['slug'] ?? '';

	if ( empty( $slug ) ) {
		_doing_it_wrong( 'dtb_register_admin_page', 'Page registration is missing a slug.', '2.0.0' );
		return;
	}

	if ( isset( $GLOBALS['_dtb_page_registry'][ $slug ] ) ) {
		error_log( "[DTB] AdminPageRegistry: duplicate slug '{$slug}' — second registration ignored." );
		return;
	}

	if ( ! isset( $meta['callback'] ) || ! is_callable( $meta['callback'] ) ) {
		error_log( "[DTB] AdminPageRegistry: slug '{$slug}' has no valid callback." );
		// Allow registration so the menu appears but shows a safe error.
		$meta['callback'] = static function () use ( $slug ): void {
			echo '<div class="wrap dtb-admin-page"><div class="dtb-admin"><div class="dtb-alert dtb-alert--danger">'
				. '<span class="dtb-alert__icon dashicons dashicons-warning"></span>'
				. '<div class="dtb-alert__body"><p class="dtb-alert__text">Page callback for <code>'
				. esc_html( $slug ) . '</code> is not registered.</p></div></div></div></div>';
		};
	}

	$GLOBALS['_dtb_page_registry'][ $slug ] = wp_parse_args( $meta, [
		'library'    => 'operations',
		'slug'       => $slug,
		'title'      => $slug,
		'menu_title' => $slug,
		'capability' => 'manage_options',
		'callback'   => '__return_false',
		'position'   => 50,
		'template'   => 'dashboard',
		'section'    => '',
		'icon'       => '',
		'menu_visible' => true,
	] );
}

/**
 * Get all registered pages, optionally filtered by library.
 *
 * @param string|null $library 'operations' | 'tools' | null (all)
 * @return array<string, array>
 */
function dtb_get_registered_pages( ?string $library = null ): array {
	$registry = $GLOBALS['_dtb_page_registry'] ?? [];

	if ( $library === null ) {
		return $registry;
	}

	return array_filter( $registry, fn( $p ) => ( $p['library'] ?? '' ) === $library );
}

/**
 * Get page metadata by slug.
 *
 * @param string $slug
 * @return array|null
 */
function dtb_get_page_meta( string $slug ): ?array {
	return $GLOBALS['_dtb_page_registry'][ $slug ] ?? null;
}

/**
 * Resolve the current DTB page's metadata based on the query string.
 *
 * @return array|null
 */
function dtb_current_page_meta(): ?array {
	if ( ! is_admin() ) {
		return null;
	}

	$page = sanitize_text_field( wp_unslash( $_GET['page'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

	if ( empty( $page ) ) {
		return null;
	}

	return dtb_get_page_meta( $page );
}

/**
 * Check whether the current screen is a DTB admin page.
 *
 * @return bool
 */
function dtb_is_dtb_admin_page(): bool {
	return dtb_current_page_meta() !== null;
}

/**
 * Return the pages registered for a library, sorted by position.
 *
 * @param string $library
 * @return array
 */
function dtb_get_sorted_pages( string $library ): array {
	$pages = dtb_get_registered_pages( $library );
	uasort( $pages, fn( $a, $b ) => ( $a['position'] ?? 50 ) <=> ( $b['position'] ?? 50 ) );
	return $pages;
}
