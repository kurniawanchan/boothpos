# Phase 1 Data Model: Pre-order Form & Workflow Updates

One migration, three new nullable columns on the existing `preorders`
table. No changes to `preorder_items` or `shipments`' schema.

## `Preorder` (extended)

| Field | Type | Notes |
|---|---|---|
| `discount` | `decimal(14,2)`, default `0` | Fixed Rupiah amount (Decision 3). Validated server-side: `0 ≤ discount ≤ subtotal + shipping_cost`. Included in `total_amount = subtotal + shipping_cost - discount`. Never re-derived after creation — an immutable snapshot like `sell_price`/`cost_price` on each line item. |
| `pickup_day` | `date`, nullable | Only set when `fulfillment = pickup` **and** the preorder has a linked `event_id`. Must be a calendar date within that event's `[start_date, end_date]` range (Decision 2). `null` when there's no linked event, or when `fulfillment = courier`. Cleared automatically if the linked event's dates change such that the stored date falls outside the new range (FR-009a). Reaching this field through the UI requires the create form to have an event dropdown at all — it doesn't today (Decision 9) — so that dropdown is a co-requisite of this field, not optional polish. |
| `courier_name` | `string(50)`, nullable | Only set when `fulfillment = courier` ("Mail Order"). Must be one of `App\Support\Couriers`' known values (Decision 5). Defaults to `"JNE"` when the frontend submits "Mail Order" fulfillment without explicitly changing the dropdown. `null` when `fulfillment = pickup`. This is a *default/preference* value only — the actual `Shipment` record (created later, unchanged in shape) has its own independent, required `courier_name`. |

No change to `fulfillment`'s stored values (`pickup`/`courier`) — see
Decision 4. Only its displayed label changes ("Courier" → "Mail Order").

## `PreorderItem` (unchanged)

`qty` already exists, already validated `min:1`. This feature only changes
*how* the frontend collects a value for it (direct numeric entry, in
addition to the existing +/- stepper) — no schema or backend validation
change (Decision 7).

## `Shipment` (unchanged schema, changed frontend input only)

`courier_name` remains a required, freestanding `string(50)` column, still
only ever set through `ShipmentController::store()`'s own validation, still
only creatable after the preorder already exists. The only change is that
its frontend input becomes a dropdown (the same `Couriers` list, Decision
5) instead of free text, pre-filled from `preorder.courier_name` if set.

## Import/Export Row Shape (extends `PreorderExportImportService::HEADINGS`)

Current headings: `customer_name, customer_phone, customer_email, event_id,
fulfillment, sku, qty, unit_price, notes`.

New headings, appended at the end (order-level values, meaningful only on
a group's first row — same convention as `customer_name`/`event_id`/
`fulfillment` today):

| Column | Applies when | Validation |
|---|---|---|
| `discount` | Any row | Optional numeric ≥ 0; row error if it would make the total negative. |
| `pickup_day` | `fulfillment = pickup` | Optional; if present, must resolve to a date within the row's linked event's date range. **Row error if present while `fulfillment = courier`** (FR-014). |
| `courier_name` | `fulfillment = courier` | Optional (defaults to `JNE` if blank); if present, must be one of `Couriers`' known values. **Row error if present while `fulfillment = pickup`** (FR-014). |

Export includes all three columns for every row (blank where not
applicable), so an exported file re-imports without modification
(round-trip integrity, SC-004) — matching this product's existing
export/import convention.

## New shared definition

**`App\Support\Couriers`**: `public const OPTIONS = ['JNE', 'J&T', 'SiCepat',
'Pos Indonesia', 'Other']` (or equivalent), `public const DEFAULT = 'JNE'`.
Single source read by both dropdowns and by import validation (Decision 5).
