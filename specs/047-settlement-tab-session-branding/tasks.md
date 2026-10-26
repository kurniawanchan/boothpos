**Classification: INTERNAL**

---

description: "Task list for feature 076: new-tab session, seller settlement module, store branding"
---

# Tasks: New-Tab Session, Seller Settlement Module, Store Branding

**Input**: Design documents from `specs/047-settlement-tab-session-branding/` (branch `076-settlement-tab-session-branding`)

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ (`settlements-api.md`, `session-and-branding.md`), quickstart.md

**Tests**: INCLUDED. The spec does not ask for TDD, but the project constitution (Principle II) makes tests mandatory for every backend and frontend change plus a real-browser check for user-facing screens. Within each story, write the tests first and see them fail before implementing.

**Organization**: Grouped by user story. US1 (session) and US4 (branding) do **not** depend on Phase 2; US2 and US3 do.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1 new-tab session · US2 settlement statement · US3 proofs, acknowledgement, payments · US4 branding
- Paths are repository-relative. Code comments, UI copy and commit messages are **Indonesian**; locale keys go in **both** `resources/js/locales/en.json` and `id.json`.

## Safe test commands (CLAUDE.md — mandatory)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=<Name>
npm test
```

Never run `php artisan test` inside the `app` container without the two `-e` flags (it has wiped the dev database twice). Migrations are applied with `docker compose exec app php artisan migrate`; the app never migrates itself.

---

## Phase 1: Setup

- [X] T001 Run the full backend suite (safe command above) and `npm test` on the clean branch and record any pre-existing failures in `specs/047-settlement-tab-session-branding/baseline-notes.md`, so later failures can be attributed correctly

---

## Phase 2: Foundational — settlement prerequisites (blocks US2 and US3 only)

**Purpose**: schema, models, the shared revenue rule, the builder and the clone registry. US1 and US4 can start without this phase.

**⚠️ CRITICAL**: no US2/US3 task starts before T002–T016 are done.

- [X] T002 Create migration `database/migrations/2026_11_20_000001_create_seller_settlements_tables.php` with the five tables in `data-model.md` (`seller_settlements` with `data_mode`, nullable unique `number`, `active_slot` + `UNIQUE(artist_id,event_id,active_slot)`; child tables `seller_settlement_sources|adjustments|acknowledgements|payments` without `data_mode`), money as `decimal(14,2)`, and the raw `DB::statement` CHECK constraints (`chk_sset_revenue`, `chk_sset_net`, `chk_sset_paid_nonneg`, `chk_sset_paid_cap`, `chk_sset_final`, `chk_sadj_amount`, payment `amount > 0`); `down()` drops children first. Do not rename or reorder existing migrations
- [X] T003 Create migration `database/migrations/2026_11_20_000002_add_settlements_menu_key_to_default_roles.php` appending `settlements` to the default **Owner** and **Admin** roles only (template: `2026_10_12_000001_add_purchase_orders_menu_key_to_default_roles.php`, idempotent `up()`, symmetric `down()`)
- [X] T004 [P] Add `'settlements' => 'Settlement'` to `MenuKeys::ALL` in `app/Support/MenuKeys.php`
- [X] T005 [P] Create `app/Models/SellerSettlement.php` (`HasDataMode`, casts, relations to event/artist/sources/adjustments/acknowledgements/payments, accessors `direction`, `balance`, `payment_status`, `display_status`, `currentAcknowledgement()`; Indonesian "why" comments)
- [X] T006 [P] Create `app/Models/SellerSettlementSource.php` (no `HasDataMode`; reaches its mode through the parent)
- [X] T007 [P] Create `app/Models/SellerSettlementAdjustment.php`
- [X] T008 [P] Create `app/Models/SellerSettlementAcknowledgement.php` (scope `current()` = `superseded_at IS NULL`)
- [X] T009 [P] Create `app/Models/SellerSettlementPayment.php`
- [X] T010 Extract the pre-order recognised-revenue query and `PREORDER_FRACTION_EXPR` from `app/Http/Controllers/Api/ReportController.php` into `app/Support/PreorderRecognizedRevenue.php` (takes plain filters, explicit `data_mode`), and make `ReportController::preorderRecognizedRevenueBase()` delegate with **identical SQL** — no behaviour change
- [X] T011 Run the existing report tests unchanged to prove T010 is behaviour-preserving: `--filter=ReportTest`, `ReportCostPosPreorderSplitTest`, `PreorderReportTest`, `PreorderSellerSubtotalsReportTest`, `ReportDataModeIsolationTest`, `StockByArtistReportTest`; fix T010 (not the tests) until green
- [X] T012 Create `app/Services/SellerSettlementBuilder.php`: read-only, for `(event, artist)` returns per-transaction source rows (POS `order_items` of completed orders; pre-order rows via `PreorderRecognizedRevenue`), each rounded **once** to cents with `ReportSplit`, plus totals and memo (uncollected, cost of goods); one grouped query per kind, explicit `data_mode`, no per-row queries
- [X] T013 [P] Create `app/Services/SettlementReconciliation.php`: independent integer-cent proof per `data-model.md` (`sources_sum`, `adjustments_sum`, `expected_net`, `difference`, `pos_check`, `preorder_check`, `payment_check`, `balanced`)
- [X] T014 Register the five tables in `CloneRegistry::tables()` in `app/Services/DemoClone/CloneRegistry.php` after `artist_settlements` (parent first; children with `via`; `number` suffix `-DM`; `client_ref` uuid; `signature_path` / `proof_path` in `files` on the `local` disk)
- [X] T015 [P] Extend `tests/Feature/DemoClone/CloneRegistryDriftTest.php` to cover the new tables (extend the checks, do **not** add them to the exclusion allow-lists)
- [X] T016 [P] Extend `tests/Support/BuildsCloneFixtures.php` and `tests/Feature/DemoClone/LiveToDemoClonerTest.php` with a settlement + source + adjustment + acknowledgement + payment fixture and assert the demo copy (numbers `-DM`, new `client_ref`, LIVE untouched)

**Checkpoint**: schema, models, shared revenue rule, builder and clone registry ready.

---

## Phase 3: User Story 1 — Open a menu in a new tab without logging in again (P1) 🎯

**Goal**: a new tab opens signed in; logout ends every tab; the session still ends when the browser closes; deep links return after login.

**Independent Test**: quickstart section A (middle-click a menu, cross-tab logout, close all windows, deep link while signed out).

### Tests (write first, expect failure)

- [X] T017 [P] [US1] Vitest for the handoff protocol in `qa-tests/unit/tabSession.test.js` (request→token reply accepted only by the waiting tab, 250 ms timeout → `null`, no `BroadcastChannel` → `null` immediately, `logout` message handler, token never written to `localStorage`)
- [X] T018 [P] [US1] Vitest for `safeRedirect` in `qa-tests/unit/safeRedirect.test.js` (accepts `/sales?cashier=2`; rejects `//evil.example`, `https://…`, `/login`, `javascript:`, empty, unknown routes → dashboard)
- [X] T019 [P] [US1] Extend `qa-tests/unit/auth.test.js`: `restore()` adopts a token handed over by another tab, calls `/auth/me`, and a 401 on that token clears it; `logout()` broadcasts
- [X] T020 [P] [US1] Extend `qa-tests/component/AppSidebar.test.js`: every leaf nav item renders an `<a href>` (guards FR-005 against regression)

