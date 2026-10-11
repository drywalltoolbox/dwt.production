"""Regression checks for the public authentication rate-limit boundary."""

from __future__ import annotations

import re
import unittest
from pathlib import Path


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
AUTH_ROUTES = (
    REPOSITORY_ROOT
    / "drywalltoolbox"
    / "wp"
    / "wp-content"
    / "mu-plugins"
    / "dtb-platform"
    / "Auth"
    / "AuthRoutes.php"
)
RATE_LIMITER = (
    REPOSITORY_ROOT
    / "drywalltoolbox"
    / "wp"
    / "wp-content"
    / "mu-plugins"
    / "dtb-platform"
    / "Security"
    / "RateLimiter.php"
)


class AuthRateLimitContractTests(unittest.TestCase):
    def test_shared_auth_wrapper_exists_and_uses_canonical_limiter(self) -> None:
        source = RATE_LIMITER.read_text(encoding="utf-8")

        self.assertIn("function dtb_rate_limit(", source)
        self.assertIn("DTB_RateLimiter::check(", source)
        self.assertIn("'auth_login'", source)
        self.assertIn("'auth_register'", source)
        self.assertIn("'auth_forgot_password'", source)
        self.assertIn("'auth_reset_password'", source)
        self.assertIn("Retry-After", source)
        self.assertIn("Cache-Control", source)

    def test_all_public_auth_mutations_enter_a_rate_limit_bucket(self) -> None:
        source = AUTH_ROUTES.read_text(encoding="utf-8")

        expected = {
            "dtb_auth_login": "auth_login",
            "dtb_auth_register": "auth_register",
            "dtb_auth_forgot_password": "auth_forgot_password",
            "dtb_auth_reset_password": "auth_reset_password",
        }
        for handler, bucket in expected.items():
            with self.subTest(handler=handler):
                match = re.search(
                    rf"function {handler}\([^)]*\).*?dtb_rate_limit\(\s*\$request,\s*'{bucket}'\s*\)",
                    source,
                    re.DOTALL,
                )
                self.assertIsNotNone(match)


if __name__ == "__main__":
    unittest.main()
