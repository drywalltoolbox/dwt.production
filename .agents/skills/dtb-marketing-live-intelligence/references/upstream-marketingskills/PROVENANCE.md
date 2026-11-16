# Upstream corpus provenance

- Repository: https://github.com/coreyhaines31/marketingskills
- Maintainer identified by repository: Corey Haines
- Pinned commit: `820ad2c1c0ac4cc350158bd7912c753681a7d173`
- Snapshot date: 2026-10-30
- License: MIT; see the adjacent `LICENSE` file.
- Scope: the 52 `skills/<slug>/SKILL.md` files at that commit. This snapshot does not include the upstream repository's CLIs, MCP integration guides, scripts, tests, or supporting reference assets.
- Integrity and local discovery: original source text and frontmatter are preserved byte-for-byte as local `METHOD.md` files. The filename avoids recursive `.agents/skills/**/SKILL.md` discovery, which would expose these reference methods as independently invocable DTB skills outside the wrapper and registry. `index.json` records the original upstream path, pinned source URL, and local path. The separate private plugin package stores the same source files under `references/` and exposes only its curated DTB skills directory.
- Supporting files: relative links inside original methods may target files outside this 52-skill snapshot. Resolve those paths only when needed under `https://github.com/coreyhaines31/marketingskills/tree/820ad2c1c0ac4cc350158bd7912c753681a7d173/skills/<slug>/`; do not assume the referenced files are locally available.

Keep the full upstream skill text intact when updating this snapshot. Record a new commit and date, preserve MIT attribution, regenerate the index, and review any altered file, references, and live-action instructions before enabling the update. Do not run scripts from upstream. Do not treat the MIT license as evidence that a method is accurate, secure, suitable for DTB, or authorized for live use.
