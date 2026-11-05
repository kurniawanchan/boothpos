---
description: "Task list for Transaction Approval & Seller Audit"
---

# Tasks: Transaction Approval & Seller Audit

**Classification**: INTERNAL

**Input**: `/specs/059-seller-transaction-approval/` — plan.md, spec.md, research.md (D1–D15), data-model.md, contracts/approvals-api.md, quickstart.md

**Tests**: INCLUDED (Constitution II makes tests mandatory; write them first and watch them fail).

**Organization**: by user story. US1 + US2 are both P1 (US2 is mostly rules/tests on top of US1's writer); MVP = Phase 1–3.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: parallelizable (different files, no dependency on an unfinished task)
- Backend tests: `tests/Feature/OrderApproval/`; run on MySQL only. Inside Docker ONLY with `-e APP_ENV=testing -e DB_DATABASE=boothpos_test`; never two runs at once.
- Frontend tests: `qa-tests/`; run `npx vitest run --exclude '.claude/**'`.
- Code comments/UI copy/commit messages in Indonesian; locale keys in BOTH `en` and `id`.

---

## Phase 1: Setup

- [x] T001 Create `tests/Feature/OrderApproval/OrderApprovalFixture.php` — a trait building a sale with N sellers (items per seller), users linked to a seller (`artist_id`), an owner/admin and an unlinked cashier; reuse `tests/Feature/SellerScope` fixture style (deterministic names).
- [x] T002 [P] Create `lang/id/approvals.php` and `lang/en/approvals.php` with all server messages (order_voided, approval_not_allowed, already_approved, approval_stale, seller_not_required, image_required, image_not_allowed_for_stamp, unsupported_file_type, activity descriptions) and add the `approve` action label for the Sales menu in `lang/{id,en}/permissions.php`.
- [x] T003 [P] Add `approvals.*` keys (section title, statuses, methods, progress, confirm dialog, popover, filter, errors, "on behalf") to `resources/js/locales/en.json` and `resources/js/locales/id.json` (guard: `qa-tests/unit/localeKeys.test.js`).

---

## Phase 2: Foundational (blocks every story)

**⚠️ No user story starts before this phase is done.**

- [x] T004 Migration `database/migrations/2026_11_25_000001_create_order_approvals_table.php` per data-model.md: columns, FKs (`order_id` cascade, `artist_id`/`approved_by` restrict), `active_slot` nullable tinyint default 1, `UNIQUE(order_id, artist_id, active_slot)`, indexes, and the two `CHECK`s via `DB::statement` (stamp ⇔ no image; `invalidated_at` null ⇔ `active_slot` not null). Do not rename/reorder other migrations.
- [x] T005 [P] Model `app/Models/OrderApproval.php` (no `HasDataMode`; docblock explaining why it rides the order; casts for `items_snapshot`, timestamps, `on_behalf`; `scopeActive()`; relations order/artist/approver) and add `approvals()` hasMany to `app/Models/Order.php`.
- [x] T006 [P] Test `tests/Feature/OrderApproval/OrderApprovalSummaryTest.php` (write first, must fail): unique sellers (4 items/3 sellers → 3 requirements), per-seller items, overall pending/partial/full and `X/N`, voided → `voided`, legacy order without rows → pending 0/N, **pinned hash**: a fixed input produces a fixed `v1:` hash (guards against accidental canonical-form changes), hash ignores `order_items.id`/`variant_id`/`artist_id`, hash changes when qty/price/discount/line_total/name changes, order-independent.
- [x] T007 Implement `app/Support/OrderApprovalSummary.php` (pure, no writes): `for(Order)` returning requirements, per-seller state (`approved|pending`, `stale`), overall, counts, and `hashFor(items)` (canonical `v1`, money as 2-dp strings, sorted tuples). Make T006 pass.
- [x] T008 [P] Register the action: add `'approve'` to `sales` named actions in `app/Support/MenuActions.php`; extend the role-editor/registry tests that enumerate Sales actions (`tests/Feature/MenuActions*`, `qa-tests` role picker test) and the roles Excel `menu_actions` import/export expectations if they list actions.
- [x] T009 [P] Register `order_approvals` in `app/Services/DemoClone/CloneRegistry.php` (`fks: order_id→orders, artist_id→artists`, `files: image_path => local`, `via: orders.order_id`, after `order_items`); run `CloneRegistryDriftTest` and extend the clone test to assert an approval + its image survive a clone and still read as valid (hash survives id remap).
- [x] T010 Skeleton `app/Services/OrderApprovalService.php` (constructor with `ActivityLogger`, constants `MAX_IMAGE_KILOBYTES = 5120`, private `storeUpload()`/`deleteFile()` copied in spirit from `SellerSettlementService`, `present(Order, ?User)` presenter returning the contract's `data` shape incl. server-computed `can_approve`, `can_view_image`, `history` only for owner/admin) and routes in `routes/api.php`: `GET /orders/{order}/approvals`, `GET /orders/{order}/approvals/{approval}/image` (`->scopeBindings()`, param name `approval`), `POST /orders/{order}/approvals` with `->middleware('ability:sales,approve')`. Classify them as scope-aware in `ForbiddenSurfacesTest` and confirm `MenuActionsRouteCoverageTest` stays green.

**Checkpoint**: tables, calculator, registry, routes exist; stories can start.

---

## Phase 3: User Story 1 — A seller approves their items (P1) 🎯 MVP

**Goal**: a seller (or owner/admin) approves with draw / upload / stamp, with confirmation, exactly once, fully audited.

**Independent Test**: on a single-seller sale, approve with each method; each shows Approved with approver, time, method, image.

### Tests (write first)

- [x] T011 [P] [US1] `tests/Feature/OrderApproval/ApproveTest.php`: stamp/draw/upload happy paths (row fields: approver, `on_behalf=false`, `items_snapshot`, hash, `approved_at`; files on the private disk only, UUID names, none for stamp); GET never creates a row; activity-log row `order_approved` written in the same transaction (force a failure after the insert → no row, no log, no orphan file).
- [x] T012 [P] [US1] `tests/Feature/OrderApproval/ApproveValidationTest.php`: `draw`/`upload` without image → 422; `stamp` with image → 422; GIF/PDF/disguised file (content sniffed, not extension) → 422; > 5 MB → 422; empty file → 422; unknown `artist_id` / seller not in sale → 422 `seller_not_required`; voided sale → 409 `order_voided`.
- [x] T013 [P] [US1] `tests/Feature/OrderApproval/ApproveConcurrencyTest.php`: second approval of the same seller → 409 `already_approved` and exactly one row; unique-index violation is mapped to 409 (simulate by inserting the active row between check and insert), never 500; stale `items_hash` → 409 `approval_stale`.
- [x] T014 [P] [US1] `tests/Feature/OrderApproval/ApprovePermissionTest.php`: linked user approves own seller; owner/admin approve any seller with `on_behalf=true`; unlinked cashier → 403 `approval_not_allowed`; a role whose `menu_actions` lacks `sales.approve` → 403 `action_not_allowed` even if linked; user linked to Seller A posting for Seller B → 403; a user without `sales` menu → 403.
- [x] T015 [P] [US1] `qa-tests/component/ApproveSellerModal.test.js`: three method tabs; Confirm disabled until valid (stamp always, draw needs ink, upload needs a PNG/JPG ≤ 5 MB); confirmation step shows seller, item count and amount before submit; cancel/close sends nothing; double click sends one request; 409/422 messages shown via the central error handler.

### Implementation

- [x] T016 [US1] Implement `OrderApprovalService::approve()` per research D3–D6/D12: `DB::transaction`, `lockForUpdate` on the order, voided check, eligibility (owner/admin OR `user->artist_id === artist_id`; set `on_behalf`), seller must be a current requirement, `reconcile()` of stale rows, active-row check, freshness (`items_hash`), store file, insert row (`artist_name`/`approver_name` snapshots, `items_snapshot`), `ActivityLogger` row, catch the unique violation → 409, delete the stored file on any throw. Make T011–T014 pass.
- [x] T017 [US1] `app/Http/Controllers/Api/OrderApprovalController.php` (`index`, `store`, `image`) + `app/Http/Requests/StoreOrderApprovalRequest.php` (rules of the contract; `authorize()` true — eligibility is the service's job and returns 403 with a code); `image` streams from the private disk with `Cache-Control: private, no-store`, 403 when not viewable, 404 for stamp/missing file. Typed errors via an exception carrying `code` + status (pattern: `BomRuleException`).
- [x] T018 [P] [US1] `resources/js/api/orderApprovals.js` (`getApprovals`, `approve` as multipart, `fetchApprovalImage` as blob).
- [x] T019 [P] [US1] `resources/js/components/sales/ApprovalStamp.vue` — APPROVED stamp artwork from theme tokens (no hex), shows seller name, approver and date; used in the modal preview and in the section.
- [x] T020 [US1] `resources/js/components/sales/ApproveSellerModal.vue` (dynamically imported by its caller): method tabs; draw uses `components/settlement/SignaturePad.vue` (blob via `toBlob()`; if its copy strings are settlement-specific, pass them as props rather than forking); upload with client-side type/size triage (`utils/referenceFiles.js` style); confirmation step; sends `items_hash`; `data-autofocus` on the confirm control; works at phone width. Make T015 pass.
- [x] T021 [US1] `resources/js/components/sales/OrderApprovalSection.vue` and mount it in `resources/js/components/sales/TransactionItemsModal.vue` (loads `GET /orders/{id}/approvals` once per open; per seller: name, items, status pill, approver/time/method, image thumbnail via blob fetch only when `can_view_image`, "Approve" button only when `can_approve && auth.canDo('sales','approve')`; success toast and refresh; overall status/progress shown separately and visibly distinct from the seller status). Component test `qa-tests/component/OrderApprovalSection.test.js` (pending/approved states, button visibility by `can_approve`, image only when allowed, failed load → message + Retry, never an empty state).

**Checkpoint**: single-seller sale can be approved end to end. MVP demo-able.

---

## Phase 4: User Story 2 — One approval per unique seller (P1)

**Goal**: multi-seller sales get exactly one requirement per seller; independent approvals; correct overall status/progress.

**Independent Test**: 4 items/3 sellers (two items of one seller) → 3 rows; approve one at a time → 0/3, 1/3, 2/3, 3/3 with the right overall status.

- [x] T022 [P] [US2] `tests/Feature/OrderApproval/MultiSellerTest.php`: the spec's A/A/B/C example (3 requirements, not 4); progression pending → partial → full; one seller's approval creates/changes no other row; approving A twice via different users linked to A → one row; a seller-scoped user (058) sees the whole sale's approvals but `can_approve` is true only for their seller; owner approving the last seller flips to full.
- [x] T023 [P] [US2] `tests/Feature/OrderApproval/SellerScopeInteractionTest.php`: foreign sale (no line of the user's seller) → 404 on GET/POST/image; `seller.forbidden` classification test updated; Owner role with a stale `artist_id` is not restricted.
- [x] T024 [US2] Ensure `OrderApprovalService::present()` returns every requirement with correct `can_approve` per seller for the viewing user (own seller / owner-admin only, never for approved or voided) and that GET uses one eager load (`items.artist`, `approvals.approver`); make T022–T023 pass. Add a query-count test in `tests/Feature/OrderApproval/QueryCountTest.php` (warm-up call first, per CLAUDE.md).
- [x] T025 [US2] Extend `OrderApprovalSection.vue` (+ its test) for multi-seller: one block per seller, progress line "2/3 Sellers Approved", overall status header; actions only on the user's own pending seller block(s); owner/admin see an action on every pending block with the "on behalf of" wording in the confirmation.

---

## Phase 5: User Story 3 — Status icon, popover and filter in the Sales list (P2)

**Goal**: spot and filter approval status in the list without opening anything.

**Independent Test**: sales in each state; each filter value shows only matching rows; icon popover shows counts and per-seller details; filter survives reload.

- [x] T026 [P] [US3] `tests/Feature/OrderApproval/SalesListApprovalTest.php`: each row has `approval {status,total,approved,pending,sellers[]}`; no hash/URL/image anywhere in the payload; voided rows → `voided` (only with `include_voided=1`); legacy rows → pending; query count does not grow with row count (eager-load `approvals`); cashier/seller-scoped rows still computed over the WHOLE sale.
- [x] T027 [US3] Add the `approval` block to rows in `app/Services/SalesTransactionsService.php` (`orderRow()`), calling `OrderApprovalSummary` and eager-loading `approvals` once in `build()`; document the explicit "list rows never carry images" rule in the docblock.
- [x] T028 [P] [US3] `qa-tests/unit/useSalesFilters.approval.test.js`: `filters.approval` `''|pending|partial|full` over `row.approval.status`; combines (AND) with seller/payment/reference filters; voided never matches a non-empty value; included in the active-filter count and reset; URL key `appr` restored/serialised, invalid value ignored, page reset to 1 on change, `appr` listed in `OUR_QUERY_KEYS`.
- [x] T029 [US3] Implement the filter in `resources/js/composables/useSalesFilters.js` (field, defaults, reset, active count, URL sync, `OUR_QUERY_KEYS`).
- [x] T030 [P] [US3] `resources/js/components/sales/ApprovalStatusIcon.vue` + `ApprovalPopover.vue` (clock / half-progress / green check from the existing Phosphor duotone set; button with `aria-label`; click opens a teleported popover with overall status, total/approved/pending, "X/N", per-seller name + status + approver/time/method; Esc closes and returns focus; viewport-clamped like `BaseTooltip`; no network call). Component test `qa-tests/component/ApprovalPopover.test.js`.
- [x] T031 [US3] Wire into `resources/js/views/SalesView.vue`: icon next to the transaction number (no new column, no layout shift), Approval `BaseSelect` (with an explicit empty "All" option, as for the events dropdown) beside the other filters, active-filter chips/reset; summary line, select-all, export and pagination keep operating on all filtered rows. Update `qa-tests` for SalesView filters.

---

## Phase 6: User Story 4 — Changes invalidate only the affected seller (P2)

**Goal**: any change to a seller's lines makes that seller Pending again; others untouched; history kept.

**Independent Test**: approve a 2-seller sale, change B's line → B pending, A approved, overall partial, old row kept.

- [x] T032 [P] [US4] `tests/Feature/OrderApproval/InvalidationTest.php`: change qty/price/name of B's line → B `stale` pending, A still approved, overall partial 1/2; line moved A→B → both pending; B's last line removed → B no longer required and counts shrink, old row kept; POST with the old `items_hash` → 409 `approval_stale`; re-approve → new row in a new cycle, old row has `invalidated_at` + `items_changed`, `active_slot` NULL; `reconcile()` writes one `order_approval_invalidated` log row and is idempotent; GET never writes; voided sale: no approve, rows untouched.
- [x] T033 [US4] Implement `OrderApprovalService::reconcile(Order, ?User)` (locks, compares stored vs current hash per active row, sets `invalidated_at`/`invalidation_reason`/`active_slot=NULL`, logs) and call it from `approve()`; docblock states that any future code that edits/removes/reassigns `order_items` MUST call it inside its transaction. Make T032 pass.
- [x] T034 [US4] UI for stale state in `OrderApprovalSection.vue` and the popover: "Re-approval required — items changed" cue on the seller block, previous approval shown as history, Approve button available; 409 `approval_stale` from the modal → message + automatic reload of the section. Extend the component tests.

---

## Phase 7: User Story 5 — Audit trail and protected records (P3)

**Goal**: owner/admin see full history; nothing can edit/delete approvals; images only for permitted viewers.

**Independent Test**: approve → invalidate → re-approve; history lists every cycle; no mutation routes; other users get 403 on images.

- [x] T035 [P] [US5] `tests/Feature/OrderApproval/AuditAndProtectionTest.php`: `history` present only for owner/admin and contains invalidated rows (actor, seller, method, times, reason); no PUT/PATCH/DELETE route under `/orders/{order}/approvals*` (route list assertion); model has no update path other than `reconcile()`; image 403 for an unrelated user, 200 for owner/admin, approver and seller-linked user, 404 for stamp; headers `private, no-store`; the activity log shows `order_approved`/`order_approval_invalidated` entries on the existing Activity Log API.
- [x] T036 [US5] Finish `present()` history block and the activity-log presentation (descriptions from `lang/*/approvals.php`; entity type `Order`); make T035 pass.
- [x] T037 [US5] "Approval history" disclosure in `OrderApprovalSection.vue` for owner/admin only (omitted from the payload for others, so the SPA never decides by role); component test.

---

## Phase 8: Polish & Cross-Cutting

- [x] T038 [P] OpenAPI: add the three routes, schemas, error codes and the `approval` field on Sales rows to `docs/openapi-pos-mvp.yaml` (same commit as the routes, per Constitution).
- [x] T039 [P] Dated notes: PRD (`docs/PRD-POS-Event-Multivendor.md`, new capability, approval is an audit overlay with no effect on money/stock/reports/settlements) and a CLAUDE.md section "Transaction approval (feature 059)" with the non-obvious rules (hash without ids, derived invalidation + `reconcile()` duty, `active_slot`, eligibility = action AND relation, images not in backups, not built list).
- [x] T040 [P] Backward-compat check: run an old sale (no rows) through list/detail/filter in a feature test and manually on the dev database; confirm no write on any GET (assert row count unchanged).
- [x] T041 Full regression: backend suite serially (`php artisan test`), expecting only the known pre-existing Code128 failures; Vitest full run + `localeKeys` guard; `npm run build` and confirm `ApproveSellerModal`/`SignaturePad` are their own chunk (Constitution V). **Result:** backend 1839 passed / 0 failed (PHPUnit run directly with `-d memory_limit=2G`: `artisan test` spawns a child PHP that ignores the flag and the default 128 MB dies in the xlsx export tests, an environment limit, not code); Vitest 136 files / 1247 passed; build clean, `ApproveSellerModal` + `SignaturePad` are separate chunks.
- [x] T042 Run `php artisan migrate` on the dev database and walk quickstart scenarios 1–10 (API-level with a throwaway seller-linked user; record counts only, no customer data). **Done as:** migration applied to the dev DB (only `order_approvals` was pending); a READ-ONLY check over 104 real dev sales gave all `pending`, 16 multi-seller (max 3 sellers), 0 approval rows written. Scenarios 1–10 are exercised by the automated feature tests, NOT by hand on dev data (no throwaway users/approvals were created in the dev database).
- [ ] T043 **Real-browser verification (Constitution II) — keep OPEN until actually run**: draw with mouse and touchpad on desktop and with touch on an Android device; popover keyboard/Esc; phone-width Approval section; stamp artwork; console clean. If browser tooling is unavailable, say so explicitly instead of ticking.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (blocks all) → US1 (MVP) → US2 (needs US1's writer) → US3 (needs Phase 2 calculator + US1 data; UI can start after T027) → US4 (needs US1 writer) → US5 (needs US1 + US4 rows) → Polish.
- US3 and US4 are independent of each other and can run in parallel after US2. US5's history needs US4's invalidated rows to be meaningful (T033).
- Within a story: tests → service → controller → API client → components → wiring.

### Parallel opportunities

- Setup: T002, T003 together.
- Foundational: T005, T008, T009 together after T004; T006 can be written alongside T004.
- US1 tests T011–T015 together; T018, T019 together.
- US3: T026, T028, T030 together; US3 and US4 as whole phases in parallel.
- Polish: T038, T039, T040 together.

## Implementation Strategy

1. **MVP**: Phases 1–3 (T001–T021): approve a sale with any method, audited. Stop and demo.
2. Add US2 (correct multi-seller behaviour — the primary business rule), then US3 (visibility/filter), US4 (integrity), US5 (history).
3. Never skip T043; do not tick it from a unit-test run.

**Total**: 43 tasks — Setup 3, Foundational 7, US1 11, US2 4, US3 6, US4 3, US5 3, Polish 6.
