# WordPress checkout theme runtime

## Authority

The public React storefront does not make WordPress themes authoritative for customer commerce. WooCommerce remains the checkout/order/payment authority. The tracked WordPress theme `drywalltoolbox/wp/wp-content/themes/drywall-toolbox` owns only the native WooCommerce checkout document shell and its presentation assets/templates.

The shared WordPress runtime must deploy that theme directory to `wp/wp-content/themes/drywall-toolbox`. The active WordPress `template` and `stylesheet` options should normally be `drywall-toolbox` when native checkout is enabled.

## Missing-theme protection

`dtb-deployment/Infrastructure/ThemeRuntimeGuard.php` protects runtime availability when WordPress options reference a deleted theme directory. It filters the resolved `template` and `stylesheet` values only when all of the following are true:

1. the configured theme slug does not resolve to an installed theme directory with `style.css`;
2. the tracked `drywall-toolbox` theme is installed and valid.

The guard then uses `drywall-toolbox` for that request. It does not write WordPress options, does not override a valid installed theme, and does not invent a theme if the canonical DTB theme is also missing.

This is a failover mechanism, not the normal activation workflow. Operators should still activate `Drywall Toolbox` in WordPress and verify the database options after deployment.

## Staging checkout routing

The staging React storefront is mounted at `/staging`. Its public checkout URL remains `/staging/checkout/`, but `drywalltoolbox/staging/.htaccess` internally executes the shared WordPress controller at `/wp/index.php?pagename=checkout`. The browser must not be externally redirected to the production storefront root merely to reach WooCommerce.

Production remains root-mounted at `/checkout/` and is internally routed to the same shared WordPress checkout controller.

`dtb-commerce/Payment/WooNativeCheckoutRuntime.php` suppresses WordPress's canonical redirect only for the original request paths `/staging/checkout`, `/staging/checkout/order-pay/<numeric-id>`, `/staging/checkout/order-received/<numeric-id>`, and `/staging/order-pay/<numeric-id>` (with optional trailing slash). These are the existing internal native checkout aliases, not a separate WordPress installation. The adapter derives the presentation mount from this exact path allowlist, without decoding path segments or trusting an Origin, referrer, query value, or proposed redirect URL. Admin, AJAX, and REST requests retain normal canonical handling.

WordPress also parses the original staging path against its permalink rules after Apache's internal rewrite. For these exact aliases, the `request` filter resolves `pagename` to the configured checkout page slug while retaining WooCommerce endpoint and provider query variables. Without this correction, the canonical redirect can be suppressed while the main query still resolves a nonexistent `staging/checkout` page and the native theme renders its 404 fallback.

On those native staging documents the theme's brand link points to `/staging/` and Back to cart points to `/staging/cart/`. Production continues to use `home_url('/')` and `wc_get_cart_url()`. Checkout/order-pay/order-received query parameters remain unchanged and WooCommerce/payment providers retain order-key validation, authentication, payment execution, and callback ownership. Plain-permalink provider callbacks at the mount root are outside this canonical exception.

## Deployment checklist

Deploy these together when checkout/theme runtime changes:

- `drywalltoolbox/wp/wp-content/themes/drywall-toolbox/`
- `drywalltoolbox/wp/wp-content/mu-plugins/dtb-deployment/`
- `drywalltoolbox/wp/wp-content/mu-plugins/dtb-commerce/Payment/WooNativeCheckoutRuntime.php`
- `drywalltoolbox/staging/.htaccess` for staging routing changes
- the corresponding frontend build when checkout URL generation changes

After deployment verify:

1. `wp-content/themes/drywall-toolbox/style.css` exists on the server;
2. WordPress Appearance reports `Drywall Toolbox` as installed and active;
3. `/staging/checkout/` renders WooCommerce in staging without a 30x escape to `/checkout/`;
4. `/checkout/` renders WooCommerce in production routing;
5. checkout remains private/no-store;
6. payment fields and order creation remain provider/WooCommerce-owned.

Before deployment run `python -m unittest discover -s scripts/launch-readiness/tests -p test_routing_contract.py`. The native mount regression harness requires PHP CLI and is reported as skipped when PHP is unavailable; a skipped harness does not establish PHP execution or live routing success.

