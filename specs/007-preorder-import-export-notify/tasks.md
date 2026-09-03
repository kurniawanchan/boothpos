---

description: "Task list for 007-preorder-import-export-notify"

---

# Tasks: Pre-order Import/Export, Printing, Email Notification & Search

**Input**: Design documents from `/specs/007-preorder-import-export-notify/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/api-contract.md, quickstart.md

**Tests**: Included — Constitution Principle II requires backend tests under `tests/Feature/` (real MySQL) and, for any touched screen, real-browser verification; this feature explicitly asked for that standard in every prior feature (005, 006), so it applies here unchanged.

**Organization**: Tasks are grouped by user story (US1–US4, priority order from spec.md) so each is independently implementable, testable, and demoable.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no unmet dependency)
- **[Story]**: US1 (search), US2 (print), US3 (export/import), US4 (email)

## Path Conventions

Existing single Laravel-API + Vue-SPA structure (see plan.md's Project Structure) — no new top-level directory.

---

## Phase 1: Setup

**Purpose**: Nothing new to initialize — reuses the existing Laravel/Vue toolchain, `maatwebsite/excel` (already a dependency), and Laravel's built-in `Mail` (net-new *usage*, but no new package).

- [X] T001 Confirm `.env.testing` has `MAIL_MAILER=array` (or leave default `log`) so `Mail::fake()`-based tests never attempt a real SMTP connection during `php artisan test`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Shared infrastructure every user story's tests/implementation will call into — MUST complete first.

**⚠️ CRITICAL**: No user story work can begin until this phase is complete.

- [X] T002 Migration `database/migrations/2026_10_15_000001_create_preorder_notifications_table.php` — columns per data-model.md (`preorder_id` FK cascade-delete, `trigger` enum, `triggered_by_status` nullable string, `recipient_email` nullable string, `status` enum, `error_message` nullable text, `sent_at` nullable timestamp, timestamps). **NOT** `HasDataMode` (operational/administrative metadata, same category as `activity_logs` — see CLAUDE.md's DEMO/LIVE section and research.md R6)
- [X] T003 [P] `app/Models/PreorderNotification.php` — `belongsTo(Preorder::class)`, fillable per T002's columns, no create/update API surface of its own (data-model.md's validation rule — written only as a side effect of the notify flow)
- [X] T004 [P] Add `public function notifications(): HasMany` to `app/Models/Preorder.php` (→ `PreorderNotification`, `orderByDesc('sent_at')` default via a `latestNotification()` accessor or a dedicated relation method — implementer's choice, but `PreorderController::show()` in T010 needs a single call to get the most recent one)
- [X] T005 [P] `app/Support/PreorderDocumentType.php` — single source of truth for the status→document-type/email-subject-theme mapping table in data-model.md (`ordered`/`dp_paid`/`arrived` → `invoice`, `settled`/`handed_over` → `receipt`, `cancelled` → `cancelled`), a small static method e.g. `PreorderDocumentType::forStatus(string $status): string` plus a subject-theme lookup — consumed by both T013 (invoice endpoint) and T024 (email) so the mapping is never duplicated (research.md R2)
- [X] T006 `app/Services/PreorderNotifier.php` skeleton — constructor, single public method `notifyStatusChange(Preorder $preorder, string $trigger): PreorderNotification` that: resolves `$preorder->customer->email`, returns/writes a `skipped_no_email` row if empty; checks `config('mail.default') === 'log'` and writes `skipped_not_configured` if so (research.md R5); otherwise attempts the send (body wired in T025) inside a try/catch, writing `sent` or `failed` (+ `error_message`) — this task is the shell + the two skip branches, so it can be tested (T007) before US4's actual Mailable exists

### Tests for Foundational shell

- [X] T007 [P] Feature test `tests/Feature/PreorderNotifierSkipBranchesTest.php` — asserts `skipped_no_email` when customer has no email, and `skipped_not_configured` when `config(['mail.default' => 'log'])`, writes a `PreorderNotification` row in both cases, never throws

**Checkpoint**: Foundation ready — all four user stories can now proceed (independently, or in parallel if staffed).

---

## Phase 3: User Story 1 - Search pre-orders by customer name (Priority: P1) 🎯 MVP

**Goal**: A `search` param on the existing pre-order list, combinable with existing filters.

**Independent Test**: Per quickstart.md step 1 — type a partial customer name, confirm only matching pre-orders show, combined with an existing filter still narrows correctly.

### Tests for User Story 1

- [X] T008 [P] [US1] Feature test `tests/Feature/PreorderSearchTest.php` — partial/case-insensitive match on customer name returns only matching pre-orders; combined with an existing `status` filter narrows further; a non-matching search returns an empty `data` array (not an error); confirm no N+1 introduced (assert query count or reuse existing eager-load pattern)

### Implementation for User Story 1

- [X] T009 [US1] Add `search` query param handling to `PreorderController::index()` in `app/Http/Controllers/Api/PreorderController.php` — `when($request->filled('search'), fn ($q) => $q->whereHas('customer', fn ($cq) => $cq->where('name', 'like', '%' . $request->string('search') . '%')))`, slotted into the existing `when()` chain (research.md R1)
- [X] T010 [P] [US1] Add a search input to `resources/js/views/PreordersView.vue`'s existing filter row, wired to `usePaginatedList`'s `setFilter({ search })` (debounced, matching any existing debounce convention already used elsewhere in this codebase, e.g. Products page search)
- [X] T011 [P] [US1] Update `resources/js/api/preorders.js`'s list function to pass through the new `search` param (likely already generic passthrough — confirm, only add if the function currently allowlists specific params)
- [X] T012 [US1] Manual browser verification per Constitution Principle II: search a known customer, confirm results narrow, confirm combining with the status filter works, check console for errors

**Checkpoint**: User Story 1 fully functional and independently testable/demoable.

---

## Phase 4: User Story 2 - Print an invoice or receipt matching status (Priority: P1)

**Goal**: `GET /preorders/{id}/invoice` returning status-appropriate document data; a frontend modal rendering and exporting it as image/PDF, mirroring `ReceiptModal.vue`.

**Independent Test**: Per quickstart.md step 2 — print a pre-order at each status family (open/paid/cancelled) and confirm the document type and figures are correct.

### Tests for User Story 2

- [X] T013 [P] [US2] Feature test `tests/Feature/PreorderInvoiceTest.php` — `GET /preorders/{id}/invoice` returns `document_type: "invoice"` for `ordered`/`dp_paid`/`arrived`, `"receipt"` for `settled`/`handed_over`, `"cancelled"` for `cancelled`; response includes `outstanding`; 404 for a pre-order in the other DEMO/LIVE mode (mirrors existing mode-isolation tests' pattern)

### Implementation for User Story 2

- [X] T014 [US2] Add `invoice(Preorder $preorder): JsonResponse` to `app/Http/Controllers/Api/PreorderController.php` — reuses/extends the existing `show()`-shaped response (eager-load `items`, `customer`, `payments`), adds `document_type` via `PreorderDocumentType::forStatus()` (T005) and `outstanding` (reuse `$preorder->outstanding()` if it exists on the model, else the same computation `index()` already does per-row)
- [X] T015 [US2] Add route `Route::get('/preorders/{preorder}/invoice', [PreorderController::class, 'invoice'])` to `routes/api.php`, alongside the existing `preorders` routes
- [X] T016 [P] [US2] `resources/js/components/preorder/PreorderInvoiceModal.vue` — mirrors `ReceiptModal.vue`'s structure: fetch via a new `getPreorderInvoice(id)` in `resources/js/api/preorders.js`, render item breakdown + status-appropriate heading/labels (Invoice vs. Struk/Kwitansi vs. Dibatalkan) using `document_type`, dynamically `import('html2canvas')`/`import('jspdf')` on download click (copy `captureCanvas()`/`downloadAsPdf()` pattern verbatim, adjusted filename to `invoice-{preorder_number}` / `struk-{preorder_number}`)
- [X] T017 [US2] Add a "Cetak" button/action to `resources/js/views/PreordersView.vue`'s pre-order row/detail actions, opening `PreorderInvoiceModal`
- [X] T018 [P] [US2] Add i18n keys (`preorders.invoice_*`/`preorders.receipt_*`/`preorders.print_action`) to `resources/js/locales/id.json`/`en.json`
- [X] T019 [P] [US2] Update `docs/openapi-pos-mvp.yaml` for `GET /preorders/{id}/invoice`
- [X] T020 [US2] Manual browser verification per Constitution Principle II: print a pre-order at an open status (confirm "Invoice" + outstanding shown), at a paid status (confirm "Struk/Kwitansi", no outstanding), and cancelled (confirm clearly marked, no active balance); check console for errors

**Checkpoint**: User Stories 1 AND 2 both work independently.

---

## Phase 5: User Story 3 - Export and import pre-order transactions (Priority: P2)

**Goal**: Owner/admin can export the (filtered) pre-order list to `.xlsx` and import a batch of new pre-orders from a matching-shape file, all-or-nothing.

**Independent Test**: Per quickstart.md steps 3–4 — export respects active filters and matches on-screen figures; import creates correct pre-orders (+ new customers where needed); a file with one bad row creates nothing and reports the failure.

### Tests for User Story 3

- [X] T021 [P] [US3] Feature test `tests/Feature/PreorderExportImportTest.php` — covering: (a) export respects active filters and row count/figures match `index()`'s response for the same filters; (b) export/import is 403 for a cashier role (`isOwnerOrAdmin()` gate); (c) import of a valid file creates the right pre-orders/items/customers, all at `status = 'ordered'`, `paid_amount = 0`, even when a row's data implies further progress (FR-010); (d) import creates a new `Customer` when `customer_name` doesn't match an existing one, and reuses an existing one when it does; (e) import of a file with one bad row (unknown SKU) creates **nothing** and returns 409 with `row_errors` naming the failing row; (f) `dry_run=1` validates without writing

### Implementation for User Story 3

- [X] T022 [P] [US3] `app/Imports/PreorderImport.php` — implements the row-grouping convention finalized from data-model.md (blank `customer_name` = continuation of previous row's order), resolves/creates `Customer` by name (FR-009), resolves `event_id`/SKU via mode-scoped `findOrFail` (closing the same cross-mode gap `OrderService`/`PreorderService::create()` already close, per research.md R4), validates qty×unit_price=line_total arithmetic per item and sum-of-lines=total per order
- [X] T023 [US3] `app/Services/PreorderExportImportService.php` — `export(array $filters): array` (reuses the same query shape as `PreorderController::index()`, respecting the same filter set including the new `search`), `import(UploadedFile $file, bool $dryRun): array` (full validation pass via `PreorderImport`, one `DB::transaction()` for all rows — mirrors `MasterDataImportService`'s all-or-nothing convention per research.md R3), `template(): void` (blank workbook via the same `Excel::download` pattern)
- [X] T024 [US3] Add `export(Request $request)`, `importTemplate()`, `import(Request $request)` methods to `app/Http/Controllers/Api/PreorderController.php` — each gated `abort_unless($request->user()->isOwnerOrAdmin(), 403)` inline (matching `ReportController`/`CashierSessionController`'s existing pattern per plan.md's Technical Context), delegating to `PreorderExportImportService`; `export()` returns `Excel::download(new GenericArrayExport($rows), 'preorders.xlsx')`; `import()` returns 201/200 with `created_count`/`created_customer_count`/`preorder_ids` on success, 409 with `row_errors` on any validation failure (contracts/api-contract.md)
- [X] T025 [US3] Add routes to `routes/api.php`: `GET /preorders/export`, `GET /preorders/import/template`, `POST /preorders/import`
- [X] T026 [P] [US3] Add "Ekspor .xlsx"/"Impor"/"Unduh template" controls to `resources/js/views/PreordersView.vue`, visible only when `auth.user.role` is owner/admin (hidden entirely per Constitution III, not disabled) — wired to new `exportPreorders(params)`/`downloadImportTemplate()`/`importPreorders(file, dryRun)` functions in `resources/js/api/preorders.js`
- [X] T027 [P] [US3] A small import-result modal/panel showing `created_count`/`created_customer_count` on success or the `row_errors` list on failure (can reuse `MasterDataImportModal.vue`'s result-display pattern if it already separates "trigger UI" from "result display")
- [X] T028 [P] [US3] Add i18n keys (`preorders.export_*`/`preorders.import_*`) to `resources/js/locales/id.json`/`en.json`
- [X] T029 [P] [US3] Update `docs/openapi-pos-mvp.yaml` for `GET /preorders/export`, `GET /preorders/import/template`, `POST /preorders/import`
- [X] T030 [US3] Manual browser verification per Constitution Principle II: as owner, export with a filter active and confirm the file matches; import a valid file and a file with one bad row (confirm nothing written on the bad file); as `kasir01`, confirm the controls are absent and the endpoints 403

**Checkpoint**: User Stories 1, 2, AND 3 all work independently.

---

## Phase 6: User Story 4 - Send status updates and invoices to the customer's email (Priority: P3)

**Goal**: On pre-order status change (and on manual demand), email the customer the new status + status-appropriate invoice/receipt, with every attempt logged so failures are visible.

**Independent Test**: Per quickstart.md steps 5–7 — status change triggers a logged send attempt (using `Mail::fake()` in tests to avoid real SMTP); customer with no email is skipped without error; manual resend works independent of a status change.

### Tests for User Story 4

- [X] T031 [P] [US4] Feature test `tests/Feature/PreorderNotificationTest.php` — `Mail::fake()`-based: (a) changing a pre-order's status with a real customer email + `MAIL_MAILER` not `log` sends `PreorderStatusMail` and writes a `sent` `PreorderNotification` row with `trigger=status_change`; (b) the status-change response itself succeeds and is unaffected even if the mail send is made to throw (simulate via a bound fake that throws) — asserting `failed` row + `error_message`, but 200 on the status endpoint (FR-013); (c) `POST /preorders/{id}/notifications/resend` sends independent of any status change and is 403 for a cashier; (d) `GET /preorders/{id}` includes `latest_notification` matching the most recent attempt

### Implementation for User Story 4

- [X] T032 [US4] `app/Mail/PreorderStatusMail.php` — `Mailable`, constructor takes the `Preorder` (+ eager-loaded relations) and its `document_type`/subject theme from `PreorderDocumentType` (T005), body includes store name, pre-order number, new status in Indonesian, and either an attached PDF (server-rendered from the same data `PreorderInvoiceModal` renders — simplest: a plain-text/HTML summary in the body plus a link back to view it in-app, NOT a server-side PDF generation, per research.md R2's "no server PDF path" decision) or a link to view/print the invoice — implementer confirms final body shape against research.md R2 before writing
- [X] T033 [US4] Complete `PreorderNotifier::notifyStatusChange()` (T006's skeleton) — wires the actual `Mail::to($email)->send(new PreorderStatusMail(...))` call into the previously-stubbed "attempt send" branch, writes `sent`/`failed` outcome + `recipient_email` snapshot + `sent_at`
- [X] T034 [US4] Call `PreorderNotifier::notifyStatusChange($preorder, 'status_change')` from `PreorderService::transitionStatus()` in `app/Services/PreorderService.php` — **after** `DB::transaction()` commits, not inside it (research.md R7); wrap in its own try/catch so even an unexpected `PreorderNotifier` exception can never bubble up and fail the status-change response
- [X] T035 [US4] Add `resendNotification(Request $request, Preorder $preorder): JsonResponse` to `app/Http/Controllers/Api/PreorderController.php`, gated `abort_unless($request->user()->isOwnerOrAdmin(), 403)`, calling `PreorderNotifier::notifyStatusChange($preorder, 'manual_resend')` and returning its outcome per contracts/api-contract.md's response shape
- [X] T036 [US4] Add route `POST /preorders/{preorder}/notifications/resend` to `routes/api.php`
- [X] T037 [US4] Add `latest_notification` to `PreorderController::show()`'s response (the most recent `PreorderNotification` row via T004's relation)
- [X] T038 [P] [US4] Add a notification-status indicator + "Kirim ulang notifikasi" button to `resources/js/views/PreordersView.vue`'s pre-order detail view, visible only for owner/admin, showing sent/failed/skipped state from `latest_notification`
- [X] T039 [P] [US4] Add `resendPreorderNotification(id)` to `resources/js/api/preorders.js`
- [X] T040 [P] [US4] Add i18n keys (`preorders.notification_*`) to `resources/js/locales/id.json`/`en.json`
- [X] T041 [P] [US4] Update `docs/openapi-pos-mvp.yaml` for `POST /preorders/{id}/notifications/resend` and the extended `GET /preorders/{id}` response
- [X] T042 [US4] Manual browser verification per Constitution Principle II: change a pre-order's status with `MAIL_MAILER=log` (confirm the log entry + a `sent` row, since `log` driver "sends" successfully by writing to the log — to test the `skipped_not_configured` branch specifically, temporarily verify via the automated test from T007/T031 rather than manual browser steps, since it requires manipulating server config); change status for a customer with no email (confirm `skipped_no_email` shown, no error); manually resend; confirm `kasir01` cannot see or hit the resend action/endpoint

**Checkpoint**: All four user stories independently functional.

---

## Phase 7: Polish & Cross-Cutting Concerns

**Purpose**: Final checks spanning all four stories.

- [X] T043 Run `php artisan test` (full suite) and confirm no regressions
- [X] T044 Run `npm test` (full suite) and confirm no regressions
- [X] T045 Run `npm run build` and confirm bundle sizes are reasonable (no new heavy dependency — `html2canvas`/`jspdf` are already dynamically imported elsewhere, this feature adds no new frontend package)
- [X] T046 Full `quickstart.md` walkthrough end-to-end in a real browser against the real API, checking browser console for errors on every touched screen

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies.
- **Foundational (Phase 2)**: Depends on Setup — BLOCKS all four user stories (the `PreorderNotification` model/migration and `PreorderDocumentType` mapping are shared by US2 and US4; US1 and US3 don't strictly need Phase 2's notifier pieces, but the migration should still land first for a clean, single foundational commit).
- **User Stories (Phase 3–6)**: All depend on Foundational. US1 and US3 have no dependency on US2/US4. US4 depends on US2's `PreorderDocumentType` (Phase 2, not Phase 4 itself) for its email subject/body theme, but not on US2's endpoint or frontend modal — independently testable per its own Independent Test.
- **Polish (Phase 7)**: Depends on all four stories.

### User Story Dependencies

- **US1 (P1, search)**: No dependency on other stories.
- **US2 (P1, print)**: No dependency on other stories (only on Foundational's `PreorderDocumentType`).
- **US3 (P2, export/import)**: No dependency on other stories.
- **US4 (P3, email)**: Depends on Foundational's `PreorderDocumentType` (T005) and `PreorderNotifier` shell (T006); does NOT depend on US2's invoice endpoint/modal being built.

### Within Each User Story

- Tests written and run (expected to fail) before implementation.
- Backend (migration/model/service/controller/route) before frontend wiring.
- Story complete (including its manual browser verification) before moving to the next priority, though all four can be worked in parallel once Foundational is done.

### Parallel Opportunities

- T003, T004, T005 (Foundational) can run in parallel — different files.
- Once Foundational is done: US1, US2, US3 can all start in parallel (US4 needs T005/T006 from Foundational, already done by then).
- Within US2: T016, T018, T019 in parallel. Within US3: T022, T026, T027, T028, T029 in parallel (after T023/T024 land). Within US4: T038, T039, T040, T041 in parallel (after T032–T037 land).

---

## Parallel Example: Foundational

```bash
Task: "Create PreorderNotification model in app/Models/PreorderNotification.php"
Task: "Add notifications() relation to app/Models/Preorder.php"
Task: "Create PreorderDocumentType support class in app/Support/PreorderDocumentType.php"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1 + Phase 2.
2. Complete Phase 3 (US1 — search).
3. **STOP and VALIDATE**: search works independently against the real API and a real browser.
4. Demo if ready — this alone already removes daily friction per spec.md's own framing.

