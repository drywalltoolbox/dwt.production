<?php
/**
 * Explicit WP-CLI commands for approved fitment projection and rollback.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'init', 'dtb_register_relationship_projection_cli', 100 );
}

function dtb_register_relationship_projection_cli(): void {
	if ( ! class_exists( 'WP_CLI' ) ) {
		return;
	}
	WP_CLI::add_command( 'dtb relationships project-fitment', [ DTB_CompatibilityProjectionCli::class, 'project' ] );
	WP_CLI::add_command( 'dtb relationships rollback-fitment', [ DTB_CompatibilityProjectionCli::class, 'rollback' ] );
	WP_CLI::add_command( 'dtb relationships release-status', [ DTB_CompatibilityProjectionCli::class, 'release_status' ] );
}

final class DTB_CompatibilityProjectionCli {
	/**
	 * Preview an exact compatibility projection. Writes require --apply.
	 *
	 * ## OPTIONS
	 *
	 * <artifact>
	 * : Path to compatibility-projection-artifact.json.
	 *
	 * --sha256=<digest>
	 * : SHA-256 of the reviewed artifact file.
	 *
	 * [--apply]
	 * : Apply the bounded update-existing projection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dtb relationships project-fitment /secure/release/projection.json --sha256=<digest>
	 *     wp dtb relationships project-fitment /secure/release/projection.json --sha256=<digest> --apply
	 */
	public static function project( array $args, array $assoc_args ): void {
		if ( count( $args ) !== 1 || empty( $assoc_args['sha256'] ) ) {
			WP_CLI::error( 'Provide exactly one artifact path and its reviewed --sha256 digest.' );
		}
		try {
			$result = DTB_ProductRelationshipProjectionService::run_artifact(
				(string) $args[0],
				(string) $assoc_args['sha256'],
				isset( $assoc_args['apply'] )
			);
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		if ( ! empty( $result['error'] ) ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Roll back a projection report after checking current values still match its after-state.
	 *
	 * ## OPTIONS
	 *
	 * <report>
	 * : Private rollback report path printed by a successful apply.
	 *
	 * --sha256=<digest>
	 * : SHA-256 of the current rollback report file.
	 */
	public static function rollback( array $args, array $assoc_args ): void {
		if ( count( $args ) !== 1 || empty( $assoc_args['sha256'] ) ) {
			WP_CLI::error( 'Provide exactly one rollback report path and its --sha256 digest.' );
		}
		try {
			$result = DTB_ProductRelationshipProjectionService::rollback_report( (string) $args[0], (string) $assoc_args['sha256'] );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Read the private terminal reconciliation state for a release.
	 *
	 * ## OPTIONS
	 *
	 * <release-id>
	 * : The 24-character release identity printed by the artifact builder.
	 */
	public static function release_status( array $args ): void {
		if ( count( $args ) !== 1 ) {
			WP_CLI::error( 'Provide exactly one release ID.' );
		}
		try {
			$result = DTB_ProductRelationshipProjectionService::read_release_status( (string) $args[0] );
		} catch ( Throwable $error ) {
			WP_CLI::error( $error->getMessage() );
		}
		WP_CLI::log( wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}
}

