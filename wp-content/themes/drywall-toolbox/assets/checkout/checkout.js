/**
 * Drywall Toolbox — native checkout behavior boundary.
 *
 * Intentionally no-op.
 *
 * WooCommerce Checkout Block and the active payment extension own the checkout
 * DOM, field focus, validation, shipping recalculation, payment surfaces and
 * submission lifecycle. DTB must not hide, move, resize, observe, focus, or
 * otherwise orchestrate nodes inside that React-managed tree.
 *
 * Mobile checkout is therefore a single native WooCommerce document flow.
 * DTB presentation belongs in checkout.css only. This file remains as a stable
 * asset handle so deployments do not need a second asset-registration contract
 * and so future behavior, if ever required, has an explicit ownership boundary.
 */
( function () {
	'use strict';
}() );
