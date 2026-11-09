# Research: Pre-order Bulk Actions

**Classification**: INTERNAL

Grounded in the code as read on 2026-11-08: `PreorderService` (esp. `transitionStatus`), `PaymentService::addPayment`,
`PaymentRecorder`, `PreorderController` (`updateStatus`, `updateDispatchStatus`, `storePayment`, `neighbors`, `applyFilters`),
`ShipmentController`, `Preorder::ALLOWED_TRANSITIONS`, `PreordersView.vue` (selection + bulk bar), `DataTable.vue`, the 064
bulk-approve implementation. Anything not verified is marked ASSUMPTION or "verify at implementation".

## D1 — Reuse by extraction, never by copy

- **Status**: `PreorderService::transitionStatus($preorder, $status, $reason, $user)` already holds every rule (valid
  transition, fully-paid check for `handed_over`, stock deduction, auto-settle on arrival) in one transaction. Bulk calls it.
- **Payment**: `PaymentService::addPayment($preorder, $input, $user, &$replayed)` already row-locks, replays by `client_ref`,
  refuses overpay/closed/fully-paid, syncs the cache and writes its own audit row. Bulk calls it.
- **Dispatch marker**: the rule lives INSIDE `PreorderController::updateDispatchStatus` (cancelled → 409, `shipping` only for
  courier, date rules). Extract to `PreorderService::setDispatchStatus(Preorder, string): Preorder` throwing the same
  `ValidationException(...)->status(409)`; the controller delegates; its tests must stay green unchanged.
- **Shipment**: the rule lives INSIDE `ShipmentController::store` (courier-only 409, already-exists 409, required fields).
  Extract to a new `ShipmentService::createFor(Preorder, array $data): Shipment`; the controller delegates (same status codes).
- **Shared "why can't this move" answer**: preview must explain skips without re-deriving rules. Add
  `PreorderService::statusBlocker(Preorder, string $target): ?array` (`[code, message]`, null = allowed) that
  `transitionStatus()` itself uses for its two pre-checks (`canTransitionTo`, `handed_over` outstanding). Same idea for the
  marker (`setDispatchStatus` throws; preview calls a `dispatchBlocker()` that it also uses). Stock for `handed_over` is
  checked only at apply (inside `applyMovement`); the preview says so rather than duplicating the stock rule.
- **Rejected**: calling the HTTP endpoints from the bulk loop (extra auth/serialisation, N requests inside one request);
  copying the rules into the bulk service (would drift — exactly the second-write-path defect Constitution I forbids).
- **Order of work matters**: run the existing Preorder/Shipment/Payment test suites first (regression net), extract, rerun.

## D2 — API shape: preview + apply per action, routes from one table

- Routes: `POST /preorders/bulk/{action}/preview` and `/apply` for `status`, `dispatch`, `payment`, `shipment` (8 routes) +
  `GET /preorders/matching-ids`. Registered in a loop over one table
  `action → ability` = `status→status`, `dispatch→dispatch`, `payment→payment`, `shipment→edit` (the abilities the individual
  routes use), so `MenuActionsRouteCoverageTest` stays satisfied and a role without the individual action cannot call the
  bulk one. A single generic route with an in-controller check was rejected: middleware is the project's enforcement point
  and the coverage test exists to stop un-gated routes.
- "Invoice progress" and "shipping progress" are ONE action (`dispatch`) with a `dispatch_status` param
  (`invoice_sent` | `pending` | `shipping`) — they are two values of the same marker (spec assumption).
- All bodies are JSON. Unlike 064 there is no file, so no multipart and no `max_input_vars` concern even with 200 rows.
- **Preview** is read-only and returns, per action, the numbers for the confirmation step and (payment, shipment) the rows
  the user edits. **Apply** takes the final values and re-judges everything on current state. No hash/plan: unlike
  approval, nothing here is "content the user reviewed that must not change" — each action's own rules (transition,
  remaining balance, shipment exists) already refuse a stale request safely.

## D3 — Transaction boundary: one outer transaction per pre-order, row locked

- Per pre-order: `DB::transaction(function () { lock row → call the service → write the bulk audit row })`. The services'
  own transactions nest inside (Laravel counts levels; one commit). A throw rolls back that pre-order only — including its
  audit row (spec FR-007/FR-038).
- `transitionStatus` does not lock the pre-order row itself; the wrapper takes `lockForUpdate()` first and passes the locked
  model, so two bulk runs (or bulk + manual) serialize per pre-order. The individual path is unchanged.
- Notifications (`notifyStatusChangeSafely`) run AFTER the pre-order's outer transaction commits, never inside it, and are
  swallowed on failure (same as the individual action).
