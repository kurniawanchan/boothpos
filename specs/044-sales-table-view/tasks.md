**Classification**: INTERNAL

# Tasks: Sales Page — Tables View

**Input**: Design documents from `specs/044-sales-table-view/` — plan.md, spec.md, research.md (D1–D12), data-model.md, contracts/, quickstart.md

**Tests**: INCLUDED. The constitution requires backend tests on real MySQL and frontend tests under `qa-tests/`, plus a real-browser check of any screen a user interacts with (Principle II). Write each test task before its implementation task and see it fail first.

**Backend test command (never omit the overrides)**: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array app php artisan test --filter=<Name>`. Do not edit PHP while a suite is running.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 toggle · US2 board · US3 detail · US4 access
- Every UI string goes through `python3 -I specs/041-back-office-ui-redesign/tools/merge_locale.py additions.json` (id + en, identical key sets); never edit locale JSON by hand.

---

## Phase 1: Setup

- [X] T001 Record the baseline before changing anything in `specs/044-sales-table-view/baseline.md`: run `FloorViewTest`, `TabPermissionsTest`, `TabPerformanceTest`, `qa-tests/component/SalesView*.test.js`, `qa-tests/component/FloorView*.test.js` (if present) and `npm run build`; write down pass counts and the current main chunk size (≈ 239.6 kB). These are the "must stay green / must not grow" numbers for SC-006.

---

## Phase 2: Foundational (blocks every user story)

**Purpose**: the additive backend read-model changes, the pure figures function and the copy.

### Backend

- [X] T002 [P] Tests first in `tests/Feature/FloorViewTest.php` (extend): `paid_amount` is `"0.00"` for an unpaid open order, the paid total for a partially paid one, and total-minus-change semantics for cash overpaid (use the same fixtures as `PaymentSummary` parity tests); a **rejected** payment is excluded; an inactive table WITH an open order is listed with `is_active: false` and `state: 'occupied'`; an inactive table WITHOUT one stays hidden (the existing test keeps passing); the snapshot `version` changes after a payment is recorded; takeaway rows carry `paid_amount` too.
- [X] T003 [P] Tests first in `tests/Feature/TabDetailReadTest.php` (new, use `Tests\Support\RestaurantFixtures`): `GET /tabs/{id}` returns `opened_by` (cashier name; null when absent), still carries no cost fields (assert `cost_price`/`total_cost` keys are absent on payload and lines), a completed order is returned with `status: completed`, a voided order answers 404, a role without the `floor` menu gets 403.
- [X] T004 Implement in `app/Services/FloorQuery.php`: add `PaymentSummary::orderPaidSql('orders')` as a `paid_amount` select in the existing open-orders query (no per-order query) and format it as a money string in `tabRow()`; list active tables plus inactive tables that have an open order (one `orWhereIn` on the ids already loaded from the open-orders query). Keep the docblock rule: nothing clock-dependent in the payload. Make T002 pass.
- [X] T005 Implement `opened_by` in `app/Http/Resources/TabResource.php` (`whenLoaded('cashier')` → name) and eager-load `cashier:id,name` in `app/Services/TabService.php::loaded()`; make T003 pass. Check every caller of `loaded()` still works (the relationLoaded trap: a caller that does not load it simply omits the field).
- [X] T006 Extend `tests/Feature/TabPerformanceTest.php` (or the floor test) with a query-count assertion for `GET /floor` that is identical for 3 and for 30 tables including paid orders and an inactive-with-order table (the existing "constant queries" guarantee must survive T004).
- [X] T007 Update `docs/openapi-pos-mvp.yaml` in the same change: `/floor` response (`paid_amount` on open orders, inactive-with-order tables) and the tab schema (`opened_by`); keep the YAML valid (`python3 -c "import yaml,sys; yaml.safe_load(open('docs/openapi-pos-mvp.yaml'))"`).

### Frontend foundations

- [X] T008 [P] Tests first in `qa-tests/unit/floorBoard.test.js`, then `resources/js/utils/floorBoard.js` (pure, no I/O): `buildBoard(snapshot, nowMs)` → `{ figures: { openOrders, occupiedTables, totalTables, occupancyPct, overLimit, openValue }, groups: [{ area, label, occupied, total, tiles }], takeaway: [tiles] }`; tile = `{ key, kind: 'table'|'takeaway', name, state: 'empty'|'occupied', partial, overLimit, inactive, orderId, orderNumber, elapsedMinutes, amount }`. Rules from data-model.md: occupied ⇔ `tab` present; over-limit ⇔ elapsed > `OVER_LIMIT_MINUTES = 45` (table tiles only counted in the figure; takeaway tiles may be flagged but are excluded from the "tables over 45 min" figure); partial ⇔ `0 < paid < total`; money summed in integer cents; inactive-with-order tiles count in occupied and total; ungrouped area last; also `formatElapsed(minutes)` ("19 mnt/min", "1 h 05 m" style via the i18n function passed in or returning parts). Cover: boundary 45 vs 46, cents exactness (e.g. 3 × 33 000.10), empty floor, takeaway only, inactive-with-order, ordering preserved, figures equal the sum of the tiles.
- [X] T009 [P] Add all new copy in one `additions.json` merged with `merge_locale.py` (both languages, keys under `sales.*`): `view_list`, `view_tables`, `view_aria`, board title/subtitle ("Live · per {time} · klik meja terisi untuk detail order"), the four figure labels and sub-lines (`n_open_with_table`, `n_percent_occupied`, `hint_serve_or_upsell`, `hint_open_unpaid`), legend (`state_empty`, `state_occupied`, `state_partial`, `state_over`, `state_inactive`), group heading ("{occupied} dari {total} meja terisi"), `takeaway`, `available`, `elapsed_min`, `elapsed_hm`, empty/error/stale/paused texts, detail texts (kicker, facts labels, kitchen status labels reuse existing `floor.*` keys where they exist, `ended_paid`, `ended_voided`, `outdated`, `view_transaction`, `open_in_cashier`). Reuse existing keys wherever they already exist; `qa-tests/unit/localeParity.test.js` and `localeKeys.test.js` must pass.
- [X] T010 Check that existing Floor screen code tolerates the additive snapshot fields: run the Floor screen/store tests and, if a test or `TableCard` assumes only active tables or ignores `is_active: false`, adjust deliberately (an inactive table that holds an open order must remain reachable by cashiers so the order can be settled) without weakening coverage.

**Checkpoint**: backend additions green on MySQL, `floorBoard.js` unit-tested, copy merged.

---

## Phase 3: User Story 1 — Toggle between order list and tables (P1)

**Goal**: a toggle on `/sales` that switches between the untouched list and the Tables view, URL-persisted, hidden without floor access.

**Independent Test**: open `/sales`, switch to Tables and back, reload, confirm the list/filters/summary are unchanged (spec US1).

- [X] T011 [P] [US1] Tests first in `qa-tests/component/SalesViewToggle.test.js` (render `SalesView` with the existing mocking pattern from `SalesView.test.js`): both segments with id/en labels; default list; `?view=tables` shows the board placeholder and sets the toggle state; unknown `view` value → list; no toggle for a user without the `floor` menu (and `?view=tables` falls back to the list); round trip keeps the list's filters, sorting and selection (set a filter, switch, switch back); list-only header actions (event filter, export, bulk verify) are absent in Tables view and present in the list; the existing list requests are not repeated by merely toggling.
- [X] T012 [US1] Implement in `resources/js/views/SalesView.vue`: `useQueryState({ view: 'list' })`; toggle through the page-kit `ViewToggle` placed at the right of the `PageHeader` actions; render it only when `auth.canAccessMenu('floor')`; wrap everything list-specific (KPI strip, shift panel, table, list-only header actions) in `v-if="view === 'list'"` so script state persists while the template is unmounted; mount point for the board (`v-else`). Keep the title/subtitle unchanged. Make T011 and all existing `SalesView*.test.js` pass.

**Checkpoint**: toggle works with a placeholder board; list behaviour identical.

---

## Phase 4: User Story 2 — Floor board (P1)

**Goal**: the four figures and the grouped tiles, live, with all states.

**Independent Test**: with a mix of empty tables, occupied tables and a takeaway order, open Tables and check each tile and figure against the data; open an order at a table and watch it appear without reload (spec US2).

- [X] T013 [P] [US2] Tests first in `qa-tests/component/FloorBoardTile.test.js`: empty tile (name, badge, "Tersedia/Available", not a button); occupied tile (name, badge, order number, elapsed text, amount, is a button with the accessible name from ui-contracts.md); partial and over-45 and inactive-with-order variants and their legend wording; Enter/Space emit `select`; elapsed updates when the `now` prop changes; token classes only (no `#hex` in the component source).
- [X] T014 [P] [US2] Implement `resources/js/components/sales/FloorBoardTile.vue` (props: a tile from `floorBoard.js`, `now`; emits `select`; token classes; empty tiles are not interactive).
- [X] T015 [P] [US2] Tests first in `qa-tests/component/FloorBoard.test.js` (mock `api/floor`): four figures equal what the tiles show; area groups with "N dari M meja terisi" in floor order and the ungrouped group last; takeaway group only when takeaway orders exist and excluded from table occupancy; loading, empty (setup link only when the user has the `dining_setup` menu), error + Retry (never an empty floor), stale notice keeping the last data after a failed refresh; "per HH.MM" follows the last successful refresh; polling pauses when the document is hidden and resumes (reuse the `usePolling` test approach); a snapshot with 60 tables renders all tiles; `unchanged` responses do not re-render.
- [X] T016 [US2] Implement `resources/js/components/sales/FloorBoard.vue`: `KpiStrip` figures, the "Status meja" panel (subtitle with last-refresh time, legend limited to states actually used), area groups and the takeaway group with `FloorBoardTile`, `useFloorStore` + `usePolling` started on mount and stopped on unmount, `useNow(15000)` for elapsed, emits `select-order` with the order id. States per T015. All numbers from `floorBoard.js` only — no second computation. Load it from `SalesView.vue` with `defineAsyncComponent` so it ships with the Sales route chunk, not the main chunk.
- [X] T017 [US2] Wire the board into `resources/js/views/SalesView.vue` (replace the T012 placeholder) and re-run `SalesViewToggle`/`SalesView*` tests; then `npm run build` and compare the main chunk with T001 (must not grow beyond noise; the board code must appear in a lazy chunk).

