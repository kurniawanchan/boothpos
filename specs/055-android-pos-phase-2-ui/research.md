# Research: Android POS Phase 2 (055)

**Verified** = read in code this session. **ASSUMPTION** = not yet confirmed; labelled.

## R1. Where the category list comes from

- **Decision**: a new read-only endpoint `GET /pos/categories` returns the active categories of the current system mode, with a product count per category. The tablet uses it as the only category source.
- **Verified root cause**: `MenuMapper.toCatalog` builds categories from `p.categoryId`/`p.categoryName` of the products it receives (`MenuMapper.kt:22–29`). A category with no sellable product in the active mode never appears. The DEMO seed has 5 categories with products (`WarkopMenuDemoSeeder`); the web admin's Categories list is the category table itself (8 rows in the reference screenshot).
- **Verified existing endpoint**: `GET /categories` (`CategoryController::index`) is a `viewAny` policy gate on `Category`, which cashiers may not hold, and it is paginated with admin fields. The tablet should not depend on a manager-level permission to sell.
- **Alternatives rejected**: (a) add `categories` to `GET /products?pos=1` — the product endpoint is paged, so the list would repeat per page; (b) make `GET /categories` cashier-readable — widens an admin endpoint's access; (c) keep deriving on the tablet with an "include empty" flag — still depends on product data and misses categories with no products.

## R2. System mode

- **Decision**: no mode parameter. `Category` and `Product` use `HasDataMode`, so the global scope filters by `ModeGate::current()` on every query. The endpoint inherits the mode automatically.
- **Verified**: `Category` and `Product` both `use HasDataMode` (`app/Models/Category.php:16`, `app/Models/Product.php:15`).
- **Consequence**: switching DEMO/LIVE on the server and pulling to refresh on the tablet shows the other mode's categories and products. The 3 categories absent from DEMO (for example Gantungan Kunci, Pin Emamel, Standee Akrilik in the admin reference) appear in the mode that holds them.
- **ASSUMPTION**: the 8-category admin list was taken in LIVE mode. Confirm by reading `GET /pos/categories` once in each mode (quickstart).

## R3. Product names

- **Verified**: names come from `GET /products?pos=1`; the DEMO names (Affogato, Americano, …) are in `WarkopMenuDemoSeeder`. `PosCatalog.products` is unused. No product-source change needed.
- **Decision**: keep `GET /products?pos=1`; delete nothing else. Remove the unused `PosCatalog.products`/`categories` only if a later cleanup asks (out of this plan's scope).

## R4. Two-column counter and category strip

- **Verified**: `EditorScreen.kt:52` shows a vertical `CategoryRail` (fixed width, 52 dp chips in a `Column` with no scroll) only on `EXPANDED`/`LARGE`; the horizontal `FilterChip` row exists only on `COMPACT`/`MEDIUM` (line 54–57). On the tablet the rail is clipped, which explains "only a few" categories.
- **Decision**: one horizontal `LazyRow` strip above the product grid on every size, scrollable sideways, with full category names (no truncation). Products fill the left column (`weight(1f)`), the order list the right column (fixed fraction, about 40 % on LARGE).
- **Portrait**: the order list stacks below the grid on COMPACT/MEDIUM, keeping Kirim/Bayar reachable (spec FR-002).
- **ASSUMPTION**: exact column fraction is a visual choice; confirmed on the tablet.

## R5. Totals show/hide

- **Decision**: one `TotalsPreference` (shared preferences, key `counter_totals_visible`, default true). The area collapses to a compact bar with a single control to show it again.
- **Rationale**: a device-local display choice; not a secret, so not in `TokenVault`; not a server setting (spec: per device).
- **Alternatives rejected**: server setting (would change every tablet); in-memory only (lost on restart, spec says remembered).

## R6. Kirim and Bayar labels

- **Verified**: `EditorScreen.kt:193–194`: Kirim label is `"Kirim ${o.unsent.size}"` and Bayar carries `trailing = rp(o.remaining)`; both pills share the row by weight, so the label is cropped.
- **Decision**: Kirim shows the word "Kirim" with the count as a badge; Bayar shows only "Bayar" (no amount, spec FR-010). Label text fits with the existing `ChipFit.chooseFontSize` (054); shrink or wrap inside the button, never crop (FR-009). Use `maxLines = 1` and the fitted size.
- **DECIDED (2026-10-18, product owner)**: the count badge on Kirim is kept (it is information, not a price).

## R7. Tax line from the server

- **Verified**: `EditorScreen.kt:188` hard-codes `"Pajak 10%"`. The receipt already uses server values (`Sheets.kt:624`: `r.taxLabel`, `r.taxRatePercent`). `GET /settings/features` exposes `tax_rate` and `tax_label` to every role (`SettingsController::features`).
- **Decision**: read `tax_label` and `tax_rate` once with the counter's settings load and show `"${taxLabel} ${taxRatePercent}%"`. No constant on the tablet.
- **ASSUMPTION**: the counter's order payload (`o.tax`) is already computed by the server with the same rate; the label is display only.

## R8. Pull to refresh

- **Decision**: Material 3 `PullToRefreshBox` wrapping each server-backed screen body, with one shared wrapper in `ui/Common.kt` so the behaviour is written once (constitution I).
- **Refresh action per screen**: counter → `loadMenu()` (categories + products) + settings; Meja → floor reload; Order → orders reload; Shift → shift report reload; Perangkat → device/printer status reload.
- **Failure rule**: a failed refresh keeps the last loaded data and shows the offline message (spec FR-018); the current order selection and typed payment amount are not reset (FR-019).
- **ASSUMPTION**: `PullToRefreshBox` is available in material3 1.3.0 under the project's opt-in annotation. Verified at compile time (T-task); if not, the fallback is `Modifier.pullToRefresh` from `material3` / a small custom nested-scroll wrapper, recorded then.

## Open items

- R2 confirmation: read `GET /pos/categories` in DEMO and LIVE (quickstart).
- R8 compile check of `PullToRefreshBox` at BOM 2024.09.00.
- Tablet visual check (column fraction, label fit, portrait layout).
