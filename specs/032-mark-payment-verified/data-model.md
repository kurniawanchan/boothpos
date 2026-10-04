# Data Model: Mark a Non-Cash Payment as Verified

**No schema change.** All needed columns already exist on `payments`.

## Columns used

| Column | Type | Use |
|---|---|---|
| `verification` | enum(`pending`,`verified`,`rejected`) default `pending` | The state. Cash is created `verified`; non-cash is created `pending` and moves to `verified` through this feature. `rejected` is never written here (out of scope). |
| `verified_by` | FK users, nullable, `nullOnDelete` | Who verified. NULL for cash/pending rows and for rows whose verifier user was deleted. |
| `verified_at` | timestamp, nullable | When. |
| `recorded_by` | FK users, nullable | Drives `mayVerify()` (the recorder may not verify; NULL = legacy, no restriction). |

## State machine (per payment)

```text
cash:      verified (at creation) ── no transitions
non-cash:  pending ──(verify, once, by an allowed user)──▶ verified   [final: no way back]
           rejected  (existing state, never entered/left by this feature)
```

Guards: target open (not voided / cancelled), method non-cash, state `pending`, actor allowed. Anything else is refused or skipped; a repeated request is a no-op error (409), never a second transition or log row.

## Derived (not stored)

- `can_verify` per payment per requesting user = non-cash AND `verification === 'pending'` AND target open AND `mayVerify(user)`.
- Sales list `payment_state` (unchanged logic): worst state among a sale's payments (rejected > pending > verified > none) — recomputed from rows, so verifying the last pending payment flips the badge.

## Audit

`activity_logs` row per verified payment: `action = 'payment_verified'`, `entity_type = Order|Preorder`, `entity_id` = the transaction, `old_values = {payment_id, verification: 'pending'}`, `new_values = {payment_id, verification: 'verified', verified_by, verified_at}`, written inside the same transaction as the state change.

## Invariants

- Amount, method, channel, purpose, `paid_at`, `session_id`, proof/reference/notes (031), and the target's `paid_amount`/status/totals are never written by verification.
- Reads that DO depend on the state (existing, unchanged): the Sales `payment_state` badge and the shift summary's per-method breakdown (`verified` only). Expected shift cash is cash-only and independent.
- A verified payment never returns to `pending` through any code path added here.
