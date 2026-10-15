---

description: "Task list for feature 034 — seller-specific BOM built from purchase order lines"
---

# Tasks: Seller-specific BOM Built from Purchase Order Lines

**Input**: Design documents from `/specs/034-seller-po-bom/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/bom-po-api.md, quickstart.md

**Tests**: INCLUDED (Constitution II; the spec's rules are all testable guarantees). Existing BOM / PO / Excel tests that encode the old contract are adjusted deliberately, never loosened silently.

**Organization**: By user story. Priority order is US2 → US1 (both P1; **US2 is a prerequisite of US1** because the BOM selector filters on the PO's seller) → US3 / US4 / US5 (P2) → US6 (P3).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1..US6

## Conventions to honour

- Comments, locale `id` copy, commit messages in Indonesian; locale keys in BOTH `en.json` and `id.json` (a key repeated within a section silently overrides — grep for the name first).
- Every hand-rolled `DB::table(...)` query filters `data_mode` explicitly; `PurchaseOrderItem` has no `HasDataMode` — reach it only through its scoped `PurchaseOrder` (`whereHas('purchaseOrder', …)`).
- Backend tests on the HOST only: `APP_ENV=testing php artisan test --filter=…` (database `boothpos_test`, wiped each run); never inside the `app` container without the `-e` overrides.
- Money in responses is a 2-dp string; compute in cents (`App\Support\ReportSplit::cents()/money()` already exist — reuse them, do not add another helper).
- Every BOM mutation goes through `VariantBomService` (the only writer); audit via `ActivityLogger` inside the same transaction.
- Status codes: 422 validation, 409 business-rule conflict, 403 role denial.
- Migration filename date prefixes are load-bearing: use exactly `2026_11_02_000001/2/3_*`.

---

## Phase 1: Setup

- [X] T001 Read the code this feature extends and note line ranges before editing: `app/Services/PurchaseOrderService.php`, `app/Http/Controllers/Api/{PurchaseOrderController,MaterialController (BOM actions ~170-260),ProductController (storeVariant/updateVariant)}.php`, `app/Services/{BomCostCalculator,MasterDataImportService (validateBom/applyBom, products sheet cost_price)}.php`, `app/Models/{ProductVariantBomLine,ProductVariant,PurchaseOrder,PurchaseOrderItem}.php`, `database/migrations/2026_10_08_000001_*` (BOM table, UNIQUE(variant, material)) and `2026_10_12_000002_*`, `resources/js/{components/product/VariantBomModal.vue,views/PurchaseOrdersView.vue,views/ProductsView.vue,api/materials.js}`.
- [X] T002 Record the baseline (must be green before any change): `APP_ENV=testing php artisan test --filter='BomCostTest|MaterialTest|PurchaseOrder|PurchasesReportTest|MasterDataImportVendorMaterialTest|ProductTest'` and `npx vitest run qa-tests/component/VariantBomModal.test.js qa-tests/component/ProductsView.test.js`.

---

## Phase 2: Foundational (blocks every user story)

**Purpose**: schema, models, the cost calculator and the service skeleton every story builds on.

- [X] T003 Create `database/migrations/2026_11_02_000001_add_artist_id_to_purchase_orders_table.php`: nullable `artist_id` FK → `artists` (`restrictOnDelete`), index `(artist_id, status)`; `down()` drops them. No backfill.
- [X] T004 Create `database/migrations/2026_11_02_000002_add_po_source_to_product_variant_bom_lines_table.php`: add `purchase_order_item_id` (nullable FK → `purchase_order_items`, `restrictOnDelete`, indexed), `line_type` enum(`material`,`service`) NOT NULL default `material`, `item_name` string(255) nullable, `po_number` string(30) nullable, `vendor_id` (nullable FK → `vendors`, `nullOnDelete`), `vendor_name` string(255) nullable, `unit_cost` decimal(14,2) nullable; make `material_id` nullable (`->change()`); **order matters**: add a plain index on `product_variant_id` FIRST, then drop `UNIQUE(product_variant_id, material_id)`, then add `UNIQUE(product_variant_id, purchase_order_item_id)`. Indonesian comment explaining why (MySQL FK needs a supporting index). `down()` restores the old unique only if no row violates it (otherwise leave the new index) — document that limitation.
- [X] T005 Create `database/migrations/2026_11_02_000003_add_bom_complete_to_product_variants_table.php`: `bom_complete` boolean default false, `bom_completed_at` timestamp nullable, `bom_completed_by` FK → `users` nullable `nullOnDelete`.
- [X] T006 [P] Update models: `app/Models/ProductVariantBomLine.php` (fillable for the new columns, casts `unit_cost` decimal:2, relations `purchaseOrderItem()`, `vendor()`; `isLegacy()` = `purchase_order_item_id === null`); `app/Models/PurchaseOrderItem.php` (`bomLines()` hasMany); `app/Models/PurchaseOrder.php` (`artist_id` fillable, `artist()` belongsTo `Artist::withTrashed()`); `app/Models/ProductVariant.php` (`bom_complete`/`bom_completed_at`/`bom_completed_by` fillable + casts — note `cost_price` stays fillable).
- [X] T007 [P] Update `database/factories/PurchaseOrderFactory.php` (give POs an `artist_id` by default via `Artist::factory()`, add a `legacy()` state with null artist) and `database/factories/ProductVariantBomLineFactory.php` (keep producing a legacy-shaped row; add a `fromPoLine(PurchaseOrderItem $item)` state that fills the snapshot columns).
- [X] T008 Write `tests/Feature/BomSchemaTest.php`: migrations apply on MySQL; two BOM rows with the SAME material but different PO lines are allowed; the SAME PO line twice for one variant is rejected by the DB; two legacy rows (NULL PO line) of different materials are allowed; a service-style row with NULL `material_id` inserts; deleting a PO line that a BOM row references is refused (restrict).
- [X] T009 Add `scopeEligibleForSeller` (query scope) on `app/Models/PurchaseOrderItem.php`: lines whose PO (through `whereHas('purchaseOrder', …)`, so `HasDataMode` applies) has `artist_id = $artistId` and `status IN (ordered, received, paid)`. Single definition of eligibility — the selector, add and replace-source all use it (research Decision 5).
- [X] T010 Rewrite `app/Services/BomCostCalculator.php` (extend, keep the docblock rationale and the "never writes cost_price" rule where still true): `breakdown(ProductVariant)` returns `lines[]` (PO rows use the snapshot `unit_cost`; legacy rows keep the live vendor-catalogue reference price and `is_legacy: true`), plus `material_cost`, `service_cost`, `bom_cost`, `has_legacy`. All sums in cents via `ReportSplit`. Keep every existing key so `MaterialController::costBreakdown` and `BomCostTest` still pass.
- [X] T011 Create `app/Services/VariantBomService.php` skeleton with the shared internals only (no public workflow yet): `snapshotFrom(PurchaseOrderItem): array`, `assertEligible(ProductVariant, PurchaseOrderItem)` (uses T009; throws `ValidationException` 422 for another seller / ineligible status), `syncCostPriceIfComplete(ProductVariant)` (no-op unless `bom_complete`; sets `cost_price = bom_cost` and logs `cost_price_synced` with old/new), `reopenIfInvalid(ProductVariant): bool`, and a private `log()` wrapper over `ActivityLogger`. Constructor-inject `BomCostCalculator`, `ActivityLogger`. Indonesian docblock stating "the ONLY BOM writer".
- [X] T012 Run the T002 baseline again plus `BomSchemaTest`; all green (checkpoint — stop and fix before continuing).

**Checkpoint**: schema + models + calculator + service internals ready; no behaviour change yet.

---

## Phase 3: User Story 2 — Purchase orders belong to a seller (Priority: P1, prerequisite of US1)

**Goal**: a PO records its seller; list/detail show it; legacy POs are "no seller" and assignable; "Linked Product" leaves the form.

**Independent Test**: create POs for Seller A and B → each shows its seller and the list filters by it; a legacy PO shows "no seller" and can be assigned; the form has no Linked Product.

### Tests for US2 (write first, expect failures)

- [X] T013 [P] [US2] Create `tests/Feature/PurchaseOrderSellerTest.php`: create requires `artist_id` (422 without / unknown); response + list + detail carry `artist_id`/`artist_name` (soft-deleted artist name still resolves); list filter `artist_id`; legacy PO (null) listed with `artist_name: null`; assigning a seller to a legacy PO works and writes `purchase_order_seller_assigned`; changing the seller of a PO whose line is referenced by a BOM row → 409 (insert the BOM row directly with the factory state), allowed when unreferenced (`purchase_order_seller_changed` logged); detail items carry `used_in_bom_count`; an optional `items.*.product_id` is still accepted and stored.
- [X] T014 [P] [US2] Adjust the existing PO tests that create POs through the API (`tests/Feature/PurchaseOrderTest.php`, `PurchaseOrderStatusTransitionTest.php`, `PurchaseOrderStockReceivingTest.php`, `PurchaseOrderPaymentProofRuleTest.php`, `PurchasesReportTest.php`) to send `artist_id` (or use the factory default); do NOT weaken any assertion.

### Implementation for US2

- [X] T015 [US2] `app/Http/Requests/StorePurchaseOrderRequest.php`: `artist_id` required, `exists:artists,id` (not soft-deleted); keep `items.*.product_id` optional. `app/Http/Requests/UpdatePurchaseOrderRequest.php`: `artist_id` sometimes + same exists rule.
- [X] T016 [US2] `app/Services/PurchaseOrderService.php`: `create()` stores `artist_id`; `update()` handles `artist_id` — refuse with `ValidationException` (409 via the controller's existing mapping) when any BOM row references a line of this PO and the value would change; allow assignment on a null seller; write `purchase_order_seller_assigned` / `purchase_order_seller_changed` (old/new) inside the same transaction. Do not touch `items` handling (draft rewrite) except to keep passing through a given `product_id`.
- [X] T017 [US2] `app/Http/Controllers/Api/PurchaseOrderController.php`: eager-load `artist` in `index/show/invoice`; `present()` adds `artist_id`, `artist_name`; items add `used_in_bom_count` (use `withCount('bomLines')` when loading items — remember the `relationLoaded()` trap: load what `present()` reads); `index()` supports `artist_id` filter.
- [X] T018 [P] [US2] Frontend API + locales: `resources/js/api/purchaseOrders.js` pass `artist_id`; add keys under the existing `purchase_orders` section in `en.json`/`id.json`: `seller`, `seller_required`, `no_seller`, `assign_seller`, `seller_changed_blocked` (check existing names first).
- [X] T019 [US2] `resources/js/views/PurchaseOrdersView.vue`: add a required Seller `BaseSelect` (artists list, same loader the other forms use) to the create/edit form; **remove the visible "Linked Product" field** but keep `product_id` in the row state so editing a draft carries an existing value through; add a Seller column and a seller filter to the list; legacy rows show a muted "No seller" label.
- [X] T020 [US2] `resources/js/components/purchaseOrders/PurchaseOrderDetailModal.vue`: show the seller; for a legacy PO (no seller) an "Assign seller" action for roles that can edit POs (inline select + save via `PUT`); show a read-only linked product when a stored one exists; map a 409 on seller change to the localized message.
- [X] T021 [P] [US2] Create `qa-tests/component/PurchaseOrderForm.test.js`: Seller is required (submit blocked/422 shown); no "Linked Product" control rendered; an existing line's `product_id` is still sent when editing a draft; legacy row shows "No seller"; assign-seller flow calls the API.
- [X] T022 [US2] Run `PurchaseOrderSellerTest` + the adjusted PO tests + `PurchaseOrderForm.test.js`; all green.

**Checkpoint**: POs carry a seller; US1 can now filter on it.

---

## Phase 4: User Story 1 — Build a variant's BOM from the seller's PO lines (Priority: P1) 🎯 MVP

**Goal**: the BOM table with Add BOM Item selector, per-row quantity and removal, material/service rows, totals, full trace per row.

**Independent Test**: for a seller with 3 eligible PO lines (2 materials, 1 service) add all three to a variant's BOM; the table shows type, PO, vendor, unit cost, totals Rp 1,800; another seller's lines never appear.

### Tests for US1 (write first)

- [X] T023 [P] [US1] Create `tests/Feature/VariantBomTest.php` — selector: `GET /variants/{v}/bom/eligible-lines` lists only the variant's seller's lines in ordered/received/paid POs (not draft, not cancelled, not legacy, not another seller's, not the other DEMO/LIVE mode); filters `q`, `purchase_order`, `vendor_id`, `line_type`, `date_from/date_to`; `in_bom` flag; pagination envelope; response shape per contract.
- [X] T024 [P] [US1] Same file — add/update/remove: `POST /variants/{v}/bom/items` with several lines (snapshot columns filled from the PO line: `item_name`, `line_type`, `po_number`, `vendor_name`, `unit_cost`; qty default 1); another seller's line → 422; draft/cancelled PO line → 422; unknown line → 422; qty 0 / negative / > 4 decimals → 422; duplicate PO line → 409 (whole request atomic: nothing added); `PUT /bom/{id}` changes qty/notes only and never the cost; `DELETE` removes; service line (no material) accepted; client-sent `unit_cost` is ignored; summary totals (material/service/total) correct for the Rp 500 + 300 + 1,000 example.
- [X] T025 [P] [US1] Same file — snapshot immutability + authorization + audit: after adding, directly `UPDATE` the PO line price and cancel the PO → `GET /variants/{v}/bom` still returns the recorded `unit_cost`; a user with `products` but not `purchase_orders` can `GET` the BOM but gets 403 on eligible-lines/items/PUT/DELETE; cashier 403 on all; every mutation writes `bom_item_added` / `bom_qty_changed` / `bom_item_removed` rows in the same transaction (force a failure mid-batch → no rows, no logs).

### Implementation for US1

- [X] T026 [US1] `app/Services/VariantBomService.php`: `addItems(ProductVariant, array $items, User)` (row-lock the variant, per item `assertEligible`, duplicate check → 409, create rows from `snapshotFrom`, then `syncCostPriceIfComplete`, audit `bom_item_added` per row, all in one `DB::transaction`), `updateQty(ProductVariantBomLine, float $qty, ?string $notes, User)` (qty > 0; audit `bom_qty_changed` old/new; sync), `remove(ProductVariantBomLine, User)` (audit; sync; `reopenIfInvalid`). Cost always comes from the PO line, never from input.
- [X] T027 [US1] Create `app/Http/Controllers/Api/VariantBomController.php` + requests `app/Http/Requests/StoreBomItemsRequest.php` and `EligibleBomLinesRequest`: `eligibleLines`, `index` (BOM payload + `summary`), `storeItems`, and move `update`/`destroy` behaviour to delegate to the service (see T029). Authorization helper in ONE place: reads need `products`; everything else needs `products` AND `purchase_orders` (403 otherwise). Add `po_qty` (reference only) and `in_bom` to the payload; batch-load relations (no N+1).
- [X] T028 [US1] `app/Http/Resources/BomLineResource.php`: new shape per contract (`line_type`, `item_name`, `is_legacy`, snapshot columns, `item_cost`, `po_qty`, keeps `material_id/name/unit` for legacy). Build `summary` from `BomCostCalculator` (T010).
- [X] T029 [US1] `routes/api.php`: register `GET /variants/{variant}/bom/eligible-lines`, `POST /variants/{variant}/bom/items`; point `GET /variants/{variant}/bom`, `PUT /bom/{bomLine}`, `DELETE /bom/{bomLine}` at `VariantBomController`; keep `POST /variants/{variant}/bom` (legacy) in `MaterialController` but route its write through the service/guard in US4/US6. Order the static `…/bom/eligible-lines` before any `{param}` route.
- [X] T030 [US1] Adjust the existing BOM tests that assert the old BOM payload/endpoints (`tests/Feature/MaterialTest.php`, `tests/Feature/BomCostTest.php`) to the new shape — add the new fields, keep every old assertion that still holds; note in a comment why a changed assertion changed.
- [X] T031 [P] [US1] Frontend API `resources/js/api/materials.js`: `eligibleBomLines(variantId, params)`, `addBomItems(variantId, items)`, update `listBomLines` to return `{data, summary}`, keep the cost-breakdown call.
- [X] T032 [US1] Create `resources/js/components/product/AddBomItemModal.vue`: search + filters (PO number, vendor, item text, type, PO date range), paginated table of eligible lines with checkboxes, rows with `in_bom` disabled and labelled, "Add selected (N)", empty state explaining why (no PO lines for this seller) with a link to Purchase Orders; teleported modal built from `BaseModal`/`DataTable`; tokens only; `data-autofocus` on the search field.
- [X] T033 [US1] Rewrite `resources/js/components/product/VariantBomModal.vue` as the compact table (Item, Type, Purchase Order, Vendor, Unit Cost, Quantity, Total Cost, Action) with inline quantity edit (decimal, > 0, saved on blur/enter), Remove (with `ConfirmDialog`), "Add BOM Item" (opens T032), and a totals footer (Material Cost / Service Cost / Total BOM Cost); read-only for roles without edit permission (hide controls, not disable). Keep how `ProductDetailModal.vue` opens it.
- [X] T034 [P] [US1] Locale keys (en + id) under the existing `master_data` section: `bom_table_*` column labels, `add_bom_item`, `bom_total`, `bom_material_cost`, `bom_service_cost`, `bom_in_bom`, `bom_no_eligible_lines`, `bom_qty_invalid`, `bom_remove_confirm`, `bom_add_selected` (check for existing `bom*` names; do not override).
- [X] T035 [P] [US1] Rewrite `qa-tests/component/VariantBomModal.test.js` and create `qa-tests/component/AddBomItemModal.test.js`: table renders the three example rows and Rp 1,800 total; selector filters call the API with the right params; multi-select adds in one call; `in_bom` rows disabled; quantity validation message; read-only variant for a role without edit rights.
- [X] T036 [US1] Run `VariantBomTest`, the adjusted BOM tests, `PurchaseOrderSellerTest`, and the two Vitest files; all green.

**Checkpoint**: US1 + US2 deliver the traceable, seller-filtered BOM end to end. MVP.

---

## Phase 5: User Story 4 — Material/service cost and cost price that follows a complete BOM (Priority: P2)

**Goal**: cost split shown; marking the BOM complete makes the variant's cost price follow the BOM (locked against hand edits); reopening releases it.

**Independent Test**: BOM of Rp 500 + 300 (materials) + 1,000 (service) → 800 / 1,000 / 1,800; mark complete → `cost_price` = 1,800 and a hand edit is refused; change a quantity → cost price follows; reopen → editable with the last value; old sales/reports unchanged.

### Tests for US4

- [X] T037 [P] [US4] Create `tests/Feature/VariantBomCompleteTest.php`: `complete` refused (409 + reason code) for `empty`, `has_legacy`, `invalid_row`; success sets `bom_complete`, `bom_completed_at/by`, syncs `cost_price`, logs `bom_completed` + `cost_price_synced`; add/remove/qty change on a complete BOM re-syncs; removing the last row auto-reopens (`reopened: true`, cost price keeps value); `reopen` clears the flag and keeps the value; `PUT /variants/{v}` with a different `cost_price` → 409 `cost_price_locked_by_bom`, an unchanged value → 200, after reopen the edit works; `ProductVariantResource` exposes `bom_complete`, `bom_cost`, `has_bom`; authorization (both menus).
- [X] T038 [P] [US4] Same file — history & Excel: sell one unit (order item records the OLD cost), complete the BOM at a different cost → the recorded order item cost and `GET /reports/profit` / artist-settlement figures for that sale are unchanged, a new sale uses the new cost; product Excel import row with a different `cost_price` for a complete variant → row error, with unchanged/blank cost → ok; `bom` sheet row for a complete variant → row error; legacy `POST /variants/{v}/bom` on a complete variant → 409.

### Implementation for US4

- [X] T039 [US4] `VariantBomService::complete(ProductVariant, User)` and `reopen(ProductVariant, User)` (row lock, validations returning a typed reason via `ValidationException::status(409)` + `code`, audit, `syncCostPriceIfComplete`); finish `reopenIfInvalid()` (empty BOM → reopen, cost price keeps value). `VariantBomController::complete/reopen`; routes `POST /variants/{variant}/bom/complete|reopen`.
- [X] T040 [US4] Lock in `app/Http/Controllers/Api/ProductController.php::updateVariant` (and `app/Http/Requests/UpdateVariantRequest.php` if cleaner): when `bom_complete` and the validated `cost_price` differs from the stored value (compare in cents) → 409 `{message, code: 'cost_price_locked_by_bom'}`; unchanged value accepted. `app/Http/Resources/ProductVariantResource.php`: add `bom_complete`, `bom_cost` (null when no BOM rows), `has_bom` (batch/`withCount` so product lists stay N+1-free).
- [X] T041 [US4] Excel guards in `app/Services/MasterDataImportService.php`: products sheet — row error when a complete variant's `cost_price` cell differs (blank still means "unchanged"); bom sheet (`validateBom`) — row error for a complete variant; and `MaterialController::storeBomLine` (legacy route) — 409 for a complete variant. Message keys in `lang/*/vendors_materials.php` (check how existing messages are localized).
- [X] T042 [P] [US4] Frontend: `VariantBomModal.vue` — summary card (Material / Service / Total, cost price, margin hint), "Mark BOM complete" / "Reopen BOM" buttons with `ConfirmDialog`, reason messages for the 409 codes, "auto-reopened" notice; `resources/js/api/materials.js` `completeBom`, `reopenBom`. `resources/js/views/ProductsView.vue` — in the variant card: when `bom_complete`, the cost price input is read-only with a "From BOM" badge and a link/button "Open BOM" (saved variants); also show BOM cost beside cost price and margin; map `cost_price_locked_by_bom` through the central error handler. Locale keys (en + id).
- [X] T043 [P] [US4] Vitest: extend `VariantBomModal.test.js` (complete/reopen flow, reasons) and `ProductsView.test.js` (cost price read-only + badge when complete, editable otherwise).
- [X] T044 [US4] Run `VariantBomCompleteTest`, `VariantBomTest`, `ProductTest`, `MasterDataImportVendorMaterialTest`, report suites (`ReportTest|PreorderReportTest|ReportPosPreorderSplitTest`), and the touched Vitest files; all green.

---

## Phase 6: User Story 3 — Copy a BOM to other variants (Priority: P2)

**Goal**: copy from another variant / to the next / to all, independent afterwards; start empty; copy while creating a variant.

**Independent Test**: configure Red, copy to Blue and Green, change Blue only → Red and Green unchanged.

### Tests for US3

- [X] T045 [P] [US3] Create `tests/Feature/VariantBomCopyTest.php`: `copy` mode `from` duplicates rows with snapshots/quantities/costs; `copy-out` `next` (next variant by id; none → 422) and `all` (every other variant of the product); target with rows → 409 `requires_confirmation` + affected variants, `confirm_replace: true` replaces; different product → 422; independence (edit/remove on one target leaves others unchanged); a copy never completes the target and reopens a complete target (cost price keeps value); legacy rows are copied as legacy; `copy_bom_from_variant_id` on `POST /products/{p}/variants` copies into the new variant (other product's variant → 422); audit `bom_copied`; authorization (both menus); DEMO/LIVE isolation.

### Implementation for US3

- [X] T046 [US3] `VariantBomService::copy(ProductVariant $source, array $targets, bool $confirmReplace, User)` — validates same product, confirmation, replaces rows in one transaction per request (all-or-nothing), reopens complete targets, never completes, audits one `bom_copied` per target (source/target ids, row counts); `VariantBomController::copyFrom/copyOut` + `app/Http/Requests/CopyBomRequest.php`; routes `POST /variants/{variant}/bom/copy` and `…/copy-out`.
- [X] T047 [US3] `app/Http/Controllers/Api/ProductController.php::storeVariant` + `app/Http/Requests/StoreVariantRequest.php`: optional `copy_bom_from_variant_id` → after creating the variant, call `VariantBomService::copy` in the same transaction (422 if not the same product).
- [X] T048 [US3] Frontend: create `resources/js/components/product/BomCopyMenu.vue` (Copy to all variants / Copy to next variant / Copy from another variant (select) / start empty is the default) with a confirm dialog listing variants that will be replaced; place it in `VariantBomModal.vue`; `resources/js/api/materials.js` `copyBom`, `copyBomOut`. `ProductsView.vue`: a new (unsaved) variant card gets "BOM: start empty / copy from [saved variant]" and sends `copy_bom_from_variant_id` on save. Locale keys (en + id).
- [X] T049 [P] [US3] Vitest: `BomCopyMenu` (modes, confirmation, disabled when the variant has no BOM / no other variants) and the new-variant select in `ProductsView.test.js`.
- [X] T050 [US3] Run `VariantBomCopyTest` + `VariantBomTest` + `VariantBomCompleteTest` + touched Vitest; all green.

---

## Phase 7: User Story 5 — Historical cost accuracy and auditability (Priority: P2)

**Goal**: no row reprices itself; newer prices and cancelled sources are visible cues; explicit replace; every change audited.

**Independent Test**: row at Rp 500; newer PO at Rp 600 → row stays 500 with a cue; replace → 600 + audit; cancel the source → row keeps cost, flagged.

### Tests for US5

- [X] T051 [P] [US5] Create `tests/Feature/VariantBomHistoryTest.php`: `newer_price` cue (same seller, same material / same normalized service description, newer PO, different price; none when same price, older PO, other seller, draft/cancelled PO), computed with a constant query count regardless of row count; `source_cancelled` when the source PO is cancelled and the row's cost is untouched; `POST /bom/{id}/replace-source` re-snapshots cost/vendor/PO, keeps qty, enforces the same eligibility (other seller → 422), audits `bom_source_replaced` (old/new), re-syncs a complete BOM; a PO that feeds a BOM cannot be deleted; cancelling it changes no BOM cost; complete audit trail present in `activity_logs` for a full scenario (add → qty → replace → copy → complete → reopen) with user, time and old/new.

### Implementation for US5

- [X] T052 [US5] `VariantBomService::replaceSource(ProductVariantBomLine, PurchaseOrderItem, User)`; `VariantBomController::replaceSource` + `app/Http/Requests/ReplaceBomSourceRequest.php`; route `POST /bom/{bomLine}/replace-source`.
- [X] T053 [US5] Cue computation in one place (a method on the service or a small `BomSourceCues` support class used by `BomLineResource`/index): ONE batched query for all rows of the variant yielding `newer_price {purchase_order_item_id, po_number, unit_price}` and `source_cancelled`; match key = `material_id` for materials, lower-trimmed `description` for services; only eligible lines of the variant's seller whose PO is newer than the row's source PO.
- [X] T054 [US5] Frontend: badges "Newer price" (with the new price; click → "Use newer price" confirm → replace) and "Source cancelled" in `VariantBomModal.vue`; `resources/js/api/materials.js` `replaceBomSource`; locale keys (en + id); Vitest cases in `VariantBomModal.test.js`.
- [X] T055 [US5] Run `VariantBomHistoryTest` + all earlier BOM tests + touched Vitest; all green.

---

## Phase 8: User Story 6 — Retire "Linked Product" and carry legacy BOMs forward (Priority: P3)

**Goal**: old BOM lines stay visible, flagged, costed as before, replaceable one by one; Linked Product gone from the form (done in US2).

**Independent Test**: a variant with old-style lines opens with every line present and flagged "Legacy"; replacing one with a PO row works; completion is refused while a legacy row remains.

### Tests for US6

- [X] T056 [P] [US6] Create `tests/Feature/VariantBomLegacyTest.php`: pre-existing legacy rows (factory) appear with `is_legacy: true`, `unit_cost` = the live vendor-catalogue reference price, counted under material cost in the summary and `has_legacy: true`; they block `complete` (reason `has_legacy` naming the rows); `replace-source` on a legacy row turns it into a PO-sourced row (keeps qty; `material_id` kept or null for a service line); the master-data `bom` Excel sheet and the legacy `POST /variants/{v}/bom` still create/update legacy rows for a non-complete variant; the seeder's BOM lines (`SakanaFridgeDemoSeeder`) still seed and read as legacy.

### Implementation for US6

- [X] T057 [US6] Make `VariantBomService::replaceSource` accept legacy rows (they have no source to compare); make the legacy `MaterialController::storeBomLine` / `updateBomLine` / `destroyBomLine` go through the service for audit consistency (`bom_item_*` actions) while keeping their request/response contract (new fields added only); keep the "one legacy row per material" rule in the service.
- [X] T058 [P] [US6] Frontend: "Legacy" badge + "Replace with PO line" action (opens the selector in replace mode, single select) in `VariantBomModal.vue`; `AddBomItemModal.vue` gets a `mode="replace"` prop; locale keys (en + id); Vitest cases.
- [X] T059 [US6] Run `VariantBomLegacyTest`, `MaterialTest`, `MasterDataImportVendorMaterialTest`, `BomCostTest` and the seeder smoke (`APP_ENV=testing php artisan db:seed --class=SakanaFridgeDemoSeeder` on a fresh test DB); all green.

---

## Phase 9: Polish & Cross-cutting

- [X] T060 [P] Update `docs/openapi-pos-mvp.yaml` in the same change (PRD §9.5): PO `artist_id`/`artist_name`/filter/`used_in_bom_count`; the new BOM endpoints (eligible-lines, items, replace-source, copy, copy-out, complete, reopen); the new `GET /variants/{v}/bom` shape + `summary`; `cost_price_locked_by_bom` 409 on `PUT /variants/{v}`; `ProductVariant` fields (`bom_complete`, `bom_cost`, `has_bom`); mark `POST /variants/{v}/bom` as legacy/deprecated.
- [X] T061 [P] Update `CLAUDE.md`: amend the "Vendor, material, and BOM tracking" section (BOM rows now reference PO lines, snapshot cost, legacy rows, `bom_cost` no longer "never writes cost_price" for COMPLETE BOMs — say exactly when it does) and add a "BOM from purchase orders (feature 034)" section (single writer, eligibility = seller + ordered/received/paid, cues at read time, lock + sync rules, copy rules, authorization needing both menus). Keep the Active-feature block already written.
- [X] T062 [P] Add a README "bugs found during execution" entry only if implementation reveals a real defect; otherwise skip.
- [X] T063 Full verification: `APP_ENV=testing php artisan test` (whole suite, host) and `npm test`; both green; report any pre-existing failure separately.
- [X] T064 Real-browser verification per `quickstart.md` on an ISOLATED server + `boothpos_test` (seed per quickstart step 1: two sellers, three variants, vendors, ordered/draft/cancelled/legacy POs, one old-style BOM line): PO form/list/detail seller flows; BOM table, selector filters, multi-add, quantity edit, remove; copy from/next/all with confirmation and independence; complete → read-only cost price "From BOM" → follows quantity changes → reopen; legacy badge + blocked completion + replace; newer-price and source-cancelled cues; cashier cannot see BOM/PO costs; EN ↔ ID; console clean. Screenshots (no customer data) to `specs/034-seller-po-bom/evidence/`. **Done 2026-10-05** on :8091 + `boothpos_test`: PO list/form/detail seller flows (incl. assigning a seller to a legacy PO), selector filtering (Seller A vs B), multi-add, qty edit, copy to next + confirmation, complete → locked cost price (UI + 409), reopen via last-row removal, newer-price and source-cancelled cues + explicit replace, legacy flag/blocked completion/replace, copy-on-create variant, cashier 403 on every BOM/PO endpoint, EN↔ID. Console: only deliberate 4xx probe entries. The browser found 2 UI defects (PO draft edit showing no lines — pre-existing; wrong pre-completion hint) — both fixed with regression tests, see README.
- [X] T065 Mark every completed task `[X]`, clean scratch seed scripts and `.playwright-mcp` copies, and prepare the commits (Indonesian messages: docs commit for the spec set, then the feature commit) — commit/PR only on the user's request.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T003–T005 migrations first; T006/T007 parallel after them; T008 after T006; T009–T011 after T006; T012 last) → user stories.
- **US2 (T013–T022)** needs Phase 2. **US1 (T023–T036)** needs US2 (selector filters on the PO seller) and Phase 2. **US4 (T037–T044)** needs US1's rows and service. **US3 (T045–T050)** needs US1 (rows to copy) and uses `reopenIfInvalid`/sync from Phase 2/US4 (a complete target is reopened — build US4 first if you want the complete-target copy test green; otherwise write that one assertion in T045 against the flag directly). **US5 (T051–T055)** needs US1. **US6 (T056–T059)** needs US1 + US5's `replaceSource`.
- Recommended order: US2 → US1 → US4 → US3 → US5 → US6 → Polish.
- Files shared by several stories (`VariantBomService.php`, `VariantBomController.php`, `routes/api.php`, `VariantBomModal.vue`, `ProductsView.vue`, `materials.js`, locales) are edited sequentially in that order; do not parallelize across stories.

### Within a story

Tests first (fail) → migration/model/service → controller/routes → frontend → run the story's tests.

### Parallel opportunities

- T006 ∥ T007; T013 ∥ T014; T018 ∥ T021; T023 ∥ T024 ∥ T025 (one test file, written in one sitting); T031 ∥ T034 ∥ T035; T037 ∥ T038; T042 ∥ T043; T045 ∥ T049; T060 ∥ T061 ∥ T062.

## Implementation Strategy

- **MVP = Phases 1–4 (US2 + US1)**: a seller-filtered, traceable, PO-sourced BOM with totals — usable on its own (cost price stays manual).
- Then US4 (cost price follows complete BOM — the one change that touches `cost_price`), US3 (copy), US5 (cues + replace), US6 (legacy polish), Polish.
- Stop at every checkpoint and run that phase's tests plus the baseline set; never loosen an existing assertion to get green — adjust only where the contract deliberately changed and say why in a comment.

## Notes

- Every task names its file(s); if a task reveals a conflict with `docs/` or with an unchanged-figures guarantee (SC-009), stop and surface it.
- The old unique key `(variant, material)` is intentionally replaced; the legacy "one row per material" rule lives in the service only.
