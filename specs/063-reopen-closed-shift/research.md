# Research: Owner Reopens a Closed Shift

**Classification**: INTERNAL. Facts below are read from the code in this session; anything not verified is labelled ASSUMPTION.

## D1 — Reopening restores the plain *open* shape; the old figures go to an audit table

- **Decision**: reopen sets `status='open'`, `closed_at=NULL`, `closing_cash`, `expected_cash`, `cash_difference` = NULL, and
  writes one `cashier_session_reopens` row holding the values it just cleared.
- **Rationale (fact)**: `CashierSessionQuery::detail()` already treats `status='closed'` as "use stored figures" and
  everything else as "compute live", and `close()` writes all four fields unconditionally, so a shift in the open shape
  needs **no change to `close()`** and cannot show stale reconciliation figures. The four columns are nullable
  (`create_cashier_sessions_table`).
- **Alternatives rejected**: keep the old figures on the row while open (the detail would then show a closing count for an
  open shift, and a forgotten re-close would leave a wrong "closed-looking" record); add a `reopened` boolean on the shift
  (derivable from the audit rows, a second source of truth).

## D2 — Dedicated append-only table instead of only `activity_logs`

- **Decision**: new `cashier_session_reopens`; also write one `activity_logs` row (`session_reopened`).
- **Rationale**: FR-007 needs the history to be retrievable per shift and rendered in the detail. `activity_logs` has
  generic `old_values/new_values` JSON and no per-entity query path used by the SPA; the table gives typed columns and a
  simple `hasMany`. The log row keeps the project's "sensitive mutation is logged" rule (Constitution IV) and is written in
  the same transaction via `ActivityLogger`.
- **Alternative rejected**: `activity_logs` only — would need JSON parsing and a new read path, and its description text is free-form.

## D3 — Owner-only is a fixed rule, not a `MenuActions` action

- **Decision**: inline `$request->user()->role?->isOwner()` in the controller (same primitive `SellerGate` uses); 403 otherwise.
  The route is added to `MenuActionsRouteCoverageTest::EXEMPT` with a reason.
- **Rationale (fact)**: 057 states the Owner role is always full and cannot be restricted, and `isOwnerOrAdmin()` is
  `canAccessMenu('settings')`, i.e. true for admins. The spec says Owner only and that no role setting may grant it. A
  named `session.reopen` action would appear in the role editor and imply it can be delegated; keeping it out of
  `MenuActions` makes delegation impossible by construction. `EXEMPT` already documents routes that are not under a menu action.
- **Alternatives rejected**: `ability:session,reopen` plus an inline Owner check (the grid would show a ticket box that
  never works); reuse `ability:session,edit` (a cashier with Edit could reach it before the inline check — and `edit` is
  what lets them close shifts).
- **Seller scope (058)**: `sessions` is already in `ForbiddenSurfacesTest::SCOPE_AWARE_SEGMENTS`; `SellerGate` ignores the
  Owner role, so no change. To confirm in the test.

## D4 — Concurrency and "one open shift per user"

- **Fact**: `POST /sessions` refuses a second open shift for a user with a plain `exists()` check; there is no DB unique
  constraint on (user, open).
- **Decision**: inside the transaction, `lockForUpdate` the shift row, re-check `status='closed'` (else 409
  `session_not_closed`), then `lockForUpdate` the **cashier's user row** and check the cashier has no other open shift
  (else 409 `cashier_has_open_session`). Locking the user row serialises two reopens for the same cashier and a concurrent
  `POST /sessions`-style race is no worse than today (that endpoint is unchanged).
- **Rationale**: two Owners reopening two different old shifts of one cashier would otherwise both pass the check.

## D5 — Money attribution after a reopen (consequence to document, not to change)

- **Fact**: `PaymentService::receivingSessionId()` attributes a new cash/non-cash payment to the recording user's **open**
  shift. `deletePayment()` refuses deleting a cash payment of a **closed** shift, and `changeMethod()` already permits
  re-categorising cash received in a closed shift (054, logged).
