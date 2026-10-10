# Research: Restaurant Dine-in Ordering Core (028)

Every finding below comes from reading the current code of **this** checkout (`OrderService`, `StockService`, `PaymentRecorder`, `CashierSessionController`, `OrderController`, `ReportController`, `User`/`MenuKeys`, the orders/payments migrations, the router/sidebar/locale layout, `docker/php/entrypoint.sh`) plus a read-only impact map of every consumer of `orders`. Where something was inferred and not read, it is marked **UNVERIFIED**. Nothing here assumes any other BoothPOS installation.

## Decision 1 — Open tabs are a new status on `orders`, not a separate model

**Decision**: Add `'open'` to `orders.status` (`completed`/`voided` today) and keep the tab, its lines and its payments in the existing `orders` / `order_items` / `payments` tables. A tab that is paid simply flips to `completed` — it *is* an ordinary sale from that moment.

**Rationale**:
- Reports, settlements, receipts, customer history, activity log and the whole-sale void already hang off `orders`/`order_items`. A second record type would force every one of them to learn a second source, or a copy-on-close step that can fail halfway.
- Payment recording already attaches to `order_id` (CHECK `chk_payments_target`), so partial and split payments need no schema change.
- Safe by default: the column default stays `completed`, so every existing caller that omits `status` (including direct `Order::create()` in tests/seeders) is unchanged.
- The cost — "an open order must never be counted as a sale" — is bounded and fully mapped (Decision 5): one explicit `completed` filter fix, two endpoint guards, two filters. Almost every report query **already** filters `status = 'completed'` explicitly.

**Alternatives rejected**: a separate `tabs`/`tab_items` model (duplicates line snapshotting, pricing and the void/receipt/report surface, then needs a risky "convert to order" step); reusing `pos_drafts` (a loose JSON cart snapshot with deliberately no stock/payment effect — the opposite of what a tab needs; it stays untouched for the retail POS).

## Decision 2 — The kitchen domain is separate from `order_items`; cancelled lines are deleted

**Decision**: Preparation state lives in `kitchen_tickets` + `kitchen_ticket_items` (name/qty/modifier/note **snapshots**, status, who/when per transition). `order_items` gains only `modifiers`, `note`, `sent_at`. A cancelled line is **deleted** from `order_items`; its ticket item is marked `cancelled` with the reason and the action goes to the activity log.

**Rationale**:
- `order_items` is read by every revenue/settlement/profit query as "what was sold" (`sum(line_total)`, `sum(qty)`). Adding a `cancelled` line state to it would put a hidden filter obligation on all of those queries; deleting the line means no existing query can ever count a cancelled item.
- The existing `qty > 0` CHECK forbids zero-quantity lines anyway.
- The station screen must keep showing a cancelled item ("do not cook this") after the line is gone, which is exactly what the ticket-item snapshot is for.
- Forward-only transitions happen at most once each, so `preparing_at/by`, `ready_at/by`, `served_at/by`, `cancelled_at/by/reason` columns on the ticket item replace a history table (one fewer table than the first sketch).

**Alternatives rejected**: status columns on `order_items` (leaks into every report, couples the kitchen to the money model, and a split/merge would have to move prep state around with the money lines); a `kitchen_item_events` history table (more rows and joins than the forward-only rule needs).

## Decision 3 — Shifts, events and the "default event" (spec Assumption resolved)

**Facts**: `orders.session_id` and `event_id` are NOT NULL; `OrderService::create` takes `event_id` from the cashier session; a session can only be opened on an event with `status = 'active'` (`CashierSessionController::store`); `events.start_date/end_date` are NOT NULL; `Event` carries `data_mode`.

**Decision**: No schema or flow change. A restaurant operates by creating **one long-lived active Event** (e.g. "Operasional Restoran", start today, end far in the future) per data mode and opening shifts on it, exactly as today. `quickstart.md` documents this as a one-time setup step, and the demo seeder (a follow-up, not in 028) can create it for DEMO mode.

**Rationale**: Making `event_id` optional would touch an FK that `ReportController`/`SettlementService` join and group by; the event concept is the reporting grouping the owner can still use ("Operasional Okt 2026"). Zero risk to existing flows.

