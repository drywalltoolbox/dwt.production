<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_DependencyHealthCheck' ) ) {
	return;
}

final class DTB_DependencyHealthCheck {
	/**
	 * @return array<string,mixed>
	 */
	public static function run(): array {
		$checks = [
			'wp_rest'      => function_exists( 'rest_url' ),
			'woocommerce'  => class_exists( 'WooCommerce' ),
			'curl'         => function_exists( 'wp_remote_get' ),
			'json'         => function_exists( 'wp_json_encode' ),
			'mbstring'     => extension_loaded( 'mbstring' ),
			'openssl'      => extension_loaded( 'openssl' ),
		];

		$failed = [];
		foreach ( $checks as $name => $ok ) {
			if ( ! $ok ) {
				$failed[] = $name;
			}
		}

		return [
			'ok'      => empty( $failed ),
			'checks'  => $checks,
			'failed'  => $failed,
			'checked' => gmdate( 'c' ),
		];
	}
}

DTB_HealthRegistry::register( 'dependencies', [ DTB_DependencyHealthCheck::class, 'run' ] );
