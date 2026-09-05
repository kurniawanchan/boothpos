# Phase 1 Data Model: Seller Recap Preorder Detail, Richer Pre-order Report & Missing Report Exports

No new tables and no migrations. `PreorderItem.artist_id` (existing FK,
confirmed at `database/migrations/2026_10_05_000001_create_preorders_tables.php:38`)
is the only column this feature's new joins rely on, and it already
exists. Changes are additive query logic and response shaping.

## Seller transaction-detail entry (merged shape, `artistSettlementTransactions()`)

New unified shape returned per entry (replaces the Order-only shape):

```
{
  key: string,            // "order-<id>" or "preorder-<id>" — was `order_id`, renamed since preorders have no order_id
  number: string,         // order_number or preorder_number
  source: "order" | "preorder",
  created_at: string|null,
  items: [{ sku, name, qty, line_total }],
  amount_for_artist: string,  // money string; for preorder entries this is the R1-prorated COLLECTED amount, not full line_total
}
```

**Invariant**: `SUM(amount_for_artist)` across all entries for a seller in
an event MUST equal that seller's `total_sales` on the Seller Recap
summary row (FR-003) — both are computed via the same `010`-established
collected-and-prorated rule.

## Pre-order per-seller breakdown row (new, `preorders()` breakdown path)

```
{
  artist_id: int,
  artist_name: string,
  status: string,
  payment_completeness: "unpaid" | "partial" | "paid",
  preorder_count: int,        // count of DISTINCT preorders touching this artist+status+completeness bucket
  total_order_value: string,  // prorated to this artist's item share
  total_collected: string,    // prorated
  total_outstanding: string,  // prorated
}
```

**Invariant**: for a fixed `status`/`payment_completeness`, summing
`total_order_value`/`total_collected`/`total_outstanding` across all
`artist_id` rows equals that status/completeness bucket's existing
(unbroken-down) summary row from `010` (FR-006's proration consistency).

## Pre-order drilldown row (new, individual-preorder detail)

```
{
  preorder_id: int,
  preorder_number: string,
  customer_name: string|null,
  order_value: string,      // this preorder's total_amount (or its prorated artist share, when opened from a seller-scoped row)
  collected: string,
  outstanding: string,
}
```

**Invariant**: summing this list's amounts equals the summary row (or
per-seller row) the drilldown was opened from (FR-007/SC-003).

## No changes: `Order`, `OrderItem`, `Preorder`, `PreorderItem`, `Payment`, `Artist`

All existing fields already carry everything this feature needs — this
feature is entirely additive query/response-shaping logic over
already-modeled data.
