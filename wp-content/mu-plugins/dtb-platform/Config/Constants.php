<?php
defined( 'ABSPATH' ) || exit;

/*
 * =============================================================================
 * DTB wp-config.php Constants — Configuration Reference
 * =============================================================================
 *
 * This file contains NO executable code.
 * All content below is a comment-only reference for site administrators.
 *
 * Copy the define() block into your wp-config.php (or a file included by it)
 * and fill in the values for your environment.
 *
 * -----------------------------------------------------------------------------
 * READY-TO-COPY CONSTANTS BLOCK
 * -----------------------------------------------------------------------------
 *
 * define('DRYWALL_ALLOWED_ORIGIN',   'https://drywalltoolbox.com');
 * define('DRYWALL_JWT_SECRET',       '');  // 32+ char random string
 * define('WC_PROXY_CONSUMER_KEY',    'ck_...');
 * define('WC_PROXY_CONSUMER_SECRET', 'cs_...');
 * define('WC_WEBHOOK_SECRET',        '');  // 32+ char random string
 * define('DTB_ADMIN_EMAIL',          'you@drywalltoolbox.com');
 * define('DISALLOW_FILE_EDIT',       true);
 * define('DTB_VEEQO_API_KEY',        '');  // Veeqo API key (Settings → API Keys)
 * define('DTB_VEEQO_WEBHOOK_SECRET', '');  // 32+ char random string for Veeqo webhook HMAC
 * define('DTB_VEEQO_WAREHOUSE_ID',   0);   // Primary Veeqo warehouse ID
 * define('DTB_VEEQO_CHANNEL_ID',     0);   // Veeqo channel ID for this WooCommerce store
 * define('DTB_VEEQO_DELIVERY_METHOD_ID', 0); // Optional Veeqo delivery method ID for API-created orders
 *
 * -----------------------------------------------------------------------------
 * CONSTANT DOCUMENTATION
 * -----------------------------------------------------------------------------
 *
 * DRYWALL_ALLOWED_ORIGIN
 *   What it does : Adds an extra origin to the CORS allowlist maintained by
 *                  dtb_allowed_origins() in 00-dtb-loader.php. Useful for
 *                  staging domains or custom sub-domains.
 *   Where to get : The full scheme + host of your extra origin, e.g.
 *                  'https://preview.example.com'. No trailing slash.
 *   What breaks  : Without it the extra origin is blocked by CORS; only the
 *                  hard-coded production domain and localhost URLs are allowed.
 *                  Set it only when you need a non-standard origin.
 *
 * DRYWALL_JWT_SECRET
 *   What it does : The HMAC-SHA256 signing secret used by dtb_generate_jwt()
 *                  and dtb_verify_jwt() in dtb-auth.php. Every JWT issued to
 *                  SPA users is signed with this value. Must be at least 32
 *                  characters of cryptographically random data.
 *   Where to get : Generate one with: php -r "echo bin2hex(random_bytes(32));"
 *                  or use a password manager's secure-string generator.
 *   What breaks  : Without it no JWT can be signed or verified — all login
 *                  attempts return a 401 and the SPA cannot authenticate users.
 *
 * WC_PROXY_CONSUMER_KEY
 *   What it does : WooCommerce REST API consumer key used by dtb-rest-api.php
 *                  to make server-side proxy calls to wc/v3/* endpoints. The
 *                  key authenticates WordPress → WooCommerce internal requests.
 *   Where to get : WordPress Admin → WooCommerce → Settings → Advanced →
 *                  REST API → Add Key. Set Read/Write permissions.
 *   What breaks  : Without it all product, category, order, and customer proxy
 *                  routes return 401 from the WC internal API.
 *
 * WC_PROXY_CONSUMER_SECRET
 *   What it does : Paired with WC_PROXY_CONSUMER_KEY to form the Basic Auth
 *                  header for internal WC REST proxy calls.
 *   Where to get : Shown once at key creation (same screen as above). Store it
 *                  immediately — WooCommerce never displays it again.
 *   What breaks  : Same as WC_PROXY_CONSUMER_KEY — all proxy routes fail.
 *
 * WC_WEBHOOK_SECRET
 *   What it does : HMAC secret used to validate incoming WooCommerce product
 *                  webhooks (created/updated/deleted/restored). The webhook
 *                  receiver in dtb-rest-api.php rejects any request whose
 *                  X-WC-Webhook-Signature does not match this secret.
 *   Where to get : Generate one with: php -r "echo bin2hex(random_bytes(32));"
 *                  Set the same value on each webhook in WooCommerce →
 *                  Settings → Advanced → Webhooks.
 *   What breaks  : Without it drywall_ensure_webhooks() silently skips webhook
 *                  creation, and any manually created webhook will be rejected
 *                  with a 401 signature mismatch, so the product cache is never
 *                  invalidated automatically.
 *
 * DTB_ADMIN_EMAIL
 *   What it does : Destination address for admin notification e-mails sent by
 *                  dtb-coming-soon.php on new subscriber sign-ups.
 *   Where to get : Your operational inbox — typically the same address as the
 *                  WordPress admin e-mail in Settings → General.
 *   What breaks  : Without it new-subscriber notifications are silently
 *                  dropped (WordPress falls back to the admin_email option
 *                  value stored in the database, so notifications still send
 *                  if that option is set correctly).
 *
 * DISALLOW_FILE_EDIT
 *   What it does : Removes the Theme/Plugin file editor from the WordPress
 *                  admin UI. Strongly recommended for production sites to
 *                  prevent remote code execution through a compromised admin
 *                  account.
 *   Where to get : No value to fetch — just set to true.
 *   What breaks  : Theme and plugin files can no longer be edited from the
 *                  WordPress admin. Make changes in canonical repository source
 *                  and transfer only reviewed production artifacts.
 *
 * -----------------------------------------------------------------------------
 * OPTIONAL CONSTANTS (not shown in the ready-to-copy block above)
 * -----------------------------------------------------------------------------
 *
 * define('DTB_WC_CSV_FILENAME', 'woocommerce_catalog_production_remapped.csv');
 *   Override the catalog CSV filename used by the /dtb/v1/products-csv and
 *   /dtb/v1/import-catalog endpoints (set in dtb-utils.php).
 *   When omitted, dtb-utils.php auto-discovers the newest product-wc-*.csv
 *   file in wp-content/uploads/wc-imports/ via glob.
 *
 * define('DTB_CATALOG_PLATFORM_ENABLED', false);
 *   Feature gate for dtb-catalog-platform/bootstrap.php.
 *   false = module stays loaded in code but runtime routes/services do not
 *   bootstrap. Use false for staged catalog imports/backfills, then set true
 *   only after catalog facet/product/toolset endpoint smoke checks pass.
 *
 * define('DTB_WEBHOOK_DELIVERY_URL', 'https://drywalltoolbox.com/wp-json/drywall/v1/webhooks/products');
 *   Override the WooCommerce webhook delivery URL (drywall_ensure_webhooks).
 *
 * define('DTB_IMPORT_SECRET', '');
 *   Token for CI/CD-triggered catalog imports via POST /dtb/v1/import-catalog.
 *   If omitted, the dtb_import_secret WordPress option is used as fallback.
 *
 * define('DTB_WC_AUTH_USER', '');
 * define('DTB_WC_AUTH_PASS', '');
 *   Application Password username/password pair exposed to trusted browser
 *   origins via GET /dtb/v1/config. Used by the React SPA to authenticate
 *   WooCommerce Store API calls directly from the browser.
 *
 * define('DTB_VEEQO_DEBUG', true);
 *   Enable Veeqo integration debug logging (disabled by default in production).
 *   When true, all dtb_veeqo_log('debug', ...) calls are written to the PHP
 *   error log. Set only when actively debugging the Veeqo integration.
 *
 * =============================================================================
 * VEEQO INTEGRATION CONSTANTS
 * =============================================================================
 *
 * DTB_VEEQO_API_KEY
 *   What it does : The Veeqo API key used by dtb-veeqo.php for all server-side
 *                  calls to the Veeqo REST API. The key is never exposed to
 *                  browser clients — all Veeqo API calls originate from the
 *                  WordPress server.
 *   Where to get : Veeqo → Settings → API Keys → Add API Key.
 *   What breaks  : Without it the Veeqo integration is silently disabled.
 *                  All order sync, inventory checks, and webhook registration
 *                  are skipped.
 *
 * DTB_VEEQO_WEBHOOK_SECRET
 *   What it does : HMAC-SHA256 secret used to validate incoming webhook events
 *                  from Veeqo at POST /dtb/v1/veeqo/webhooks/order. Any
 *                  request whose X-Veeqo-Signature does not match is rejected
 *                  with 401.
 *   Where to get : Generate with: php -r "echo bin2hex(random_bytes(32));"
 *                  Set the same value when configuring the webhook in Veeqo.
 *   What breaks  : If left empty the signature check is skipped (less secure).
 *                  If set incorrectly all Veeqo webhooks are rejected.
 *
 * DTB_VEEQO_WAREHOUSE_ID
 *   What it does : The numeric ID of the primary Veeqo warehouse used for
 *                  order fulfilment and inventory routing.
 *   Where to get : Auto-discovered automatically from GET /warehouses when
 *                  you save the API key under WooCommerce → Settings →
 *                  Integrations → Drywall Toolbox Veeqo.  Alternatively,
 *                  you can find the ID in Veeqo → Warehouses → select
 *                  warehouse → check the URL.
 *   Override     : Define this constant in wp-config.php to force a specific
 *                  warehouse; it takes precedence over the auto-discovered
 *                  value stored in wp_options.
 *   What breaks  : Without it order payloads omit warehouse routing; Veeqo
 *                  will use the account default warehouse instead.
 *
 * DTB_VEEQO_CHANNEL_ID
 *   What it does : The Veeqo channel ID (= Store ID) that corresponds to this
 *                  WooCommerce store.  Used when creating orders so Veeqo can
 *                  attribute sales to the correct sales channel.
 *   Where to get : Auto-discovered automatically from GET /channels when you
 *                  save the API key under WooCommerce → Settings →
 *                  Integrations → Drywall Toolbox Veeqo.  Alternatively,
 *                  you can find the ID in Veeqo → Settings → Channels →
 *                  WooCommerce channel → ID.
 *   Override     : Define this constant in wp-config.php to force a specific
 *                  channel; it takes precedence over the auto-discovered
 *                  value stored in wp_options.
 *   What breaks  : Without it orders are created without a channel assignment.
 *
 * DTB_VEEQO_DELIVERY_METHOD_ID
 *   What it does : Optional Veeqo delivery method ID included when creating
 *                  WooCommerce orders in Veeqo. Veeqo's order API documents
 *                  delivery_method_id as required for order creation.
 *   Where to get : Use the delivery method ID configured in Veeqo for the
 *                  shipping method this WooCommerce store should use.
 *   Override     : Define this constant in wp-config.php to force a specific
 *                  delivery method; it takes precedence over wp_options.
 *   What breaks  : Accounts that require delivery_method_id can reject order
 *                  creation without it.
 *
 * =============================================================================
 * QUICKBOOKS ONLINE CONSTANTS
 * =============================================================================
 *
 * DTB_QBO_CLIENT_ID
 *   What it does : OAuth2 client ID for the QuickBooks Online integration in
 *                  dtb-quickbooks.php.  Required to begin the OAuth2
 *                  authorization flow and to refresh access tokens.
 *   Where to get : Intuit Developer → My Apps → <your app> → Keys & OAuth →
 *                  Production keys → Client ID.
 *   What breaks  : Without it the QuickBooks integration is silently disabled.
 *                  All QBO syncs, accounting REST routes, and the OAuth2 flow
 *                  return early without attempting any API call.
 *
 * DTB_QBO_CLIENT_SECRET
 *   What it does : OAuth2 client secret paired with DTB_QBO_CLIENT_ID.  Used
 *                  to exchange the authorization code for access/refresh tokens
 *                  and to refresh expired access tokens via the token endpoint.
 *   Where to get : Intuit Developer → My Apps → <your app> → Keys & OAuth →
 *                  Production keys → Client Secret.
 *   What breaks  : Same as DTB_QBO_CLIENT_ID — the entire QBO integration
 *                  fails to authorize without it.
 *
 * DTB_QBO_REALM_ID
 *   What it does : The QuickBooks company (realm) ID that identifies which QBO
 *                  company account to sync against.  Included in every API
 *                  request URL: /v3/company/{realm_id}/...
 *   Where to get : Displayed in the QBO URL after sign-in:
 *                  https://app.qbo.intuit.com/app/homepage?companyId=<realm_id>
 *                  Also returned in the OAuth2 callback query string.
 *   What breaks  : Without it all QBO REST API calls fail with a 400/404.
 *
 * =============================================================================
 * DTB OPS DASHBOARD CONSTANTS
 * =============================================================================
 *
 * DTB_OPS_VERSION
 *   What it does : Semantic version string for the DTB Ops Dashboard module.
 *                  Stored in wp_options('dtb_ops_version') on activation and
 *                  emitted in the X-DTB-Version response header.
 *   Default      : '1.0.0' — defined automatically in dtb-ops-dashboard.php.
 *   Override     : Rarely needed; override only when deploying a specific
 *                  pinned version string to a staging environment.
 *
 * DTB_ENABLE_CSP
 *   What it does : Boolean flag that enables the Content-Security-Policy header
 *                  on all non-admin frontend responses (dtb-frontend-security.php).
 *   Default      : false (CSP is off by default to avoid breaking third-party
 *                  scripts on initial deployment).
 *   Override     : Set to true in wp-config.php once you have verified that your
 *                  theme and all active plugins comply with the policy:
 *                    default-src 'self'; script-src 'self' 'unsafe-inline'; ...
 *                  Test in CSP Report-Only mode first if possible.
 *
 * =============================================================================
 * =============================================================================
 * PRODUCT IMAGE SYNC — WORKFLOW
 * =============================================================================
 *
 * Images are stored on the live server at:
 *   public_html/drywalltoolbox/wp/wp-content/uploads/2026/04/<filename>.webp
 *
 * The workflow to wire those images to WooCommerce products is:
 *
 * STEP 1 — Export the product catalog CSV from WooCommerce
 * ----------------------------------------------------------
 *   WooCommerce → Products → Export (or use WP Admin CSV export).
 *   Audit/fix missing or placeholder image URLs and optionally convert
 *   replacements to high-quality WebP:
 *     python scripts/audit_catalog_images.py --download-convert --write
 *
 *   Safety guardrails in the script block prohibited sources:
 *     - alstapingtools.com
 *     - all-wall.com / allwall.com
 *
 * STEP 2 — Upload the updated CSV to the server
 * -----------------------------------------------
 *   Use WooCommerce → Products → Import, or upload the reviewed CSV with
 *   FileZilla to:
 *     public_html/drywalltoolbox/wp/wp-content/uploads/wc-imports/
 *   (filename must match DTB_WC_CSV_FILENAME / dtb_get_config()['csv_filename'],
 *    or use auto-discovery by naming it product-wc-<brand>-<suffix>.csv)
 *
 * STEP 3 — Register images in the WP Media Library
 * --------------------------------------------------
 *   POST https://drywalltoolbox.com/wp-json/dtb/v1/sync-images
 *   Authorization: Bearer <admin-jwt>     (or Application Password Basic auth)
 *   Content-Type: application/json
 *
 *   Body (all fields optional):
 *     { "year": "2026", "month": "04", "dry_run": false }
 *
 *   This scans wp-content/uploads/2026/04/, creates WP attachment records for
 *   any file not yet in the Media Library, and sets each image as the featured
 *   image on the matching WooCommerce product (matched by SKU = filename stem).
 *
 *   Status check (no writes):
 *   GET https://drywalltoolbox.com/wp-json/dtb/v1/sync-images/status
 *
 * STEP 4 — Re-import the product catalog
 * ----------------------------------------
 *   POST https://drywalltoolbox.com/wp-json/dtb/v1/import-catalog
 *   Content-Type: application/json
 *   { "secret": "<DTB_IMPORT_SECRET>" }
 *
 *   WooCommerce will now find the images already registered in the Media
 *   Library and link them to products by URL during the CSV import.
 *
 * NOTES:
 *   • The WooCommerce CSV importer resolves Images column URLs by matching
 *     them against attachment GUIDs. Steps 3 and 4 together ensure GUIDs
 *     and the CSV URLs are in sync.
 *   • To add images for a future upload month (e.g. 2026/05), repeat
 *     Step 1 with --base https://drywalltoolbox.com/wp-content/uploads/2026/05
 *     and call sync-images with { "year": "2026", "month": "05" }.
 *
 * =============================================================================
 */