**Checkpoint**: the board is usable end to end; clicking a tile only emits an event.

---

## Phase 5: User Story 3 — Click a table to see its transaction (P1)

**Goal**: a read-only detail panel of the open order, live, with the ended states.

**Independent Test**: click an occupied table and check every value against the order; confirm no changing action exists; click an empty table and confirm nothing opens (spec US3).

- [X] T018 [P] [US3] Tests first in `qa-tests/component/OpenOrderDetail.test.js` (mock `api/tabs`): shows table/takeaway label, order number, opened-by, opened-at, elapsed, every line (qty, name, modifiers, note, amount, kitchen status pill), subtotal, discount, total, paid, balance and the payments list; **no** add/edit/send/pay/move/merge/split/void control and no call to any mutating tab API (assert the mocked mutators are never called); partial order lists its payments; live refresh picks up a changed line; `status: completed` → "paid" message + "View transaction" that emits the order id for the existing completed-sale popup; 404 → "cancelled or merged" message; failed refresh keeps data with an "outdated" mark; the optional "Open in cashier screen" link points at the `floor-tab` route; Escape closes and focus returns to the opener; polling stops when closed or hidden.
- [X] T019 [US3] Implement `resources/js/components/sales/OpenOrderDetail.vue` on the page-kit `DetailPanel` (kicker, title = order number, chips, facts grid, lines table with the same table style as the item page, totals, payments) using `getTab` from `api/tabs` and `usePolling` (≈5 s, only while open); map responses to the four states of D6; reuse existing `floor.*` copy for kitchen statuses where present; no cost fields are read.
- [X] T020 [US3] Wire `FloorBoard` `select-order` → `OpenOrderDetail` in `resources/js/views/SalesView.vue`; on "View transaction" open the existing `TransactionItemsModal` (`:order-id`) after closing the panel; make sure empty tiles never emit; add the case to `SalesViewToggle.test.js` or a new `SalesViewTables.test.js` (click tile → panel → close → focus back on the tile).

