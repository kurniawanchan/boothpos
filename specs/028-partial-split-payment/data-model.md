# Data Model: Partial and Split Payments

## Schema change — one migration

`database/migrations/2026_10_31_000001_add_ledger_columns_to_payments_table.php` (prefix sorts after `2026_10_30_000001`; do not rename).

| Column (on `payments`) | Type | Notes |
|---|---|---|
| `reference` | string(100), nullable | Free-text payment reference / transaction number. Optional for every method |
| `client_ref` | char(36), nullable, **unique** | Client-generated idempotency key (UUID) — see research Decision 4 |
| `session_id` | FK → `cashier_sessions`, nullable, `nullOnDelete`, indexed | Shift the money was **received in**. NULL for pre-order payments and legacy non-order rows |
| `recorded_by` | FK → `users`, nullable, `nullOnDelete` | Who entered the payment. NULL for rows created before this feature |

**Backfill (same migration)**: `UPDATE payments p JOIN orders o ON o.id = p.order_id SET p.session_id = o.session_id WHERE p.order_id IS NOT NULL`. `reference`/`client_ref`/`recorded_by` stay NULL.

Existing CHECK constraints (`chk_payments_amount > 0`, `chk_payments_target`, `chk_payments_channel` — non-cash needs a channel) are untouched. No new table; `orders` and `preorders` keep `paid_amount` as a cache that is **recomputed from the entries** on every change.

## Derived payment summary (pure function of target + payments)

```
counted    = payments where verification != 'rejected'
total_paid = Σ counted.amount                       (pre-order)
           = Σ counted.amount − orders.change_amount (order — payments store tendered cash)
remaining  = max(0, grand_total − total_paid)       (rounded to 2 dp)
status     = unpaid          if counted is empty
             partially_paid  if remaining > 0
             fully_paid      otherwise
count      = |counted|
```

`grand_total` = `preorders.total_amount` / `orders.total_amount`.

Payload shape (both transaction types):

```json
"payment_summary": { "grand_total": "1000000.00", "total_paid": "400000.00", "remaining": "600000.00",
                     "status": "partially_paid", "payment_count": 2 }
```

## Payment entry (as presented)

`id, method, amount, paid_at, reference, recorded_by_name, status ("paid" | "rejected"), provider, purpose, proof_id` — `purpose` is kept for pre-orders (down_payment/settlement) and defaults to `full` for POS. `status` is derived from `verification` (Decision 9).

## Rules and state

| Rule | Detail |
|---|---|
| Amount | `> 0`, `≤ remaining` at the moment of saving (re-evaluated under the row lock) → 422 otherwise |
| Closed target | Pre-order `handed_over`/`cancelled`, or POS order `voided` → 409 on add and on delete |
| Fully paid | `remaining = 0` → add refused (409); UI hides Add Payment |
| Idempotency | Same `client_ref` + same target → return current state (200), no new row; ref of another target → 422 |
| Non-cash | Channel and proof (`proof_token`) required exactly as today |
| Cash on POS late payment | Requires the recording user's **open** cashier session (409 `payment_session_required`); stored in `session_id` |
| Immutability | No update path. Delete = owner/admin only, audited, recalculates; refused for a **cash** payment whose shift is **closed** |
| Pre-order lifecycle | Unchanged: first payment `ordered→dp_paid`; `arrived` + fully paid → `settled`; delete reverses as built in 027 (`settled→arrived`, `dp_paid` without payments → `ordered`) |
| POS order | Stays `completed`; payment status is derived. Partial checkout requires `customer_id` (422) |

## Shift cash (rebased on `payments.session_id`)

```
expected_cash(shift) = opening_cash
                     + Σ amount of CASH payments with session_id = shift, verification = verified, order not voided
                     − Σ change_amount of non-voided orders created in the shift
```

Pre-order payments (`session_id` NULL) are excluded, as today. The Sales page `sessions[]` gains `cash_received` (same Σ, excluding the opening cash) and includes shifts that only received late payments.

## Activity log rows (inside the same transaction)

| Action | `entity_type` / `entity_id` | Values |
|---|---|---|
| `payment_recorded` | `Preorder` or `Order` / target id | new: amount, method, reference, status, total_paid, remaining |
| `payment_deleted` | `Preorder` or `Order` / target id | old: payment_id, amount, method, status, paid; new: status, paid (existing pre-order row extended; same action for POS) |

## Model additions

`Payment::recorder(): BelongsTo` (User via `recorded_by`), `Payment::session(): BelongsTo`; `Order::paymentSummary()` / `Preorder::paymentSummary()` delegating to `PaymentSummary`; fillable additions on `Payment`.
