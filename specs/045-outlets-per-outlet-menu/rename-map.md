**Classification**: INTERNAL

# Rename map — Seller/Artist → Outlet (045, Stage 1)

Source of truth for the codemods (tests, fixtures, seeders, frontend) and the locale-key rename tool. Counts are from the repository on 2026-10-11 (`grep`, backend: 226 `artist`, 156 `artist_id`, 83 `artists`, 80 `seller` …; frontend: 43 `artists`, 29 `artist_id`, 27 `artist` …; locale keys/values touching seller/artist: 70 in en, 96 in id).

## 1. The decisive rule: rename by MEANING, not by string

| Where the old `artist_id` / "seller" appears | Meaning | New name |
|---|---|---|
| `products.artist_id`, product forms/filters, `ProductResource.artist_id/artist_name`, product code prefix | the item's **home outlet** | `home_outlet_id`, `home_outlet_name`, `home_outlet_code` |
| `order_items.artist_id`, `preorder_items.artist_id`, `OrderItem::artist`, line `artist_name`, `tx.artist_names` | immutable snapshot of the item's home outlet at sale time | `home_outlet_id`, `home_outlet_name(s)` |
| `purchase_orders.artist_id`, PO "seller" (`purchase_orders.seller`, `col_seller`, `no_seller`, `sellersFor`, `eligibleForSeller`, `purchase_order_seller_*`) | the **buying outlet** | `outlet_id` / "outlet" (`eligibleForOutlet`, `purchase_order_outlet_*`); BOM rule unchanged: PO outlet = product home outlet |
| `session_opening_cash_entries.artist_id`, `useOpeningCashPerArtist` | opening cash per outlet (legacy per-seller rows keep their value) | `outlet_id`, `useOpeningCashPerOutlet` |
| `artist_settlements.artist_id` | the **report outlet** of a settlement row | table `outlet_settlements`, `outlet_id` |
| report filters/groupings (`stockByArtist`, `artistProfit`, `artistSettlements`, `preordersByArtist`, `PreorderSellerSubtotals`, `bySeller`, `by_seller`) | until Stage 7: grouped by the item's home outlet (numbers unchanged); from Stage 7: by the **selling** outlet | `stockByOutlet`, `outletProfit`, `outletSettlements`, `preordersByOutlet`, `PreorderOutletSubtotals`, `byOutlet`, `by_outlet` |
| `artists` master record itself, `ArtistController/Policy/Request/Resource/Factory`, `listArtists/createArtist/updateArtist/deleteArtist`, `ArtistsView`, `api/artists.js`, menu key `artists` | the outlet | `Outlet*`, `listOutlets/createOutlet/updateOutlet/deleteOutlet`, `OutletsView`, `api/outlets.js`, `outlets` |
| `multi_artist_enabled`, `multiArtistEnabled`, `artistLimitReached`, `artist_limit_reached`, `activeArtistCount`, `enforceArtistLicenseQuota`, `canCreateArtist`, `artist_license_quota_exceeded`, `artist_count` | licence tier (one vs several outlets) | `multi_outlet_enabled`, `multiOutletEnabled`, `outletLimitReached`, `outlet_limit_reached`, `activeOutletCount`, `enforceOutletLicenseQuota`, `canCreateOutlet`, `outlet_license_quota_exceeded`, `outlet_count` |

**Never** rename mechanically `artist_id` → `outlet_id` everywhere: a line-level `artist_id` on an item/product/preorder item is the HOME outlet; only operational columns introduced in Stage 2 (`orders.outlet_id` …) mean the selling outlet.

## 2. Files and classes

