**Classification**: INTERNAL

# Evidence — Stage 3 (per-outlet menu) — DATA LAYER + FIRST API/UI SLICE

**Date**: 2026-10-12. Nothing committed or pushed. Data layer first, then the first API/UI slice (below); the Stage 3 gate is **not** closed.

## Built

- Migration `2026_12_03_000001`: `outlet_item_settings` (unique outlet × product, `is_carried`, `data_mode`) and `variant_outlet_prices` (unique variant × outlet, `price` ≥ 0 with a database CHECK). Every (outlet, product) pair in the same data mode is back-filled as carried. Re-runnable; `down()` provided.
- Models `OutletItemSetting`, `VariantOutletPrice` (both `HasDataMode`).
- `OutletMenuService`: materialise rows for a product / an outlet (hooked in `AppServiceProvider`, so controllers, imports, seeders and factories are all covered), `setCarried` (all-or-nothing), `isCarried` (a missing row counts as carried), `carriedOutletIds`.
- `PriceService::priceFor($variant, $outletId)` (single resolver) and `setOutletPrice(...)` (the only writer of `variant_outlet_prices`): `outlet_id` history row, audit entry, no-op on equal price, `null` removes the override, negative refused (service and database).

## Verification

| Check | Result |
|---|---|
| New backend tests | `OutletItemSettingsTest` 6, `VariantOutletPricesTest` 7 (13 pass) |
| Backend full suite | **1738 passed, 1 skipped, exit 0** |
| Dev DB | backup `storage/app/backups/2026-10-12_024715` first; migration applied; DEMO: 3 outlets × 9 products = 27 rows, all carried; LIVE: 0; 0 outlet prices |
| `outlets:reconcile` (dev) | zero differences, exit 0 |

## Second slice: product API and drawer

- `outlet_ids` on `POST /products` and `PUT /products/{id}`: the outlets that carry the item. Every id must be an outlet the user can see (422 otherwise); only those outlets are changed, the rest keep their state; without `outlet_ids` nothing changes (create default: every outlet carries). `show`/`store`/`update` return `outlets[]` ({id, code, name, carried}) only when several outlets are visible.
- `GET /variants/{variant}/outlet-prices` and `PUT /variants/{variant}/outlet-prices/{outlet}` (price or `null`) through `PriceService`; 403 `outlet_forbidden` outside the permitted set, 422 for negative prices, cashiers refused.
- `ProductFormDrawer`: "Active at outlets" checkboxes (all checked on create, prefilled on edit, hidden at one outlet, `outlet_ids` only sent when several outlets are visible). New locale keys through `merge_locale.py`.
- OpenAPI updated (190 paths).

| Check (final code of this slice) | Result |
|---|---|
| New tests | backend `ProductOutletsApiTest` 5, `VariantOutletPricesTest` now 10; frontend `ProductFormOutlets` 3 |
| Backend full suite | **1746 passed, 1 skipped, exit 0** |
| Frontend full suite + build | **181 files, 1718 passed, 2 skipped, exit 0; build passes** |

## Third slice: per-outlet 86, carried check, outlet prices at the till

- **86 per outlet**: a mark with `outlet_id` NULL is global (legacy and single-outlet behaviour unchanged); a mark with an outlet applies only there. Rule: `ItemAvailabilityMark::appliesTo($outletId)`. This replaces the planned "expand every existing mark per outlet + NOT NULL" with an equivalent rule that needs no data rewrite (deviation, documented here).
  - `POST /products/{id}/sold-out`: `outlet_ids` (permitted outlets only; `[]` → 422) → one mark per outlet; without it the **selected** outlet (`X-Outlet-Id`) is used, else a global mark. 409 when an active mark already applies to a target. `DELETE` takes the same `outlet_ids` (403 outside the permitted set) and clears only those marks.
  - `GET /menu/availability` returns marks for the effective outlets (global + those outlets) with each mark's `outlet_id`.
