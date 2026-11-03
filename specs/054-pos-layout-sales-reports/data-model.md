**Classification: INTERNAL**

# Data Model: 054

One migration, additive only. Everything else is derived at read time.

## Migration `2026_11_03_000001_add_reference_to_orders_table`

Table `orders` (already `HasDataMode`; no new table, so no new `CloneRegistry` entry — only one `files` mapping, below).

| Column | Type | Null | Notes |
|---|---|---|---|
| `reference_note` | `text` | yes | ≤ 1000 chars, enforced by the request class |
| `reference_image_path` | `string(255)` | yes | private `local` disk, `order-references/<uuid>.<ext>` |
| `reference_image_mime` | `string(50)` | yes | sniffed server-side; `image/jpeg`, `image/png` or `image/webp` |
| `reference_updated_by` | FK `users`, `nullOnDelete` | yes | last editor |
| `reference_updated_at` | `timestamp` | yes | last edit |

- Invariant (enforced in `OrderReferenceService`, not a DB constraint): `reference_image_mime` is non-null iff `reference_image_path` is non-null; a reference "exists" when `reference_note` or `reference_image_path` is non-null.
- `down()` drops the columns (files are not touched by a rollback; see quickstart cleanup note).
- **Clone**: `CloneRegistry` entry for `orders` gains `'files' => ['reference_image_path' => 'local']` so a LIVE→DEMO clone *copies* the file, never shares the path (existing rule). `CloneRegistryDriftTest` and the clone parity test must stay green.
- Existing `orders.notes` (free text, unrelated) is left alone; the reference is a separate concept.

## Entities (spec Key Entities → storage)

- **Transaction Reference** → the five columns above, 0..1 per sale.
- **Payment** → existing `payments` row; `method`, `channel_id`, `verification`, `verified_by`, `verified_at`, `session_id` become *correctable together* only through `PaymentService::changeMethod()`. `amount`, `paid_at`, `client_ref`, `recorded_by`, `order_id` are never changed.
- **Cashier Session** → existing `cashier_sessions` (no change); list/detail are read models.
- **Best Seller Mark** → not stored; computed per report request.

## State rules: payment method change

```text
completed order, payment P (method m, channel c, verification v, session s)
  target (m', c')
  ├─ m'=m and c'=c                       → 422 nothing_to_change
  ├─ order voided                        → 409
  ├─ m=cash and shift s is closed        → 409 closed_shift
  ├─ m=cash and order.change_amount > 0  → 409 change_given
  ├─ m'=cash                             → needs actor's OPEN shift (else 409); c'=null; v:=verified; s:=actor shift
  └─ m'≠cash                             → c' required, active, type matches m'; v:=pending; verified_by/at:=null; s unchanged
  always: amount/paid_at unchanged; orders.paid_amount cache re-synced; activity log row in same transaction
```

## Read models (not stored)

### Sales list row additions (`SalesTransactionsService::orderRow`)

| Field | Meaning |
|---|---|
| `has_reference` | bool, derived from the two reference columns (no extra query) |
| `items_gross` | `Σ(line_total + discount_amount)` over items, 2-decimal string |
| `items_net` | `Σ(line_total)` over items, 2-decimal string |
| `can_change_method` / `can_edit_reference` | **not** in list rows (kept in the detail payload only) |

### `OrderResource` additions (detail)

`reference: { note, has_image, updated_by_name, updated_at, can_edit }` (emitted only when the order is loaded with the editor relation — the same `relationLoaded` convention, so every endpoint returning an order must eager-load `referenceEditor`); each payment entry gains `can_change_method` (server-computed from `Payment::mayManageConfirmation` + order/shift state, so the SPA never derives permission from role).

### Sales by product (per request)

`product_id, product_name, artist_id, artist_name, units, sales, transaction_count, rank, is_best_seller, variants[{variant_id?, variant_name, units, sales}]`

### Sales by payment method (per request)

`method, channel_id, label, transaction_count, amount, verified_amount, unverified_amount`, plus `totals`.

### Cashier session list/detail

List row: `id, cashier_id, cashier_name, event_id, event_name, opened_at, closed_at, status, opening_cash`.
Detail adds: `closing_cash, expected_cash, cash_difference` (stored for a closed shift; computed with the shared formula for an open one), `opening_cash_entries[]`, `order_count`, `total_sales`, `by_method[]`, and `transactions[]` (`kind: sale|payment_received`, order number, time, amount).

## Validation summary

| Input | Rule |
|---|---|
| change method | `method` in `cash,qr_ewallet,bank_transfer`; `channel_id` required for non-cash, forbidden for cash |
| reference | `note` ≤ 1000, `image` ≤ 5120 KB, mimes `jpeg,png,webp` + content sniff; at least one of note/image after the change |
| bulk receipts | `order_ids` 1..200, each integer |
| reports | `event_id` integer optional; `date_from/to` dates; `q` ≤ 100 chars |
| session list | `per_page` ≤ 100; non-owner/admin forced to own sessions |