### Implementation

- [X] T021 [P] [US1] Create `resources/js/utils/tabSession.js` per `contracts/session-and-branding.md` (`adoptTokenFromOtherTabs()`, `broadcastLogout()`, `listenForLogout(cb)`, `HANDOFF_TIMEOUT_MS = 250`; Indonesian comments explaining why `sessionStorage` is kept)
- [X] T022 [P] [US1] Create `resources/js/utils/safeRedirect.js` (single open-redirect guard used by the guard, interceptor and login)
- [X] T023 [US1] Update `resources/js/stores/auth.js`: `restore()` calls the handoff **before** the `getToken()` check (keeps the cached `_restorePromise`); `logout()` broadcasts after clearing
- [X] T024 [US1] Update `resources/js/main.js` to install the logout listener (clear token, empty `auth.user`, hard-redirect to `/login`)
- [X] T025 [US1] Update `resources/js/router/index.js`: signed-out redirect becomes `{ name: 'login', query: { redirect: to.fullPath } }` (omit for `/`)
- [X] T026 [US1] Update `resources/js/api/client.js`: the 401 hard redirect appends the validated current path as `?redirect=`
- [X] T027 [US1] Update `resources/js/views/LoginView.vue`: after login navigate to `safeRedirect(route.query.redirect)` else dashboard; keep the screen Indonesian-only with no `t()` (feature 002)
- [X] T028 [US1] Real-browser verification, quickstart section A steps 1–5, console clean; note the result in the PR description

