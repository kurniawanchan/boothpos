# Implementation Plan: Seller-specific BOM Built from Purchase Order Lines

**Branch**: `034-seller-po-bom` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/034-seller-po-bom/spec.md`

## Summary

Today a variant's BOM is "material + quantity", priced live from a vendor price list; services do not exist in it, nothing ties it to what was actually bought, a purchase order (PO) has no seller, and a variant's `cost_price` is typed by hand. This feature makes a BOM the traceable list of **PO lines** that produce one variant:

1. **PO gets a seller** (`purchase_orders.artist_id`, one seller per PO, required on create, blank = legacy). The "Linked Product" field leaves the PO form (stored values stay readable).
2. **BOM rows reference a PO line.** The existing `product_variant_bom_lines` table is **extended, not replaced**: new nullable `purchase_order_item_id` plus a **snapshot** of item name / type / PO number / vendor / unit cost taken when the row is added. A row without a PO line is a **legacy row** (kept, flagged, still costed the old way).
3. **One service, `VariantBomService`**, owns every BOM mutation (add several PO lines, change quantity, remove, replace source, copy to all/next/from another, mark complete, reopen) and runs the cost-price sync and the audit log inside the same transaction.
4. **Cost**: `BomCostCalculator` becomes material + service + total (snapshot cost for PO rows, live vendor-catalogue price for legacy rows). When a variant's BOM is **complete**, `cost_price` = BOM cost, kept in sync on every change and **locked against hand edits** (409), including through the product Excel import. Past sales/pre-orders are untouched because they already snapshot `cost_price`.
5. **Safety**: nothing ever reprices a row by itself. Newer prices and cancelled source POs are computed at read time and shown as cues; the user explicitly replaces a row's source.
6. **UI**: `VariantBomModal` becomes the table (Item / Type / Purchase Order / Vendor / Unit Cost / Quantity / Total Cost / Action) with Add BOM Item selector (filters, multi-select), copy menu, complete/reopen; the product edit variant card shows the BOM state and locks cost price when complete; the PO form gets a Seller field and loses Linked Product; the PO list/detail show the seller.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel; Vue 3 SPA (Vite, Pinia, vue-i18n en/id, Tailwind v4 tokens)

**Primary Dependencies**: none new

**Storage**: MySQL 8 — **additive migrations only**: `purchase_orders.artist_id`; `product_variant_bom_lines` gains PO-source + snapshot columns (and `material_id` becomes nullable; the `(variant, material)` unique key is replaced); `product_variants` gains `bom_complete` + timestamps. No data is deleted or rewritten.

**Testing**: PHPUnit on the host (`APP_ENV=testing`, `boothpos_test`) incl. the existing BOM/PO/product/report suites as regression; Vitest + Testing Library; real-browser check on an isolated server + test DB (Constitution II)

**Target Platform**: Local Laravel app + browser SPA (no cloud tier)

**Project Type**: Web application (backend + frontend)

**Performance Goals**: eligible-line selector paginated (default 25, max 100) with indexes on `purchase_orders(artist_id, status)` and `product_variant_bom_lines(purchase_order_item_id)`; "newer price"/"source cancelled" cues computed with ONE batched query per BOM view (no N+1)

**Constraints**: server is the only source of costs (client never sends a unit cost); a row's recorded cost never changes without an explicit action; historical orders/pre-orders/reports unchanged; DEMO/LIVE respected (`PurchaseOrder` and `ProductVariantBomLine` already use `HasDataMode`; `PurchaseOrderItem` is reached only through its scoped PO); migration filename date prefixes are load-bearing (next free: `2026_11_02_*`)

**Scale/Scope**: 4 migrations, 1 new service, 1 calculator change, ~8 endpoints (BOM) + 3 PO changes, 1 modal rewrite + 1 new selector modal, product variant card, PO form/list/detail, locales, OpenAPI, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 — still passes.*

| Principle | Assessment |
|---|---|
| I. Single write path / no duplication | **Pass.** `VariantBomService` is the only code that writes BOM rows, completion state and the synced `cost_price`; the legacy `storeBomLine`/`updateBomLine`/`destroyBomLine` controller actions and the Excel import call it (or its guard) instead of writing directly. Cost maths lives only in `BomCostCalculator`. Audit goes through `ActivityLogger` only. |
| II. Testing | **Pass (planned).** Backend tests per rule: seller filter, eligibility, snapshot immutability, newer-price/cancelled cues, duplicate, qty>0, copy modes + independence, complete/reopen/auto-reopen, cost-price sync + lock (API and Excel), audit rows, legacy handling, authorization, DEMO/LIVE; Vitest for modal/selector/product card/PO form; real-browser run of the whole flow. |
| III. UX consistency | **Pass.** `DataTable`/`BaseModal`/`BaseSelect`, token classes only; 422/409/403 mapped by the central handler; controls a role cannot use are hidden (BOM editing needs `products` + `purchase_orders`). Copy in en + id. |
| IV. Security | **Pass.** Costs come from the server's PO line, never from the request; seller rule enforced server-side in the eligible-lines query AND again on add/replace (rejects another seller's line with 422); BOM mutations gated on both menus, PO prices keep the `purchase_orders` gate; cost price locked server-side; every mutation audited in the same transaction. Recorded sales keep their cost snapshot (already immutable). |
| V. Performance | **Pass.** Paginated selector, batched cue query, indexes above. |
| Documentation discipline | `docs/openapi-pos-mvp.yaml` moves in the same change (PRD §9.5); `CLAUDE.md` gets a BOM/PO section; the stale "vendor/material/BOM" bullets are amended (BOM no longer priced only from the vendor list). |

No violations → Complexity Tracking not required.

## Project Structure

### Documentation (this feature)

```text
specs/034-seller-po-bom/
├── plan.md
├── research.md                 # 15 decisions + alternatives
├── data-model.md               # column-level changes, states, invariants
├── contracts/bom-po-api.md     # endpoints + payload shapes + error codes
├── quickstart.md               # automated + isolated real-browser verification
├── checklists/requirements.md
└── tasks.md                    # created later by /speckit-tasks
```

### Source Code (repository root)

```text
database/migrations/2026_11_02_000001_add_artist_id_to_purchase_orders_table.php
database/migrations/2026_11_02_000002_add_po_source_to_product_variant_bom_lines_table.php
database/migrations/2026_11_02_000003_add_bom_complete_to_product_variants_table.php
app/Services/VariantBomService.php            # NEW — the only BOM writer
app/Services/BomCostCalculator.php            # material/service/total, snapshot vs legacy
app/Services/PurchaseOrderService.php         # artist_id on create/update (+ lock when used by a BOM)
app/Models/{ProductVariantBomLine,ProductVariant,PurchaseOrder,PurchaseOrderItem}.php
app/Http/Controllers/Api/{MaterialController (BOM actions delegate),PurchaseOrderController,ProductController}.php
app/Http/Controllers/Api/VariantBomController.php   # NEW — eligible-lines, add, replace, copy, complete, reopen
app/Http/Requests/{StoreBomItemsRequest,CopyBomRequest,ReplaceBomSourceRequest (NEW), StorePurchaseOrderRequest, UpdatePurchaseOrderRequest, UpdateVariantRequest}.php
app/Http/Resources/BomLineResource.php
app/Services/MasterDataImportService.php      # products sheet: cost_price vs complete BOM; bom sheet: complete variant
routes/api.php
resources/js/components/product/{VariantBomModal.vue (rewrite),AddBomItemModal.vue (NEW),BomCopyMenu.vue (NEW)}.vue
resources/js/views/{ProductsView.vue,PurchaseOrdersView.vue}, components/purchaseOrders/PurchaseOrderDetailModal.vue
resources/js/api/{materials.js,purchaseOrders.js}, locales/{en,id}.json
docs/openapi-pos-mvp.yaml, CLAUDE.md
tests/Feature/{VariantBom*Test,PurchaseOrderSellerTest}.php (new), existing BOM/PO/Product tests (adjusted where the contract deliberately changed)
qa-tests/component/{VariantBomModal,AddBomItemModal,ProductsViewBom,PurchaseOrderForm}.test.js
```

**Structure Decision**: Extend the existing vendor/material/BOM and purchase-order modules; one new service + one new controller for the BOM workflow; no new top-level concepts.

## Complexity Tracking

No constitution violations to justify.
