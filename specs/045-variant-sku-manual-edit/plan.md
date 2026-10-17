# Implementation Plan: Edit Variant Sequence Number Manually

**Branch**: `053-variant-sku-manual-edit` (from `develop`, PR #45 merged) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/045-variant-sku-manual-edit/spec.md`

## Summary

In the product edit drawer each variant card shows its SKU as fixed text: the product code, a hyphen, then an editable sequence number. The owner types only the number. The server builds the SKU from the variant's own code prefix and the number, checks it (digits only, at least 1, leading zeros removed, at most 14 characters, unique across the store including deleted variants and both data modes), stores it, marks the SKU as typed, and writes an activity log entry in the same transaction.

Typed numbers are kept on a segment change (decision Q2 A). Generated SKUs keep feature 044's rule. This needs one new flag per variant, `sku_is_typed`.

Consequence to note: a typed SKU keeps the code it had when typed, so it can differ from the product's current code after a segment change. This is the intended result of Q2 A.

## Update 2026-10-17 — User Story 4: variants listed in SKU order

The product edit drawer lists the saved variants in SKU order: by code text, then by the number after the last hyphen, compared as whole numbers (`…-2` before `…-10`). Legacy SKUs without a hyphen sort after the numbered ones of the same code. Unsaved variants (added or duplicated in the drawer) keep the position where they were added until the product is saved (FR-014).

This is a display rule in the drawer only, so it is done in the SPA, not on the server. The product list and the API responses are unchanged.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 13); Vue 3 SPA (Pinia, vue-i18n)
**Primary Dependencies**: none new
**Storage**: MySQL 8. One new column: `product_variants.sku_is_typed BOOLEAN NOT NULL DEFAULT 0`. Existing rows default to generated (false). `sku CHAR(14)` and its unique index are unchanged.
**Testing**: PHPUnit feature tests against MySQL `boothpos_test` (`ProductTest`, `ProductSegmentChangeTest`, a new `VariantSkuTest`); Vitest under `qa-tests/` (`ProductsView`, a new preview unit test); browser check of the drawer (Principle II)
**Target Platform**: local single-machine install (native or Docker store image)
**Project Type**: Laravel API + Vue SPA
**Performance Goals**: one variant update per save; the uniqueness check is one indexed lookup
**Constraints**: codes stay permanent except through the segment change (044); sales and stock snapshots are never rewritten (FR-006); audit inside the transaction (Principle IV); migration filename date prefix must sort after the latest existing migration
**Scale/Scope**: 1 migration, 1 service, 1 request rule, a controller hook in `updateVariant`, the segment-change service (skip typed variants), `nextVariantSku` (count typed SKUs with older prefixes), the drawer card, OpenAPI, tests

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| I. Code quality (single source per concern) | **Pass** | The sequence rule (digits, no leading zeros, length, uniqueness) lives in one service, `VariantSkuService`; the drawer only mirrors the display. The segment change and `nextVariantSku` read the same `sku_is_typed` flag. No new abstraction beyond that service. |
| II. Testing standards | **Pass (planned)** | Backend: accept/refuse cases, leading zeros, length, duplicate incl. trashed and other mode, flag set, activity row, snapshots unchanged, typed SKU kept on segment change, generated SKU still regenerated, numbering after typed SKUs. Frontend: card shows fixed code and editable number; error on the field. Browser check of the drawer. |
| III. UX consistency | **Pass** | Same 422 convention; messages on the sequence field; existing chip colours reused for the fixed code. |
| IV. Security | **Pass** | Server validates everything; the fixed code is not accepted from the client (the server builds the SKU from the stored prefix); authorisation stays the existing variant update policy; audit inside the transaction. |
| V. Performance | **Pass** | One lookup per save; no per-row query in lists. |
| Documentation (OpenAPI with request change) | **Pass (planned)** | `PUT /variants/{id}` gains `sequence`; OpenAPI updated in the same change. |

**Gate result**: PASS. No NEEDS CLARIFICATION remain; FR-009 and FR-010 are decided (spec).

## Project Structure

### Documentation (this feature)

```text
specs/045-variant-sku-manual-edit/
├── spec.md
├── plan.md                  # this file
├── research.md              # Phase 0
├── data-model.md            # Phase 1
├── quickstart.md            # Phase 1
├── contracts/
│   └── variant-sequence.md  # Phase 1: PUT /variants/{id} sequence, errors, examples
└── checklists/requirements.md
```