**Also verified**: a venue with `multi_artist_enabled = false` (the default) may have exactly **one** active artist (`LicenseGate`). Every menu item still needs an `artist_id`, so a restaurant creates one artist ("Restoran") and all dishes use it. Seller-settlement reports then show a single row — acceptable and documented, not a defect.

## Decision 4 — Stock: deduct on add, restore only before preparation; `track_stock` flag

**Decision** (the explicit decision the spec demanded): see the stock table in `data-model.md`. Summary — `sale` movement when a stock-tracked line is added; `return` when an unsent line is removed/reduced or a sent line is cancelled while the ticket item is still `new`; **nothing** when cancelled from `preparing` onward; moves/merges/splits/settlement never touch stock. A new boolean `product_variants.track_stock` (default true) lets made-to-order dishes skip stock entirely.

**Implementation shape**: a new method `StockService::applyMovementIfTracked(...)` that returns `null` for `track_stock = false` and otherwise delegates to the existing `applyMovement()`. The retail `OrderService::create()/void()` switch to it too, so a non-tracked dish behaves the same on both screens, while tracked variants behave byte-for-byte as before. Constitution I (single sanctioned write path) is preserved: every stock change still ends in `applyMovement()`.

**Rationale**: matches how sales already deduct stock (at creation), blocks over-selling at ordering time (`applyMovement` already refuses to go negative with a validation error and runs under a row lock, which also serialises two waiters ordering the last portion), and stays correct if a tab is abandoned.

**Facts to respect**: movement `reference_type='order_item'`; the existing retail `sale` movement stores the **order** id in `reference_id` while `return` movements store the **item** id (an existing inconsistency, reported by the impact scan). Tab-line movements use the item id for both directions so the net per line is derivable. The retail code is **not** changed to "fix" this (FR-041).

**Alternatives rejected**: deduct at payment (a dish could be promised and sold twice during a long meal; abandoned tabs would need no cleanup but guests would be served from stock that was already gone); a separate reserved-stock counter (a second source of truth for the same quantity — exactly what Constitution I forbids).

## Decision 5 — Everything that reads orders: the full guard list

From the impact map (all line numbers checked against the current files). **Must change** (each gets a regression test):

| # | Where | Today | Change |
|---|---|---|---|
| 1 | `CashierSessionController::close()` ~L120 | `status != 'voided'` — an `open` tab would be counted in `expected_cash` | explicit `status = 'completed'`; plus the new 409 guard "shift has open tabs" placed after the already-closed check, before the update; response lists the open tabs |
| 2 | `OrderService::void()` L212 | only rejects `voided` — an open tab could be "voided" and wrongly **return** stock | reject `open` with a clear 409 ("gunakan Batalkan Tab"); also switch its stock call to `applyMovementIfTracked` |
| 3 | `OrderService::create()` L36-40 | `local_ref` idempotency returns any existing order | if the existing order is `open` → a `ValidationException` on `local_ref`, which `OrderController::store()` already maps to **409** (a reused client uuid must not masquerade as a created sale) |
| 4 | `OrderController::index()` L29-37 | no default status filter | **always** restrict to `completed`+`voided` — even an explicit `?status=open` returns nothing (open tabs have their own endpoints) |
| 5 | `OrderController::receipt()` L106 | no status gate — would print an unpaid receipt | 409 for `open` |
| 6 | `CustomerController::transactions()` L149 | unfiltered `orders()->get()` | exclude `open` |
| 7 | `SalesTransactionsService` L43, `ReportController` (sales/profit/artistProfit/settlement/exports), `SettlementService` L40, `CashierSessionController::summary()` L153 | already `status = 'completed'` | **no change** — covered by new tests proving an open tab is excluded (FR-042) |
| 8 | `Customer`/`Event`/`CashierSession` `hasMany(Order)` relations | unfiltered | **no change**; delete guards (`customer/event->orders()->exists()`) are any-status by design, so an open tab correctly blocks deleting a customer/event |
| 9 | Frontend `useSalesFilters.js` L34/L155-169/L178-180 | `isVoided = status === 'voided'`; summaries count "not voided" | harden to `status === 'completed'` so a future payload change can never count an open tab (defence in depth; the API already filters) |
| 10 | Frontend `SessionView.vue` close flow | no open-tab handling | surface the 409 `open_tabs` list with links to each tab |