- Pre-orders are resolved through the scoped `Preorder` model with ONE query (`whereIn id`, eager loads shared by all four
  actions: `items`, `payments`, `customer`, `shipment`): other mode / other seller / deleted = `not_found`, indistinguishable.

## D4 — Result vocabulary

Per pre-order: `{preorder_id, preorder_number, status, reason?, message?}`, `status` ∈ `succeeded | skipped | failed`.

- **skipped** = a business rule said no, or it was already done. `reason` codes: `not_found`, `invalid_transition`,
  `not_fully_paid`, `insufficient_stock`, `cancelled`, `not_mail_order`, `already_done` (marker already at target),
  `shipment_exists`, `missing_recipient`, `fully_paid`, `invalid_amount`, `exceeds_balance`, `already_recorded` (payment replay),
  `rejected` (a service `ValidationException` not matching a known code — its message is shown as is).
- **failed** = an unexpected exception (`reason: failed`, generic message; detail only in the log, never SQL/paths).
- `succeeded + skipped + failed = selected = results.length` (FR-032), results in request order. The HTTP response is
  always `200` once the body is well-formed (same pattern as `bulk-email`, `verify-payments`, 064).
- Reason codes are assigned by mapping the service's `ValidationException` keys/messages in ONE place
  (`PreorderBulkService::classify()`); the message shown is the service's own localized text, so the individual and bulk
  wording cannot diverge.

## D5 — Payments: amounts, idempotency, what is NOT offered

- Shared fields: `method`, `channel_id` (non-cash), `reference`, `notes`. Per-row: `amount` (default = outstanding).
  `purpose` is derived by the bulk layer the same way the single flow's UI does (verify at implement; default `settlement`
  when it fully pays, else `down_payment`) — ASSUMPTION to verify against `PaymentPanel`'s mapping.
- **No payment date and no proof** (spec amended at planning): `PaymentRecorder` always sets `paid_at = now()`, and a proof
  token links to exactly one payment while `deletePayment()` deletes the proof file — sharing one proof would let deleting
  one payment destroy the others' evidence. Backdating would alter a shared money rule → separate decision.
- **Idempotency**: the dialog generates one `batch_ref` UUID per open; the server derives
  `client_ref = UUIDv5(namespace, "{batch_ref}:{preorder_id}")`. `addPayment()` already answers a repeated `client_ref` with a
  replay (`$replayed = true`, no new row) — reported as `skipped / already_recorded`, which is what a retried batch should show.
  `client_ref` is UNIQUE across modes, so a collision with another mode's key is a `422` the service already handles.
- Amount rules are enforced by `addPayment` (≤ remaining, > 0, not closed/fully paid); the preview only PRE-FLAGS the same
  conditions from `PaymentSummary` (single source of the numbers) so the review table can warn before confirming.
- Cash: pre-order payments are outside shift cash (existing rule), so no open-shift requirement.

## D6 — Audit: what exists, what bulk adds (verified by grep)

- Today only `PaymentService` (`payment_recorded`), `duplicate` and `split` write `activity_logs` for pre-orders. **Status,
  dispatch marker and shipment creation write no audit row at all** (individually).
- Bulk adds, inside each pre-order's outer transaction: `preorder_bulk_status` (old/new status, cancel reason),
  `preorder_bulk_dispatch` (old/new marker + dates), `preorder_bulk_shipment` (shipment id, courier, tracking),
  `preorder_bulk_payment` (payment id, amount, resulting status/remaining; the service's own `payment_recorded` row also
  exists — the bulk row links it to the operation). Every row carries `bulk_ref` in `new_values`.
- After the loop, ONE `preorder_bulk_operation` row (who, action, params, selected/succeeded/skipped/failed, `skipped_by_reason`,
  `bulk_ref`); it summarises committed work, so it is not inside a pre-order transaction, and a failure writing it never fails
  the response (same stance as 064 D6). `ActivityLogger::log()` accepts a null `entity_id` (verified in 064).
- Viewing = the existing owner/admin Activity Log screen (filter by entity/action). There is no per-pre-order history panel;
  none is added (out of scope).
- The individual status/marker/shipment actions are deliberately NOT given audit rows here (would change behaviour and
  tests of shipped features); recorded as a known gap, not a hidden one.

## D7 — Status specifics

- Target offered = statuses that ≥ 1 selected pre-order can move to, with a count per target (from `statusBlocker`).
- `cancelled` requires `cancel_reason` once (existing `max:255` rule), applied to every cancelled pre-order.
- `handed_over`: `transitionStatus` already refuses when outstanding > 0.01 (`not_fully_paid`) and when stock is short
  (`insufficient_stock` via `applyMovement`); both become skips with the service's message. Stock moves exactly as the
  individual action does (one `preorder_handover` movement per item).
