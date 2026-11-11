<?php
/**
 * Validation — DeploymentActionValidator
 *
 * Input validation for operator-initiated deploy/rollback dispatch requests
 * from the Deployment Center admin UI, before they are forwarded to GitHub
 * as a workflow_dispatch call.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Validate a "deploy" dispatch request.
 *
 * @param string $ref     Branch, tag, or commit SHA to release.
 * @param string $confirm Must be the literal string 'DEPLOY'.
 * @return true|WP_Error
 */
function dtb_deployment_validate_deploy_request( string $ref, string $confirm ) {
	if ( 'DEPLOY' !== $confirm ) {
		return new WP_Error( 'dtb_deployment_confirm_required', __( 'Type DEPLOY to confirm a production release.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	$ref = trim( $ref );
	if ( '' === $ref || strlen( $ref ) > 200 || ! preg_match( '/^[A-Za-z0-9._\/-]+$/', $ref ) ) {
		return new WP_Error( 'dtb_deployment_invalid_ref', __( 'Enter a valid branch, tag, or commit SHA.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	if ( str_starts_with( $ref, '-' ) || str_contains( $ref, '..' ) ) {
		return new WP_Error( 'dtb_deployment_invalid_ref', __( 'That ref is not allowed.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	return true;
}

/**
 * Validate a "rollback" dispatch request.
 *
 * @param string $release_id Target release ID to restore.
 * @param string $confirm    Must be the literal string 'ROLLBACK'.
 * @return true|WP_Error
 */
function dtb_deployment_validate_rollback_request( string $release_id, string $confirm ) {
	if ( 'ROLLBACK' !== $confirm ) {
		return new WP_Error( 'dtb_deployment_confirm_required', __( 'Type ROLLBACK to confirm a production restore.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	if ( ! preg_match( '/^rel-[0-9]+-[0-9a-f]{7,40}$/', $release_id ) ) {
		return new WP_Error( 'dtb_deployment_invalid_release_id', __( 'Select a valid release from the deployment history.', 'drywall-toolbox' ), [ 'status' => 422 ] );
	}

	$release = dtb_release_history_get_one( $release_id );
	if ( null === $release ) {
		return new WP_Error( 'dtb_deployment_unknown_release', __( 'That release was not found in deployment history.', 'drywall-toolbox' ), [ 'status' => 404 ] );
	}

	if ( ! in_array( $release['status'], [ 'validated', 'backed_up', 'deploying', 'deployed', 'verified', 'completed' ], true ) ) {
		return new WP_Error(
			'dtb_deployment_release_not_restorable',
			__( 'That release never reached a validated manifest and cannot be restored.', 'drywall-toolbox' ),
			[ 'status' => 422 ]
		);
	}

	return true;
}