Two quirks recorded, **not** fixed here (out of scope, FR-041): the Session close preview in `SessionView.vue` ignores change given while the server subtracts it; `cashier_sessions.status` also uses the literal `'open'`, so greps for `'open'` mix two concepts — new code/tests use `Order::STATUS_OPEN` style constants.

**Partial payments and shift cash**: a cash payment taken on a still-open tab sits physically in the drawer but is not in `summary()` (completed only) until the tab completes. Because FR-011 blocks closing a shift with open tabs, no closed shift can ever miss that cash — the only effect is that the *live* summary of an open shift under-reports until tabs are settled. Accepted and documented in the quickstart.

## Decision 6 — Live updates: short polling with a version token (no SSE, no sockets)

**Facts**: nothing in `resources/js` polls today (`setInterval`/`EventSource`/`visibilitychange` all absent); there is no broadcasting/queue infrastructure; both Docker images serve PHP with **`php artisan serve`** (`docker/php/entrypoint.sh:44`, `docker/store/entrypoint.sh:45`), the single-process built-in server unless `PHP_CLI_SERVER_WORKERS` is set.

**Decision**:
- Station screens and the floor view poll every **3 s** while the tab/window is visible, pause when hidden, and do a full fetch on resume and after any failed request (so an overnight-open screen or a dropped connection self-heals and never shows stale items — spec edge cases).
- Each poll sends the last `version` token. The token is a **hash of the response body itself** (decided during implementation: a `max(updated_at)` token cannot see two changes inside the same second). The server still builds the payload (a constant 2 queries for the floor), but answers a tiny `{unchanged: true, version}` — saving bandwidth and, more importantly, client re-rendering. The payload therefore carries no time-varying fields.
- A new composable `composables/usePolling.js` owns this (visibility-aware, in-flight-guard, jittered, cleans up on unmount).
- Set `PHP_CLI_SERVER_WORKERS` (e.g. 4) in both entrypoints so one slow request (an export, a report) cannot stall every station poll. **Risk flagged**: PHP documents the built-in server as not for production, but it is the deployment already in use; the setting only adds worker processes. Measured in quickstart (5 polling clients + a report export).

**Why 3 s meets SC-003 (≤5 s for 95%)**: worst case is one poll interval + request time ≈ 3.2 s on a local network.

**Alternatives rejected**: SSE/long-poll (each open connection would pin a PHP worker — fatal with `artisan serve`); Laravel Reverb/websockets (new always-on process and dependency for a single-machine product, violates "no new moving parts" and the stack constraints); 1 s polling (3× the load for no user-visible gain).

## Decision 7 — Modifiers: snapshot onto the line; effective price in `sell_price`

**Decision**: When a line is added, the server resolves the chosen option ids against the product's **effective groups** (product ∪ category groups), validates every rule, and snapshots `[{group, option, price_delta}]` into `order_items.modifiers`. `order_items.sell_price` stores the **effective unit price** (variant price + Σ deltas). `line_total = sell_price × qty − discount_amount` therefore keeps holding, and every existing report formula that reads `sell_price`/`line_total` stays correct without modification. `cost_price` is untouched.

**Rationale**: Constitution IV requires an immutable snapshot of historical financial data — later edits to a group/option/price must never alter a past line (SC-006). Folding the delta into `sell_price` is the only option that needs **zero** changes in report code.

**Rules**: unit price < 0 → 422; selecting an option from a group not effective for that product → 422 (never trust ids from the client); inactive options/groups are not offered and not accepted; identical **unsent** lines (same variant, same option-id set, same note) merge by qty.

**Alternatives rejected**: a separate `order_item_modifiers` table (extra joins for receipts/reports, no benefit over an immutable JSON snapshot that is only ever read whole); storing the delta in a new column and recomputing price in reports (touches every report query).

## Decision 8 — Permissions: three new menu keys, mapped to existing roles by a data migration

**Facts**: authorization is entirely menu-key based — `User::canAccessMenu($key)` reads `role.menu_keys`; `isOwnerOrAdmin()` **is** `canAccessMenu('settings')`; roles are configurable per store (feature 001); the repo's pattern for a new key is `MenuKeys::ALL` + a `*_add_<key>_menu_key_to_default_roles` migration (e.g. `2026_10_19_000011_…invoices…`).