### Source Code (repository root)

```text
database/migrations/2026_11_17_000001_add_sku_is_typed_to_product_variants.php   # NEW: boolean flag, default false
app/Models/ProductVariant.php                         # CHANGE: cast sku_is_typed to boolean; fillable
app/Services/VariantSkuService.php                    # NEW: setSequence(variant, raw, user) — one transaction, audit
app/Services/ProductCodeGenerator.php                 # CHANGE: normaliseSequence(string): ?int; nextVariantSku counts typed SKUs of other prefixes too
app/Services/ProductSegmentChangeService.php          # CHANGE: typed variants keep their SKU; generated ones regenerate
app/Http/Requests/UpdateVariantRequest.php            # CHANGE: 'sequence' => nullable, string, digits only (raw, normalised in prepareForValidation)
app/Http/Requests/StoreVariantRequest.php             # CHANGE: 'sequence' => nullable (new variants in the drawer)
app/Http/Controllers/Api/ProductController.php        # CHANGE: updateVariant() calls VariantSkuService when sequence is present and differs; storeVariant() passes sequence
resources/js/utils/productSegment.js                  # CHANGE: add splitSku(sku) → { code, number } and previewSku unchanged
resources/js/views/ProductsView.vue                   # CHANGE: variant card — fixed code text + number input; send sequence on save; show sequence error under the field
docs/openapi-pos-mvp.yaml                             # CHANGE: PUT /variants/{id} and POST /products/{id}/variants gain sequence (sku_is_typed stays internal)
tests/Feature/VariantSkuTest.php                      # NEW: accept/refuse/normalise/duplicate/flag/audit/snapshot
tests/Feature/ProductSegmentChangeTest.php            # CHANGE: typed variants keep SKU
tests/Feature/ProductTest.php                         # CHANGE: numbering after typed SKUs of an older prefix
qa-tests/unit/variantSkuSplit.test.js                 # NEW: splitSku
qa-tests/component/ProductsView.test.js               # CHANGE: fixed code + editable number; error on field
```

**Structure Decision**: one new service for the manual SKU (it spans a variant, the uniqueness check and the audit, so it is not controller code). The segment-change and numbering rules stay where they are and only read the new flag. The only schema change is the flag column.

## Design Details

### 1. Flag (migration)

`sku_is_typed BOOLEAN NOT NULL DEFAULT 0` on `product_variants`. Set `true` only by `VariantSkuService::setSequence`. Never set by the generator, import, or segment change (a regenerated SKU stays generated).

### 2. Manual sequence (`VariantSkuService::setSequence`)

Inside `DB::transaction`, with the variant row locked:
1. `normaliseSequence($raw)`: trim; digits only (`ctype_digit`); cast to int; reject 0 (422). Leading zeros disappear because the value is an int.
2. The prefix is the variant's own code: `Str::beforeLast($variant->sku, '-')` (for generated and typed SKUs alike). For a typed SKU kept across a segment change this is its old code (Q2 A). The product's current code is not used here, so the owner never edits it.
3. `candidate = prefix.'-'.n`. If `strlen(candidate) > 14`: 422 on `sku`. If the candidate equals the current SKU: no change, return.
4. Uniqueness: `ProductVariant::withTrashed()->withoutGlobalScope(DataModeScope::class)->where('sku', $candidate)->where('id', '!=', $variant->id)->exists()` → 422 on `sku` ("Nomor SKU sudah dipakai").
5. Update `sku` and `sku_is_typed = true`.
6. `ActivityLogger::log(action: 'sku_changed', entity: ProductVariant, old: ['sku'=>…], new: ['sku'=>…])` in the same transaction.

Sales and stock snapshots are not touched (FR-006).

### 3. Numbering after typed SKUs (`ProductCodeGenerator::nextVariantSku`)

Today the next number is the highest `sequenceOf($product->code_prefix, sku)` + 1. A typed SKU that kept an older prefix would not be counted, so a later generated number could repeat its number (different prefix, so no SKU collision, but it breaks FR-002 of feature 044: numbers are never reused within a product). Fix: for each variant of the product, the number is `sequenceOf(current prefix)` when the SKU has the current prefix; otherwise the trailing number after the last hyphen (`/-(\d+)$/`). The highest of these + 1 is the next number. Decision recorded in research.md.

