# Evidence and audit findings

## Scope and provenance

- Upstream: [addyosmani/agent-skills](https://github.com/addyosmani/agent-skills), shallow clone of `main` at `39b723ceebf81474d0557c1fecd01eddab585563` on 2026-11-15.
- Upstream license: MIT. The plugin manifest reports version `0.6.16`.
- The upstream [`AGENTS.md`](https://github.com/addyosmani/agent-skills/blob/39b723ceebf81474d0557c1fecd01eddab585563/AGENTS.md) explicitly limits its instructions to work inside that repository and says its reusable assets are the skills. Those instructions do not govern DTB.
- The repository's [README](https://github.com/addyosmani/agent-skills/blob/39b723ceebf81474d0557c1fecd01eddab585563/README.md) describes a multi-host skill pack. Root `plugin.json` and `.claude-plugin/plugin.json` were inspected; plugin metadata points at the skills and commands. The Codex manifest points discovery at `./skills/` and declares `Interactive`, `Read`, and `Write` capabilities; these declarations do not establish what access a particular host grants. The marketplace manifest sets install policy to `AVAILABLE` and authentication to `ON_INSTALL`. The OpenCode skill entry is a symlink to `../skills/`. These host-specific adapters are not one portable runtime contract.

## Repository inventory

At the audited revision, the tree contains:

- 25 skills (`SKILL.md`, approximately 53,815 whitespace-delimited words), 4 agent personas, 27 command files across three host layouts, 17 documentation files, 10 shared reference files, 121 evaluation assets, 20 JavaScript/shell script files (18 under root `scripts/` plus 2 skill-specific helpers), and 9 files under root `hooks/` (4 hook implementations, 3 shell tests, and 2 documentation files).
- Four read-only upstream validators were executed: `validate-skills.js`, `validate-reference-links.js`, `validate-commands.js`, and `validate-artifact-paths.js`; each passed. Their reported scope was respectively skill/persona metadata and sections, local references, command parity/frontmatter/description sync, and generated artifact locations. These are repository validators, not proof that every workflow is appropriate for DTB.
- Upstream behavioral eval assets include deterministic trigger/ranking and routing-collision cases plus opt-in model-backed execution/dialogue cases. The model-backed runner uses the `claude` CLI, grants a fixture workspace editing/shell/web tools, and writes evaluation artifacts; it was not run.
- Hooks are standalone host integrations, not automatically wired by the inspected plugin manifest. `session-start.sh` injects the meta-skill when a host lacks native discovery. `sdd-cache-pre.sh` and `sdd-cache-post.sh` make `curl` requests to tool-provided URLs and persist cache data under the project. `sdd-cache-post.sh` stores the full fetched response together with its URL and prompt; optional debug logging also records URL/prompt fragments and the first 400 characters of hook input. This can retain sensitive fetched content in project files/logs. None was installed or executed.
- `sdd-cache-pre.sh` follows redirects and performs a request to a URL supplied by the host tool input without a destination allowlist. That creates a local network/SSRF boundary requiring host-specific review before wiring. The source includes a request timeout, but this audit does not establish that arbitrary internal destinations are safe.
- `simplify-ignore.sh` reads a host-supplied `FILE_PATH`, backs up matching files, rewrites them in place to replace marked blocks, then restores/expands them during lifecycle events. Static inspection did not find project-root containment on that path. If wired, it can mutate any host-accessible matching file supplied to the hook and needs explicit path containment, recovery, and concurrency review. It was not run.

## Fit and disposition of every upstream skill

| Upstream skill | DTB coverage / disposition |
|---|---|
| `api-and-interface-design` | Covered by `dtb-module-architecture`, `dtb-integration-engineering`, and API/security invariants in `AGENTS.md`; no new API skill. |
| `browser-testing-with-devtools` | Rendering and responsive verification are covered by the frontend/UI skills. Its Chrome DevTools MCP requirement is host-specific and is not available as a canonical DTB dependency. |
| `ci-cd-and-automation` | Deployment authority and release boundaries are already defined by DTB. Its generic GitHub Actions/stack examples are not DTB deployment procedure. |
| `code-review-and-quality` | Covered by `dtb-engineering-review` and role-specific reviewers. Its unconditional five-axis review and no-exceptions gate conflict with DTB's risk- and flag-based review dimensions. |
| `code-simplification` | Covered by `dtb-refactoring`; no duplicate skill. |
| `constraint-driven-development` | Covered by `AGENTS.md` and scoped validators. Its proposed root `CONSTRAINTS.md`, automated thresholds, and ratchet are not justified and would add mutable policy state. |
| `context-engineering` | Covered by `dtb-ai-context-engineering`; the upstream's compact progressive-disclosure framing is consistent. |
| `debugging-and-error-recovery` | Useful gap in the implementation workflow. Added a bounded root-cause method to `.agents/workflows/implementation.md`, with reproduction/evidence, last-known-good context, hypothesis checks, owning-cause fixes, and same-path verification. |
| `deprecation-and-migration` | Covered by DTB migration, compatibility, and architecture contracts plus `dtb-refactoring`; no new route. |
| `documentation-and-adrs` | Covered by the repository's documentation governance and architecture workflow. |
| `doubt-driven-development` | Adversarial evidence review is already bounded by independent review roles and `dtb-engineering-review`; no permanent second-pass persona. |
| `frontend-ui-engineering` | Covered by `dtb-react-engineering`, `dtb-design-system`, `dtb-responsive-ui-engineering`, and `dtb-ux-flow-engineering`. |
| `git-workflow-and-versioning` | Covered by repository scripts and publication controls; generic commit/push guidance does not override DTB authorization or dirty-worktree handling. |
| `idea-refine` | Useful only for intentionally open-ended ideation; it does not justify a permanent route or a question-first constraint on ordinary tasks. |
| `incremental-implementation` | Covered by DTB's smallest-complete-change, one-writer, and targeted-verification workflow. No mandatory artificial slices. |
| `interview-me` | Not adopted: its 95%-confidence interview loop and mandatory questions conflict with DTB's source-first resolution and user autonomy unless clarification is genuinely necessary. |
| `observability-and-instrumentation` | Covered by `AGENTS.md` and owning integration/domain skills. |
| `performance-optimization` | Covered by React/PHP performance guidance and bounded-resource policy. Its broad caching/threshold suggestions require DTB's measured-constraint gate; no separate skill is needed. |
| `planning-and-task-breakdown` | Covered by registry-resolved workflows and task manifests when substantial work warrants them. |
| `security-and-hardening` | Covered by DTB security policy, `dtb-php-wordpress-quality`, integration engineering, and read-only security review. |
| `shipping-and-launch` | Covered by repository Git/deployment controls. A generic launch checklist cannot authorize publication/deployment. |
| `source-driven-development` | Compatible principle: consult authoritative current docs for volatile semantics. Existing research and implementation workflows already require this when material. |
| `spec-driven-development` | Covered by explicit acceptance criteria, workflow selection, and task manifests; mandatory specs for every feature would add overhead. |
| `test-driven-development` | Not adopted as a universal gate. DTB verification is risk-proportional and follows the task/repository instructions; blanket tests or test-file changes are not always authorized. |
| `using-agent-skills` | Not adopted: it requires skill invocation at even 1% relevance and conflicts with DTB's deterministic registry and evidence-sufficiency stop rule. |

## Security and instruction audit

- The upstream repository-level policy is correctly scoped to its own development process. Several instructions are unsuitable for transplant: unconditional skill use, always-spec/plan/TDD lifecycle, fixed multi-axis review, mandatory interview confidence targets, and model/host-specific command wiring.
- Skill text is generally procedural, with activation descriptions and explicit verification. The pack's validators and trigger/negative examples are useful design patterns, but the runtime evaluator is Claude-specific and model-backed.
- The executable surface has meaningful filesystem/network effects. Since DTB has no need for the upstream hooks, plugin, or eval runner, they were not installed or executed. Only the four read-only structural validators named above were executed. Before any future hook adoption, contain URL destinations, exclude/restrict cache files, avoid storing sensitive fetches/prompts, constrain file paths to the intended project, and review recovery/concurrency behavior in an isolated host-specific setup.
- The separate pinned Corey Haines marketing corpus already present under DTB contains only upstream `SKILL.md` files by declared provenance scope. A static link check found 295 relative references across 42 files, resolving to 202 unique supporting assets omitted from that snapshot. This is a known packaging limitation, separate from Addy Osmani's repository. The pinned source commit is recorded locally; do not claim those supporting files are present in the DTB copy.

## Existing private marketing plugin integration

- Initial read-only Plugin Creator inspection returned the existing private USER-scope plugin `drywall-toolbox-marketing-specialist`, version `0.1.6`, release `pluginrel_6ae44ea7d5788191859fb0238d9c4253`. Its package has 32 curated DTB skills plus the 52 pinned Corey Haines source files below `references/upstream-marketingskills/`; the manifest exposes only `./skills/` for skill discovery. After the user explicitly authorized deployment, the plugin was updated in place to version `0.1.7`, release `pluginrel_6afa864a84448191815c061ef81b3652`; the private audience and plugin identity were retained.
- In the repository checkout, the 52 references previously used the filename `SKILL.md`, so recursive `.agents/skills/**/SKILL.md` discovery exposed them as independent skills despite registry and wrapper policy. Renamed these files to `METHOD.md` without changing their bytes, updated the local index paths and wrapper/provenance docs, and verified all 52 SHA-256 hashes against their prior contents. The private plugin package remains separately rooted at its curated `skills/` directory.
- The local source snapshot has 295 relative references across 42 methods, leading to 202 unique supporting assets absent from the snapshot. The source paths are recoverable from the commit-pinned URL in `PROVENANCE.md`; the missing files are not represented as local content.

## Current DTB library defects found and corrected

1. `prompt-engineering-expert` was a 319-byte Codex-specific discovery adapter, absent from `.agents/registry.json` and referenced nowhere else in the code/governance sources. It duplicated the registered `dtb-ai-context-engineering` route and contradicted the model-neutral canonical-library rule. Removed the orphan.
2. The registered `product-image-art-director` role had no frontmatter `id` or capability metadata. Added its existing read-only role identity and bounded read/fetch/render capabilities so registry and role source agree.
3. `validate-context.mjs` did not validate skill frontmatter or ensure top-level skill directories had exactly one registry entry with a matching registry path. Added name/description syntax, directory match, uniqueness, description-length, and registry-key/path/directory parity checks. The scalar parser rejects duplicate, malformed, empty, and block-style metadata values; frontmatter parsing supports both LF and CRLF. The new check exposed a parser gap and a role metadata omission during verification. It now also rejects nested `SKILL.md` files to prevent reference methods from becoming independent discovery entries again.
4. The implementation workflow lacked an explicit defect-investigation sequence. Added evidence-led symptom/root-cause handling within the existing workflow rather than adding another generic debugging skill.
5. Expanded `dtb-external-skill-security` with repository-instruction scope, executable-code staging, package/link completeness, and a canonical integration boundary. No external skill text or executable dependency was copied into DTB.
6. Renamed the 52 pinned marketing reference files from `SKILL.md` to `METHOD.md` in the `.agents` copy after confirming they bypassed the registry. Verified byte identity, updated source-to-local path mappings, and retained the separately managed private plugin's curated discovery root.

