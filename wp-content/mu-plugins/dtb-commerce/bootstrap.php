<?php
/**
 * DTB Commerce bootstrap.
 *
 * Loads cart metadata, checkout validation, native WooCommerce checkout/runtime
 * support, WooPayments observation, shipping, and commerce-facing
 * REST/email integrations.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Cart/ToolsetCartItemData.php';
require_once __DIR__ . '/Orders/ToolsetOrderLineMeta.php';
$order_line_provenance_file = __DIR__ . '/Orders/OrderLineProvenance.php';
if ( file_exists( $order_line_provenance_file ) ) {
	require_once $order_line_provenance_file;
}
require_once __DIR__ . '/Services/OrderTypeService.php';
require_once __DIR__ . '/Services/OrderAdminQueryService.php';
require_once __DIR__ . '/Validation/CheckoutValidator.php';
require_once __DIR__ . '/Validation/SignupCouponPolicy.php';
require_once __DIR__ . '/Validation/CheckoutFieldPolicy.php';
require_once __DIR__ . '/Validation/CheckoutTaxReadiness.php';
require_once __DIR__ . '/Validation/CheckoutTaxPresentation.php';
require_once __DIR__ . '/Domain/PaymentState.php';
require_once __DIR__ . '/Payment/StoreApiSameOriginCartSession.php';
require_once __DIR__ . '/Payment/WooNativeCheckoutRuntime.php';
require_once __DIR__ . '/Payment/StorefrontReturnContext.php';
require_once __DIR__ . '/Payment/WooPaymentsNativeCheckout.php';
require_once __DIR__ . '/Payment/CheckoutPerformance.php';
require_once __DIR__ . '/Payment/CheckoutRuntimeIntegrity.php';
require_once __DIR__ . '/Shipping/DTBShippingMethod.php';
// Shared lifecycle email design must be available before template rendering.
require_once __DIR__ . '/Email/DesignV3.php';
require_once __DIR__ . '/Email/TemplateOverride.php';
require_once __DIR__ . '/Email/Preview/EmailPreviewService.php';

if ( is_admin() ) {
	require_once __DIR__ . '/Admin/OrdersPage.php';
}

require_once __DIR__ . '/Rest/OrderRestController.php';
$product_review_controller_file = __DIR__ . '/Rest/ProductReviewController.php';
if ( file_exists( $product_review_controller_file ) ) {
	require_once $product_review_controller_file;
}

DTB_ToolsetCartItemData::register();
DTB_ToolsetOrderLineMeta::register();
if ( class_exists( 'DTB_OrderLineProvenance' ) ) {
	DTB_OrderLineProvenance::register();
}
DTB_CheckoutFieldPolicy::register();
DTB_CheckoutTaxReadiness::register();
DTB_CheckoutTaxPresentation::register();