### 4. Segment change (`ProductSegmentChangeService`)

- Variants with `sku_is_typed = true` keep their SKU (FR-010 / Q2 A).
- Generated variants are regenerated as in feature 044; their number choice ignores typed SKUs (typed SKUs have another prefix, so they cannot collide).
- Audit and transaction unchanged; the audit count reports only the regenerated SKUs.

### 5. Requests and controller

- `UpdateVariantRequest` / `StoreVariantRequest`: `sequence` nullable; `prepareForValidation` trims it; the rule is applied in the service (digits, ≥1, length), so the request stays small. Blank on update = unchanged; blank on store = generated.
- `updateVariant()`: when `sequence` is present, call `VariantSkuService::setSequence` before the other fields, inside the existing flow; the BOM cost lock is unaffected.
- `storeVariant()`: when `sequence` is present, the new SKU is `productCode-n` built by the same service rule and uniqueness check, instead of `nextVariantSku`; empty = generated as today.

### 6. Drawer (`ProductsView.vue`)

- The SKU chip (line ~748) becomes: the code part as fixed text (`splitSku(row.sku).code`), a hyphen, and an input for the number (`splitSku(row.sku).number`), width sized to the card header.
- Save: sends `sequence` for saved variants and for new variants when filled.
- Errors: a 422 on `sku` is shown under the variant's number field; the save does not commit any SKU.
- No product-level code field is added; the product code stays as today.

### 7. OpenAPI

`PUT /variants/{variant}` and `POST /products/{product}/variants` gain `sequence` (string, digits, nullable); 422 on `sku` for duplicate, too long, or zero. The `ProductVariant` schema is unchanged for clients (the flag is internal).

## Design §8 — SKU order in the drawer (User Story 4)

- **Comparison** (`compareVariantSku(a, b)` in `resources/js/utils/productSegment.js`, beside `splitSku`): both SKUs have a number → compare code text with a plain string compare, then the numbers as integers; a SKU without a hyphen has no number and sorts after the numbered SKUs of the same code; otherwise compare the whole SKU text. Pure function, unit-testable without the DOM.
- **Where it applies**: `ProductsView.vue`, where saved variants are loaded into `variantRows` (the `full.variants.map(...)` mapping, line ~290). The saved rows are sorted once when the drawer opens, so the order matches what was saved (FR-013).
- **Unsaved rows** (added with `emptyVariant()` or duplicated from a source card) are appended or inserted where the user added them and are not re-sorted until the product is reloaded (FR-014). No change to `addVariant`, `duplicateVariantRow` or the save order from User Story 1.
- **No server change**: no endpoint, no response ordering, no migration. The batch number save (`updateVariantSequences`) is unaffected because it sends `variant_id` values, not positions.
- **Why not server-side order**: the drawer is the only consumer, and an API order would not place unsaved rows, so the SPA rule is the single place that matters.

Source files added to the structure list:

```text
resources/js/utils/productSegment.js       # + compareVariantSku(a, b)
resources/js/views/ProductsView.vue         # sort saved rows when the drawer loads them
qa-tests/unit/variantSkuSplit.test.js       # + compareVariantSku cases (numeric order, legacy, mixed codes)
qa-tests/component/ProductsView.test.js     # + drawer shows saved variants in SKU order; unsaved rows keep position
```

## Complexity Tracking

| Violation | Why Needed | Simpler Alternative Rejected Because |
|-----------|------------|-------------------------------------|
| One new column `sku_is_typed` | Q2 A needs to tell typed from generated SKUs; the SKU text alone cannot tell them apart | Deriving it from the SKU shape is impossible: a typed `SPF-PI-S-7` and a generated one look the same |
| New service `VariantSkuService` | Spans uniqueness, audit, and the flag in one transaction (Principle IV) | Controller code would break Principle I |

## Constitution re-check (User Story 4)

| Principle | Status | Notes |
|---|---|---|
| I. Single source per concern | **Pass** | One comparison function next to `splitSku`; the drawer calls it. No second order rule on the server. |
| II. Testing | **Pass (planned)** | Vitest for the comparison (numeric, legacy, mixed codes) and drawer order; browser check of the drawer with 10+ variants. |
| III. UX consistency | **Pass** | Same cards and layout; only their order changes. |
| IV. Security | **N/A** | Display only; no data or permission change. |
| V. Performance | **Pass** | Sorting a handful of variants once when the drawer opens. |
