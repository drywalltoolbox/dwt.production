"""Stage 3 — Guest Checkout Simulation.

Browse -> Product -> Add to Cart -> Cart -> Checkout -> Shipping -> Payment
-> Order Confirmation, exactly as an unauthenticated customer would.
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

STAGE_NAME = "Guest Checkout Simulation"


@dataclass(slots=True)
class GuestCheckoutRun:
    stage: StageResult
    order: "OrderConfirmation | None"
    email: str | None


def run(config: "Config", browser: "Browser") -> GuestCheckoutRun:
    stage = StageResult(name=STAGE_NAME)

    if not config.enable_checkout_simulation:
        tui.run_step(
            stage,
            "Guest checkout simulation",
            lambda: (
                Status.SKIP,
                "Skipped: set LAUNCH_ENABLE_CHECKOUT_SIMULATION=true against a test-mode "
                "environment to run this stage.",
            ),
        )
        return GuestCheckoutRun(stage=stage, order=None, email=None)

    page = browser.page
    assert page is not None
    email = f"guest+{unique_suffix()}@{config.test_customer_email_domain}"
    order_box: dict[str, "OrderConfirmation"] = {}

    def step_browse():
        product = common.find_first_purchasable_product(page, config)
        step_browse.product = product  # type: ignore[attr-defined]
        return Status.PASS, f"Selected product: {product.name} ({product.url})"

    tui.run_step(stage, "Browse to a product", step_browse)
    if stage.status is Status.FAIL:
        return GuestCheckoutRun(stage=stage, order=None, email=email)
    product = step_browse.product  # type: ignore[attr-defined]

    def step_add_to_cart():
        common.add_product_to_cart(page, config, product)
        return Status.PASS, "Clicked Add to Cart."

    tui.run_step(stage, "Add product to cart", step_add_to_cart)

    def step_cart():
        common.go_to_cart(page, config)
        if not common.cart_has_items(page):
            return Status.FAIL, "Cart page did not render the added item and checkout control."
        return Status.PASS, "Cart contains the added product."

    tui.run_step(stage, "View cart", step_cart)
    if stage.status is Status.FAIL:
        return GuestCheckoutRun(stage=stage, order=None, email=email)

    def step_checkout_navigation():
        common.proceed_to_checkout(page, config)
        if "checkout" not in page.url:
            return Status.FAIL, f"Expected to land on the checkout page, got {page.url}"
        return Status.PASS, f"Reached checkout at {page.url}"

    tui.run_step(stage, "Proceed to checkout", step_checkout_navigation)
    if stage.status is Status.FAIL:
        return GuestCheckoutRun(stage=stage, order=None, email=email)

    def step_shipping():
        common.fill_billing_details(page, email, "Guest", "Contractor")
        common.select_a_shipping_method(page)
        return Status.PASS, f"Filled billing/shipping details for {email}."

    tui.run_step(stage, "Enter shipping details", step_shipping)
    if stage.status is Status.FAIL:
        return GuestCheckoutRun(stage=stage, order=None, email=email)

    def step_payment():
        common.pay_with_woopayments_test_card(page, config)
        return Status.PASS, "Entered WooPayments test card in the Payment Element."

    tui.run_step(stage, "Enter payment details", step_payment)
    if stage.status is Status.FAIL:
        return GuestCheckoutRun(stage=stage, order=None, email=email)

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

    return GuestCheckoutRun(stage=stage, order=order_box.get("order"), email=email)