**Checkpoint**: US1 is shippable alone.

---

## Phase 4: User Story 2 — Settle with each seller using a balanced statement (P1)

**Goal**: an owner/admin creates a draft per (seller, event), sees revenue, deductions, adjustments, reconciliation `0.00`, finalizes it into a frozen, numbered statement.

**Independent Test**: quickstart section C steps 1–5 and 10–11 (statement, parity with Seller Cost, deduction, finalize, source-changed cue, negative net, reopen/delete).

### Tests (write first, expect failure)

- [X] T029 [P] [US2] `tests/Feature/SellerSettlement/SellerSettlementBuilderTest.php`: POS completed only; voided order excluded; cancelled pre-order excluded; rejected-only payments contribute nothing; partly paid pre-order = collected fraction; per-row rounding sums exactly; DEMO rows never leak into LIVE
- [X] T030 [P] [US2] `tests/Feature/SellerSettlement/SellerSettlementReportParityTest.php`: `pos_revenue` equals `GET /reports/artist-profit` `sales_pos` exactly; `preorder_revenue` equals `sales_preorder` within 1 cent × number of pre-order rows (SC-006)
- [X] T031 [P] [US2] `tests/Feature/SellerSettlement/SellerSettlementDraftTest.php`: create (409 `settlement_exists` with `existing_id`), zero-activity preview returns zeros, refresh, hard delete, deduction stored negative, adjustment ±, `422` validation, no endpoint accepts a revenue amount
- [X] T032 [P] [US2] `tests/Feature/SellerSettlement/SellerSettlementFinalizeTest.php`: finalize freezes totals and assigns `STL-YYYYMMDD-NNNN`; tampered total → `409 reconciliation_mismatch`; empty → `409 nothing_to_settle`; later void/payment leaves figures frozen and raises `source_changed`; concurrent create for one pair → exactly one wins; reopen needs no payments; negative net direction wording; DB CHECKs reject an inconsistent row
- [X] T033 [P] [US2] `tests/Feature/SellerSettlement/SellerSettlementAuthorizationTest.php`: every endpoint (incl. list, preview, document, file streams once they exist) returns `403` without the `settlements` menu and `404` for the other data mode; cashier/inventory roles denied
- [X] T034 [P] [US2] `tests/Feature/SellerSettlement/SellerSettlementDeleteGuardTest.php`: deleting an event or an artist that has a settlement → `409`; audit rows written inside the transaction (`activity_logs` entry rolled back with a failed action)

### Backend