- Arrival auto-settle (`settleArrivedIfFullyPaid`) already runs inside `transitionStatus`.
- Email notifications: sent per pre-order after commit, synchronous (existing design). With real SMTP this can dominate the
  time of a 200-pre-order batch — to be measured (D10); if too slow, the bulk path may defer notifications to after the
  loop (still before responding) but must not change who gets notified.

## D8 — Shipments

- `ShipmentService::createFor()` enforces: courier fulfillment only, no existing shipment, required `courier_name`,
  `recipient_name`, `recipient_phone`, `address_line` (same max lengths as the controller today), optional
  `tracking_number`, `shipping_cost`, `province`, `notes`.
- Shared in the dialog: `courier_name`, optional `shipping_cost`/`notes`. Per row (editable): `recipient_name`,
  `recipient_phone`, `address_line`, `tracking_number`.
- Row defaults come from the preview (`ShipmentService::recipientDefaults(Preorder)` = the pre-order's customer name/phone/
  address — the same source the single form pre-fills from since 022; verify against `PreordersView` at implement). A row
  missing a required field is flagged in the review table; at apply it is skipped `missing_recipient` if still empty.
- Shipment-record status (packed/shipped/delivered) is NOT part of this feature (spec assumption). The marker `shipping` is
  separate and handled by the `dispatch` action.

## D9 — Selection across pages

- `GET /preorders/matching-ids` runs `applyFilters()` (the ONE filter source, as `neighbors()` and `summary()` do) and returns
  `{ids, total, capped}` with at most `bulk_max` ids (`capped` = more matched than returned). It plucks ids only (no hydration).
  Visible to anyone with the pre-orders menu; scope/mode rules apply through the scoped model.
- UI (composable `usePreorderSelection`): header checkbox = all rows on the page (indeterminate when some); when the whole page
  is selected and `total > page size`, a banner offers "Select all N matching" (calls the endpoint) and, when `capped`, says
  only the first `bulk_max` can be selected. Clear selection button in the action bar. Changing filter/search/sort resets the
  selection (truthful count > convenience; the existing bulk actions behave the same after this change).
- `DataTable` gets an optional `header-<key>` slot (default renders the label, so no existing table changes).

## D10 — Limits and time

- Cap `config('preorders.bulk_max')` (env `PREORDER_BULK_MAX`, default 200) enforced in `PreorderBulkRequest` on every
  bulk endpoint; also sent to the SPA via `GET /settings/features` as a convenience, like 064's `bulk_approve_max`
  (reuse that field's pattern; a separate `preorder_bulk_max` key).
- Each pre-order costs one outer transaction + the service's own work (+ a notification for status). The apply action raises
  the PHP time limit for itself only. **To be measured at implement** (same method as 064: in-process timing on the test DB,
  then with the log mailer and — separately — a slow-mail simulation); do not claim SC-001…003 without it.

## D11 — Permissions and visibility

- Route middleware per action (D2) = the individual action's ability; then per pre-order the scoped lookup. Seller-scoped
  users see pre-orders whose header has ≥ 1 of their lines (058) and act on them exactly like individually.
- UI: each bulk button shows only when `auth.canDo('preorders', <ability>)`; the whole bar only when ≥ 1 row is selected.
  Hidden, never disabled (Constitution III).

## D12 — Frontend structure

- `PreordersView.vue` is ~1600 lines already: selection moves into `usePreorderSelection.js`; the dialog is a lazy
  `PreorderBulkModal` (shell) + four small forms + `BulkResultView`.
- Steps: **configure** (shared fields) → **preview call** → **review** (counts; for payment/shipment an editable per-row
  table seeded from the preview) → **apply** (progress, single-flight) → **result**.
- Result view: counts that add up, per-row reason list, click a number to open the detail, "Retry these" = replace the
  selection with the skipped/failed ids and reopen the same action (payment keeps the same `batch_ref` for rows that did not
  succeed; a NEW dialog open makes a new `batch_ref`).
- After apply: reload the list + summary cards (existing `load()`), keep only the non-successes selected.
- Existing bulk actions (download/email/duplicate) are untouched and keep using the same `selectedIds`.

## D13 — Non-goals

Backdated payment date; sharing a proof across payments; shipment-record status changes (packed/shipped/delivered);
audit rows for the individual actions; a per-pre-order history panel; background/queued processing; changing the status
lifecycle; bulk edit of items/prices; bulk delete/split.

## Open items to verify at implementation (none block planning)

1. Payment `purpose` mapping used by the single-payment UI (D5).
2. Exact source of the shipment form's recipient defaults (D8).
3. Notification cost with real SMTP vs the log mailer (D7/D10).
4. `ActivityLogger` usage for `Preorder` entity type names (match existing `entity_type` strings).
5. Real-browser check (selection, 5 dialogs, retry, phone width) — browser tooling has been unavailable in previous sessions;
   stays an open task, not an assumption.
