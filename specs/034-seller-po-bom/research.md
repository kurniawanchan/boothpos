# Research: Seller-specific BOM from Purchase Order Lines

## Evidence (current behaviour)

- `purchase_orders` has `vendor_id` and status (draft → ordered → received → paid, or cancelled), **no seller**. `purchase_order_items` has `line_type` (material/service), `material_id` (required for material lines), optional `product_id` ("Linked Product"), `description` (required for service lines), `qty`, `unit_price`. A draft PO's lines are **deleted and recreated** on every edit (`PurchaseOrderService::update`); only drafts can be edited or deleted; ordered/received/paid/cancelled are final for lines.
- `product_variant_bom_lines`: `product_variant_id`, `material_id` (NOT NULL), `qty_needed` decimal(12,4), `notes`; **UNIQUE(product_variant_id, material_id)**. Routes `GET/POST /variants/{v}/bom`, `PUT/DELETE /bom/{line}` live in `MaterialController`; only `storeBomLine` and `destroyBomLine` write activity logs. `BomCostCalculator` prices each line from the material's vendor price list (preferred vendor, else cheapest, else 0) at read time and never writes `cost_price`.
- `product_variants.cost_price` is written by `ProductController::store/storeVariant/updateVariant` (`price_changed` audit) and the product Excel import. `OrderService`/`PreorderService` copy `cost_price` into `order_items`/`preorder_items` at sale time (immutable snapshot) — every report reads that snapshot.
- A product belongs to exactly one seller (`products.artist_id`); variants inherit it. PO read is gated on the `purchase_orders` menu (owner, admin and inventory get it by default) precisely because PO lines carry real purchase prices; BOM routes are gated on `products`.
- `MasterDataImportService` has a `bom` sheet (material code + qty per variant SKU, `firstOrNew(['material_id'])`) and a `products` sheet that writes `cost_price`.

## Decision 1 — Extend `product_variant_bom_lines`; legacy = a row without a PO line

**Decision**: keep one BOM table. Add `purchase_order_item_id` (nullable FK, `restrictOnDelete`) and snapshot columns; a row with `purchase_order_item_id IS NULL` is a **legacy row**. Make `material_id` nullable (service rows have no material).

**Rationale**: FR-030 requires legacy rows to stay visible, costed and replaceable one by one — impossible if they live in a different table than the new rows. One table keeps the routes, resource, Excel sheet, seeder and tests valid; "legacy" is derived, not stored (no flag to drift).

**Alternatives**: a second table `variant_bom_items` + migrating/hiding the old (two BOM concepts, double cost logic); a `source` enum column (redundant with the FK being NULL).

## Decision 2 — Row stores a SNAPSHOT, the FK is only the trace

**Decision**: at add/replace the service copies from the PO line into the row: `line_type`, `item_name` (material name or service description), `purchase_order_id`'s number as `po_number`, `vendor_id` + `vendor_name`, `unit_cost`. Displays and totals read the snapshot; the FK exists for traceability, cue computation and lock rules.

**Rationale**: FR-020/FR-023 — price changes, a cancelled PO, a renamed vendor or material must never alter a recorded cost. Mirrors the project's rule of immutable snapshots for financial history (Constitution IV). Display also survives soft-deleted vendors/materials.

**Alternatives**: join to the PO line for cost (silently follows edits — violates FR-020); store only `unit_cost` (loses vendor/PO identity when the source changes).

## Decision 3 — Allow several PO lines of the same material in one BOM; unique on the PO line

