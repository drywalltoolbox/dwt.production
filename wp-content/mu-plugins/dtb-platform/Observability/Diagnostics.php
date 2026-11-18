<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_Diagnostics' ) ) {
	return;
}

final class DTB_Diagnostics {
	/**
	 * @return array<string,mixed>
	 */
	public static function snapshot(): array {
		return [
			'timestamp'      => gmdate( 'c' ),
			'php'            => PHP_VERSION,
			'wp'             => get_bloginfo( 'version' ),
			'site_url'       => site_url(),
			'home_url'       => home_url(),
			'rest_url'       => rest_url(),
			'environment'    => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'feature_flags'  => class_exists( 'DTB_FeatureFlags' ) ? DTB_FeatureFlags::all() : [],
			'dependencies'   => class_exists( 'DTB_DependencyHealthCheck' ) ? DTB_DependencyHealthCheck::run() : [],
		];
	}
}
