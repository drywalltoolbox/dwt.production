<?php
/**
 * Plugin Name: DTB Public Label Normalization
 * Description: Normalizes internal checkout/shipping/payment identifiers before customer-facing output.
 * Version: 1.0.0
 * Author: Drywall Toolbox
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/dtb-platform/Support/PublicLabels.php';