- [X] T035 [US2] Create `app/Services/SellerSettlementService.php` — the **only** writer: `create`, `refresh`, `delete`, `addAdjustment` / `updateAdjustment` / `removeAdjustment` (draft), `finalize` (row lock, refresh, reconciliation gate, number generation with `withoutGlobalScope` + `lockForUpdate` + one duplicate-key retry, freeze), `reopen`; every change calls `ActivityLogger` inside the same transaction; typed business errors carry a machine-readable `code`
- [X] T036 [P] [US2] Create `app/Http/Requests/Settlement/SettlementRequest.php` (abstract base: `authorize()` = `canAccessMenu('settlements')`) and `StoreSettlementRequest.php`
- [X] T037 [P] [US2] Create `app/Http/Requests/Settlement/AdjustmentRequest.php` (label ≤ 120, reason required, basis ≤ 160, `0 < |amount|`, 2 dp, kind rules)
- [X] T038 [P] [US2] Create `app/Http/Resources/SellerSettlementResource.php`, `SellerSettlementDetailResource.php`, `SellerSettlementSourceResource.php`, `SellerSettlementAdjustmentResource.php` (money as `number_format((float) $x, 2, '.', '')`; `can.*` computed server-side; eager-load every relation the detail reads — the `relationLoaded` trap — and add an assertion in T031/T032 that no documented field is missing)
- [X] T039 [US2] Create `app/Http/Controllers/Api/SellerSettlementController.php` (thin): `index` (filters + `totals`, paginated envelope), `preview`, `store`, `show` (live `source_changed` for finalized), `refresh`, `destroy`, adjustments CRUD, `finalize`, `reopen`; reads guarded inline by `canAccessMenu('settlements')`
- [X] T040 [US2] Register the routes in `routes/api.php` inside the existing authenticated group (apiResource-style names, no clash with `/reports/artist-settlements`)
- [X] T041 [US2] Add the settlement delete guards (409, Indonesian message in `lang/*`) to `app/Http/Controllers/Api/EventController.php::destroy` and `app/Http/Controllers/Api/ArtistController.php::destroy`
- [X] T042 [US2] Document the US2 endpoints and schemas in `docs/openapi-pos-mvp.yaml` (same commit as the routes)

### Frontend

- [X] T043 [P] [US2] Create `resources/js/api/settlements.js` (list, preview, create, show, refresh, delete, adjustments, finalize, reopen)
- [X] T044 [P] [US2] Add locale keys (`nav.settlements`, `settlements.*` for list, create dialog, statement, reconciliation, adjustments, errors by `code`) to `resources/js/locales/en.json` and `id.json`; `qa-tests/unit/localeKeys.test.js` must stay green
- [X] T045 [US2] Add routes `settlements` and `settlement-detail` (`meta.menuKey: 'settlements'`) in `resources/js/router/index.js`
- [X] T046 [US2] Add the "Settlement" item (icon `ph-handshake`, `menuKey: 'settlements'`) after Reports in `NAV_DEFS` in `resources/js/components/layout/AppSidebar.vue`; hidden for roles without the key
- [X] T047 [P] [US2] Create `resources/js/components/settlement/ReconciliationCard.vue` (shows each check and the `0.00` difference, design tokens only)
- [X] T048 [P] [US2] Create `resources/js/components/settlement/SettlementSourcesTable.vue` (per-transaction rows; source number links to the Sales / Pre-orders screen via the existing deep links)
- [X] T049 [P] [US2] Create `resources/js/components/settlement/SettlementAdjustmentModal.vue` (deduction entered positive; reason and basis fields; central error handler)
- [X] T050 [US2] Create `resources/js/views/SettlementsView.vue` (list via `usePaginatedList`, filters seller/event/status, totals row, "New settlement" dialog with preview)
- [X] T051 [US2] Create `resources/js/views/SettlementDetailView.vue` (statement sections, memo lines, source-changed banner, finalize / reopen / delete; state-invalid actions disabled with a reason from `can.*`)
- [X] T052 [P] [US2] Vitest component tests `qa-tests/component/SettlementsView.test.js` and `SettlementDetailView.test.js` (API mocked: list, filters, banner, disabled-with-reason)
- [X] T053 [US2] Real-browser verification, quickstart section C steps 1–5 and 10–11, console clean

**Checkpoint**: US2 delivers a finalizable, balanced, numbered statement.

---

## Phase 5: User Story 3 — Acknowledgement, payments and printable proofs (P2)

