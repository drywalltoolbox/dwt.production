#!/usr/bin/env python3
"""Resolve a validated GitHub Actions staging build for manual publication.

Uses only the calling workflow's short-lived GITHUB_TOKEN. Does not deploy.
"""
import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request

SHA = re.compile(r"^[a-f0-9]{40}$")
RUN_ID = re.compile(r"^[1-9][0-9]*$")
REPO = "drywalltoolbox/dwt.production"
BUILD_WORKFLOW = "staging-frontend-build.yml"


def api(path):
    url = f"https://api.github.com/repos/{REPO}/{path}"
    request = urllib.request.Request(url, headers={
        "Authorization": f"Bearer {os.environ['GH_TOKEN']}",
        "Accept": "application/vnd.github+json",
        "X-GitHub-Api-Version": "2022-11-28",
        "User-Agent": "dtb-staging-release-resolver",
    })
    with urllib.request.urlopen(request, timeout=20) as response:
        return json.load(response)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--source-sha", default="")
    parser.add_argument("--build-run-id", default="")
    args = parser.parse_args()

    if os.environ.get("GITHUB_REPOSITORY") != REPO:
        raise ValueError("Unexpected GitHub repository")
    if not os.environ.get("GH_TOKEN"):
        raise ValueError("Missing GitHub Actions token")
    if bool(args.source_sha) != bool(args.build_run_id):
        raise ValueError("Provide both source SHA and build run ID, or neither")
    if args.source_sha and not SHA.fullmatch(args.source_sha):
        raise ValueError("Invalid source SHA")
    if args.build_run_id and not RUN_ID.fullmatch(args.build_run_id):
        raise ValueError("Invalid build run ID")

    workflow = api(f"actions/workflows/{BUILD_WORKFLOW}")
    if args.build_run_id:
        run = api(f"actions/runs/{args.build_run_id}")
        if run.get("head_sha") != args.source_sha:
            raise ValueError("Build run SHA differs from requested release")
    else:
        # Never silently fall back to an older successful build.
        runs = api(f"actions/workflows/{BUILD_WORKFLOW}/runs?branch=main&per_page=1").get("workflow_runs", [])
        if not runs:
            raise ValueError("No staging build exists for main")
        run = runs[0]

    sha = run.get("head_sha", "")
    run_id = run.get("id")
    if (run.get("workflow_id") != workflow.get("id")
            or run.get("head_branch") != "main"
            or run.get("status") != "completed"
            or run.get("conclusion") != "success"
            or run.get("event") not in ("push", "workflow_dispatch")
            or run.get("head_repository", {}).get("full_name") != REPO
            or not isinstance(sha, str) or not SHA.fullmatch(sha)
            or not isinstance(run_id, int) or run_id <= 0):
        raise ValueError("Build run does not satisfy staging publication requirements")

    artifacts = api(f"actions/runs/{run_id}/artifacts?per_page=100").get("artifacts", [])
    matches = [a for a in artifacts if a.get("name") == f"dtb-staging-{sha}"
               and not a.get("expired") and a.get("size_in_bytes", 0) > 0
               and a.get("workflow_run", {}).get("id") == run_id]
    if len(matches) != 1:
        raise ValueError("Expected exactly one unexpired validated staging artifact")

    with open(os.environ["GITHUB_ENV"], "a", encoding="utf-8") as env:
        env.write(f"RELEASE_SHA={sha}\nBUILD_RUN_ID={run_id}\n")
    print(f"Validated staging build run {run_id} for source {sha}")


if __name__ == "__main__":
    try:
        main()
    except (ValueError, KeyError, urllib.error.URLError, TimeoutError) as exc:
        print(f"::error::Staging build resolution failed: {exc}", file=sys.stderr)
        sys.exit(2)
