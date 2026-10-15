---

description: "Task list for Optional Payment Proof, Addable Later from Sales Detail"
---

# Tasks: Optional Payment Proof, Addable Later from Sales Detail

**Input**: Design documents from `/specs/031-optional-payment-proof/`

**Prerequisites**: plan.md, spec.md, research.md (Decisions 1–6), data-model.md, contracts/payment-confirmation.md, quickstart.md

**Tests**: INCLUDED. Constitution II requires tests; this feature touches money records and object-level authorization, so the authorization matrix, the closed-target rules and the "totals never change" invariant are tested server-side, plus component tests and a real-browser check on an ISOLATED server.

**Organization**: US1 Complete a non-cash sale without a proof (P1) · US2 Add or change the confirmation later from the Sales detail (P1) · US3 Same rule and later-confirmation for pre-order payments (P2). The shared groundwork (migration, model rules, messages) is Foundational.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an incomplete task)
- **[Story]**: US1–US3, only on user-story phase tasks
- Code comments, docs and commit messages in Indonesian (project convention); every new UI string goes in BOTH `resources/js/locales/en.json` and `id.json`; backend messages in BOTH `lang/en/orders_payments.php` and `lang/id/orders_payments.php`

## Safety reminders

- **Never run `php artisan test` inside the `app` container without** `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test …`. Prefer running tests on the HOST (`.env.testing` → `boothpos_test`). Never `migrate:fresh`/`db:wipe`/`db:seed` against the dev DB (it has real data).
- The migration is additive (one nullable column). The dev DB gets it when the `app` container starts (entrypoint migrates) or via `docker compose exec app php artisan migrate`; do not run it unprompted — tell the user.
- The real-browser check WRITES data (sales, proofs): use an isolated server (`php artisan serve --port=8091` with `.env.testing` values, e.g. `APP_ENV=testing`) against `boothpos_test`, seeded with `db:seed` + `license:dev-activate`; never the dev app on :8000. `php artisan test` wipes `boothpos_test`, so re-seed afterwards.
- Never print or echo the DB password or an API token in commands/outputs.
- Check free Docker disk space before long runs.

---

## Phase 1: Setup

- [X] T001 Record the baseline on the host: `php artisan test --filter='OrderTest|PreorderTest|PreorderPaymentLedgerTest|SplitPaymentTest|PosPartialPaymentTest|PreorderPaymentDeleteTest|PaymentNotesTest|PurchaseOrderTest'` and `npx vitest run qa-tests/component/PaymentPanel.test.js qa-tests/component/PosPaymentModal.test.js qa-tests/component/TransactionItemsModal.test.js qa-tests/component/PreordersView.test.js`; all green before any change (note the counts)

---

## Phase 2: Foundational (blocks all stories)

**Purpose**: The schema column, the three model rules every story relies on, and the backend messages.

- [X] T002 [P] Write failing tests in a new `tests/Feature/PaymentConfirmationRulesTest.php` for the model rules (use factories; roles via `User::factory` as the other payment tests do): `Payment::confirmationEditableBy($user)` — owner true, admin true, the recording cashier true, another cashier false, cashier on a payment with NULL `recorded_by` false, inventory role false, and false for a cash payment; `Payment::currentProof()` — null with no proofs, the single proof, the latest non-superseded proof when an older one has `superseded_at`, null when all are superseded; `Payment::proofViewableBy($user)` — owner/admin true, the proof's uploader true, the payment's recorder true for the CURRENT proof, false for another cashier, false for the recorder on a SUPERSEDED proof
- [X] T003 Add migration `database/migrations/2026_11_01_000001_add_superseded_at_to_payment_proofs_table.php` (nullable `timestamp superseded_at`; Indonesian docblock: why a timestamp instead of unlinking the row, see research.md Decision 3; `down()` drops it); add `superseded_at` to `PaymentProof::$fillable` and a `datetime` cast in `app/Models/PaymentProof.php`
- [X] T004 In `app/Models/Payment.php` implement `currentProof(): ?PaymentProof` (uses the loaded `proofs` relation when present, else queries; non-superseded, latest id), `confirmationEditableBy(User $user): bool` and `proofViewableBy(User $user, ?PaymentProof $proof = null): bool` exactly per data-model.md (single source for the service, the controller guard, the presenters and `PaymentProofController`); Indonesian docblocks citing spec FR-009/FR-014
- [X] T005 [P] Add backend messages to BOTH `lang/en/orders_payments.php` and `lang/id/orders_payments.php`: `channel_required_for_non_cash`, `payment_confirmation_not_allowed`, `payment_confirmation_empty` (nothing sent / would leave nothing), `payment_confirmation_cash` (cash payments have none), `payment_confirmation_target_closed` (voided sale / cancelled pre-order)
- [X] T006 Run `php artisan test --filter=PaymentConfirmationRulesTest` on the host; fix until green (T002 tests must have failed before T003/T004)

