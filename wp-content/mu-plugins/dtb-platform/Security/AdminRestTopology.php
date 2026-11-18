<?php
/**
 * Native wp-admin topology contract.
 *
 * WordPress core is installed under WP_SITEURL (`/wp`). On SiteGround the
 * native admin path must remain `/wp/wp-admin/`; redirecting that request to a
 * root `/wp-admin/` alias allows the nginx/static SPA layer to serve React's
 * index.html before WordPress receives the request.
 *
 * Root REST aliases remain supported by the document-root rewrite layer, but
 * native WordPress login and admin navigation stay on WP_SITEURL.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

/**
 * Retained as a compatibility no-op for callers from older deployments.
 *
 * Do not redirect `/wp/wp-admin/` to `/wp-admin/`. WordPress authentication,
 * nonce generation, plugin screens, and POST redirects must stay inside the
 * physical WordPress URL namespace on this hosting topology.
 */
function dtb_native_admin_redirect_physical_admin_alias(): void {
	return;
}