- **Consequences**: (a) once reopened, the cashier's shift is their open shift, so *their* new payments and POS sales attach
  to it until it is closed again; the dialog warns the Owner of this. (b) With the shift open, cash payments recorded in it
  can be deleted by owner/admin as for any open shift. (c) Money received in another shift is never moved (spec assumption).
- **Verified (T018, test `test_a_pos_sale_under_a_reopened_shift_of_an_ended_event_is_accepted`)**: a POS sale rung up under a reopened shift of an *ended* event is accepted; `POST /orders` has no event-status guard. So after a reopen the cashier can keep selling into that shift until it is closed, which is why the Owner's dialog carries the warning.
- **Verified (T017)**: `close()` and `CashierSessionQuery::expectedCash()` needed no change; a reopened shift recomputes expected cash and difference on re-close (`test_reopen_adjust_and_close_again_...`).
- **Baseline (T001)**: before any change, `CashierSession|MenuActionsRouteCoverageTest|CloneRegistryDriftTest|ForbiddenSurfacesTest|ShiftCashAttribution` = 41 passed (442 assertions).

## D6 — Settlements, recaps, reports are untouched (FR-008, FR-009)

- **Fact (CLAUDE.md, 040/076)**: the seller recap and seller settlements aggregate `order_items`/payments, not shift
  closing figures; event close writes recap totals from completed POS items.
- **Decision**: the reopen service never calls `SettlementService`/`SellerSettlementService`, and has no event-status
  condition (FR-009 = always allowed for the Owner). A test asserts recap rows and a finalised settlement are byte-identical
  before and after a reopen.

## D7 — Read model: server-computed flags

- **Decision**: `GET /sessions/{id}` adds `can_reopen` (Owner AND closed) and `reopens[]`; `GET /sessions` rows add
  `reopen_count` (one `withCount`). The SPA never derives permission from the role (project convention, 057/031).
- **Visibility**: whoever may already view a shift (`CashierSessionQuery::mayView`: owner/admin or its own cashier) sees
  `reopens[]`, matching User Story 3.

## D8 — DEMO/LIVE clone

- **Fact**: `CloneRegistry` lists `cashier_sessions` and child tables ride their parent with `via`; `users` is a shared
  parent so `reopened_by` needs no mapping; `CloneRegistryDriftTest` fails if a new FK table is unregistered.
- **Decision**: no `data_mode` column on the new table; register `cashier_session_reopens` with
  `fks => ['session_id' => 'cashier_sessions']`, `via => 'cashier_sessions.session_id'`, placed after `cashier_sessions`.
  The copied `reopened_by` points to the same user (shared), and the history is mirrored with the shift.

## D9 — Test plan (feature tests on MySQL)

1. Owner reopens → shift open, closing figures NULL, reopen row has the previous figures, `activity_logs` row written.
2. Admin, cashier (own shift), inventory → 403, nothing changed. A custom role granted every action on `session` → still 403.
3. Open shift → 409; blank/missing reason → 422; unknown shift → 404 (DEMO/LIVE scoping).
4. Cashier already has another open shift → 409, nothing changed.
5. Reopen, add a cash payment, close with a new count → expected cash/difference recomputed; reopen row keeps the *first*
   close's figures; a second reopen adds a second row (append-only).
6. Reopening does not change recap rows, a finalised settlement, stock or order data.
7. Event closed/ended → still allowed (FR-009).
8. Rollback: failure after the status update leaves neither a changed shift nor a log row.
9. `MenuActionsRouteCoverageTest` stays green with the new EXEMPT entry; `CloneRegistryDriftTest` green; seller-scoped Owner unaffected.
10. Vitest: button only when `can_reopen`, reason required, history renders, list badge.

## D10 — Out of scope (stays out, per spec)

New adjustment screens, moving money between shifts, un-reopen/cancel-reopen, notifications to the cashier, a reopen-count limit.