**Checkpoint**: schema and rules ready.

---

## Phase 3: User Story 1 — Complete a non-cash sale without a proof (Priority: P1) 🎯 MVP

**Goal**: Photo/file, reference and notes are all optional when paying by QRIS/bank transfer — at POS checkout and for later payments; the Confirm button no longer waits for a proof.

**Independent Test**: At POS pay by QRIS with nothing attached → the sale saves; the payment exists without a proof.

### Tests for User Story 1 ⚠️ write first

- [X] T007 [P] [US1] In `tests/Feature/OrderTest.php` rewrite `test_non_cash_payment_without_proof_token_is_rejected` into `test_non_cash_payment_without_proof_token_is_accepted` (201, payment row exists, no `payment_proofs` row linked, `verification` still `pending`); add: a split checkout with one proof-less and one proof-bearing non-cash entry succeeds; a non-cash entry with an UNKNOWN or already-linked `proof_token` is still refused (409, no order); the existing valid-token test stays
- [X] T008 [P] [US1] In `tests/Feature/PreorderPaymentLedgerTest.php` rewrite `test_a_non_cash_payment_still_needs_a_channel_and_proof` into two tests: a non-cash payment WITHOUT a channel is still refused with a clean 422 (not a DB 500), and a non-cash payment WITH a channel but NO proof is accepted (201 via `POST /preorders/{p}/payments`, no proof row, totals/status as for any payment); add the same pair for `POST /orders/{o}/payments`; an invalid token is still 422
- [X] T009 [P] [US1] In `tests/Feature/PurchaseOrderTest.php` add a test pinning that a NON-CASH purchase-order payment WITHOUT a proof is still refused (the rule is unchanged for purchase orders); locate the existing PO payment test helper first
- [X] T010 [P] [US1] Update `qa-tests/component/PaymentPanel.test.js`: for a non-cash method with a channel chosen and NO proof the confirm action is enabled in both `checkout` and `record` modes and the emitted payload has `proof_token: null`; with a proof attached the payload carries the token as before; the confirm action stays disabled while a proof upload is in flight; the old "A payment proof must be attached before confirming" text is gone and the neutral hint shows (EN and ID); fix any assertions in `qa-tests/component/PosPaymentModal.test.js` / `AddPaymentModal` tests that assumed proof was required

### Implementation for User Story 1

- [X] T011 [US1] In `app/Services/PaymentRecorder.php` stop requiring a `proof_token` for non-cash payments of sales and pre-orders (keep the requirement when `$purchaseOrderId !== null`); a PROVIDED token must still resolve to an unlinked proof (else `proof_token_invalid`); add an explicit check that a non-cash payment carries a `channel_id` (new message `channel_required_for_non_cash` in BOTH lang files) because the DB constraint `chk_payments_channel` would otherwise surface as a 500 once the proof check no longer guards it; update the class/method docblock (Indonesian) citing feature 031; remove the now-misleading comment in `OrderService`/`PreorderService` if any mentions the requirement
- [X] T012 [US1] In `resources/js/components/payment/PaymentPanel.vue` remove `proofToken !== null` from `canSubmitCurrent` (both the `record` branch and the checkout branch) while keeping the `uploading` guard, and replace the `pos.proof_required_before_confirm` hint with the new neutral key (`pos.proof_optional_hint`: "Attaching a proof is optional — you can add it later from the sales detail." / Indonesian equivalent); add the new key to BOTH `resources/js/locales/en.json` and `id.json` and delete the unused `proof_required_before_confirm` from both
- [X] T013 [US1] Run `php artisan test --filter='OrderTest|PreorderPaymentLedgerTest|PurchaseOrderTest|SplitPaymentTest|PosPartialPaymentTest'` on the host and `npx vitest run qa-tests/component/PaymentPanel.test.js qa-tests/component/PosPaymentModal.test.js`; fix until green