**Checkpoint**: P1 stories complete; the whole feature works for a floor-access user.

---

## Phase 6: User Story 4 — Respect who may see what (P2)

**Goal**: no trace of the feature, and no data, for roles without floor access; no cost in the detail; mode-scoped data.

**Independent Test**: compare Sales as a role with and without floor access; call the two data routes directly as the latter (spec US4).

- [X] T021 [P] [US4] Backend tests in `tests/Feature/FloorViewTest.php` / `TabPermissionsTest.php` (extend if not already covered): a role without the `floor` menu receives 403 on `GET /floor` and `GET /tabs/{id}`; a role with `floor` but without `products`/`reports` still gets no cost fields; DEMO and LIVE data never mix (a table/order created under the other mode is absent from the snapshot and `GET /tabs/{id}` answers 404 for it).
- [X] T022 [US4] Frontend verification of the access rules (no new code expected): the T011 cases (no toggle, deep-link fallback) and T018 cases (no cost fields rendered) cover them; add any missing assertion, and confirm nothing in the Tables view or detail is rendered disabled instead of absent (Constitution III).

---

## Phase 7: Polish & cross-cutting

- [X] T023 Run the full suites and the build: whole PHP suite with the safe command, `npx vitest run --testTimeout=60000`, `npm run build`; update `specs/044-sales-table-view/baseline.md` with the after-numbers (main chunk must be ≈ unchanged; list the new lazy chunk).
- [X] T024 Real-browser walk-through of `quickstart.md` (owner and `kasir01`, both languages, a second window for the live updates, deactivated table with an order, ended-order states, role without floor access, console clean), saving screenshots under `specs/044-sales-table-view/evidence/` and the result in `specs/044-sales-table-view/evidence.md`; remove any test data created in the dev DB afterwards.
- [X] T025 Update `CLAUDE.md` with a short "Sales tables view (feature 044)" section (reuse of `/floor` and `/tabs/{id}`, the three additive backend fields, the single `floorBoard.js` for figures/tile states, the fixed 45-minute rule, what image 27 shows that is NOT built: pre-bill, guests, outlets) and correct the outdated line "No git remote is configured; nothing is pushed" (a remote `origin` exists).
- [X] T026 Final review pass: no unused code or locale keys introduced, comments in Indonesian explaining *why*, the Floor screen unchanged visually, every task above ticked, open items and deviations written into `evidence.md`.

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories**. Phase 2 blocks everything: T004/T005 need their tests (T002/T003) first; T006 after T004; T007 after T004/T005; T008, T009 are independent of the backend and of each other.
- **US1 (T011–T012)** needs only T009 (copy) and the existing floor access check; it can start as soon as T009 is merged.
- **US2** needs T008 (figures), T009, T004 (for `paid_amount`/inactive), and the US1 mount point (T012) for T017.
- **US3** needs US2's `select-order` (T016) and T005 (`opened_by`).
- **US4** backend tests (T021) can run right after T004/T005; T022 after US3.
- **Polish** last.
- Same-file sequencing: `SalesView.vue` is touched by T012 → T017 → T020 (in that order, never in parallel); `FloorQuery.php` only by T004; `locales/*.json` only through the merge tool.

### Parallel opportunities

- After T001: T002, T003, T008, T009 in parallel (four different files/areas).
- After T004/T005: T006, T007, T021 in parallel.
- US1 test T011 can be written while T008/T009 are still in progress.
- Within US2: T013 ‖ T015 (tests), then T014 ‖ (T016 after T014).
- US3's test T018 can be written as soon as the `getTab` mock shape is known (right after T005).

## Implementation Strategy

1. **MVP (stories 1 + 2)**: Phase 1–2, T011–T012, T013–T017. Result: the toggle and a live board with figures and tiles — already answers "see every table's status".
2. **Story 3** next (T018–T020): the click-through the owner explicitly asked for; deliver before declaring the feature done — the three P1 stories ship together.
3. **Story 4** and **Polish** close it out; T024 (browser) is mandatory before reporting done, with evidence recorded.

## Counts

26 tasks — Setup 1 · Foundational 9 (T002–T010) · US1 2 · US2 5 · US3 3 · US4 2 · Polish 4.
