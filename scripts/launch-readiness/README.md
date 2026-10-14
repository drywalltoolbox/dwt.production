# Launch Readiness Validation Suite

A single-command simulator that behaves like a real Drywall Toolbox customer
and confirms the essential commerce workflow works end to end, from the
storefront through WooCommerce, Veeqo, and QuickBooks.

This is a launch validator, not a testing platform: seven linear stages, one
process, one report. No plugin system, no distributed workers, no AI
decision-making.

## Install

```bash
cd scripts/launch-readiness
python3 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
python -m playwright install chromium   # skip if Chromium is already provisioned
cp .env.example .env                    # then fill in credentials
```

## Run

```bash

# or, from the repo root:
python scripts/launch-readiness
```

Exit code is `0` for PASS/PASS WITH WARNINGS and `1` for FAIL, so it can gate
a deploy pipeline.

## What it does

| # | Stage | What it verifies |
|---|-------|-------------------|
| 1 | Environment Validation | Site, WordPress REST API, and WooCommerce Store API are reachable; REST credentials are valid; WooPayments test mode is confirmed before any checkout simulation runs. Aborts the run on failure. |
| 2 | Website Crawl | Every essential public page (home, shop, brands, product, cart, checkout, login, register, account, contact, repairs, returns, policies, FAQ) returns success, renders real content, and has no console errors. |
| 3 | Guest Checkout Simulation | Browse → product → add to cart → cart → checkout → shipping → WooPayments payment → order confirmation, as an unauthenticated customer, using a real browser (Playwright). |
| 4 | Registered Customer Simulation | Register → login → browse → add to cart → checkout → payment → order complete → logout → login again → order history is visible. |
| 5 | WooCommerce Verification | The placed order exists with a paid-equivalent status, correct customer email, totals, shipping, tax, and captured payment. |
| 6 | Veeqo Verification | The order synchronized into Veeqo (polls the DTB order-pipeline sync status until terminal or timeout). |
| 7 | QuickBooks Verification | The order synchronized into QuickBooks as an accounting projection with the correct total. |

Stages 3-7 are skipped (not failed) unless `LAUNCH_ENABLE_CHECKOUT_SIMULATION=true`
is set, since they place real orders. Stages 5-7 read Veeqo/QuickBooks
synchronization state from WooCommerce order meta rather than calling Veeqo
or Intuit directly — see `integrations/veeqo.py` and
`integrations/quickbooks.py` for exactly which fields, and why (this mirrors
what the DTB order pipeline itself writes: `dtb-order-platform/Infrastructure/OrderQueue.php`,
`dtb-platform/Services/AdminIntegrationStateService.php`).

## Configuration

All configuration is environment variables — see `.env.example` for the full
list and defaults. Only `LAUNCH_SITE_URL` is required; everything else
unlocks additional stages and is otherwise reported as `SKIP` or `WARN` with
an explanation of what's missing.

`LAUNCH_PRODUCT_URL_PATH` accepts either a root-relative product path or a
same-origin absolute URL. Cross-origin product URLs are rejected so checkout
automation and its test customer data cannot be redirected to another site.

## Reports

Every run writes:

- a live terminal summary (stage-by-stage, color-coded);
- an HTML report to `reports/output/launch-readiness-<timestamp>.html`;
- a JSON report to `reports/output/launch-readiness-<timestamp>.json`.

Both are gitignored — they're run artifacts, not source.

## Safety

- Stages 3-7 are opt-in (`LAUNCH_ENABLE_CHECKOUT_SIMULATION=true`) because
  they place real orders and charge a real (test-mode) card.
- The suite refuses to run checkout simulation if the configured WooPayments
  publishable key is a live key (`pk_live_...`), regardless of the opt-in flag.
- Stages 1-2 are read-only and safe to run against production at any time.

## Project layout

```text
main.py            single-command entry point / orchestrator
config.py           environment-driven configuration + essential page list
tui.py               rich-based terminal UI (banner, live steps, summary)
browser/             Playwright wrapper (navigation, console-error capture)
workflows/           the seven stages, plus shared checkout actions in common.py
integrations/        WooCommerce/Veeqo/QuickBooks verification clients
reports/             result data model + HTML/JSON report writers (models.py, html_report.py, json_report.py); output/ holds generated reports
utils/               small timing/text helpers
assets/              report.css for the HTML report
```

## Extending

- Add a page to the crawl: append to `ESSENTIAL_PAGES` in `config.py`.
- Add a WooCommerce/Veeqo/QuickBooks check: add a tuple to the relevant
  `verify()` method in `integrations/`.
- Tune checkout selectors: `workflows/common.py` centralizes every DOM
  interaction used by both checkout simulations. If the storefront's
  checkout markup changes, this is the only file that needs updating.
