<?php
/**
 * Runtime guard for stale WordPress theme options.
 *
 * WordPress stores the active theme stylesheet/template slugs in options. If
 * those options point at a theme directory that has been removed from the
 * deployed application tree, WordPress aborts before WooCommerce checkout can
 * render. DTB's tracked WordPress application includes the canonical
 * `drywall-toolbox` theme for the native WooCommerce checkout shell.
 *
 * This guard is deliberately non-mutating: it only substitutes the canonical
 * DTB theme for the current request when the configured theme directory is
 * missing and the canonical theme is actually present. Valid installed themes
 * remain untouched, and no database option is silently rewritten.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_CANONICAL_THEME_SLUG = 'drywall-toolbox';

/**
 * Determine whether a theme slug resolves to an installed theme directory.
 */
function dtb_deployment_theme_exists( string $slug ): bool {
	$slug = trim( $slug );
	if ( '' === $slug || 1 !== preg_match( '/^[A-Za-z0-9._-]+$/', $slug ) ) {
		return false;
	}

	$themes_root = wp_normalize_path( WP_CONTENT_DIR . '/themes' );
	$theme_path  = realpath( $themes_root . '/' . $slug );
	if ( false === $theme_path || ! is_dir( $theme_path ) ) {
		return false;
	}

	$theme_path = wp_normalize_path( $theme_path );
	if ( 0 !== strpos( $theme_path . '/', trailingslashit( $themes_root ) ) ) {
		return false;
	}

	return is_file( $theme_path . '/style.css' );
}

/**
 * Fall back to the canonical DTB theme only when the configured theme is gone.
 */
function dtb_deployment_guard_theme_option( $configured ) {
	$configured = is_string( $configured ) ? trim( $configured ) : '';
	if ( dtb_deployment_theme_exists( $configured ) ) {
		return $configured;
	}

	if ( dtb_deployment_theme_exists( DTB_CANONICAL_THEME_SLUG ) ) {
		return DTB_CANONICAL_THEME_SLUG;
	}

	return $configured;
}

add_filter( 'option_template', 'dtb_deployment_guard_theme_option', PHP_INT_MAX );
add_filter( 'option_stylesheet', 'dtb_deployment_guard_theme_option', PHP_INT_MAX );
