<?php
/**
 * Admin-only native WooCommerce product-page shortcode, staging diagnostic.
 * WooPayments owns button rendering/payment processing. No custom payment code.
 */
defined( 'ABSPATH' ) || exit;
if ( ! function_exists( 'dtb_staging_wallet_probe_authorized' ) || ! dtb_staging_wallet_probe_authorized() ) {
    status_header( 404 );
    exit;
}
$product = wc_get_product( 18271 );
if ( ! $product || ! $product->is_purchasable() ) {
    status_header( 404 );
    exit;
}
nocache_headers();
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Staging wallet probe — Drywall Toolbox</title>
<style>
body{margin:0;background:#f7f8fa;color:#202936;font:16px/1.5 system-ui,sans-serif}
main{max-width:1000px;margin:32px auto;padding:20px}
.notice{padding:16px 20px;margin-bottom:20px;border:1px solid #b9c8d8;background:#fff;border-radius:10px}
.woocommerce div.product{background:#fff;padding:24px;border:1px solid #e1e5eb;border-radius:12px}
.woocommerce div.product form.cart{display:block}
</style>
<?php wp_head(); ?>
</head>
<body <?php body_class( 'dtb-wallet-probe' ); ?>>
<main>
<div class="notice" role="status"><strong>Administrator-only staging diagnostic — WooPayments test mode.</strong><br>
The product form and wallet controls below are owned by WooCommerce and its provider plugins.
Do not submit a completed purchase here: the shared WordPress backend is not an isolated sandbox.</div>
<?php
// Crucially the queried WordPress page contains the product_page shortcode.
// WooPayments 11.2.0 resolves that singular query's post_content before asset
// registration; this is not a reimplementation of its private JS bootstrap.
while ( have_posts() ) {
    the_post();
    echo do_shortcode( get_the_content() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