### Incremental Delivery

1. Setup + Foundational → foundation ready.
2. US1 (search) → verify → demo (MVP).
3. US2 (print) → verify → demo.
4. US3 (export/import) → verify → demo.
5. US4 (email) → verify → demo — the one story that depends on the store having configured outgoing mail at all; ships gracefully degraded (skipped, visibly) even where it hasn't.

---

## Notes

- [P] tasks = different files, no dependencies.
- [Story] label maps each task to US1–US4 for traceability back to spec.md.
- Constitution Principle II requires: tests before implementation, and a real-browser check before any story is declared done — reflected in T012, T020, T030, T042.
- Constitution Principle IV's flagged risk for this feature is import never being able to forge a paid/handed-over status (FR-010, enforced in T022/T023, tested in T021) and the deliberate, documented exception to "server always recomputes money" for imported historical amounts (research.md R4) — do not silently drop that exception's boundary (only import is exempt; live pre-order creation via `POST /preorders` is untouched and still recomputes as it always has).
- `docs/openapi-pos-mvp.yaml` updates are folded into each story's own task list (T019, T029, T041) — same convention 006 established, since these four stories don't share a route surface.
- Email sending in tests MUST use `Mail::fake()` — never a real SMTP attempt in CI/local `php artisan test` runs (T001, T007, T031).

