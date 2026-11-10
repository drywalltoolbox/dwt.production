# SiteGround WordPress runtime architecture

## Environment boundaries

Drywall Toolbox runs one production WordPress/WooCommerce runtime. The staging
surface is a separately built React client mounted below `/staging`; it
reads from the shared root WordPress REST authority.

| Surface | Public URL | WordPress/REST authority | Server directory |
| --- | --- | --- | --- |
| Staging React | `https://drywalltoolbox.com/staging` | `https://drywalltoolbox.com/wp-json` | `/public_html/staging/` |
| Production | `https://drywalltoolbox.com` | `https://drywalltoolbox.com/wp` | `/public_html/wp/` |

Native admin and login URLs remain in the physical production WordPress
namespace: `/wp/wp-admin/` and `/wp/wp-login.php`.

The document-root Apache files may expose convenience aliases, but WordPress
must generate admin, login, plugin, nonce, and POST destinations from
`WP_SITEURL`. Do not change `WP_SITEURL` to the React storefront URL.

## Configuration ownership

- `wp-config.php` is server-owned, ignored by Git, and contains secrets.
- `wp-config-sample.php` is the tracked WordPress production contract.
- `drywalltoolbox/.htaccess` is the canonical production root routing contract;
  `frontend/.htaccess` is its build mirror and must match byte-for-byte.
  `drywalltoolbox/staging/.htaccess` owns the `/staging` React mount. The root
  production build emits `frontend/.htaccess`; the staging build emits the
  staging contract as `.htaccess` in its artifact.
- `npm run build:production` builds the root-mounted React storefront into
  `dist/`, including its production routing contract. Upload the artifact
  contents into `/public_html/` only after the local build and release checks
  pass; publish `index.html` after its hashed assets are present.
- the files under `drywalltoolbox/wp/` own the shared WordPress runtime routing
  and dynamic-response cache policy.

The staging build must not assume that WordPress exists below
`/staging/wp`. Its public API configuration points to the root origin while
`PUBLIC_URL=/staging` remains authoritative for staging-owned assets and
React routes.

## Database and URL synchronization

The SiteGround database connection and credentials remain only in the
server-owned `wp-config.php`; repository documentation does not define or
duplicate those environment-owned values.

`WP_HOME` and `WP_SITEURL` constants are authoritative at runtime. The matching
`home` and `siteurl` option rows should contain the same values so removing the
constants during recovery does not expose stale routing. URL replacement must
use WP-CLI or another serialization-aware WordPress migration tool; do not run
raw SQL replacement over serialized option or metadata values.

The production WordPress option values are:

```text
home    = https://drywalltoolbox.com
siteurl = https://drywalltoolbox.com/wp
```

## Cookie and cache boundaries

WordPress and WooCommerce cookies use `/` because native `/wp/wp-admin/`, root
REST aliases, production storefront, and the staging React client share the
same WordPress/WooCommerce runtime. Staging is therefore not a data-isolation
boundary: authenticated, cart, account, and checkout behavior reaches the
production system of record.

Do not define `COOKIE_DOMAIN` for this same-host topology. Host-only cookies
avoid unnecessary subdomain sharing. Dynamic admin, login, REST, cart, checkout,
account, callback, and authenticated requests must remain private and uncacheable.
Static content-addressed assets may use long-lived public caching.

## Secret handling

Never upload a backup configuration into a web-accessible directory. Apache
denies common backup names as defense in depth, but the authoritative control is
keeping backups outside `public_html`. Any database, WordPress application,
WooCommerce API/webhook, JWT, Veeqo, QuickBooks, GitHub, or deployment secret
that was pasted into a browser, chat, log, or repository must be rotated in its
own provider and then updated only in the server-owned configuration.