**Checkpoint**: US1 delivered — sales/pre-orders can be confirmed without a proof; POs unchanged.

---

## Phase 4: User Story 2 — Add or change the confirmation later from the Sales detail (Priority: P1)

**Goal**: From a sale's Transaction details, an owner/admin or the cashier who recorded a non-cash payment can add or change its proof, reference and notes; entries without a proof are marked; the proof opens for those allowed.

**Independent Test**: Make a proof-less QRIS sale, open its detail, add a photo + reference + note → they show on the entry and the proof opens; another cashier sees the text but no actions.

### Tests for User Story 2 ⚠️ write first

- [X] T014 [P] [US2] Write `tests/Feature/PaymentConfirmationTest.php` (sale path, `PATCH /api/v1/orders/{order}/payments/{payment}/confirmation`; helpers to create a sale with a non-cash proof-less payment recorded by a given cashier): authorization matrix — owner ok, admin ok, recording cashier ok, another cashier 403, inventory 403, NULL-recorder payment: cashier 403 / owner ok; add proof + reference + notes → 200 with the new values in the payload; only reference; only notes; only proof; empty body → 422 `confirmation`; cash payment → 422; payment of another sale → 404; voided sale → 409; unknown or already-linked `proof_token` → 422 and nothing changed; reference > 100 or notes > 1000 → 422; clearing the reference while notes remain is OK; an edit that would leave reference, notes and proof all empty → 422; replacing a proof: old row has `superseded_at`, old FILE still exists on the private disk, new proof is the payload's `proof_id`; activity log row `payment_confirmation_updated` with old/new reference, notes and proof ids and the acting user; **invariants**: amount, method, channel, `paid_at`, `session_id`, order `paid_amount`/`status`/totals, the shift's expected cash, `payment_summary` are identical before/after; DEMO/LIVE: a payment of the other mode → 404
- [X] T015 [P] [US2] In `tests/Feature/PaymentConfirmationTest.php` (or `PaymentProofViewingTest.php`) cover `GET /api/v1/payment-proofs/{proof}/file`: owner ok; uploader ok; the payment's recorder ok for the CURRENT proof added by an owner; another cashier 403; the recorder on a SUPERSEDED proof 403; owner on a superseded proof ok (audit); and that the sale payload's per-payment flags match (`can_view_proof`, `can_edit_confirmation`, `has_proof`, `notes`, `proof_id`) for owner / recording cashier / other cashier, for a cash payment (`can_edit_confirmation` false, no `has_proof` marker needed) and for a voided sale (`can_edit_confirmation` false)
- [X] T016 [P] [US2] Add `qa-tests/component/PaymentHistoryList.test.js` (new or extend the existing one): a non-cash entry without a proof shows the "No proof" marker; a cash entry does not; notes are shown; "View proof" appears only when `proof_id && can_view_proof`; "Add confirmation" (no proof/reference/notes yet) vs "Edit confirmation" (something exists) appears only when `can_edit_confirmation`, and clicking emits `edit-confirmation` with the entry; entries from before this feature (no new fields) render exactly as before
- [X] T017 [P] [US2] Add `qa-tests/component/PaymentConfirmationModal.test.js`: opens prefilled with the entry's reference/notes; the save action is disabled until something is entered or changed; saving with only text sends one PATCH with just those keys (no upload); picking a photo uploads it first (`uploadPaymentProof`) then PATCHes with `proof_token`; the save action is disabled while an upload is in flight; a server refusal (403/409/422) shows its message and leaves the modal open; success emits the updated record and closes; a replace note is shown when the entry already has a proof (EN and ID)
- [X] T018 [P] [US2] In `qa-tests/component/TransactionItemsModal.test.js` add: the sale's payment entries show the "No proof" marker and notes; as owner the "Add confirmation" action opens the modal and, after saving, the entry re-renders from the response with the new reference/notes and a "View proof" action; as a user without `can_edit_confirmation` no action is offered; "View proof" opens the lightbox via the proof blob URL; a voided sale offers no edit action

