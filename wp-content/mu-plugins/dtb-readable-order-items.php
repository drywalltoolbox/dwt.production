<?php
/**
 * Compatibility entrypoint. Load the DTB BrikPanel presentation module once.
 * Kept at the original path for safe incremental deployment and rollback.
 */
defined( 'ABSPATH' ) || exit;
require_once __DIR__ . '/dtb-brikpanel/bootstrap.php';
