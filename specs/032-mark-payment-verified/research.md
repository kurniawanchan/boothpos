# Research: Mark a Non-Cash Payment as Verified

## Evidence (current behaviour)

- `PaymentRecorder` stores `verification = 'cash' ? 'verified' : 'pending'` and never touches it again; no route, service or UI changes it (`grep` of `verification` in `app/` shows only reads/filters).
- Columns exist since the original schema: `payments.verification` enum(`pending`,`verified`,`rejected`), `verified_by` (FK users, nullOnDelete), `verified_at`, `reject_reason`; `Payment` casts `verified_at` to datetime and lists these in `$fillable`; there is no `verifier` relation.
- Shift summary (`CashierSessionController::summary()`, shown on the Cashier Session screen): `by_method` counts only `verification = 'verified'` payments of ALL methods, so a pending QRIS payment is absent until it is verified — an existing rule, and the only place verification is visible besides the badge. Expected cash (`close()`) is cash-only and unaffected.
- Readers: `SalesTransactionsService::paymentState()` (rejected > pending > verified > none) feeds the Sales list badge ("Not verified") and the existing `pstate` filter (option "Needs verification"); `PaymentSummary` and the reports only exclude `rejected`; shift cash counts `method = cash AND verification = verified` (cash is verified at creation) — so changing a NON-cash payment to `verified` cannot move any figure (FR-010).
- Payment payloads (`OrderResource`, `PreorderController::present`) already return `verification`; they do not return who/when. `PaymentHistoryList` shows only "Paid"/"Rejected".
- The Sales list already has row selection (`selected` set of `order:{id}` keys), an Export button using it, and `pstate` filtering; the activity-log screen shows raw action names (no label mapping to maintain).

## Decision 1 — A one-way, row-locked service action; no undo anywhere

**Decision**: `PaymentService::markVerified(Preorder|Order $target, Payment $payment, User $user)`:
1. lock the target (like every other ledger mutation);
2. payment must belong to the target (404 otherwise);
3. target must be open: voided sale / cancelled pre-order → 409 (a handed-over pre-order is fine — nothing moves);
4. `Payment::isVerifiable()`: non-cash AND `verification === 'pending'` — cash → 422 (`errors.method`), `verified` → 409 "already verified", `rejected` → 409 "rejected payments cannot be verified";
5. `Payment::mayVerify($user)` (owner/admin, or `recorded_by` NULL, or `recorded_by !== user`) → else 403 (also guarded in the controller before the lock, repeated in the service);
6. set `verification='verified'`, `verified_by=user`, `verified_at=now()`; activity log `payment_verified` (`old_values`: payment id + `verification: pending`; `new_values`: payment id, `verification: verified`, `verified_by`, `verified_at`) in the same transaction.
There is deliberately **no** un-verify route/service method/UI (spec Q2=B, FR-009) — the one-way state machine is enforced by the guard in step 4, so a repeated or stale request is a harmless 409, never a second log row.

**Rationale**: Mirrors the ledger discipline of 028/031. The row lock makes "two people verify at once" deterministic (second gets 409, one verifier recorded).

## Decision 2 — "Who may verify": exclude the recorder, defined once

**Decision**: `Payment::mayVerify(User)` = `isOwnerOrAdmin() || recorded_by === null || recorded_by !== user.id`. Owner/admin may verify payments they recorded themselves (spec assumption). The SPA only receives the server-computed `can_verify` (never derives it from role/ids). Access to the endpoints is the same as the other payment endpoints (any authenticated user); the Sales/Pre-order screens that expose the action are the ones those roles already reach (spec: "any user with access to the transaction").

**Rationale**: Separation of duties is the point of Q1=C; legacy rows without a recorder have no conflict of interest to protect.

## Decision 3 — Bulk = loop over the single-payment path, with skip reasons

