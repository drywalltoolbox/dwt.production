"""Configuration for the Launch Readiness Validation Suite.

All configuration comes from environment variables (optionally loaded from a
`.env` file in this directory via `python-dotenv`). Nothing is hard-coded to
a specific deployment except the documented defaults for the Drywall Toolbox
production site, which double as sane examples.

Only read-scoped, non-destructive credentials are required by default. The
checkout simulation stages that add to cart, pay, and place real orders are
gated behind `LAUNCH_ENABLE_CHECKOUT_SIMULATION` and refuse to run against a
live (non-test-mode) WooPayments key, so this suite is safe to point at a
production URL for the read-only stages (environment validation and website
crawl) without accidentally placing orders.
"""

from __future__ import annotations

import os
from dataclasses import dataclass, field
from pathlib import Path

PROJECT_ROOT = Path(__file__).resolve().parent
REPORTS_DIR = PROJECT_ROOT / "reports" / "output"

_TRUE_VALUES = {"1", "true", "yes", "on"}


def _env_bool(name: str, default: bool) -> bool:
    raw = os.environ.get(name)
    if raw is None:
        return default
    return raw.strip().lower() in _TRUE_VALUES


def _env_float(name: str, default: float) -> float:
    raw = os.environ.get(name)
    if raw is None or not raw.strip():
        return default
    try:
        return float(raw)
    except ValueError:
        return default


def _env_int(name: str, default: int) -> int:
    raw = os.environ.get(name)
    if raw is None or not raw.strip():
        return default
    try:
        return int(raw)
    except ValueError:
        return default


def _load_dotenv() -> None:
    """Best-effort `.env` loading without a hard dependency on the package."""

    try:
        from dotenv import load_dotenv
    except ImportError:
        return
    load_dotenv(PROJECT_ROOT / ".env", override=False)


@dataclass(slots=True)
class Config:
    # -- Target environment ------------------------------------------------
    site_url: str = "https://drywalltoolbox.com/staging"
    api_base: str = ""  # public WordPress REST base, e.g. https://host/wp-json
    request_timeout_s: float = 15.0

    # -- WooCommerce REST API (Settings > Advanced > REST API, read scope) --
    wc_consumer_key: str = ""
    wc_consumer_secret: str = ""

    # -- DTB admin control center (WordPress Application Password) ---------
    dtb_admin_username: str = ""
    dtb_admin_app_password: str = ""

    # -- Browser automation --------------------------------------------------
    headless: bool = True
    browser_timeout_ms: int = 30_000
    slow_mo_ms: int = 0
    browser_ignore_https_errors: bool = False

    # -- Checkout simulation safety gate --------------------------------------
    enable_checkout_simulation: bool = False
    test_customer_email_domain: str = "launch-readiness.drywalltoolbox.test"
    # Optional product path or same-origin absolute URL.
    product_url_path: str = ""
    # Populated by the Stage 1 Store API preflight and reused by browser stages.
    validated_product_name: str = ""
    validated_product_url: str = ""

    # WooPayments test card (default is WooPayments's standard successful test card).
    test_card_number: str = "4242424242424242"
    test_card_exp: str = "05/29"
    test_card_cvc: str = "398"
    test_card_zip: str = "10001"

    # -- Integration verification polling ------------------------------------
    sync_poll_timeout_s: float = 90.0
    sync_poll_interval_s: float = 5.0
    skip_integration_checks: bool = False

    # -- Reporting -------------------------------------------------------------
    reports_dir: Path = field(default_factory=lambda: REPORTS_DIR)

    @property
    def wc_api_configured(self) -> bool:
        return bool(self.wc_consumer_key and self.wc_consumer_secret)

    @property
    def dtb_admin_configured(self) -> bool:
        return bool(self.dtb_admin_username and self.dtb_admin_app_password)


def load_config() -> Config:
    _load_dotenv()

    site_url = os.environ.get("LAUNCH_SITE_URL", "https://drywalltoolbox.com/staging").rstrip("/")
    api_base = os.environ.get("LAUNCH_API_BASE_URL", f"{site_url}/wp-json").rstrip("/")

    config = Config(
        site_url=site_url,
        api_base=api_base,
        request_timeout_s=_env_float("LAUNCH_REQUEST_TIMEOUT_S", 15.0),
        wc_consumer_key=os.environ.get("LAUNCH_WC_CONSUMER_KEY", ""),
        wc_consumer_secret=os.environ.get("LAUNCH_WC_CONSUMER_SECRET", ""),
        dtb_admin_username=os.environ.get("LAUNCH_DTB_ADMIN_USERNAME", ""),
        dtb_admin_app_password=os.environ.get("LAUNCH_DTB_ADMIN_APP_PASSWORD", ""),
        headless=_env_bool("LAUNCH_HEADLESS", True),
        browser_timeout_ms=_env_int("LAUNCH_BROWSER_TIMEOUT_MS", 30_000),
        slow_mo_ms=_env_int("LAUNCH_SLOW_MO_MS", 0),
        browser_ignore_https_errors=_env_bool("LAUNCH_BROWSER_IGNORE_TLS_ERRORS", False),
        enable_checkout_simulation=_env_bool("LAUNCH_ENABLE_CHECKOUT_SIMULATION", False),
        test_customer_email_domain=os.environ.get(
            "LAUNCH_TEST_EMAIL_DOMAIN", "launch-readiness.drywalltoolbox.test"
        ),
        product_url_path=os.environ.get("LAUNCH_PRODUCT_URL_PATH", ""),
        test_card_number=os.environ.get("LAUNCH_TEST_CARD_NUMBER", "4242424242424242"),
        test_card_exp=os.environ.get("LAUNCH_TEST_CARD_EXP", "05/29"),
        test_card_cvc=os.environ.get("LAUNCH_TEST_CARD_CVC", "398"),
        test_card_zip=os.environ.get("LAUNCH_TEST_CARD_ZIP", "10001"),
        sync_poll_timeout_s=_env_float("LAUNCH_SYNC_POLL_TIMEOUT_S", 90.0),
        sync_poll_interval_s=_env_float("LAUNCH_SYNC_POLL_INTERVAL_S", 5.0),
        skip_integration_checks=_env_bool("LAUNCH_SKIP_INTEGRATION_CHECKS", False),
    )
    config.reports_dir.mkdir(parents=True, exist_ok=True)
    return config


# -- Essential public pages crawled by the Website Crawl workflow -----------
# (path, human label). Kept here, next to Config, since it is launch-critical
# surface area rather than a workflow implementation detail.
ESSENTIAL_PAGES: list[tuple[str, str]] = [
    ("/", "Home"),
    ("/products", "Shop"),
    ("/products/brands", "Brands"),
    ("/parts", "Parts / Categories"),
    ("/products?search=drywall", "Search"),
    ("/schematics", "Schematics"),
    ("/repairs", "Repair Services"),
    ("/cart", "Cart"),
    ("/checkout", "Checkout"),
    ("/login", "Login"),
    ("/register", "Register"),
    ("/dashboard", "Account"),
    ("/contact", "Contact"),
    ("/returns", "Returns"),
    ("/return-policy", "Return Policy"),
    ("/shipping-policy", "Shipping Policy"),
    ("/policies", "Store Policies"),
    ("/faq", "FAQ"),
]
