# Tasks: Owner Reopens a Closed Shift

**Classification**: INTERNAL

**Input**: Design documents from `/specs/063-reopen-closed-shift/`

**Prerequisites**: plan.md, spec.md, research.md (D1–D10), data-model.md, contracts/reopen-session.md, quickstart.md

**Tests**: INCLUDED. The constitution (Principle II) makes backend feature tests on MySQL and frontend tests mandatory, plus a real-browser check for screen changes.

**Organization**: grouped by user story. US1 is the MVP (the Owner can reopen). US2 proves the correction loop (adjust, re-close). US3 adds the visible history.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- Code comments, UI copy and commit messages are in Indonesian (project convention); this file is English like other specs.
- Backend tests: only via `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test ...` (never without the `-e` overrides, see CLAUDE.md).

## Phase 1: Setup

- [x] T001 Confirm the baseline is green before changing anything: run `--filter=CashierSession`, `MenuActionsRouteCoverageTest`, `CloneRegistryDriftTest` and `ForbiddenSurfacesTest` with the two `-e` overrides, and note the result in `specs/063-reopen-closed-shift/research.md` (one line under D9).

---

## Phase 2: Foundational (blocks all user stories)

**Purpose**: the storage, model and registry entries every story depends on.

- [x] T002 Create migration `database/migrations/2026_11_06_000001_create_cashier_session_reopens_table.php` per data-model.md: `id`, `session_id` (FK `cashier_sessions`, restrictOnDelete, indexed), `reopened_by` (FK `users`, restrictOnDelete), `reopened_at`, `reason` (text), `previous_closed_at` (nullable timestamp), `previous_closing_cash`, `previous_expected_cash`, `previous_cash_difference` (nullable decimal 14,2). No `updated_at`, no `data_mode`. Do not rename or reorder any existing migration.
- [x] T003 [P] Create model `app/Models/CashierSessionReopen.php` (fillable, casts for the timestamps and decimals, `session()` and `reopenedBy()` relations). Do NOT use `HasDataMode`. Add a short Indonesian comment saying it is append-only and written only by `CashierSessionReopenService`.
- [x] T004 Add the `reopens()` relation (hasMany, ordered by `id`) to `app/Models/CashierSession.php` (depends on T003).
- [x] T005 [P] Register `cashier_session_reopens` in `app/Services/DemoClone/CloneRegistry.php` right after `cashier_sessions`: `'fks' => ['session_id' => 'cashier_sessions']`, `'via' => 'cashier_sessions.session_id'` (users are a shared parent, so `reopened_by` needs no mapping). Run `CloneRegistryDriftTest` and a clone parity check.
- [x] T006 [P] Add the messages to `lang/id/events_sessions.php` and `lang/en/events_sessions.php`: not authorized to reopen (403), `session_not_closed` (409), `cashier_has_open_session` (409), reason required/length (422).
- [x] T007 Run the migration against `boothpos_test` through the test run and confirm the table exists with the two foreign keys (depends on T002).

**Checkpoint**: storage, model, clone registry and messages ready.

---

## Phase 3: User Story 1 - Owner reopens a closed shift (Priority: P1) — MVP

**Goal**: the Owner role, and only it, can reopen a closed shift with a reason; the shift returns to the plain open shape and the action is audited.

**Independent Test**: close a shift as a cashier, reopen it as Owner with a reason, check status/figures/audit; repeat as admin, cashier, inventory and a custom role and get 403.

### Tests for User Story 1

- [x] T008 [P] [US1] Create `tests/Feature/CashierSessionReopenTest.php` with the permission and state cases from research D9: Owner success (status `open`, `closed_at`/`closing_cash`/`expected_cash`/`cash_difference` NULL, one reopen row holding the previous figures, one `activity_logs` row `session_reopened`); 403 for admin, the shift's own cashier, inventory, and a custom role holding every action on `session` (nothing changes in any of them); 409 `session_not_closed` for an open shift; 422 for a missing, blank, too short and too long reason; 404 for a shift of the other DEMO/LIVE mode; 409 `cashier_has_open_session` when the cashier already has another open shift. Use real MySQL helpers; do not name a helper `put/seed/get/call`.
- [x] T009 [P] [US1] In the same file add the rollback case: force a failure after the shift update (for example a throwing logger binding) and assert neither a changed shift nor a reopen row nor a log row remains.
- [x] T010 [P] [US1] In `tests/Feature/MenuActionsRouteCoverageTest.php` add `'POST api/v1/sessions/{session}/reopen'` to `EXEMPT` with the reason "Owner-only fixed rule, checked in the controller, not a delegable action" and add a test asserting a role granted all `session` actions still gets 403 on that route.

### Implementation for User Story 1

