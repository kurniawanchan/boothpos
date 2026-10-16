# Implementation Plan: Product Segment Length (1–3) and SKU Format

**Branch**: `050-product-segment-1-3` (stacked on `049-product-export-columns`, unpushed) | **Date**: 2026-10-16 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/042-product-segment-1-3/spec.md`

## Summary

A product's segment becomes **1 to 3 letters**, stored uppercase and **not padded**. The product code is `artist(3)-category(2)-segment`, and each variant SKU is that code plus `-NNN`. For `S` with artist `SPF` and category `SH`, the SKU is `SPF-SH-S-001`. Blank segments still derive 3 letters from the name, padded with `X`, as today.

Today the rule (exactly 3 letters, padded with `X` in the form preview) is written in **five places**: the store request, the code generator, the product import, the SPA preview, and the demo-clone parser. The plan replaces the drift-prone copies with **one definition** in a small support class, and keeps the SPA mirror as cosmetic only (Principle IV).

No migration: `products.code_prefix` is `CHAR(10)` and `product_variants.sku` is `CHAR(14)`; the new values (8–10 and 12 characters) fit, and MySQL `CHAR` trims trailing spaces on read, so values round-trip unchanged.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 13); Vue 3 SPA (Pinia, vue-i18n)
**Primary Dependencies**: none new
**Storage**: MySQL 8 — no schema change (see Summary)
**Testing**: PHPUnit feature tests against MySQL `boothpos_test` (`ProductTest`, `MasterDataImportTest`, `DemoClone/*`); Vitest under `qa-tests/unit/` for the preview; browser check of the product form (Principle II)
**Target Platform**: local single-machine install (native or Docker store image)
**Project Type**: Laravel API + Vue SPA
**Performance Goals**: unchanged (one uniqueness query per product create, as today)
**Constraints**: codes are permanent once created (F19.4) — existing products untouched; uniqueness across DEMO and LIVE (FR-010); UI copy in both `en.json` and `id.json`
**Scale/Scope**: 1 new support class, 5 call-site changes, 2 locale keys, 1 OpenAPI schema, tests

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| I. Code quality (single source per concern) | **Pass — this plan enforces it** | Today the rule is duplicated in five places and they already disagree (store request says `size:3`, generator says `strlen === 10`, demo clone says `[A-Za-z]{3}`). One definition: `App\Support\ProductSegment` (pattern + normaliser). The SPA mirror is a UI-only copy and is labelled as such. |
| II. Testing standards | **Pass (planned)** | Backend: store/import/generator/clone tests for lengths 1–3, rejects 0/4/non-letters, SKU `SPF-SH-S-001`, blank derivation unchanged, existing products unchanged, DEMO/LIVE collision. Frontend: Vitest for the preview (no `X` padding). Browser check of the product form and the SKU preview. |
| III. UX consistency | **Pass** | Same error convention (422 on the field). Label "Segment (1–3)" in both languages. Hidden-vs-disabled unchanged. |
| IV. Security | **Pass** | All validation server-side (FormRequest + import + generator). The SPA preview never decides what is saved. |
| V. Performance | **Pass** | No new queries. |
| Documentation (OpenAPI with request change) | **Pass (planned)** | `docs/openapi-pos-mvp.yaml` product create schema for `product_segment` updated in the same change (PRD §9.5). |

**Gate result**: PASS. No unresolved NEEDS CLARIFICATION. "Letters only" is an assumption recorded in the spec.

## Project Structure

### Documentation (this feature)

```text
specs/042-product-segment-1-3/
├── spec.md
├── plan.md               # this file
├── research.md           # Phase 0
├── data-model.md         # Phase 1
├── quickstart.md         # Phase 1
├── contracts/
│   └── product-segment.md   # Phase 1: request + import rule
└── checklists/requirements.md
```

### Source Code (repository root)

```text
app/Support/ProductSegment.php                    # NEW: PATTERN (1–3 uppercase letters), normalise(), CODE_PATTERN (AAA-BB-SEG)
app/Http/Requests/StoreProductRequest.php         # CHANGE: product_segment => nullable, string, regex:ProductSegment::PATTERN (letters, 1–3)
app/Services/ProductCodeGenerator.php             # CHANGE: deriveSegmentFromName unchanged (3, X-pad); buildCodePrefix checks CODE_PATTERN instead of strlen === 10
app/Http/Controllers/Api/ProductController.php    # CHANGE: strtoupper of the typed segment only; no padding (derived path unchanged)
app/Services/MasterDataImportService.php          # CHANGE: segment check (line ~725) uses ProductSegment; error text "1–3 huruf"
app/Services/DemoClone/CodeAllocator.php          # CHANGE: productPrefix() regex accepts segment of 1–3 letters (was exactly 3)
resources/js/views/ProductsView.vue               # CHANGE: effectiveSegment no longer pads typed input (derived name still padded); codePreview; "product · 3" meta label
resources/js/locales/en.json, id.json             # CHANGE: segment_3 label → "Segment (1–3)" / "Segmen (1–3)"
docs/openapi-pos-mvp.yaml                         # CHANGE: product create schema product_segment (pattern, length 1–3)
tests/Feature/ProductTest.php                     # CHANGE/ADD: segment lengths, SKU, derivation unchanged, duplicates
tests/Feature/MasterDataImportTest.php            # CHANGE/ADD: import accepts 1–3, rejects 4 with 1–3 wording
tests/Feature/DemoClone/CodeAllocatorTest.php     # CHANGE/ADD: productPrefix accepts 1–3 segment
qa-tests/unit/productSegmentPreview.test.js       # NEW: preview keeps typed segment (no X padding), derived still padded
```

**Structure Decision**: a single support class next to the other domain helpers in `App\Support` (`ReportSplit`, `MasterDataSheets`, `Couriers`). It holds only the rule, not behaviour, so it adds no new service or abstraction (Principle I: no speculative layers).

## Design Details

### 1. `App\Support\ProductSegment` (one definition)

- `PATTERN = '/^[A-Z]{1,3}$/'` — applied after uppercasing and trimming.
- `CODE_PATTERN = '/^[A-Z]{3}-[A-Z]{2}-[A-Z]{1,3}$/'` — full code `artist-category-segment`, used by the generator and the demo clone.
- `normalise(?string $value): ?string` — trims and uppercases; returns `null` for blank input (blank means derive).
- Uses the same letters-only rule as today (`ctype_alpha` / `[A-Z]`).

### 2. Request (`StoreProductRequest`)

`'product_segment' => ['nullable', 'string', 'regex:' . ProductSegment::PATTERN_RULE]` — one message on the field: "Segmen produk maksimal 3 huruf (A–Z)." Blank stays allowed.

Note: the FormRequest sees the raw input, so the rule must accept lowercase (`spf`) and normalise first. Implement with `prepareForValidation()` (uppercases and trims `product_segment`) so the regex sees the stored form. The stored value is what the rule validated.

### 3. Generator (`ProductCodeGenerator`)

- `deriveSegmentFromName()` unchanged (3 letters, `X`-padded).
- `buildCodePrefix()`: replace `strlen($prefix) !== 10` with `! preg_match(ProductSegment::CODE_PATTERN, $prefix)` (throws the existing `InvalidArgumentException`). The collision check stays exactly as it is (cross-mode, `withTrashed`).
- `nextVariantSku()` unchanged: `code_prefix . '-' . NNN` already produces `SPF-SH-S-001`.

### 4. Controller (`ProductController::store`)

- Keeps `$segment = $data['product_segment'] ?? deriveSegmentFromName(...)`.
- Remove any implicit padding; `strtoupper` already applied via `ProductSegment::normalise`.

### 5. Excel import (`MasterDataImportService`, ~line 722–731)

- Replace `strlen($segment) !== 3 || ! ctype_alpha($segment)` with `ProductSegment::PATTERN` match on the normalised value; error text "Segmen produk '{x}' harus 1–3 huruf."
- Blank still derives from the name (unchanged path).
- The existing deferred-collision handling (`masih dipakai produk yang sudah dihapus`) is unchanged.

### 6. DEMO clone (`CodeAllocator::productPrefix`)

- Regex `'/^([A-Za-z]{3})-([A-Za-z]{2})-([A-Za-z]{3})$/'` → `ProductSegment::CODE_PATTERN` (case-insensitive variant as today). Without this, a LIVE product with segment `S` aborts the whole clone with `CloneException` — the highest-impact call site.
- `sku()` already uses `strlen($livePrefix)`, so shorter prefixes need no change.

### 7. SPA (`ProductsView.vue`)

- `effectiveSegment`: `(productForm.product_segment || deriveSegment(name))` — typed input is uppercased and sliced to 3 but **not padded**; the derived path keeps `deriveSegment()` which pads to 3.
- `codePreview` uses `effectiveSegment` (so `S` previews `SPF-SH-S-001`).
- The preview's "product · 3" meta label becomes "product · 1–3" (or the length of the effective segment — decide in tasks; default: the static "1–3" text).
- Keep `maxlength="3"` on the input. The SPA rule is a mirror only.

### 8. OpenAPI

`product_segment`: `type: string`, `minLength: 1`, `maxLength: 3`, `pattern: '^[A-Za-z]{1,3}$'`, description: blank derives from the product name.

## Complexity Tracking

No violations. The only new file is `App\Support\ProductSegment`, which removes duplication rather than adding a layer.