**Goal**: the seller acknowledges (drawn / typed / outside), payments are recorded against the balance, and both documents print or download.

**Independent Test**: quickstart section C steps 6–9 and 12.

### Tests (write first, expect failure)

- [X] T054 [P] [US3] `tests/Feature/SellerSettlement/SellerSettlementAcknowledgementTest.php`: per-method required fields; new acknowledgement supersedes the old (`superseded_at`, reason `replaced`); reopen supersedes (`reopened`); only finalized; signature stored on the private disk
- [X] T055 [P] [US3] `tests/Feature/SellerSettlement/SellerSettlementPaymentTest.php`: `balance_new = balance_old − amount`; over-payment `422` naming the maximum; already settled `409`; same `client_ref` + same settlement → `200` replay, no new row; ref on another settlement → `422`; net-zero refuses payment; negative-net direction records money received; delete recomputes the cache; the `chk_sset_paid_cap` CHECK is the backstop
- [X] T056 [P] [US3] `tests/Feature/SellerSettlement/SellerSettlementPostFinalizationTest.php`: adjustment on a finalized settlement supersedes the acknowledgement (`adjusted`); refused when net would drop below paid (`409 net_below_paid`) or flip direction while paid > 0 (`409 direction_locked`); only `kind=adjustment` accepted after finalize
- [X] T057 [P] [US3] `tests/Feature/SellerSettlement/SellerSettlementDocumentTest.php`: document payload has store identity and `powered_by`, **no customer names/phone/email**, no `cost_of_goods`/`gross_profit` unless `include_cost=1`; `409 not_finalized` for a draft; payment-proof payload shows balance after that payment
- [X] T058 [P] [US3] `tests/Feature/SellerSettlement/SellerSettlementFilesTest.php`: signature and proof stream only through the authorizing endpoint with `Cache-Control: private, no-store`; `403` without the menu key; files are on the private `local` disk, never under `public/`

### Backend

- [X] T059 [US3] Extend `app/Services/SellerSettlementService.php` (same file as T035, sequential): `acknowledge` (supersede previous, store signature PNG on `local`), `addPayment` / `deletePayment` (row lock, idempotent `client_ref`, cap at balance, recompute `paid_amount` cache), post-finalization `addAdjustment` rules, supersede acknowledgement on `reopen`/adjust; audit actions per `contracts/settlements-api.md`
- [X] T060 [P] [US3] Create `app/Http/Requests/Settlement/AcknowledgeRequest.php` (signature PNG ≤ 512 KB) and `SettlementPaymentRequest.php` (uuid `client_ref`, amount, date, method enum, optional proof JPEG/PNG/PDF within the existing proof cap)
- [X] T061 [P] [US3] Create `app/Http/Resources/SellerSettlementAcknowledgementResource.php` and `SellerSettlementPaymentResource.php` (`has_signature`, `has_proof`, recorder names — only when eager-loaded relations are present, asserted by tests)
- [X] T062 [P] [US3] Create `app/Support/BuildsSettlementDocument.php`: assembles store identity (name, logo URL via `ImageUploadService::url`, contact, address), seller, event, number, statement lines, reconciliation, acknowledgement block, `AppName::current()`; source rows carry only order/pre-order numbers
- [X] T063 [US3] Extend `SellerSettlementController` with `acknowledge`, `signature`, `storePayment`, `destroyPayment`, `paymentProof`, `document`, `paymentDocument`
- [X] T064 [US3] Register the US3 routes in `routes/api.php`
- [X] T065 [US3] Document the US3 endpoints and schemas in `docs/openapi-pos-mvp.yaml`
- [X] T066 [US3] Extend the clone test from T016 to assert signature and proof **files are copied, never shared**, and that wiping demo deletes a file only when no row in either mode references it (`tests/Feature/DemoClone/LiveToDemoClonerTest.php`)

### Frontend

