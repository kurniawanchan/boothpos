# Contract: payment confirmation (new/changed API surface)

## `PATCH /api/v1/orders/{order}/payments/{payment}/confirmation`
## `PATCH /api/v1/preorders/{preorder}/payments/{payment}/confirmation`

Add or change the confirmation of ONE existing non-cash payment. Authenticated; object-level authorization below.

**Body** (JSON; every key optional, at least one required):

| Key | Type | Notes |
|---|---|---|
| `proof_token` | uuid | From `POST /payment-proofs` (unchanged: JPEG/PNG ≤ 5 MB). Links the new proof; any current proof is superseded. |
| `reference` | string ≤ 100 \| null | Sets/clears the reference. |
| `notes` | string ≤ 1000 \| null | Sets/clears the notes. |

**Response 200**: the updated sale (`OrderResource` with `payments.proofs`) or pre-order payload — same shape the add/delete payment endpoints return.

**Authorization**: owner/admin, or the user who recorded that payment (`payments.recorded_by`). Others → `403`. Payments with no recorded user → owner/admin only.

| Status | When |
|---|---|
| 200 | Saved |
| 403 | Caller is neither owner/admin nor the payment's recorder |
| 404 | Payment does not belong to this sale/pre-order (or is in the other DEMO/LIVE mode) |
| 409 | Sale is voided / pre-order is cancelled |
| 422 | `confirmation`: none of the three keys sent, or the edit would leave nothing; `method`: payment is cash; `proof_token`: unknown/already used; `reference`/`notes`: too long |

**Side effects**: activity log `payment_confirmation_updated` (who, payment, old/new reference/notes/proof ids) in the same transaction. No change to amounts, status, totals, shift cash, reports.

## Changed: record payment (`POST /orders`, `POST /orders/{o}/payments`, `POST /preorders/{p}/payments`)

`proof_token` is **optional for every method** (it was required for non-cash). If supplied it must still be a valid, unlinked token (`422` otherwise). `POST /purchase-orders/{po}/payments` is unchanged (non-cash still requires a proof).

## Changed: payment objects in sale and pre-order payloads

Added per payment: `notes` (string|null), `proof_id` (int|null — current proof; sale payments previously had none), `has_proof` (bool), `can_view_proof` (bool), `can_edit_confirmation` (bool). Omitted (not an error) when the endpoint did not load `payments.proofs`.

## Changed: `GET /payment-proofs/{proof}/file`

Allowed for owner/admin and the uploader (as before) **and** for the user who recorded the proof's payment when the proof is that payment's current proof. Superseded proofs: owner/admin and uploader only. Others → `403`.
