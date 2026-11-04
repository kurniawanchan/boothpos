# Data Model: Sales Payment Proof Visibility & List Column Refinements

**Classification**: INTERNAL

**No schema change, no migration, no stored data written.** Everything below is derived at read time.

## Derived row field (Sales list)

| Field | Type | Definition |
|---|---|---|
| `payment_proof_count` | integer ≥ 0 | Count of the sale's payments where `method != cash`, a current (non-superseded) `payment_proofs` row exists, and `Payment::proofViewableBy($requestUser, $currentProof)` is true. Voided sales are counted like any other (audit). |

Existing fields reused as-is: `has_reference` (bool), `payment_state` (`none|pending|rejected|verified`, worst-of), `payment_methods[]`, `margin_amount` (owner/admin only).

## Client-side derived state

- **Verified pill**: `payment_state === 'verified'` AND `payment_methods` includes a method other than `cash`.
- **Reference filter**: `filters.reference ∈ {'', 'has', 'none'}`; `has` ⇔ `tx.has_reference === true`; absent ⇒ `none`. URL key `ref`.

## Entities touched (read only)

- **Payment** → `currentProof()`, `proofViewableBy()`; **PaymentProof** (`superseded_at` null = current); **Order** → `payments`, `hasReference()`.

## Invariants

- A proof the user may not view never contributes to the count and never yields an image (the file endpoint re-checks authorization on every fetch).
- A replaced (superseded) proof never contributes.

## Addendum (US7–US9)

No new stored data. Derived/transient only:
- **Export row** = list row fields + `verification` (all non-cash verified), `reference_note`, `reference_images[]` (paths, server-side only) and `proof_images[] {method, amount, path}` limited to proofs the exporter may view. Never serialised to the JSON list.
- **Seller part** = `seller_items[name] {units, gross, net}` (unchanged shape), summed over the selected sellers.
- **Toggle state** = `localStorage` boolean per viewer/device; not stored server-side.
