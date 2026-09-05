# Implementation Plan: Preorder List Filters, Seller Info & Receipt-Style Invoice

**Branch**: `013-preorder-list-filters-receipt` | **Date**: 2026-09-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/013-preorder-list-filters-receipt/spec.md`

## Summary

Five additive slices on top of the existing Pre-orders screen, none requiring a schema
change: (1) a seller filter + a visible seller column/detail, both derived by finally
exposing the `preorder_items.artist_id` column that already exists but has never been surfaced
as a relation or in any response; (2) making the transaction number itself clickable to open
the same detail view its existing "Detail" button already opens; (3) restyling
`PreorderInvoiceModal.vue` to match the POS receipt's visual conventions while showing the
preorder's live granular status, by reusing the "Pre-order marking + StatusPill" pattern
`PreorderPaymentReceiptModal.vue` already established in `010`; (4) renaming the two
"Print"-wording locale keys actually used on this screen to "Receipt" wording, in both
languages; (5) a new `GET /preorders/summary` aggregate endpoint (count, per-status totals,
grand total, outstanding), computed from the same filtered query `index()` already builds so
it can never disagree with what's on screen. See research.md R1–R7 for the full reasoning,
including why the summary reuses `Preorder.total_amount`/`paid_amount` directly rather than
the artist-proration revenue rule `010`/`012` use elsewhere (that rule solves a different,
per-seller-attribution problem this preorder-level summary doesn't have).

## Technical Context

**Language/Version**: PHP 8.2 (Laravel 13), Vue 3 (Composition API, `<script setup>`), Vite

**Primary Dependencies**: Existing stack only — Eloquent, `vue-i18n`, `html2canvas` + `jsPDF`
(already used by `PreorderInvoiceModal.vue`/`ReceiptModal.vue`). No new dependency.

**Storage**: MySQL 8 (existing `preorders`/`preorder_items`/`artists` tables) — no migration.

**Testing**: `php artisan test` (PHPUnit, `tests/Feature/PreorderTest.php`) against real MySQL;
`npm test` (Vitest, `qa-tests/component/PreordersView.test.js` and the invoice modal's test
file) with mocked APIs; manual browser verification per Constitution II.

**Target Platform**: Same single-machine Laravel+Vue SPA as the rest of this codebase.

**Project Type**: Web application (existing Laravel API + Vue SPA in one repo).

**Performance Goals**: No new goal beyond existing conventions — `GET /preorders/summary`
is a small aggregate query (COUNT + two SUMs) over an already-indexed, already-paginated
table; not a hot path.

**Constraints**: Must not change any existing response field's meaning or shape (additive
only) — other callers of `GET /preorders`/`GET /preorders/{id}` must keep working unchanged.

**Scale/Scope**: One controller (`PreorderController`), one model relation
(`PreorderItem::artist()`), one Vue view (`PreordersView.vue`), one Vue component
(`PreorderInvoiceModal.vue`), locale files, `docs/openapi-pos-mvp.yaml`.

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Code Quality & Maintainability**: Filter logic is extracted into one shared
  `applyFilters()` helper reused by `index()` and the new `summary()` (research.md R4) — no
  duplicated predicate logic. `PreorderItem::artist()` reuses the existing `artist_id` column
  rather than adding a denormalized/duplicated field (research.md R1). PASS.
- **II. Testing Standards**: New backend behavior (artist filter, `sellers` field, `summary()`)
  gets `tests/Feature/PreorderTest.php` coverage against real MySQL; new frontend behavior gets
  `qa-tests/` coverage; the invoice restyle and summary display get real-browser verification
  per quickstart.md before being declared done. PASS.
- **III. User Experience Consistency**: Seller filter reuses the exact same BaseSelect
  single-select pattern just shipped on the Reports screen (`012`, same session) rather than
  inventing a new filter widget; the invoice restyle reuses an already-shipped visual pattern
  (`PreorderPaymentReceiptModal.vue`) rather than a new one; all new/changed UI copy is
  Indonesian-first with an English counterpart via the existing `vue-i18n` setup, matching
  every other screen. PASS.
- **IV/V (Security, Performance)**: No new authorization surface — `GET /preorders/summary`
  reuses `GET /preorders`'s existing (implicit, no stricter) authorization; no
  performance-sensitive path introduced. PASS.

No violations — Complexity Tracking section omitted.

## Project Structure

### Documentation (this feature)

```text
specs/013-preorder-list-filters-receipt/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md         # Phase 1 output
├── quickstart.md         # Phase 1 output
├── contracts/
│   └── api-deltas.md     # Phase 1 output
└── tasks.md              # Phase 2 output (/speckit-tasks — not created by /speckit-plan)
```

### Source Code (repository root)

```text
app/
├── Http/Controllers/Api/PreorderController.php   # index() gains artist_id filter + sellers
│                                                  # field; present() gains sellers/items[].artist_*;
│                                                  # new summary() action; new applyFilters() helper
├── Models/
│   ├── Preorder.php                              # unchanged (no new relation needed here)
│   └── PreorderItem.php                          # new artist(): BelongsTo relation
routes/api.php                                    # new GET /preorders/summary route
docs/openapi-pos-mvp.yaml                         # documents all of the above (PRD §9.5)

resources/js/
├── api/preorders.js                              # new getPreorderSummary(params) call
├── views/PreordersView.vue                       # seller filter, seller column, clickable
│                                                  # number, summary display, print_action rename
├── components/preorder/
│   └── PreorderInvoiceModal.vue                  # restyled: receipt-style layout, status
│                                                  # marking, per-item seller, "Receipt" wording
├── locales/{id,en}.json                          # print_action/print_payment_receipt renamed,
│                                                  # new keys for seller filter/column, summary

tests/Feature/PreorderTest.php                    # artist_id filter, sellers field, summary()
qa-tests/component/PreordersView.test.js          # seller filter, clickable number, summary
qa-tests/component/PreorderInvoiceModal.test.js   # (if exists) restyled layout, status marking
```

**Structure Decision**: Existing single-repo Laravel API + Vue SPA structure — no new
directories. Every change lands inside files that already exist for this exact screen, per
the file list above.

## Complexity Tracking

*No violations — section intentionally left empty.*