- [x] T011 [US1] Create `app/Services/CashierSessionReopenService.php` with `reopen(CashierSession $session, User $actor, string $reason): CashierSession`. In ONE `DB::transaction`: `lockForUpdate` the shift and re-check `status === 'closed'` (409 `session_not_closed`); `lockForUpdate` the cashier's `User` row and refuse when that user has another `open` shift (409 `cashier_has_open_session`); create the `CashierSessionReopen` row with the figures about to be cleared; update the shift to `open` with `closed_at` and the three closing figures NULL (keep `notes`, `opening_cash`); call `ActivityLogger::log()` with action `session_reopened`, old values = previous figures, new values = status/reason. Typed conflicts use `ValidationException::withMessages(...)->status(409)` like `PaymentService`, or a small typed exception that carries the machine-readable `code`. No call to any settlement or stock service.
- [x] T012 [US1] Add `reopen()` to `app/Http/Controllers/Api/CashierSessionController.php`: reject unless `$request->user()->role?->isOwner()` (403 with the new message, before revealing any state); validate `reason` (required, string, trimmed, 3–500); delegate to the service; return `CashierSessionQuery::detail()` of the reopened shift. Map the service's typed conflicts to 409 with `code`.
- [x] T013 [US1] Register `POST /sessions/{session}/reopen` in `routes/api.php` next to the other session routes with `->whereNumber('session')` and no `ability` middleware (see T010). Confirm `ForbiddenSurfacesTest` stays green for an Owner and for a seller-scoped non-Owner.
- [x] T014 [US1] Run `CashierSessionReopenTest`, `MenuActionsRouteCoverageTest`, `CloneRegistryDriftTest`, `ForbiddenSurfacesTest` and the existing `CashierSession*` tests with the two `-e` overrides and record the counts in the final report.

**Checkpoint**: the Owner can reopen through the API, everyone else is refused, the audit exists. This is the MVP.

---

## Phase 4: User Story 2 - Adjust and re-close with correct figures (Priority: P1)

**Goal**: once reopened, the shift behaves as an open shift; closing it again recomputes expected cash and difference, and earlier figures stay in the audit trail.

**Independent Test**: reopen, record a cash payment into the shift, close with a new count, confirm the recomputed figures and that the first close's figures are still on the reopen row.

### Tests for User Story 2

- [x] T015 [P] [US2] In `tests/Feature/CashierSessionReopenTest.php` add: reopen → cashier records a cash payment (it lands in the reopened shift because it is their only open shift) → `POST /sessions/{id}/close` recomputes `expected_cash` and `cash_difference` from current data; the reopen row still holds the FIRST close's figures; a second reopen adds a second row and leaves the first untouched (append-only); a cash payment of the reopened shift can now be deleted by owner/admin, and could not before the reopen.
- [x] T016 [P] [US2] Add the isolation case (FR-008, FR-009): with a closed event and a finalised seller settlement, snapshot the seller-recap rows, settlement rows, stock levels and order/payment totals, reopen, and assert all of them are identical afterwards; assert reopen is allowed for a shift whose event is ended.

### Implementation for User Story 2

- [x] T017 [US2] Verify, and fix only if the tests show a gap, that `CashierSessionController::close()` and `CashierSessionQuery::expectedCash()` need no change for a reopened shift. Expected: none. If a gap appears, record it in research.md D1 and fix it in the narrowest place.
- [x] T018 [US2] Resolve the ASSUMPTION in research D5: add a test that a POS sale (`POST /orders`) under a reopened shift of an ended event succeeds or is refused, and record the observed result in research.md D5 (replace the word ASSUMPTION with the verified fact).

**Checkpoint**: the correction loop is proven end to end at the API level.

---

## Phase 5: User Story 3 - Visibility and audit of reopened shifts (Priority: P2)

**Goal**: the Session History screen offers the Owner a "Reopen shift" action and shows every reopen, who, when, why and the figures before it; the list marks reopened shifts.

**Independent Test**: as Owner reopen and re-close a shift in the browser, open its detail and see each reopen with actor, time, reason and previous figures; a cashier sees the history of their own shift but no button.

### Tests for User Story 3

