<?php
/**
 * Compatibility entrypoint for critical legacy commerce route hardening.
 *
 * Canonical behavior lives in dtb-platform. Keeping this thin root delegator
 * ensures WordPress loads the security boundary even during recovery from a
 * partially converged module bootstrap.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/dtb-platform/Security/LegacyCommerceRouteHardening.php';
