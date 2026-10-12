**Classification**: INTERNAL

# Evidence — Stage 1 (rename seller → outlet, behaviour unchanged)

**Date**: 2026-10-12 (runs on the 2026-10-11 evening session). **Branch**: `045-outlets-per-outlet-menu`. Nothing committed or pushed.

## Gate criteria (tasks.md, Stage DONE rule)

| Criterion | Result | Evidence |
|---|---|---|
| Stage tests pass | Backend: see "Backend suite" below. Frontend: pass | `vitest run` 174 files, 1696 passed, 2 skipped |
| Full backend + frontend suites green | Backend: see "Backend suite". Frontend: green | — |
| `outlets:reconcile` zero differences | **Pass** (exit 0) | before/after/compare on dev DB, see "Reconcile" |
| Browser check recorded | **Partial** — see "Browser check" | — |
| Dev DB has no leftover test data | **Pass** — tests run only with `APP_ENV=testing` + `DB_DATABASE=warkop_wjm_test` | command used for every backend run |

## Backup and migration (dev DB)

- `php artisan app:backup` before migrating → `storage/app/backups/2026-10-11_160642`. **Warning from the tool:** `BACKUP_EXTERNAL_PATH` is not set, so this copy exists only on this laptop.
- Migration applied: `2026_12_01_000001_rename_artists_to_outlets` (DONE). Only pending migration at the time.

## Reconcile

- `outlets:reconcile --before` taken with the **pre-rename table names**. The first snapshot read `outlets=0` because the candidate list only named the post-rename tables. Fixed in `OutletsReconcile::TABLES` (`outlets` → `['outlets','artists']`, `settlements` → `['outlet_settlements','artist_settlements']`) and the before snapshot re-taken. The command's test (`OutletsReconcileCommandTest`, 5 tests) passes.
- `--after` after the migration; `--compare` → "REKONSILIASI OK — tidak ada selisih", exit 0. Re-run after the frontend sweep: still exit 0.
- Compared counts/identities: 3 outlets (NEK, YUK, HOS), 3 settlements, 9 products, 27 variants, 5 orders, 11 order items, 2 pre-orders, 40 stock movements, 3 cashier sessions, per-variant stock (27 variants), order-item totals per owner.

## Frontend

- Sweep done by five disjoint groups (reports/dashboard; sales/POS/session; pre-orders/purchasing; products/master data; locale values), plus the router, navigation, settings store, API modules, Outlets page and licence section.
- `vitest run`: 174 files, 1696 passed, 2 skipped.
- `npm run build`: passes.
- Wording guard `qa-tests/unit/noSellerWording.test.js` passes (en and id, empty allow-list).

## Browser check (dev app, owner account, http://localhost:8010)

Done:
- Login works; dashboard loads.
- `/artists` → redirects to `/outlets` (client-side route).
- `/outlets` lists the three demo outlets (HOS, NEK, YUK), outlet wording in headings, "Export .xlsx" / "Bulk import" / "Add outlet", Pro-quota banner ("Pro license — one outlet").
- `/reports` shows the outlet recap with real figures (Hoshizora Craft 4 units / Rp 130.000; Nekoyama Studio 5 / Rp 190.000).
- `/products` shows zero seller/artist/penjual wording in the rendered text.
- Console: no errors on login, /outlets, /reports, /products.
- API: old routes `/api/v1/artists`, `/api/v1/exports/artists`, `/api/v1/reports/artist-*` and `stock-by-artist` → 404. New routes `/outlets`, `/reports/outlet-settlements|outlet-profit|stock-by-outlet`, `/exports/outlets` → 401 without a session.

**Not walked in the browser yet** (covered only by component tests): Outlets add / edit / delete / search / export / Excel bulk import; Excel old-format import and new-format round trip; POS, Sales, Pre-orders, Purchase orders screens for leftover wording.

## Deviations from the plan (honest list)

1. **Translation key names were not renamed** (T023 said to rename keys). Only values were rewritten. Keys such as `nav.artists`, `master_data.artist_updated`, `reports.col_artist` still say "artist" internally; they are not user-visible. The route `outlets` sets `titleKey: 'nav.artists'` explicitly.
2. **Pre-order payloads still return `sellers`** and the pre-order split mode is still `by_seller`. Both are API names; renaming them needs a coordinated backend + frontend change (not done in Stage 1).
3. **Backend messages reworded** in `lang/id` and `lang/en` (`master_data`, `preorders`, `events_sessions`, `bom`): "penjual/seller" → "outlet". No backend test asserted the old text.
4. **Old bookmarks** with `/sales?seller=…` are now ignored (query key `outlet`). Reported by the sales-group agent; accepted as a Stage 1 behaviour change.
5. `master_data.template_description` (en and id) ends mid-sentence ("…now also include a sample"). This is pre-existing text in HEAD, not changed here.
6. **Three backend test files never ran** before this evidence was written. Their class names still said `Artist`, so PHPUnit reported "class cannot be found" warnings and skipped them. Fixed: `StockByArtistReportTest.php` → `StockByOutletReportTest.php`, `SessionOpeningCashPerArtistTest.php` → `SessionOpeningCashPerOutletTest.php`, class `ArtistDeleteGuardTest` → `OutletDeleteGuardTest`. Their assertions were updated to the outlet keys (`outlet_id`, `outlet_name`, query `?outlet_id=`). 13 tests now pass. A scan of all `tests/**/*.php` found no other filename/class mismatch.
7. Pre-existing data condition, not caused by Stage 1: the dev DB has three outlets on a Pro install (Pro = one outlet). The demo seeder created them; the quota check only blocks new creation.
8. **Midnight-window test failures (test-side, fixed).** Between 00:00 and 07:00 WIB the store business date (Asia/Jakarta, `BusinessDay::today()`) is one day ahead of the UTC date returned by `now()`. Tests that built "today" with `now()` failed in that window: `BulkPriceTest` (effective date validated against the business date) and `ReportTest` (`group_by=customer` date filter, order stamped with the business date). Both now use `BusinessDay::today()`. `BulkPriceTest` got a `businessToday()` helper.
9. **Product inconsistency found, NOT fixed in Stage 1 (needs a decision):** `RecipeService` decides which recipe version is active with `Carbon::today()`, which is the app timezone (UTC), and validates back-dating the same way. Price changes (`BulkPriceService`) use the business date. Between 00:00 and 07:00 WIB a recipe whose effective date is today (Jakarta) is treated as future and does not apply until 07:00 WIB. The recipe test was left on the UTC date to match the current code. Fix: move the recipe date checks to `BusinessDay::today()`, in a separate change.

## Backend suite

- Earlier full run (before the three test files were fixed): 1673 passed, **exit 1** because of the three PHPUnit "class cannot be found" warnings.
- Re-run after the fix: see "Final run" below.

**Final run (2026-10-12, after the test-file and midnight-window fixes): 1686 passed (8118 assertions), exit 0, no warnings, 449 s.** Command: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app php artisan test`.
