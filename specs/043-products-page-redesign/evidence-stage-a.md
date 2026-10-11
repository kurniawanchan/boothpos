**Classification**: INTERNAL

# Evidence — 043 Stage A (single outlet)

Date: 2026-10-11. Dev DB `warkop_wjm` (DEMO data, owner account). Backup taken before migrating: `storage/app/backups/2026-10-11_092245` (inside the `app` container).

## Backend (automated)

| Area | Tests | Result |
|---|---|---|
| Availability / price / lifecycle / permissions / settings / reason codes | see `tests/Feature/*Availability*, PriceService, ProductLifecycle*, PermissionCatalog, MenuSettings, ReasonCode` | pass |
| Catalogue, summary, detail, facts, sold-out, discontinue | `ProductCatalog, ProductSummary, ProductDetail, ProductFacts, ProductSoldOut, MenuAvailabilityPoll` | pass |
| Bulk price, variants batch, release engine, approvals | `BulkPrice, VariantBatch, ReleaseService, MenuReleaseApi, ApprovalService, ApprovalApi` | pass |
| Modifier links (US7) | `ModifierLinkTest` (9) + `ProductDetailTest`, `TabModifiersTest`, `ModifierGroups*` | pass |
| Recipes (US8) | `RecipeCostTest` (4, hand calculation 35 000 → 17 500 → 35.0 %), `RecipeApiTest` (14) | pass |
| Import/export new columns (T022) | `ProductImportNewColumnsTest` + `MasterDataImport*`, `MasterDataExport*` | pass |
| Regression sweep (BOM, import, orders, tabs, variants, catalogue) | filter run, 550 tests | pass |
| Full backend suite (`php artisan test`, test DB, 2026-10-11) | 1663 tests, 8052 assertions | pass, 0 failed |

Security review finding on `ApprovalController::approve/reject` (permission only enforced in the service): not exploitable (`ApprovalService::lockPending()` checks `menu.price.approve` before any write; `ApprovalApiTest::test_a_user_without_the_permission_gets_403_everywhere`), but the controller now checks it too (defense in depth).

## Frontend (automated)

`npm test` (re-run by the lead on the final state): 165 files, 1554 passed, 2 skipped, 0 failed. `npm run build` green (lead re-run). Main chunk was 435.22 kB (+22.4 kB over the budget, mostly bundled locale text). **Resolved 2026-10-11 (owner: lazy-load)**: locale JSON became separate chunks loaded on demand → main chunk **239.61 kB** (gzip 86.99 kB), `id` chunk 99 kB / `en` chunk 98 kB (each ~30 kB gzip, `en` only fetched for users who use it). Full `npm test` after the change: 166 files, 1558 passed, 2 skipped. A hard reload as owner in English renders correctly with a clean console.

## Browser walk-through (real browser, owner, 2026-10-11) — PARTIAL

Verified:
- Cashier (`kasir01`) opening `/products` is redirected to the dashboard (menu guard). 
- `/products` grid: three figures (releases off), item-type tabs with counts (9 menu items, 8 raw materials), search/category/status filters, Grid/Table toggle, "Bulk price change", "Add item", cards with status/availability. Console: 0 errors. Screenshot `evidence/evidence-043-products-grid.png`.
- `/products/:id`: header actions, facts strip, variants & price, modifier groups, availability, recipe + versions, price history. Console clean. `evidence/evidence-043-item-page.png`.
- Mark 86: reason required → stored with expiry at the end of the Jakarta business day (17:00 UTC) → undo works.
- Variants drawer: price 35 000 → 36 000 saved immediately (releases off), toast, history timeline row, page refreshed. `evidence/evidence-043-after-price-save.png`.
- Recipe drawer opens with the versioning sentence, "does not deduct ingredient stock", variants, ingredient groups by kind, "Save as v1". `evidence/evidence-043-recipe-drawer.png`.

NOT verified in a browser (tasks T036, T041, T048, T053, T058, T063, T069, T080 stay open): Table view and every filter; both languages; empty install; POS greying out within 5 s and an order refused for an 86 item; discontinue/reactivate; bulk price drawer and preview; release/approval flows with the setting ON; modifier-links drawer; creating a recipe (version + cost sync); roles pass (owner/admin/inventory/kasir01).

## Findings from the walk-through

1. ~~Price-history row said "Proposed by" for an immediate edit~~ — fixed 2026-10-11: "Diajukan/Proposed by" only when the entry went through approval or a release, otherwise "Diubah oleh/Changed by".
2. Availability section heading reads "Availability per outlet" on a single-outlet install (row says "Whole installation") — acceptable, revisit with Stage B.
3. ~~Old "Nonaktifkan" list action overlapped with "Discontinue"~~ — removed 2026-10-11 (owner decision); its table test now asserts Edit is the only row action.

## Open items for Stage A

- Browser checks listed above; performance pass (T082) on an unloaded machine.
- Stage B (T085–T095) is blocked on the 042 constitution amendment (042 T001) and 042 Phase 7.
