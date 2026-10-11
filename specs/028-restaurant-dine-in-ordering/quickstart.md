# Quickstart & Verification Script: Restaurant Dine-in Ordering Core (028)

Runs against this checkout's Docker stack: app `http://localhost:8010`, Vite `http://localhost:5180`, MySQL host port `3308`, databases `warkop_wjm` / `warkop_wjm_test`. Nothing here touches any other BoothPOS installation.

> Fill in `tasks.md` slice by slice; sections 3–7 can only be run once the corresponding slice exists. Section 8 is the final acceptance pass and is **required before declaring the feature done** (Constitution II).

## 1. One-time restaurant setup (no code needed — existing screens)

1. Start the stack: `docker compose up -d` (from the repo root). First start installs dependencies and migrates.
2. Activate the dev licence once: `docker compose exec app php artisan license:dev-activate`.
3. Seed the base accounts if the database is empty: `docker compose exec app php artisan db:seed` (dev accounts are listed in `CLAUDE.md`).
4. **Default event** (research.md Decision 3). Sign in as owner → *Events* → create `Operasional Restoran`, start = today, end = a date years ahead, status **Active**. Do this once per data mode (DEMO and LIVE each need their own).
5. **One artist**: *Artists* → create `Restoran` (a venue with `multi_artist_enabled` off may have exactly one active artist).
6. Create menu categories (e.g. *Makanan*, *Minuman*) and a few items with variants (sizes), e.g. `Es Kopi Susu — Regular 25.000`, `— Large 30.000`, and one packaged drink with stock 24.
7. Mark made-to-order dishes **not stock-tracked** on their variant (slice A+B).
8. Open a cashier shift on the event (*Sesi Kasir*), opening cash 0.

