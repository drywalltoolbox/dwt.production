<?php
/**
 * Drywall Toolbox native WooCommerce Checkout Block document.
 *
 * WooCommerce owns checkout rendering and order creation. WooPayments owns
 * provider surfaces and payment execution. DTB owns only this
 * document shell and progressive presentation assets.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

$storefront_base_path = is_callable( [ 'DTB_WooNativeCheckoutRuntime', 'staging_checkout_base_path' ] )
	? DTB_WooNativeCheckoutRuntime::staging_checkout_base_path()
	: '';
$storefront_cart_url = '' !== $storefront_base_path
	? home_url( $storefront_base_path . '/cart/' )
	: wc_get_cart_url();
$summary_style_path  = get_template_directory() . '/assets/checkout/checkout-summary.css';
$summary_style_ver   = file_exists( $summary_style_path ) ? (string) filemtime( $summary_style_path ) : DTB_VERSION;
$mobile_refined_path = get_template_directory() . '/assets/checkout/checkout-mobile-refined.css';
$mobile_refined_ver  = file_exists( $mobile_refined_path ) ? (string) filemtime( $mobile_refined_path ) : DTB_VERSION;
$sheet_script_path  = get_template_directory() . '/assets/checkout/checkout-mobile-sheet.js';
$sheet_script_ver   = file_exists( $sheet_script_path ) ? (string) filemtime( $sheet_script_path ) : DTB_VERSION;
$desktop_style_path  = get_template_directory() . '/assets/checkout/checkout-desktop.css';
$desktop_style_ver   = file_exists( $desktop_style_path ) ? (string) filemtime( $desktop_style_path ) : DTB_VERSION;

wp_enqueue_style(
	'dtb-checkout-summary',
	get_template_directory_uri() . '/assets/checkout/checkout-summary.css',
	[ 'dtb-checkout' ],
	$summary_style_ver,
	'(max-width: 1023px)'
);

wp_enqueue_style(
	'dtb-checkout-mobile-refined',
	get_template_directory_uri() . '/assets/checkout/checkout-mobile-refined.css',
	[ 'dtb-checkout-summary' ],
	$mobile_refined_ver,
	'(max-width: 767px)'
);

wp_enqueue_style(
	'dtb-checkout-desktop',
	get_template_directory_uri() . '/assets/checkout/checkout-desktop.css',
	[ 'dtb-checkout' ],
	$desktop_style_ver,
	'(min-width: 1024px)'
);
wp_enqueue_script(
	'dtb-checkout-mobile-sheet',
	get_template_directory_uri() . '/assets/checkout/checkout-mobile-sheet.js',
	[],
	$sheet_script_ver,
	true
);
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<meta name="robots" content="noindex,nofollow,noarchive">
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700;800&display=swap">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
	<header class="dtb-checkout__topbar">
		<div class="dtb-checkout__topbar-inner">
			<a class="dtb-checkout__back-link" href="<?php echo esc_url( $storefront_cart_url ); ?>" aria-label="<?php esc_attr_e( 'Back to cart', 'drywall-toolbox' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
				<span><?php esc_html_e( 'Back to cart', 'drywall-toolbox' ); ?></span>
			</a>
			<div class="dtb-checkout__brand">
				<img src="<?php echo esc_url( home_url( '/logos/logo-white.svg' ) ); ?>" alt="<?php esc_attr_e( 'Drywall Toolbox', 'drywall-toolbox' ); ?>" width="3000" height="917">
			</div>
			<span class="dtb-checkout__header-label"><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 0a3 3 0 0 0-3 3v2H4a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-1V3a3 3 0 0 0-3-3Zm0 1.5A1.5 1.5 0 0 1 9.5 3v2h-3V3A1.5 1.5 0 0 1 8 1.5Z"/></svg><span><?php esc_html_e( 'Secure checkout', 'drywall-toolbox' ); ?></span></span>
		</div>
	</header>
	<div class="dtb-mobile-sheet-backdrop" aria-hidden="true"></div>
	<button type="button" class="dtb-mobile-sheet-trigger" aria-controls="dtb-mobile-summary" aria-expanded="false" hidden>
		<span class="dtb-mobile-sheet-trigger__label"><?php esc_html_e( 'View order summary', 'drywall-toolbox' ); ?></span>
		<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 15 6-6 6 6"/></svg>
	</button>
	<main id="primary" role="main">
		<div class="dtb-checkout__intro"><span class="dtb-checkout__eyebrow"><?php esc_html_e( 'Drywall Toolbox', 'drywall-toolbox' ); ?></span><h1><?php esc_html_e( 'Complete your order', 'drywall-toolbox' ); ?></h1><p><?php esc_html_e( 'Your tools are almost on their way.', 'drywall-toolbox' ); ?></p></div>
		<?php
		if ( have_posts() ) {
			while ( have_posts() ) {
				the_post();
				the_content();
			}
		} else {
			status_header( 404 );
			echo '<div class="woocommerce-error" role="alert">' . esc_html__( 'Checkout is temporarily unavailable. Please return to your cart and try again.', 'drywall-toolbox' ) . '</div>';
		}
		?>
	</main>
<?php wp_footer(); ?>
</body>
</html>

