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
 * BrikPanel-backed order editor presentation; preserves native WooCommerce
 * order fields, postboxes, tabs, and save lifecycle.
 */
if ( is_admin() && function_exists( 'dtb_feature_enabled' )
	&& dtb_feature_enabled( 'DTB_ORDER_EDIT_WORKSPACE_V2', true ) ) {
	require_once __DIR__ . '/Orders/OrderEditExperience.php';
}

/**
 * Native WooCommerce order-preview layout; uses the native preview payload
 * and modal lifecycle.
 */
if ( is_admin() && function_exists( 'dtb_feature_enabled' )
	&& dtb_feature_enabled( 'DTB_ORDER_QUICK_VIEW_V2', true ) ) {
	require_once __DIR__ . '/Orders/QuickViewExperience.php';
}

/**
 * Product Media Studio can be disabled with DTB_BRIKPANEL_PRODUCT_MEDIA_STUDIO=false.
 * Enabled for prelaunch UI verification after current BrikPanel contract inspection.
 * Previously opt-in until the current BrikPanel runtime,
 * variations/save transaction, accessibility and regression tests pass.
 * No production editor behavior changes with this default-off flag.
 */
if ( is_admin() && function_exists( 'dtb_feature_enabled' )
    && dtb_feature_enabled( 'DTB_BRIKPANEL_PRODUCT_MEDIA_STUDIO', true ) ) {
    require_once __DIR__ . '/Products/ProductMediaStudio.php';
}
