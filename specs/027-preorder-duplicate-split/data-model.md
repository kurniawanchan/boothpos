# Data Model: Duplicate and Split Pre-orders

## Schema change (one additive migration)

`database/migrations/2026_10_30_000001_add_source_to_preorders_table.php` — the date prefix sorts after the latest existing preorder migration (`2026_10_29_000001`) and must not be renamed.

| Column (on `preorders`) | Type | Notes |
|---|---|---|
| `source_preorder_id` | unsigned bigint, nullable | Self-referencing FK → `preorders.id`, `nullOnDelete` (an "Ordered" source can still be deleted later) |
| `source_type` | enum(`duplicate`,`split`), nullable | Null for ordinary orders. Must be set iff `source_preorder_id`/`source_preorder_number` is set |
| `source_preorder_number` | string(30), nullable | Snapshot of the source's `preorder_number`, kept if the source is deleted |

Index on `source_preorder_id` (needed by the `splitChildren()` lookup). No new table. `preorder_items` is unchanged. `Preorder::$fillable` gains the three columns.

## Model additions (`Preorder`)

- `sourcePreorder(): BelongsTo` → `source_preorder_id`
- `splitChildren(): HasMany` → `Preorder` where `source_preorder_id = id` and `source_type = 'split'`
- Both inherit the DEMO/LIVE global scope; a duplicate/split can therefore never reference the other mode's rows.

## What a duplicate carries over vs. resets

| Field | Duplicate result |
|---|---|
| `preorder_number` | New (`generateNumber()`) |
| `customer_id`, `fulfillment`, `shipping_cost`, `discount`, `expected_date`, `notes` | Copied |
| `event_id` | Copied, or `null` if the event no longer exists in the active mode |
| `pickup_day` | Copied only if still inside the (existing) event's range; `courier_name` copied for `courier` only |
| Items | Same `variant_id` + `qty`; **price, cost, seller, name/SKU snapshots re-taken from the variant now** |
| `subtotal`, `total_amount` | Recomputed by `create()` |
| `status` | `ordered` |
| `paid_amount`, payments, proofs, shipment | None / 0 |
| `dispatch_status`, `invoice_sent_at`, `shipping_at` | `pending`, null, null |
| `cancel_reason`, notifications | None |
| `user_id` | Acting user |
| `source_*` | `duplicate`, source id, source number |

## Split rules (state and arithmetic)

**Eligibility** (re-checked after the row lock): `status ∉ {handed_over, cancelled}` **and** no row in `payments` for the order.

**Input**: a list of `(item_id, qty)` moves, or `by_seller`.

**Invariants** (all asserted by tests):
1. Moved units ≥ 1 and remaining units ≥ 1.
2. For every variant, Σ qty over (original + new orders) before = after → stock untouched.
3. `Σ line_total` and `Σ subtotal` over the resulting orders = original `subtotal`.
4. Original keeps `shipping_cost`, `discount`, `notes`, shipment record, `dispatch_status`/dates; new orders get `shipping_cost = 0`, `discount = 0`, no notes, `dispatch_status = pending`.
5. `total_amount = subtotal + shipping_cost − discount` on each order; original must satisfy `discount ≤ subtotal + shipping_cost` or the split is refused (409).
6. `paid_amount = 0` on both (a consequence of eligibility).
7. New order inherits `status` (so an "arrived" order's goods are not re-received and no stock movement is written).

**Per-move effect**:

| Move | Effect on rows |
|---|---|
| `qty == line.qty` | The existing `preorder_items` row's `preorder_id` is updated to the new order (id preserved) |
| `0 < qty < line.qty` | Original row: `qty -= moved`, `line_total = sell_price × qty`. New row in the new order: same `variant_id`, `artist_id`, `sku_snapshot`, `name_snapshot`, `cost_price`, `sell_price`; `qty = moved`; `line_total = sell_price × moved` |

**By seller**: group lines by `artist_id`; the seller of the lowest-`id` line stays; each other seller → one new order of whole lines (same `source_type = split`, source = the original).

## Derived/read-only presentation

`present()` gains (additive; both guarded by `relationLoaded`):
- `source`: `{ type, preorder_id, preorder_number }` or `null`
- `split_children`: `[{ id, preorder_number }]` (original only; empty array if none)

## Activity log rows (inside the same transaction)

| Action | `entity_type` / `entity_id` | Description gist |
|---|---|---|
| `duplicated` | `Preorder` / new order id | "Duplicated from PO-… " (source number) |
| `split` | `Preorder` / source id | "Split into PO-…, PO-…" with `new_values` listing moved `(item_id, qty)` |
