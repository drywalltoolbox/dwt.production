# Catalog Navigation Contract

## Authority

`products/catalog/source/taxonomy.json` is the canonical machine-readable catalog taxonomy registry.

`products/launch/official/dtb_official_catalog.csv` is the canonical launch catalog CSV. It assigns products and variations to the registered WooCommerce hierarchy and preserves protected catalog identity. WooCommerce owns the resulting runtime products, variations, and `product_cat` terms after import.

`scripts/catalog/catalog_taxonomy_policy.py` derives deterministic validation and compatibility metadata from `taxonomy.json`; it must not maintain a second independent hierarchy.

`drywalltoolbox/wp/wp-content/mu-plugins/dtb-catalog-platform/Resources/catalog-taxonomy.json` is the deployment projection of that registry. It must remain byte-identical to the canonical source. `scripts/catalog/sync_catalog_runtime_taxonomy.py` checks or atomically refreshes the projection; contract tests reject drift.

The React storefront consumes backend navigation/facet contracts. It must not classify products from names, SKU patterns, brands, product families, or a parallel hardcoded category tree.

`Meta: _dtb_category_key` and `Meta: _dtb_display_category_key` are compatibility/filter facets. They are not alternate product-category authorities.

Brand and product family are orthogonal dimensions and must not become `product_cat` hierarchy nodes.

## Classification basis

DTB normalizes current cross-brand drywall-tool terminology by physical/function class rather than copying any one manufacturer or retailer navigation tree.

The governing rules are:

- classify by what the sellable product physically/functionally is;
- keep automation/system membership separate from the primary functional class when a tool class spans workflows;
- normalize manufacturer synonyms to one cross-brand class when they describe the same tool function;
- do not use brand, marketing series, material, or product family as category ancestry;
- use exact SKU evidence for genuinely ambiguous products rather than name heuristics;
- variable parents own classification and every variation inherits the exact parent tuple.

## Canonical hierarchy

```text
Automatic Taping Tools
  Automatic Tapers
  Flat Boxes
  Corner Finishers
  Angle Boxes & Corner Applicators
  Compound Tubes
  Powered Compound Applicators
  Applicator Heads
  Corner Flushers
  Corner Rollers
  Nail Spotters
  Loading Pumps
  Goosenecks, Box Fillers & Adapters
  Handles
  Continuous Flow Tools
  Automatic Tool Sets & Kits

Taping & Finishing Tools
  Semi-Automatic Tapers & Banjos
  Mud Pans
  Mud Pan Accessories
  Mud Mixing Tools
  Semi-Automatic Tool Sets & Kits

Corner Bead Tools
  Bead Hoppers
  Corner Bead Rollers

Hanging Tools
  Drywall Squares
  Drywall Saws
  Fastener Setting Tools

Drywall Stilts
Replacement Parts
Tool Cases

Drywall Supplies
  Sealants
```

Only stocked leaves appear in customer navigation; the registry may retain a hidden future leaf such as Continuous Flow Tools. Taping knives, joint knives, trowels, bead mud heads, and bead fastening tools require verified owner SKUs before they are added. Repair hardware remains exclusively in Replacement Parts.

## Important normalization decisions

### Corner Finishers / Angle Heads

`Corner Finishers` is the canonical product class. `Angle Head` and `Anglehead` are manufacturer/search aliases, not separate taxonomy leaves.

### Corner Applicators / Angle Boxes / Corner Boxes

`Angle Boxes & Corner Applicators` is the canonical cross-brand class for reservoir-style corner applicator boxes. `Corner Box` remains an alias.

### Compound delivery versus applicator heads

Passive applicator/mud heads and complete compound-delivery tools are separate product classes:

- `Compound Tubes` — compound-holding/delivery tubes;
- `Powered Compound Applicators` — complete powered or gas-assisted compound-delivery/applicator tools;
- `Applicator Heads` — passive heads attached to a compatible tube/applicator/delivery tool.

The retired `Compound Applicators` leaf is ambiguous and must not be used as a new canonical assignment.

### Semi-automatic tapers

Semi-automatic tapers/banjos are a functional product class directly under `Taping & Finishing Tools`. They are not children of an automatic-tool hierarchy.

### Tool sets

Tool sets use `Automatic Tool Sets & Kits` or `Semi-Automatic Tool Sets & Kits` when their equipment and use are verified. Mixed or other kits remain assigned by their supported product function; the `toolsets` compatibility facet remains common across tool-set categories.

### Handles

Fixed, extendable, box, corner-tool, and related compatible handles remain consolidated under `Handles`. Handle type/compatibility belongs in facets/attributes rather than duplicate category leaves.

### Replacement parts

`Replacement Parts` remains a dedicated top-level catalog surface. Part discovery should primarily use compatibility relationships such as brand, compatible tool/model, schematic, assembly, and part type rather than creating hardware-type product-category branches.

Outside bead rollers and inside tape rollers are distinct. Reviewed outside bead rollers may be assigned to both `Corner Bead Tools > Corner Bead Rollers` and `Automatic Taping Tools > Corner Rollers`; inside-only rollers belong under `Automatic Taping Tools > Corner Rollers`. The official catalog now separates the reviewed TapeTech and Platinum inside/outside roller families while preserving manufacturer sellable SKUs. Variations inherit their parent's category tuple. Runtime WooCommerce and schematic-family reconciliation remains a separate migration step.

`Mud Pan Accessories` belongs under `Taping & Finishing Tools`. `Tool Cases` is a top-level storage category because cases may hold tools across multiple functional groups. The retired `Accessories` root is not part of the canonical tree. `Drywall Supplies` contains installation products used up in the work, such as sealants. It is not a replacement-parts synonym.