## 2. Test commands (always the safe form)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test --filter=TabImpactGuardsTest
docker compose exec node npm test
```

Never run `php artisan test` in the container without the two `-e` flags (CLAUDE.md: that has wiped dev data twice).

## 3. Slice B — tables

1. *Pengaturan Restoran → Meja*: create `1`…`6` in area *Indoor* and `T1`,`T2` in *Terrace*; try a duplicate `1` in *Indoor* → rejected.
2. Open *Lantai*: 8 tables, all **free**, grouped by area.
3. Deactivate `T2` → disappears from the offer list for new tabs.
4. Sign in as `kasir01` (cashier): can see the floor, cannot see/open *Pengaturan Restoran* (menu hidden) and `POST /dining-tables` returns **403**.

## 4. Slice C — tab lifecycle (MVP check)

1. As cashier: open a tab on table 7 → table shows **occupied**, total 0.
2. Add 2× `Es Kopi Susu Regular`; later add 1× `Nasi Goreng` → one tab, 3 lines' worth, total correct (server price).
3. Try to open a second tab on table 7 → **409** pointing to the existing tab.
4. Pay part in cash (partial) → tab stays **open**, balance shown. Pay the remainder with transfer (+ proof) → tab **completed**, receipt available, table **free**.
5. Repeat the final submission with the same `client_ref` (use the browser network tab or `curl`) → **no second payment**, same state returned.
6. Close the shift while a *new* tab is open → **409** listing the tab; settle or void it, then close succeeds.
7. Reports: open a tab and add items **without paying**; check *Sales*, *Dashboard*, *Reports → Profit/Seller settlement*, customer history → the open tab appears **nowhere**; pay it → it appears exactly once.

## 5. Slice D — modifiers

1. *Pengaturan Restoran → Modifier*: group `Size` (required, single): Regular 0 / Large +5.000. Group `Add-ons` (optional, multiple, max 2): Extra shot +8.000, Oat milk +10.000. Attach to the category *Minuman*.
2. In a tab add the coffee: not choosing Size → rejected naming `Size`; Large + Extra shot + note `tanpa es` → line price 25.000 + 5.000 + 8.000 = **38.000**.
3. Rename `Large` → `Besar` and change its price; the existing tab line, receipt and any completed sale still show `Large +5.000`.
4. Receipt and station ticket show the options and the note.

## 6. Slice E — kitchen / bar

1. *Stasiun*: `Dapur`, `Bar`; assign category *Makanan* → Dapur, *Minuman* → Bar; assign the packaged drink item **no station**.
2. Tab with 1 food, 1 coffee, 1 packaged drink → **Send**. Expect: one ticket on `Dapur` (food), one on `Bar` (coffee); the packaged drink creates no ticket on either screen and shows as **ready** on the floor.
3. Two browsers: waiter on *Lantai*, cook on *Dapur*. Send a new round → ticket appears on the Dapur screen **within 5 s** without refreshing (stopwatch it; repeat 10×, ≥ 9 must be ≤ 5 s — SC-003).
4. Cook: `new → preparing → ready`. Waiter's floor tile shows **items ready**; mark served → flag clears.
5. Skipping a state (`new → ready`) is refused (**409**).
6. *Print* on a ticket opens a clean print view (browser print preview).
7. Hide the Dapur browser tab for 30 s, show it again → it refreshes immediately and shows current state. Put the laptop to sleep / drop Wi-Fi briefly → it recovers on its own, no stale "waiting" tickets.

## 7. Slices F–G — move / merge / split / cancel / void

| Check | Expected |
|---|---|
| Move tab 3 → free table 8 | 3 free, 8 occupied, lines/tickets unchanged |
| Move onto an occupied table | **409** (suggest merge) |
| Merge tab on table 4 into table 3 | one tab on 3; table 4 free; tickets still show the right table |
| Merge tabs from two different shifts | **409** |
| Split: tab of 4 lines, pay 2 | new completed sale with 2 lines + receipt; 2 lines remain open; Σ equals original to the cent; stock unchanged by the split |
| Split a tab that already has a partial payment | **409** |
| Remove an **unsent** tracked item | stock restored, total drops |
| Cancel a **sent, `new`** item (cashier, reason) | stock restored, station shows it **cancelled** |
| Cancel a **`preparing`** item as cashier | **403**; as owner with reason → allowed, stock **not** restored, activity-log entry with user + reason |
| Void a tab with payments | **409**; void a tab without payments as owner → stock restored for unsent/`new` lines only, tickets cancelled, table free |
| Void a *completed* tab-sale | existing rule (all stock returned) |

## 8. Final acceptance pass (real browser — required)

1. Run the whole of sections 3–7 in a real browser against `:8010` as the relevant roles, with the **browser console open**: zero errors/warnings attributable to the feature.
2. Check both languages: switch the user language Indonesian ↔ English on every new screen; no raw keys visible.
3. Confirm hidden-not-disabled: log in as inventory (no `floor`) and as cashier (no `dining_setup`) and confirm those menus/buttons are absent.
4. Backend: full suite green — **no modified expectations** in existing tests (SC-008).
5. Frontend: `docker compose exec node npm test` green; `npm run build` succeeds and the new views are separate lazy chunks.
6. Confirm the two other stacks on this machine are unaffected: `docker ps` shows the unrelated containers unchanged.

## 9. Concurrency and load checks (research risks 1 & 2)

```bash
# Two parallel opens of the SAME table → exactly one 201 and one 409 (FR-004 / Decision 10)
TOKEN=<cashier bearer>; BODY='{"session_id":<id>,"table_id":<free table id>}'
for i in 1 2; do curl -s -o /dev/null -w "%{http_code}\n" -X POST http://localhost:8010/api/v1/tabs \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d "$BODY" & done; wait

# Two parallel settles of the same open tab → exactly one completes (SC-009)
# Two parallel adds of different items to one tab → both present afterwards
```

Polling load (before enabling `PHP_CLI_SERVER_WORKERS`, research Decision 6): run 5 browser tabs polling the floor/station screens while triggering a heavy report export; station refreshes must stay ≤ 5 s. If they stall, set `PHP_CLI_SERVER_WORKERS=4` in `docker/php/entrypoint.sh` and repeat; **if multi-worker misbehaves** (sessions, file cache), drop the setting and record the finding in research.md.

Migration check on populated data (risk 2): copy a populated database into `warkop_wjm_test`, run the new migrations, confirm existing orders keep `status='completed'`, `service_type='retail'`, `open_table_key IS NULL`, and that `migrate:rollback` is refused while any `open` order exists.

## 10. Known and accepted behaviours (not defects)

- The *live* shift summary under-reports cash taken on a still-open tab until that tab completes; closing the shift is blocked while tabs are open, so a closed shift can never miss it.
- Seller-settlement reports show a single row ("Restoran") for a venue with one artist.
- Partial-unit bill splitting is limited to unsent or already-served lines; whole lines can always be split.
- Tax, service charge, QRIS, reservations, thermal printing and delivery-platform integrations are separate future features.
