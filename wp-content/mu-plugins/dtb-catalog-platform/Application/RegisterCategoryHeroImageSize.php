<?php
/**
 * Registers the dedicated image size category hero banners are served at.
 *
 * Deliberately a soft resize (crop = false), not a hard center-crop: the
 * frontend hero banner already does its own responsive crop via CSS
 * `aspect-ratio` + `object-fit: cover` per breakpoint (4:3 on phones up to
 * 2.4:1 on desktop — see category-hero.css). If this size pre-cropped the
 * source to one fixed ratio, the browser would be cropping an
 * already-cropped image on every other breakpoint. Capping the longest
 * dimension at 1920px keeps the served file appropriately sized (not the
 * full, possibly much larger, original upload) while preserving the whole
 * photo for the browser to crop correctly at any ratio.
 *
 * Must load on every admin-or-REST request (not just wp-admin), since the
 * storefront REST endpoint (CatalogFacetsController::handle_category)
 * resolves this named size too — that's why this file lives outside the
 * Admin/-only require block in bootstrap.php.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

const DTB_CATEGORY_HERO_IMAGE_SIZE = 'dtb_category_hero';

add_action( 'init', 'dtb_register_category_hero_image_size' );

function dtb_register_category_hero_image_size(): void {
	add_image_size( DTB_CATEGORY_HERO_IMAGE_SIZE, 1920, 1920, false );
}
