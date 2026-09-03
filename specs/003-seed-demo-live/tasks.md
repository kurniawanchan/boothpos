---

description: "Task list for Seed Data Dummy & Mode DEMO/LIVE"
---

# Tasks: Seed Data Dummy & Mode DEMO/LIVE

**Input**: Design documents from `/specs/003-seed-demo-live/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/, quickstart.md (all present)

**Tests**: Included and **mandatory**, not optional — Constitution Principle II requires every backend change to ship with a `tests/Feature/` test run against real MySQL, and any user-facing screen change to be exercised in a real browser before being called done. This overrides the generic template default of "tests are optional."

**Organization**: Tasks are grouped by user story (spec.md priorities: US1 = P1, US2 = P1, US3 = P2) so each can be implemented and verified independently on top of a shared foundational layer.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- **[Story]**: US1 / US2 / US3, per spec.md
- File paths are exact and repo-relative

## Path Conventions

Existing single-repo Laravel API + Vue SPA (per plan.md "Structure Decision") — `app/`, `database/`, `docs/`, `resources/js/`, `tests/Feature/`, `qa-tests/` at repo root. No new top-level directories.

---

## Phase 1: Setup

**Purpose**: Confirm the environment this feature's tests depend on before touching schema or code.

- [X] T001 Verify `.env.testing` exists and points at a separate `boothpos_test` MySQL database (CLAUDE.md non-negotiable constraint — `RefreshDatabase` must never touch the real app DB); run `php artisan test` once unmodified to confirm the existing 214+ tests pass as a clean baseline before this feature's changes begin. — 264/264 passing baseline confirmed.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: The `data_mode` schema change and the `ModeGate`/`HasDataMode` mechanism are shared by all three user stories (US1 needs to stamp seed rows `demo`; US2 needs the scope to filter reads; US3 needs both to prove report isolation) — per plan.md/data-model.md this is one mechanism, built once.

**⚠️ CRITICAL**: No user story task may begin until this phase is complete.

- [X] T002 Create migration `database/migrations/2026_10_11_000001_add_data_mode_to_business_tables.php` adding `data_mode ENUM('demo','live') NOT NULL DEFAULT 'live'` + an index (`idx_<table>_data_mode`) to all 20 tables listed in `specs/003-seed-demo-live/data-model.md`: `events`, `artists`, `categories`, `products`, `product_variants`, `customers`, `vendors`, `materials`, `vendor_material_prices`, `product_variant_bom_lines`, `cashier_sessions`, `orders`, `order_items`, `preorders`, `preorder_items`, `shipments`, `payments`, `payment_proofs`, `stock_movements`, `artist_settlements`. Do **not** touch `users`, `roles`, `settings`, `activity_logs`, `payment_channels` (explicitly excluded per FR-012).
- [X] T003 [P] Update `docs/schema-pos-mvp.sql` to document the new `data_mode` column on each of the 20 tables from T002 and add a comment block explaining the new `settings` row `system_mode` (mirroring the existing `multi_artist_enabled` comment style) — required in the same feature per CLAUDE.md's "schema is documentation source of truth" convention.
- [X] T004 [P] Create `App\Support\ModeGate` in `app/Support/ModeGate.php`: `current(): string` (checks an in-process override stack, else `Setting::get('system_mode', 'live')`), `isDemo(): bool`, `isLive(): bool`, `runAs(string $mode, callable $callback): mixed` (push/pop the override stack in a `try/finally`) — mirror the docblock style of `app/Support/LicenseGate.php`.
- [X] T005 [P] Create `App\Models\Concerns\DataModeScope` in `app/Models/Concerns/DataModeScope.php` implementing `Illuminate\Database\Eloquent\Scope`, applying `$builder->where($model->getTable().'.data_mode', ModeGate::current())`.
- [X] T006 Create `App\Models\Concerns\HasDataMode` trait in `app/Models/Concerns/HasDataMode.php`: `protected static function bootHasDataMode(): void` registers `static::addGlobalScope(new DataModeScope)` and a `static::creating()` listener that sets `$model->data_mode = $model->data_mode ?? ModeGate::current();` — depends on T004, T005.
- [X] T007 [P] Apply `use HasDataMode;` to the 10 master-data models: `app/Models/Event.php`, `app/Models/Artist.php`, `app/Models/Category.php`, `app/Models/Product.php`, `app/Models/ProductVariant.php`, `app/Models/Customer.php`, `app/Models/Vendor.php`, `app/Models/Material.php`, `app/Models/VendorMaterialPrice.php`, `app/Models/ProductVariantBomLine.php` — depends on T006.
- [X] T008 [P] Apply `use HasDataMode;` to the 10 transactional models: `app/Models/CashierSession.php`, `app/Models/Order.php`, `app/Models/OrderItem.php`, `app/Models/Preorder.php`, `app/Models/PreorderItem.php`, `app/Models/Shipment.php`, `app/Models/Payment.php`, `app/Models/PaymentProof.php`, `app/Models/StockMovement.php`, `app/Models/ArtistSettlement.php` — depends on T006.
- [X] T009 Write and run `tests/Feature/DataModeScopingTest.php` against real MySQL: (a) creating an `Artist` inside `ModeGate::runAs('demo', ...)` stamps `data_mode = 'demo'`; (b) creating one with no override and no `system_mode` setting stamps `'live'` (the safe default); (c) a plain `Artist::all()`/`find()` call while in one mode never returns a row created in the other mode; (d) `Artist::withoutGlobalScope(\App\Models\Concerns\DataModeScope::class)->get()` returns rows from both modes — depends on T007, T008.

**Checkpoint**: Foundation ready — `data_mode` exists everywhere it needs to, is auto-stamped, and is filtered by default. User story work can now begin.

---

## Phase 3: User Story 1 - Toko baru mencoba sistem dengan data contoh lengkap (Priority: P1) 🎯 MVP

**Goal**: One command populates a complete, realistic, business-rule-valid dummy dataset for "Demo Sakana Fridge."

**Independent Test**: Run `php artisan db:seed --class=SakanaFridgeDemoSeeder`, then verify via `quickstart.md`'s tinker snippet that every requested category of data exists, is interconnected correctly, and is tagged `data_mode = 'demo'`.

### Tests for User Story 1

- [X] T010 [P] [US1] Write `tests/Feature/SakanaFridgeDemoSeederTest.php` (fails until the seeder exists): asserts post-seed counts (1 event/active, 3 artists, 3 categories, 9 products, 27 variants each with `current_stock > 0`, 3 customers, 6 vendors, ≥1 material with 2 vendor prices, ≥1 BOM line, ≥1 completed order, ≥2 preorders spanning different statuses), asserts every created row has `data_mode = 'demo'`, and asserts running the seeder a second time does not change any of these counts (FR-003 idempotency).

### Implementation for User Story 1

- [X] T011 [US1] Create `database/seeders/SakanaFridgeDemoSeeder.php`: wrap the entire `run()` body in `App\Support\ModeGate::runAs('demo', function () { ... })` (research.md Decision 2); inside, `Setting::updateOrCreate(['key' => 'store_name'], ['value' => 'Demo Sakana Fridge', 'type' => 'string', 'group' => 'receipt'])` (store name is administrative, not mode-scoped, per FR-012).
- [X] T012 [US1] In `SakanaFridgeDemoSeeder`, seed 1 `Event` (`status = 'active'`) via `Event::firstOrCreate(['name' => '...'], [...])` for idempotency.
- [X] T013 [US1] In `SakanaFridgeDemoSeeder`, seed 3 `Category` rows, each `firstOrCreate`'d by its unique `code`.
- [X] T014 [US1] In `SakanaFridgeDemoSeeder`, seed 3 `Artist` rows (original names, no real IP per research.md Decision 4), each `firstOrCreate`'d by its unique `code`.
- [X] T015 [US1] In `SakanaFridgeDemoSeeder`, seed 9 `Product` rows (3 per artist, spread across the 3 categories) via `App\Services\ProductCodeGenerator` for `code_prefix`, and 27 `ProductVariant` rows (3 per product) with initial stock applied through `App\Services\StockService::applyMovement()` (`type = 'initial'`) — never a raw `current_stock` write, per Constitution I / CLAUDE.md stock invariants.
- [X] T016 [US1] In `SakanaFridgeDemoSeeder`, seed 3 `Customer` rows, upserted on the `(name, phone)` pair per contracts/demo-seeder-cli.md.
- [X] T017 [US1] In `SakanaFridgeDemoSeeder`, seed 6 `Vendor` rows (3 described as online-store vendors, 3 as offline-store vendors, distinguished in `notes` per data-model.md), each `firstOrCreate`'d by `code`.
- [X] T018 [US1] In `SakanaFridgeDemoSeeder`, seed anime/game-merch `Material` rows (akrilik bening, PVC lembaran, pin blank émail, kain kanvas, stiker vinyl, kertas photocard glossy, gantungan kunci metal, case handphone polos — research.md Decision 4) and `VendorMaterialPrice` rows linking each to one or more of the seeded vendors, with exactly one `is_preferred = true` per material that has 2+ prices.
- [X] T019 [US1] In `SakanaFridgeDemoSeeder`, seed `ProductVariantBomLine` rows for at least one seeded variant, referencing seeded materials, so `GET /variants/{variant}/cost-breakdown` has real data.
- [X] T020 [US1] In `SakanaFridgeDemoSeeder`, open and close one `CashierSession` for the seeded event under the seeded `owner` account, then create several completed sales via `App\Services\OrderService` spanning multiple seeded products/variants — skip entirely if a `demo`-mode order already exists for the seeded event (idempotency per contracts/demo-seeder-cli.md).
- [X] T021 [US1] In `SakanaFridgeDemoSeeder`, create pre-orders via `App\Services\PreorderService` spanning at least two different `status` values (e.g. `dp_paid`, `handed_over`) against seeded customers/variants — skip entirely if a `demo`-mode preorder already exists for the seeded event.
- [X] T022 [US1] Run `php artisan test --filter=SakanaFridgeDemoSeederTest` until green, then actually execute `php artisan db:seed --class=SakanaFridgeDemoSeeder` against real MySQL and manually confirm the counts in `specs/003-seed-demo-live/quickstart.md`'s tinker snippet (Constitution II — verified by running, not by reading the seeder code).

**Checkpoint**: User Story 1 is fully functional and independently testable — the dummy dataset exists and passes all business validation.

---

## Phase 4: User Story 2 - Berpindah antara mode DEMO dan LIVE (Priority: P1)

**Goal**: Owner/admin can switch the store-wide mode; the active mode is always visible; switching correctly re-filters every list.

**Independent Test**: Via `quickstart.md` steps 2–5 — flip `system_mode` through the settings endpoint, confirm seeded DEMO data appears/disappears correctly and non-destructively.

### Tests for User Story 2

- [X] T023 [P] [US2] Write `tests/Feature/SettingsSystemModeTest.php`: `GET /api/v1/settings/features` includes `system_mode` (defaults to `"live"` on a fresh install); `PUT /api/v1/settings` with `system_mode` set to something other than `demo`/`live` fails validation (422); a `cashier`/`inventory` user gets 403 attempting to change `system_mode` via `PUT /settings` (but can still read it via `features()`); a successful `owner`/`admin` change writes an `activity_logs` row in the same transaction (FR-011).
- [X] T024 [P] [US2] Write a Vitest test under `qa-tests/` for `SystemModeBadge.vue` (renders the mode from the settings store, updates reactively) and for the Settings view's mode-toggle control being absent (not just disabled — Constitution III) from the rendered output for a mocked cashier/inventory session.

### Implementation for User Story 2

- [X] T025 [US2] Edit `app/Http/Requests/UpdateSettingsRequest.php`: add a closure to the `settings.*.value` rule (same pattern already used for `store_contact_email`) that fails validation when `key === 'system_mode'` and `value` is not one of `demo`/`live`.
- [X] T026 [US2] Edit `app/Http/Controllers/Api/SettingsController.php`'s `features()` method to add `'system_mode' => \App\Support\ModeGate::current()` to its JSON response.
- [X] T027 [P] [US2] Update `docs/openapi-pos-mvp.yaml`: add `system_mode` (`enum: [demo, live]`) to the `GET /settings/features` response schema, and add a `system_mode` example row to the `PUT /settings` request body schema/example, per `specs/003-seed-demo-live/contracts/settings-system-mode.md`.
- [X] T028 [US2] Extend the existing frontend settings/features Pinia store (the one that already holds `multi_artist_enabled`, under `resources/js/stores/`) with a `systemMode` field populated from `GET /api/v1/settings/features`.
- [X] T029 [P] [US2] Create `resources/js/components/layout/SystemModeBadge.vue`: reads `systemMode` from the store, renders a small always-visible status badge using existing `@theme` design tokens (no raw hex).
- [X] T030 [US2] Mount `SystemModeBadge` in `resources/js/components/layout/AppShell.vue`'s header, next to the existing language toggle — depends on T029.
- [X] T031 [US2] Add a mode-switch control to the existing Settings view, gated by the same `canAccessMenu('settings')`-driven visibility the rest of that screen already uses (hidden entirely for roles without it, per Constitution III), wired to the existing bulk `PUT /api/v1/settings` call — depends on T028.
- [X] T032 [P] [US2] Add the new UI copy keys (badge label, toggle label/confirmation) to both `resources/js/locales/id.json` and `resources/js/locales/en.json`, per the 002-language-toggle precedent already established in this codebase.
- [X] T033 [US2] Run `php artisan test --filter=SettingsSystemModeTest` and `npm test` until green, then manually run `quickstart.md` steps 2–5 in a real browser as `owner` and confirm the badge/toggle behave correctly; separately log in as `kasir01` and confirm the badge is visible but no toggle control exists anywhere in the UI.

**Checkpoint**: User Stories 1 and 2 both independently functional — seeded DEMO data is now visible only in DEMO mode, invisible (but intact) in LIVE mode, and the switch is owner/admin-gated with a visible status badge for everyone.

---

## Phase 5: User Story 3 - Mencegah data DEMO tercampur ke pelaporan bisnis nyata (Priority: P2)

**Goal**: Prove — not just assume — that reports, settlements, and stock figures in LIVE mode are never affected by DEMO activity, including through code paths that might bypass the global scope.

**Independent Test**: Per spec.md — with real LIVE orders and DEMO orders both present, LIVE-mode reports/stock figures must reflect only LIVE data.

### Tests for User Story 3

- [X] T034 [P] [US3] Write `tests/Feature/ReportDataModeIsolationTest.php`: create LIVE orders/stock movements and DEMO orders/stock movements (via `ModeGate::runAs`) against comparable data, then assert `ReportController`'s sales/profit endpoints and a recomputed `ArtistSettlement` in LIVE mode total only the LIVE rows, and that a `ProductVariant.current_stock` figure is unaffected by DEMO-mode stock movements on the same variant's DEMO counterpart.

### Implementation for User Story 3

- [X] T035 [US3] Audit `app/Http/Controllers/Api/ReportController.php`'s hand-rolled `present()`/array-builder queries (CLAUDE.md: this style silently drops fields/rows if a caller forgets something) for any `DB::table()`/raw-query usage on the 20 `HasDataMode` tables that would bypass the Eloquent global scope; patch any found to filter explicitly by `App\Support\ModeGate::current()`.
- [X] T036 [US3] Apply the same audit to `app/Services/SettlementService.php`'s recomputation path — confirm a settlement row's `data_mode` always matches its `event_id`'s mode, and that its aggregation query never joins `order_items` across modes.
- [X] T037 [US3] Implement the cross-mode reference guard from `specs/003-seed-demo-live/research.md` Decision 3 in `app/Services/OrderService.php` and `app/Services/PreorderService.php`: before persisting a line referencing a `ProductVariant`, verify `$variant->data_mode === \App\Support\ModeGate::current()`; if not, throw/return the existing `409` conflict convention (CLAUDE.md API conventions) instead of silently writing a cross-mode reference.
- [X] T038 [US3] Run `php artisan test --filter=ReportDataModeIsolationTest` and the **full** `php artisan test` suite until green; manually execute `quickstart.md` step 5 (create a real LIVE artist, flip to DEMO, confirm it does not appear) in a real browser.

**Checkpoint**: All three user stories independently functional; LIVE financial figures are provably immune to DEMO-mode activity, including through service-layer edge cases, not just the default scoped-query path.

---

## Phase 6: Polish & Cross-Cutting Concerns

**Purpose**: Documentation and whole-suite verification, per Constitution's Documentation & Change Discipline and Testing Standards sections.

- [X] T039 [P] Add a dated note (2026-09-03) to `docs/PRD-POS-Event-Multivendor.md` recording this post-MVP seed-data + DEMO/LIVE addition, matching the existing dated-note convention used for the Vendor/Material/BOM addition (CLAUDE.md "Scope discipline").
- [X] T040 [P] Confirm `CLAUDE.md`'s "Architecture" section needs no further edit beyond the SPECKIT block already updated during `/speckit-plan` — add a short cross-reference under an appropriate existing heading only if a future reader would otherwise miss that `ModeGate`/`HasDataMode` is now an established pattern.
- [X] T041 Run the complete `php artisan test` suite (pre-existing 214+ tests plus all tests added in this feature) and the complete `npm test` suite — zero regressions, per Constitution Principle II.
- [X] T042 Execute `specs/003-seed-demo-live/quickstart.md` end-to-end, exactly as written, in a real running browser against the real API, once as `owner` and once as `kasir01` (Constitution Principle II — a passing test suite is not a substitute for seeing the feature work).
- [X] T043 [P] Re-review the Constitution Check table in `specs/003-seed-demo-live/plan.md` against what was actually built (not what was planned) and record any deviation that needs a Complexity Tracking entry — expected result: none.

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies — start immediately.
- **Foundational (Phase 2)**: Depends on Setup. **Blocks every user story** — the `data_mode` column and `HasDataMode`/`ModeGate` mechanism are shared infrastructure, not story-specific.
- **User Story 1 (Phase 3)**: Depends on Foundational only. Needs `ModeGate::runAs()` (T004) and the trait applied to the models it seeds (T007, T008) to exist first.
- **User Story 2 (Phase 4)**: Depends on Foundational only. Does not depend on US1's seed data existing (it can be verified against an empty DEMO dataset too), though running it *after* US1 makes the manual verification in T033 more convincing.
- **User Story 3 (Phase 5)**: Depends on Foundational, and in practice is verified most meaningfully after US1 (has DEMO data to try to leak) and US2 (has a working mode switch to test against) — sequence it last even though nothing technically blocks starting it earlier.
- **Polish (Phase 6)**: Depends on all three user stories being complete.

### Within Each User Story

- Tests are written first and confirmed failing before implementation (T010 before T011; T023/T024 before T025+; T034 before T035+).
- Seeder/model work before the verification-by-running task that closes each phase.

### Parallel Opportunities

- Within Foundational: T003, T004, T005 can run in parallel; T007 and T008 can run in parallel (disjoint model files) once T006 lands.
- Within US1: T010 (test) can be written in parallel with early scaffolding of T011, but must fail against the real seeder before T011–T021 are considered done.
- Within US2: T023, T024, T027, T029, T032 are all disjoint files and can run in parallel.
- Across user stories: once Foundational is done, US1 and US2 can be staffed in parallel (they touch disjoint files); US3 touches `ReportController`/`SettlementService`/`OrderService`/`PreorderService`, which are read by US1's seeder — sequence US3 after US1/US2 to avoid churn on files the seeder is actively exercising.

---

## Parallel Example: Foundational Phase

```bash
# After T002 (migration) and T006 (trait) land, these can run together:
Task: "Apply HasDataMode to master-data models (T007)"
Task: "Apply HasDataMode to transactional models (T008)"
```

## Parallel Example: User Story 2

```bash
Task: "SettingsSystemModeTest (T023)"
Task: "SystemModeBadge Vitest test (T024)"
Task: "Update docs/openapi-pos-mvp.yaml (T027)"
Task: "Create SystemModeBadge.vue (T029)"
Task: "Add locale keys to id.json/en.json (T032)"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Phase 1 (Setup) → Phase 2 (Foundational) → Phase 3 (US1).
2. **STOP and VALIDATE**: run the seeder against real MySQL, confirm every count in quickstart.md.
3. This alone already delivers value (a demoable, fully-populated install) even before the mode toggle exists — though without US2, seeded data is simply always visible (current behavior, `data_mode` defaults don't hide anything until a `system_mode` setting and scope-aware UI exist to act on it... note: the global scope in Foundational is already live from Phase 2, so technically once Phase 2 lands, LIVE-mode reads already filter out DEMO rows even before US2's UI exists — US1 alone, without US2, means DEMO data exists but is invisible by default with no way to switch into DEMO mode to see it. Recommend shipping US1+US2 together as the MVP.)

