# Drywall Toolbox · Scheduled Engineering Reports

This directory contains **derived, informational** engineering reports. It does not supersede `AGENTS.md`, `.agents/`, active code, automated tests, or observed production behavior.

## Report channels

| Workflow | Directory | Cadence (America/Chicago) |
| --- | --- | --- |
| Daily Engineering Briefing | [daily-engineering/](daily-engineering/) | Weekdays, 08:00 |
| Development Progress Review | [development-progress/](development-progress/) | Weekdays, 17:00 |
| Weekly Project Planning | [weekly-planning/](weekly-planning/) | Sundays, 18:00 |

Each successful run publishes one immutable Markdown artifact to its channel using `YYYY-MM-DD-HHMM-CT.md` (local scheduled time, with a unique suffix if needed). Reports must never overwrite historical entries. GitHub commit timestamps and report timestamps distinguish the observed snapshot from publication time.

## Report contract

1. **Executive summary** — 3–6 sentences, actionable and readable on mobile.
2. **Metadata** — workflow name, reporting window, collection/publication timestamps (ISO 8601 including offset), timezone, branch, observed commit SHA, observed server identity, data-source freshness, and coverage limitations.
3. **Operational scorecard** — state/priority and concise evidence for repository, issues/PRs, CI, production, catalog, integrations, and deployment as applicable. Use `Not checked` and `Unknown`, never guessed green statuses.
4. **Verified changes since last report** — evidence-backed commit/PR links and deployment status *separately*.
5. **Risks, blockers, and recommendations** — severity, impact, evidence, recommended action, proposed owner (label unassigned if not verified), and acceptance criteria.
6. **Next actions** — rank up to five by operational value, prerequisites, and achievable scope.
7. **Verification ledger** — source-specific checks, observed time, result and gaps.

## Publication contract

- Read `AGENTS.md`, applicable `.agents/` governance, and the latest prior report if accessible.
- Collect fresh repository evidence at execution time; attempt live WordPress/WooCommerce diagnostics where relevant and accessible. Clearly distinguish observed live data from cached, prior-chat, or historical observations.
- Only publish **sanitized, public-repository-safe** material: no customer identifiers, private order information, credentials, security tokens, internal URLs, precise infrastructure secrets, confidential financials, or sensitive diagnostic payloads.
- Report publishing is the **only** permitted mutation. Never change production code, catalog data, WordPress configuration, commerce records, secrets, or deployment state.
- Publish under this directory on `main` using GitHub's authenticated write tools. Re-read the target/ref before write; use a unique run path and fail safely on conflicts. Ensure a successful commit SHA, then read back the file from `main` before claiming publication. Do not attempt a local `git push` after a successful GitHub API commit: that commit is already on the remote branch.
- If the scheduled runtime cannot access GitHub, cannot authenticate, or cannot write, **do not claim success**; return the complete report in ChatGPT and a clear `Publication failed / unavailable` notice with the intended path.
- No fabricated links, evidence, test executions, screenshots, CI results, or production deployments.
- Do not write empty / hallucinated reports merely to satisfy a schedule. If meaningful sources are unavailable, report the coverage failure and leave `main` untouched.

## Suggested filename examples

`daily-engineering/2026-11-03-0800-CT.md`  
`development-progress/2026-11-02-1700-CT.md`  
`weekly-planning/2026-11-08-1800-CT.md`

Reports are snapshots, not the authoritative task tracker. A scheduled-task prompt is **not** proof of unattended tool or GitHub write capability: verify with a real scheduled run and its resulting remote commit.
