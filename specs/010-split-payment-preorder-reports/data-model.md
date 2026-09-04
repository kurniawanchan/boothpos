# Phase 1 Data Model: Split Payment Visibility, Preorder Receipt & Reporting

No new tables and no migrations. `Payment`, `Preorder`, `PreorderItem` already
have every field this feature needs (confirmed in research.md). Changes are
additive query logic and frontend state, not schema.

## Payment (`app/Models/Payment.php`) — unchanged

Existing fields used by this feature: `order_id` / `preorder_id` (mutually
exclusive), `channel_id`, `method`, `purpose` (`down_payment`|`settlement`,
Preorder-only), `amount`, `verification`, `verified_by`, `verified_at`,
`reject_reason`, `paid_at`, `notes`. Already one row per payment entry for
both Orders and Preorders — this feature's split-payment work is entirely
about *submitting more of these rows visibly and reliably*, not changing
the row shape.

**New read pattern**: report queries (R1) and the dedicated preorder report
(R6) sum `payments.amount` directly, filtered to non-rejected verification
states, as the source of truth for "revenue collected" — not
`preorders.paid_amount`.

## Preorder (`app/Models/Preorder.php`) — unchanged fields, new derived reads

Existing fields: `subtotal`, `shipping_cost`, `total_amount`, `paid_amount`,
`status` (`ordered`→`dp_paid`→`arrived`→`settled`→`handed_over`, or
`cancelled`), `outstanding()` helper.

**New derived value (query-time only, not persisted)**: `amount_collected`
= `SUM(payments.amount)` for that preorder (non-rejected), used instead of
the `paid_amount` cache column for every report/receipt computation in this
feature, per research.md R1's cache-drift rationale.

**New derived value**: `payment_completeness` bucket for the dedicated
report — `unpaid` (`amount_collected = 0`), `partial` (`0 <
amount_collected < total_amount`), `paid` (`amount_collected >=
total_amount`).

## PreorderItem (`app/Models/PreorderItem.php`) — unchanged fields, new derived reads

Existing fields: `preorder_id`, `variant_id`, `artist_id`, `qty`,
`cost_price`, `sell_price`, `line_total`, `data_mode`.

**New derived value (query-time only)**: `recognized_revenue` per item =
`(item.line_total / preorder.subtotal) * preorder.amount_collected` — the
proration described in research.md R1. Used by the `sales()`/`profit()`
merge and by `SettlementService::recalculateForEvent()`'s artist-level
aggregation. A cancelled preorder's items always compute to zero regardless
of `amount_collected`.

## ArtistSettlement (`app/Models/ArtistSettlement.php`) — no field changes

`SettlementService::recalculateForEvent()`'s existing `order_items`-based
aggregation gains a second, parallel aggregation over `preorder_items`
(excluding `cancelled` preorders, using the same `recognized_revenue`
proration) whose per-artist sums are added into the same `total_sales` /
`total_units` figures already being computed — no new column, this is
purely a wider input to an existing computed output.

## Frontend state: split-payment entry list (`PaymentPanel.vue`)

No new persisted state — `entries[]` (already exists client-side) is now
used identically in both `mode="checkout"` and `mode="record"`. New
per-entry submission state for Preorder's multi-call flow (research.md R2):
each entry in the list carries a transient `status`
(`pending`|`submitting`|`submitted`|`failed`) so the UI can show progress
and let the user retry a single failed entry without resubmitting entries
that already succeeded — this state lives only in the component, never
sent to the backend.

## New report shape: Preorder report (`GET /reports/preorders`, new)

Row shape (mirrors `sales()`'s existing `group_by` row convention):
`{ status, payment_completeness, preorder_count, total_order_value,
total_collected, total_outstanding }`, grouped by `status` ×
`payment_completeness`, filterable by `event_id` per FR-014.
