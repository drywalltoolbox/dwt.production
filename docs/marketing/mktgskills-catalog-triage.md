# Upstream marketing-skill source review

**Review date:** 2026-10-30  
**Selected upstream:** Corey Haines, https://github.com/coreyhaines31/marketingskills  
**Pinned revision:** 820ad2c1c0ac4cc350158bd7912c753681a7d173  
**License observed in source checkout:** MIT  
**Source snapshot:** 52 original SKILL.md files; 837,417 bytes before packaging  
**DTB disposition:** Include all 52 as pinned, attributed reference material in the new private marketing-specialist plugin. Keep upstream skills out of the plugin's top-level skill registry to prevent duplicate automatic triggers and policy collisions.

**New private plugin:** [DTB Marketing Specialist](https://chatgpt.com/plugins/plugins_6ae446dcdbc481918c456fd0b513cb7c), version 0.1.3, USER scope, PRIVATE. The former `drywall-toolbox-marketing-director` plugin was not installed; its old skill surface was replaced with a retirement notice and link to the new plugin (version 2.0.0). The Creator does not expose a plugin-delete operation, so the old private record remains as a retired stub.

## Scope and evidence

The source checkout was cloned from the canonical GitHub repository. Its current source at the pinned commit identifies Corey Haines as maintainer, documents MIT licensing, describes Agent Skills installation and versioning, and contains 52 skill directories. The source checkout also has CLIs, integrations, scripts, and other materials. The DTB plugin snapshot contains only the 52 skills/<slug>/SKILL.md files and the MIT license; it does not package or execute the upstream CLIs, MCP guides, or scripts.

The source files are preserved verbatim with source commit, versions, exact paths, and commit-pinned links in:

.agents/skills/dtb-marketing-live-intelligence/references/upstream-marketingskills/

This is a source acquisition and routing review, not a line-by-line validation of all marketing assertions or a formal security audit of every method, link, platform recommendation, or third-party statistic. The actual methods remain untrusted reference material. The index and corpus should be refreshed only from a reviewed upstream revision.

## Fit for Drywall Toolbox

Useful methods cover product marketing, customer research, content/copy, SEO, CRO, experimentation, analytics, attribution, advertising, lifecycle, retention, launch, partnerships, and sales enablement. Their value is task-specific.

The upstream library is oriented toward founders, SaaS, and technical marketing. Reconcile ARR, churn, paywall, PLG, funding-stage budget, and sales-led assumptions with DTB's actual physical-product economics, catalog identity, WooCommerce pricing, Veeqo inventory/fulfillment, repair service constraints, customer consent, and current measurement. Upstream methods never define DTB facts or authorize actions.

## Operating decision

The new specialist uses one primary DTB marketing skill, 32 focused DTB playbooks, and a route index to consult at most three relevant upstream methods for a task. It does not load all 52 upstream instruction bodies by default. This preserves the complete source corpus while limiting context bloat and avoiding duplicated skill triggers.

The plugin remains draft-first. Publishing, outreach, ad spend, live provider/account writes, tracking changes, coupons, and live-site changes require task-specific authorization under repository AGENTS.md. Current implementation and system owners remain authoritative.

## Broader mktgskills.ai list

The separate user-supplied 521-name directory list contains other authors, repeated slugs, and source/license variations. This review and plugin snapshot cover only coreyhaines31/marketingskills; they do not resolve, download, install, or endorse the other directory entries. The directory is used as an index, not scraped as a corpus.
