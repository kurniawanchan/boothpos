# Implementation Plan: BOM, Variant History, and Product/Stock List Refinements

**Branch**: `036-bom-variant-stock-ux` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/036-bom-variant-stock-ux/spec.md`

## Summary

Five slices, mostly frontend, with a small backend core:

1. **BOM dialog (US1)** — quantities become whole numbers end to end (one shared validation rule used by every HTTP and Excel entry point); edits become a *draft* saved by an explicit **Save** through ONE new all-or-nothing batch endpoint (`PUT /variants/{variant}/bom`) built on `VariantBomService` (one lock, one transaction, audit + cost-price sync inside it); an unsaved-changes guard; the variant's current stock is returned in the BOM payload and shown read-only; cost/quantity labels say "per 1 product". The cost calculation is untouched.
2. **Copy-from picker (US2)** — root cause found: `BaseSelect` closes on ANY scroll event (window-level capture listener), including scrolling its own list, so a long list can never be scrolled. Fix there (ignore scrolls inside the panel) and add an opt-in `searchable` mode (default off, so the 8 existing callers are unchanged). `BomCopyMenu` opts in.
3. **Variant history (US3)** — reuse `GET /stock/movements` (already filters by `variant_id`/`type`/dates): extend its rows with `user_name`, product/variant names and a resolved `reference` (order / pre-order / purchase-order number, batch-resolved, no N+1), add the missing server-side access gate, and add a read-only `VariantHistoryModal` opened from the variant row in the product detail.
4. **Product list (US4)** — larger thumbnail, one-line code, and the missing header: `master_data.col_type` was never defined (the ONLY missing literal key in the whole app — verified by scan); add it, plus a static test that fails on any future missing key.
5. **Stock list (US5)** — SKU becomes a button that opens the existing `ProductDetailModal` with the variant highlighted (needs `product_id` in the movement row); the Type header is fixed by the same key.

No migration. One new route, one extended route, one new validation rule class.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel; Vue 3 SPA (Pinia, vue-i18n en/id, Tailwind v4 tokens)

**Primary Dependencies**: none new

**Storage**: MySQL 8 — **no migration** (`stock_movements` already has `reference_type/reference_id/user_id`, indexed on `(variant_id, created_at)`; `qty_needed` stays `decimal(12,4)` so legacy fractional rows remain readable)

**Testing**: PHPUnit on the host (`APP_ENV=testing`, `boothpos_test`); Vitest + Testing Library; real-browser check on an isolated server with the test DB

**Target Platform**: Local Laravel app + SPA (native or Docker)

**Project Type**: Web application

**Performance Goals**: variant history first page < 2 s at 1,000 movements (index `(variant_id, created_at)` already exists; references resolved with ≤ 3 grouped queries per page); batch save = one transaction

**Constraints**: server recomputes cost, the client never sends money; BOM writes stay inside `VariantBomService`; audit rows inside the same transaction; status codes 422/409/403 as per convention; DEMO/LIVE scoping unchanged (`StockMovement` already `HasDataMode`); `docs/openapi-pos-mvp.yaml` moves in the same commit; all UI copy in en+id

**Scale/Scope**: 1 rule class, 1 service method, 1 new route + 1 extended payload, 1 controller gate, ~6 frontend components touched/added, 1 util, locales, docs, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 design.*

| Principle | Assessment |
|---|---|
| I. Clean code / single write path | Batch save goes through `VariantBomService` (the only BOM writer) — no controller logic; one `WholeBomQuantity` rule shared by all entry points (DRY); history reuses the existing movements endpoint instead of a parallel one; movement type/label map extracted once (`utils/stockMovements.js`) for StockView + history modal. PASS |
| II. Testing | Backend feature tests for the batch endpoint, whole-number rule on every entry point, payload stock, movements shape/gate/references; Vitest for BaseSelect, BOM modal, history modal, StockView, ProductsView, locale scan; real-browser verification planned (T-final). PASS |
| III. UX consistency | Tokens only (no hex); standard 422/409/403 handling through the shared client; controls the role cannot use are hidden (SKU is plain text without `products` access; BOM edit controls already hidden). Copy in Indonesian + English. PASS |
| IV. Security | Server enforces whole numbers and recomputes totals; batch endpoint requires the same `products` + `purchase_orders` gate as every BOM mutation; **closes a gap**: `GET /stock/movements` was authenticated-only (any role) — now gated to the `stock` or `products` menu, and the new `user_name` field is therefore not exposed to cashiers; audit rows in the same transaction. PASS |
| V. Performance | References batch-resolved (grouped `whereIn`), eager-loaded user/variant/product, paginated; no N+1; no new frontend dependency. PASS |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/036-bom-variant-stock-ux/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── bom-batch-and-movements.md
├── checklists/requirements.md
└── tasks.md        # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Rules/WholeBomQuantity.php                       # NEW – whole number >= 1 (accepts 2 / "2" / 2.0, refuses 1.5)
├── Http/Requests/UpdateBomItemRequest.php           # qty rule -> WholeBomQuantity
├── Http/Requests/StoreBomItemsRequest.php           # items.*.qty rule
├── Http/Requests/StoreBomLineRequest.php            # legacy create qty rule
├── Http/Requests/UpdateBomQuantitiesRequest.php     # NEW – lines[].id + lines[].qty_needed (distinct ids, max 200)
├── Http/Controllers/Api/VariantBomController.php    # + updateQuantities()
├── Http/Controllers/Api/StockController.php         # movements(): gate, user_name, product/variant, reference
├── Services/VariantBomService.php                   # + updateQuantities(), payload() summary.current_stock
├── Services/MasterDataImportService.php             # bom sheet: whole-number row error
└── Services/StockMovementReferences.php             # NEW – batch-resolve reference_type/id -> {type,id,number}
routes/api.php                                       # PUT /variants/{variant}/bom
lang/{en,id}/bom.php, stock.php                      # messages
docs/openapi-pos-mvp.yaml

resources/js/
├── components/ui/BaseSelect.vue                     # scroll-close fix + opt-in `searchable`
├── components/product/VariantBomModal.vue           # draft + Save + guard + stock card + per-unit labels
├── components/product/BomCopyMenu.vue              # searchable picker
├── components/product/VariantHistoryModal.vue      # NEW
├── components/product/ProductDetailModal.vue       # History button + highlightVariantId
├── views/ProductsView.vue                          # thumbnail, one-line code
├── views/StockView.vue                             # SKU button -> detail, uses shared movement util
├── utils/stockMovements.js                         # NEW – type -> pill variant / label key
├── api/materials.js, api/stock.js                  # saveBomQuantities(), movement params
└── locales/{en,id}.json                            # col_type, per-unit labels, history, save/unsaved…

tests/Feature/  BomQuantitiesTest, BomWholeQuantityTest, StockMovementsTest (extended)
qa-tests/       component/{BaseSelect,VariantBomModal,BomCopyMenu,VariantHistoryModal,StockView,ProductsView}, unit/localeKeys
```

**Structure Decision**: existing Laravel + Vue monolith layout; new files only where a single-responsibility unit is genuinely new (rule, references resolver, history modal, small util).
