# Drywall Toolbox · Scheduled Engineering Reports

This directory stores immutable, evidence-backed operational reports. Reports inform decisions; they do not replace active source, runtime evidence, enforced contracts, `AGENTS.md`, `.agents/`, or the systems of record.

## Workflows and cadence

| Workflow | Directory | America/Chicago cadence | Decision purpose |
| --- | --- | --- | --- |
| Daily Engineering Briefing | [daily-engineering/](daily-engineering/) | Weekdays, 08:00 | Triage new or materially changed urgent engineering and operational signals. |
| Development Progress Review | [development-progress/](development-progress/) | Weekdays, 17:00 | Establish verified delivery, deployment, runtime, and blocker status since the prior review. |
| Weekly Project Planning | [weekly-planning/](weekly-planning/) | Sundays, 18:00 | Select a realistic, dependency-aware five-day plan from current evidence and capacity constraints. |

The scheduled-task definitions own execution time and recurrence. This table is a human-readable contract and must be kept aligned when those settings change.

## Evidence and decision contract

At each run:

- Re-read this contract, the current `AGENTS.md`, the applicable routing/governance guidance, and the latest report for the same workflow when accessible. Use `.agents/registry.json` to resolve relevant guidance; load only what the task needs.
- Confirm repository identity, current `main` head, and the intended live environment before querying it. Gather bounded, fresh evidence from relevant GitHub source, commits, issues/PRs, Actions runs, and read-only MCP DTB diagnostics where available.
- Compare with the previous report and distinguish genuinely new, changed, resolved, and unchanged findings. Expand investigation only when another check can change a material conclusion.
- For material claims, preserve traceable source links or identifiers, observation time with timezone, environment, result, and limits. Explicitly label unavailable, stale, not checked, or inconclusive evidence. Do not reuse a prior observation as current.
- Keep source changes, CI, staging deployment, staging runtime, production deployment, and production runtime separate. A commit, merge, successful workflow, or point-in-time diagnostic proves only what it directly observed.
- Separate verified fact, user-supplied context, inference, hypothesis, and recommendation. Identify owners only when the evidence verifies them.
- Use connected capabilities autonomously within this read-and-report scope. Do not invent access, evidence, checks, ownership, capacity, or completed work. Do not require private chain-of-thought; make conclusions inspectable through evidence and concise rationale.

## Adaptive report composition

Reports are decision tools, not form submissions. There is no fixed page, word, sentence, or section limit, and no mandatory copy-and-paste layout. Choose the organization and depth that make this run's evidence easiest to understand: lead with the consequential findings, explain why they matter, and include only the context, comparison, risks, decisions, actions, and verification detail needed to support the conclusion. A short no-change report is appropriate when evidence supports it; a longer report is appropriate when material findings need explanation. Do not pad reports to meet a template or omit necessary evidence to meet a length target.

Preserve a readable metadata header and a compact evidence trail. Use tables only when they improve comparison or scanning. Prioritize a small achievable set of actions, state concrete completion checks, and label estimates and uncertainty. Match the report's scope to its purpose: daily triage, verified progress, or weekly planning. Avoid duplicating another report's purpose.

## Public-data and mutation boundaries

- Reports are public artifacts. Exclude customer/order/payment information, credentials, tokens, private operational identifiers, internal URLs, confidential financials, and sensitive diagnostic payloads. Include only the minimum safe evidence needed to support a claim.
- Publishing one unique, timestamped Markdown report in the workflow's directory on `main` is the only permitted mutation. Do not modify application source, configuration, catalog, commerce records, production, deployments, or issues.
- Before writing, verify the target branch and ensure the path does not already exist. Never overwrite history. Use the authenticated GitHub write capability. A successful contents API write is already a remote commit; do not make a redundant push.
- Read the exact published path back from `main` and capture its resulting commit SHA. Then inspect available Actions evidence for that commit and report what ran, what it verified, or `Not triggered`. Do not infer deployment from report publication or absent workflow runs.
- If access, authentication, write, or readback fails, clearly mark publication `FAILED` or `UNVERIFIED`, provide the intended path and the complete report in chat, and never imply success.
- Do not publish an empty report to satisfy cadence. If evidence coverage is too weak for a useful report, state that limitation and leave `main` untouched.

Use a timestamp based on the run's scheduled local time, `YYYY-MM-DD-HHMM-CT.md`, adding a unique suffix only if needed to avoid a collision.

## Run quality review

Evaluate each execution against the evidence available, including failed or unavailable source reads. Record concise evidence for any material failure:

1. Freshness and coverage: current reads were attempted; gaps are explicit.
2. Environment and claim accuracy: each state is labeled only to the level actually verified.
3. Change detection: prior evidence is used for comparison, never as current proof.
4. Publication integrity: unique path, exact readback, and commit SHA are verified before claiming publication.
5. Action evidence: post-publication workflow runs and their scope are reported accurately.
6. Safety: public content is sanitized and no out-of-scope mutation occurred.
7. Decision value: the report serves its specific purpose, and recommendations have clear next checks or acceptance criteria.

A missing capability is a finding about coverage, not a reason to simulate a successful check.
