"""Regression checks for the storefront/native-checkout route boundary."""

from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]


class RoutingContractTests(unittest.TestCase):
    def test_staging_native_aliases_share_woo_and_preserve_query_values(self) -> None:
        htaccess = (REPOSITORY_ROOT / "drywalltoolbox" / "staging" / ".htaccess").read_text(
            encoding="utf-8"
        )
        aliases = [
            "RewriteRule ^checkout/?$ /wp/index.php?pagename=checkout [QSA,L]",
            "RewriteRule ^checkout/order-pay/([0-9]+)/?$ /wp/index.php?pagename=checkout&order-pay=$1 [QSA,L]",
            "RewriteRule ^order-pay/([0-9]+)/?$ /wp/index.php?pagename=checkout&order-pay=$1 [QSA,L]",
            "RewriteRule ^checkout/order-received/([0-9]+)/?$ /wp/index.php?pagename=checkout&order-received=$1 [QSA,L]",
        ]
        for alias in aliases:
            with self.subTest(alias=alias):
                self.assertIn(alias, htaccess)
                self.assertLess(htaccess.index(alias), htaccess.index("RewriteRule ^$ index.html [L]"))

    @unittest.skipUnless(shutil.which("php"), "PHP CLI is required for native checkout behavioral checks")
    def test_native_checkout_mount_behavior(self) -> None:
        result = subprocess.run(
            ["php", str(Path(__file__).with_name("native_checkout_mount_contract.php"))],
            check=False,
            capture_output=True,
            text=True,
        )
        self.assertEqual(0, result.returncode, result.stdout + "\n" + result.stderr)
        self.assertIn("native checkout mount contract passed", result.stdout)

    def test_production_build_selects_the_canonical_root_htaccess(self) -> None:
        webpack_config = (
            REPOSITORY_ROOT / "frontend" / "webpack.config.cjs"
        ).read_text(encoding="utf-8")

        self.assertRegex(
            webpack_config,
            re.compile(
                r"from:\s*path\.resolve\(\s*__dirname,\s*'\.\.',\s*"
                r"'drywalltoolbox',[\s\S]*?:\s*'\.htaccess',\s*\)",
            ),
        )

    def test_frontend_build_keeps_cart_in_the_spa(self) -> None:
        htaccess = (REPOSITORY_ROOT / "drywalltoolbox" / ".htaccess").read_text(
            encoding="utf-8"
        )

        self.assertNotRegex(
            htaccess,
            re.compile(r"^\s*RewriteRule\s+\^cart", re.MULTILINE),
        )
        self.assertNotRegex(
            htaccess,
            re.compile(
                r"^\s*RewriteCond\s+%\{REQUEST_URI\}\s+!\^/\([^)]*\bcart\b",
                re.MULTILINE,
            ),
        )

    def test_frontend_build_routes_native_checkout_to_wordpress(self) -> None:
        htaccess = (REPOSITORY_ROOT / "drywalltoolbox" / ".htaccess").read_text(
            encoding="utf-8"
        )

        self.assertRegex(
            htaccess,
            re.compile(
                r"^\s*RewriteRule\s+\^checkout/\?\$\s+"
                r"wp/index\.php\?pagename=checkout\s+\[QSA,L\]$",
                re.MULTILINE,
            ),
        )
        self.assertRegex(
            htaccess,
            re.compile(
                r"^\s*RewriteRule\s+\^checkout/order-received/"
                r"\(\[0-9\]\+\)/\?\$\s+"
                r"wp/index\.php\?pagename=checkout&order-received=\$1\s+"
                r"\[QSA,L\]$",
                re.MULTILINE,
            ),
        )

    def test_provider_payment_intent_returns_reach_wordpress_before_the_spa(self) -> None:
        htaccess = (REPOSITORY_ROOT / "drywalltoolbox" / ".htaccess").read_text(
            encoding="utf-8"
        )

        payment_intent_condition = (
            "RewriteCond %{QUERY_STRING} "
            "(^|&)payment_intent=pi_[^&]+(&|$) [NC]"
        )
        wordpress_rule = "RewriteRule ^$ wp/index.php [QSA,L]"
        homepage_rule = "RewriteRule ^$ index.html [L]"

        self.assertIn(payment_intent_condition, htaccess)
        self.assertIn(wordpress_rule, htaccess)
        self.assertLess(htaccess.index(payment_intent_condition), htaccess.index(homepage_rule))
        self.assertLess(htaccess.index(wordpress_rule, htaccess.index(payment_intent_condition)), htaccess.index(homepage_rule))

    def test_plain_order_received_returns_reach_wordpress_before_the_spa(self) -> None:
        htaccess = (REPOSITORY_ROOT / "drywalltoolbox" / ".htaccess").read_text(
            encoding="utf-8"
        )

        order_received_condition = (
            "RewriteCond %{QUERY_STRING} (^|&)order-received=[0-9]+(&|$) [NC]"
        )
        order_key_condition = (
            "RewriteCond %{QUERY_STRING} (^|&)key=wc_order_[^&]+(&|$) [NC]"
        )
        wordpress_rule = "RewriteRule ^$ wp/index.php [QSA,L]"
        homepage_rule = "RewriteRule ^$ index.html [L]"
        return_position = htaccess.index(order_received_condition)

        self.assertGreaterEqual(return_position, 0)
        self.assertGreater(htaccess.index(order_key_condition, return_position), return_position)
        self.assertLess(htaccess.index(wordpress_rule, return_position), htaccess.index(homepage_rule))

    def test_prelaunch_order_tracking_uses_the_react_storefront_shell(self) -> None:
        htaccess = (REPOSITORY_ROOT / "drywalltoolbox" / ".htaccess").read_text(
            encoding="utf-8"
        )

        tracking_rule = (
            "RewriteRule ^order-tracking/[0-9]+/?$ storefront.html [QSA,L]"
        )
        homepage_rule = "RewriteRule ^$ index.html [L]"

        self.assertIn(tracking_rule, htaccess)
        self.assertLess(htaccess.index(tracking_rule), htaccess.index(homepage_rule))


if __name__ == "__main__":
    unittest.main()

