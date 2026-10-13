<?php
/**
 * DTB-owned BrikPanel admin presentation extensions.
 * BrikPanel remains the screen/layout authority; WooCommerce owns records.
 * Module files may be installed independently of BrikPanel, but only render
 * on the corresponding WordPress admin screens.
 */
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Orders/ReadableOrderItems.php';

/**
 * Product Media Studio can be disabled with DTB_BRIKPANEL_PRODUCT_MEDIA_STUDIO=false.\n * Enabled for prelaunch UI verification after current BrikPanel contract inspection.\n * Previously opt-in until the current BrikPanel runtime,
 * variations/save transaction, accessibility and regression tests pass.
 * No production editor behavior changes with this default-off flag.
 */
if ( is_admin() && function_exists( 'dtb_feature_enabled' )
    && dtb_feature_enabled( 'DTB_BRIKPANEL_PRODUCT_MEDIA_STUDIO', true ) ) {
    require_once __DIR__ . '/Products/ProductMediaStudio.php';
}
