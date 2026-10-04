---

description: "Task list for Mark a Non-Cash Payment as Verified"
---

# Tasks: Mark a Non-Cash Payment as Verified

**Input**: Design documents from `/specs/032-mark-payment-verified/`

**Prerequisites**: plan.md, spec.md, research.md (Decisions 1–5), data-model.md (no schema change), contracts/payment-verification.md, quickstart.md

**Tests**: INCLUDED. This feature changes a financial control (who may confirm that money arrived), so the authorization matrix, the one-way state machine, the "no money figure changes" invariant and the audit trail are tested server-side, plus component tests and a real-browser check on an ISOLATED server.

**Organization**: US1 Mark one payment verified (P1) · US2 See who verified it and when + audit (P1) · US3 Verify several from the Sales list (P2). Verification is FINAL (no undo anywhere — spec "Verification is final" / FR-009); the shared groundwork (model rules, messages) is Foundational.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US3, only on user-story phase tasks
- Code comments, docs and commit messages in Indonesian (project convention); every new UI string goes in BOTH `resources/js/locales/en.json` and `id.json`; backend messages in BOTH `lang/en/orders_payments.php` and `lang/id/orders_payments.php`

## Safety reminders

- **Never run `php artisan test` inside the `app` container without** `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test …`. Prefer the HOST (`APP_ENV=testing php artisan test …`, `.env.testing` → `boothpos_test`). Never `migrate:fresh`/`db:wipe`/`db:seed` against the dev DB.
- This feature has **no migration**. (Feature 031's migration — `payment_proofs.superseded_at` — must already be applied to the dev DB; not this feature's concern.)
- The real-browser check WRITES data (sales, verifications): use an isolated server (`APP_ENV=testing php artisan serve --port=8091`) against `boothpos_test`, seeded with `db:seed` + `license:dev-activate`; never the dev app on :8000. `php artisan test` wipes `boothpos_test`, so re-seed afterwards. Run `cp`/`mv` with `-f` (the shell aliases them to interactive).
- Never print or echo the DB password or an API token in commands/outputs. Check free Docker disk space before long runs.

---

## Phase 1: Setup

- [X] T001 Record the baseline on the host: `APP_ENV=testing php artisan test --filter='PaymentConfirmation|OrderTest|PreorderPaymentLedgerTest|SalesTransactions|SalesPageData|SalesPaymentStatus'` and `npx vitest run qa-tests/component/PaymentHistoryList.test.js qa-tests/component/TransactionItemsModal.test.js qa-tests/component/PreordersView.test.js qa-tests/component/SalesView.test.js qa-tests/component/SalesViewAdvanced.test.js`; all green before any change (note the counts)

---

## Phase 2: Foundational (blocks all stories)

**Purpose**: The model rules every story relies on and the backend messages.

- [X] T002 [P] Write failing tests in a new `tests/Feature/PaymentVerificationRulesTest.php` (build a pre-order through the API and insert `Payment` rows directly, as `PaymentConfirmationRulesTest` does; non-cash rows need a channel, cash rows none — constraint `chk_payments_channel`): `Payment::isVerifiable()` — true only for a NON-cash payment in state `pending`; false for `verified`, `rejected` and cash; `Payment::mayVerify($user)` — owner true, admin true, another cashier true, the recording cashier FALSE, an owner/admin who recorded it TRUE, a payment with NULL `recorded_by` true for any role; `Payment::verifier()` relation returns the `verified_by` user or null
- [X] T003 In `app/Models/Payment.php` add `verifier(): BelongsTo` (`verified_by` → `User`), `isVerifiable(): bool` and `mayVerify(User $user): bool` exactly per data-model.md/research.md Decisions 1–2 (Indonesian docblocks citing spec FR-002/FR-007; "SATU-SATUNYA definisi aturan ini"; owner/admin may verify payments they recorded themselves)
- [X] T004 [P] Add backend messages to BOTH `lang/en/orders_payments.php` and `lang/id/orders_payments.php`: `payment_verify_not_allowed` (the recorder may not verify their own payment), `payment_verify_cash` (cash payments are verified when recorded), `payment_already_verified`, `payment_rejected_cannot_verify`, `payment_verify_target_closed` (voided sale / cancelled pre-order)
- [X] T005 Run `APP_ENV=testing php artisan test --filter=PaymentVerificationRulesTest` on the host; fix until green (T002 tests must have failed before T003)