- [X] T067 [P] [US3] Create `resources/js/components/settlement/SignaturePad.vue` (`<canvas>` + pointer events, clear, empty check, exports a PNG `Blob`; no new dependency)
- [X] T068 [P] [US3] Create `resources/js/components/settlement/SettlementAcknowledgeModal.vue` (three tabs: drawn signature / typed name / recorded outside the system)
- [X] T069 [P] [US3] Create `resources/js/components/settlement/AddSettlementPaymentModal.vue` (one `client_ref` per dialog open, reused on retry; proof capture reuses `components/payment/ProofCapture.vue`)
- [X] T070 [P] [US3] Create `resources/js/components/settlement/SettlementProofDocument.vue` (A4 layout; cost/profit block only when `include_cost`; signature image converted to a data URL before capture)
- [X] T071 [P] [US3] Create `resources/js/components/settlement/SettlementPaymentProofDocument.vue`
- [X] T072 [US3] Extend `resources/js/api/settlements.js` (acknowledge, signature blob, payments, proof blob, documents)
- [X] T073 [US3] Add the US3 locale keys to `resources/js/locales/en.json` and `id.json`
- [X] T074 [US3] Integrate into `resources/js/views/SettlementDetailView.vue`: acknowledgement block + history, payments list, progress and balance, "Print / download proof" with PDF and PNG through `utils/pdfCapture.js` (dynamic import, images swapped by `src` only), "include cost & profit" checkbox (default off)
- [X] T075 [P] [US3] Vitest `qa-tests/component/SignaturePad.test.js`, `SettlementProofDocument.test.js` (cost block absent by default, no customer fields), `AddSettlementPaymentModal.test.js` (same `client_ref` on retry, regenerated on reopen)
- [X] T076 [US3] Real-browser verification, quickstart section C steps 6–9 and 12, console clean; confirm the signature and document code appear as separate chunks in `npm run build` output

**Checkpoint**: US3 closes the loop with signed, printable, payable settlements.

---

## Phase 6: User Story 4 — Page title, sidebar brand and favicon follow the store identity (P3)

**Goal**: tab title `<store name> · <app name>`, sidebar logo + store name, favicon = store logo, with safe fallbacks and live updates.

**Independent Test**: quickstart section B.

### Tests (write first, expect failure)

- [X] T077 [P] [US4] `tests/Feature/StoreLogoFeaturesTest.php`: `GET /settings/features` returns `store_logo_url` (resolved URL / `null`) for every signed-in role; follows the active mode's `store_name`
- [X] T078 [P] [US4] Vitest `qa-tests/unit/useBranding.test.js`: title with and without store name, reacts to store changes, favicon falls back on image error, default restored when logo cleared
- [X] T079 [P] [US4] Extend `qa-tests/component/AppSidebar.test.js`: logo + store name + tier label; blank name → app name; `@error` → default storefront glyph; long name truncated with full `title`

### Implementation

- [X] T080 [US4] Add `store_logo_url` (via `ImageUploadService::url(Setting::get('store_logo_path'))`) to `app/Http/Controllers/Api/SettingsController.php::features()`; update the `features` schema in `docs/openapi-pos-mvp.yaml`
- [X] T081 [P] [US4] Create `resources/js/utils/logoFavicon.js` (draw to a 64×64 "contain" canvas → PNG data URL; resolve `null` on any failure)
- [X] T082 [US4] Update `resources/js/stores/settings.js`: `storeLogoUrl` state, loaded from `features`; actions `setStoreName()` and `setStoreLogoUrl()`
- [X] T083 [US4] Create `resources/js/composables/useBranding.js` (reactive `document.title` and `<link rel="icon">`, default fallback, no flicker on route change)
- [X] T084 [US4] Mount `useBranding()` once in `resources/js/App.vue` (also runs on the login screen with defaults)
- [X] T085 [US4] Update the brand block in `resources/js/components/layout/AppSidebar.vue` (logo `<img>` 32 px `object-contain` with `@error` fallback, store name truncated with `title`, tier label kept; design tokens only) — same file as T046, do after it
- [X] T086 [US4] Update `resources/js/views/SettingsView.vue` to call `settings.setStoreName()` / `setStoreLogoUrl()` after a successful save so the title, sidebar and favicon update with no reload (it currently keeps its own local `storeLogoUrl` only)
- [X] T087 [US4] Real-browser verification, quickstart section B steps 1–3, console clean

