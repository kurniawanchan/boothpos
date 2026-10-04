# Data Model: Optional Payment Proof, Addable Later from Sales Detail

## Schema change (one column)

`payment_proofs.superseded_at` — `timestamp`, nullable, default NULL. Set when a later proof replaces this one on the same payment. Migration `2026_11_01_000001_add_superseded_at_to_payment_proofs_table.php` (additive; prefix keeps FK/ordering convention). No backfill: every existing row is "current".

## Existing columns now used

- `payments.reference` (string 100, nullable) and `payments.notes` (text, nullable) — already exist (028); now editable through the confirmation path only.
- `payments.recorded_by` (nullable FK users) — drives `confirmationEditableBy()` and the recorder viewing rule; NULL on pre-028 rows ⇒ owner/admin only.

## Derived (not stored)

- **Current proof** of a payment: the non-superseded `payment_proofs` row (latest id). 0 or 1 per payment.
- **Per-payment flags in API payloads** (computed per request user): `has_proof`, `can_view_proof`, `can_edit_confirmation`.
- `can_edit_confirmation` = non-cash payment AND (user is owner/admin OR `recorded_by == user.id`) AND the target is not a voided sale / cancelled pre-order.
- `can_view_proof` = has a current proof AND (owner/admin OR `proof.uploaded_by == user.id` OR `payment.recorded_by == user.id`).

## Invariants

- Cash payments never carry a confirmation.
- A payment's confirmation can never become entirely empty through an edit (reference, notes and current proof not all absent).
- Amount, method, channel, purpose, verification, `paid_at`, `session_id`, and the target's `paid_amount`/status are not changed by this feature.
- A proof row links to at most one payment; superseded rows are never re-linked or shown.