- **Not carried here**: orders and tabs now refuse an item the shift's/tab's outlet does not carry (`unavailable_not_carried`), alongside the sold-out and inactive reasons (one resolver, `MenuAvailability`).
- **Outlet price at the till**: `OrderService` and `TabService` price from `PriceService::applyOutletPrice` (in-memory variant carries the outlet price, original synced so a later save never writes it back). The base `sell_price` is never changed by selling. Variants expose a separate `outlet_price` (single effective outlet only, one request-scoped query via `OutletPriceBook`); POS cards show `outlet_price ?? sell_price`; the server always charges the shift outlet's price.

| Check | Result |
|---|---|
| New tests | `OutletAvailabilityTest` 8; `VariantOutletPricesTest` now 13 (orders, tabs, `outlet_price`); POS `shownPrice` unit test |
| Backend full suite | **1757 passed, 1 skipped, exit 0** |
| Frontend full suite | 3 timeouts under load (PreordersView 2, StockView 1; 60 s) — both files **pass in isolation (71 tests)**; build passes |

## Fourth slice: the remaining Stage 3 UI (list, item page, variant prices, bulk price)

- **List (T057/T059)**: `GET /products?tab=…` returns one availability entry per visible outlet (`available|sold_out|not_carried`) and accepts `outlet_id[]`; `ProductsView` sends the selected outlet; `AvailabilityChips` collapses above 4 outlets. Single outlet = legacy shape.
- **Item page (T061)**: `ProductDetailService` returns one availability row per visible outlet (86 note per outlet, "not carried" row) and `outlet_prices` (`outlets[]`, `by_variant` overrides only; `null` at one outlet). `AvailabilitySection` shows outlet code + name.
- **Variant prices (T060)**: `VariantsSection` adds one price column per outlet (grey = follows base); inline edit on blur (empty clears the override) only for editors and only while menu releases are off (hidden, not disabled); horizontal scroll comes from `SectionTable`.
- **Bulk price (T051/T062)**: `price_scope = base|outlets|all_outlets` + `outlet_ids[]` on preview and apply. Old price = effective price at the outlet; result written as an outlet override via `PriceService::setOutletPrice`; items not carried at an outlet are skipped; outlets outside the caller's permitted set → 422; refused (422) while releases are on; default scope unchanged. Drawer shows the scope section only with several outlets and no release mode, and the outlet code on each preview row.
- OpenAPI updated (`outlet_id[]`, per-outlet availability, detail `availability`/`outlet_prices`, bulk scope); 190 paths valid.

| Check | Result |
|---|---|
| New backend tests | `ProductsOutletsListTest` 5, `ProductDetailOutletsTest` 4, `BulkPriceOutletsTest` 6 |
| New frontend tests | `ProductsViewOutlets` 3, `AvailabilitySection` 2, `VariantsSectionOutlets` 4, `BulkPriceDrawerOutlets` 4 |
| Backend full suite | **1772 passed, 1 skipped, exit 0** (8416 assertions) |
| Frontend full suite + build | **185 files, 1734 passed, 2 skipped, exit 0; build passes** |

## Fifth slice: Stage 3 completion (and Stage 2 leftovers)