**Checkpoint**: all four stories independently working.

---

## Phase 7: Polish and cross-cutting

- [X] T088 [P] Add a dated note to `docs/PRD-POS-Event-Multivendor.md`: seller settlement module added post-MVP at the product owner's request (2026-10-26), no commission taken, scope one event, recap (feature 040) unchanged
- [X] T089 [P] Add a "Seller settlement" section to `CLAUDE.md` (rules a future change must not break: single writer, reconciliation gate, no commission, separate from `artist_settlements`, clone registration, token handoff constraints) and move the active-plan pointer to "Previous feature" when merged
- [X] T090 [P] Add a migration reminder for `2026_11_20_*` to `docs/RUNBOOK.md` §"Setelah menarik perubahan kode"
- [X] T091 Run the full backend suite with the safe command and `npm test`; compare with `baseline-notes.md`; every failure is either pre-existing or fixed
- [X] T092 Run `npm run build`; confirm `html2canvas`/`jsPDF`, `SignaturePad` and the settlement documents are separate chunks and the initial bundle did not grow beyond the new views
- [X] T093 Run the security review skill on the diff (focus: authorization on all settlement endpoints and file streams, token handoff, `redirect` validation, file upload limits and MIME checks, PII in documents)
- [X] T094 Execute `quickstart.md` sections A–D end to end in a real browser with the console open; tick `specs/047-settlement-tab-session-branding/checklists/requirements.md` evidence in the PR description

---

## Dependencies and execution order

```text
Phase 1 (T001)
  ├─► Phase 2 (T002–T016) ──► US2 (Phase 4) ──► US3 (Phase 5)
  ├─► US1 (Phase 3)      independent of Phase 2
  └─► US4 (Phase 6)      independent of Phase 2
Phase 7 after the stories you ship
```

- **Within Phase 2**: T002 → T005–T009 (models need the tables to test) · T010 → T011 → T012 → T013 · T014 → T015/T016.
- **Shared files** (never parallel with each other): `SellerSettlementService.php` (T035, T059), `SellerSettlementController.php` (T039, T063), `routes/api.php` (T040, T064), `docs/openapi-pos-mvp.yaml` (T042, T065, T080), `AppSidebar.vue` (T046, T085), `settlements.js` (T043, T072), `locales/en.json` / `id.json` (T044, T073), `SettlementDetailView.vue` (T051, T074).
- **US3 requires US2** (it extends the same service, controller and detail view). **US1 and US4 require nothing else.**

## Parallel examples

```text
After T001, three people in parallel:
  A: US1  T017–T020 [P] → T021, T022 [P] → T023–T028
  B: US4  T077–T079 [P] → T080, T081 → T082–T087       (do T085 after T046 if US2 is in flight)
  C: Phase 2  T002 → T004–T009 [P] → T010 → T011 → T012, T013 [P] → T014 → T015, T016 [P]

US2 test batch (all different files):  T029 T030 T031 T032 T033 T034
US2 frontend batch:                    T043 T044 T047 T048 T049
US3 component batch:                   T067 T068 T069 T070 T071
```

## Implementation strategy

1. **Fast value, low risk**: ship **US1** first (frontend only, no migration), then **US4** — each is a small independent PR.
2. **Core business value**: Phase 2 → **US2** → **US3**. US2 alone already gives a balanced, frozen statement; US3 adds the acknowledgement, payouts and printable proofs the request asked for. Do not release US2 to sellers' hands before US3 if proofs are the goal; releasing US2 internally first is fine.
3. Each story ends with its real-browser verification task; do not mark a story done on passing unit tests alone (constitution II).
4. Commit per task or per small group, Indonesian messages; no push (no remote is configured).
