<?php
/**
 * Services — ProtectedPathPolicy
 *
 * Read-only, display-only mirror of the release boundary declared in
 * scripts/deployment/protected-paths.json.
 *
 * That JSON file is CI-only tooling — it lives in the repository root and is
 * never part of the deployed /wp payload, so production PHP cannot read it
 * from disk. The lists below MUST be kept identical to
 * scripts/deployment/protected-paths.json; the actual write-boundary
 * enforcement always happens in scripts/deployment/siteground-git-release.sh
 * during the GitHub Actions run — this file only powers the Deployment
 * Center's policy display and never performs any file operation itself.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Paths (relative to the SiteGround Git repository root / production /wp)
 * this system is allowed to write.
 *
 * @return string[]
 */
function dtb_deployment_owned_paths(): array {
	return [
		'.htaccess',
		'index.php',
		'wp-content/mu-plugins',
		'wp-content/themes',
	];
}

/**
 * Paths this system must never write, regardless of payload contents —
 * enforced by scripts/deployment/siteground-git-release.sh refusing any
 * release whose diff falls outside dtb_deployment_owned_paths().
 *
 * @return string[]
 */
function dtb_deployment_protected_paths(): array {
	return [
		'wp-config.php',
		'sgs_encrypt_key.php',
		'php_errorlog',
		'php.ini',
		'wp-admin',
		'wp-includes',
		'wp-content/plugins',
		'wp-content/uploads',
		'wp-content/cache',
		'wp-content/upgrade',
		'wp-content/backup',
		'wp-content/backups',
	];
}
