"""Stage 1 — Environment Validation.

Confirms the target is reachable and correctly configured before any
simulated customer touches it. This stage is the abort gate: if the site or
its APIs are unreachable, every later stage would just fail for the same
reason, so `main.py` stops the run here instead of burning time on a
guaranteed-red report.
"""

from __future__ import annotations

from typing import TYPE_CHECKING

import requests

import tui
from integrations.woocommerce import WooCommerceClient
from reports.models import StageResult, Status
from workflows import common

if TYPE_CHECKING:
    from config import Config

STAGE_NAME = "Environment Validation"


def run(config: "Config") -> StageResult:
    stage = StageResult(name=STAGE_NAME)
    session = requests.Session()

    def check_site_reachable():
        response = session.get(config.site_url, timeout=config.request_timeout_s)
        ok = response.status_code < 500
        status = Status.PASS if response.status_code < 400 else Status.WARN if ok else Status.FAIL
        return status, f"GET {config.site_url} -> HTTP {response.status_code}"

    tui.run_step(stage, "Website reachable", check_site_reachable)
    if stage.status is Status.FAIL:
        return stage  # No point probing APIs on an unreachable host.

    def check_wp_api():
        url = f"{config.api_base}/"
        response = session.get(url, timeout=config.request_timeout_s)
        if response.status_code != 200:
            return Status.FAIL, f"GET {url} -> HTTP {response.status_code}"
        try:
            payload = response.json()
        except ValueError:
            return Status.FAIL, f"GET {url} did not return JSON"
        name = payload.get("name", "unknown")
        return Status.PASS, f"WordPress REST API reachable ({name!r})"

    tui.run_step(stage, "WordPress REST API reachable", check_wp_api)

    def check_store_api():
        url = f"{config.api_base}/wc/store/v1/products"
        response = session.get(url, params={"per_page": 1}, timeout=config.request_timeout_s)
        ok = response.status_code == 200
        return (
            Status.PASS if ok else Status.FAIL,
            f"GET {url} -> HTTP {response.status_code}",
        )

    tui.run_step(stage, "WooCommerce Store API reachable", check_store_api)

    def check_configured_product():
        if not config.product_url_path:
            return Status.SKIP, "No LAUNCH_PRODUCT_URL_PATH override is configured."
        product = common.configured_product(config)
        assert product is not None
        return Status.PASS, f"Configured checkout product is live: {product.name} ({product.url})"

    tui.run_step(stage, "Configured checkout product", check_configured_product)

    def check_wc_rest_credentials():
        if not config.wc_api_configured:
            return (
                Status.WARN,
                "LAUNCH_WC_CONSUMER_KEY/SECRET not set — WooCommerce/Veeqo/QuickBooks "
                "order verification stages will be skipped.",
            )
        ok, detail = WooCommerceClient(config).ping()
        return (Status.PASS if ok else Status.FAIL), detail

    tui.run_step(stage, "WooCommerce REST API credentials", check_wc_rest_credentials)

    def check_dtb_admin_credentials():
        if not config.dtb_admin_configured:
            return (
                Status.SKIP,
                "LAUNCH_DTB_ADMIN_USERNAME/APP_PASSWORD not set — operator control-center "
                "diagnostics will be skipped (order-level Veeqo/QuickBooks checks still run).",
            )
        return Status.PASS, "DTB admin application-password credentials configured."

    tui.run_step(stage, "DTB admin credentials configured", check_dtb_admin_credentials)

    def check_checkout_simulation_safety():
        if not config.enable_checkout_simulation:
            return (
                Status.SKIP,
                "LAUNCH_ENABLE_CHECKOUT_SIMULATION is not set — guest/customer checkout "
                "stages will be skipped and no orders will be placed.",
            )
        url = f"{config.api_base}/dtb/v1/checkout/capabilities"
        try:
            response = session.get(url, timeout=config.request_timeout_s)
            payload = response.json() if response.ok else {}
        except (requests.RequestException, ValueError):
            payload = {}
        readiness = payload.get("readiness", {}) if isinstance(payload, dict) else {}
        if readiness.get("provider_test_mode") is not True:
            return Status.FAIL, "WooPayments is not confirmed in test mode; refusing checkout simulation."
        if readiness.get("isolated_test_environment") is not True or readiness.get("test_checkout_available") is not True:
            return Status.FAIL, "The target is not an isolated staging checkout; refusing simulated orders."
        if payload.get("provider") != "woopayments":
            return Status.FAIL, "WooPayments is not the reported checkout provider; refusing simulation."
        return Status.PASS, "WooPayments test mode and isolated staging checkout are confirmed by the server."

    tui.run_step(stage, "Checkout simulation safety gate", check_checkout_simulation_safety)

    def check_test_email_delivery():
        if not config.enable_checkout_simulation:
            return Status.SKIP, "Checkout simulation is disabled."
        domain = config.test_customer_email_domain.strip().lower().rstrip(".")
        if domain.endswith(".test") or domain == "test":
            return Status.WARN, (
                f"Generated customer addresses use the non-deliverable .test domain ({domain}); "
                "email dispatch can be exercised, but inbox delivery cannot be verified. Set "
                "LAUNCH_TEST_EMAIL_DOMAIN to a controlled catch-all mailbox domain for delivery tests."
            )
        return Status.PASS, f"Generated customer email domain is routable: {domain}"

    tui.run_step(stage, "Test customer email delivery domain", check_test_email_delivery)

    return stage
