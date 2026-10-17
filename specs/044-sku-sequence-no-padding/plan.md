# Implementation Plan: SKU Sequence Without Zero Padding, and Segment Changes

**Branch**: `052-sku-sequence-no-padding` (stacked on `051-pos-cart-pinned-summary`, PR #44 open) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/044-sku-sequence-no-padding/spec.md`

**Update 2026-10-17 (User Story 4)**: the product response now carries the saved segment, so the edit form shows it and its preview uses the saved code. `setup-plan.sh` was NOT re-run for this update because it overwrites `plan.md` with the blank template.

## Summary

Three changes to product codes:

1. **New variants** get a sequence with no leading zeros: `VLC-SK-S-1`, `-2`, … `-11`. The number is one more than the highest number ever used by the product, deleted variants included (FR-001, FR-002).
2. **Existing variants** keep their SKUs (FR-003).
3. **Segment change (option A, decided)**: changing a product's segment regenerates every variant SKU of that product, including soft-deleted ones, from the new product code. Each variant keeps its own number (read from its current SKU). The change is refused with a message if the new code is taken by another product, and it is audited in the same transaction. Past sales and stock keep their recorded SKUs (FR-006, FR-007).

The server gains the segment-change path it never had (today `UpdateProductRequest` ignores `product_segment`). The product form's edit field and SKU preview are updated to match.

## Technical Context

**Language/Version**: PHP 8.3+ (Laravel 13); Vue 3 SPA (Pinia, vue-i18n)
**Primary Dependencies**: none new
**Storage**: MySQL 8. `products.code_prefix CHAR(10)`, `product_variants.sku CHAR(14)` with a unique index (soft-deleted rows included). No migration: new SKUs are 9–14 characters.
**Testing**: PHPUnit feature tests against MySQL `boothpos_test` (`ProductTest`, `MasterDataImportTest`, `MasterDataExportTest`, `DemoClone/*`, BOM and pre-order tests that create variants); Vitest under `qa-tests/`; browser check on the product form (Principle II)
**Target Platform**: local single-machine install (native or Docker store image)
**Project Type**: Laravel API + Vue SPA
**Performance Goals**: a segment change touches one product's variants (typically under 20 rows) in one transaction; no per-row N+1 queries beyond the variant loop.
**Constraints**: SKUs are unique across both DEMO and LIVE (`withTrashed`, unscoped); old SKUs stay in sales and stock snapshots; activity log written inside the transaction (Principle IV)
**Scale/Scope**: 1 generator method changed, 1 new service, 1 FormRequest rule, controller update branch, import path (same generator), SPA form and preview, ~44 test files whose expected SKUs change, OpenAPI

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| I. Code quality (single source per concern) | **Pass** | The SKU format lives in one method (`ProductCodeGenerator::variantSku`); store, variant create, import and the segment change all call it. The segment change is a service (`ProductSegmentChangeService`), not controller code. |
| II. Testing standards | **Pass (planned)** | Feature tests for: new format, no reuse after delete, legacy SKUs untouched, segment change regenerates all variants including soft-deleted, keeps numbers, refused on collision, audited, snapshots unchanged. Vitest for the preview. Browser check of the edit form. |
| III. UX consistency | **Pass** | Refusal shows the same field error as today's duplicate-code message (422 on `product_segment`). Labels stay Indonesian/English pairs. |
| IV. Security | **Pass** | Segment change is server-side only; the audit row and every SKU update happen in one transaction; authorization stays the existing product `update` policy. Sensitive-mutation audit is required by this principle and is included. |
| V. Performance | **Pass** | One product's variants per change; the variant query is loaded once. |
| Documentation (OpenAPI with request change) | **Pass (planned)** | `PUT /products/{id}` gains `product_segment`; SKU format description updated in `docs/openapi-pos-mvp.yaml`. |

**Gate result**: PASS. No NEEDS CLARIFICATION remain. Justified exception: none.

## Project Structure

### Documentation (this feature)

```text
specs/044-sku-sequence-no-padding/
├── spec.md
├── plan.md                 # this file
├── research.md             # Phase 0
├── data-model.md           # Phase 1
├── quickstart.md           # Phase 1
├── contracts/
│   └── product-sku.md      # Phase 1: SKU format and segment-change behaviour
└── checklists/requirements.md
```

### Source Code (repository root)

```text
app/Services/ProductCodeGenerator.php            # CHANGE: variantSku(prefix, n) with no padding; nextVariantSku uses it; numeric tail parser
app/Services/ProductSegmentChangeService.php     # NEW: changeSegment(Product, segment, user) — one transaction, regenerates all variant SKUs
app/Http/Requests/UpdateProductRequest.php        # CHANGE: product_segment optional, normalised; blank = keep
app/Http/Controllers/Api/ProductController.php    # CHANGE: update() calls the service when the segment differs
app/Services/MasterDataImportService.php          # VERIFY: uses nextVariantSku (gets the new format automatically)
resources/js/views/ProductsView.vue               # CHANGE: codePreview uses "-1" format; edit save surfaces segment errors
resources/js/utils/productSegment.js              # no change expected
docs/openapi-pos-mvp.yaml                         # CHANGE: PUT /products/{id} product_segment; SKU format text
tests/Feature/ProductTest.php                     # CHANGE/ADD: new format, deleted-number rule, segment change tests
tests/Feature/ProductSegmentChangeTest.php        # NEW: segment change: regenerate, keep numbers, trashed, collision, audit, snapshots
tests/Feature/MasterDataImportTest.php            # CHANGE: expected SKUs for new variants
tests/Feature/** (≈44 files with -00N expectations)   # CHANGE: expected SKU strings only
qa-tests/unit/variantSkuPreview.test.js           # NEW: preview shows "-1" format
```

**Structure Decision**: the format rule stays in `ProductCodeGenerator` (it already owns SKU and code generation). The segment change is a new service because it spans a product and all its variants, writes audit rows, and must run in one transaction; putting it in the controller would break Principle I.

## Design Details

### 1. SKU format (`ProductCodeGenerator`)

- `variantSku(string $prefix, int $number): string` returns `$prefix.'-'.$number` (no padding).
- `nextVariantSku(Product)` keeps the collision loop, but starts from `max(existing number) + 1` over ALL variants including soft-deleted ones, instead of `count + 1`. Counting was already safe for deletes (soft-deleted rows are counted), but the max rule also covers legacy rows whose numbers do not match their count. The loop still retries on collision.
- `sequenceOf(string $sku): ?int` extracts the trailing digits of an existing SKU (`…-001` → 1; legacy `RYUKYSAK0001` → 1), returning null when there is none.

### 2. Segment change (`ProductSegmentChangeService::changeSegment`)

Inside `DB::transaction`:
1. Lock the product row (`lockForUpdate`).
2. Normalise the new segment (`ProductSegment::normalise`). If null or equal to the current one: return, no change.
3. New prefix = `codeGenerator->buildCodePrefix($artist->code, $category->code, $segment)` — this already refuses a code used by another product (cross-mode, including trashed) with a 422 on `product_segment`.
4. Load ALL variants of the product with `withTrashed()`, ordered by id.
5. For each variant, number = `sequenceOf(oldPrefix, old sku)`. If null, assign the next free number of the product. If a number was already used by an earlier variant in this same product, take the next free number (collisions inside one product are only possible with legacy data). Build `newSku = variantSku(newPrefix, number)`.
6. Update `products.code_prefix` and `product_segment`; update each variant `sku`.
7. Activity log: one entry `segment_changed` with old/new prefix and the number of SKUs regenerated; written inside the same transaction (`ActivityLogger`).

Sales and stock snapshots (`order_items.sku_snapshot`, `preorder_items.sku_snapshot`) are not touched (FR-005); stock movements and BOM lines reference variant ids and are unaffected.

### 3. Request and controller

- `UpdateProductRequest`: `'product_segment' => ['sometimes', 'nullable', 'string', 'regex:'.ProductSegment::PATTERN_RULE]`, with `prepareForValidation` normalising it. Blank means keep the current segment (never derive on edit: derivation would silently change codes).
- `ProductController::update`: if `product_segment` is present and differs, call `ProductSegmentChangeService::changeSegment` inside the existing transaction flow; then apply the other fields as today. The existing 'updated' audit entry is kept.
- Response: unchanged shape; the refreshed product includes the new `code_prefix`, and the variant list shows the new SKUs (FR-007: the saved value is visible).

### 4. Import

`MasterDataImportService` already calls `nextVariantSku` for new variants, so new variants get the new format with no import change. Existing SKUs in the sheet are still matched exactly; a regenerated SKU in an old file is an unknown-SKU row (spec Edge Cases).

### 5. SPA

- `ProductsView.vue` `codePreview` (currently `…-001`) shows `…-1`.
- Edit mode: the segment field is already shown. After a successful save with a segment change, the form reloads the product so the variant SKUs refresh; a 422 on `product_segment` shows under the field (existing `formErrors` mapping).

### 6. OpenAPI

`PUT /products/{id}` request gains `product_segment`; `POST /products` and variant create describe the new SKU format; the update description states that a segment change regenerates every variant SKU.

## Design §7 — Saved segment in the edit form (User Story 4)

**Root cause (verified)**: `ProductResource` returns `code_prefix` but not `product_segment`. The edit form reads `full.product_segment`, gets `undefined`, shows a blank field, and its preview falls back to the name.

- **Server**: `ProductResource` adds `'product_segment' => $this->product_segment ?? ProductSegment::fromCode($this->code_prefix)`. For legacy rows with no stored segment, the segment is read from the saved code when it has the `ART-CA-SEG` shape, otherwise null. The rule lives in `App\Support\ProductSegment::fromCode()` (one definition, Principle I). No data change, no migration.
- **Form**: `ProductsView.vue` already maps `full.product_segment` into the form (line ~281); no change needed beyond the response. The preview in edit mode then uses the saved segment through `previewSegment`.
- **Save without change**: the form sends the same segment; `ProductSegmentChangeService` returns early on an unchanged value (FR-010), so nothing is regenerated.
- **OpenAPI**: `Product` schema gains `product_segment` (nullable, 1–3 letters).

Source files added to the structure list:

```text
app/Http/Resources/ProductResource.php         # + product_segment (stored value)
docs/openapi-pos-mvp.yaml                      # Product schema + product_segment
tests/Feature/ProductTest.php                  # + show returns the saved segment
qa-tests/component/ProductsView.test.js        # + edit form shows saved segment; preview uses it
```

## Complexity Tracking

No violations. The new service is justified by the transaction and audit requirement and by Principle I (the controller must not hold this logic). `ProductSegment::fromCode` is a second use of the existing segment rule, not a new layer.