- [x] T019 [P] [US3] In `tests/Feature/CashierSessionReopenTest.php` add read-model cases: `GET /sessions/{id}` returns `can_reopen` true only for the Owner on a closed shift (false for admin, cashier, and for an open shift) and `reopens[]` oldest first with the previous figures as two-decimal strings; the shift's own cashier and owner/admin see `reopens[]`, another cashier gets 403 as today; `GET /sessions` rows carry `reopen_count`; a query-count test proves the list does not add a query per row (warm up once first, per CLAUDE.md).
- [x] T020 [P] [US3] Create `qa-tests/component/SessionsHistoryReopen.test.js` (component tests live in `qa-tests/component/`, next to `SessionsHistory.test.js`; also add `reopenSession` to that file's API mock) (Vitest, API mocked): the button shows only when `can_reopen`; submitting without a reason is blocked client-side; success refreshes the detail and the list; a 409 shows the server message and keeps the dialog usable; the history section renders each entry; the list shows the "Reopened" marker for `reopen_count > 0`.

### Implementation for User Story 3

- [x] T021 [US3] Extend `app/Services/CashierSessionQuery.php`: `detail()` adds `can_reopen` (Owner and closed, computed from the viewer passed in — add the viewer parameter and update both callers) and `reopens[]` (eager-load `reopens.reopenedBy:id,name`, money as two-decimal strings, ISO 8601 dates); `list()` adds `withCount('reopens')` and `reopen_count` per row. Keep `reopens` an empty array, not omitted.
- [x] T022 [P] [US3] Add `reopenSession(id, payload)` to `resources/js/api/sessions.js` (POST `/sessions/${id}/reopen`).
- [x] T023 [US3] Update `resources/js/views/SessionsHistoryView.vue`: a "Reopen shift" button in the detail drawer shown only when `detail.can_reopen`; a dialog with a required reason field and a warning that the cashier's new sales and payments attach to this shift until it is closed again; on success refresh the detail and the list through the existing load functions; a "Reopen history" section listing each entry; a "Reopened" marker on list rows using `reopen_count`. Use design tokens only, `BaseTooltip`/existing modal components, and the central error handler (409/403/422).
- [x] T024 [P] [US3] Add the Indonesian and English keys (button, dialog title, reason label and validation, warning, history headings, "Reopened" marker) under `sessions_history` in `resources/js/locales/id.json` and `resources/js/locales/en.json`; make sure `qa-tests/unit/localeKeys.test.js` passes and no key is repeated within a section.
- [x] T025 [US3] Run `npx vitest run --exclude '.claude/**'` and `npm run build`; record the counts.

**Checkpoint**: the whole flow is usable from the screen and auditable.

---

## Phase 6: Polish and cross-cutting

- [x] T026 [P] Update `docs/openapi-pos-mvp.yaml` in the same commit as the code: the new `POST /sessions/{id}/reopen` with its 200/403/404/409/422 responses, `can_reopen` and `reopens[]` on `GET /sessions/{id}`, `reopen_count` on `GET /sessions`, per `contracts/reopen-session.md`.
- [x] T027 [P] Add a dated note (2026-11-06) in `docs/PRD-POS-Event-Multivendor.md` where cashier-shift closing is described, stating that a closed shift can now be reopened by the Owner and that this is a logged exception to the earlier immutability wording (do not rewrite the old text). Add a short section to `CLAUDE.md` describing the rules a future change must not break (Owner-only and exempt from `MenuActions`, restore-to-open-shape, append-only table, no settlement side effects).
- [x] T028 [P] Add a line to `docs/RUNBOOK.md` on what an Owner does to correct a closed shift (reopen, adjust, close again) and that the migration must be run after pulling (stale-schema 503 explained in CLAUDE.md, feature 035).
- [ ] T029 (NOT DONE: playwright and chrome-devtools MCP servers failed to connect in this session; still required before calling the screen verified) Real-browser verification of `quickstart.md` steps 1–10 with the browser console checked, including the phone-width layout of the dialog. If browser tooling is unavailable (it failed to connect in this environment: playwright and chrome-devtools MCP servers), say so explicitly in the final report instead of claiming the screen was verified.
- [x] T030 Final full runs with the two `-e` overrides: the whole PHP suite one run at a time, and the Vitest suite; report exact pass/fail counts. Confirm `git diff` contains no change to `close()` beyond what T017 justified and no write to `cashier_session_reopens` outside the service.

---

## Dependencies and execution order

- **Phase 1 → Phase 2 → stories.** Phase 2 blocks everything.
- **US1** needs T002–T006. T011 → T012 → T013. Its tests (T008–T010) can be written first and must fail before T011.
- **US2** depends on US1 (it needs the endpoint). T015/T016 are tests over existing behaviour, T017/T018 only fix gaps.
- **US3** back end (T021) depends on US1; front end (T022–T024) depends on T021's response shape and can start from `contracts/reopen-session.md`.
- **Polish** after the stories. T026–T028 are independent files.

### Parallel opportunities

- Phase 2: T003, T005, T006 together after T002.
- US1 tests T008, T009, T010 together.
- US2 tests T015, T016 together.
- US3: T019, T020 together; T022 and T024 together with T021.
- Polish: T026, T027, T028 together.

## Implementation strategy

1. **MVP**: Phases 1–3. At that point the Owner can reopen through the API and the audit exists; it is shippable behind no UI if needed.
2. Phase 4 proves the correction loop and settles the one open assumption.
3. Phase 5 makes it usable from the screen.
4. Phase 6 documents and verifies in a real browser.

## Notes

- 30 tasks: Setup 1, Foundational 6, US1 7, US2 4, US3 7, Polish 5.
- Every task names its file or command; there are no tasks without a path.
- Nothing here changes `close()` unless T017's tests prove it necessary.
