# Audit upstream agent-skills and improve DTB skill governance

## Objective

Thoroughly audit Addy Osmani's `agent-skills` repository against DTB's canonical `.agents/` library, identify compatible methods and integration/security conflicts, then make focused improvements to the existing DTB skill governance and integration.

## Acceptance criteria

- Inspect the upstream source at a pinned revision, including its skill, persona, command, hook, script, reference, evaluation, and host-adapter surfaces.
- Compare its methods against current DTB registry routes, skills, roles, workflows, and policy.
- Preserve DTB authority and avoid importing redundant or conflicting universal instructions.
- Make useful improvements to current DTB skills/validation without introducing a duplicate skill or routing authority.
- Persist evidence, dispositions, changes, verification, and residual limits in this task package.

## Initial audit non-goals

- Installing the upstream plugin, wiring its hooks, running its model-backed evaluations, publishing a plugin update, or changing external accounts.
- Replacing DTB's deterministic registry, adopting vendor instructions, or importing the 25 upstream skills wholesale.

## Deployment addendum (2026-11-15)

The user subsequently authorized deploying all plugin and integration revisions. The existing private marketing specialist plugin was updated in place and read back at version 0.1.7. The repository changes were pushed to `origin/main`. No WordPress runtime/plugin code changed in this task, so no site-server deployment was required.

## Decisions

The repository-specific audit uses upstream `main` commit `39b723ceebf81474d0557c1fecd01eddab585563`, cloned read-only on 2026-11-15. Useful general methods were applied within current DTB owners. No upstream plugin, hook, or model-backed eval runner was installed or executed; four read-only upstream validators were run and are listed in `verification.md`.
