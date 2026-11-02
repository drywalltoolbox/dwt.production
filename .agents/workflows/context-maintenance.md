# Context Maintenance Workflow

Use for canonical AI-governance changes. `AGENTS.md` is constitutional policy; `.agents/registry.json` is deterministic routing authority; roles/skills/workflows provide reusable model-neutral behavior; derived context is subordinate; vendor adapters remain thin.

## Sequence
1. Inspect current `AGENTS.md`, registry, routing implementation/tests, affected canonical files, and adapter layout.
2. Classify the deficiency: constitutional policy, routing semantics, responsibility, reusable method, workflow, derived context, validation, or adapter mapping.
3. Strengthen/delete existing mechanisms before adding new ones.
4. Update `AGENTS.md` only for repository-wide durable policy/invariants.
5. Update `registry.json` for routing; do not duplicate routing truth in role frontmatter/adapters.
6. Update roles/skills/workflows only when reusable behavior changes.
7. Update derived context only after authoritative sources are correct; keep it concise and provenance-tagged.
8. Update vendor adapters only for discovery/tool/model/capability mapping.
9. Update `.agents/context/connected-systems.md` when durable system ownership,
   connected-interface routing, or live/deployment boundaries change. Keep
   mutable health and permissions dated and revalidated; never store credentials.
10. Check context bloat, duplicate doctrine, semantic write overlap, stale paths, vendor coupling, unreachable assets, and contradictions.
11. Run AI validation/routing tests when execution is available.

## Efficiency
Ordinary resolved task context is `AGENTS.md` plus resolved workflow/role/subject/skills; `.agents/README.md` is not default inference context. Require evidence sufficiency, reuse-before-retrieval, bounded fetching, and isolated reviewer context.

## Exit
Each concern has one authoritative home, machine routing matches constitutional policy, adapters remain subordinate, and no new infrastructure exists without a demonstrated recurring need.