- **86 dialog**: `SoldOutDialog` shows outlet checkboxes with several outlets (default = selected outlet, else all) and sends `outlet_ids`; undo on the item page clears exactly the outlets currently sold out. `ReasonDialog` gained an `extra` slot + `extraValid` prop.
- **Excel**: sheets `outlet_items` (`outlet_code`, `product_code` = `code_prefix`, `is_carried`) and `outlet_prices` (`sku`, `outlet_code`, `price` or the word `base` to clear) in `MasterDataSheets` (export, template, import; all-or-nothing; per-row errors; outlet must be in the uploader's permitted set; SKUs/products made by the same file resolve at apply). The shipped template still imports as-is (test extended). Export route accepts both entities.
- **Release/approval path for outlet prices (T048)**: `PriceService::setOutletPrice` queues a `menu_change` (payload `outlet_id`, `outlet_code`, `new_override`) while releases are on; threshold → approval; `PriceChangeApplier` writes it via `writeOutletPrice` on publish (history source `release`, `change_id`, approver). Bulk price and Excel import inherit it (the earlier "refused while releases are on" guards were removed). Change/approval/release lists carry `outlet_code`; the UI appends `@ WJ2`.
- **Price history** names the outlet (and hides outlets the caller cannot see).
- **Isolation matrix** grew product/menu cases (list/detail never name an unseen outlet; 86/price at a foreign outlet is refused; price listing; poll).
- **OutletScopeRegistry (T036/T033)**: one registry of scoped models and raw-query files (`filtered` or `exempt: reason`); a test scans `app/` and fails on an unregistered raw query to an outlet table. `outlets:reconcile` gained Stage 3 checks (every outlet × product has a setting row; no orphan outlet price).
- **Stage 2 leftovers**: users list **Outlet** column; outlet on the shift header (`session.outlet`) and on the receipt (`outlet_name`, printed only with several outlets); board headings "Outlet — N dari M meja terisi" computed in `floorBoard.js` (`outletSections`); dashboard scope line (+ org-scope note on the stock card when an outlet is selected); `all_outlets` now settable on roles (only by a user who sees all outlets; Owner/Admin locked on; audit `role_outlet_scope_changed`; checkbox on the role form).

| Check | Result |
|---|---|
| New backend tests | `OutletExcelSheetsTest` 7, `OutletPriceReleaseTest` 5, `ProductDetailOutletsTest` 4, `BulkPriceOutletsTest` 7, `OutletScopeRegistryTest` 3, `ShiftOutletPayloadTest` 1, isolation +4, role/assignment +3, reconcile +2 |
| New frontend tests | `SoldOutDialogOutlets` 3, `RolesViewOutlets` 4, plus Users/Receipt/Session/Dashboard/FloorBoard additions |
| Backend full suite | **1798 passed, 1 skipped, exit 0** (8546 assertions) |
| Frontend full suite + build | **187 files, 1749 passed, 2 skipped after one fix (productsApi undo call shape); build passes** |
| Dev DB | no migration; API smoke as `owner`: list chips (3 outlets), detail rows + `outlet_prices`, outlet price set/clear, 86 at one outlet then undo (only that outlet changed), forged `X-Outlet-Id` → 403, both Excel exports 200; test data reverted; `outlets:reconcile` OK with Stage 2+3 checks |
| `rehearse.sh` | re-run on the current dev copy: OK (migrate is a no-op there) |

## Deviations / still open

- **Browser evidence (T046/T064) NOT recorded**: the Playwright browser profile is held by a Chrome process that has been running for over a day (not started by this session); I did not kill it. Needs: selector per role, shift per outlet, floor/board per outlet, chips/filter, add item carried at two outlets, per-outlet price edit, 86 per outlet, bulk scope, Excel round trip, both languages, console clean. API smoke above covers the server side only.
- Per-outlet prices are edited **inline on the item page** (and via bulk/Excel), not as columns in the create/edit drawer; `VariantsDrawer` batch (`outlet_prices` in `PUT /products/{id}/variants`) was not built.
- Opening cash: a new shift does not auto-create an opening-cash entry; the opening cash is stored on the shift, which is per outlet by construction.
- T034 (automated backfill test) infeasible in the test DB; backfill evidenced on dev.
- Pre-orders still price from the base `sell_price`; the POS does not hide items "not carried" (server refuses them).
- **Pre-existing finding (not 045)**: dumps made by `app:backup` (MariaDB client) list the generated column `orders.open_table_key` in `INSERT INTO orders VALUES`, so restoring such a file into MySQL 8 fails with error 3105 once orders exist. A full-chain rehearsal from the oldest backup was therefore not possible. Worth a separate fix (`--complete-insert`/skip generated columns).
- Recipe effectivity (UTC `today()`) vs price changes (business day) mismatch from Stage 1 is still unresolved (owner decision).
