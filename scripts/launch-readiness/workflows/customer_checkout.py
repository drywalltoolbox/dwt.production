"""Stage 4 — Registered Customer Simulation.

Create Account -> Login -> Browse -> Add Product -> Checkout -> Payment ->
Order Complete -> Logout -> Login Again -> Verify Order History.

Reuses the same checkout core as the guest simulation (`workflows/common.py`)
so the two flows cannot silently drift apart.
"""

from __future__ import annotations

from dataclasses import dataclass
from typing import TYPE_CHECKING

import tui
from reports.models import StageResult, Status
from utils.text import unique_suffix
from workflows import common

if TYPE_CHECKING:
    from browser import Browser
    from config import Config
    from workflows.common import OrderConfirmation

STAGE_NAME = "Registered Customer Simulation"


@dataclass(slots=True)
class CustomerCheckoutRun:
    stage: StageResult
    order: "OrderConfirmation | None"
    email: str | None


def run(config: "Config", browser: "Browser") -> CustomerCheckoutRun:
    stage = StageResult(name=STAGE_NAME)

    if not config.enable_checkout_simulation:
        tui.run_step(
            stage,
            "Registered customer simulation",
            lambda: (
                Status.SKIP,
                "Skipped: set LAUNCH_ENABLE_CHECKOUT_SIMULATION=true against a test-mode "
                "environment to run this stage.",
            ),
        )
        return CustomerCheckoutRun(stage=stage, order=None, email=None)

    page = browser.page
    assert page is not None
    email = f"customer+{unique_suffix()}@{config.test_customer_email_domain}"
    password = f"Launch-{unique_suffix(12)}!"
    order_box: dict[str, "OrderConfirmation"] = {}

    def step_register():
        common.register_account(page, config, email, password, "Registered", "Contractor")
        return Status.PASS, f"Registered account for {email}."

    tui.run_step(stage, "Create account", step_register)
    if stage.status is Status.FAIL:
        return CustomerCheckoutRun(stage=stage, order=None, email=email)

    def step_login():
        common.login(page, config, email, password)
        return Status.PASS, f"Logged in as {email} with checkout-ready native identity."

    tui.run_step(stage, "Login", step_login)
    if stage.status is Status.FAIL:
        return CustomerCheckoutRun(stage=stage, order=None, email=email)

    def step_browse():
        product = common.find_first_purchasable_product(page, config)
        step_browse.product = product  # type: ignore[attr-defined]
        return Status.PASS, f"Selected product: {product.name} ({product.url})"

    tui.run_step(stage, "Browse and select a product", step_browse)
    if stage.status is Status.FAIL:
        return CustomerCheckoutRun(stage=stage, order=None, email=email)
    product = step_browse.product  # type: ignore[attr-defined]

    def step_add_to_cart():
        common.add_product_to_cart(page, config, product)
        common.go_to_cart(page, config)
        if not common.cart_has_items(page):
            return Status.FAIL, "Cart page did not render the added item and checkout control."
        return Status.PASS, "Product added to cart."

    tui.run_step(stage, "Add product to cart", step_add_to_cart)
    if stage.status is Status.FAIL:
        return CustomerCheckoutRun(stage=stage, order=None, email=email)

    def step_checkout():
        common.proceed_to_checkout(page, config, registered_customer=True)
        common.fill_billing_details(page, email, "Registered", "Contractor")
        common.select_a_shipping_method(page)
        common.pay_with_woopayments_test_card(page, config)
        return Status.PASS, "Checkout, shipping, and payment details entered."

    tui.run_step(stage, "Checkout, shipping, and payment", step_checkout)
    if stage.status is Status.FAIL:
        return CustomerCheckoutRun(stage=stage, order=None, email=email)

    def step_place_order():
        try:
            order = common.place_order(page, config)
        except common.OrderConfirmationPageError as exc:
            order_box["order"] = exc.confirmation
            return Status.FAIL, str(exc)
        else:
            order_box["order"] = order
            return Status.PASS, f"Order confirmed: #{order.order_number} at {order.confirmation_url}"

    tui.run_step(stage, "Place order and confirm", step_place_order)
    if stage.status is Status.FAIL:
        return CustomerCheckoutRun(stage=stage, order=order_box.get("order"), email=email)
    order = order_box["order"]

    def step_logout():
        common.logout(page, config)
        return Status.PASS, "Logged out."

    tui.run_step(stage, "Logout", step_logout)

    def step_login_again():
        common.login(page, config, email, password)
        return Status.PASS, "Logged in again with the same credentials."

    tui.run_step(stage, "Login again", step_login_again)

    def step_order_history():
        if common.order_appears_in_history(page, config, order.order_number):
            return Status.PASS, f"Order #{order.order_number} appears in account order history."
        return Status.WARN, (
            f"Order #{order.order_number} was not found on the account order-history page — "
            "check for propagation delay or a mismatched order-number format."
        )

    tui.run_step(stage, "Verify order history", step_order_history)

    return CustomerCheckoutRun(stage=stage, order=order_box.get("order"), email=email)