| Old | New |
|---|---|
| `app/Models/Artist.php`, `ArtistSettlement.php` | `Outlet.php`, `OutletSettlement.php` |
| `app/Policies/ArtistPolicy.php` | `OutletPolicy.php` |
| `app/Http/Controllers/Api/ArtistController.php` | `OutletController.php` |
| `app/Http/Requests/{Store,Update}ArtistRequest.php` | `{Store,Update}OutletRequest.php` |
| `app/Http/Resources/ArtistResource.php` | `OutletResource.php` |
| `database/factories/ArtistFactory.php` | `OutletFactory.php` |
| `app/Support/PreorderSellerSubtotals.php` (+ `tests/Unit/PreorderSellerSubtotalsTest.php`) | `PreorderOutletSubtotals.php` |
| `resources/js/api/artists.js`, `views/ArtistsView.vue` | `outlets.js`, `OutletsView.vue` |
| `views/reports/{StockByArtistTab,ArtistProfitTab}.vue`, `components/report/{ArtistTransactionsModal,StockByArtistDetailModal}.vue` | `StockByOutletTab`, `OutletProfitTab`, `OutletTransactionsModal`, `StockByOutletDetailModal` |
| tests `ArtistTest`, `ArtistDeleteGuardTest`, `StockByArtistReportTest`, `SellerRecapPosOnlyTest`, `PurchaseOrderSellerTest` | `OutletTest`, `OutletDeleteGuardTest`, `StockByOutletReportTest`, `OutletRecapTest` (Stage 7), `PurchaseOrderOutletTest` |

## 3. Routes, params, fields (see contracts/api-outlets-rename.md for the full table)

`/artists` → `/outlets`; `/reports/{stock-by-artist,artist-profit,artist-settlements[/{artist}/transactions]}` → `{stock-by-outlet,outlet-profit,outlet-settlements[/{outlet}/transactions]}`; `artist_id[]` → `home_outlet_id[]` (catalogue) | `outlet_id[]` (sales/reports); `artist_name(s)` → `home_outlet_name(s)` on items, `outlet_name` on report rows; `artist_code` → `home_outlet_code` (old accepted on Excel import); `split by_seller` → `by_outlet`; Excel sheet `artists` → `outlets`; menu key `artists` → `outlets`; setting `multi_artist_enabled` → `multi_outlet_enabled`; page route name `artists` → `outlets` with `/artists` redirect.

## 4. Language-specific wording (locale values, both files identical key sets)

| Old (en / id) | New (en / id) |
|---|---|
| Sellers / Penjual | Outlets / Outlet |
| Seller / Penjual (single) | Outlet / Outlet |
| Owner seller | Home outlet / Outlet asal |
| Seller recap / Rekap Penjual | Outlet recap / Rekap Outlet |
| Seller cost / Biaya Penjual | Outlet cost / Biaya Outlet |
| Merchandise owners consigning their goods | Locations that sell the menu / Lokasi yang menjual menu |
| Add seller / Tambah penjual | Add outlet / Tambah outlet |
| Search seller name… | Search outlet name… / Cari nama outlet… |

Key families to rename (examples): `nav.artists(_subtitle)`, `dashboard.{results_per_artist,no_active_artists,sales_by_artist}`, `master_data.{search_artist*,add_artist,artist_*,no_artists,pro_license_one_artist,owner_artist}`, `reports.{col_artist,all_sellers,…}`, `purchase_orders.{seller,col_seller,seller_placeholder,no_seller}` and the matching `lang/*/*.php` keys (`artist_delete_has_active_products`, `artist_code_permanent`, `artist_license_quota_exceeded`, `artist_limit_reached`, `artist_not_authorized`, `split_single_seller`, `purchase_order_seller_*`).

## 4b. Related words that must NOT be mechanically renamed

- "Vendor" (suppliers) stays; "customer" stays; "owner" the ROLE stays (only "owner seller" the product field changes).
- Strings inside shipped migrations, `specs/001…044`, `docs/PRD…` historical sections and `README.md` narrative stay as written (history); the PRD/CLAUDE.md get dated notes instead.
- Test method names that describe past bugs may keep the old word only inside comments that cite history.

## 5. Intentional exclusions from the "no seller wording" check (T-sweep)

`specs/**` (except 045), `database/migrations/<shipped before 2026_12>`, `docs/PRD-POS-Event-Multivendor.md` historical text, `README.md`, `.specify/**`, git history, license-key vendor wording ("vendor-signed" refers to the software vendor, not a seller).

## 6. Order of mechanical work

1. Backend classes/migrations (Stage 1 tasks) → 2. tests/fixtures/seeders codemod (reviewed dry run) → 3. locale key rename tool (reviewed mapping) → 4. frontend sweep by area → 5. wording sweep test.