### Incremental Delivery

1. Setup + Foundational → shared mechanism ready.
2. US1 + US2 together → MVP: a store can be seeded with demo data AND toggle into DEMO mode to see it. Deploy/demo.
3. US3 → hardens the guarantee that LIVE reports can never be contaminated, including under adversarial/edge-case usage. Deploy/demo.
4. Polish → documentation and whole-suite regression pass.

### Parallel Team Strategy

1. One developer completes Setup + Foundational (Phase 1–2) — this is the narrow waist everything else depends on.
2. Once done: Developer A takes US1 (seeder), Developer B takes US2 (mode switch + UI) — both touch disjoint files.
3. A third developer (or A/B once free) takes US3 after both land, since it audits code paths US1's seeder actively exercises.

---

## Follow-up Tasks (2026-09-03) — Store/User Mode Separation & Sales Lookup

- [X] T044 [P] Add `data_mode`-aware store name storage: seeder and `OrderController` receipt read/write via a mode-qualified key (`store_name` for LIVE, `store_name_demo` for DEMO) instead of one shared row.
- [X] T045 [P] Update `resources/js/views/SettingsView.vue` to read/save the store name under the mode-qualified key matching `settings.systemMode`.
- [X] T046 Update `database/seeders/SakanaFridgeDemoSeeder.php` to write the demo store name to the DEMO-only key, not the shared `store_name` key.
- [X] T047 Migration: add `data_mode` to `users` (default `live`, no global scope — auth must never be mode-gated); stamp new users at creation; leave the 5 base seeded accounts untouched (`live`).
- [X] T048 Filter `UsersController::index()`'s list by `data_mode = ModeGate::current()`.
- [X] T049 [P] Tests: mode switch never breaks an active session/auth; Users list filters correctly by mode; store name never leaks across modes.
- [X] T050 Backend: include artist name per line item in the order/receipt response (`OrderItem`/receipt resource).
- [X] T051 Backend: sales lookup by order number / customer name / artist name (extend `ReportController::sales()`'s `transactions` query or add filters).
- [X] T052 Frontend: `SalesView` search inputs wired to the new lookup.
- [X] T053 Frontend: `ReceiptModal` renders artist name per line item.
- [X] T054 [P] Tests (backend Feature + Vitest) for T050-T053.
- [X] T055 Run full `php artisan test` + `npm test`; browser-verify search + receipt artist names.

## Follow-up Tasks 2 (2026-09-03) — Demo Users, Clickable Lookup, Real Receipt Footer

- [X] T056 Extend `SakanaFridgeDemoSeeder` to create ≥2 demo user accounts linked to existing shared roles (e.g. Kasir Demo, Admin Demo), tagged `data_mode = demo`.
- [X] T057 Backend: add `customer_phone`/`customer_email` (and keep `customer_id`) to `ReportController::sales()`'s `transactions` payload.
- [X] T058 Backend: `OrderController::receipt()` — replace the store-contact-person footer fields with the order's own customer (name/phone/email), null for walk-in.
- [X] T059 Frontend: make the order number and artist name cells in `SalesView` clickable (number → open receipt; artist → set search box to that name); add a small customer-detail popover/modal on customer-name click using data already in the row.
- [X] T060 Frontend: `ReceiptModal` footer renders the order's customer instead of store contact person.
- [X] T061 Tests (backend Feature + Vitest) for T056-T060; run full suites; browser-verify.

## Notes

- [P] tasks touch different files with no unmet dependencies.
- Every task in Phases 2–5 that changes backend behavior has a corresponding test task per Constitution Principle II — this is not optional in this codebase.
- Commit after each task or logical group, per this repo's existing commit granularity (see `git log`).
- Stop at each phase checkpoint and validate before moving on — each user story is independently demoable.
