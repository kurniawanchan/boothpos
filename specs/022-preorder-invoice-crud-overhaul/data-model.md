# Phase 1 Data Model: Pre-order Invoice & CRUD Overhaul

## Preorder (existing table, no new columns for CRUD)

Fields relevant to this feature (unchanged names/types unless noted):
`id, order_number, customer_id, event_id, status, fulfillment, pickup_day,
courier_name, discount, subtotal, total_amount, paid_amount, notes,
expected_date, data_mode, created_at, updated_at`.

**State machine (unchanged, from `Preorder::ALLOWED_TRANSITIONS`)**:
`ordered → dp_paid → arrived → settled → handed_over`, plus `cancelled`
reachable from `ordered/dp_paid/arrived/settled`.

**New behavioral rules (this feature)**:

| Rule | Statuses it applies to | Enforced in |
|---|---|---|
| Edit allowed | `ordered, dp_paid, arrived, settled` | `PreorderService::update()` |
| Edit refused | `handed_over, cancelled` | `PreorderService::update()` → 409 |
| Edit recalculates stock | `arrived, settled` (stock already applied) | `PreorderService::update()` via `StockService::applyMovement()` delta |
| Edit does NOT touch stock | `ordered, dp_paid` (stock never applied yet) | `PreorderService::update()` — item replace only |
| Delete allowed | `ordered` only, and no `payments` rows | `PreorderService::delete()` |
| Delete refused | any other status, or any payment exists | `PreorderService::delete()` → 409, message points to Cancel |

No new columns. `updated_at` naturally reflects the edit.

## PreorderItem (existing table, no schema change)

Edit implications only:
- A line's `quantity` may change; `unit_price`/`sku_snapshot` are
  re-derived from the *current* `ProductVariant` at edit time for any
  newly-added line (same snapshot rule `create()` already applies), but
  an unchanged line's stored `unit_price`/`sku_snapshot` is left alone —
  editing quantity on an existing line does not silently reprice it.
- Removing a line deletes its `preorder_items` row; if the order's status
  is `arrived`/`settled`, the removed quantity is treated as a delta of
  `−old_quantity` for stock purposes (see Preorder table above).

## Shipment (existing table, schema change)

**Migration**: drop columns `city`, `postal_code`.
Remaining address data lives entirely in the existing `address_line`
(string, max 255, required) — consistent with `Customer.address`'s
single-field shape (feature 020).

**New behavior**: `ShipmentController::store()` accepts an optional
auto-fill: when the frontend opens "Create shipment data" for a pre-order,
it pre-populates `recipient_name`/`recipient_phone`/`address_line` from
`Preorder->customer` (`name`, `phone`, `address`) client-side — no backend
change needed for the auto-fill itself (the existing endpoint already
accepts these fields; the frontend simply supplies defaults). Staff can
still edit any of the three before submitting.

## Invoice response shape (PreorderController::invoice(), extended)

Existing fields unchanged (`order_number`, `customer`, `items`,
`subtotal`, `discount`, `total_amount`, `paid_amount`, `status`,
`pickup_day`, `courier_name`, `fulfillment`, `event`), **plus**:

```jsonc
{
  "store_identity": {
    "name": "string (mode-aware demo/live)",
    "logo_url": "string|null (omitted if receipt_show_logo is false)",
    "contact_person": "string|null",
    "contact_phone": "string|null",
    "contact_email": "string|null",
    "address": "string|null"
  },
  "payment_channels": [
    {
      "id": 1,
      "type": "bank_transfer|e_wallet|qris",
      "name": "string",
      "account_number": "string (UNMASKED — research.md Decision 2)",
      "account_name": "string|null",
      "qr_image_url": "string|null"
    }
  ],
  "footer_text": "string|null"
}
```

`store_identity`/`footer_text` are omitted-field-safe the same way
`OrderController::receipt()` already is: a null Setting means the key is
absent from the response, not an empty string, so the frontend's existing
`v-if` guards degrade the same way `ReceiptModal.vue` already does.

## Payment Invoice response shape (PreorderController's existing payment-receipt endpoint, extended)

Same `store_identity`/`payment_channels`/`footer_text` addition as above,
**plus** the payment-event-specific fields already returned today
(`payment.amount`, `payment.method`, `payment.paid_at`, `payment.notes`,
running `paid_amount`/`total_amount`/`outstanding` at the time of that
payment) — unchanged, just now presented inside the same document shell
component (`PreorderPaymentReceiptModal.vue` restyled to match
`PreorderInvoiceModal.vue`, per FR of User Story 4).

## Import/Export row shape (replaces the existing row-per-item shape entirely)

One row = one pre-order. Columns, in order:

| Column | Type | Notes |
|---|---|---|
| `event_name` | string, optional | Matched against `Event.name` (data-mode scoped); ambiguous match is a row error |
| `fulfillment` | string | `pickup` or `mail order` (case-insensitive) |
| `pickup_day` | string, optional | `"Day 1"`, `"Day 2"`, ... — required if `fulfillment = pickup` and the event has pickup days; resolved to a real date against the matched event |
| `products` | string | comma-separated SKUs |
| `quantities` | string | comma-separated integers, positionally matched to `products` |
| `unit_prices` | string | comma-separated decimals, positionally matched to `products` |
| `shipping_cost` | decimal, optional | order-level, only meaningful for `mail order` |
| `courier_name` | string, optional | required if `fulfillment = mail order`; one of `App\Support\Couriers::OPTIONS` |
| `expected_date` | date, optional | ETA, order-level |
| `discount` | decimal, optional | order-level fixed Rupiah amount, default 0 |
| `notes` | string, optional | order-level free text |

Customer identification: kept from the existing template's `customer_name`
column (the user's list doesn't mention removing it, only phone/email) —
`customer_phone`/`customer_email` columns are dropped per the request;
a row's customer is resolved/created by name alone, same lookup
`PreorderExportImportService` already performs today for the "find or
create a customer by name" step.

**Export** produces exactly this same column set/order, so the exported
file round-trips back through import unchanged (mirrors the master-data
workbook's own round-trip guarantee).
