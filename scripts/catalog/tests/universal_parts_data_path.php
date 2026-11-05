<?php
/** Shared universal-parts source resolution check. */

define( 'ABSPATH', __DIR__ . DIRECTORY_SEPARATOR );
require dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Services/UniversalPartsProjectionService.php';

$service = new DTB_UniversalPartsProjectionService();
$resolved = realpath( $service->data_dir() );
$repo_root = dirname( __DIR__, 3 );
$production = realpath( $repo_root . '/products/Production/launch/universal_parts' );
$canonical = realpath( $repo_root . '/products/launch/universal_parts' );
$expected = false !== $production ? $production : $canonical;

if ( false === $resolved || false === $expected || $resolved !== $expected ) {
	throw new RuntimeException( 'Universal-parts resolver did not select an existing authoritative seed directory.' );
}

$actions = file_get_contents( dirname( __DIR__, 3 ) . '/drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Admin/PartsManagerActions.php' );
if ( false === $actions || ! str_contains( $actions, 'return ( new DTB_UniversalPartsProjectionService() )->data_dir();' ) ) {
	throw new RuntimeException( 'Parts Manager actions do not share the universal-parts service path resolver.' );
}

echo "Universal-parts source resolution and shared owner path passed.\n";
