<?php
/**
 * SiteGround SuperCacher / SG Optimizer exclusions for the native checkout surface.
 *
 * DONOTCACHEPAGE (DTB_WooNativeCheckoutRuntime::define_donotcachepage_if_checkout())
 * and explicit no-cache response headers (DTB_WooNativeCheckoutRuntime::send_private_headers(),
 * DTB_CacheHeaders::filter_headers()) already stop SG Optimizer's Dynamic Cache from
 * storing/serving a page-cached copy of /checkout/ and its Store API traffic.
 *
 * SG Optimizer's CSS/JS Combine and Minify features are a *separate* subsystem from
 * Dynamic Cache — they rewrite/concatenate enqueued asset URLs into a single combined
 * file independent of whether the page itself is cached, and they do not consult
 * DONOTCACHEPAGE. Left unexcluded, they can silently rewrite or bundle the checkout
 * presentation handles (`dtb-checkout`, `dtb-checkout-desktop`, `dtb-checkout-order-summary`)
 * or WooCommerce/WooPayments checkout scripts into a combined file
 * that only regenerates on SG Optimizer's own schedule/purge — reproducing the exact
 * "looks like the fix failed" symptom this file exists to prevent permanently, without
 * anyone needing to remember to purge Site Tools cache after every deploy.
 *
 * This file only registers exclusions via SG Optimizer's own documented filters. It does
 * not vendor, copy, or patch any SG Optimizer plugin code, and it no-ops completely on any
 * install where SG Optimizer is not active (the filters simply go unused).
 *
 * Presentation-owning files (checkout.css, checkout.js, checkout-desktop.css,
 * checkout-order-summary.js) are intentionally not touched by this file — see
 * docs/checkout/checkout-ui-architecture.md / checkout-desktop-layout.md for the
 * asset/enqueue contract this exclusion list mirrors.
 *
 * @package drywall-toolbox
 */

defined( 'ABSPATH' ) || exit;

if ( class_exists( 'DTB_SiteGroundOptimizerExclusions' ) ) {
	return;
}

final class DTB_SiteGroundOptimizerExclusions {

	/**
	 * Enqueue handles that must never be rewritten into a combined/minified SG Optimizer
	 * asset bundle. Kept in sync with the checkout enqueue registration in the active
	 * theme's functions.php (dtb_enqueue_native_checkout_assets()) and
	 * native-checkout.php (dtb-checkout-desktop).
	 */
	private const EXCLUDED_HANDLES = [
		'dtb-checkout',
		'dtb-checkout-desktop',
		'dtb-checkout-order-summary',
		'dtb-checkout-diagnostics',
	];

	public static function register(): void {
		// CSS/JS Combine.
		add_filter( 'sgo_javascript_combine_exclude', [ self::class, 'exclude_handles_and_paths' ] );
		add_filter( 'sgo_css_combine_exclude', [ self::class, 'exclude_handles_and_paths' ] );

		// CSS/JS Minify.
		add_filter( 'sgo_javascript_minify_exclude', [ self::class, 'exclude_handles_and_paths' ] );
		add_filter( 'sgo_css_minify_exclude', [ self::class, 'exclude_handles_and_paths' ] );

		// HTML minify (defensive — the checkout document itself should not be
		// rewritten either, in case a future SG Optimizer version stops treating
		// DONOTCACHEPAGE pages as automatically HTML-minify-exempt).
		add_filter( 'sgo_html_minify_exclude', [ self::class, 'exclude_paths_only' ] );
	}

	/**
	 * SG Optimizer's combine/minify exclude filters accept an array of strings matched
	 * against the asset URL (substring match against each entry). Handles alone are not
	 * enough to guarantee a match once WordPress resolves a handle to its src URL, so
	 * both the handle name and the checkout asset directory path are supplied — either
	 * is sufficient for SG Optimizer to skip the asset.
	 */
	public static function exclude_handles_and_paths( $excluded ): array {
		$excluded = is_array( $excluded ) ? $excluded : [];

		foreach ( self::EXCLUDED_HANDLES as $handle ) {
			$excluded[] = $handle;
		}

		$excluded[] = '/assets/checkout/';
		$excluded[] = '/checkout/';

		return array_values( array_unique( $excluded ) );
	}

	/**
	 * URL-path-only variant for filters that operate on document/page URLs rather than
	 * individual enqueued asset src URLs.
	 */
	public static function exclude_paths_only( $excluded ): array {
		$excluded   = is_array( $excluded ) ? $excluded : [];
		$excluded[] = '/checkout/';

		return array_values( array_unique( $excluded ) );
	}
}

DTB_SiteGroundOptimizerExclusions::register();
