<?php
/**
 * DTB Catalog Platform — Pricing Manager service compatibility entry point.
 *
 * The active implementation lives in PricingManagerEngine.php. This stable
 * filename remains the composition-root contract for existing module loading
 * and documentation references.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/PricingManagerEngine.php';
