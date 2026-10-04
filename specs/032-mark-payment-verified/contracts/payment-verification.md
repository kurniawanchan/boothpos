# Contract: payment verification (new/changed API surface)

All routes are inside the authenticated API group.

## `POST /api/v1/orders/{order}/payments/{payment}/verify`
## `POST /api/v1/preorders/{preorder}/payments/{payment}/verify`

Mark ONE pending non-cash payment verified. No body.

**Authorization**: owner/admin, or any user except the user who recorded that payment (`payments.recorded_by`); a payment with no recorded user can be verified by anyone. The recorder → `403`.

| Status | When |
|---|---|
| 200 | Verified; body = the updated sale (`OrderResource`) / pre-order payload (payments carry `verification`, `verified_by_name`, `verified_at`, `can_verify`) |
| 403 | Caller is the payment's recorder and not owner/admin |
| 404 | Payment does not belong to this sale/pre-order, or is in the other DEMO/LIVE mode |
| 409 | Voided sale / cancelled pre-order; payment already verified; payment rejected |
| 422 | Cash payment (`errors.method`) |

Side effect: activity log `payment_verified`. Amount, status, totals, expected shift cash, reports unchanged. The payment now also appears in `GET /sessions/{id}/summary` → `by_method` (that breakdown counts only verified payments — existing rule). There is **no** route to undo a verification.

## `POST /api/v1/orders/verify-payments`

Verify the pending non-cash payments of several sales (Sales list selection).

**Body**: `{ "order_ids": [int, ...] }` — 1 to 200 distinct integers.

**Response 200**:

```json
{
  "verified": 12,            // payments verified now
  "verified_orders": 9,      // distinct sales that had at least one payment verified
  "skipped": {               // payments NOT verified, by reason (non-cash payments only; cash is ignored)
    "already_verified": 3,
    "own_payment": 2,        // recorded by the caller (caller is not owner/admin)
    "voided": 1,
    "rejected": 0
  },
  "skipped_total": 6
}
```

Unknown / other-mode order ids are ignored. Each verified payment is its own transaction with its own `payment_verified` audit row; a mid-batch conflict is counted under `already_verified` and does not abort the batch. `422` only for a malformed body (empty list, > 200, non-integers).

## Changed: payment objects in sale and pre-order payloads

Added per payment: `verified_by_name` (string|null — omitted when `payments.verifier` was not loaded), `verified_at` (datetime|null), `can_verify` (bool, server-computed for the requesting user). `verification` (`pending|verified|rejected`) is already present on both.