**Decision**: `POST /orders/verify-payments { order_ids: [...] }` (1–200 distinct ids; the Sales list sends the ids of its selected `order:{id}` keys). `PaymentService::verifyOrderPayments(array $orderIds, User $user)`: load the orders (scoped, `payments.verifier`) in one query batch, then for every NON-cash payment: voided order → skip `voided`; `rejected` → skip `rejected`; already verified → skip `already_verified`; recorded by the caller (and caller not owner/admin) → skip `own_payment`; else call `markVerified()` (own transaction + own audit row). Cash payments are ignored silently (they are verified by design and would only inflate "skipped"). Response: `{ verified, verified_orders, skipped: { already_verified, own_payment, voided, rejected }, skipped_total }` (counts are PAYMENTS except `verified_orders`). A race that makes `markVerified` throw 409 mid-loop is counted as `already_verified`, never aborting the batch.

**Rationale**: One implementation of the rules (Constitution I); per-payment transactions keep locks short and make partial progress safe (each verification is independent and idempotent).

## Decision 3b — Unknown/other-mode order ids

Ids that don't resolve through the scoped `Order` lookup (deleted, other DEMO/LIVE mode) are ignored (not counted) — they never reach the service; the form request only bounds the list size and shape.

## Decision 4 — Payload additions computed server-side, with the usual `relationLoaded` guard

Each payment in sale and pre-order payloads gains `verified_by_name` (needs `payments.verifier` — loaded wherever `payments.recorder` is, incl. `PreorderService::PAYLOAD_RELATIONS`; omitted, never wrongly "none", when not loaded), `verified_at`, and `can_verify` (`isVerifiable() && mayVerify($user) && target open`). Existing `verification` stays. Legacy/other endpoints that don't send the new keys render exactly as before (SC: no regression).

## Decision 5 — UI: confirm before the (final) action; reuse list + filter

- `PaymentHistoryList`: non-cash entry with `verification === 'pending'` → warn "Not verified" pill + "Mark verified" action when `can_verify`; `verified` → mint "Verified" pill with "Verified by {name} · {date/time}" line; cash and entries without the new fields unchanged.
- Both single and bulk actions open a `ConfirmDialog` stating the action cannot be undone (2 interactions after opening the details, SC-001).
- `SalesView`: "Mark verified (N)" next to Export when rows are selected; combined with the existing `pstate=pending` filter + "Select all" this is the "verify the day" flow (SC-006). After the call: toast with the summary (verified count; skipped by reason when > 0), reload the list, keep selection only for rows still pending.
- 409 on a single action (already verified / stale) → refetch the transaction so the entry shows the real state, and toast the server message.

## Decision 6 — Verified non-cash payments DO enter the shift per-method breakdown (accepted)

Found while writing the invariant test: the shift summary's per-method breakdown already filters on `verified`. Marking a QRIS payment verified therefore adds it to that breakdown. Left as is — it is the intended meaning of the existing rule, expected cash and every money figure on the sale are untouched, and changing the summary to count pending payments is a separate product decision. The spec (FR-010/SC-004/Assumptions) and the tests state it explicitly.

## Alternatives considered

| Alternative | Why rejected |
|---|---|
| Allow un-verify for owner/admin | Contradicts the requester's answer (Q2=B: final); a bad tick is accepted as permanent and traceable through the log. |
| Verify via `PATCH` on the payment with a `verification` field | A generic state setter would invite `pending`/`rejected` writes (undo/reject) that are out of scope; a dedicated action endpoint cannot express them. |
| Bulk operates on payment ids | The Sales list works in transactions; selecting payments would need a payment-level list the screen doesn't have. Order ids + server-side expansion keeps the client from sending state. |
| One DB transaction for the whole bulk batch | A single conflict would roll back dozens of valid verifications and holds many row locks at once; independent per-payment transactions are safer. |
| Verifier name stored as text on the payment | Duplicates `users.name` and goes stale; the FK already exists. |
| Hide "Not verified" until verification exists | Not requested; the badge is exactly what the new action clears. |

## Risks / notes

- `verified_by` is `nullOnDelete`: if the verifier user is later deleted, `verified_by_name` is null while `verified_at` remains — the UI shows "Verified" with the date only.
- Bulk response counts are payments (not transactions) except `verified_orders`; documented in the contract so the toast wording is exact.
- The `pstate` filter label is "Needs verification" while the badge says "Not verified" — kept as is (existing copy).
