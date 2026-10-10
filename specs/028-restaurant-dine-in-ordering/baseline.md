# Baseline — before any 028 code (T001)

Command (safe form, this checkout only):

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test
```

| | |
|---|---|
| Date | 2026-10-03 |
| Branch | `028-restaurant-dine-in-ordering` (only docs/config changes at that point) |
| Backend result | **778 passed**, 3047 assertions, **213.5 s**, exit 0 |
| Frontend (Vitest) | 58 files, **441 passed, 2 skipped** (includes the 4 new locale-parity tests from T004) |

SC-008: after the feature, every one of these 778 tests must still pass with **no modified expectations** (new tests are additions; the only deliberate exception is the shift-close case in T005/T059, documented in `TabImpactGuardsTest`).

## Checkpoint log

| When | Scope | Backend | Frontend |
|---|---|---|---|
| After Phase 2 (foundation) | guards, schema, menu keys, tracked stock | 801 passed (778 + 23) | 59 files, 444 passed, 2 skipped |
| After Phase 4 (US1 + US2 = **MVP**) | tables, floor, tabs, settlement | **902 passed**, 3556 assertions, 254 s | 65 files, 483 passed, 2 skipped |

Every existing test still passes with no modified expectation (one deliberate behavioural change: the shift-close test in `TabImpactGuardsTest` was rewritten **before** any existing suite was touched, because FR-011 now blocks closing a shift that holds an open tab).

## Final verification (2026-10-04)

- **Backend (T162)**: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test` → **1056 passed, 4482 assertions** (baseline 778 + 278 new). Includes `OpenApiCoverageTest`.
- **Frontend (T163)**: `npm test` → **74 files, 584 passed, 2 skipped** (baseline 441 + 2 skipped). `npm run build` OK; `FloorView`, `TabView`, `KitchenView`, `DiningSetupView` are separate lazy chunks; no change to `package.json`/`composer.json`/lockfiles (no new dependency).
- **Flaky test, not caused by 028**: on the first full run `PreordersView.test.js › split action (027 US3) › loads the full pre-order…` failed once (`waitFor` timeout, ~1.4 s) while 74 files ran in parallel; it passed 3/3 in isolation and in a second full run. Neither the test nor `PreordersView.vue` is modified by this feature. Treated as a timing flake under load; **ASSUMPTION** (cause not investigated).
- **Separation (T166)**: `boothpos-app-1`, `boothpos-node-1`, `boothpos-mysql-1` still up since 2026-10-03 05:14 UTC (never restarted), ports 8000/5174/3307, volume `boothpos_mysql_data` present. Checked read-only with `docker ps`/`docker inspect`; no connection to the `boothpos` databases was made.
- **Server workers (T156)**: see research.md risk 1 and RUNBOOK §11.3.

## Real-browser run (2026-10-04, `http://localhost:8010`, user `owner`, DEMO mode, English then Indonesian)

**Covered and working** (driven through the UI, then checked in the database):
- Restaurant Setup: created tables 1 and 2 (Area "Indoor") and a "Kitchen" station through the forms; the category form's "Preparation station" dropdown listed the new station and saved.
- Cashier shift opened; Floor showed both tables Free, event name under the title, no "open a shift" banner.
- Opened a tab on table 1 (confirmation dialog) → tab screen: Occupied + order number, menu, Pay disabled while empty.
- Modifier picker: required group "Level pedas" ("Required · choose 1"), "Add to tab" disabled until chosen, live price 25.000 → 27.000, kitchen note field.
- Send to kitchen → toast "Sent: 1 ticket(s)", line badge "New · Kitchen", qty/note became read-only.
- Kitchen & Bar: ticket card (table, order number, round, qty, option, italic note, age timer, print icon); New → Start → Preparing → Ready.
- Floor: table tile "1 ready to serve"; tab: "Ready · Kitchen" → Mark served → "Served · Kitchen".
- Pay (reused payment panel) → cash 27.000 → receipt shows "Meja 1", option and note; tab "Paid".
- Database after payment: order `completed`/`dine_in`, 27.000 paid, `open_table_key` NULL, one verified cash payment, `sell_price` = effective 27.000, modifier snapshot incl. ids and `price_delta`, ticket item `served`, **0** stock movements (`track_stock = false`).
- Indonesian locale on the floor and sidebar; language restored to English afterwards.
- Console: no JS errors and no Vue warnings. The only entries were two `404 /api/v1/sessions/current` made BEFORE a shift existed — the existing "no open shift" response, `current()` is unchanged by this feature.

**NOT covered (still open):** move / merge / split bill; cancel of a sent line and void tab (with reason); other roles (cashier, inventory, admin) and their permission differences; the "send → appears on the station within 5 s" two-browser timing; modifier-group and station admin screens beyond opening them; takeaway tabs; shift-close with an open tab; DEMO/LIVE switch; parallel open/settle curl checks (T165).

**Observations (not blocking):**
1. Cosmetic: on the tab screen at ~1200 px width, a line with a status badge + "Mark served" + "Cancel" squeezes the dish name onto three short lines.
2. First paint after a full page load (`page.goto`) showed a blank body or the default "LIVE / Pro" header for several seconds before settling to DEMO / Master. Duration not measured; the default flash is the settings store's defaults before `/settings/features` returns. **ASSUMPTION** that it pre-dates 028.
3. Two real pointer clicks on "New table" right after the lazy view first rendered did nothing; a later real click worked and the in-page click always did. Cause not found — **ASSUMPTION**: clicks landed before the view had finished mounting. Not reproduced.
4. `Escape` pressed while focus is outside the dialog does not close `BaseModal` (its key handler sits on the backdrop). Pre-existing shared component, not changed here.

Dev data left in the `warkop_wjm` dev database (DEMO mode) by this run: tables 1–2, station Kitchen, category MK, product Nasi Goreng, modifier group "Level pedas", one event, one artist "Restoran", one open cashier shift (opening cash 100.000), one paid sale. None of it touches `boothpos`.
