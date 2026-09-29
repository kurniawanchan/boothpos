# Phase 1 Data Model: Pre-order Invoice/Shipping Progress, Print Menu & List Refinements

## `preorders` table — three additive columns

| Column | Type | Default | Migration | Notes |
|---|---|---|---|---|
| `dispatch_status` | ENUM(`pending`,`invoice_sent`,`shipping`) NOT NULL | `pending` | `2026_10_28_000001` | after `status` |
| `invoice_sent_at` | TIMESTAMP NULL | NULL | `2026_10_29_000001` | after `dispatch_status` |
| `shipping_at` | TIMESTAMP NULL | NULL | `2026_10_29_000001` | after `invoice_sent_at` |

Both migrations are reversible (`down()` drops the columns). No index — the filter is a `whereIn` over a table sized for one store's pre-orders.

`Preorder` model: the three columns are `$fillable`; `invoice_sent_at` / `shipping_at` are cast to `datetime`; `Preorder::DISPATCH_STATUSES` is the single list of legal values; `$attributes = ['dispatch_status' => 'pending']` mirrors the column default so `create()` responses are correct (research.md Decision 2).

## State table (what the server stores for each target value)

| Target `dispatch_status` | `invoice_sent_at` | `shipping_at` | Allowed for |
|---|---|---|---|
| `pending` | NULL | NULL | any non-cancelled |
| `invoice_sent` | existing, else now | NULL | any non-cancelled |
| `shipping` | existing (never invented) | existing, else now | `fulfillment = courier` only |

- Cancelled pre-orders: any change → 409.
- Editing a `shipping` pre-order to pickup → `dispatch_status = invoice_sent`, `shipping_at = NULL`, `invoice_sent_at` unchanged.
- Import applies the same table; a blank date on the active value becomes the import time (see contracts/export-import.md).

## Response shape (`PreorderController::present()` and `index()` rows)

```jsonc
{
  // ...existing fields unchanged...
  "dispatch_status": "invoice_sent",
  "invoice_sent_at": "2026-09-27T03:00:00+00:00", // null when not applicable
  "shipping_at": null,
  "created_at": "2026-09-27T02:30:00+00:00",
  "updated_at": "2026-09-27T03:00:00+00:00"
}
```

`index()` already returned `created_at`; it now also returns `updated_at`, `dispatch_status`, `invoice_sent_at`, `shipping_at`. `present()` (detail, create/update responses, invoice payloads) gains the four new fields (`created_at` came with feature 024).

## Query additions

- Filter: `dispatch_status[]=…` in `PreorderController::applyFilters()` (same array pattern as `status` / `fulfillment`), so list, `/preorders/summary` and `/preorders/export` agree.
- Sort: `sort_by=created_at|updated_at` added to `applySort()`'s allow-list.

## Frontend-only derived values

- `DISPATCH_LABEL` / `DISPATCH_VARIANT` maps (labels via i18n).
- `detailDispatchOptions` = all three, minus `shipping` unless `detail.fulfillment === 'courier'`.
- `rowActions(row)` builds the per-row action list (Invoice, Payment invoice [disabled without payment], Edit, Delete, Detail) by the same status rules as before.
- `lastPaymentId` = id of the last item in `detail.payments`.
