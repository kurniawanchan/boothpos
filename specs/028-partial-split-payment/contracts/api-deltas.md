# API Deltas: Partial and Split Payments

All routes sit in the authenticated group. Money is a string (`"0.00"`). Mirror these in `docs/openapi-pos-mvp.yaml` in the same commit.

## Shared request body (add a payment) — `StorePaymentRequest`

```json
{ "method": "cash|bank_transfer|qr_ewallet", "amount": 200000, "channel_id": 3, "proof_token": "uuid",
  "reference": "TRX-8841", "notes": "...", "purpose": "full|down_payment|settlement", "client_ref": "uuid" }
```

Shape (`422`): `method` required; `amount` numeric `> 0`; `reference` ≤ 100 chars; `client_ref` uuid (optional on the server so older clients keep working; the SPA always sends it). Non-cash additionally requires `channel_id` + `proof_token` (existing rule, surfaced as 422 from `PaymentRecorder`).

Business results (both transaction types):

| Condition | Status | Message key |
|---|---|---|
| `amount > remaining` | `422` (`errors.amount`) | `payment_exceeds_balance` (includes the maximum) |
| Transaction fully paid | `409` | `payment_already_fully_paid` |
| Pre-order `handed_over`/`cancelled`, or POS order `voided` | `409` | `payment_target_closed` |
| Cash on a POS sale without an open shift for the user | `409` | `payment_session_required` |
| `client_ref` already used on **this** transaction | `200` with the current state (no new payment) | — |
| `client_ref` used on another transaction | `422` | `payment_client_ref_conflict` |

Success: `201` (new payment) returning the transaction payload with `payment_summary` and `payments[]`.

## `POST /api/v1/orders/{order}/payments`  — NEW (POS late payment)

Any authenticated user (same as checkout). Cash requires the caller's open cashier session (stored on the payment). Response: `OrderResource` (see below) with `payments.channel`, `payments.recorder`.

## `DELETE /api/v1/orders/{order}/payments/{payment}`  — NEW

Owner/admin only (`403` otherwise). `409` for a voided order, and for a **cash** payment whose shift is already closed. `404` if the payment is not this order's. Recalculates `paid_amount`; writes `payment_deleted`. Response `200` `OrderResource`.

## `POST /api/v1/preorders/{preorder}/payments`  — CHANGED

Same route/body plus `reference`, `client_ref`; **now refuses overpayment (422)**, a fully-paid or closed pre-order (409), and is idempotent on `client_ref`. Response adds `payment_summary`; `payments[]` entries gain `reference`, `recorded_by_name`, `status`.

## `DELETE /api/v1/preorders/{preorder}/payments/{payment}`  — unchanged shape

(Owner/admin, from the 027 follow-up.) Response now also carries `payment_summary`.

## `POST /api/v1/orders`  — CHANGED (partial checkout)

`payments[]` may total **less than** the order total **iff** `customer_id` is present; otherwise `409` `customer_required_for_partial_payment` (409, not 422: `OrderController::store()` maps every service `ValidationException` to 409 — the existing business-rule convention for checkout). A partial order is `completed`, `change_amount = 0`; each payment is stored with `session_id` = the order's shift and `recorded_by` = the cashier. Idempotency by `local_ref` is unchanged. Each payment may include `reference`.

## `GET /api/v1/orders/{id}` / list (`OrderResource`) — CHANGED

Adds `payment_summary` (same shape as pre-orders) and enriches `payments[]` with `reference`, `recorded_by_name`, `status`, `provider`, `session_id`. `GET /orders/{id}` loads `payments.channel` and `payments.recorder`.

## `GET /api/v1/orders/{id}/receipt` — CHANGED

Adds `paid_amount`, `balance_amount`, `payment_status` (`unpaid|partially_paid|fully_paid`) and per-payment `reference`/`paid_at` to `payment_summary[]`; a receipt re-fetched after later payments reflects them.

## `POST /api/v1/reports/sales/transactions/export`, `GET /api/v1/reports/sales` — CHANGED (additive)

Each `transactions[]` row adds `paid_amount`, `balance_amount`, `payment_status`. Each `sessions[]` item adds `cash_received`. Shifts that only received late payments are included. `totals` and every aggregate report are **unchanged** (sales are counted at completion — FR-029).

## `POST /api/v1/sessions/{id}/close`, `GET /api/v1/sessions/{id}/summary` — CHANGED (behavioural)

`expected_cash` and `by_method` are computed from `payments.session_id` (see data-model.md). No request/response shape change.
