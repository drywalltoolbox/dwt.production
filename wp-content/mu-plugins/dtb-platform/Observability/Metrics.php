<?php
defined( 'ABSPATH' ) || exit;

/**
 * DTB Admin Performance helpers (must-use).
 *
 * Admin performance policy must not deregister WordPress or plugin script
 * handles. WooCommerce Admin, WooPayments, WordPress core, and host plugins
 * share dependency handles (notably `heartbeat`). Removing a registered
 * dependency can prevent dependent scripts from being printed and leave
 * React-powered admin screens stuck in loading/skeleton states.
 *
 * This module therefore limits itself to non-destructive tuning and request
 * timing observability.
 *
 * @package drywall-toolbox
 */

// Quick disable for development or incident isolation.
if ( defined( 'DTB_ADMIN_PERF_DISABLE' ) && DTB_ADMIN_PERF_DISABLE ) {
	return;
}

/**
 * Reduce Heartbeat frequency without removing the registered script handle.
 *
 * Keeping the handle registered preserves the WordPress dependency graph for
 * wp-auth-check, WooCommerce/WooPayments admin bundles, and third-party admin
 * integrations that declare heartbeat as a dependency.
 */
add_filter(
	'heartbeat_settings',
	static function ( array $settings ): array {
		if ( is_admin() ) {
			$settings['interval'] = 60;
		}

		return $settings;
	}
);

/**
 * Preserve the heartbeat payload unchanged.
 *
 * This hook remains as an explicit extension point for future low-risk metrics
 * work without changing heartbeat availability or response semantics.
 */
add_filter(
	'heartbeat_send',
	static function ( $response ) {
		return $response;
	},
	10,
	1
);

/**
 * Add request timing to REST responses when the request start marker exists.
 */
add_action(
	'rest_api_init',
	static function (): void {
		add_filter(
			'rest_post_dispatch',
			static function ( $result ) {
				if ( defined( 'DTB_REQUEST_START_MS' ) ) {
					$took = (int) round( ( microtime( true ) - DTB_REQUEST_START_MS ) * 1000 );

					if ( is_array( $result ) && isset( $result['headers'] ) && is_array( $result['headers'] ) ) {
						$result['headers']['X-DTB-Took-ms'] = $took;
					}
				}

				return $result;
			},
			10,
			1
		);
	}
);

// Record request start time early in the request lifecycle.
if ( ! defined( 'DTB_REQUEST_START_MS' ) ) {
	define( 'DTB_REQUEST_START_MS', microtime( true ) );
}
