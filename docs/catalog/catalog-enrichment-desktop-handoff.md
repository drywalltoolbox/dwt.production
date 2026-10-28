# Official Catalog Enrichment Desktop Handoff

## Purpose

Continue the Drywall Toolbox official-catalog enrichment and optimization workflow on another desktop without losing taxonomy decisions, changing protected identifiers, or processing an outdated catalog.

Repository: `C:\Users\Elliott\launch-dtb`

## Transfer baseline

At handoff creation:

- branch: `main`;
- local and remote commit: `843491aef56adbbedf3a7510eef9f111fdc10e5f` (`Update - Checkpoint`);
- `main` and `origin/main` are synchronized;
- the source worktree was clean before this handoff document was added;
- canonical catalog: `products/launch/official/dtb_official_catalog.csv`;
- current catalog SHA-256: `667b3929e049f67a284f7db74d190407e28c4829212be7080dc7844360347758`.

The committed `run-summary.json` was generated immediately before the checkpoint commit, so its `repository_commit` records the preceding commit. Its before/after catalog hashes match the canonical hash above, and the run was non-mutating.

## Confirmed catalog state

- 755 total catalog rows;
- 442 owner rows (simple and variable products);
- 313 variation rows;
- 100% of owner rows have canonical category and display-category metadata;
- every variation inherits its exact parent taxonomy tuple;
- taxonomy normalization preview reports 0 changes and 0 unresolved rows;
- 649/649 item-owning rows have an MPN;
- 754/755 rows have images;
- 755/755 rows have structured specifications;
- the latest catalog test run passed 87 tests.

The universal taxonomy is brand-neutral and function-based. Category paths contain separate parent and leaf terms. For example:

`Drywall Finishing Tools > Semi-Automatic Tools > Compound Tubes`

Do not flatten this as `Semi-Automatic Compound Tubes`. Do not copy a retailer's complete category tree, and do not create brand-specific category branches.

## System authority and protected data

- `products/` owns canonical catalog source material.
- `scripts/catalog/` owns deterministic catalog tooling.
- WooCommerce owns runtime product and variation records after reviewed import.
- `Meta: _dtb_category_key` and `Meta: _dtb_display_category_key` are derived from the canonical category path.
- Variations must retain their parent relationship and exact parent taxonomy tuple.

Never change SKU, MPN, GTIN, part number, brand identity, taxonomy identity, compatibility IDs, parent/variation identity, or external provider IDs as incidental cleanup. Never invent compatibility, specifications, product claims, identifiers, or image URLs.

## Start on the destination desktop

Use PowerShell from the repository root.

```powershell
cd C:\Users\Elliott\launch-dtb
git status --short
git fetch origin
git pull --ff-only
git rev-parse HEAD
```

The expected starting commit is the checkpoint containing this handoff document. Stop if `git status` shows unexpected local changes or if a non-fast-forward update would be required.

Create the ignored local Python environment if it is absent:

```powershell
python -m venv products\dev\catalog-enrichment\.venv
products\dev\catalog-enrichment\.venv\Scripts\python.exe -m pip install -r scripts\catalog\requirements-dev.txt
```

Only `products/dev/catalog-enrichment/.venv/` is ignored. Current reports and results under `products/dev/catalog-enrichment/` are intentionally tracked.

## Required baseline validation

Run these checks before modifying catalog data:

```powershell
products\dev\catalog-enrichment\.venv\Scripts\python.exe scripts\catalog\catalog_preflight.py --profile all
products\dev\catalog-enrichment\.venv\Scripts\python.exe scripts\catalog\validate_official_catalog.py
products\dev\catalog-enrichment\.venv\Scripts\python.exe -m pytest scripts\catalog\tests -q
```

Expected strict validation:

- 755 rows;
- 442 owners;
- 313 variations.

If these values differ, investigate the source change before running enrichment or applying fixes.

## Current tracked results

Current results are under `products/dev/catalog-enrichment/`:

- `run-summary.json`;
- `catalog-enrichment-audit.json`;
- `catalog-remediation.csv`;
- `seo-pre-generation/`;
- `content-review/`;
- `compatibility/`.

Do not accumulate timestamped, intermediate, superseded, or legacy reports here. Regenerate the stable current filenames through their owning workflows.

## Remaining work

The latest audit contains 315 actionable findings:

1. 314 part-family rows lack reviewed compatibility or replacement relationships.
2. One catalog row lacks an image.

The compatibility workflow currently produces:

- 422 exact proposal rows covering 236 unique parts and 27 schematics;
- 2,764 unresolved evidence rows;
- unresolved reasons currently include 1,611 part identifiers absent from the catalog and 1,153 unresolved schematic identities.

These are proposals, not approved catalog mutations. Continue by:

1. identifying the single missing-image SKU from `catalog-remediation.csv`;
2. resolving it only if an authoritative existing asset is available;
3. reviewing exact compatibility proposals against canonical tool-family and schematic identity;
4. resolving identifier/schematic gaps without fuzzy matching;
5. allowlisting only reviewed compatibility fields for any eventual mutation;
6. processing the accuracy-review queue before editorial enrichment;
7. preserving all protected identifiers and unrelated catalog fields.

Do not automatically apply compatibility or content proposals merely because they were generated.

## Regenerate current results

After any reviewed canonical catalog change, regenerate the reports in this order:

```powershell
.\scripts\catalog\run-official-catalog-enrichment.ps1 `
  -Python "products\dev\catalog-enrichment\.venv\Scripts\python.exe"

products\dev\catalog-enrichment\.venv\Scripts\python.exe `
  scripts\catalog\prepare_schematic_compatibility_proposals.py

products\dev\catalog-enrichment\.venv\Scripts\python.exe `
  scripts\catalog\prepare_content_review_queue.py `
  --workflow accuracy_review
```

The routine runner is non-mutating unless `-ApplySafeFixes` is explicitly supplied. Do not use that switch until its preview has been reviewed and a clean rollback boundary exists.

## Completion checks

After changes:

```powershell
products\dev\catalog-enrichment\.venv\Scripts\python.exe scripts\catalog\validate_official_catalog.py
products\dev\catalog-enrichment\.venv\Scripts\python.exe -m pytest scripts\catalog\tests -q
php -l drywalltoolbox\wp\wp-content\mu-plugins\dtb-catalog-platform\Rest\CatalogFacetsController.php
php -l drywalltoolbox\wp\wp-content\mu-plugins\dtb-catalog-platform\Services\CategoryNormalizer.php
npm --prefix frontend run lint
git diff --check
git status --short
```

Only lint backend files that actually changed if later work moves ownership elsewhere. Do not claim WooCommerce runtime behavior, deployment, or live import success from these static checks.

## Agent operating instructions

The destination agent must:

1. read and follow repository `AGENTS.md`;
2. inspect active implementation and generators before editing outputs;
3. preserve unrelated user changes in a dirty worktree;
4. update the owning source or generator instead of hand-editing generated artifacts;
5. use exact evidence and leave uncertain data unresolved;
6. make bounded, deterministic, reviewable changes;
7. keep `.venv/` ignored and all current reports commit-visible;
8. remove obsolete reports only when a current owning workflow replaces them;
9. never deploy or import the catalog into live WooCommerce without explicit authorization;
10. report changed files, data impact, validation evidence, remaining findings, and the exact next recommended step.

## Commit and synchronization procedure

Before committing, inspect the complete scope:

```powershell
git status --short
git diff --check
git diff --stat
```

Stage only reviewed files. Do not include `.venv`, secrets, unrelated media deletions, build output, or personal editor state.

```powershell
git add <reviewed paths>
git diff --cached --check
git diff --cached --stat
git commit -m "Continue official catalog enrichment workflow"
git push origin HEAD
```

The next desktop must `git fetch` and use a fast-forward pull before continuing.
