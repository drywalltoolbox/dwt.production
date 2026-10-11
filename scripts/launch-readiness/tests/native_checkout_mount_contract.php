<?php
/** Behavioral regression harness for shared-runtime native checkout mounts. */
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'DTB_VERSION', 'test' );

$registered_filters = [];
$admin_request      = false;
$ajax_request       = false;
$_SERVER['REQUEST_URI'] = '/staging/order-pay/12/';

function add_action( ...$args ): void {}
function add_filter( string $hook, $callback, int $priority = 10, int $accepted_args = 1 ): void {
	global $registered_filters;
	$registered_filters[ $hook ] = [ $callback, $priority, $accepted_args ];
}
function is_admin(): bool { global $admin_request; return $admin_request; }
function wp_doing_ajax(): bool { global $ajax_request; return $ajax_request; }
function is_checkout(): bool { return false; }
function wp_unslash( string $value ): string { return stripslashes( $value ); }
function wp_parse_url( string $url, int $component ) { return parse_url( $url, $component ); }
function home_url( string $path ): string { return 'https://drywalltoolbox.com' . $path; }
function wc_get_cart_url(): string { return home_url( '/cart/?woo-owned=1' ); }
function get_template_directory(): string { return __DIR__ . '/missing-theme-assets'; }
function get_template_directory_uri(): string { return home_url( '/wp/theme' ); }
function wp_enqueue_style( ...$args ): void {}
function language_attributes(): void {}
function bloginfo( ...$args ): void {}
function wp_head(): void {}
function body_class(): void {}
function wp_body_open(): void {}
function wp_footer(): void {}
function have_posts(): bool { return false; }
function status_header( ...$args ): void {}
function esc_url( string $value ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html__( string $value, string $domain ): string { return htmlspecialchars( $value, ENT_QUOTES ); }
function esc_attr_e( string $value, string $domain ): void { echo htmlspecialchars( $value, ENT_QUOTES ); }
function esc_html_e( string $value, string $domain ): void { echo htmlspecialchars( $value, ENT_QUOTES ); }

$repository_root = dirname( __DIR__, 3 );
require_once $repository_root . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Payment/WooNativeCheckoutRuntime.php';

function expect_same( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new RuntimeException( $message );
	}
}

expect_same(
	[ [ DTB_WooNativeCheckoutRuntime::class, 'preserve_staging_checkout_canonical' ], 10, 2 ],
	$registered_filters['redirect_canonical'] ?? null,
	'The native runtime must register the scoped canonical filter.'
);
expect_same( true, defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE, 'Native staging payment aliases must bypass page caches at bootstrap.' );

$canonical_url = home_url( '/checkout/' );
$query         = '?key=wc_order_test&payment_intent=pi_test&redirect_status=succeeded';
$checkout_paths = [
	'/staging/checkout',
	'/staging/checkout/',
	'/staging/checkout/order-pay/12/',
	'/staging/checkout/order-received/12/',
	'/staging/order-pay/12/',
];

foreach ( $checkout_paths as $path ) {
	$_SERVER['REQUEST_URI'] = $path . $query;
	$_GET = [ 'key' => 'wc_order_test', 'payment_intent' => 'pi_test', 'redirect_status' => 'succeeded' ];
	$original_uri   = $_SERVER['REQUEST_URI'];
	$original_query = $_GET;
	expect_same( '/staging', DTB_WooNativeCheckoutRuntime::staging_checkout_base_path(), 'Existing native staging aliases must retain their mount.' );
	expect_same( false, DTB_WooNativeCheckoutRuntime::preserve_staging_checkout_canonical( $canonical_url, home_url( $path ) ), 'Canonical routing must not escape to production.' );
	expect_same( $original_uri, $_SERVER['REQUEST_URI'], 'The original callback URI must remain unchanged.' );
	expect_same( $original_query, $_GET, 'WooCommerce/provider query values must remain unchanged.' );
}

foreach ( [
	'/checkout/',
	'/checkout/order-pay/12/',
	'/staging/',
	'/staging/cart/',
	'/staging/checkout-extra/',
	'/staging/checkout/order-pay/not-an-id/',
	'/staging/checkout/order-pay/12/extra/',
	'/other/staging/checkout/',
	'/staging/../checkout/',
	'/staging/%63heckout/',
	'/staging/checkout%2forder-pay/12/',
	'//attacker.example/staging/checkout/',
	'https://attacker.example/staging/checkout/',
	'/checkout/?next=/staging/checkout/',
] as $path ) {
	$_SERVER['REQUEST_URI'] = $path;
	$_SERVER['HTTP_ORIGIN'] = 'https://drywalltoolbox.com/staging/checkout/';
	$_SERVER['HTTP_REFERER'] = home_url( '/staging/checkout/' );
	expect_same( '', DTB_WooNativeCheckoutRuntime::staging_checkout_base_path(), 'An unrelated or encoded path must not create a staging mount.' );
	expect_same( $canonical_url, DTB_WooNativeCheckoutRuntime::preserve_staging_checkout_canonical( $canonical_url, home_url( '/staging/checkout/' ) ), 'Proposed redirect/referrer must not broaden the canonical exception.' );
}

$_SERVER['REQUEST_URI'] = '/staging/checkout/';
$admin_request = true;
expect_same( $canonical_url, DTB_WooNativeCheckoutRuntime::preserve_staging_checkout_canonical( $canonical_url, '' ), 'Admin requests must retain normal canonical handling.' );
$admin_request = false;
$ajax_request = true;
expect_same( $canonical_url, DTB_WooNativeCheckoutRuntime::preserve_staging_checkout_canonical( $canonical_url, '' ), 'AJAX requests must retain normal canonical handling.' );
$ajax_request = false;

foreach ( [ '/staging/checkout/' => [ '/staging/', '/staging/cart/' ], '/checkout/' => [ '/', '/cart/?woo-owned=1' ] ] as $path => $expected_links ) {
	$_SERVER['REQUEST_URI'] = $path;
	ob_start();
	include $repository_root . '/drywalltoolbox/wp/wp-content/themes/drywall-toolbox/templates/checkout/native-checkout.php';
	$html = (string) ob_get_clean();
	foreach ( $expected_links as $link ) {
		if ( ! str_contains( $html, 'href="' . esc_url( home_url( $link ) ) . '"' ) ) {
			throw new RuntimeException( 'The native shell must preserve the staging links and WooCommerce production cart URL.' );
		}
	}
}

define( 'REST_REQUEST', true );
$_SERVER['REQUEST_URI'] = '/staging/checkout/';
expect_same( $canonical_url, DTB_WooNativeCheckoutRuntime::preserve_staging_checkout_canonical( $canonical_url, '' ), 'REST requests must retain normal canonical handling.' );

echo "native checkout mount contract passed\n";
