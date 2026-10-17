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
$storefront_link = static function ( string $path ) use ( $storefront_base_path ): string {
	return home_url( $storefront_base_path . $path );
};
$storefront_nav_items = [
	[ 'Shop', '/all-products', [ [ 'All products', '/all-products' ], [ 'New arrivals', '/products?sort=newest' ] ] ],
	[ 'Brands', '/products/brands', [ [ 'Shop by brand', '/products/brands' ] ] ],
	[ 'Parts', '/parts', [ [ 'All parts', '/parts' ], [ 'Parts by brand', '/parts' ] ] ],
	[ 'Repair Services', '/repairs', [ [ 'Repair services', '/repairs' ], [ 'Start a repair', '/repairs/start' ] ] ],
	[ 'Schematics', '/schematics', [ [ 'Browse schematics', '/schematics' ] ] ],
];
$summary_style_path  = get_template_directory() . '/assets/checkout/checkout-summary.css';
$summary_style_ver   = file_exists( $summary_style_path ) ? (string) filemtime( $summary_style_path ) : DTB_VERSION;
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
	'dtb-checkout-desktop',
	get_template_directory_uri() . '/assets/checkout/checkout-desktop.css',
	[ 'dtb-checkout' ],
	$desktop_style_ver,
	'(min-width: 1024px)'
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
	<header class="dtb-checkout-storefront" aria-label="Storefront navigation">
	<div class="dtb-checkout-storefront__utility"><span aria-hidden="true">▱</span> <?php esc_html_e( 'Free shipping on orders $75+', 'drywall-toolbox' ); ?></div>
	<div class="dtb-checkout-storefront__main">
		<details class="dtb-checkout-storefront__mobile-menu">
			<summary aria-label="<?php esc_attr_e( 'Open storefront menu', 'drywall-toolbox' ); ?>"><span aria-hidden="true">☰</span><span class="screen-reader-text"><?php esc_html_e( 'Storefront menu', 'drywall-toolbox' ); ?></span></summary>
			<nav aria-label="<?php esc_attr_e( 'Mobile storefront navigation', 'drywall-toolbox' ); ?>">
				<?php foreach ( $storefront_nav_items as $nav_item ) : ?>
					<a href="<?php echo esc_url( $storefront_link( $nav_item[1] ) ); ?>"><?php echo esc_html( $nav_item[0] ); ?></a>
				<?php endforeach; ?>
			</nav>
		</details>
		<a class="dtb-checkout-storefront__logo" href="<?php echo esc_url( $storefront_link( '/' ) ); ?>" aria-label="<?php esc_attr_e( 'Drywall Toolbox home', 'drywall-toolbox' ); ?>"><img src="<?php echo esc_url( home_url( '/logos/logo-white.svg' ) ); ?>" width="3000" height="917" alt="<?php esc_attr_e( 'Drywall Toolbox', 'drywall-toolbox' ); ?>"></a>
		<form class="dtb-checkout-storefront__search" role="search" method="get" action="<?php echo esc_url( $storefront_link( '/products' ) ); ?>">
			<label for="dtb-checkout-search" class="screen-reader-text"><?php esc_html_e( 'Search products, brands, or SKU', 'drywall-toolbox' ); ?></label>
			<input id="dtb-checkout-search" type="search" name="search" placeholder="<?php esc_attr_e( 'Search products, brands, SKU...', 'drywall-toolbox' ); ?>" autocomplete="off">
			<button type="submit" aria-label="<?php esc_attr_e( 'Submit product search', 'drywall-toolbox' ); ?>">⌕</button>
		</form>
		<div class="dtb-checkout-storefront__actions">
			<a href="<?php echo esc_url( $storefront_link( '/account' ) ); ?>" aria-label="<?php esc_attr_e( 'My account', 'drywall-toolbox' ); ?>">♙</a>
			<a href="<?php echo esc_url( $storefront_cart_url ); ?>" aria-label="<?php esc_attr_e( 'View cart', 'drywall-toolbox' ); ?>">♧</a>
		</div>
	</div>
	<nav class="dtb-checkout-storefront__desktop-nav" aria-label="<?php esc_attr_e( 'Primary storefront navigation', 'drywall-toolbox' ); ?>">
		<?php foreach ( $storefront_nav_items as $nav_item ) : ?>
			<details><summary><?php echo esc_html( $nav_item[0] ); ?><span aria-hidden="true">⌄</span></summary><div class="dtb-checkout-storefront__submenu">
			<?php foreach ( $nav_item[2] as $nav_link ) : ?><a href="<?php echo esc_url( $storefront_link( $nav_link[1] ) ); ?>"><?php echo esc_html( $nav_link[0] ); ?></a><?php endforeach; ?>
			</div></details>
		<?php endforeach; ?>
	</nav>
</header>
	<header class="dtb-checkout__topbar">
		<div class="dtb-checkout__topbar-inner">
			<a class="dtb-checkout__back-link" href="<?php echo esc_url( $storefront_cart_url ); ?>" aria-label="<?php esc_attr_e( 'Back to cart', 'drywall-toolbox' ); ?>">
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m12 19-7-7 7-7"/><path d="M19 12H5"/></svg>
				<span><?php esc_html_e( 'Back to cart', 'drywall-toolbox' ); ?></span>
			</a>

			<span class="dtb-checkout__header-label"><svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 0a3 3 0 0 0-3 3v2H4a1 1 0 0 0-1 1v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V6a1 1 0 0 0-1-1h-1V3a3 3 0 0 0-3-3Zm0 1.5A1.5 1.5 0 0 1 9.5 3v2h-3V3A1.5 1.5 0 0 1 8 1.5Z"/></svg><span><?php esc_html_e( 'Secure checkout', 'drywall-toolbox' ); ?></span></span>
		</div>
	</header>
	<main id="primary" role="main">
		<div class="dtb-checkout__intro"><span class="dtb-checkout__eyebrow"><?php esc_html_e( 'Drywall Toolbox', 'drywall-toolbox' ); ?></span><h1><?php esc_html_e( 'Checkout', 'drywall-toolbox' ); ?></h1><p><?php esc_html_e( 'Your tools are almost on their way.', 'drywall-toolbox' ); ?></p></div>
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

