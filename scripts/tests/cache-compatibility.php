<?php
/**
 * Standalone smoke tests: php scripts/tests/cache-compatibility.php
 * No WordPress bootstrap, database, provider calls, or live mutations.
 */
define( 'ABSPATH', __DIR__ );
$GLOBALS['dtb_test_options'] = [];
$GLOBALS['dtb_test_transients'] = [];
function get_option( $key, $default = false ) { return $GLOBALS['dtb_test_options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['dtb_test_options'][$key] = $value; return true; }
function get_transient( $key ) { return $GLOBALS['dtb_test_transients'][$key] ?? false; }
function set_transient( $key, $value, $ttl ) { $GLOBALS['dtb_test_transients'][$key] = $value; return true; }
function wp_generate_uuid4() { static $v = 0; return sprintf( 'test-generation-%d', ++$v ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function sanitize_key( $value ) { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', $value ) ); }
function wp_unslash( $value ) { return stripslashes( $value ); }
function wp_parse_url( $value, $part ) { return parse_url( $value, $part ); }
function add_filter() {}
function dtb_log_cache_event() {}
function check( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: $message\n" ); exit( 1 ); } }
$root = dirname( __DIR__, 2 ) . '/drywalltoolbox/wp/wp-content/mu-plugins';
require $root . '/dtb-platform/Cache/CacheKeyBuilder.php';
require $root . '/dtb-platform/Cache/CacheInvalidationService.php';
require $root . '/dtb-platform/Cache/CacheHeaders.php';
$a = DTB_CacheKeyBuilder::proxy_key( 'wc/v3/products', [ 'page' => 1 ] );
DTB_CacheInvalidationService::invalidate_product_cache();
$b = DTB_CacheKeyBuilder::proxy_key( 'wc/v3/products', [ 'page' => 1 ] );
check( $a !== $b, 'proxy key must change after invalidation' );
$c = DTB_CacheKeyBuilder::ops_key( 'media', 'list' );
DTB_CacheInvalidationService::flush_ops_cache( 'media' );
$d = DTB_CacheKeyBuilder::ops_key( 'media', 'list' );
check( $c !== $d, 'module flush must invalidate its keys' );
$other = DTB_CacheKeyBuilder::ops_key( 'catalog', 'list' );
DTB_CacheInvalidationService::flush_ops_cache();
check( $other !== DTB_CacheKeyBuilder::ops_key( 'catalog', 'list' ), 'global flush must invalidate every module' );
$_SERVER['REQUEST_URI'] = '/wp-json/dtb/v1/repairs?test=1';
$headers = DTB_CacheHeaders::filter_headers( [ 'Cache-Control' => 'public, max-age=3600' ] );
check( $headers['Cache-Control'] === 'private, no-store, max-age=0', 'DTB endpoints must be private' );
$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
$headers = DTB_CacheHeaders::filter_headers( [ 'Cache-Control' => 'no-cache' ] );
check( $headers['Cache-Control'] === 'no-cache', 'must not override Woo Store API policy' );
echo "Cache compatibility smoke tests passed\n";
