# Implementation Plan: Remove the Length Limit on Product Segment (6 letters)

**Branch**: `053-variant-sku-manual-edit` (current working branch; the spec directory is `046-segment-length-unlimited`) | **Date**: 2026-10-17 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `specs/046-segment-length-unlimited/spec.md`

## Summary

A product segment grows from 1–3 letters to at most 6 characters (decision, spec FR-001/FR-003), and may now contain single hyphens between letter groups (for example `SH-K`). The hyphen is part of the segment, so codes and SKUs can contain more than the two separators they have today. The
product code grows to at most 13 characters and a variant SKU to at most 20. Three things must
change together: the single segment rule (`App\Support\ProductSegment`) and every copy of it;
the stored widths (migration: `products.product_segment`, `products.code_prefix`,
`product_variants.sku` plus its generated `active_sku`, and both `sku_snapshot` columns); and the
hard-coded 10/14/3 limits in services, requests, the importer, the DEMO clone, the SPA and the
OpenAPI document. Existing data is unchanged. Segment-change logic gains one new refusal: a
segment that would push any regenerated SKU past 20 characters.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel 13 (API); Vue 3 + Pinia + vue-i18n (SPA, Vite, Tailwind v4)
**Primary Dependencies**: maatwebsite/excel (import/export); Vitest; PHPUnit
**Storage**: MySQL 8 (CHAR columns, generated column `active_sku`, unique index on it)
**Testing**: `php artisan test` (run inside the `app` container with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`); `npm test` (qa-tests)
**Target Platform**: Single-machine local install (native or Docker store image)
**Project Type**: Web (Laravel API + Vue SPA on one host)
**Performance Goals**: Unchanged; a segment change regenerates at most a few dozen SKUs in one transaction
**Constraints**: Migration filename date prefix is load-bearing (new migration is dated after 2026-11-18); widening is a raw `ALTER TABLE` (no doctrine/dbal); DEMO and LIVE share unique codes; snapshots are historical and are not rewritten
**Scale/Scope**: About 15 PHP files, 6 JS/Vue files, 1 migration, 1 OpenAPI document, ~6 test files

## Constitution Check

*GATE: checked before Phase 0; re-checked after Phase 1.*

The constitution (`.specify/memory/constitution.md`) has five principles: code quality, testing, UX consistency, security, performance.

- **Code quality**: PASS if the length rule lives in one place (`ProductSegment`) and every other site reads its constants. The plan adds `MAX_SEGMENT`, `MAX_CODE_LENGTH`, `MAX_SKU_LENGTH` to that class and removes the literal 3/10/14 copies.
- **Testing**: PASS. Every changed rule has a test: the unit rule, the request refusal, the SKU width refusal, the segment change refusal, the import, the DEMO clone, and a migration round-trip check.
- **UX consistency**: PASS. The refusal message for a too-long segment or SKU goes on the segment or sequence field, as FR-004 requires. The field's `maxlength` and the label "(1–3)" change to 6 in both languages.
- **Security**: PASS. The segment is still letters only, normalised server-side; the SPA preview is display-only.
- **Performance**: PASS. Wider CHAR columns on a single-store database; no new queries.

No violations, so the Complexity Tracking table stays empty.

## Project Structure

### Documentation (this feature)

```text
specs/046-segment-length-unlimited/
├── spec.md
├── plan.md              # this file
├── research.md          # Phase 0
├── data-model.md        # Phase 1
├── quickstart.md        # Phase 1
├── contracts/
│   └── product-segment.md
└── checklists/requirements.md
```

### Source Code (affected)

```text
app/Support/ProductSegment.php                       # PATTERN, CODE_PATTERN, PATTERN_RULE → letters with single hyphens, ≤ 6 chars; new MAX_* constants
app/Http/Requests/StoreProductRequest.php            # message "1 sampai 3 huruf" → from constant
app/Http/Requests/UpdateProductRequest.php           # same
app/Services/ProductCodeGenerator.php                # docblock; strlen 14 → MAX_SKU_LENGTH; RuntimeException text
app/Services/VariantSkuService.php                   # strlen > 14 (two places) → MAX_SKU_LENGTH; messages
app/Services/ProductSegmentChangeService.php         # refuse if any regenerated SKU > MAX_SKU_LENGTH (new check)
app/Services/MasterDataImportService.php             # line ~728: segment rule and message → 6 chars with hyphens; SKU length check if any
app/Services/DemoClone/CodeAllocator.php             # explode('-') → explode('-', $p, 3) so a hyphenated segment is kept whole; message → 6 chars
app/Services/MasterDataExportService.php             # skuTail() substr(-7) → derive the code from the product code, not from a fixed width
database/migrations/2026_11_19_000001_widen_product_segment_and_sku_columns.php   # NEW
resources/js/utils/productSegment.js                 # previewSegment keeps letters and hyphens, ≤ 6 chars; maxSequenceLength 14 → 20
resources/js/views/ProductsView.vue                  # segment input maxlength="3" → 6; label key
resources/js/locales/en.json, id.json                # segment_3 "(1–3)" → "(1–6)"
docs/openapi-pos-mvp.yaml                            # ~line 905 (1–3), 5997 (sku 14 → 20), 6052 (code_prefix 10 → 13), 6053 (product_segment pattern)
CLAUDE.md                                            # SPECKIT pointer to this plan
```

**Structure Decision**: No new files in app code; one new migration; all changes edit existing
single-responsibility units. The rule stays in `ProductSegment` (spec 042 decision: "jangan
menyalin polanya ke tempat lain").

## Phase 0: Research

See [research.md](./research.md). Resolved decisions: the migration shape (drop generated
`active_sku`, widen, re-add), the segment column type (`CHAR(6)`, matching the other CHAR
columns), the constants location, and the length check for segment changes.

## Phase 1: Design

- [data-model.md](./data-model.md): the new widths and the length rules.
- [contracts/product-segment.md](./contracts/product-segment.md): the API-visible changes (segment pattern, code/SKU lengths, error messages).
- [quickstart.md](./quickstart.md): manual and automated checks.

## Post-design Constitution Check

Re-checked after the design: still PASS. The migration is the only new file and is reversible
(with a documented data caveat, below).

## Complexity Tracking

No constitution violations to justify.
