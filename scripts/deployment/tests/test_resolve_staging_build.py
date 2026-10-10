"""Unit tests for the staging build resolver (no network calls)."""
import importlib.util
import os
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[1] / "resolve-staging-build.py"
spec = importlib.util.spec_from_file_location("staging_resolver", SCRIPT)
resolver = importlib.util.module_from_spec(spec)
spec.loader.exec_module(resolver)

SHA = "8998ace04c6a5caa2cf7f0414f084050bbf60949"
RUN = {
    "id": 38022157842,
    "workflow_id": 7,
    "head_sha": SHA,
    "head_branch": "main",
    "status": "completed",
    "conclusion": "success",
    "event": "push",
    "head_repository": {"full_name": resolver.REPO},
}
ARTIFACT = {
    "name": f"dtb-staging-{SHA}",
    "expired": False,
    "size_in_bytes": 100,
    "workflow_run": {"id": RUN["id"]},
}


def mock_api(path):
    if path == "actions/workflows/staging-frontend-build.yml":
        return {"id": 7}
    if path.endswith("/runs?branch=main&per_page=1"):
        return {"workflow_runs": [RUN]}
    if path == f"actions/runs/{RUN['id']}":
        return RUN
    if path == f"actions/runs/{RUN['id']}/artifacts?per_page=100":
        return {"artifacts": [ARTIFACT]}
    raise AssertionError(f"Unexpected API request: {path}")


class ResolverTests(unittest.TestCase):
    def invoke(self, *arguments, api=mock_api):
        with tempfile.TemporaryDirectory() as tmp:
            output = str(Path(tmp) / "github-env")
            with patch.dict(os.environ, {
                "GH_TOKEN": "test-only",
                "GITHUB_REPOSITORY": resolver.REPO,
                "GITHUB_ENV": output,
            }), patch.object(sys, "argv", ["resolver", *arguments]), patch.object(resolver, "api", side_effect=api):
                resolver.main()
            return Path(output).read_text(encoding="utf-8")

    def test_auto_selects_latest_validated_build(self):
        result = self.invoke()
        self.assertIn(f"RELEASE_SHA={SHA}\n", result)
        self.assertIn(f"BUILD_RUN_ID={RUN['id']}\n", result)

    def test_explicit_pinned_build(self):
        result = self.invoke("--source-sha", SHA, "--build-run-id", str(RUN["id"]))
        self.assertIn(f"RELEASE_SHA={SHA}\n", result)

    def test_partial_inputs_rejected(self):
        with self.assertRaisesRegex(ValueError, "both"):
            self.invoke("--source-sha", SHA)

    def test_latest_failed_build_rejected(self):
        def failed(path):
            if path.endswith("/runs?branch=main&per_page=1"):
                return {"workflow_runs": [{**RUN, "conclusion": "failure"}]}
            return mock_api(path)
        with self.assertRaisesRegex(ValueError, "requirements"):
            self.invoke(api=failed)

    def test_expired_artifact_rejected(self):
        def expired(path):
            if path.endswith("/artifacts?per_page=100"):
                return {"artifacts": [{**ARTIFACT, "expired": True}]}
            return mock_api(path)
        with self.assertRaisesRegex(ValueError, "unexpired"):
            self.invoke(api=expired)


if __name__ == "__main__":
    unittest.main()