### Implementation for User Story 2

- [X] T019 [US2] Add `PaymentService::updateConfirmation(Preorder|Order $target, Payment $payment, array $input, User $user): Preorder|Order` in `app/Services/PaymentService.php` following research.md Decision 2 exactly (lock target; payment belongs to target else 404; closed rule: voided sale / cancelled pre-order → 409 `payment_confirmation_target_closed`, handed-over pre-order allowed; non-cash only → 422; `confirmationEditableBy` → 403 via a `ValidationException::status(403)` or an `AuthorizationException` consistent with the controller mapping; at least one key; token must resolve to an unlinked proof, link it and set `superseded_at` on the previous current proof; reference/notes set with empty → NULL; final-state-not-empty check; activity log `payment_confirmation_updated` with old/new values inside the same transaction; return `reload()`); Indonesian docblock with a `BUG YANG DITEMUKAN`-style note on why this is not a general payment edit
- [X] T020 [P] [US2] Create `app/Http/Requests/UpdatePaymentConfirmationRequest.php` (authorize: authenticated; rules per contracts/payment-confirmation.md: `proof_token` nullable uuid, `reference` nullable string max:100, `notes` nullable string max:1000; Indonesian docblock: shape only, business rules in the service)
- [X] T021 [US2] Add `OrderController::updatePaymentConfirmation()` (guard with `Payment::confirmationEditableBy` → 403 `payment_confirmation_not_allowed` BEFORE the service, mirror `destroyPayment()`'s ValidationException→status mapping, return `OrderResource` loaded with `items.variant.product.category`, `items.artist`, `payments.channel`, `payments.recorder`, `payments.proofs`, `customer`, `cashier`, `event`); add `payments.proofs` to the eager-loads of `show()`, `storePayment()` and `destroyPayment()`; register `PATCH /orders/{order}/payments/{payment}/confirmation` in `routes/api.php` next to the existing payment routes
- [X] T022 [US2] In `app/Http/Resources/OrderResource.php` extend each `payments[]` entry with `notes`, `proof_id` (via `currentProof()`), `has_proof`, `can_view_proof`, `can_edit_confirmation` (computed with the request user and the order status for the closed rule), guarded with `relationLoaded('proofs')` so endpoints that don't load proofs omit them instead of failing; keep every existing key and its value untouched
- [X] T023 [US2] In `app/Http/Controllers/Api/PaymentProofController.php::show()` extend the authorization to `Payment::proofViewableBy($user, $proof)` (owner/admin, uploader, or the payment's recorder for the current proof) — keep the 403 message and the file headers; update the method comment (Indonesian) to explain the recorder case
- [X] T024 [P] [US2] Add the API client `updatePaymentConfirmation(kind, targetId, paymentId, payload)` (kind `orders`|`preorders`) next to `uploadPaymentProof` in `resources/js/api/payments.js`, returning the updated record
- [X] T025 [US2] Create `resources/js/components/payment/PaymentConfirmationModal.vue` (BaseModal; `ProofCapture` + reference input (maxlength 100) + notes textarea; props: open, payment entry, target kind/id; flow per research.md Decision 6 and T017; replace note; focus-trap/Escape like sibling modals; no raw hex colours) and add the locale keys to BOTH `en.json` and `id.json` under `payment_ledger`: `no_proof`, `add_confirmation`, `edit_confirmation`, `confirmation_title`, `confirmation_save`, `confirmation_replace_note`, `confirmation_saved`, `confirmation_needs_something`, `notes_label` (reuse existing keys where they exist)
- [X] T026 [US2] Update `resources/js/components/payment/PaymentHistoryList.vue`: show `notes`; "No proof" pill for non-cash entries without `proof_id`; "View proof" when `proof_id && can_view_proof` (so sales show it too — the `showProof` prop default stays but callers pass true); "Add/Edit confirmation" when `can_edit_confirmation` emitting `edit-confirmation`; entries lacking the new fields render exactly as today (SC-006); update the component docblock (it says entries have no edit path)
- [X] T027 [US2] Wire `resources/js/components/sales/TransactionItemsModal.vue`: pass `:show-proof="true"`, handle `@view-proof` (blob URL through `getPaymentProofBlobUrl` + `ImageLightbox`, revoke on close, toast on failure, same pattern as `PreordersView.viewPaymentProof`), open `PaymentConfirmationModal` on `@edit-confirmation`, replace `order` with the response on success (so `payment_summary`/entries refresh) and emit the existing `changed` event so the Sales list refreshes
- [X] T028 [US2] Run `php artisan test --filter='PaymentConfirmation|PaymentProof|OrderTest|SalesPaymentStatusTest|PosPartialPaymentTest'` on the host and `npx vitest run qa-tests/component/PaymentHistoryList.test.js qa-tests/component/PaymentConfirmationModal.test.js qa-tests/component/TransactionItemsModal.test.js`; fix until green

**Checkpoint**: US2 delivered for sales.

---

## Phase 5: User Story 3 — Same rule for pre-order payments (Priority: P2)

**Goal**: A pre-order deposit/settlement by transfer/QRIS needs no proof, and its confirmation can be added later from the pre-order's payment history exactly like for sales.

**Independent Test**: Record a pre-order deposit by bank transfer with nothing attached, then add a confirmation from the pre-order detail.

### Tests for User Story 3 ⚠️ write first

- [X] T029 [P] [US3] Extend `tests/Feature/PaymentConfirmationTest.php` with the pre-order path (`PATCH /api/v1/preorders/{preorder}/payments/{payment}/confirmation`): the same authorization matrix, add/edit/replace and invariants (pre-order `paid_amount`, status incl. `dp_paid`/`settled`, outstanding, summary unchanged); cancelled pre-order → 409; handed-over pre-order → 200; payment of another pre-order → 404; payload flags (`notes`, `proof_id`, `has_proof`, `can_view_proof`, `can_edit_confirmation`) present for `show()`
- [X] T030 [P] [US3] In `qa-tests/component/PreordersView.test.js` add: the pre-order payment history shows the "No proof" marker, notes and the add/edit action by `can_edit_confirmation`; saving through the modal refreshes the detail's payment entry; the AddPayment flow can be confirmed for a non-cash method without a proof (shared `PaymentPanel` — assert the emitted request has no `proof_token`)

### Implementation for User Story 3

- [X] T031 [US3] Add `PreorderController::updatePaymentConfirmation()` (same guard/mapping as the order one; returns the standard pre-order payload via `present()`) and register `PATCH /preorders/{preorder}/payments/{payment}/confirmation` in `routes/api.php`; in `present()` add `notes`, `has_proof`, `can_view_proof`, `can_edit_confirmation` to each payment and make `proof_id` use `currentProof()` (keep `relationLoaded('proofs')` guards; every `present()` call site already loads `payments.proofs` — verify `show()`, `invoice`, bulk and `PreorderService::PAYLOAD_RELATIONS`, add where missing)
- [X] T032 [US3] Wire `resources/js/views/PreordersView.vue`: pass the same handlers to its `PaymentHistoryList` (`@edit-confirmation` opens `PaymentConfirmationModal` with kind `preorders`; keep the existing `viewPaymentProof`; refresh `detail` from the response and the list row if it shows payment info)
- [X] T033 [US3] Run `php artisan test --filter='PaymentConfirmation|PreorderTest|PreorderPaymentLedgerTest|PreorderPaymentDeleteTest'` on the host and `npx vitest run qa-tests/component/PreordersView.test.js`; fix until green

**Checkpoint**: US3 delivered.

---

## Phase 6: Polish & Cross-Cutting Concerns

- [X] T034 [P] Update `docs/openapi-pos-mvp.yaml`: the two new PATCH routes (schemas per contracts/payment-confirmation.md, 200/403/404/409/422), the added payment fields (`notes`, `proof_id`, `has_proof`, `can_view_proof`, `can_edit_confirmation`) on the sale and pre-order payment schemas, `proof_token` described as optional on the order/payment create bodies (non-cash no longer requires it; PO unchanged), and the proof file endpoint's authorization note; validate the YAML parses
- [X] T035 [P] Update `CLAUDE.md` "Payment ledger — partial and split payments" section: payments are still created only by `PaymentRecorder`, the proof is optional for sales/pre-orders (PO unchanged), and the new confirmation path (`PaymentService::updateConfirmation`, who may use it, what it can never change, supersede-not-delete, viewing rule incl. recorder, flags computed server-side); fix the sentence "History entries have no edit path"; keep the SPECKIT pointer as is
- [X] T036 Run the full suites: `php artisan test` on the HOST (`boothpos_test`), `npx vitest run`, `npm run build`; record counts; fix any regression (watch for tests elsewhere that asserted the old "proof required" behaviour, e.g. `qa-tests/` and `tests/Feature` greps for `proof_required`/`still needs`)
- [X] T037 Real-browser verification on an ISOLATED server + `boothpos_test` per quickstart.md: seed (`db:seed`, `license:dev-activate`, a QRIS channel with image, a product with stock, an open cashier session for `kasir01`); as `kasir01` pay a sale by QRIS with NO proof/reference/notes (button enabled, sale saved) and a split with one proof; open Sales → the transaction: "No proof" marker + "Add confirmation"; add photo + reference + note → shown, "View proof" opens the image; edit the reference; replace the photo; as `kasir02` the same transaction shows text but no actions/no "View proof"; as `owner` can edit; void the sale → no edit action; repeat the add/replace on a pre-order payment; console clean, EN ↔ ID; save screenshots under `specs/031-optional-payment-proof/evidence/` (no customer data)
- [X] T038 Final diff review against the constitution: single write path kept (`PaymentRecorder` only creator), the editability/view/current-proof rules each defined once, server-side authorization on every write and on proof reads, token consumed once, nothing in the confirmation path writes amount/method/status/totals/shift cash, activity log inside the transaction, no `relationLoaded` trap left unloaded, PO rule unchanged, no raw hex colours, all strings in both locales, Indonesian comments including a `BUG YANG DITEMUKAN & DIPERBAIKI` note where a bug-pattern is documented, evidence screenshots free of customer data
- [ ] T039 Ask the reporter to try it once on the real app (pay a QRIS sale with nothing attached, then add the confirmation from the Sales detail) and note their confirmation in the final report; remind them the additive migration must run on the dev DB (container restart or `docker compose exec app php artisan migrate`)

---

## Dependencies & Execution Order

- **Phase 1 → Phase 2 → stories → Polish.** Phase 2 (migration, model rules, messages) blocks US2/US3; US1 needs only T011/T012 and can start right after Phase 1, but keep the order Setup → Foundational so one migration state is tested throughout.
- **US1** is independent (server rule + panel). **US2** depends on Foundational. **US3** depends on US2's service/modal/list (it adds the pre-order controller action, presenter fields and the PreordersView wiring).
- Inside US2: tests T014–T018 first (they fail), then service (T019) → request/controller/routes/resource (T020–T023, T020 [P]) → frontend (T024 [P], T025, T026, T027) → green run (T028).
- Same-file serialisation: `tests/Feature/PaymentConfirmationTest.php` (T014, T015, T029 — one after another), `qa-tests/component/PreordersView.test.js` (T030), `routes/api.php` (T021 then T031), `app/Services/PaymentService.php` (T019 only), `resources/js/locales/{en,id}.json` (T012 then T025 — add keys one after another), `resources/js/components/payment/PaymentHistoryList.vue` (T026 only).

## Parallel examples

- After T001: T002 (rules test) and T005 (messages) are independent files.
- US1 tests T007–T010 touch four different files → write together.
- US2 tests T014/T015 (one file, sequential), T016, T017, T018 (three other files) → together; T020 and T024 are independent of the service.
- Polish T034 and T035 are independent doc edits.

## Implementation strategy

1. **MVP**: Phases 1–3 — proof optional for sales and pre-orders (the blocker at the register). Stop and validate with the reporter.
2. + **US2** (add/edit later from the Sales detail) — the part that makes optional safe.
3. + **US3** (pre-order history), then Polish and the isolated real-browser check.

### Notes

- Commit as one Indonesian-message docs commit (specs/031 + `.specify/feature.json`) and one implementation commit; do not push or open a PR without explicit instruction.
- If a pre-existing test asserts the old "proof required" behaviour somewhere not listed, rewrite it to the new rule (do not delete it) and mention it in the final report.
- If the real-browser check shows a cashier can open or edit a payment they should not, STOP and fix the server-side rule before anything else — the SPA flags are only a convenience.
