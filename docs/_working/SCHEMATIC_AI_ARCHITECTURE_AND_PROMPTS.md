# Schematic Enhancer Official (`SchematicAI`) — Complete Logic, Prompting & Optimization Reference

This document is a comprehensive technical reference capturing **all application logic, verbatim system/user prompts, model fine-tuning parameters, pre/post-processing pipelines, mathematical optimizations, resilience mechanisms, and schema integrations** across the entire codebase.

---

## Table of Contents

1. [System Architecture & Workflows](#1-system-architecture--workflows)
2. [Model Routing, Hyperparameter Tuning & Resilience Engine](#2-model-routing-hyperparameter-tuning--resilience-engine)
3. [Pre-Processing Optimizations, Caching & Client-Side Heuristics](#3-pre-processing-optimizations-caching--client-side-heuristics)
4. [Style System, Multi-Style Blending & Conflict Resolution](#4-style-system-multi-style-blending--conflict-resolution)
5. [Complete Verbatim Prompt Library](#5-complete-verbatim-prompt-library)
   - [5.1 Pre-Processing Classification, Audit & Material Mapping Prompts](#51-pre-processing-classification-audit--material-mapping-prompts)
   - [5.2 Schematic Generation, Upscaling, Creative & Style Transfer Prompts](#52-schematic-generation-upscaling-creative--style-transfer-prompts)
   - [5.3 Surgical Schematic Refinement Prompts](#53-surgical-schematic-refinement-prompts)
   - [5.4 Bulk Product & Hardware Photography Regeneration Prompts](#54-bulk-product--hardware-photography-regeneration-prompts)
   - [5.5 Multi-Stage Hotspot Extraction, OCR & Memory Refinement Prompts](#55-multi-stage-hotspot-extraction-ocr--memory-refinement-prompts)
   - [5.6 PDF Page Classification, Legend Extraction & JSON Audit Prompts](#56-pdf-page-classification-legend-extraction--json-audit-prompts)
6. [Post-Processing Geometry, Touch Optimization & Coordinate Math](#6-post-processing-geometry-touch-optimization--coordinate-math)
7. [Batch Concurrency, Human-in-the-Loop (HITL) State Machines & Persistence](#7-batch-concurrency-human-in-the-loop-hitl-state-machines--persistence)
8. [Export Schema v2.0, WordPress/WooCommerce Binding & Observability Contracts](#8-export-schema-v20-wordpresswoocommerce-binding--observability-contracts)

---

## 1. System Architecture & Workflows

### Core Module Map

| File | Responsibility |
| :--- | :--- |
| `src/lib/gemini.ts` | Core AI orchestration engine: style registry, prompt builders, pre-processing vision audits, dynamic temperature tuning, model fallback rings, exponential backoff, failure-aware prompt mutation, schematic enhancement/refinement, product photo regeneration, and 2-stage center-to-box hotspot extraction. |
| `src/lib/prompts.ts` | Canonical 3-stage optical OCR and adversarial QA system instructions (`STAGE_2_SYSTEM`, `STAGE_4_SYSTEM`, `STAGE_6_SYSTEM`). |
| `src/lib/HotspotMemoryStore.ts` | Single-turn shape-aware surgical hotspot refiner enforcing touch-target minimums, proximity gap separation, and systematic coordinate offset correction. |
| `src/lib/SchematicExtractionSession.ts` | Sequential 3-stage extraction session runner (`Stage 2 Style Classifier -> Stage 4 OCR -> Stage 6 Adversarial QA`). |
| `src/lib/schematic-legend-processor.ts` | Multi-page PDF page classifier (`SCHEMATIC` / `LEGEND` / `OTHER`), quantity-aware label parser, legend key-table extractor, 3-stage schematic data extractor, and AI-assisted JSON part-to-hotspot mapper (`auditAndUpdateJson`). |
| `src/lib/coordUtils.ts` | Coordinate space converters (`0–1000` normalized, `0–100%` percentage, `px` natural pixels) and the collision-aware mobile touch-target padding optimizer (`adjustHotspotsForMobileFriendlyTouch`). |
| `src/lib/exportSchemaV2.ts` | Formal `SchemaV2JSON` (`schema_version: "2.0"`) compiler generating slugified diagram metadata, reconciled `parts_catalog`, and dual pixel/percentage shape definitions (`rect`, `circle`, `polygon`). |
| `src/lib/pdf-utils.ts` | Client-side `pdfjs-dist` rasterizer converting multi-page PDFs into high-DPI PNG data URIs (`scale = 3.0` default; configurable `1.0x–4.0x`). |
| `src/App.tsx` | Primary workspace controller: IndexedDB persistence (`localforage`), multi-ratio parallel generation fan-out, Smart Density Auto-Cropper with automatic hotspot coordinate remapping, interactive pointer drag/resize handles, keyboard arrow micro-nudging, and PNG/WebP + JSON exporters. |
| `src/components/WorkflowPipeline.tsx` | End-to-end automated & Human-in-the-Loop (HITL) multi-file PDF/Image processing state machine with approval gates and rejection feedback loops. |
| `src/components/SchematicLegendProcessor.tsx` | Dedicated multi-PDF ingestion, page classification, label/legend extraction, cross-page part correlation, and batch ZIP exporter. |
| `src/components/BatchProcessor.tsx` | Bulk image/ZIP/folder/PDF schematic enhancement & batch `SchematicExtractionSession` hotspot runner. |
| `src/components/ImageRegenerator.tsx` | Bulk e-commerce product & hardware macro photography synthesis engine (`clone`, `product_hero`, `hardware_part`) with adaptive worker concurrency, pause/resume/stop controls, and live terminal telemetry. |
| `src/components/SchematicViewer.tsx` | Pan/zoom (`0.1x–10x`) interactive SVG `0–1000` coordinate overlay viewer/editor with invisible `strokeWidth="20"` touch-target extenders. |
| `src/components/ImageComparisonSlider.tsx` | Hardware-accelerated `clip-path` split-screen comparison slider for Before/After HD inspection. |
| `src/types.ts` | Domain entities, error taxonomy (`ErrorCode`), validation contracts, `TraceLog`, and `AIPromptPatch` self-healing interfaces. |

### Supported Workflow Modes (`Project.workflowType`)
1. **`generate`**: Upload raw schematic (Image/PDF) -> Pre-audit + Style/Material synthesis -> Multi-ratio enhanced CAD output -> Optional hotspot extraction.
2. **`refine`**: Upload existing diagram -> Surgical difference-aware edits (`refineSchematic`) referencing `<CURRENT_IMAGE>`, `<ORIGINAL_SOURCE_IMAGE>`, and `<STYLE_TRANSFER_REFERENCE_IMAGES>`.
3. **`upscale`**: Pure 1:1 architectural HD reconstruction (`mode: 'upscale'`) without stylistic mutation, paired with interactive split-slider comparison.
4. **`extract`**: Direct hotspot detection (`extractHotspots` -> `refineHotspots` -> `adjustHotspotsForMobileFriendlyTouch`) + optional `auditAndUpdateJson` against an uploaded `schematic_data.json`.
5. **`batch`**: Bulk folder/ZIP/PDF schematic enhancement + batch 3-stage hotspot extraction (`BatchProcessor`).
6. **`legend-correlation`**: Multi-PDF page classification, legend table extraction, and automatic part number/quantity correlation (`SchematicLegendProcessor`).
7. **`pipeline`**: Unified configurable workflow combining PDF conversion, classification, legend extraction, HITL approval gates, AI enhancement, hotspot extraction, and JSON auditing (`WorkflowPipeline`).
8. **Standalone `ImageRegenerator`**: 2-stage vision-specification + multimodal photography synthesis for physical tools, retail boxes, and micro-hardware (`product_hero`, `hardware_part`, `clone`).

---

## 2. Model Routing, Hyperparameter Tuning & Resilience Engine

### 2.1 Model Pools & Fallback Rotation (`getModelForAttempt`)
Every AI call wrapped in `withRetry(fn, modelType, policy, primaryModel)` dynamically selects a model per attempt using modular rotation `(attempt - 1) % models.length`:

- **Image Generation Pool (`modelType = 'image'`)**:
  1. `gemini-3.1-flash-image`
  2. `gemini-3.5-flash`
  3. `gemini-2.5-flash-image`
- **Text / Vision / JSON Analysis Pool (`modelType = 'text'`)**:
  1. `gemini-3.5-flash`
  2. `gemini-3.1-flash-lite`
  3. `gemini-3-flash-preview` *(Note: `generateContentStreamAndLog` transparently remaps `"gemini-3-flash-preview"` -> `"gemini-3.5-flash"` at runtime to avoid preview throttling/deprecation).*
  4. `gemini-2.5-flash`

**Primary Model Promotion**: If a user explicitly selects a `primaryModel` in the UI and it exists in the pool, `getModelForAttempt` splices it to index `0` for Attempt 1 and rotates through the remaining models as fallbacks on Attempts 2+.

### 2.2 Base Model Hyperparameters (`MODEL_DEFAULTS`)

| Model ID | Base `temperature` | `topP` | `topK` | Default Seed |
| :--- | :---: | :---: | :---: | :---: |
| `gemini-3.1-flash-image` | `0.40` | `0.95` | `40` | `42` |
| `gemini-3.5-flash` | `0.65` | `0.92` | `40` | `42` |
| `gemini-2.5-flash-image` | `0.50` | `0.95` | `40` | `42` |
| `gemini-3.1-flash-lite` | `0.65` | `0.92` | `40` | `42` |
| `gemini-3-flash-preview` | `0.65` | `0.92` | `40` | `42` |
| `gemini-2.5-flash` | `0.65` | `0.92` | `40` | `42` |

### 2.3 Dynamic Temperature Optimization Formulas

1. **Schematic Enhancement (`resolveTemperature` in `enhanceSchematic`)**:
   $$\text{temperature} = \text{clamp}\left(0.01,\ 1.0,\ T_{\text{base}} + \frac{\sum_{s \in \text{styles}} \Delta T_s}{\max(|\text{styles}|, 1)} + \Delta T_{\text{quality}}\right)$$
   - **Style Offsets ($\Delta T_s$)**:
     - `minimalist`: `-0.10`
     - `patent`: `-0.05`
     - `modern_realism`: `-0.05`
     - `mobile_responsive`: `-0.05`
     - `modern`, `blueprint`, `isometric`: `0.00`
     - `vintage`: `+0.05`
     - `artistic`: `+0.10`
     - `realistic`: `+0.15`
   - **Quality Tier Offsets ($\Delta T_{\text{quality}}$)**:
     - `maximum`: `-0.10` (maximizes deterministic geometric lock)
     - `high`: `-0.05`
     - `standard`: `0.00`

2. **Task-Specific Temperature Overrides**:
   - **`refineSchematic` (Surgical Schematic Edits)**: `Math.max(0.1, modelParams.temperature - 0.15)`
   - **`regenerateImage` (Product & Hardware Synthesis)**:
     - `clone`: `0.05` (strict 1:1 visual layout lock)
     - `hardware_part`: `0.15` (structural dimensional lock)
     - `product_hero`: `0.75` (creative studio camera/arrangement synthesis)
   - **`refineImage` (Photographic Retoucher)**:
     - `clone`: `0.05`
     - `hardware_part`: `0.20`
     - `product_hero`: `0.85`
   - **`classifySchematic` (Optical Drawing Classifier)**: `0.05` (`application/json`)
   - **`extractHotspots` (Stage 1 & Stage 2) & `HotspotMemoryStore.refine`**: `0.10` (`application/json` + strict `responseSchema`)

### 2.4 Output Resolution & Multimodal `imageConfig` Rules

- **`mapImageSizeToApi(size)`**:
  - `"720p"` -> `"0.5K"`
  - `"1080p"` -> `"1K"`
  - `"1440p"` -> `"2K"`
  - `"2K"` -> `"2K"`
  - `"4K"` -> `"4K"`
  - `"8K"` -> `"4K"`
- **Multimodal `aspectRatio` Safeguard**:
  When calling image-capable models (`currentModel.includes('image')`), `generationConfig.imageConfig = { imageSize: mapImageSizeToApi(imageSize) }` is always set, while `generationConfig.imageConfig.aspectRatio = targetAspectRatio` is **only** set in `imageConfig` if `!parts.some(p => p.inlineData)` (preventing API conflicts when inline reference/source images are attached; canvas framing and target aspect ratio are instead enforced inside the prompt specification).

### 2.5 Exponential Backoff, Rate Limiting & Retry Policies

- **Sequential Pipeline Rate Limiter (`rateLimit`)**: Enforces a minimum `3000ms` interval between consecutive Gemini API calls in automated multi-page pipelines (`WorkflowPipeline`).
- **Backoff Delay Formula (`withRetry`)**:
  $$\text{delay} = \min\left(\text{baseDelayMs} \times \text{backoffMultiplier}^{(\text{attempt} - 1)} + \text{rand}(0, 500),\ \text{maxDelayMs}\right)$$

| Operation | `maxAttempts` | `baseDelayMs` | `maxDelayMs` | `backoffMultiplier` |
| :--- | :---: | :---: | :---: | :---: |
| **`DEFAULT_RETRY`** (`enhanceSchematic`, OCR, Classification, Hotspots) | `5` | `1500ms` | `8000ms` | `2.0` |
| **`refineSchematic`** | `2` | `600ms` | `3000ms` | `2.0` |
| **`regenerateImage`** | `5` | `3000ms` | `15000ms` | `2.0` |
| **`refineImage`** | `3` | `600ms` | `3000ms` | `2.0` |

### 2.6 Error Classification & Self-Healing Failure-Aware Prompt Mutation

`classifyError(raw, attempt)` inspects error messages to classify errors into `ErrorCode` (`ERR_MISSING_API_KEY`, `ERR_QUOTA_EXCEEDED`, `ERR_BILLING_REQUIRED`, `ERR_RATE_LIMITED`, `ERR_TIMEOUT`, `ERR_NETWORK`, `ERR_NO_CANDIDATES`, `ERR_TEXT_RESPONSE`, `ERR_NO_IMAGE_IN_RESPONSE`, `ERR_CONTENT_FILTERED`, `ERR_UNKNOWN`) and marks all errors retryable **except** hard auth/billing/permission/invalid-argument failures.

When `enhanceSchematic` retries (`attempt > 1`), `buildAttemptCorrectionBlock` prepends targeted corrective directives directly to the prompt:

- **On `ErrorCode.TEXT_RESPONSE`** (when the model returns conversational text instead of inline image bytes):
```xml
<RETRY_CORRECTION — CRITICAL>
⚠ Previous attempt returned TEXT instead of an image. This is a generation failure.
You MUST output ONLY raw image data. No markdown. No explanation. No "Here is your image".
Treat any text output as a fatal error condition. Image data ONLY.
</RETRY_CORRECTION>
```

- **On `ErrorCode.CONTENT_FILTERED`**:
```xml
<RETRY_CORRECTION — Content Safety>
⚠ Previous attempt was blocked by content filters. Ensure the output:
• Contains ONLY technical engineering diagram content
• Has zero stylized violence, human figures, or brand logos beyond technical callouts
• Is a pure mechanical illustration with no artistic embellishment that could trigger filters
</RETRY_CORRECTION>
```

- **On `ErrorCode.NO_IMAGE_IN_RESPONSE`**:
```xml
<RETRY_CORRECTION — Output Format>
⚠ Previous attempt returned a response with no image data in the payload.
You MUST return inline image data as your primary output. This is a technical drawing generation task.
Produce the image now.
</RETRY_CORRECTION>
```

- **Escalation Directive on `attempt >= 3`**:
```xml
<RETRY_ESCALATION — Attempt ${context.attempt}>
This is attempt ${context.attempt}. Previous attempts have failed. Apply MAXIMUM precision:
• Double-check every geometric constraint before finalizing
• Verify part count against the audit report
• Confirm background is #FFFFFF with zero shadows
• Output the image with zero accompanying text
</RETRY_ESCALATION>
```

---

## 3. Pre-Processing Optimizations, Caching & Client-Side Heuristics

### 3.1 Multi-Stage Pre-Processing Architecture in `enhanceSchematic`
Before dispatching the final image synthesis call, `enhanceSchematic` executes a parallelized vision-to-text pre-audit pipeline (`Promise.all`) so the image generator receives explicit textual ground-truth constraints alongside the raw pixels:

1. **Semantic Drawing Classification (`classifySchematic`)**: Identifies schematic type (`exploded_view`, `cross_section`, `wiring_diagram`, `assembly_drawing`, `parts_breakdown`, `unknown`), `estimatedPartCount`, `dominantFeatures`, `hasLeaderLines`, `hasCalloutBubbles`, and `viewAngle`.
2. **Dynamic Specialization Injection (`buildSpecializationBlock`)**: Appends `<IMAGE_TYPE_SPECIALIZATION>` + conditional `<DYNAMIC_FEATURE_WARNINGS>` (when `config.mode !== 'creative'`).
3. **Engineering Inventory Audit (`extractSchematicAudit`)**: Produces a plain-text parts, labels, geometry, and hallucination-risk inventory injected inside `<SCHEMATIC_AUDIT_REPORT>`.
4. **Reference Photo Material & Color Mapping (`extractMaterialDescription`)**: When real product reference photos are attached (and `mode !== 'style_transfer'`), extracts part-to-material/color mappings injected inside `<MATERIAL_AND_COLOR_GUIDE>`.

### 3.2 In-Memory Memoization Caches
To eliminate redundant token spend and latency across multi-ratio parallel fan-out (`isMultiRatio`) and iterative refinements:

| Cache Map | Key Formula | Cached Payload |
| :--- | :--- | :--- |
| `classificationCache` | `base64Image.substring(0, 80)` | `ClassificationResult` JSON |
| `schematicAuditCache` | `schematicBase64.substring(0, 100)` | Plain-text engineering audit report |
| `materialDescriptionCache` | `schematicBase64.substring(0, 100) + referenceImages.map(r => r.url.substring(0, 50)).join("")` | Bulleted part-to-material/color list |
| `productSpecCache` | `` `v4_len_${base64Image.length}_mid_${base64Image.substring(mid, mid + 100)}_${mode}` `` | Exhaustive 5-to-7-section product/hardware specification |

### 3.3 Client-Side Zero-Token Aspect Ratio Auto-Detection (`detectOptimalAspectRatio` / `getPixelAspectRatio`)
Instead of spending an LLM call to guess aspect ratios:
1. **Mobile-First Override**: If `styles` includes `"mobile_responsive"`, `detectOptimalAspectRatio` immediately returns `"9:16"`.
2. **Deterministic Pixel Matcher**: Loads the image into a browser `HTMLImageElement`, computes `ratio = naturalWidth / naturalHeight`, and selects the closest standard ratio minimizing `Math.abs(ratio - candidate.value)` across:
   - `1:1 (1.0)`, `4:3 (1.333)`, `3:4 (0.75)`, `16:9 (1.778)`, `9:16 (0.5625)`, `1:4 (0.25)`, `1:8 (0.125)`, `4:1 (4.0)`, `8:1 (8.0)` (default fallback `"4:3"`).

### 3.4 Smart Density Auto-Cropper & Hotspot Coordinate Remapping (`handleAutoCropImage` in `App.tsx`)
Eliminates excess scanner margins/borders purely on the client via HTML5 Canvas 2D pixel analysis while keeping all extracted hotspots aligned:
1. **4-Corner Background Sensing**: Samples `(0,0)`, `(W-1,0)`, `(0,H-1)`, `(W-1,H-1)` to compute `(avgR, avgG, avgB)` and `isLightBg = ((avgR + avgG + avgB) / 3) > 128`.
2. **Content Pixel Classifier**: Marks a pixel `(r,g,b,a)` as content if `a >= 30` and:
   - Light BG: Euclidean RGB distance `dist > 35` OR `((r + g + b) / 3 < 215)`
   - Dark BG: `dist > 35` OR `((r + g + b) / 3 > 40)`
3. **Row/Column Density Thresholding**: Accumulates `rowCounts[y]` and `colCounts[x]`. Scans inward from all 4 edges for the first row/column exceeding noise threshold `max(3, round(dim * 0.0015))` to find `[minX, maxX, minY, maxY]`.
4. **Safety Padding & Re-Encoding**: Expands bounds by `padding = max(35, round(min(W, H) * 0.025))`, fills background seamlessly, draws cropped region, and encodes to `image/webp` at `0.95` quality.
5. **Affine Hotspot Coordinate Transformation**: Automatically transforms every existing hotspot's `bbox`, `box_2d`, `x_pct`, and `y_pct` into the new `(cropX, cropY, cropW, cropH)` coordinate frame:
   $$x_{\text{new}\%} = \frac{\left(\frac{x_{\text{orig}\%}}{100} \cdot W\right) - \text{cropX}}{\text{cropW}} \times 100, \quad w_{\text{new}\%} = \frac{\frac{w_{\text{orig}\%}}{100} \cdot W}{\text{cropW}} \times 100$$

### 3.5 Complexity & Latency Estimators (`gemini.ts`)
- **`estimateComplexity(config)`**: Scores `(styles.length * 2) + qualityScore (1|3|6) + (hasCustomPrompt ? 2 : 0) + (enhanceDetails ? 2 : 0) + (preserveGeometry ? 1 : 0)` -> `"low"` (`<=6`), `"medium"` (`<=12`), `"high"` (`>12`).
- **`estimateLatencySeconds(model, imageSize, quality)`**: `Math.round(base[model] * sizeMultiplier[imageSize] * qualityMultiplier[quality])`.

---

## 4. Style System, Multi-Style Blending & Conflict Resolution

### 4.1 Multi-Style Blending Algorithm (`buildStyleBlock`)
1. Validates & deduplicates styles via `normalizeStyles` (defaults to `["modern"]`).
2. Resolves incompatible style combinations via `resolveStyleConflicts`.
3. Sorts active styles by `StyleDescriptor.weight` descending.
4. Prefixes each style's prompt block with hierarchical influence weights:
   - 1st style: `[60% influence]`
   - 2nd style: `[25% influence]`
   - 3rd style: `[15% influence]`
   - 4th+ styles: `[10% influence]`
5. **Special 3-Way Blend Override**: When `modern` + `artistic` + `realistic` are simultaneously selected in standard mode, injects `SPECIAL STYLE BLEND — Realistic Modern Schematic` enforcing grounded technical schematic clarity without extreme PBR gloss.

### 4.2 Style Conflict Resolution Rules (`STYLE_CONFLICTS`)

| Style Pair | Resolution | Behavior / Injected `<STYLE_RESOLUTION_NOTES>` |
| :--- | :--- | :--- |
| `minimalist` + `realistic` | `merge_note` | `STYLE CONFLICT RESOLVED — Minimalist + Realistic: Apply minimalist line geometry (clean strokes, no fills) with realistic material surface hints on edges only. No fills, no ambient light — use line weight variation to imply material.` |
| `blueprint` + `modern_realism` | `merge_note` | `STYLE CONFLICT RESOLVED — Blueprint + Modern Realism: Use blueprint colour palette (deep blue bg, white lines) but apply realistic material edge highlighting. No photorealistic fills.` |
| `minimalist` + `artistic` | `keep_first` | Removes `artistic`, retains `minimalist`. |
| `patent` + `realistic` | `keep_first` | Removes `realistic`, retains `patent` (patent rules strictly forbid gradients/color). |
| `patent` + `isometric` | `merge_note` | `STYLE CONFLICT RESOLVED — Patent + Isometric: Use isometric 30° projection geometry, but render in strict patent style (black ink only, hatching for shading, no colour fills).` |

### 4.3 Verbatim `STYLE_REGISTRY` Definitions (All 10 Styles)

#### 1. `modern` — Modern CAD (`weight: 1.0`, `temperatureDelta: 0`)
```text
STYLE — Modern CAD Technical Drawing:
  • Background: pure white (#FFFFFF), zero bleed. ABSOLUTELY NO shadows, ambient occlusion, or soft glows.
  • Stroke weights: outer contour 2–3 px, primary features 1–2 px, fine detail 0.5–1 px
  • Colour: monochrome black (#000000); optional single accent (#0055CC) for dimension arrows only
  • Shading: ABSOLUTELY NO shading, zero ambient occlusion, zero gradient fills, and zero drop/contact shadows to guarantee a 100% flat white background
  • Typography: ISO 3098B sans-serif for any retained annotations
  • Aesthetic: ISO 128 / ASME Y14.3 compliant; professional engineering CAD output
```

#### 2. `blueprint` — Blueprint (`weight: 1.0`, `temperatureDelta: 0`)
```text
STYLE — Classic Engineering Blueprint:
  • Background: deep blueprint blue (#1A3A5C), uniform flat fill
  • Lines: crisp white (#FFFFFF), weight 1–2 px; hairlines at 0.5 px
  • Grid: 5 mm reference grid overlay at 15% white opacity
  • Typography: condensed technical sans-serif, white, uppercase
  • Contrast ratio: minimum 10:1 (WCAG AAA equivalent for technical drawings)
  • Optional: subtle paper-fibre grain at 3% opacity for authenticity
```

#### 3. `patent` — Patent Drawing (`weight: 1.0`, `temperatureDelta: -0.05`)
```text
STYLE — US Patent Office Illustration (37 CFR 1.84 compliant):
  • Background: off-white (#F8F8F2), simulating 21.6 × 27.9 cm patent paper
  • Ink: solid black (#000000) ONLY — no grey, no gradients, no colour
  • Shading technique: parallel hatching at 45° (section views), stippling for curved surfaces
  • Cross-hatching: 2 mm line spacing at 90° for material sections
  • Line weights: outlines 0.35 mm, section lines 0.18 mm, centre lines 0.18 mm dashed
  • Leader lines: straight with closed arrowheads; reference numerals in 10 pt serif
  • No decorative borders, logos, or non-standard annotation styles
```

#### 4. `artistic` — Artistic Technical (`weight: 0.9`, `temperatureDelta: +0.10`)
```text
STYLE — Technical Art / Marker-Style Rendering:
  • Line quality: varied stroke weight (0.5–4 px) for visual hierarchy and depth
  • Shading: warm marker-style gradients with hard-edge termination; avoid soft airbrush
  • Colour palette: desaturated warm base (parchment, steel-grey) + 1–2 accent tones
  • Highlight: sharp white specular on radii and edges
  • Perspective: slight three-quarter view acceptable if it improves readability
  • Priority: mechanical accuracy preserved; artistic treatment is supplementary
```

#### 5. `minimalist` — Minimalist (`weight: 1.0`, `temperatureDelta: -0.10`)
```text
STYLE — Minimalist Line Art:
  • Background: pure white (#FFFFFF)
  • Strokes: single weight, uniform 0.75 px, solid black (#000000)
  • Zero shading, zero fills, zero gradients, zero textures
  • Geometry only: every non-essential decorative line removed
  • Whitespace: generous; components breathe within the frame
  • Result: maximum clarity through radical simplification
```

#### 6. `isometric` — Isometric (`weight: 1.0`, `temperatureDelta: 0`)
```text
STYLE — True Isometric Technical Projection:
  • Projection: 30° isometric (IEC 60617 dimetric variant acceptable for complex assemblies)
  • Background: white (#FFFFFF) or very light grey (#F2F2F2)
  • Colour coding: structural body in cool grey (#6B7280), moving/actuated parts in blue (#2563EB), critical/safety components in red (#DC2626), fluid/pneumatic in green (#16A34A)
  • Strokes: uniform 1 px outlines, 0.5 px internal detail lines
  • Perspective distortion: strictly forbidden — all axes must be mathematically correct
  • Hidden lines: optional 0.25 px dashed grey (#BDBDBD)
```

#### 7. `vintage` — Vintage (`weight: 0.95`, `temperatureDelta: +0.05`)
```text
STYLE — Early 20th-Century Engineering Drawing:
  • Background: aged cream / yellowed paper (#EDE5C8) with subtle irregular grain texture
  • Ink: warm sepia (#3D2B1A) with natural ink-spread variation (±5% stroke width)
  • Imperfections: slight letter-press impression, minor ink bleed at intersections — must look organic, not digitally uniform
  • Typography: serif typeface (Clarendon or Century Schoolbook), mixed caps
  • Title block: simple ruled border in sepia, manufacturer-era styling
  • Authenticity range: 1900–1950 drafting table aesthetic
```

#### 8. `realistic` — Photorealistic (`weight: 0.85`, `temperatureDelta: +0.15`)
```text
STYLE — Photorealistic Technical Render:
  • Renderer: PBR (Physically Based Rendering) output quality
  • Materials: differentiate metal (brushed aluminium, anodised, cast iron), rubber seals (black, slight gloss), transparent components (glass/lexan with refraction), and polymer housings
  • Lighting: three-point studio setup — key light at 45° elevation, fill at 0.4 intensity, rim light for edge separation
  • Shadows: ABSOLUTELY NO shadows under any circumstances. No ambient shading, ground shadows, contact shadows, floor reflections, or dropshadows. The background must remain 100% flat, pure, seamless white (#FFFFFF) with absolutely zero ambient occlusion or gradients. 
  • Depth of field: minimal, all components in acceptable sharpness
  • Tone mapping: neutral to slightly warm, avoid oversaturation
  • Anti-aliasing: maximum quality; no aliased edges
```

#### 9. `modern_realism` — Modern Realism (`weight: 1.0`, `temperatureDelta: -0.05`)
```text
STYLE — Modern Realism (High-Fidelity Product Rendering):
  • Visual Philosophy: A premium industrial design "hero shot" that maintains 100% geometric accuracy. Looks like a pristine, high-end studio photograph or a high-fidelity CAD render.
  • FRESH GENERATION: You must DRAW a completely new asset. Do not just upscale or filter the source image.
  • ZERO HALLUCINATION POLICY: You are absolutely FORBIDDEN from adding any parts, details, features, or geometry that do not explicitly exist in the source image. Pixel-perfect replication is required.
  • Line quality: Modern CAD strokes (solid black, 0.5–1.5 px). Lines MUST strictly follow the edges present in the source image.
  • Structural Fidelity: DO NOT add, remove, or rearrange any components. Maintain the exact spatial 3D relationship and exact shape of the parts as shown in the original diagram.
  • Materials: High-fidelity materials—polished stainless steel, matte powder-coated metals, satin polymers, and clean technical textures. NO hyper-realistic grit, micro-scratches, or surface imperfections.
  • Lighting: Professional studio lighting with soft, clean highlights. Emphasize form and material quality through "idealized" lighting setups.
  • Background: Pure white (#FFFFFF) with ABSOLUTELY NO shadows, drop shadows, contact shadows, ambient occlusion, reflection lines, or gradients. Both the background and adjacent object margins must be 100% flat pure white.
  • Detail: Clean and sharp. Deliver super realistic material properties (reflectivity, gloss, diffuse) without venturing into the "gritty" hyper-realism territory. The goal is "perfectly manufactured" strict 1-to-1 replication.
```

#### 10. `mobile_responsive` — Mobile-First Layout (`weight: 1.0`, `temperatureDelta: -0.05`)
*(Note: Default project preset combines `["realistic", "modern_realism", "mobile_responsive"]`)*
```text
STYLE — Mobile-First Responsive/Optimized Technical Drawing:
  • FRESH GENERATION: You must DRAW a completely new asset. Do not just upscale or pass the source image through a filter. Treat the source as a blueprint to map out the new artwork.
  • ZERO HALLUCINATION POLICY: You are absolutely FORBIDDEN from adding any parts, details, features, callouts, or labels that do not explicitly exist in the source image.
  • EXACT LABELS (CRITICAL): You MUST transcribe and generate all labels, part numbers, and annotations EXACTLY as they appear in the original schematic. Under no circumstances should you alter, shorten, compress, or replace lists, stacked numbers, commas, slashes, or reference codes with generic words like "Details". Character-for-character, verbatim reproduction of all original characters is an absolute requirement. DO NOT inject generic text like "Nut", "Bolt", or "Screw" unless it is literally written in the original schematic.
  • NO DUPLICATES: Do not duplicate any parts or labels. Each part and label from the original must appear exactly once. Never copy a label or hardware piece to fill vertical space.
  • Structural Fidelity (CRITICAL): You MUST maintain the EXACT exploded view layout, part quantities, scale, and spatial relationships of the original schematic. DO NOT re-arrange, re-assemble, skip, or change the internal relative positions of any mechanical parts.
  • Exact Parts: A pin must remain a pin (smooth shaft), a screw must remain a screw (threads). Do not hallucinate threads, hexagonal heads, or other features. Do not mutate the type of part.
  • Leader Lines & Targets (CRITICAL): You MUST trace every leader line to its EXACT target component in the original image. Never arbitrarily attach a label to a different part. Ensure each label points to the specific hardware it was assigned to in the source. Do not mix up the parts that the labels refer to.
  • Re-framing: You may space parts out vertically if necessary to fit a portrait shape, but you MUST NOT hallucinate new parts to fill space. Exactly the same number of parts as the original image must be present.
  • Typography (CRITICAL): ALL text, numbering, callouts, and labels must remain perfectly horizontal and upright for immediate readability.
  • Typography Sizing & Thin Weight: Use highly legible sans-serif fonts with ultra-light, delicate, or standard regular font weight (strictly never bold, semi-bold, thick, or bloated). Ensure characters have extremely crisp, thin strokes (uniform 1px stroke weight max) at comfortable point sizes for clear, pixel-perfect technical drawings.
  • Callouts & Leaders: Ensure leader lines are thin, sharp, and deeply contrasted. DO NOT add solid bounding boxes, circular bubbles, pill shapes, badges, or shapes around part numbers under any circumstances. Just use clean, legible, crisp text optimized for digital screens. Ensure labels are properly and optimally sized, physically spaced out, guaranteeing a minimum of 44x44 pixel tap-target density. No two callout labels may overlap or touch each other.
  • Outline Weights: Use natural, precise primary hardware outline weights (1px to 1.5px scale with zero shadows, flat styling, no ambient gradients) to remain elegant, sharp, and clean on all screens.
```

---

## 5. Complete Verbatim Prompt Library

### 5.1 Pre-Processing Classification, Audit & Material Mapping Prompts

#### A. Semantic Drawing Classifier (`classifySchematic` in `src/lib/gemini.ts`)
- **Model**: Text pool (`gemini-3.5-flash` primary), `temperature: 0.05`, `responseMimeType: "application/json"`
```text
You are a precision optical classifier for engineering drawings.
Analyze this image and return a JSON object ONLY. No explanation.
Schema:
{
  "type": "exploded_view" | "cross_section" | "wiring_diagram" | "assembly_drawing" | "parts_breakdown" | "unknown",
  "confidence": 0.0-1.0,
  "dominantFeatures": ["string"],  // max 5, e.g. ["leader_lines", "hatching", "coil_springs"]
  "estimatedPartCount": number,    // integer 0-999
  "hasLeaderLines": boolean,
  "hasCalloutBubbles": boolean,
  "viewAngle": "isometric" | "orthographic" | "perspective" | "flat"
}
```

#### B. Per-Type Specialization Blocks (`SPECIALIZATION_BLOCKS` & `buildSpecializationBlock`)
Injected into `enhanceSchematic` whenever `config.mode !== 'creative'`:

- **`exploded_view`**:
```xml
<IMAGE_TYPE_SPECIALIZATION — Exploded View Assembly>
• This is an EXPLODED VIEW. Parts are spatially separated along one or more blast axes to show assembly sequence.
• AXIS PRESERVATION (CRITICAL): Maintain the exact explosion axis (vertical, horizontal, or diagonal) of every part cluster. Do NOT reassemble parts.
• SPACING FIDELITY: The gap between each part indicates its position in the assembly stack. These gaps are intentional and must be preserved proportionally.
• PART COUNT LOCK: Count every discrete component. The output must contain the IDENTICAL number of discrete parts.
• LEADER LINE TARGETS: In exploded views, leader lines often point to parts displaced from their assembled position. Map each line to its true target, not the nearest visible part.
</IMAGE_TYPE_SPECIALIZATION>
```
- **`cross_section`**:
```xml
<IMAGE_TYPE_SPECIALIZATION — Cross-Section / Cut View>
• This is a CROSS-SECTION. Internal geometry is exposed by an imaginary cutting plane.
• CUTTING PLANE IDENTITY: Preserve the exact hatching pattern, angle, and density of all sectioned material faces.
• INTERNAL GEOMETRY: Render hidden internal cavities, bores, counterbores, and wall thicknesses with absolute precision.
• HATCHING STANDARD: Material hatching lines must be perfectly parallel and evenly spaced at exactly 45°. Do not curve or waver them.
• SECTION LINE: Preserve the section indicator line (A-A, B-B) and its arrowheads exactly.
</IMAGE_TYPE_SPECIALIZATION>
```
- **`wiring_diagram`**:
```xml
<IMAGE_TYPE_SPECIALIZATION — Wiring / Electrical Schematic>
• This is a WIRING DIAGRAM. Logical connectivity is more important than physical geometry.
• WIRE ROUTING: Preserve all wire paths, including 90° bends, junctions (T-nodes, dots), and crossing bridges.
• SYMBOL FIDELITY: Every IEC/ANSI component symbol (resistor, capacitor, relay, ground) must be redrawn to standard, not approximated.
• COLOUR CODING: Wire colours are electrically significant. Preserve exact wire colour assignments.
• NET LABELS: Pin names, net identifiers, and connector designators must be exact character-for-character transcriptions.
</IMAGE_TYPE_SPECIALIZATION>
```
- **`assembly_drawing`**:
```xml
<IMAGE_TYPE_SPECIALIZATION — Assembly Drawing>
• This is an ASSEMBLED VIEW showing components in their final mated positions.
• MATING SURFACES: Where parts interface, the boundary must be geometrically exact — no overlap, no gap unless a clearance fit is shown.
• FASTENER CONTEXT: Bolts, screws, and pins shown in-situ must align with their holes. Thread engagement depth must look plausible.
• DATUM REFERENCES: If datum triangles or reference planes appear, preserve them with exact symbol geometry.
</IMAGE_TYPE_SPECIALIZATION>
```
- **`parts_breakdown`**:
```xml
<IMAGE_TYPE_SPECIALIZATION — Parts Breakdown / Catalog View>
• This is a PARTS CATALOG diagram with isolated individual components.
• PART ISOLATION: Each part is shown individually. Do not combine or overlap parts.
• UNIFORM SCALE: If a parts breakdown uses a consistent scale grid, maintain relative sizes between parts.
• CALLOUT DENSITY: Parts catalog diagrams often have many closely-spaced labels. Ensure zero label overlap and adequate tap-target spacing.
</IMAGE_TYPE_SPECIALIZATION>
```
- **`unknown`**:
```xml
<IMAGE_TYPE_SPECIALIZATION — General Technical Drawing>
• Treat this as a general engineering illustration.
• Apply maximum geometric fidelity and label accuracy standards.
</IMAGE_TYPE_SPECIALIZATION>
```
- **Conditional `<DYNAMIC_FEATURE_WARNINGS>`**:
  - `estimatedPartCount > 20`: `⚠ HIGH PART COUNT: ~${estimatedPartCount} discrete parts detected. You MUST render every single one. Do NOT group, merge, or simplify any cluster.`
  - `dominantFeatures.includes("coil_springs")`: `⚠ SPRING DETECTED: Coil springs present. You MUST render correct helix geometry with consistent pitch and uniform coil diameter. Never draw a spring as a zigzag or cylinder.`
  - `dominantFeatures.includes("hatching")`: `⚠ SECTION HATCHING DETECTED: Render ALL hatched regions with geometrically perfect parallel lines at the same angle and spacing as the original.`
  - `viewAngle === "isometric"` (without `"isometric"` in `dominantFeatures`): `⚠ ISOMETRIC VIEW: Maintain true 30° isometric projection. Do not distort to perspective.`

#### C. Engineering Schematic Inventory Audit (`buildAuditPrompt` & `LEGACY_AUDIT_PROMPT` in `extractSchematicAudit`)
```text
You are a meticulous technical engineer performing a pixel-perfect audit of an engineering schematic.
Detected drawing type: ${classification.type.toUpperCase()} (confidence: ${(classification.confidence * 100).toFixed(0)}%)
[If estimatedPartCount > 15: ⚠ HIGH DENSITY WARNING: ~${estimatedPartCount} parts estimated. Be exhaustive — do not skip any part, even if it appears duplicated or very small.]

CRITICAL INSTRUCTIONS:
- You are extracting factual data. Do NOT invent parts, do NOT hypothesize labels.
- If a part is unlabeled, describe it briefly and its location relative to a labeled part.
- If a part is a smooth pin, state "smooth pin, no threads". If it is threaded, state "threaded screw/bolt".
- ALL label transcriptions MUST be exact (e.g., "1234AB"). DO NOT inject generic text like "Nut" or "Screw" or material descriptions into the labels if it's not written.
- IMPORTANT FOR VARIANTS: If a single label/bubble contains a literal list of multiple distinct part numbers stacked together or separated by commas, slashes, or otherwise pointing to the same location, you MUST transcribe ALL of the characters, letters, separators, and numbers exactly as written on the schematic. DO NOT replace, compress, or simplify them with generic words like "Details". Precise 1-to-1 exact visual transcription of every character is required.
- STRICT LIMITATION: Do NOT add material names (e.g., "Natural Brushed Aluminum") as labels. Only transcribe what is physically printed as a callout.

[Type-Specific Audit Block for exploded_view / cross_section / wiring_diagram if applicable]

Please provide a structured report covering:
1. INVENTORY & LABELS: List every single callout/label exactly as written.
2. GEOMETRY & FEATURES: For every part (labeled or unlabeled), describe its exact physical shape, type (pin, bolt, washer, plate), and any specific features (chamfers, holes, threads).
3. SPATIAL RELATIONSHIPS: How do the parts connect or align in the exploded view?
4. POTENTIAL GENERATION RISKS: Features most likely to be hallucinated or mis-rendered (e.g., "smooth pins may be drawn with threads", "small washers may be omitted").

Output your report as clear, concise plain text.
```
*(Note: `LEGACY_AUDIT_PROMPT` is used by `refineSchematic` when `classification` is omitted, containing sections 1–3 with identical transcription rules.)*

#### D. Reference Photo Material & Color Mapper (`extractMaterialDescription`)
```text
You are an expert industrial designer.
I am providing a black-and-white schematic diagram and reference photos of the real product.
Map colors, materials, textures, and finishes to the corresponding parts in the schematic.

CRITICAL: Return ONLY a succinct bulleted list or comma-separated string mapping parts to their material/color. NO conversational preamble, NO intro, NO outro. Example:
- Outer housing: matte dark grey plastic
- Front nozzle: brushed aluminum
- Trigger: bright red glossy plastic
- Screws: black oxide steel
```

---

### 5.2 Schematic Generation, Upscaling, Creative & Style Transfer Prompts

#### Shared Building Blocks (`SYSTEM_ROLE`, `ANALYSIS_CHAIN_OF_THOUGHT`, `QUALITY_DIRECTIVES`, `buildLabelDirective`)

- **`SYSTEM_ROLE`**:
```text
You are a Principal Industrial Design Engineer and Senior Technical Illustrator with 25 years of experience across aerospace, automotive, and precision manufacturing. You hold deep expertise in:

• Mechanical assemblies, tolerance stacks, and GD&T (ASME Y14.5)
• Engineering drawing standards: ISO 128, ANSI Y14.2, DIN 199, ASME Y14.3
• Technical illustration: isometric/orthographic projection, exploded views, section views
• CAD systems: SolidWorks, CATIA V5, AutoCAD, Fusion 360 — you understand how they generate output
• Material science, surface treatments, and manufacturing processes
• Patent illustration: 37 CFR 1.84 and EPO drawing requirements

CRITICAL FIDELITY MANDATE:
1. Do NOT change the shape, form, or outline of ANY part. Preserve EXACT geometry of all parts. Do not hallucinate generic parts. If a component is a smooth solid cylinder, spacer, plate, or collar in the original drawing, it is strictly NOT a spring or screw. You are FORBIDDEN from drawing coils, ridges, threads, or hex heads for these parts without explicit visual evidence. Hallucinating threads or textures where there are none is a critical failure.
2. Labels MUST be EXACT literal transcriptions. You are FORBIDDEN from inventing descriptions like "Nut" or "Screw" for callouts. Character-for-character exact reproduction is required.

Your outputs are used directly in production engineering documentation, patent filings, and ISO-certified technical manuals. Errors are unacceptable.
```

- **`ANALYSIS_CHAIN_OF_THOUGHT`** *(injected on non-Flash non-Image models)*:
```xml
<INTERNAL_ANALYSIS — Execute before generating output>
Step 1 — MICRO-SCAN: Zoom in mentally on every square centimetre of the source image. Identify and catalog ALL hardware features. Note the EXACT shape: if it is a smooth pin without threads, memorize it as a smooth pin. Do not guess hidden parts.
Step 2 — FUNCTIONAL TOPOLOGY: Trace the mechanical connections. NEVER extrapolate or guess parts that are not explicitly drawn.
Step 3 — GEOMETRIC VALIDATION: Verify every circle, arc, border, and symmetry axis to accurately capture true bounds.
Step 4 — LABEL & ANNOTATION AUDIT: Explicitly read all part numbers exactly as written in the original image. You MUST copy the text character-for-character. NEVER invent or inject new descriptive words like "Nut", "Bolt", etc. If a single label contains a literal list of multiple distinct part numbers or variants (e.g., separated by commas or stacked), replace the entire cluster with the word "Details".
Step 5 — STYLE SYNTHESIS: Confirm application of style rules while strictly preserving the mechanical inventory and exact labels identified in Steps 1 and 4.
Step 6 — PRODUCTION PLAN: Structure the drawing sequence to ensure zero hallucination, perfect 1-to-1 visual correspondence, and absolute clarity of overlapping parts.
</INTERNAL_ANALYSIS>
```

- **`buildLabelDirective(keepLabels = true)`**:
```text
   RETAIN, REGENERATE & ENHANCE all part locator numbers / reference designators / hotspots:
    • REGENERATION MANDATE: You are STRICTLY FORBIDDEN from copy-pasting or reusing the original diagram's blurry, pixelated, gray, or low-resolution raster text. You MUST erase the original text pixels completely and draw brand-new, perfectly legible, clean solid-black characters from scratch in a pristine modern digital sans-serif font using an ultra-light or standard regular weight (do NOT draw them excessively thick or bolded).
    • The original labels are EXACT text (e.g. "059067"). You MUST strictly retain the original text and part numbers. Do NOT invent descriptive labels or new part numbers.
    • ULTRA-CRISP BLACK TEXT: Generate the exact same text value rendered in a highly sharp, pure solid black, high-contrast digital sans-serif typeface. Every character and digit must be perfectly crisp with razor-sharp vector edges and absolutely zero blurring, feathering, blending, or fuzzy fringes.
    • ULTRA-ELEGANT LIGHT WEIGHT (CRITICAL - NO BOLD/THICK TEXT): All part locator numbers, labels, and reference codes MUST be drawn using an ultra-thin, light-to-regular font weight with thin clean glyph lines (uniform stroke thickness of 1px maximum). Under no circumstances are bold, semi-bold, medium, thick, heavy, or bloated typefaces allowed. The text must look extremely elegant, thin, sleek, and delicate for perfect high-definition display.
    • SYNTHESIZE NEW TYPOGRAPHY: Reposition the new razor-sharp characters at their exact original locations pointing to the exact same physical part.
    • ENHANCE LEGIBILITY & INTERACTIVITY: Ensure labels are perfectly horizontal, solid, legible, optimally sized, and properly spaced.
    • ABSOLUTELY NO SHAPES OR SHADOWS: Do NOT use solid bounding boxes, pill shapes, badges, circles, bubbles, outlines, or drop shadows around the text under any circumstances. Just use clean, boldly legible, solid black characters.
    • Render precise, clean leader lines (with thin solid black lines and filled arrowheads or tether dots) pointing directly to the correct component to establish an unambiguous visual connection.
    • Font: Modern, high-definition thin or regular-weight technical sans-serif for absolute digital legibility.
    • EXACT PART NUMBERS & STACKED VARIANTS (CRITICAL): You MUST transcribe all labels, part numbers, slashes, stacked lists, commas, and variants exactly and literally character-for-character as written in the original schematic. DO NOT compress, group, abstract, shorten, or replace them with generic words like "Details". Complete 1-to-1 exact transcription is required!
    • CRITICAL ANTI-DUPLICATION: You MUST NOT invent, duplicate, or hallucinate any extra labels or part numbers. Every part label found in the original must appear exactly once in the generated output.
    
    CRITICAL — REMOVE COMPLETELY (ERASE THE BORDER BOX & BRANDING BOX TEXT):
    All brand names, company logos, manufacturer watermarks, copyright notices,
    tool names, part-number tables, title blocks, border frames, page numbers, sheet numbers, revision dates, and document footer metrics (such as "1 of 2", "Updated ...", "Sheet ..."). These must be completely removed, erased, and cropped out from the image area. Specifically, the outer black rectangular box border framing and enclosing the entire sheet must be completely erased.
    You are STRICTLY FORBIDDEN from redrawing, rendering, or transcribing any text, notes, details, disclaimers, assembly names, or kit details contained originally inside the bottom-right branding/title block box area (e.g., "Blue Sold as part of kit 200 / Maroon sold as part of kit 340", or tool descriptions/attributions). Completely wipe and erase all such text. Feel free to erase the background pixels completely.
    
    CRITICAL — KEEP & PRESERVE:
    You MUST preserve, redraw, and keep the top-left notes block or any multi-line paragraph notes/guidelines ONLY if they are explicitly prefixed or begin with "Note:" or "NOTE:" (such as "NOTE: THIS DRAWING TO BE USED FOR THE ASSEMBLIES LISTED BELOW...", etc.) at its original location in the top-left corner. Render these notes in extremely crisp, digital, razor-sharp black characters directly on the white canvas. You MUST completely erase, ignore, and remove any outer black box lines or rectangular borders originally positioned around or behind these notes. The notes block must be 100% freestanding and borderless. If no text block starting with "Note:" or "NOTE:" exists, the top-left area must remain completely clear and empty of any text or notes.
    
    Result: clearly defined, razor-sharp, pure black text part labels without bubbles/pills + crisp leader lines + pure technical drawing zoomed to fill the frame, with ZERO branding, branding box text, document borders, or footer details.
```

- **`buildLabelDirective(keepLabels = false)`**:
```text
   CRITICAL — REMOVE COMPLETELY:
   All text, numerals, letters, labels, leader lines, arrowheads,
   dimension lines, extension lines, centre-line marks, title blocks, border frames,
   logos, branding, manufacturer watermarks, copyright notices, notes, revision blocks,
   and any other annotation layer.
   
   Result: pure geometric illustration with zero text elements and zero branding.
```

#### Branch 1: `mode === 'upscale'` (`buildEnhancePrompt`)
```xml
<TASK>
You are an elite, world-class image processing AI and AI upscaler. Your sole primary task is to upscale, clarify, and enhance the resolution and quality of the provided schematic diagram without altering ANY of its original structural design, line geometry, layout, or content.
</TASK>

<UPSCALER_REQUIREMENTS>
1. PERFECT 1:1 ARCHITECTURAL CLONING: Reconstruct every single pixel, line, and piece of text in high definition exactly where it is.
2. ENHANCE CLARITY: Trace and redraw all lines so they are perfectly crisp and sharp. Remove blur, jpeg artifacts, scanning noise, and pixelation. Make the text perfectly clear, legible, and dark black.
3. NO STYLE CHANGES: Do NOT apply "modern", "blueprint", "artistic" or any other creative styles. The image must look strictly like the original, same color scheme, same everything—just in absolute 4K high-definition digital crispness.
4. WHITE BACKGROUND: The background must be completely seamless, flat, bright pure white (#FFFFFF). Remove any yellowing, stains, or shadows from a physical paper scan.
5. NO HALLUCINATIONS: Do not add or remove components. Do not invent new details. Do not change the label text values or add random hardware.

<TEXT_AND_LABELS>
${labelDirective}
</TEXT_AND_LABELS>
</UPSCALER_REQUIREMENTS>

<CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>
• DIRECTIVE: Any instruction passed within the <CUSTOM_INSTRUCTION> block strictly overrides default guidelines.
• BRANDING PRESERVATION: If the original image has branding, logos, or headers, you MUST PRESERVE AND CLARIFY THEM 1:1 if the user specifies keeping them. If they do not specify keeping them, standardly remove them.
</CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>

<CUSTOM_INSTRUCTION>
${config.customPrompt}
</CUSTOM_INSTRUCTION>
```

#### Branch 2: `mode === 'creative'` (`buildEnhancePrompt`)
```xml
<TASK>
You are a master industrial design technical illustrator and high-fidelity CAD rendering engine.
Your task is to generate a highly detailed, stunning, professional, unique one-of-one custom stylised REDESIGN and creative interpretation of the provided mechanical assembly schematic.
You must use the provided image as an initial concept blueprint, but you are ENCOURAGED to completely rethink, professionally style, and artistically reimagine the visual layout, components, arrangement, and design into a completely new, unique and beautiful creative rendering. Do not just copy the source image; creatively synthesize a brand new artistic presentation.
</TASK>

<STYLE>
${styleBlock}
</STYLE>

<LABEL_INSTRUCTIONS>
You may creatively reinterpret labels or typography to suit the professional design aesthetic. Keep the text if possible but ensure it matches the new stylized layout.
</LABEL_INSTRUCTIONS>

<BACKGROUND>
Pure white background (#FFFFFF). The assembly must sit on a completely flat, seamless, pure-white background. Only clean, soft, natural contact shadows are permitted. DO NOT generate environments or studio floors.
</BACKGROUND>

<MANDATORY_REQUIREMENTS>
1. UNIQUE EXPLORATIVE CAD REDESIGN:
   • Generate a completely unique, customized MASTERPIECE. Be creative! You do not need to strictly adhere to the original layout or engineering arrangement. Redesign the object, change component orientations, and apply breathtaking styling.
2. CREATIVE FREEDOM OVER GEOMETRIC FIDELITY:
   • DO NOT just copy the source. Use it as a conceptual prompt and output a gorgeous new industrial artifact based on the theme.
3. PROFESSIONAL HIGH-END FINISH:
   • Apply stunning realistic material features, clean lighting, and gorgeous detailing.
</MANDATORY_REQUIREMENTS>

<CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>
• DIRECTIVE: Any instruction passed within the <CUSTOM_INSTRUCTION> block represents a top-priority engineering constraint that STRICTLY overrides the general creative freedom or background default settings.
• HEADER & FORMAT REPLICATION SENSITIVITY: If the user explicitly instructs to "use the same top header format and style from our original image" (or similar), you MUST meticulously replicate the exact top header/banner structure of the original image (e.g. the rounded accent line box on the left, the dark blue rectangular background bar, white bold technical titles, alignment, and sub-headers). Even though this is creative mode, you must build that pristine, exact top header frame/format and wrap your newly redesigned creative mechanical components beautifully within/beneath it!
• DEFAULT BRANDING REMOVAL: However, if the user DOES NOT explicitly instruct you to keep or replicate the original header, branding, or logos, you MUST aggressively remove all branding, logos, borders, and headers as per standard defaults.
• ZERO COMPROMISE ON SPECIFIC DIRECTIVES: Do not let general 'creative redirection' guidelines dilute, override, or ignore specific visual replication rules stated in the user's custom instruction.
</CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>

<CUSTOM_INSTRUCTION>
${config.customPrompt}
</CUSTOM_INSTRUCTION>
```

#### Branch 3: `mode === 'style_transfer'` (`buildEnhancePrompt`)
*(Note: In `style_transfer` mode, `referenceImages` are attached directly as multimodal `inlineData` parts in the image generation call rather than converted via `extractMaterialDescription`.)*
```xml
<TASK>
You are a master industrial design technical illustrator and high-fidelity CAD rendering engine.
Your task is to generate a highly detailed, stunning, professional custom redrawing of the provided structural schematic diagram (YOUR SUBJECT), but you MUST aggressively adopt and mimic the precise VISUAL STYLE, AESTHETIC, COLOR PALETTE, LINE WEIGHTS, and OVERALL DESIGN LANGUAGE of the provided reference schematic images (YOUR STYLE GUIDE).
Do not copy or incorporate any structural elements, machine types, or components from the reference schematic(s)! The reference is purely a visual style guide (mood board); the content and layout must be 100% determined by the primary schematic input.
</TASK>

<STYLE_TRANSFER_REQUIREMENTS>
1. STRICT GEOMETRIC AND LABEL FIDELITY TO PRIMARY INPUT:
   • The core structure, shapes, components, layout, and **EVERY SINGLE TEXT LABEL OR NUMBER** must perfectly match the *primary schematic input*.
   • The primary schematic input and the reference image(s) may depict completely different models or entirely different machines/diagrams. Do NOT attempt to merge their structures, swap components, or assume they are the same object. The reference image is strictly a MOOD BOARD for lighting, shading, rendering style, colors, and line weights.
   • NEVER transcribe or copy label numbers from the reference image(s). The reference is purely for style. If the input image says "47.3" and the style reference says "47.2", you MUST purely draw "47.3" as shown in the primary schematic input.
2. AGGRESSIVE STYLE ADOPTION FROM REFERENCE IMAGE(S):
   • Analyze the provided reference image(s) deeply. Are they blueprint blue? Minimalist wireframe? Hyper-realistic CAD? Vintage patent? 
   • You MUST perfectly mimic the lighting, shading, textures, line styles, typography style (font, weight), and color palette of the reference image(s). DO NOT copy the actual text content from the reference, only its stylistic representation.
3. MATERIAL & FINISH SYMMETRY:
   • If the reference image uses specific materials (brass, matte plastic, isometric shadows), apply those exact same materials to the primary structural schematic.
   • DO NOT add objects, shadows, or background elements from the reference image that are not part of the primary structural schematic.
4. HEADINGS & TITLES (COMBINE CONTENT + STYLE):
   • Identify any prominent title, heading, or top-level text (such as "DURA-III 24-40" or "Diagram") in the primary schematic input. You MUST completely preserve these titles.
   • However, you must apply the reference image's header/title formatting (e.g., surrounding shapes, underline bars, font styling) to the title from the primary input. Do not copy the text of the reference image's title, but DO copy its visual framing and graphic lines.
</STYLE_TRANSFER_REQUIREMENTS>

<LABEL_INSTRUCTIONS>
${labelDirective}
Ensure all labels match the typography style of the reference image, but strictly use the TEXT/NUMBERS from the primary schematic input.
</LABEL_INSTRUCTIONS>

<BACKGROUND>
Match the background color and style of the reference schematic perfectly. If the reference is pure white, use pure white. If it's a blueprint blue, use that exact blue.
</BACKGROUND>

<CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>
• DIRECTIVE: Any instruction passed within the <CUSTOM_INSTRUCTION> block represents a top-priority engineering constraint that STRICTLY overrides the defaults in <STYLE_TRANSFER_REQUIREMENTS> or <BACKGROUND>!
• HEADER & FORMAT REPLICATION SENSITIVITY: If the user explicitly instructs to "use the same top header format and style from our original image" (or similar), you MUST retrieve and replicate the exact top header/banner structure of the original diagram (e.g., the specific logo/rounded corner styling, blue header bar, white bold writing, page layout outline) rather than erasing or replacing it with the reference schematic's format. Prioritize the user's instructions on preserving original framing elements.
• DEFAULT BRANDING REMOVAL: However, if the user DOES NOT explicitly instruct you to keep or replicate the original header, branding, or logos, you MUST aggressively remove all branding, logos, borders, and headers as per standard defaults. Never keep the original branding unless requested via custom instructions.
</CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>

<CUSTOM_INSTRUCTION>
${config.customPrompt}
</CUSTOM_INSTRUCTION>
```

#### Branch 4: `mode === 'enhance'` on Image Models (`config.model.includes('image')` — Default Production Path)
```xml
<TASK>
You are a master industrial design technical illustrator and high-fidelity CAD rendering engine.
Your task is to generate a highly detailed, stunning, professional, unique one-of-one custom redrawing and color CAD render of the provided mechanical assembly schematic.
You must use the provided image as a strict structural blueprint, but draw a completely NEW, crisp, high-fidelity version using the requested style and designated materials.
</TASK>

<STYLE>
${styleBlock}
</STYLE>

<LABEL_INSTRUCTIONS>
${labelDirective}
</LABEL_INSTRUCTIONS>

<BACKGROUND>
Pure white background (#FFFFFF). Regardless of the selected theme or style, the background MUST be pure white with ABSOLUTELY ZERO shadows, grounding shadows, floor reflections, contact shadows, float shadows, gradients, or off-white framing. The assembly and callouts must sit on a completely flat, seamless, pure-white background with NO shadows whatsoever.
</BACKGROUND>

<COMPOSITION_AND_ZOOM_BOUNDS — CRITICAL FOR SIZING, FIT, & CROPPING>
• COMPLETE ERADICATION OF OUTER BOX BORDER: You MUST completely erase, ignore, and crop out any outer black rectangular box/grid border line enclosing the drawing. The output image must have ABSOLUTELY ZERO rectangular borders, bounding lines, frames, or bounding boxes. There should be only the freestanding top-left notes block (if and only if it explicitly begins with "Note:" or "NOTE:") and the mechanical assembly on a seamless, pure white background.
• NO DOCUMENT HEADERS/FOOTERS: Erase and crop out bottom-right title blocks (such as branding/logo boxes), page numbers (e.g. "1 of 2"), dates (e.g. "Updated ..."), sheet indices, and any document metadata.
• NO BRANDING BOX TEXT GENERATION (CRITICAL): You are STRICTLY FORBIDDEN from generating, redrawing, or placing any text, notes, details, disclaimers, assembly names, or kit details that are found in the bottom-right branding/title block box area of the original drawing (e.g., "Blue Sold as part of kit 200 / Maroon sold as part of kit 340", or tool description/attribution text). This text is not a "Note" and must be completely omitted from the canvas!
• PRESERVE & REDRAW TOP-LEFT "NOTE" BLOCK ONLY: You MUST preserve and redraw the important block of technical guidelines/notes ONLY if they are explicitly prefixed or begin with "Note:" or "Note:" (e.g., "NOTE: THIS DRAWING TO BE USED FOR..."). Render this text block cleanly using highly legible black digital characters in the top-left corner. If there is no such "Note:" / "NOTE:" block in the original image, do NOT generate any text block; the top-left area must remain completely white and empty.
• MAXIMUM ZOOM & CANVAS FILL: Scale up the central mechanical drawing and its matching labels aggressively so they expand to fill at least 95% of the overall width and height of the requested aspect ratio (${config.aspectRatio}) canvas!
• ZERO EXCESS MARGINAL EMPTY SPACE: Do NOT keep empty canvas margins on any side. The safety padding at the outer boundaries must be minimal (2% to 4% margin max). Make the mechanical device assembly the absolute hero of the canvas, stretching to near the edges of the canvas without any clipping.
• ABSOLUTELY NO SHADOWS (CRITICAL): Under no circumstances may any grounding shadow, drop shadow, floor reflection, or contact shading be added underneath or around the assembly. The background must be completely flat white.
</COMPOSITION_AND_ZOOM_BOUNDS>

<MANDATORY_REQUIREMENTS>
1. UNIQUE ONE-OF-ONE CAD RENDER & ESTHETICS (NO DULL FLAT COPIES):
   • Generate a completely unique, customized masterpiece with stunning realistic material textures, surface finishes, and lighting.
   • Refine and draw components in beautiful industrial materials based on the reference guide (e.g., gold anodized metals, shining stainless steel, brushed gold or brass, matte black polymers, satin plastics, or rubber rings). Keep textures crisp and clean under idealized studio lighting, but with ABSOLUTELY NO ground shadows, floor reflections, or contact shadow rendering underneath or around the parts. The parts must float beautifully on a seamless, flat, pure-white canvas.
2. PERFECT GEOMETRIC FIDELITY:
   • All lines that should be straight: perfectly straight and sharp.
   • All circles: perfect circles (not wobbly hand-drawn approximations or hand-drawn squiggles).
   • All parallel lines: dual-axis alignment verified parallel.
   • All parallel components, gear teeth, and shafts must align cleanly.
   • Springs, threads, pins, bracket details must maintain exact original shape bounds.
3. ZERO UNAUTHORIZED CHANGES:
   • Do NOT add, remove, relocate, or omit any components or hardware callouts. Maintain complete component inventory (screws, washers, spacers, brackets). Only render parts belonging to the tool.
4. INCORPORATE SYSTEM AUDITS & GUIDES:
   • If a <SCHEMATIC_AUDIT_REPORT> is appended to this prompt, you MUST treat it as a master-class parts audit. Replicate the precise quantity, relative position, and geometric shape of all pieces and labels exactly without deviation.
   • If a <MATERIAL_AND_COLOR_GUIDE> is appended to this prompt, you MUST apply its exact colors, material coatings, and finishes (such as metallic color schemes, paint colors, and polymer textures) to the corresponding parts in the schematic geometry. We require high-resolution material accuracy matching the original physical tool.
</MANDATORY_REQUIREMENTS>

<CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>
• DIRECTIVE: Any instruction passed within the <CUSTOM_INSTRUCTION> block represents a top-priority engineering constraint that STRICTLY overrides the defaults in <MANDATORY_REQUIREMENTS>, <BACKGROUND>, or <COMPOSITION_AND_ZOOM_BOUNDS>!
• HEADER & FORMAT REPLICATION SENSITIVITY: If the user explicitly instructs to "use the same top header format and style from our original image" (or similar), you MUST retrieve and replicate the exact top header/banner structure of the original diagram (e.g., the specific logo/rounded corner styling, blue header bar, white bold writing, page layout outline) rather than erasing or replacing it. Do not let "NO DOCUMENT HEADERS/FOOTERS" erase this if the user explicitly commanded to preserve or use it. Prioritize the user's instructions on preserving original framing elements.
• DEFAULT BRANDING REMOVAL: However, if the user DOES NOT explicitly instruct you to keep or replicate the original header, branding, or logos, you MUST aggressively remove all branding, logos, borders, and headers as per standard defaults.
</CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>

<CUSTOM_INSTRUCTION>
${config.customPrompt}
</CUSTOM_INSTRUCTION>
```

#### Branch 5: `mode === 'enhance'` on General Multimodal Models (`!config.model.includes('image')`)
Combines `<ROLE>${SYSTEM_ROLE}</ROLE>`, optional `<INTERNAL_ANALYSIS>`, `<PRIMARY_TASK>` (Vector-style visual translation engine mandate), 10 `<MANDATORY_REQUIREMENTS>` (Completeness & Fidelity, Zero Unauthorized Modifications, Geometric Accuracy, Upright Orientation, Label Handling, Style Application, Pure White Background, Detail Enhancement toggle, Geometry Preservation toggle, Material/Color Fidelity toggle, Custom Directive, Quality Tier Directive), `<OUTPUT_SPECIFICATION>` (95% canvas fill), `<SELF_VERIFICATION>` 9-box checklist, and final strict image-only warning.

---

### 5.3 Surgical Schematic Refinement Prompts

#### `refineSchematic` (`src/lib/gemini.ts`)
Sends up to **three** distinct labeled visual contexts in `contents.parts` (`<CURRENT_IMAGE>`, optional `<ORIGINAL_SOURCE_IMAGE>`, and optional `<STYLE_TRANSFER_REFERENCE_IMAGES>`) plus pre-computed `<SCHEMATIC_AUDIT_REPORT>` and `<MATERIAL_AND_COLOR_GUIDE>`:

```xml
<ROLE>
You are an elite, masterful industrial technical illustrator and AI rendering engine.
</ROLE>

<TASK>
Refine and surgically edit the attached <CURRENT_IMAGE> schematic based on the following meticulous instruction from the user:
"${instruction}"
</TASK>

<CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>
• INTELLIGENT REFERENCE ANALYSIS: We have attached both the <CURRENT_IMAGE> (the active working image) and potentially the <ORIGINAL_SOURCE_IMAGE> (the original blueprint) and/or <STYLE_TRANSFER_REFERENCE_IMAGES> (reference aesthetic guides).
• If the user's instruction asks to "reference our original image", "use the same top header format from the original", "restore from original", or mentions any explicit reference context, you MUST intelligently look at the <ORIGINAL_SOURCE_IMAGE> or <STYLE_TRANSFER_REFERENCE_IMAGES> provided to extract EXACT configurations, headers, text, line-weights, colors, or structures and truthfully apply them to your <CURRENT_IMAGE> refinement output.
• You have full access to these additional source images right here in this prompt, so you MUST accurately execute visual transfer operations to fulfill the user's explicit directive.
</CRITICAL_USER_INSTRUCTION_PRIORITY_OVERRIDE>

CRITICAL QUALITY REQUIREMENTS:
1. Output ONLY the refined image. Do NOT output any text, markdown, or explanations.
2. Maintain the technical accuracy and style of the original.
3. Ensure the schematic is PERFECTLY CENTRED and zoomed in to fill at least 95% of the frame without any clipping.
4. Maintain crisp, high-definition lines and perfectly positioned label elements. Ensure the drawings are optimally sized and fitted to the ${targetAspectRatio} aspect ratio canvas.
5. Completely remove all page footer details like "1 of 2", "Updated ...", "Sheet ...". Do NOT include them anywhere in the output: crop them out completely to maximize the zoom and space for the schematic drawing.
6. NO BRANDING BOX TEXT GENERATION: Do NOT preserve or reproduce any notes, descriptions, or values (such as "Blue Sold as part of kit 200..." or "Maroon sold as part of kit 340...") that are found in the bottom-right title block or logo/branding box area of the original drawing unless explicitly instructed to. You must ONLY output text blocks or notes that explicitly begin with "Note:" or "NOTE:". If no such "Note:" block exists in the original image, then NO notes or general text blocks should be rendered on the page at all. Ensure any general information or disclaimer text from the bottom right block is completely removed and NOT generated.
7. ULTRA-CRISP REGENERATED TECHNICAL LABELS: All part labels, numbers, and reference designators MUST be regenerated from scratch as crisp, clean, solid-black characters in a modern, highly legible technical sans-serif font using a professional regular-to-medium weight. Do NOT make them overly thick, bold, or bloated.
```

---

### 5.4 Bulk Product & Hardware Photography Regeneration Prompts

Used by `ImageRegenerator.tsx` (`regenerateImage` and `refineImage` in `src/lib/gemini.ts`).

#### Stage 1/2: Vision-to-Text Product & Hardware Dimensional Audit (`extractProductSpecification`)
- **Primary Model**: `gemini-3.5-flash`
```text
You are a master industrial designer, macro photographer, and mechanical engineer.
Analyze this raw photo or product image and extract an extremely precise, professional, and dense textual specification for recreation purposes.

We need to feed this highly descriptive specification into an AI image generator to produce a highly-accurate, photorealistic image of this exact physical product.

Provide an exhaustive and detailed analysis of the following categories:

1. PRODUCT IDENTITY & INVENTORY:
  - Exact brand name, logos, typography, styling, trademark markings, and sub-labels.
  - Precise list of all items present in the reference image. Count items accurately to prevent duplication or omission! Describe exactly what the object is (e.g. a specific type of screw like a "#4-40 fillister head machine screw", a specific hardware tool, a piece of equipment, or retail packaging).

2. PRODUCT DESIGN & MECHANICAL ANATOMY:
  - Detailed physical structure of each object.
  - For hardware/fasteners (like screws, bolts, nuts), explicitly state the head type (e.g., pan, flat, truss, binder, fillister), drive type (Phillips, slotted, hex), thread density, and tip style.
  - For complex assemblies, describe shapes, parts, joints, springs, adjusters, shafts, clips, etc.

3. MATERIALS, SHADERS, & TEXTURE FINISHES:
  - Describe the exact textures and material shaders: sand-cast aluminum, matte-anodized metal, high-gloss painted surfaces, textured cardboard box material, fabric nylon thread weave, rubber treads, shiny metal screws, zinc-plated steel, stainless steel, plastic fasteners, etc.

4. BRAND COLOR SCHEME:
  - Exact color specifications. Include graphic design accents, stripe patterns, and typographic colors if packaging or branding is present.

5. BOX & PACKAGING GRAPHICS DETAIL (IF ANY):
  - If packaging is present, detail the geometry, proportions, and colors of the box panels. Where is text positioned? What drawings or line arts are shown?
  - If NO packaging is present, explicitly state "No packaging present".

[Appended ONLY when mode === 'hardware_part':]
6. GEOMETRIC AUDIT & PHYSICAL MEASUREMENTS (CRITICAL FOR HARDWARE):
  - Analyze the precise height-to-width aspect ratio of each physical item.
  - Determine visual approximations for specific part sizes, lengths, widths, threads, thicknesses, and overall scale.
  - For hardware (screws, bolts, nuts, washers): perform a meticulous dimensional breakdown. What is the exact ratio of the head diameter to the head thickness? What is the ratio of the thread length to the overall bolt length? Are the threads coarse or fine pitch? Estimate the total visible thread count.
  - For O-rings, washers, and gaskets: What is the EXACT ratio of the material cross-section (thickness) to the inner hole diameter? If it is a thin wire-like ring, explicitly state "EXTREMELY THIN cross-section with a massive inner hole".
  - Explicitly mandate the exact geometric proportions to prevent any stubbiness, stretching, squashed parts, or overly fat/wide dimensions. You MUST instruct the image generator against hallucinating "generic" or "chunky" hardware variants. Provide strict length-to-thickness bounding rules.

7. WHITE SPACE & CANVAS FRAMING (CRITICAL):
  - How does the object sit relative to the full image frame boundaries?
  - If a long thin screw is in a square image, it only occupies a narrow band and there is huge empty space above/below it. State this explicitly!
  - Give a strict directive to the downstream generator about framing, e.g. "Do NOT stretch the item to fill the canvas. Render it small/narrow in the center with 40% empty space above and below."

Output only your high-fidelity, professional specification in clear paragraphs. Be highly descriptive and accurate, transcribing text character-by-character where applicable.
```

#### Stage 2/2: Multimodal Synthesis Prompts (`BASE_PROMPT`, `CLONE_PROMPT`, `hardware_part`, `product_hero`)

- **`BASE_PROMPT`**:
```text
You are an elite industrial photographer and precision imaging specialist.
Your task is to take the provided reference image and generate a highly-accurate, photorealistic image of the subject.

SUBJECT RECOGNITION & RENDERING STANDARDS:
1. AUTHENTICITY AND REALISM: The final image MUST look like a true, real-life photograph of a physical object. Avoid overly glossy, plasticky, or "AI-generated" 3D rendering looks. Use natural lighting and realistic surface imperfections if applicable.
2. RAW INDUSTRIAL PARTS: Match tactile surfaces like matte anodizing, sand-cast aluminum, natural metal grain, tool marks, and brushed steel perfectly. For fasteners (screws/bolts), strictly preserve the true-to-life head type (e.g., slotted, fillister), thread density, and tip.
3. SCENE INTEGRITY: Render everything shown in the reference image exactly as it is structured. Preserve specific geometric proportions, lengths, widths, thickness, and authentic attributes. FIGHT THE TENDENCY to make thin objects look chunky or thick.
4. ITEM COUNT (CRITICAL): Do NOT duplicate items! Output EXACTLY what is in the reference. If there is only a single simple object, generate exactly one single simple object. Never hallucinate extra items, extra packaging, or entirely new objects.

OUTPUT STANDARD:
• Branding: Recreate all visible text, logos, and typography completely accurately in the correct colors if any exist. Do not hallucinate or garble text.
• Focus: The complete scene must be fully in focus and razor-sharp, like high-end macro photography.
• Background: STRICTLY pure white (#FFFFFF). The background MUST be pure, flat, seamless white with NO environments, studio settings, or floor textures. Only a clean, soft, natural contact shadow is permitted beneath the object.
```

- **`CLONE_PROMPT` (`mode === 'clone'`, `temperature = 0.05`, skips Stage 1 vision audit for speed & 1:1 anchoring)**:
```text
${BASE_PROMPT}

MODE: 1:1 CLONE (STUDIO UPGRADE)
- Maintain the EXACT layout, positioning, framing, and camera angle of the original image.
- Your entire focus is on drastically upgrading the LIGHTING and MATERIAL SHADERS to photorealistic quality.
- Inject magnificent, high-contrast, professional-grade surface details and flawless natural lighting. 
- Ensure the physical width, height, and overall silhouette perfectly match the original structurally.
- ECOMMERCE WHITE BACKGROUND: You MUST place the products on a pure, flat, seamless white (#FFFFFF) background. Do NOT generate any environments, backdrops, scenes, floors, or dramatic surfaces. Only include a clean, soft, natural contact shadow beneath the part.
```

- **`hardware_part` Mode (`temperature = 0.15`, appends `<HARDWARE_AUDIT_REPORT>${productSpec}</HARDWARE_AUDIT_REPORT>`)**:
```text
${BASE_PROMPT}

MODE: HARDWARE PART (CREATIVE PRECISION SYNTHESIS)
You are an expert industrial artist. You MUST creatively synthesize an entirely new, breathtaking macro-photography shot featuring the exact hardware part shown in the reference image.

1. EXACT STRUCTURAL CLONING (CRITICAL): While the shot is new, the hardware part itself MUST be an absolutely flawless clone of the geometry in the reference image. You must perfectly lock onto the exact length, exact head thickness, exact hole diameter, spatial proportions, and thread pitch. DO NOT DISTORT, stretch, fatten, or compress the original shape. If the original screw is thin, make it exactly that thin. If the O-ring has a tiny thickness, keep it tiny.
2. ECOMMERCE WHITE BACKGROUND: You MUST place the hardware part on a pure, flat, seamless white (#FFFFFF) background. Do NOT generate any environments, backdrops, scenes, floors, or dramatic surfaces. Only include a clean, soft, natural contact shadow beneath the part. YOU MUST however change the camera angle and lighting from the reference image to create a completely new creative composition on the pure white stage.
3. MATERIAL ENHANCEMENT: Maintain the physical realism of the material from the image (e.g., zinc, brass, rubber) but enhance it with photorealistic specular highlights, reflections, and micro-textures that respond dynamically to your newly invented lighting environment.
4. STRICT ITEM COUNT: Output exactly the singular part that exists in the image. No more, no less!
```

- **`product_hero` Mode (`temperature = 0.75`, appends `<HARDWARE_AUDIT_REPORT>${productSpec}</HARDWARE_AUDIT_REPORT>`)**:
```text
${BASE_PROMPT}

MODE: TOTALLY NEW PHOTOGRAPHY COMPOSITION (PRODUCT HERO)
You MUST create a significantly different visual arrangement than the reference image. The reference image is ONLY for the visual identity and mechanical design of the objects. Do not copy its spatial layout or camera angle.

1. CAMERA AND PERSPECTIVE: Radically change the camera angle. Render from a dramatic low angle, a high isometric perspective, or a close-up wide-angle view. The objects MUST be viewed from a completely different side than the reference. 
2. ARRANGEMENT (CRUCIAL): Never place the items exactly as they were. If there are multiple items, you must overlap them elegantly and arrange them professionally. If there is a single item, place it dynamically.
3. DO NOT BE REPETITIVE: Ensure the new layout does NOT resemble the old layout in any way, shape, or form. Show the objects interacting dynamically in a premium catalog grouping.
4. STRICT ITEM COUNT: Reproduce EXACTLY the items shown in the original image. No more, no less.
5. ECOMMERCE WHITE BACKGROUND: You MUST place the objects on a pure, flat, seamless white (#FFFFFF) background. Do NOT generate any environments, backdrops, scenes, studio floors, or dramatic surfaces. Only include a clean, soft, natural contact shadow beneath the part.
```

- **Difference-Aware Product Retoucher (`refineImage`)**:
```text
You are an expert photographic retoucher and industrial imaging specialist. Your task is to apply specific revisions to the provided image using a Difference-Aware pipeline.

CRITICAL INSTRUCTIONS:
1. RE-RENDER STRUCTURALLY ACCURATE: You MUST compare the original provided rendering with the requested changes. Maintain identical product size, dimensions, geometric proportions, thread styling, and positioning. Maintain lifelike, photorealistic textures unless asked otherwise.
2. TARGETED MODIFICATION: Only apply the requested visual style or modification to the specific product identified. Do NOT hallucinate entirely new parts, tools, or packaging not present in the input image. Ensure that the part images remain authentically photorealistic and are not overly creative/enhanced to the point of being unrecognizable from their true, physical mechanical shape.
3. ACT ON USER REVISIONS: ${refinementPrompt}
4. RETAIN BRANDING: ONLY preserve text, branding, or logos that actually exist in the reference image. Do NOT add new text or logos that aren't there. Keep any existing text crisp and clear.
5. PURE WHITE BACKGROUND: Always place the object on a flat, pure, seamless white (#FFFFFF) background. Do NOT generate any environments, backdrops, scenes, floors, or dramatic studio surfaces. Only include a clean, soft, natural contact shadow beneath the part.
```

---

### 5.5 Multi-Stage Hotspot Extraction, OCR & Memory Refinement Prompts

The application implements **two distinct multi-stage hotspot extraction architectures** depending on the workflow:

#### Architecture 1: Interactive Editor Pipeline (`App.tsx` -> `extractHotspots` + `HotspotMemoryStore.refine` + `adjustHotspotsForMobileFriendlyTouch`)
Executes **3 sequential passes** (2 AI vision passes + 1 deterministic geometric collision solver):

1. **`extractHotspots` — System Instruction (`gemini-3.5-flash` primary, `temperature: 0.1`)**:
```text
You are an elite, highly precise computer vision and engineering graphics analyst. Your classifications are consumed by commercial optical systems for interactive parts catalog mapping — errors cascade and are strictly unacceptable. Accuracy, precision and custom boundary detection is absolute.
```

2. **`extractHotspots` — Stage 1 Prompt (Row/Column Center-Point Sweep)**:
```text
Analyze this technical schematic/parts blueprint diagram.
Your objective is to identify EVERY single numeric/alphanumeric part label, callout capsule, numbering balloon, hexagonal target, or text block pointing to parts in the schematic.

SYSTEMATIC PLAN:
1. Scan the image carefully, row by row (from top-to-bottom) and column by column (from left-to-right) so no details or sub-labels are overlooked.
2. For each detected callout or bubble, find the exact, normalized center point coordinates [y, x] on a scale of 0 to 1000.
   - Coordinate Scale Guide: [0, 0] is the top-left corner, and [1000, 1000] is the bottom-right corner.
   - First coordinate (Y): vertical axis, running from 0 at the absolute top row to 1000 at the absolute bottom row of the image.
   - Second coordinate (X): horizontal axis, running from 0 at the absolute left column to 1000 at the absolute right column of the image.
3. Transcribe the raw text inside or next to the callout label with LITERAL character accuracy.
   - Include any part number, quantity multipliers, brand names, or notes (e.g. "729063\n X 4", "724061F", "722003C", "X 2 (LOCTITE 243)", "726018").
   - Do NOT omit any letters or suffix.
   - Format exactly what you see.

Output format is a JSON array of objects, containing:
'label': exact transcribed text
'center_point': [y, x] normalized coordinates (0-1000) representing the absolute visual center of the label characters.
```

3. **`extractHotspots` — Stage 2 Prompt (Ultra-Tight Box, Polygon & Leader-Line Target Part Box)**:
```text
Analyze this technical schematic/parts blueprint diagram image.
You are provided with a previously detected list of center coordinates for all part hotspot labels.
Your objective is to determine the absolute pixel-perfect, ultra-tight bounding box [ymin, xmin, ymax, xmax] (normalized 0-1000) and shape category for each label.

CRITICAL INSTRUCTIONS FOR ULTRA-PRECISE GEOMETRIC ALIGNMENT:
1. Shape Classification:
   - 'circle': Use this shape ONLY for perfectly round numbering bubbles or circular balloons.
   - 'polygon': Use this shape for irregular, tilted, or non-rectilinear shapes. Trace the exact outer boundary with sequential vertices in 'polygon_2d'.
   - 'rectangle': Use this shape for standard rectangular callout shapes, plaintext labels, or oval/oblong capsules.

2. Ultra-Tight Fitting Limit (NO OVERSIZING, NO LOOSE PADDING):
   - The bounding boxes MUST tightly hug the actual ink of the text characters or the exact outer stroke of the capsule/bubble. 
   - DO NOT include leader lines, arrows, trailing strings, or diagram background space inside the bounding box.
   - The width and height of the box should be exactly the width and height of the text/balloon. If the result looks like a wide rectangle but the text is short, YOU HAVE FAILED.

3. Proximity Awareness & Overlap Prevention:
   - Close hotspots MUST NOT OVERLAP. If padding causes collisions, trim constraints immediately to create distinct gaps.
   - Each label must be a precise, independent click target spanning only its own textual area.

4. Coordinate Alignment (NO ARTIFICIAL OFFSETS):
   - Visual alignment with the actual printed graphic is the absolute source of truth. If the previously detected center point is slightly off, correct it in your final ultra-tight bounding box structure.

5. Part Bounding Box Correlation ('part_box_2d'):
   - For each label, inspect the leader lines or arrows pointing from the label to the physical hardware part.
   - If a distinct indicator line exists, locate the physical component/part being pointed to, and capture its bounding box in 'part_box_2d' as [ymin, xmin, ymax, xmax] normalized 0-1000. This must cover ONLY the actual physical hardware item (e.g. a screw, ring, tube, washer).

Input Search References (JSON):
${JSON.stringify(stage1Parsed, null, 2)}

Output the final list of precise hotspots as a JSON array of objects. All hotspots must be mapped.
```

4. **`HotspotMemoryStore.refine` — Surgical Refinement & Systematic Offset Corrector (`src/lib/HotspotMemoryStore.ts`, `temperature: 0.1`)**:
- **System Instruction**: `You are an elite optical engineering analyst specializing in high-fidelity schematic analysis is automated manufacturing. Your refined hotspots must have absolute spatial precision, correct shapes, and exact contours.`
- **Prompt**:
```text
Analyze this technical schematic diagram.

TASK: Review the existing list of hotspots and refine them with surgical precision, classifying correct shapes and outlining custom/irregular regions.

CRITICAL INSTRUCTIONS FOR SHAPE-AWARE REFINEMENT, OPTIMAL SIZING, AND PROXIMITY SEPARATION:
1. Shape Determination:
   - 'circle': Round indicator balloons, bubbles, circular symbols or targets.
   - 'polygon': Custom, asymmetrical, angled, or non-rectilinear shapes. You MUST output precise [y, x] points sequentially in 'polygon_2d' (0-1000).
   - 'rectangle': Default clean vertical/horizontal label boxes, text blocks.

2. Perfect Hotspot Sizing & Touch Responsiveness (Avoid Oversizing vs. Undersizing):
   - Touch Target Sweet Spot: To ensure excellent touch responsiveness on screen without compromising accuracy, each completed hotspot bounding box must have a MINIMUM size of 15x15 units on the 0-1000 scale (equivalent to 1.5% of total width/height). If a character or circular balloon is extremely tiny, enlarge the bounding box slightly outwards symmetrically to reach this minimum 15x15 sweet spot.
   - Tight Fitting limit: Outside of satisfying the minimum 15x15 units sweet spot, the boxes MUST NOT have excessive, loose, or arbitrary padding. Do not let checkboxes or text outlines balloon outwards unnecessarily. Keep borders hugging the visual text/shapes.

3. Proximity Awareness & Overlap Prevention (Mindful Spacing of Nearby Hotspots):
   - You MUST be highly mindful of adjacent, clustered, or stacked labels/callout capsules. 
   - Hotspots that are close to each other MUST NOT OVERLAP. If a standard padding/margin would cause two adjacent hotspots to overlap or collide, you MUST trim back their respective coordinates, creating a clear and distinct gap (minimum of 5-8 units separation) between their bounding boxes.
   - Each bubble/label must remain an independent, distinct click target. No two hotspots should bleed into each other or compete for the same pixel space.

4. Offset Detection and Correction:
   - WARNING: Inspect the existing hotspots. If you notice a systematic offset, shift, or misalignment (e.g., if coordinates are consistently positioned too high, too low, or off-center compared to the actual text bubbles on the blueprint), you MUST correct this displacement.
   - Relocate each bounding box so that the 'box_2d' parameters [ymin, xmin, ymax, xmax] (normalized 0-1000) tightly and perfectly wrap around the actual visual label/characters or circular stroke, aligning with the real pixels of the image.

5. Coordinate and Shape Precision:
   - box_2d: Represent the precise coordinate bounding box [ymin, xmin, ymax, xmax] of the indicator/label, normalized 0-1000. Keep it dense and tight. Do not leave any empty space inside the borders of the bounding box outside of satisfying minimum touch responsiveness requirements.
   - polygon_2d: For 'polygon' shape hotspots, trace the exact contour boundary vertices of the angled target area.

6. Corrections:
   - Relocate misplaced bounding boxes to wrap labels tightly.
   - Insert missing hotspots that exist in the drawing but are not in the current list.
   - Purge duplicate or false-positive hotspots.
   - Transcribe literal characters pixel-perfectly without substitutions.

7. Validation: Verify all coordinate ranges against the image pixels. Perfect pixel alignment is required.

Existing Hotspots (JSON): ...

Output ONLY the refined list in the requested JSON format. Do not generate any other text.
```
*(Implementation note: Line 51 of `HotspotMemoryStore.ts` currently escapes `\${JSON.stringify(currentHotspots)}` inside its template literal—important to be aware of if inspecting raw prompt payloads.)*

#### Architecture 2: 3-Stage Optical Classification, OCR & Adversarial QA (`src/lib/prompts.ts`, used by `extractSchematicData` in `WorkflowPipeline` and `SchematicExtractionSession` in `BatchProcessor`)

- **`STAGE_2_SYSTEM` (Optical Style & Component Shape Classifier)**:
```text
You are a precision optical engineering analyst specializing in 
technical schematic diagrams. Your classifications are consumed by downstream 
computer vision systems — accuracy is critical and errors cascade. Never guess. 
When uncertain, use the ambiguity fields provided.
Additionally, for every detected component, classify its geometric shape as "rectangle", "square", or "circle".
```

- **`STAGE_4_SYSTEM` (Zero-Tolerance OCR, Tight `box_2d`, & `polygon_2d`)**:
```text
You are a precision optical character recognition engine 
specialized in technical engineering schematics and parts diagrams. 
You extract part numbers, reference codes, and label text with zero tolerance 
for character substitution errors. Every character matters.

CRITICAL INSTRUCTIONS FOR BOUNDING BOXES AND POLYGONS:
1. You MUST find EVERY SINGLE callout label (number or letter) in the schematic.
2. Callout labels are often enclosed in a shape (like a circle, square, hexagon, or triangle).
3. For each label, you MUST provide a precise 2D bounding box tightly wrapping ONLY the number or letter itself, NOT the enclosing shape.
4. If there is no enclosing shape, tightly wrap the text itself.
5. Do NOT include the leader lines, arrows, or the mechanical part itself in the bounding box. Only the label text.
6. The bounding box format MUST be [ymin, xmin, ymax, xmax] where coordinates are normalized to 0-1000.
7. Example: [250, 400, 280, 450] means ymin=250, xmin=400, ymax=280, xmax=450.
8. Ensure the box is TIGHT around the text.
9. "Exact Transcription" Labels: If a label contains a literal list of multiple distinct part numbers, stacked part numbers, or multiple variants (e.g., separated by commas, slashes, or stacked in a list), transcribe the 'label' EXACTLY as written in the pixels. DO NOT consolidate, shorten, or replace them with words like "Details". Keep all characters, separators, slashes, and numbers exactly as printed in the original schematic.
10. Forbidden Mappings: You MUST transcribe the label EXACTLY as it appears in the image pixels. Never map a part number to a different unrelated part number. Precision is absolute.
11. You MUST also provide a `polygon_2d` which is an array of [y, x] coordinates that trace the EXACT outline of the text.
```

- **`STAGE_6_SYSTEM` (Adversarial Senior QA Engineer Validation)**:
```text
You are a senior quality assurance engineer reviewing 
automated callout extraction results from technical schematic diagrams. 
Your job is adversarial — assume the extraction has errors and prove 
or disprove each one. Be skeptical. Surface real issues.

CRITICAL INSTRUCTIONS FOR VALIDATION:
1. Review the provided list of extracted labels, their bounding boxes, and polygons.
2. Verify that EVERY bounding box tightly wraps ONLY the number or letter itself, NOT the enclosing shape.
3. Verify that the `polygon_2d` accurately traces the outline of the text.
4. Correction Rule: If a label contains a literal list of multiple distinct part numbers or variants (separated by commas, slashes, or stacked), ensure it is transcribed EXACTLY character-for-character as shown in the original diagram. DO NOT compress, abbreviate, or replace it with generic descriptions like "Details". Complete 1-to-1 exact transcription of the original numbers/characters is required.
5. Fidelity Rule: Ensure part numbers match the pixels exactly. Do not substitute or invent characters. Character-for-character precision is required.
6. If a label was missed, ADD IT.
7. The bounding box format MUST be [ymin, xmin, ymax, xmax] where coordinates are normalized to 0-1000.
8. Verify the shape classification ("rectangle", "square", "circle", "hexagon", "triangle", etc.) for each component.
```

---

### 5.6 PDF Page Classification, Legend Extraction & JSON Audit Prompts

Located in `src/lib/schematic-legend-processor.ts`:

#### 1. `classifyPage` (`SCHEMATIC` | `LEGEND` | `OTHER`)
```text
Analyze this page from a technical document.
Classify the page type as either 'SCHEMATIC', 'LEGEND', or 'OTHER'.
- 'SCHEMATIC': Contains a diagram, drawing, or illustration of parts, usually with callout numbers/labels pointing to parts.
- 'LEGEND': Contains a table or list mapping callout numbers/labels to part names, descriptions, or part numbers.
- 'OTHER': Anything else (title page, blank page, general text).

Return ONLY a JSON object with a single key "type" containing one of the three string values.
```

#### 2. `extractSchematicLabels` (Quantity-Multiplier Label Splitter)
```text
Analyze this schematic diagram. Extract all the part label callouts pointing to parts in the diagram.
If a callout includes a quantity indicator (e.g., "2x 4700", "4700 (2)", "3X 1234"), separate the base label from the quantity.
Return a JSON array of objects, each with:
- 'rawText': the exact text found on the diagram (e.g., "2x 4700")
- 'cleanLabel': the clean part number/code without the quantity (e.g., "4700")
- 'quantity': the quantity indicated (number, default to 1 if not specified)
```

#### 3. `extractLegendData` (Key Table Parser)
```text
Analyze this legend/key table page.
Extract the table mapping the callout labels to their part names/descriptions, part numbers, and quantities (if available).
Return a JSON array of objects, each with 'label' (the callout number/code), 'description' (the part name/description), 'quantity' (as a number), and optionally 'partNumber'.
```

#### 4. `auditAndUpdateJson` (AI Part-ID to Hotspot-ID Semantic Mapper)
```text
Map the following extracted hotspots to the part IDs in the provided JSON.
Return a JSON object where keys are the part IDs from the JSON and values are the corresponding hotspot ID.

JSON Parts:
${JSON.stringify(existingJsonData.parts, null, 2)}

Hotspots:
${JSON.stringify(hotspots, null, 2)}

Return ONLY the mapping JSON object.
```
**Fallback Heuristic (`App.tsx`)**: If `auditAndUpdateJson` omits a hotspot from `mapping`, `App.tsx` strips non-alphanumeric characters (`label.replace(/[^a-zA-Z0-9]/g, '').toLowerCase()`) and matches directly against `part.id` or `part.sku` to populate `woo_sku`, `local_target_id`, and `displayLabel`. Similarly, `WorkflowPipeline` normalizes leading zeros (`label.trim().toLowerCase().replace(/^0+/, '')`) when correlating hotspots with `legendEntries`.

---

## 6. Post-Processing Geometry, Touch Optimization & Coordinate Math

### 6.1 Collision-Aware Mobile Touch Target Optimizer (`adjustHotspotsForMobileFriendlyTouch` in `src/lib/coordUtils.ts`)

Applied automatically after hotspot extraction in `App.tsx`, `WorkflowPipeline.tsx`, and `SchematicViewer.tsx`.

- **Design Constants (on `0–1000` normalized grid)**:
  - `targetMinSize = 24` (`2.4%` minimum baseline tap width/height)
  - `targetPadding = 6` (`0.6%` visual breathing margin around tight ink boxes)
  - `gapAllowance = 3` (`0.3%` mandatory clearance buffer between neighboring hotspots)

- **3-Step Pairwise Clearance Solver**:
  1. Computes original dimensions `(w, h_dim)` and center `(cx, cy)` for every hotspot `i`.
  2. Calculates desired symmetric padding `desPX = max(targetPadding, (targetMinSize - w) / 2)` and `desPY = max(targetPadding, (targetMinSize - h_dim) / 2)`. Iterates over every neighbor `j != i`:
     - If `i` and `j` already overlap in both X and Y (`overlapXOrig && overlapYOrig`), clamps `limitPX = 0, limitPY = 0` so existing overlaps never worsen.
     - If aligned vertically (`overlapYOrig`), caps horizontal expansion to `max(0, (gapX - gapAllowance) / 2)`.
     - If aligned horizontally (`overlapXOrig`), caps vertical expansion to `max(0, (gapY - gapAllowance) / 2)`.
     - If diagonally separated within `targetMinSize * 1.5` (`36` units), caps `limitPX` and/or `limitPY` to `max(0, (gap - gapAllowance) / 2)`.
  3. Applies `padX` and `padY` symmetrically so `(cx, cy)` never drifts, clamps coordinates to `[0, 1000]`, and **proportionally scales `polygon_2d` vertices** outward around `(cx, cy)` by `(scaleX, scaleY) = (newW / origW, newH / origH)`.

### 6.2 Interactive Coordinate Editing & Micro-Nudging (`App.tsx` & `SchematicViewer.tsx`)
- **Pointer Drag & 4-Corner Resize**: Supports real-time drag relocation (3px deadzone to distinguish clicks from drags) and `nw`, `ne`, `sw`, `se` corner resize handles with `0.5%` minimum size floor and `[0%, 100%]` boundary clamping.
- **Click-to-Center Relocation**: Selecting "Revise" on any hotspot switches the canvas cursor to `crosshair`; clicking anywhere on the schematic centers the hotspot at `(clickX_pct, clickY_pct)` while preserving its width and height.
- **Keyboard Arrow Micro-Nudging**:
  - `ArrowUp / ArrowDown / ArrowLeft / ArrowRight`: `0.2%` step (`2` units on `0–1000` grid)
  - `Shift + Arrow`: `1.0%` coarse step (`10` units on `0–1000` grid)
  - `Enter`: Commits revision and exits edit mode.

---

## 7. Batch Concurrency, Human-in-the-Loop (HITL) State Machines & Persistence

### 7.1 `ImageRegenerator` Adaptive Worker Pool (`src/components/ImageRegenerator.tsx`)
Designed to process `100+` product/hardware images reliably without triggering 429 bursts:
- **Mode-Aware Worker Concurrency (`CONCURRENCY_LIMIT`)**:
  - `clone` mode: **5 concurrent workers** (1 API call per item)
  - `hardware_part` mode: **3 concurrent workers** (2 API calls per item: vision spec + synthesis)
  - `product_hero` mode: **2 concurrent workers** (2 API calls per item, higher token density)
- **Staggered Startup & Inter-Item Pacing**:
  - Worker `index` delays initial launch by `index * 600ms`.
  - Each worker sleeps `1200ms` after completing an item before pulling the next item from the shared queue.
- **Pause / Resume / Stop State Machine**: Uses synchronous refs (`isPausedRef`, `isStoppedRef`) polled at `200ms` intervals so active workers pause cleanly between items or reset unfinished items back to `'pending'` on stop.

### 7.2 `WorkflowPipeline` State Machine (`src/components/WorkflowPipeline.tsx`)
Each `PipelineFile` transitions through:
`pending` -> `classifying` (`classifyPage` + `extractLegendData`) -> `legend_approval` *(if `enableHitlApproval`)* -> `enhancing` (`enhanceSchematic` on `SCHEMATIC` pages; `LEGEND` pages filtered out upon approval) -> `enhancement_approval` *(if `enableHitlApproval`; supports free-text rejection feedback via `runEnhancementWithFeedback` appending `\n\nRefinement feedback: ${feedback}`)* -> `extracting_hotspots` (`extractSchematicData` + `adjustHotspotsForMobileFriendlyTouch` + optional `auditAndUpdateJson` + legend normalization correlation) -> `success`.

### 7.3 Local Storage Persistence (`localforage` / IndexedDB)
- `schematic_projects`: Auto-persists the entire `Project[]` array (including base64 original/enhanced/multi-ratio images, pages, legends, and hotspots) to IndexedDB to bypass `localStorage` 5MB quota limits.
- `regenerator_items`: Independently auto-persists `RegenerationItem[]` across browser reloads or quota interruptions.

---

## 8. Export Schema v2.0, WordPress/WooCommerce Binding & Observability Contracts

### 8.1 Formal `SchemaV2JSON` Specification (`buildSchemaV2JSON` in `src/lib/exportSchemaV2.ts`)
All single, multi-ratio, legend-correlation, and pipeline exports compile into `schema_version: "2.0"`:

- **`diagram`**: Slugified `id`, `title`, `description`, and `pages[]` (`page`, `image: { src, natural_width, natural_height }`, `coordinate_space: { origin: "top-left", x_axis: "right", y_axis: "down", unit: "px" }`).
- **`parts_catalog`**: Reconciled array of `{ part_id, display_id, name, sku, quantity, notes }` seeded from existing JSON / legend correlation and backfilled dynamically for any unmatched hotspots.
- **`hotspots`**: Unique instance-numbered IDs (`p{page}-{partIdClean}-{01..99}`), `part_ref`, `z_index: 100 + index`, `interaction: { tap_target_min_px: 40, hit_slop_px: 6, enabled: true }`, and dual pixel + percentage geometry:
  - **Circle (`circle` / `ellipse`)**: `shape: { type: "circle", cx_px, cy_px, r_px }` + `normalized: { cx_pct, cy_pct, r_pct_w, r_pct_h }`
  - **Polygon (`polygon` or `polygon_2d.length >= 3`)**: Converts internal `[y, x]` (`0–1000`) to standard SVG `[X, Y]` order in both `shape: { type: "polygon", points_px }` and `normalized: { points_pct }`
  - **Rectangle (default)**: `shape: { type: "rect", x_px, y_px, width_px, height_px }` + `normalized: { x_pct, y_pct, width_pct, height_pct }`
- **WordPress / WooCommerce Metadata (`Project` & `Hotspot`)**: Supports `wp_media_id`, `woo_product_id`, `woo_sku`, and `local_target_id` for direct WordPress interactive parts-catalog plugin ingestion.

### 8.2 Streaming Telemetry & Self-Healing Prompt Patch Contracts (`src/lib/gemini.ts` & `src/types.ts`)
- **`generateContentStreamAndLog`**: Streams text/JSON generations via `ai.models.generateContentStream`, logging per-chunk arrival latency (`+${timeSinceLast}ms`), cumulative character length, 100-char sanitized chunk previews, and total call duration.
- **Defined Optimization / Telemetry Contracts (`src/types.ts`)**:
  - `TraceLog`: `{ id, source, timestamp, model, prompt, responsePreview, latencyMs, status, errorContext?, metadata? }`
  - `AIPromptPatch`: `{ id, source, patternDetected, fixInstruction, active, appliedAt, confidence }` (schema contract for automated prompt-patch injection and failure pattern tracking).