**Checkpoint**: rules ready.

---

## Phase 3: User Story 1 — Mark one payment verified (Priority: P1) 🎯 MVP

**Goal**: An allowed user marks a pending non-cash payment verified from the transaction's details (Sales detail and Pre-order payment history); the entry and the Sales badge update; refused or no-op for everything else; no undo.

**Independent Test**: Make a QRIS sale as one cashier, open its details as another user, mark the payment verified → it shows verified and the list badge clears; the recorder never sees the action.

### Tests for User Story 1 ⚠️ write first

- [X] T006 [P] [US1] Write `tests/Feature/PaymentVerificationTest.php` (sale path, `POST /api/v1/orders/{order}/payments/{payment}/verify`; helpers like `PaymentConfirmationTest`: cashier `kasir` records a QRIS payment of a sale via `POST /orders`): authorization — owner ok, admin ok, ANOTHER cashier ok, the RECORDING cashier 403 (payment unchanged), an owner who recorded it ok, a legacy NULL-recorder payment ok for any cashier; state matrix — pending → 200 with the payment `verification: verified`; already verified → 409 and `verified_at` unchanged and still ONE `payment_verified` log row; `rejected` → 409; cash → 422 `errors.method`; voided sale → 409; foreign payment (another sale's) → 404; payment of the other DEMO/LIVE mode (flip rows to `demo` via `DB::table`) → 404; split sale with two pending QRIS payments: verifying one leaves the sale's Sales-list `payment_state` `pending`, verifying both makes it `verified`; **double click**: two sequential requests → 200 then 409 and exactly one verifier and one log row; **invariants** — payment amount/method/channel/purpose/`paid_at`/`session_id`, order `paid_amount`/`status`/`total_amount`, `payment_summary`, and the shift summary's `order_count`/`total_sales`/cash rows identical before/after (the per-method breakdown legitimately gains the verified non-cash payment — assert that explicitly in its own test); **no undo** — `PATCH`/`DELETE`/`POST` to `…/verify` variants for un-verifying (`/unverify`, `PATCH …/verification`) answer 404/405 and no route in `php artisan route:list` contains "unverify"
- [X] T007 [P] [US1] In `tests/Feature/PaymentVerificationTest.php` add the pre-order path (`POST /api/v1/preorders/{preorder}/payments/{payment}/verify`; helper to create a pre-order with a transfer payment recorded by a cashier, as in `PaymentConfirmationTest::preorder()`): same authorization matrix; pending → verified without changing `paid_amount`/status (`dp_paid`)/outstanding/summary; cancelled pre-order → 409; handed-over pre-order → 200; foreign payment 404; cash 422; already verified 409
- [X] T008 [P] [US1] Update `qa-tests/component/PaymentHistoryList.test.js`: a non-cash entry with `verification: 'pending'` shows the "Belum terverifikasi" pill (EN "Not verified") and, ONLY when `can_verify`, a "Tandai terverifikasi" action that emits `verify` with the entry; `verification: 'verified'` shows the "Terverifikasi" pill and no action even if `can_verify` is true; cash entries and `rejected` entries show no verification pill/action; entries without any of the new fields render exactly as before (existing tests stay green)
- [X] T009 [P] [US1] Extend `qa-tests/component/TransactionItemsModal.test.js` (the mocks gain `verifyPayment`): a pending QRIS entry with `can_verify: true` offers "Tandai terverifikasi"; clicking opens a confirm dialog that says the action cannot be undone; confirming calls `verifyPayment('orders', 101, <paymentId>)`, re-renders the entry from the response as verified, shows a success toast and emits `changed`; cancelling calls nothing; `can_verify: false` (the recorder) offers no action; a 409 refetches the order (`getOrder` called again) and toasts the message; a voided order offers no action (server sends `can_verify: false`)
- [X] T010 [P] [US1] Extend `qa-tests/component/PreordersView.test.js` (mock `verifyPayment` in the payments api mock): the pre-order payment history shows the pending pill and the action only when `can_verify`; confirming calls `verifyPayment('preorders', 80, <paymentId>)`, refreshes the detail entry from the response and reloads the list

### Implementation for User Story 1

- [X] T011 [US1] Add `PaymentService::markVerified(Preorder|Order $target, Payment $payment, User $user): Preorder|Order` in `app/Services/PaymentService.php` per research.md Decision 1 (lock target; payment belongs to target else 404; closed target 409 `payment_verify_target_closed` — voided sale / cancelled pre-order, handed-over allowed; cash 422 `method`; `verified` 409 `payment_already_verified`; `rejected` 409 `payment_rejected_cannot_verify`; `mayVerify` else 403 `payment_verify_not_allowed` via `ValidationException::…->status(403)`; set `verification`, `verified_by`, `verified_at`; activity log `payment_verified` with old/new values inside the same transaction; return `reload()`); Indonesian docblock stating the deliberate absence of any un-verify path
- [X] T012 [US1] Add `OrderController::verifyPayment(Request $request, Order $order, Payment $payment)` (guard `abort_unless($payment->mayVerify($user), 403, __('orders_payments.payment_verify_not_allowed'))` BEFORE the service, map `ValidationException` to `$e->status`, return `OrderResource` loaded with `items.variant.product.category`, `items.artist`, `payments.channel`, `payments.recorder`, `payments.proofs`, `payments.verifier`, `customer`, `cashier`, `event`); add `payments.verifier` to the eager-loads of `show()`, `storePayment()`, `destroyPayment()` and `updatePaymentConfirmation()` in the same controller and to `PaymentService::reload()` for orders; register `POST /orders/{order}/payments/{payment}/verify` in `routes/api.php` next to the other payment routes
- [X] T013 [US1] Add `PreorderService::markPaymentVerified()` (thin delegate to `PaymentService::markVerified`, like `deletePayment`), add `payments.verifier` to `PreorderService::PAYLOAD_RELATIONS`, add `PreorderController::verifyPayment()` (same guard/mapping as the order one, returns `present($preorder)`), and register `POST /preorders/{preorder}/payments/{payment}/verify` in `routes/api.php`
- [X] T014 [US1] Payload flag `can_verify` (computed for the requesting user: `isVerifiable() && mayVerify($user) &&` target open — not voided / not cancelled): add it to each payment in `app/Http/Resources/OrderResource.php` and in `PreorderController::present()` (next to the 031 flags; `verification` is already there); keep every existing key untouched
- [X] T015 [P] [US1] Add the API client `verifyPayment(kind, targetId, paymentId)` (`POST /${kind}/${targetId}/payments/${paymentId}/verify`, returns the updated record) to `resources/js/api/payments.js`
- [X] T016 [US1] Update `resources/js/components/payment/PaymentHistoryList.vue`: for NON-cash entries show a `StatusPill` — warn "Belum terverifikasi" when `verification === 'pending'`, mint "Terverifikasi" when `verified` — and, when `can_verify`, a "Tandai terverifikasi" action emitting `verify` with the entry; cash and `rejected` entries and entries lacking `verification` unchanged; update the docblock; add the strings to BOTH `en.json` and `id.json` under `payment_ledger`: `verification_pending`, `verification_verified`, `mark_verified`, `verify_confirm_title`, `verify_confirm_message` ("Mark this payment as verified? This cannot be undone."), `verify_done` (toast)
- [X] T017 [US1] Wire `resources/js/components/sales/TransactionItemsModal.vue`: on `@verify` open a `ConfirmDialog` (title/message from T016 keys); on confirm call `verifyPayment('orders', order.id, payment.id)`, replace `order` with the response, toast success, emit `changed`; on error with `err.isConflict` (409) refetch via `getOrder` so the entry shows the real state (the global interceptor already toasts the message); never offer a verify action when the server did not set `can_verify`
- [X] T018 [US1] Wire `resources/js/views/PreordersView.vue` the same way for the pre-order payment history (`ConfirmDialog`, `verifyPayment('preorders', detail.id, payment.id)`, `detail` refreshed from the response, `await load()` + `loadSummary()`, conflict → `refreshDetail()`)
- [X] T019 [US1] Run `APP_ENV=testing php artisan test --filter='PaymentVerification|PaymentConfirmation|OrderTest|SalesTransactions|SalesPaymentStatus'` on the host and `npx vitest run qa-tests/component/PaymentHistoryList.test.js qa-tests/component/TransactionItemsModal.test.js qa-tests/component/PreordersView.test.js`; fix until green

**Checkpoint**: US1 delivered — payments can be verified one at a time, finally, by the right people.

---

## Phase 4: User Story 2 — See who verified it and when, with an audit trail (Priority: P1)

**Goal**: A verified payment shows "Verified by {name} · {date/time}" in every payment list, and every verification is in the activity log; payments verified/recorded before this feature (cash) display as before.

**Independent Test**: Verify a payment, then check the entry shows the verifier's name and time and the activity log has the matching `payment_verified` row.

### Tests for User Story 2 ⚠️ write first

- [X] T020 [P] [US2] In `tests/Feature/PaymentVerificationTest.php` add: after verifying, `verified_by` = the acting user and `verified_at` is set (not null, ≈ now) in the database; the activity log has exactly one `payment_verified` row with `user_id` = the actor, `entity_type` `Order` (resp. `Preorder`), `entity_id` = the transaction id, `old_values` `{payment_id, verification: 'pending'}` and `new_values` `{payment_id, verification: 'verified', verified_by, verified_at}`; refused attempts (403/409/422/404) leave NO log row; sale and pre-order payloads carry `verified_by_name` (the actor's name), `verified_at` and `verification: verified` for a verified payment and null `verified_by_name`/`verified_at` for a pending one; cash payments keep `verification: verified` with null `verified_by_name` (no verifier is invented); deleting the verifier user (`nullOnDelete`) keeps `verified_at` and gives `verified_by_name: null` without error
- [X] T021 [P] [US2] Update `qa-tests/component/PaymentHistoryList.test.js`: a verified non-cash entry shows "Diverifikasi oleh {nama} · {tanggal/waktu}" (EN "Verified by …"); with `verified_by_name` null but `verified_at` set it shows "Diverifikasi · {tanggal/waktu}" (no "null"/"undefined"); a verified entry without both fields shows just the pill; cash entries never show a verifier line

### Implementation for User Story 2

- [X] T022 [US2] Add `verified_by_name` (`$p->relationLoaded('verifier') ? $p->verifier?->name : null` — omit the key when the relation is not loaded, mirroring the `recorder` guard) and `verified_at` to each payment in `OrderResource` and `PreorderController::present()`; make sure every endpoint that returns these payloads loads `payments.verifier` (`OrderController` loads from T012; `PreorderService::PAYLOAD_RELATIONS` from T013; check `PreorderController` `index`/`invoice`/bulk loads and `OrderService::create()`'s return and add where payments are rendered)
- [X] T023 [US2] In `PaymentHistoryList.vue` show the line "Diverifikasi oleh {name} · {when}" (`formatDateTime`) for verified non-cash entries, falling back to "Diverifikasi · {when}" when the name is missing; add the keys `verified_by_at` and `verified_at_only` to BOTH locale files under `payment_ledger`
- [X] T024 [US2] Run `APP_ENV=testing php artisan test --filter='PaymentVerification'` on the host and `npx vitest run qa-tests/component/PaymentHistoryList.test.js`; fix until green

**Checkpoint**: US2 delivered.

---

## Phase 5: User Story 3 — Verify several payments from the Sales list (Priority: P2)

**Goal**: From the Sales list an allowed user selects transactions and marks all their pending non-cash payments verified in one confirmed action, with a clear summary of verified and skipped.

**Independent Test**: Make three QRIS sales, select them in the Sales list, "Mark verified (3)", confirm → the summary says 3 verified and the badges clear.

### Tests for User Story 3 ⚠️ write first

- [X] T025 [P] [US3] Write the bulk tests in `tests/Feature/PaymentVerificationBulkTest.php` (`POST /api/v1/orders/verify-payments`): as another cashier/owner a mixed selection — pending QRIS payments of several sales, an already-verified one, a cash-only sale, a voided sale with a pending payment, a rejected payment, plus a sale whose payment the CALLER recorded — returns correct `verified`, `verified_orders`, `skipped` by reason (`already_verified`, `own_payment`, `voided`, `rejected`) and `skipped_total`, cash ignored (not counted), and only the right rows changed in the database; the recorder's own payments skipped as `own_payment` while the same call as an owner verifies them; one `payment_verified` audit row per verified payment (none for skipped); unknown / other-mode ids ignored without error; an empty list, a list of 201 ids, non-integer or duplicate ids → 422; idempotent: calling twice verifies once and counts the second run as `already_verified`; a payment that is already verified when the batch reaches it is counted `already_verified` and never aborts the batch (the mid-batch race itself is covered by the single-payment double-click test in T006); money/status/totals/shift cash unchanged; the Sales list `payment_state` of fully-verified sales becomes `verified`
- [X] T026 [P] [US3] Extend `qa-tests/component/SalesView.test.js` (mock `verifyOrderPayments`): selecting rows shows "Tandai terverifikasi ({n})" next to the export button and it is absent with nothing selected; clicking opens a confirm dialog stating it cannot be undone; confirming calls `verifyOrderPayments([ids of the selected rows])` (ids parsed from `order:{id}` keys), then shows a toast summary ("{verified} pembayaran terverifikasi pada {orders} transaksi" plus the skipped reasons only when > 0, e.g. "dilewati: {own} milik Anda, {already} sudah terverifikasi, {voided} batal"), reloads the list and clears the verified rows from the selection; when nothing could be verified it says so ("Tidak ada pembayaran yang bisa diverifikasi"); cancelling the dialog calls nothing; the existing export/selection tests stay green

### Implementation for User Story 3

- [X] T027 [P] [US3] Create `app/Http/Requests/VerifyOrderPaymentsRequest.php` (authorize: authenticated; rules `order_ids` required array min:1 max:200, `order_ids.*` integer distinct; Indonesian docblock: shape only — rules per payment live in `PaymentService`)
- [X] T028 [US3] Add `PaymentService::verifyOrderPayments(array $orderIds, User $user): array` in `app/Services/PaymentService.php` per research.md Decision 3 (load the scoped orders with `payments` in one query batch; for each NON-cash payment: voided order → skip `voided`; `rejected` → skip `rejected`; `verified` → skip `already_verified`; `! mayVerify($user)` → skip `own_payment`; else `markVerified()` in its own transaction, treating a 409/`already verified` thrown by a race as `already_verified`; cash payments ignored; return `{verified, verified_orders, skipped{…}, skipped_total}`); unknown ids never reach the loop
- [X] T029 [US3] Add `OrderController::verifyPayments(VerifyOrderPaymentsRequest $request)` returning the summary JSON (200) and register `POST /orders/verify-payments` in `routes/api.php` BEFORE the `{order}` routes so `verify-payments` is not captured as an id
- [X] T030 [P] [US3] Add the API client `verifyOrderPayments(orderIds)` (`POST /orders/verify-payments`, body `{ order_ids }`, returns the summary) to `resources/js/api/payments.js`
- [X] T031 [US3] In `resources/js/views/SalesView.vue` add the bulk action: a `BaseButton` "Tandai terverifikasi ({count})" beside the export button, shown only when `chosenKeys.length > 0`; a `ConfirmDialog` (cannot be undone); on confirm call `verifyOrderPayments` with the numeric ids of the chosen `order:{id}` keys (ignore keys of other kinds), toast the summary, `await load()`, and keep in `selected` only the rows still pending; add the strings to BOTH locale files under `payment_ledger` (`verify_bulk`, `verify_bulk_confirm_message`, `verify_bulk_result`, `verify_bulk_skipped`, `verify_bulk_nothing`) with ICU-style `{verified}`/`{orders}`/`{own}`/`{already}`/`{voided}`/`{rejected}` placeholders; `data-testid="bulk-verify"` on the button
- [X] T032 [US3] Run `APP_ENV=testing php artisan test --filter='PaymentVerificationBulk|PaymentVerification'` on the host and `npx vitest run qa-tests/component/SalesView.test.js qa-tests/component/SalesViewAdvanced.test.js`; fix until green

**Checkpoint**: US3 delivered.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T033 [P] Update `docs/openapi-pos-mvp.yaml`: the two single-verify routes and `POST /orders/verify-payments` (schemas per contracts/payment-verification.md; 200/403/404/409/422), the added payment fields (`verified_by_name`, `verified_at`, `can_verify`) on the sale and pre-order payment schemas, and a note that there is deliberately no un-verify route; validate the YAML parses
- [X] T034 [P] Update `CLAUDE.md` "Payment ledger" section (after the 031 subsection): verification is a one-way service action (`PaymentService::markVerified`, bulk wrapper), who may verify (`Payment::mayVerify` — everyone except the recorder, owner/admin always, legacy NULL recorder anyone), final / no undo (product-owner decision), it never touches money/status/shift cash, the `verifier` relation must be eager-loaded wherever payments are presented (relationLoaded trap), and that `payment_state` on the Sales list is derived (worst of the payments); keep the SPECKIT pointer as is
- [X] T035 Run the full suites: `APP_ENV=testing php artisan test` on the HOST, `npx vitest run`, `npm run build`; record counts and fix any regression (watch tests that assumed payment payload shapes or the Sales toolbar)
- [X] T036 Real-browser verification on an ISOLATED server + `boothpos_test` per quickstart.md: seed (`db:seed`, `license:dev-activate`, a QRIS channel, a product with stock, open sessions for `kasir01` and `kasir02`); as `kasir01` make three QRIS sales (one with a split of two QRIS payments) and one cash sale → "Not verified" badges; opening a sale shows NO verify action for `kasir01` (own); as `kasir02` open a sale → "Mark verified" → confirm ("cannot be undone") → entry shows "Verified by … · date", list badge clears (split sale clears only after BOTH payments); no undo anywhere; as `owner` filter "Needs verification", Select all, "Mark verified (N)" → summary toast, rows verified; as `kasir01` bulk-select their own sales → summary says they were skipped (own); verify a pre-order transfer as `owner`; check the activity log lists `payment_verified` rows; console clean, EN ↔ ID; save screenshots under `specs/032-mark-payment-verified/evidence/` (no customer data)
- [X] T037 Final diff review against the constitution: single write path kept (`PaymentRecorder` only creator), the rules (`isVerifiable`/`mayVerify`) defined once and reused by service/controllers/bulk/presenters, one-way transition enforced server-side with NO un-verify path, row lock + guard make verification single-shot, audit inside the transaction for each verification, nothing writes amount/method/status/totals/shift cash, bulk is a loop over the single path (no second implementation), no `relationLoaded` trap left (`payments.verifier` loaded wherever payments are presented), no raw hex colours, all strings in both locales, Indonesian comments, evidence screenshots free of customer data
- [ ] T038 Ask the reporter to try it once on the real app (verify a QRIS payment as a user who did not record it, then try the bulk action from the Sales list) and note their confirmation in the final report

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories → Polish.** Phase 2 (model rules + messages) blocks everything. **US1** is the MVP (service + endpoints + single-payment UI). **US2** builds on US1 (adds the attribution fields and display; the audit row itself is written by T011 and verified by T020). **US3** reuses `markVerified()` from US1 and the same list component.
- Inside US1: tests T006–T010 first (they fail), then service (T011) → controllers/routes (T012, T013) → payload flag (T014) → client (T015 [P]) → list component (T016) → callers (T017, T018) → green run (T019).
- Same-file serialisation: `tests/Feature/PaymentVerificationTest.php` (T006, T007, T020 — one after another), `routes/api.php` (T012, T013, T029), `app/Services/PaymentService.php` (T011 then T028), `app/Http/Resources/OrderResource.php` and `PreorderController::present()` (T014 then T022), `resources/js/components/payment/PaymentHistoryList.vue` (T016 then T023), `resources/js/api/payments.js` (T015 then T030), `resources/js/locales/{en,id}.json` (T016, T023, T031 — add keys one after another), `qa-tests/component/PaymentHistoryList.test.js` (T008 then T021).

## Parallel examples

- After T005: T006/T007 (one file, sequential), T008, T009, T010 are three other files → write together; T015 is independent of the service.
- US3 tests T025 (backend) and T026 (frontend) and the request class T027 touch different files → together; T030 is independent.
- Polish T033 and T034 are independent doc edits.

## Implementation strategy

1. **MVP**: Phases 1–3 — one payment can be verified, finally, by an allowed user (the "Not verified" badge finally clears). Stop and validate with the reporter.
2. + **US2** (who/when + audit trail), then **US3** (bulk), then Polish and the isolated real-browser check.

### Notes

- Commit as one Indonesian-message docs commit (specs/032 + `.specify/feature.json`) and one implementation commit; do not push or open a PR without explicit instruction.
- If any pre-existing test asserts the old payment payload shape strictly (exact key sets) and fails after adding the new keys, update it to the new shape (do not delete it) and mention it in the final report.
- If the real-browser check shows the recorder can verify their own payment, or any way to un-verify exists, STOP and fix the server-side rule before anything else — the SPA flags are only a convenience.