**Decision**: keys `floor` ("Lantai & Tab"), `kitchen` ("Dapur & Bar"), `dining_setup` ("Pengaturan Restoran"). One migration grants: Owner/Admin → all three; Cashier → `floor` + `kitchen`; Inventory → `dining_setup`. Gating (all server-side; the UI only mirrors it and **hides** unavailable controls, Constitution III):
- tables / stations / modifier groups / station assignment / `track_stock` → `dining_setup`
- open, add/edit/remove unsent lines, send, move, merge, settle, split, mark `served`, cancel a `new` item → `floor`
- station list, `new→preparing→ready`, ticket print → `kitchen`
- cancel `preparing`/`ready`/`served`, void tab, hand over a tab → `isOwnerOrAdmin()` (same tier as voiding a sale today, spec assumption)

**The spec's open question (seed "Kitchen"/"Waiter" roles?)**: still **no**. A "Kitchen" role is just a role with only `kitchen` ticked, creatable in the existing Roles screen. Revisit only if the product owner asks.

**Alternatives rejected**: a single `restaurant` key (a cashier would get setup powers or a cook would get payment powers); hard-coded new role slugs (feature 001 deliberately replaced fixed roles).

## Decision 9 — Payments on a tab: partial allowed, split refused once any payment exists, idempotent by `client_ref`

