"""Regression checks for the public returns identity boundary."""

from __future__ import annotations

import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
RETURNS_CONTROLLER = (
    REPOSITORY_ROOT
    / "drywalltoolbox"
    / "wp"
    / "wp-content"
    / "mu-plugins"
    / "dtb-returns"
    / "Rest"
    / "ReturnsController.php"
)
RETURN_PORTAL = REPOSITORY_ROOT / "frontend" / "src" / "pages" / "ReturnPortal.jsx"


class ReturnsContractTests(unittest.TestCase):
    def test_legacy_public_submit_verifies_woo_order_identity(self) -> None:
        source = RETURNS_CONTROLLER.read_text(encoding="utf-8")
        start = source.index("function dtb_returns_rest_public_submit")
        end = source.index("function dtb_returns_rest_public_status", start)
        handler = source[start:end]

        self.assertIn(
            "dtb_returns_resolve_verified_order( $order_number, $customer_email )",
            handler,
        )
        self.assertIn("$order->get_formatted_billing_full_name()", handler)
        self.assertIn("$order->get_billing_email()", handler)
        self.assertNotIn("$request->get_param( 'customer_name' )", handler)

    def test_storefront_uses_verified_item_level_submit_contract(self) -> None:
        source = RETURN_PORTAL.read_text(encoding="utf-8")

        self.assertIn("/wp-json/dtb/v1/returns/lookup", source)
        self.assertIn("/wp-json/dtb/v1/returns/request/verified", source)


if __name__ == "__main__":
    unittest.main()
