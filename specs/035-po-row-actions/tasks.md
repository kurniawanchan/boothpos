---

description: "Task list for feature 035 — purchase order row actions and out-of-date database errors"
---

# Tasks: Purchase Order Row Actions and Out-of-date Database Errors

**Input**: Design documents from `/specs/035-po-row-actions/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/po-rows-and-schema.md, quickstart.md

**Tests**: INCLUDED (Constitution II). Existing PO tests are extended, never loosened.

**Organization**: By user story, in spec order (US1 → US2 → US3 → US4). **US4 is independent of US1–US3** (different files) and may be done first if the schema errors are the most urgent; US2 and US3 build on US1's row-action list.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1..US4

## Conventions to honour

- Comments, `id` copy, commit messages in Indonesian; locale keys in BOTH `en.json` and `id.json` (check the name is free first — a repeated key within a section silently overrides). Backend messages in `lang/{en,id}/*.php`.
- Backend tests on the HOST only: `APP_ENV=testing php artisan test --filter=…` (database `boothpos_test`, wiped each run); never inside the `app` container without the `-e` overrides.
- Business changes go through `PurchaseOrderService` + `ActivityLogger` (audit inside the same transaction); status codes 422/409/403/503 as in the contract.
- The shared row menu is `resources/js/components/preorder/PreorderRowActions.vue` — reuse it, do not fork it.
- **Do not touch the user's dev database** (`boothpos`, container `boothpos-app-1`) from tests or scripts; applying its 4 pending migrations is a separate, user-approved step (T040).

---

## Phase 1: Setup

- [X] T001 Read what this feature changes before editing: `resources/js/views/PurchaseOrdersView.vue` (actions cell, `openEdit`, `rowsLocked`, delete flow), `resources/js/components/preorder/PreorderRowActions.vue`, `resources/js/components/purchaseOrders/PurchaseOrderDetailModal.vue`, `app/Services/PurchaseOrderService.php` (`update`, `delete`), `app/Http/Controllers/Api/{PurchaseOrderController,SettingsController}.php`, `bootstrap/app.php`, `resources/js/{api/client.js,utils/errors.js,stores/settings.js,components/layout/AppShell.vue}`, `resources/js/components/product/{AddBomItemModal,VariantBomModal}.vue`, `tests/Feature/PurchaseOrder*Test.php`, `qa-tests/component/PurchaseOrderForm.test.js`.
- [X] T002 Record the baseline (must be green): `APP_ENV=testing php artisan test --filter='PurchaseOrder|PurchasesReportTest|VariantBom'` and `npx vitest run qa-tests/component/PurchaseOrderForm.test.js qa-tests/component/VariantBomModal.test.js qa-tests/component/AddBomItemModal.test.js`.

**Phase 2 (Foundational)**: none — the stories touch separate files (US4) or build sequentially on the same view (US1 → US2 → US3).

---

## Phase 3: User Story 1 — Detail, Edit and Delete on every purchase order row (Priority: P1) 🎯 MVP

**Goal**: every row, in every status, shows Detail / Edit / Delete; unavailable ones stay visible with a reason; Detail opens the same detail as the number.

**Independent Test**: with POs in all five statuses every row shows the three actions; Detail opens the detail; Delete is disabled with the reason on non-drafts.

### Tests for US1 (write first, expect failures)

- [X] T003 [P] [US1] Create `qa-tests/component/PurchaseOrderRowActions.test.js` (mock `api/purchaseOrders`, `vendors`, `materials`, `artists` like `PurchaseOrderForm.test.js`; list rows without `items`): for rows in draft/ordered/received/paid/cancelled assert each row has buttons **Detail**, **Edit**, **Delete**; Delete is `disabled` (with the "cancel instead" title) for every non-draft and enabled for a draft; clicking **Detail** opens the detail modal (`getPurchaseOrder` called with the row id) exactly like clicking the PO number; the existing status buttons (Mark Ordered/Received/Paid, Cancel) are still present per status; a draft's Delete opens the confirm dialog naming the PO.

### Implementation for US1

- [X] T004 [US1] `resources/js/views/PurchaseOrdersView.vue`: import `PreorderRowActions` and, in the actions cell, render it with `actions = [{key:'detail',label:t('common.detail')}, {key:'edit',label:t('common.edit')}, {key:'delete',label:t('common.delete'),danger:true,disabled: row.status!=='draft',title: row.status!=='draft' ? t('purchase_orders.delete_draft_only') : ''}]`, `primary-key="detail"`, `:max-inline="3"` (so all three stay inline), placed AFTER the existing status buttons (keep them as they are); handler `onRowAction(row, key)` → `openDetail(row)` / `openEdit(row)` / `confirmDelete(row)`; remove the old draft-only Edit/Delete buttons. Keep the PO number click → detail.
- [X] T005 [P] [US1] Locale keys: confirm `common.detail` exists (en line ~335); add under the `purchase_orders` section of `resources/js/locales/en.json` + `id.json`: `delete_draft_only` ("Only draft purchase orders can be deleted. Cancel it instead." / "Hanya purchase order draft yang bisa dihapus. Batalkan saja."), plus any action label missing.
- [X] T006 [US1] Make the actions cell wrap on narrow widths (flex-wrap, token classes only) and verify nothing else in the row overflows; run `npx vitest run qa-tests/component/PurchaseOrderRowActions.test.js qa-tests/component/PurchaseOrderForm.test.js` — all green.

**Checkpoint**: US1 demonstrable and shippable on its own.

---

## Phase 4: User Story 2 — Edit at any stage, within what the status allows (Priority: P1)

**Goal**: Edit opens for every status; draft edits everything; after draft only vendor/seller/notes (lines visible, read-only, with the explanation); seller locked once a BOM uses the order's lines; edits audited.

**Independent Test**: edit a draft (lines change, total recalculated) and a Paid order (only the three fields change; lines locked with hint); both write the right audit rows.

### Tests for US2

- [X] T007 [P] [US2] Create `tests/Feature/PurchaseOrderRowActionsTest.php` (use `Tests\Support\BuildsBomFixtures` helpers where handy; owner user): `PUT` on a **paid/received/ordered/cancelled** PO changes `vendor_id`, `artist_id`, `notes` (200) and **rejects `items`** with 409; a draft accepts `items` and recalculates `subtotal/total_amount`; changing the seller of a PO whose line is used by a BOM → 409 and unchanged; a `purchase_order_updated` log row (old/new `vendor_id`, `total_amount`, line count) is written when the vendor changes or lines are rewritten, none for a notes-only edit; seller assignment/change logs still written once (no duplicate `updated`); cashier → 403; DEMO/LIVE isolation (other mode's PO → 404).
- [X] T008 [P] [US2] Extend `qa-tests/component/PurchaseOrderRowActions.test.js`: Edit on a **Paid** PO opens the drawer with the existing lines **read-only** and the "items locked" hint, Save sends only `vendor_id`/`artist_id`/`notes` (no `items`); Edit on a draft sends `items`; Edit from the list loads the full PO first (`getPurchaseOrder`) so lines are shown; a 409 on seller change is surfaced (message visible, drawer stays open).

### Implementation for US2

- [X] T009 [US2] `app/Services/PurchaseOrderService.php::update()`: capture before-state (`vendor_id`, `total_amount`, line count) and, inside the existing transaction, write ONE `purchase_order_updated` log (`ActivityLogger`, entity `PurchaseOrder`) when the vendor changed or `items` were rewritten; keep `applySellerChange()` logging unchanged; notes-only → no log. Indonesian comment citing FR-008.
- [X] T010 [US2] `resources/js/views/PurchaseOrdersView.vue`: confirm the form for non-drafts shows the lines read-only with the existing hint and that Save's payload omits `items` when `rowsLocked`; surface the 409 seller message in the drawer (field error under Seller) instead of closing; adjust only what T008 shows is missing.
- [X] T011 [US2] Run `PurchaseOrderRowActionsTest` + existing `PurchaseOrder*Test` + the Vitest files from T006/T008; all green.

---

## Phase 5: User Story 3 — Delete safely (Priority: P1)

**Goal**: only drafts are deleted (confirm names the PO, audited); every other state is refused with "Cancel it instead"; payments/BOM usage always block.

**Independent Test**: delete a draft (gone + audit); Delete on ordered/received/paid/cancelled changes nothing and explains why; direct-DB payment/BOM rows block deletion.

### Tests for US3

- [X] T012 [P] [US3] In `tests/Feature/PurchaseOrderRowActionsTest.php`: `DELETE` a draft → 204 + `deleted` log with snapshot; `DELETE` ordered/received/paid/cancelled → 409 with the new "Cancel it instead" message and the row still exists; a draft that (inserted directly) has a payment row → 409 (payment message); a PO with a line referenced by a BOM row (factory) → 409 (BOM message) and nothing is deleted; cashier → 403.
- [X] T013 [P] [US3] In `qa-tests/component/PurchaseOrderRowActions.test.js`: Delete on a draft → confirm dialog shows the PO number → confirm calls `deletePurchaseOrder` and the row disappears after reload; cancel in the dialog calls nothing; Delete on a non-draft is disabled and clicking it does nothing (no API call).

### Implementation for US3

- [X] T014 [US3] `app/Services/PurchaseOrderService.php::delete()` + `lang/{en,id}/purchase_orders.php`: change `only_draft_deletable` to mention Cancel ("Only draft purchase orders can be deleted. Cancel it instead." / Indonesian equivalent); add explicit guards BEFORE deleting — any `payments` row (`payment_blocks_delete`) and any `ProductVariantBomLine` referencing one of the PO's items (`bom_blocks_delete`, query with `withoutGlobalScopes()` like `applySellerChange`) — each a `ValidationException` mapped to 409 by the existing controller; keep the audit log inside the transaction. Add the new lang keys in both languages.
- [X] T015 [US3] `resources/js/views/PurchaseOrdersView.vue`: ensure the delete confirm message names the PO (`delete_po_confirm`) and that a 409 from the server (stale list) is shown via the shared toast and the list reloads; no change if already so — verify with T013.
- [X] T016 [US3] Run `PurchaseOrderRowActionsTest`, `PurchaseOrderTest`, the Vitest PO files; all green.

---

## Phase 6: User Story 4 — Screens work again and a stale database is explained, not exposed (Priority: P1)

**Goal**: (a) a database that is behind produces a plain 503 `schema_outdated` (no SQL) on any API route; (b) owners/admins see a banner via `schema_update_required`; (c) the PO detail dialog and the BOM selector/modal show the error with Retry instead of a blank dialog / a false "no eligible lines".

**Independent Test**: roll back the 034 migrations on the TEST database → PO detail and the BOM selector show the friendly message + Retry, owner sees the banner, cashier does not; re-apply → Retry works with no reload.

### Tests for US4

- [X] T017 [P] [US4] Create `tests/Feature/SchemaOutdatedTest.php`: register a test-only route under `api/v1` (in `setUp`, with the `api` middleware group + auth) that throws a real-shaped `Illuminate\Database\QueryException` with SQLSTATE `42S22`, then one with `42S02`: response is **503** `{code:'schema_outdated', message: <friendly>}`, the body contains NO `SQLSTATE`, no table/column name, and the exception is still reported (assert via `Log`/`Exceptions` fake or the log channel); a different `QueryException` (e.g. SQLSTATE `23000`) is NOT converted; a non-API (web) request is not converted; messages localized (en/id).
- [X] T018 [P] [US4] Create `tests/Feature/SchemaStatusTest.php`: `SchemaStatus::pendingMigrations()` is `[]` on a fully migrated test DB; after deleting one row from `migrations` inside the test (RefreshDatabase transaction) it returns that name; returns `[]` (no exception) when the `migrations` table does not exist; `GET /settings/features` → `schema_update_required` is `true` for owner/admin only while one is pending, `false` for cashier/inventory, and `false` when nothing is pending; the response never lists migration names.
- [X] T019 [P] [US4] Create `qa-tests/component/SchemaUpdateBanner.test.js`: banner renders only when `settings.schemaUpdateRequired` is true (message + how to apply: restart the app in Docker / run `php artisan migrate`), hidden otherwise; `role="alert"`; en/id.
- [X] T020 [P] [US4] Create `qa-tests/component/PurchaseOrderDetailModal.test.js`: when `getPurchaseOrder` rejects the dialog shows the server's message and a **Retry** button (never an empty body); clicking Retry calls the API again and renders the PO on success without closing; a `schema_outdated` ApiError shows the friendly message. Extend `AddBomItemModal.test.js` and `VariantBomModal.test.js`: a failed load shows the error + Retry and does **not** show "no eligible lines" / "no items" empty states; Retry reloads.

### Implementation for US4

- [X] T021 [US4] Create `app/Support/SchemaStatus.php`: `pendingMigrations(): array<int,string>` using the framework `migrator` (`getMigrationFiles` over `database_path('migrations')` + registered paths) minus `migration.repository->getRan()`; `[]` when `repositoryExists()` is false; no caching. Indonesian docblock (why: the app must tell an admin the DB is behind; the container only migrates on start).
- [X] T022 [US4] `bootstrap/app.php` `withExceptions`: add `$exceptions->render(function (QueryException $e, Request $request) { … })` for API requests (`$request->is('api/*') || $request->expectsJson()`) when `(string) $e->getCode()` ∈ {`42S22`,`42S02`} → `response()->json(['message' => __('system.schema_outdated'), 'code' => 'schema_outdated'], 503)`; return `null` for everything else so existing handling and reporting are untouched. Create `lang/en/system.php` and `lang/id/system.php` (`schema_outdated` — generic text, no table/column names; plus `schema_update_banner` keys if backend-served copy is wanted).
- [X] T023 [US4] `app/Http/Controllers/Api/SettingsController.php::features()`: add `'schema_update_required' => $request->user()?->canAccessMenu('settings') && SchemaStatus::pendingMigrations() !== []` (needs the `Request`); keep every existing key.
- [X] T024 [P] [US4] Frontend plumbing: `resources/js/utils/errors.js` add `get isSchemaOutdated()` (`status === 503 && code === 'schema_outdated'`); `resources/js/api/client.js` toast a schema-outdated 503 once (like 409) using the server's friendly message; `resources/js/stores/settings.js` add `schemaUpdateRequired` (from `schema_update_required`, default false).
- [X] T025 [US4] Create `resources/js/components/layout/SchemaUpdateBanner.vue` (warn tokens, `role="alert"`, copy via i18n: "Database update required — restart the app (Docker applies updates on start) or run `php artisan migrate`") and render it in `resources/js/components/layout/AppShell.vue` above `<main>` when `settings.schemaUpdateRequired`.
- [X] T026 [US4] `resources/js/components/purchaseOrders/PurchaseOrderDetailModal.vue`: add `loadError` state; on load failure show the message (friendly for `schema_outdated`) + a **Retry** button that re-runs `load()`; never leave the body empty.
- [X] T027 [US4] `resources/js/components/product/AddBomItemModal.vue` and `VariantBomModal.vue`: add `loadError` + Retry; in the selector render the error state INSTEAD of the "no eligible lines" empty state when the request failed (keep the empty-state text only for a successful empty result).
- [X] T028 [P] [US4] Locale keys (en + id) in a new/appropriate section: `common.retry` (check existing), `schema.update_required_title`, `schema.update_required_hint`, `schema.load_failed` (generic "Could not load. Try again."), banner copy.
- [X] T029 [US4] Run `SchemaOutdatedTest`, `SchemaStatusTest`, the PO/BOM suites and the four Vitest files; all green.

---

## Phase 7: Polish & Cross-cutting

- [X] T030 [P] `docs/openapi-pos-mvp.yaml`: document `503 schema_outdated` (shared response), `schema_update_required` on `GET /settings/features`, the changed `DELETE /purchase-orders/{id}` messages/guards, and the `purchase_order_updated` side effect on `PUT` (per `contracts/po-rows-and-schema.md`).
- [X] T031 [P] `docs/RUNBOOK.md`: short "After pulling changes" note — apply pending database updates (`php artisan migrate`; Docker applies them only when the container STARTS, so restart `app` or `docker compose exec app php artisan migrate`), and what the in-app banner means.
- [X] T032 [P] `CLAUDE.md`: add a "PO row actions and stale-schema handling (feature 035)" section (action availability rules, reuse of `PreorderRowActions`, `SchemaStatus` + the 503 renderer + `schema_update_required` owner/admin-only, never run migrations from a web request, failure states instead of blank dialogs). Keep the Active-feature block already written.
- [X] T033 [P] README "bugs found during execution" entry only if implementation reveals a real defect; otherwise skip.
- [X] T034 Full verification: `APP_ENV=testing php artisan test` (whole suite, host) and `npm test`; both green; report any pre-existing failure separately.
- [X] T035 Real-browser verification per `quickstart.md` on an ISOLATED server + `boothpos_test`: rows in every status show Detail/Edit/Delete; Detail = number click; Delete disabled with reason on non-drafts and works on a draft (confirm names it); Edit on a Paid order (lines locked, hint, only vendor/seller/notes saved); then the **pending-migration scenario** on the TEST database only (`APP_ENV=testing php artisan migrate:rollback --step=3`): PO detail and the BOM selector show the friendly message + Retry (no SQL), owner sees the banner, cashier does not; re-apply (`migrate`) → Retry loads without a page reload. EN ↔ ID, console clean (only deliberate 4xx/503 probe entries), screenshots (no customer data) to `specs/035-po-row-actions/evidence/`.
- [X] T036 (done: the user restarted the container, so `boothpos` has no pending migrations — verified read-only) **Ask the user before touching their dev database**: report that `boothpos` (container `boothpos-app-1`) has 4 pending migrations (031 payment proofs + 034's three) and offer to apply them (`docker compose exec app php artisan migrate`) or to have them restart the container; only with an explicit yes, run it, then confirm in the real browser that the PO detail and the BOM selector (the original two errors) now load. If declined, leave the dev DB untouched and say so.
- [X] T037 Mark every completed task `[X]` here, remove scratch seed scripts and `.playwright-mcp` copies, and prepare the commits (Indonesian messages: docs commit for the spec set, then the feature commit) — commit/PR only on the user's request.

---

## Dependencies & Execution Order

- Phase 1 → user stories. **US1** first (the row-action list). **US2** and **US3** extend the same view/service and run after US1 (sequential: they edit `PurchaseOrdersView.vue` and `PurchaseOrderService.php`). **US4** is independent of US1–US3 (different files; only `PurchaseOrderDetailModal.vue` is shared with nothing else) and can run before or in parallel with them.
- Polish after all stories; T035 needs the built SPA (`npm run build`); T036 is a user-approved operational step and must come last.
- Recommended order: US1 → US2 → US3 → US4 → Polish (or US4 first if the errors are the priority).

### Within a story

Tests first (fail) → service/controller/lang → frontend → run the story's tests.

### Parallel opportunities

- T003 ∥ T005; T007 ∥ T008; T012 ∥ T013; T017 ∥ T018 ∥ T019 ∥ T020; T024 ∥ T028; T030 ∥ T031 ∥ T032 ∥ T033. US4 (T017–T029) can be worked in parallel with US1–US3 by a second worker because no file overlaps.

## Implementation Strategy

- **MVP = Phases 1 + 3 (US1)**: visible Detail/Edit/Delete on every row (Edit/Delete already work server-side for their allowed cases).
- Then US2 (audit + proof that non-draft edit behaves), US3 (explicit delete rules and guards), US4 (schema handling — also the answer to the two reported errors once the dev DB is migrated).
- Stop at each checkpoint and run the phase's tests plus the T002 baseline; never loosen an existing assertion.

## Notes

- The two reported errors need NO code change to disappear — only the 4 pending migrations (T036). US4 exists so a stale database is explained clearly next time.
- Every task names its file(s); if a task reveals a conflict with `docs/` or an existing guarantee, stop and surface it.