**Decision**:
- `POST /tabs/{id}/payments` reuses `PaymentRecorder::record()` unchanged (proof tokens, verification, `chk_payments_channel`). Paid amount accumulates; the tab completes when `paid_amount ≥ total`; change uses the existing "change ≤ cash received" guard across the cumulative cash.
- A payment of `0` is never created (`chk_payments_amount`).
- **Split is refused (409) when the tab already has any payment** — there is no sane rule for apportioning an earlier partial payment across the items being split off (identical stance and wording as feature 027's pre-order split). Split takes its own payments in the same request and completes the split-off sale at once.
- Removing/reducing lines that would push `total_amount` below `paid_amount` is a 409 (no refund workflow — out of scope).
- Voiding a tab with any payment is a 409.
- **Idempotency** (FR-010): `payments.client_ref` (char(36), UNIQUE — created by the ledger migration `2026_10_31_000001_add_ledger_columns_to_payments_table`, not by feature 028). A repeated `client_ref` for the same order returns the current state with nothing re-recorded. Tab opening is idempotent through the **existing** unique `orders.local_ref`.

**Concurrency**: every tab mutation runs in a DB transaction that first `lockForUpdate()`s the order row, so two waiters adding at once serialise (neither loses an item), and two simultaneous settles serialise (the second sees `completed` and gets 409). Stock rows are additionally locked inside `applyMovement()`.

**Alternatives rejected**: refusing partial payments entirely (contradicts the spec's running-balance scenario); apportioning prior payments on split (needs allocation rules the owner never specified).

## Decision 10 — "One open tab per table" is enforced by the database

**Decision**: `orders.open_table_key` = generated STORED column `IF(status = 'open', table_id, NULL)` with a UNIQUE index. Two waiters opening the same table at the same instant: the loser gets a duplicate-key error which the service maps to the 409 "table occupied" (with the existing tab id). Merge/move/complete/void free the key automatically because status/table change.

**Rationale**: an application-level "check then insert" has a race window; MySQL 8 (already mandatory for this project — Constitution, Stack) supports generated columns and multiple NULLs in a unique index.

**Alternatives rejected**: a stored `is_occupied` flag on `dining_tables` (can drift from the truth); app-level locking only.

## Decision 11 — Split bill mechanics

**Decision**: splitting creates a **new** `completed` order (new `order_number`, `split_from_order_id` = the tab, same shift/event/table/cashier) and moves lines into it: a whole selected line is **re-parented** (`order_items.order_id` updated — same row id, so stock movements and ticket-item links stay valid); a partially selected line is **shrunk** and its snapshot **cloned** with the selected qty. Totals of both orders are recomputed from their own lines; Σ(new sale) + Σ(remaining tab) equals the original to the cent because both are sums of the same integer-qty × decimal-price lines (SC-005). No `StockService` call is made (FR-016), mirroring feature 027's split.

**Kitchen interplay**: the ticket and its items **stay** attached to the original tab (`kitchen_tickets.order_id` is never re-pointed by a split), so the station screen keeps showing the table. Because a ticket item has one `order_item_id`, partial-unit selection is limited to unsent lines or lines already `served` (409 with an explanatory message otherwise); whole-line selection is always allowed (guests may pay before the food arrives, spec US5-6).

When the last line leaves the tab, the tab (now empty) is closed as `voided` with reason "dibayar penuh melalui pisah tagihan" and the table frees.

## Decision 12 — Frontend structure and reuse

**Facts**: routes declare `meta.menuKey`; the sidebar is a list with `menuKey` per entry and collapsible `*-group` entries (`purchase-group`, `inventaris-group`, `settings-group`); locales are two single files `resources/js/locales/{id,en}.json` plus per-feature backend files `lang/{id,en}/*.php`; API modules are one-file-per-resource (`api/*.js`) over `api/client.js`; stores are Pinia (`posCart.js` is the retail cart); `PosView.vue` composes `ProductVariantPickerModal`, `PosPaymentModal`/`PaymentPanel` (`mode` = `checkout` | `record`), `ReceiptModal`, `CustomerPickerModal`, `BaseMultiSelect`; there is no polling code.

**Decision** — new screens (all lazy-loaded routes, like the rest):
- `FloorView.vue` (`/floor`, key `floor`) — tables grid by area + takeaway tabs; opens `TabPanel`.
- `TabView.vue` (`/floor/tabs/:id`, key `floor`) — product browser reused from the POS (`utils/posProductCards.js`, `ProductVariantPickerModal`) + new `ModifierPickerModal.vue`; tab lines, send, move, merge, split, pay.
- `KitchenView.vue` (`/kitchen`, key `kitchen`) — station tabs, ticket board, print view.
- `DiningSetupView.vue` (`/dining-setup`, key `dining_setup`) — three tabs: Tables, Stations, Modifier groups (+ station dropdown added to the existing category/product forms and a `track_stock` switch to the variant form).
- Sidebar: a new **"Restoran"** group (`restaurant-group`) holding Floor, Kitchen, Dining setup; visible when any child is.
- Payments for a tab reuse `PaymentPanel mode="record"` through the existing `RecordPaymentModal` (entries accumulate, then one submit with a `client_ref`) rather than a second payment UI.
- State: `stores/floor.js` (floor + current tab) and `stores/kitchen.js` (station tickets); both driven by `usePolling`.
- Strings: new `floor.*`, `kitchen.*`, `diningSetup.*` namespaces, added to **both** locale files in the same change; the JSON repeated-key trap noted in CLAUDE.md means each namespace is added once under a distinct top-level key, verified by a unit test that parses the files and fails on duplicate keys.
- Tokens only: all styling from `@theme` variables in `resources/css/app.css`; no raw hex (Constitution III). Printable ticket uses `window.print()` + a print stylesheet — **no new dependency**.

**Alternatives rejected**: extending `PosView.vue` into a dual-mode screen (it is already the most complex view; a tab editor has a different state model — server-owned lines vs a local cart); a modal-only floor (a tab can have dozens of lines and needs a full screen on a tablet).

## Decision 13 — Test strategy (Constitution II)

- **Backend (real MySQL, `tests/Feature/`)**: one file per slice — `DiningTablesTest`, `StationsTest`, `ModifierGroupsTest`, `TabLifecycleTest`, `TabLinesTest, TabModifiersTest`, `TabStockTest`, `TabSettlementTest` (partial, change, idempotency, concurrency), `TabSplitMergeMoveTest`, `KitchenTicketsTest`, `TabVoidAndCancelTest`, `TabImpactGuardsTest` (Decision 5 items 1-6, one test per row), `TabPermissionsTest` (every endpoint × every default role), `TabReportsExclusionTest` (open tab absent from sales, profit, settlements, exports, Sales page, customer history, dashboard totals), `TabDataModeTest` (DEMO/LIVE isolation incl. cross-mode table/modifier ids → 404/422), `MenuKeysMigrationTest`. Existing suites that assert order status/count (`OrderTest`, `CashierSessionTest`, `SalesTransactionsTest`, `ReportTest`, `CustomerTransactionsTest`, `SakanaFridgeDemoSeederTest`, …) run unmodified and must stay green (SC-008) — only **new** cases are added to `OrderTest`/`CashierSessionTest` for the shift-close guard.
- **Concurrency**: tests run the service twice against the same table/tab inside separate DB connections where PHPUnit allows; where it cannot be simulated in-process (true parallel requests) it is covered by a scripted check in `quickstart.md` (two parallel `curl` opens of one table → exactly one 201, one 409).
- **Frontend (`qa-tests/`)**: Vitest component tests with `vi.mock` for the new API modules (`FloorView`, `TabView` incl. modifier picker validation, `KitchenView` status buttons, `usePolling` with fake timers incl. pause-on-hidden and recover-after-error, locale-parity test).
- **Real browser (mandatory for user-facing screens)**: the quickstart scenario run against the Docker stack on `:8010` with the console checked for errors, plus a two-browser check of "send → appears on station within 5 s".
- **Run command**: always `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app php artisan test` (CLAUDE.md).

## Decision 14 — Documentation and scope-record obligations

Per the constitution: `docs/openapi-pos-mvp.yaml` is updated in the **same commit** as each route group; PRD gets a **dated note** recording the restaurant-variant scope (not a rewrite of the MVP cuts); `CLAUDE.md` gains the 028 summary and the "20 models use `HasDataMode`" count becomes 26; `docs/RUNBOOK.md` gets the default-event setup and the `PHP_CLI_SERVER_WORKERS` note. New code comments are Indonesian and explain **why**, following the existing `BUG YANG DITEMUKAN & DIPERBAIKI` convention for anything discovered during real execution.

## Open risks carried into tasks

1. **`PHP_CLI_SERVER_WORKERS`** under `artisan serve` — **RESOLVED 2026-10-04 (measured)**. Single-process baseline: a 4 s request held a light poll for ~3.5 s (head-of-line blocking); with 4 workers the same poll took ~7 ms. Two non-obvious facts: (a) `artisan serve` honours the variable **only with `--no-reload`** (otherwise it warns and starts one server); (b) in the real app, 5 `php -S` processes run (1 master + 4 workers) and 60/60 concurrent requests returned 200; cache/session drivers are `file`, shared by all workers. Applied in both entrypoints (default 4, `PHP_CLI_SERVER_WORKERS=1` disables). Residual caveat: PHP marks the multi-worker mode **experimental** and the built-in server is not for production — already the deployed server, so this adds processes, not a new risk class.
2. **MySQL generated-column migration** on a table that already has data (`ALTER TABLE orders ADD COLUMN … GENERATED … STORED` rewrites the table) — acceptable at this product's data size; test the migration against a populated `warkop_wjm_test` copy.
3. **Enum `MODIFY`** must restate the full enum list and keep the default; migration must be reversible only while no `open` rows exist (`down()` refuses otherwise).
4. **Partial-unit split limited to unsent/served lines** is a deliberate simplification; if owners need finer splitting, per-unit ticket items are the next step (not in 028).

## Out-of-scope follow-ups and known limitations (recorded at feature close)

Follow-ups (each a separate future feature, none started): a restaurant **demo seeder** (tables, stations, modifier groups, a sample menu); **tax and service charge**; **recipe-based ingredient stock deduction**; a **waste/spoilage** adjustment category; **equal-share split** (N people); **per-unit ticket items** (finer split of partially-sent lines, risk 4).

Known limitations, deliberately accepted in 028:
- **Merge and split are refused (409) for a tab that already has a payment** (mirrors the pre-order split rule — no payment-allocation logic).
- **Stock return is skipped for a soft-deleted variant** when a line is cancelled or a tab is voided; the line is removed but no `return` movement is written for a variant that no longer exists.
- **Short polling, not push**: screens lag by up to the poll interval (3-4 s); see Decision 6.
- **Browser acceptance tasks** (quickstart sections run in a real browser, two-browser 5-second kitchen check) were not run by the assistant — see tasks.md notes.