**Decision**: drop `UNIQUE(product_variant_id, material_id)`, add an index on `product_variant_id` first (MySQL needs it as the FK's supporting index), then `UNIQUE(product_variant_id, purchase_order_item_id)` (NULLs allowed many times). Legacy rows keep the one-per-material rule **in the service** (the Excel `bom` import and `storeBomLine` already look up by material).

**Rationale**: the spec's edge case — two PO lines of the same material at different prices — must be selectable, and only the same *line* is a duplicate (FR-011).

## Decision 4 — PO seller is a nullable FK on the header

**Decision**: `purchase_orders.artist_id` (nullable, index `(artist_id, status)`). Required by `StorePurchaseOrderRequest` for new POs; `UpdatePurchaseOrderRequest` accepts it (legacy assignment, or a change while no BOM row references any of the PO's lines — 409 otherwise). No automatic backfill from "Linked Product".

**Rationale**: requester chose one seller per PO. Guessing a seller for old POs from linked products could be wrong and would silently decide which BOMs may use them; an explicit, audited assignment is safer. Artists are soft-deleted, so the FK never blocks artist deletion; the name is read `withTrashed()`.

**Alternatives**: seller per line (rejected by requester); backfill by linked product (rejected: guessing).

## Decision 5 — Eligibility = same seller + ordered/received/paid

**Decision**: the selector (and the server-side re-check on add/replace) accepts a PO line iff its PO has `artist_id = variant.product.artist_id`, status ∈ {ordered, received, paid}, and is in the active DEMO/LIVE mode. Drafts are excluded because their lines are rewritten on every edit (unstable references); cancelled POs are not real purchases.

## Decision 6 — Cues are computed at READ time; nothing reprices itself

**Decision**: `GET` of a BOM adds per row, from ONE batched query: `source_cancelled` (source PO status = cancelled) and `newer_price` (the most recent eligible line of the same seller for the same item — `material_id` for materials, case-insensitive trimmed `description` for services — whose PO is newer than the row's source and whose unit price differs; returns that line's id, PO number, price). The user's explicit action `replace-source` re-snapshots the row and keeps its quantity.

**Rationale**: FR-021/FR-022 without a background job, a queue, or any stored "stale" state that can itself go stale.

## Decision 7 — Completion + cost-price sync in one service, one transaction

**Decision**: `product_variants` gains `bom_complete` (bool, default false), `bom_completed_at`, `bom_completed_by`. `VariantBomService` methods (`addItems`, `updateQty`, `remove`, `replaceSource`, `copy`, `complete`, `reopen`) each: lock the variant row, mutate, recompute via `BomCostCalculator`, **if complete** write `cost_price` = total BOM cost, then write the audit rows — all in one DB transaction. `complete()` refuses with 409 when the BOM is empty, has a legacy row, or has a row with qty ≤ 0 / missing source (FR-025). Removing the last row of a complete BOM **auto-reopens** it (cost price keeps its last value) and the response says so.

**Rationale**: Constitution I (single write path, audit in the same transaction); the requester chose "cost price follows the BOM once complete".

**Alternatives**: a model observer syncing cost price (hidden side effects, bypassable by raw queries, hard to audit); a computed `cost_price` accessor (would rewrite history of every report that reads the column).

## Decision 8 — Lock `cost_price` server-side while complete

**Decision**: `ProductController::updateVariant` returns **409** when the variant is complete and the request's `cost_price` differs from the stored value (an unchanged value is accepted, because the SPA sends the whole variant); the product Excel import reports a row error for a complete variant whose `cost_price` cell differs; the Excel `bom` sheet and the legacy `storeBomLine` return an error/409 for a complete variant (they would create a legacy row). Reopening releases the lock and leaves the value.

## Decision 9 — Past transactions are unaffected (verified, not changed)

`OrderService` / `PreorderService` already snapshot `cost_price` into the item rows when the sale/pre-order is made, and every profit/settlement report reads those snapshots. Changing `cost_price` through BOM sync therefore only affects later transactions (FR-029). A test pins it: sell, complete a BOM at a different cost, re-open the reports, assert unchanged.

## Decision 10 — Copy: one endpoint, three modes, rows duplicated

**Decision**: `POST /variants/{target}/bom/copy` with `mode`: `from` (+ `source_variant_id`), and `POST /variants/{source}/bom/copy-out` with `mode`: `next` | `all`. Rules: source and target must share the same product (hence seller); a target that already has rows needs `confirm_replace: true` (422/409 otherwise); rows are duplicated with their snapshots and quantities (legacy rows too); a copy **never** completes the target, and a complete target is **reopened** (cost price keeps its value). `next` = the next non-deleted variant of the product by id after the source (none → 422). New variants may be created with `copy_bom_from_variant_id` on `POST /products/{product}/variants` (the "copy while creating" option). No shared template (out of scope).

## Decision 11 — Authorization

**Decision**: reading a BOM/cost breakdown keeps the `products` menu gate; **every BOM mutation, the eligible-lines endpoint and copy** require BOTH `products` and `purchase_orders` menus (the selector exposes PO unit costs and vendors, which the PO policy reserves for the `purchase_orders` menu). Server-side only; the UI hides the controls for others. The PO seller field follows the existing PO policy.

## Decision 12 — "Linked Product" retired from the UI, not the data

**Decision**: remove the field from `PurchaseOrdersView` (and its locale text use); keep `purchase_order_items.product_id`, keep accepting an optional `items.*.product_id` in the API, and carry an existing value through hidden when a draft is edited (the form already loads it into row state) so editing a draft cannot silently drop it. Detail views still show a stored linked product read-only.

## Decision 13 — Quantity semantics

BOM `qty_needed` is per ONE finished unit (decimal(12,4), > 0). The source line's purchased `qty` is returned as `po_qty` for reference only; there is no consumption tracking, so no limit is enforced (FR-013; future extension).

## Decision 14 — UI shape

- **`VariantBomModal`** (opened from the variant row in `ProductDetailModal`, as today): table + totals (Material / Service / Total), badges (Legacy, Source cancelled, Newer price), per-row Remove, quantity edit inline, "Add BOM Item", copy menu (Copy to all variants / to next variant / from another variant), "Mark BOM complete" / "Reopen BOM".
- **`AddBomItemModal`**: search + filters (PO number, vendor, item/material text, type, PO date range) over the eligible lines, checkboxes, rows already in the BOM disabled with "In BOM", "Add selected (N)".
- **Product edit drawer variant card**: when `bom_complete`, cost price is read-only with a "From BOM" badge; saved variants show BOM state/cost and an "Open BOM" link; new unsaved variants get "BOM: start empty / copy from [variant]".
- **PO form**: Seller select (required), Linked Product removed; list shows a Seller column + filter; legacy POs show "No seller" with an "Assign seller" action in the detail.

## Decision 15 — Excel and seeder

The master-data `bom` sheet and `vendor_prices` sheet keep working unchanged (they create/update legacy rows; error row for a complete variant). `SakanaFridgeDemoSeeder` keeps its legacy BOM lines (valid legacy rows); no demo POs are added (optional follow-up).

## Alternatives considered (summary)

| Alternative | Why rejected |
|---|---|
| New BOM table, deprecate the old | Two BOM concepts; migration/hiding of legacy rows contradicts FR-030 |
| BOM cost read live from PO lines | Silently reprices history (FR-020) |
| Model observer / accessor for cost price | Hidden side effects; accessor rewrites report history |
| Seller on each PO line | Rejected by the requester |
| Auto-backfill PO seller from linked product | Guessing; wrong seller would unlock wrong BOMs |
| Block adding rows beyond PO quantity | No consumption tracking yet (FR-013) |
| Allow draft POs as BOM sources | Draft lines are deleted/recreated on edit → dangling references |
| Shared BOM templates | Out of scope; copy duplicates rows |

## Risks / notes

- Dropping `UNIQUE(product_variant_id, material_id)` must be preceded by a plain index on `product_variant_id` or MySQL refuses (FK needs an index).
- Changing `material_id` to nullable via `->change()` on MySQL 8: verified by the migration test running on `boothpos_test`.
- Existing tests that post BOM lines for a material only keep passing (legacy path); tests that asserted `bom_cost` semantics get the new split fields added, not removed.
- The `price_changed` audit on `updateVariant` stays; BOM-driven changes write a distinct `cost_price_synced` entry so the two are distinguishable.