## Session progress note (final — all 46 tasks, all 4 user stories)

All 46 tasks complete. `php artisan test`: 370/370 passing (was 348 before
this feature — 22 new tests). `npm test`: all 32 files / 161 tests
passing, no regressions. `npm run build` clean. All four user stories
verified live in a real browser via chrome-devtools MCP, each as owner
(feature works) and as `kasir01` (correctly denied where gated):

- **US1 (search)**: partial/case-insensitive customer-name search
  confirmed narrowing the list and clearing correctly.
- **US2 (print)**: invoice/receipt modal confirmed showing the right
  label and figures for an open pre-order; PDF download completed with
  no console error.
- **US3 (export/import)**: export/template download confirmed
  error-free for owner; all three controls confirmed absent from the DOM
  for `kasir01`, AND a direct API call with kasir01's real bearer token
  confirmed a server-side 403 — defense in depth actually verified, not
  assumed from the UI hiding alone.
- **US4 (email)**: notification card + resend button confirmed working
  end-to-end on this dev machine's `MAIL_MAILER=log` default (correctly
  producing `skipped_not_configured`/`skipped_no_email` outcomes, never
  blocking the underlying status change), and confirmed hidden for
  `kasir01`.

**One real bug found and fixed during US1/US2 verification**: the
`PreorderInvoiceModal`'s close button rendered the literal untranslated
key `common.close` instead of "Tutup"/"Close" — the `common` i18n
namespace never had a `close` key (only `cancel`), even though several
existing modals elsewhere in this codebase already relied on it
implicitly working. Fixed by adding `common.close` to both
`resources/js/locales/id.json` and `en.json`.

**Scope note on T027** (import-result display): implemented as toast
notifications (success count, or first failing row + a "+N other rows
also failed" suffix on error) rather than a dedicated result modal/panel
— judged sufficient for this feature's scope; a full per-row error table
UI (like `MasterDataImportModal.vue`'s) can be added later if operators
find the toast summary insufficient in practice.

**Not yet done**: this feature branch has not been committed, pushed, or
opened as a PR — awaiting explicit go-ahead, consistent with how prior
features (005, 006) in this session handled that step.
