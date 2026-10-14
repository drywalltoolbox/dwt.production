"""Shared browser-driven customer actions used by both the guest and
registered-customer checkout workflows.

Kept in one place so "add a product to the cart" or "pay with the WooPayments
test card" is implemented once, not duplicated between the two simulations
(they differ only in account creation/login around the same checkout core).

Checkout runs against the real, full-document WooCommerce Checkout Block at
`/checkout/` (see `frontend/src/utils/checkoutUrl.js` and `AGENTS.md` section
6) — this module drives that native page, not a simulated one.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from typing import TYPE_CHECKING
from urllib.parse import parse_qs, urljoin, urlsplit

import requests
from playwright.sync_api import Error as PlaywrightError
from playwright.sync_api import TimeoutError as PlaywrightTimeoutError

if TYPE_CHECKING:
    from playwright.sync_api import Page

    from config import Config


@dataclass(slots=True)
class ProductInfo:
    name: str
    url: str


@dataclass(slots=True)
class OrderConfirmation:
    order_number: str
    order_id: int | None
    confirmation_url: str


class CheckoutFlowError(RuntimeError):
    """Raised when a customer-simulation step cannot complete as expected."""


class OrderConfirmationPageError(CheckoutFlowError):
    """Raised when an order exists but its confirmation document did not render."""

    def __init__(self, message: str, confirmation: OrderConfirmation) -> None:
        super().__init__(message)
        self.confirmation = confirmation


def configured_product(config: "Config") -> ProductInfo | None:
    """Validate and describe an explicitly configured checkout product."""

    if not config.product_url_path:
        return None
    if config.validated_product_name and config.validated_product_url:
        return ProductInfo(
            name=config.validated_product_name,
            url=config.validated_product_url,
        )

    url = resolve_product_url(config)
    slug = urlsplit(url).path.rstrip("/").rsplit("/", 1)[-1]
    try:
        response = requests.get(
            f"{config.api_base}/wc/store/v1/products",
            params={"slug": slug},
            timeout=config.request_timeout_s,
        )
        response.raise_for_status()
        products = response.json()
    except (requests.RequestException, ValueError) as exc:
        raise CheckoutFlowError(
            f"Could not validate LAUNCH_PRODUCT_URL_PATH through the Store API: {exc}"
        ) from exc

    product = next(
        (item for item in products if item.get("slug") == slug),
        None,
    )
    if not product:
        raise CheckoutFlowError(
            "LAUNCH_PRODUCT_URL_PATH does not identify a live WooCommerce product "
            f"(slug: {slug!r})."
        )
    if not product.get("is_purchasable") or not product.get("is_in_stock"):
        raise CheckoutFlowError(
            "LAUNCH_PRODUCT_URL_PATH identifies a product that is not currently "
            f"purchasable and in stock (slug: {slug!r})."
        )

    result = ProductInfo(name=str(product.get("name") or slug), url=url)
    config.validated_product_name = result.name
    config.validated_product_url = result.url
    return result


def resolve_product_url(config: "Config") -> str:
    """Resolve the configured product path without permitting another origin."""

    configured = config.product_url_path.strip()
    if not configured:
        return ""

    url = urljoin(f"{config.site_url}/", configured)
    site = urlsplit(config.site_url)
    product = urlsplit(url)
    if (product.scheme, product.netloc) != (site.scheme, site.netloc):
        raise CheckoutFlowError(
            "LAUNCH_PRODUCT_URL_PATH must be a path or an absolute URL on "
            f"{site.scheme}://{site.netloc}."
        )
    return url


def order_id_from_confirmation_url(url: str) -> int | None:
    """Recognize native Woo and DTB post-checkout confirmation routes."""

    parsed = urlsplit(url)
    path_match = re.search(
        r"(?:order-received|order-tracking|order)/(\d+)(?:/|$)",
        parsed.path,
        re.I,
    )
    if not path_match:
        return None

    order_id = int(path_match.group(1))
    if "/order-tracking/" not in parsed.path.lower():
        return order_id

    query = parse_qs(parsed.query)
    order_key = (query.get("order_key") or [""])[0]
    checkout_complete = (query.get("checkout_complete") or [""])[0]
    if checkout_complete != "1" or not order_key.startswith("wc_order_"):
        return None
    return order_id


def find_first_purchasable_product(page: "Page", config: "Config") -> ProductInfo:
    """Return the first product listed on the Shop page, or the configured one."""

    if config.product_url_path:
        product = configured_product(config)
        assert product is not None
        page.goto(product.url, wait_until="domcontentloaded")
        return product

    page.goto(f"{config.site_url}/products", wait_until="domcontentloaded")
    link = page.locator('a[href*="/products/"]').first
    try:
        link.wait_for(state="visible", timeout=config.browser_timeout_ms)
    except PlaywrightTimeoutError as exc:
        raise CheckoutFlowError("No product links found on the Shop page.") from exc

    href = link.get_attribute("href") or ""
    name = (link.get_attribute("aria-label") or link.inner_text() or "product").strip()
    url = href if href.startswith("http") else f"{config.site_url}{href}"
    return ProductInfo(name=name or "product", url=url)


def add_product_to_cart(page: "Page", config: "Config", product: ProductInfo) -> None:
    page.goto(product.url, wait_until="domcontentloaded")
    add_to_cart = page.get_by_role("button", name=re.compile("add to cart", re.I)).first
    add_to_cart.wait_for(state="visible", timeout=config.browser_timeout_ms)
    add_to_cart.click()
    # Confirmation is either an inline toast/badge update or a cart-drawer open;
    # give the storefront a moment to reflect the new cart state before we read it.
    page.wait_for_timeout(1500)


def go_to_cart(page: "Page", config: "Config") -> None:
    page.goto(f"{config.site_url}/cart", wait_until="domcontentloaded")


def cart_has_items(page: "Page") -> bool:
    body = page.inner_text("body").strip().lower()
    if not body:
        return False
    if "your cart is empty" in body or "cart is empty" in body:
        return False
    return page.locator('a[href*="/checkout"]').count() > 0


def proceed_to_checkout(
    page: "Page",
    config: "Config",
    *,
    registered_customer: bool = False,
) -> None:
    handoff = page.evaluate(
        """async ({ registeredCustomer }) => {
            const request = async (url, options = {}) => {
                const response = await fetch(url, {
                    credentials: 'include',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'Cache-Control': 'no-store',
                        Pragma: 'no-cache',
                        ...(options.headers || {}),
                    },
                    ...options,
                });
                const data = await response.json().catch(() => null);
                return { ok: response.ok, status: response.status, data };
            };

            let auth = null;
            if (registeredCustomer) {
                auth = await request('/wp-json/dtb/v1/auth/validate', { method: 'POST' });
            }
            const cart = await request('/wp-json/wc/store/v1/cart');
            return { auth, cart };
        }""",
        {"registeredCustomer": registered_customer},
    )

    cart_response = handoff.get("cart") or {}
    cart_data = cart_response.get("data") or {}
    if not cart_response.get("ok") or not cart_data.get("items"):
        raise CheckoutFlowError(
            "The Store API did not confirm an authoritative cart before checkout."
        )

    if registered_customer:
        auth_response = handoff.get("auth") or {}
        auth_data = auth_response.get("data") or {}
        native_checkout = (auth_data.get("session") or {}).get("native_checkout") or {}
        native_status = str(native_checkout.get("status") or "unknown")
        if (
            not auth_response.get("ok")
            or not auth_data.get("user")
            or native_checkout.get("ready") is not True
            or native_status not in {"aligned", "bridged"}
            or native_checkout.get("identity_conflict_contained") is True
        ):
            raise CheckoutFlowError(
                "The registered WordPress/Woo checkout identity is not ready "
                f"(status={native_status!r}, ready={native_checkout.get('ready')!r})."
            )

    page.goto(f"{config.site_url}/checkout/", wait_until="domcontentloaded")

    # WooPayments and Woo Blocks keep background connections active, so
    # `networkidle` is not a reliable checkout-readiness signal.
    page.locator("#email, .wc-block-checkout").first.wait_for(
        state="visible",
        timeout=config.browser_timeout_ms * 2,
    )


def _fill_first_matching(page: "Page", selectors: list[str], value: str) -> bool:
    for selector in selectors:
        locator = page.locator(selector).first
        try:
            if locator.count() and locator.is_visible():
                locator.fill(value)
                return True
        except PlaywrightTimeoutError:
            continue
    return False


def fill_billing_details(page: "Page", email: str, first_name: str, last_name: str) -> None:
    """Fill DTB contact fields and the native Checkout Block shipping address."""

    fields: list[tuple[list[str], str]] = [
        (["#email", 'input[name="email"]'], email),
        (
            [
                "#contact-dtb-first_name",
                'input[name="contact_dtb/first_name"]',
                "#shipping-first_name",
                "#billing-first_name",
                "#billing_first_name",
            ],
            first_name,
        ),
        (
            [
                "#contact-dtb-last_name",
                'input[name="contact_dtb/last_name"]',
                "#shipping-last_name",
                "#billing-last_name",
                "#billing_last_name",
            ],
            last_name,
        ),
        (["#shipping-address_1", "#billing-address_1", "#billing_address_1"], "123 Contractor Way"),
        (["#shipping-city", "#billing-city", "#billing_city"], "Chicago"),
        (["#shipping-postcode", "#billing-postcode", "#billing_postcode"], "60601"),
        (
            [
                "#contact-dtb-phone",
                'input[name="contact_dtb/phone"]',
                "#shipping-phone",
                "#billing-phone",
                "#billing_phone",
            ],
            "3125551234",
        ),
    ]
    missing = []
    for selectors, value in fields:
        if not _fill_first_matching(page, selectors, value):
            missing.append(selectors[0])

    _select_state(page, "IL")

    if missing:
        raise CheckoutFlowError(f"Could not locate checkout fields: {', '.join(missing)}")


def _select_state(page: "Page", state_code: str) -> None:
    for selector in ["#shipping-state", "#billing-state", "#billing_state"]:
        locator = page.locator(selector).first
        if not locator.count():
            continue
        try:
            tag = locator.evaluate("el => el.tagName.toLowerCase()")
        except PlaywrightTimeoutError:
            continue
        try:
            if tag == "select":
                locator.select_option(state_code)
            else:
                locator.click()
                page.get_by_role("option", name=re.compile(rf"^{state_code}\b|Illinois", re.I)).first.click()
            return
        except PlaywrightTimeoutError:
            continue


def select_a_shipping_method(page: "Page") -> None:
    option = page.locator('input[type="radio"][name*="shipping"]').first
    try:
        if option.count() and option.is_visible():
            option.check()
            page.wait_for_timeout(500)
    except PlaywrightTimeoutError:
        pass  # A single free-shipping method may already be pre-selected with no radio group.


def pay_with_woopayments_test_card(page: "Page", config: "Config") -> None:
    """Fill the WooPayments card fields inside its secure iframe with a test card."""

    gateway = page.locator(
        '#radio-control-wc-payment-method-options-woocommerce_payments, '
        'input[type="radio"][value="woocommerce_payments"]'
    ).first
    try:
        if gateway.count() and not gateway.is_checked():
            gateway.check()
    except PlaywrightTimeoutError:
        pass

    frame = page.frame_locator('iframe[title*="Secure payment input frame"]').first
    try:
        card_method = frame.get_by_role("button", name=re.compile(r"^Card$", re.I)).first
        card_method.wait_for(state="visible", timeout=config.browser_timeout_ms)
        card_method.click()

        number = frame.locator(
            'input[name="number"], input[autocomplete="cc-number"], input[aria-label*="card number" i]'
        ).first
        number.wait_for(state="visible", timeout=config.browser_timeout_ms)
        number.fill(config.test_card_number)

        frame.locator(
            'input[name="expiry"], input[autocomplete="cc-exp"], input[aria-label*="expiration" i]'
        ).first.fill(config.test_card_exp)
        frame.locator(
            'input[name="cvc"], input[autocomplete="cc-csc"], input[aria-label*="security code" i]'
        ).first.fill(config.test_card_cvc)
        zip_field = frame.locator(
            'input[name="postalCode"], input[autocomplete="postal-code"], '
            'input[aria-label*="postal" i], input[aria-label*="ZIP" i]'
        ).first
        if zip_field.count():
            zip_field.fill(config.test_card_zip)
    except PlaywrightTimeoutError as exc:
        raise CheckoutFlowError(
            "Could not fill the WooPayments card fields — the storefront's WooPayments "
            "iframe markup may not match the suite's selectors and needs tuning."
        ) from exc


def place_order(page: "Page", config: "Config") -> OrderConfirmation:
    place_order_button = page.get_by_role("button", name=re.compile("place order", re.I)).first
    place_order_button.wait_for(state="visible", timeout=config.browser_timeout_ms)

    try:
        with page.expect_response(
            lambda response: (
                response.request.method == "POST"
                and "/wp-json/wc/store/v1/checkout" in response.url
            ),
            timeout=config.browser_timeout_ms * 4,
        ) as checkout_request:
            place_order_button.click()
        checkout_response = checkout_request.value
    except PlaywrightTimeoutError as exc:
        raise CheckoutFlowError(
            "WooCommerce did not return a Store API checkout response after Place Order. "
            "No retry should be attempted until existing orders are checked."
        ) from exc

    try:
        checkout_payload = checkout_response.json()
    except (PlaywrightError, ValueError):
        checkout_payload = {}

    response_order_id = int(checkout_payload.get("order_id") or 0)
    response_order_number = str(checkout_payload.get("order_number") or response_order_id or "")
    payment_result = checkout_payload.get("payment_result") or {}
    redirect_url = str(payment_result.get("redirect_url") or "")

    try:
        page.wait_for_url(
            re.compile(r"/(?:checkout/order-received|order-tracking|thank-you)/", re.I),
            wait_until="domcontentloaded",
            timeout=config.browser_timeout_ms * 4,
        )
    except PlaywrightTimeoutError as exc:
        if response_order_id > 0:
            confirmation = OrderConfirmation(
                order_number=response_order_number,
                order_id=response_order_id,
                confirmation_url=redirect_url or page.url,
            )
            payment_status = str(payment_result.get("payment_status") or "unknown")
            raise OrderConfirmationPageError(
                f"Order #{response_order_number} was created by WooCommerce, but the browser "
                "did not reach its confirmation page "
                f"(payment_status={payment_status!r}, current_url={page.url}).",
                confirmation,
            ) from exc
        raise CheckoutFlowError(
            "WooCommerce returned from checkout without an order ID, and the browser did not "
            "reach an order-confirmation page. Do not retry until existing orders are checked."
        ) from exc

    url = page.url
    order_id = order_id_from_confirmation_url(url)
    if order_id is None and not re.search(r"thank-you", url, re.I):
        raise CheckoutFlowError(f"Checkout navigated to an unexpected post-payment URL: {url}")

    body = page.inner_text("body")
    number_match = re.search(r"order\s*(?:number|#)\s*[:#]?\s*([A-Za-z0-9-]+)", body, re.I)
    order_number = number_match.group(1) if number_match else (str(order_id) if order_id else "")

    if not order_number:
        raise CheckoutFlowError("Order confirmation page did not expose an order number.")

    confirmation = OrderConfirmation(order_number=order_number, order_id=order_id, confirmation_url=url)
    if checkout_response.status >= 400:
        raise OrderConfirmationPageError(
            f"Order #{order_number} was created, but the Store API checkout request returned "
            f"HTTP {checkout_response.status} at {url}",
            confirmation,
        )

    return confirmation


def register_account(
    page: "Page",
    config: "Config",
    email: str,
    password: str,
    first_name: str,
    last_name: str,
) -> None:
    page.goto(f"{config.site_url}/register", wait_until="networkidle")
    if not _fill_first_matching(page, ["#signup-email", 'input[name="email"]', "#email"], email):
        raise CheckoutFlowError("Registration form email field not found.")
    if not _fill_first_matching(
        page,
        ["#signup-first-name", 'input[autocomplete="given-name"]', 'input[name="first_name"]'],
        first_name,
    ):
        raise CheckoutFlowError("Registration form first-name field not found.")
    if not _fill_first_matching(
        page,
        ["#signup-last-name", 'input[autocomplete="family-name"]', 'input[name="last_name"]'],
        last_name,
    ):
        raise CheckoutFlowError("Registration form last-name field not found.")
    if not _fill_first_matching(
        page, ["#signup-password", 'input[name="password"]', "#password", 'input[type="password"]'], password
    ):
        raise CheckoutFlowError("Registration form password field not found.")
    if not _fill_first_matching(
        page,
        ["#signup-confirm-password", 'input[name="confirm_password"]', 'input[autocomplete="new-password"]:nth-of-type(2)'],
        password,
    ):
        raise CheckoutFlowError("Registration form password-confirmation field not found.")
    submit = page.get_by_role("button", name=re.compile("register|create account|sign up", re.I)).first
    submit.click()
    page.wait_for_load_state("networkidle")


def login(page: "Page", config: "Config", email: str, password: str) -> None:
    page.goto(f"{config.site_url}/login", wait_until="networkidle")
    if not _fill_first_matching(page, ['input[name="email"]', "#email", 'input[type="email"]'], email):
        raise CheckoutFlowError("Login form email field not found.")
    if not _fill_first_matching(page, ['input[name="password"]', "#password", 'input[type="password"]'], password):
        raise CheckoutFlowError("Login form password field not found.")
    submit = page.get_by_role("button", name=re.compile("log ?in|sign in", re.I)).first
    submit.click()
    page.wait_for_load_state("networkidle")

    # Clicking Submit is not proof that the HttpOnly DTB cookie converged with
    # native WordPress identity. Match the storefront's post-login validation
    # contract before reporting this step as successful.
    last_state: dict = {}
    for attempt in range(5):
        last_state = page.evaluate(
            """async () => {
                const response = await fetch('/wp-json/dtb/v1/auth/validate', {
                    method: 'POST',
                    credentials: 'include',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'Cache-Control': 'no-store',
                        Pragma: 'no-cache',
                    },
                });
                const data = await response.json().catch(() => null);
                return { ok: response.ok, status: response.status, data };
            }"""
        )
        data = last_state.get("data") or {}
        native = (data.get("session") or {}).get("native_checkout") or {}
        if (
            last_state.get("ok")
            and data.get("authenticated") is True
            and data.get("user")
            and native.get("ready") is True
            and native.get("status") in {"aligned", "bridged"}
        ):
            return
        if attempt < 4:
            page.wait_for_timeout(250 * (attempt + 1))

    data = last_state.get("data") or {}
    native = (data.get("session") or {}).get("native_checkout") or {}
    raise CheckoutFlowError(
        "Login submission did not establish a checkout-ready customer session "
        f"(authenticated={data.get('authenticated')!r}, "
        f"status={native.get('status', 'unknown')!r}, ready={native.get('ready')!r})."
    )


def logout(page: "Page", config: "Config") -> None:
    logout_link = page.get_by_role("link", name=re.compile("log ?out|sign out", re.I)).first
    try:
        logout_link.wait_for(state="visible", timeout=5_000)
        logout_link.click()
        page.wait_for_load_state("networkidle")
        return
    except PlaywrightTimeoutError:
        pass
    page.goto(f"{config.site_url}/wp-login.php?action=logout", wait_until="networkidle")


def order_appears_in_history(page: "Page", config: "Config", order_number: str) -> bool:
    page.goto(f"{config.site_url}/dashboard?tab=orders", wait_until="networkidle")
    page.wait_for_timeout(1000)
    body = page.inner_text("body")
    return order_number in body