## Canonical compatibility facets

The official CSV currently derives compatibility/filter keys from the functional category. Representative tuples are:

```text
Automatic Taping Tools > Automatic Tapers
  -> _dtb_category_key = taping
  -> _dtb_display_category_key = automatic_tapers

Automatic Taping Tools > Flat Boxes
  -> finishing
  -> flat_boxes

Automatic Taping Tools > Corner Finishers
  -> corner
  -> corner_finishers

Automatic Taping Tools > Compound Tubes
  -> corner
  -> compound_tubes

Automatic Taping Tools > Powered Compound Applicators
  -> corner
  -> powered_compound_applicators

Automatic Taping Tools > Applicator Heads
  -> corner
  -> applicator_heads

Automatic Taping Tools > Handles
  -> handles
  -> handles

Taping & Finishing Tools > Semi-Automatic Tool Sets & Kits
  -> taping
  -> toolsets

Taping & Finishing Tools > Semi-Automatic Tapers & Banjos
  -> taping
  -> semi_automatic_tapers_banjos

Replacement Parts
  -> parts
  -> parts

Drywall Stilts
  -> stilts
  -> stilts
```

Compatibility metadata uses lowercase snake_case. Historical values remain aliases only where a deterministic one-to-one migration exists. The old `automatic_compound_applicators` value is intentionally retained as a legacy read value because that historical class split into two canonical product classes and therefore cannot be safely rewritten without SKU-level evidence.

## Parent / variation contract

A variable parent owns navigation classification. Every variation inherits the parent's exact:

- `Categories`
- `Meta: _dtb_category_key`
- `Meta: _dtb_display_category_key`

Variation-specific category drift is invalid. If choices require materially different functional product classes, they must not be modeled as variations of one product family.

## Category assignment authority

`products/launch/official/dtb_official_catalog.csv` owns product category assignments for catalog updates. Review and edit its `Categories` and compatibility metadata explicitly; validation checks the assigned paths and variation inheritance without choosing or rewriting categories. The runtime taxonomy registry defines supported navigation paths, not SKU assignments. WooCommerce owns the imported runtime records; the import and any reconciliation of runtime edits into the CSV are separate explicit operations. No automatic bidirectional synchronization is implied.

## Facets API and frontend

`GET /wp-json/dtb/v1/catalog/facets` derives `navigationGroups` from active WooCommerce `product_cat` ancestry.

The response contract is version `2.3`. Primary `navigationGroups` are read through `DTB_CatalogNavigationService` using one bounded query restricted to registered taxonomy slugs. WooCommerce owns term existence, ancestry, counts, descriptions, and media; the deployed registry projection supplies the canonical allowlist and `sort` order. Scoped brand/display facets may still scan the bounded paginated product projection, but primary navigation must never be rebuilt by scanning every product.

Supported customer navigation roots are ordered:

1. `Automatic Taping Tools`
2. `Taping & Finishing Tools`
3. `Corner Bead Tools`
4. `Hanging Tools`
5. `Drywall Stilts`
6. `Tool Cases`
7. `Drywall Supplies`

Replacement Parts remain a dedicated storefront navigation surface.

The frontend renders the same backend-owned groups and children for desktop and mobile navigation, preserves backend order, and defensively deduplicates canonical slugs. The catalog backend redirects the old Accessories archive to the shop, the old nested Tool Cases archive to the top-level Tool Cases archive, and the old nested Mud Pan Accessories archive to its new Taping & Finishing Tools path. Frontend compatibility code must not become a taxonomy authority. Facet and category caches are namespaced to the response contract version; responses with a different contract version are not persisted.

WooCommerce treats unescaped commas in `Categories` as separators between separate terms. Canonical category labels containing a comma must therefore escape it as `\,` in the official CSV. The canonical Goosenecks path is serialized as `Automatic Taping Tools > Goosenecks\, Box Fillers & Adapters` while its human-facing term name remains `Goosenecks, Box Fillers & Adapters`.

Runtime taxonomy migration version `2` idempotently renames the historical `goosenecks` child, merges product relationships and media from any pre-existing canonical/legacy terms, removes the accidental top-level `box-fillers-adapters` split term, and invalidates catalog caches. Version `2` replays the migration for installations where an import recreated the legacy term after version `1` had completed. The migration version is recorded only after every required mutation succeeds.

Migration version `3` creates the new storefront roots and reparents or renames existing exact-slug WooCommerce terms before the revised official CSV is imported. It preserves term IDs and existing product relationships, refuses a conflicting target slug, and records completion only after all moves succeed. Migration version `4` moves `Tool Cases` to the top level, moves `Mud Pan Accessories` under `Taping & Finishing Tools`, and removes `Accessories` only after confirming it has no direct products or child terms. It preserves the existing child term IDs and relationships. Deploy and verify this migration before the CSV update-existing import so WooCommerce reuses the intended term slugs. The CSV import then reconciles owner products and their variations into the registered paths. Do not make empty proposed product families visible merely to mirror another retailer.

## Runtime compatibility

`DTB_CategoryNormalizer` resolves explicit compatibility metadata first and uses Woo category names only as a conservative legacy fallback. It recognizes the revised precise display keys and deterministic historical aliases.

Historical `Angle Heads` normalize to `Corner Finishers`. Historical automatic/semi handle and tool-set display values normalize to `handles` and `toolsets`. Ambiguous historical `Compound Applicators` remain a compatibility value until SKU-level catalog migration supplies either `applicator_heads` or `powered_compound_applicators`.

## Security and ownership

This contract changes catalog classification and navigation only. It does not modify order creation, payment, pricing, tax, inventory, fulfillment, accounting, authentication, REST authorization, queue ownership, or provider security boundaries.
