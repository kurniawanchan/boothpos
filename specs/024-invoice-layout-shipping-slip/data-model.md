# Phase 1 Data Model: Pre-order Invoice Layout Refinements & Shipping Slip

## Preorder response shape (`PreorderController::present()`, extended)

One new field, reaching every consumer of `present()` (detail view, create/update/payment responses, and — via `invoicePayload()`'s spread — the invoice/payment-invoice endpoints):

```jsonc
{
  // ...existing fields unchanged...
  "created_at": "2026-09-23T10:15:00+00:00" // ISO 8601, always present (standard Eloquent timestamp)
}
```

No migration — `created_at` already exists as a column; this is a response-shape addition only.

## No other schema or response changes

Every other field this feature's UI needs already exists on the invoice payload:

| Needed for | Source field(s) | Already present since |
|---|---|---|
| Event name (header left column) | `invoice.event_name` | feature 014 |
| Location / available-on (header left column) | `invoice.event_location` / `invoice.event_available_on_date` | feature 014 / 023 |
| Pre-order number / status (header right column) | `invoice.preorder_number` / `invoice.status` | feature 007 |
| "To:" block (header right column) | `invoice.customer.{name,email,phone,social_handle,address}` | feature 001/020 (`CustomerResource`) |
| Payment column split | `invoice.payment_channels[].type` (`qr_ewallet`/`bank_transfer`) | feature 022 |
| Shipping slip visibility | `invoice.fulfillment` (`'courier'` = Mail Order) | feature 007 |
| Shipping slip "From" | `invoice.store_identity` | feature 022 |
| Shipping slip "To" | `invoice.customer` (same as header) | — |
| Shipping slip item type | `invoice.items[].name_snapshot` (de-duplicated client-side) | feature 007 |
| Footer message | `invoice.footer_text` | feature 022 |

## Frontend-only derived values (`PreorderInvoiceModal.vue` / `PreorderPaymentReceiptModal.vue`)

- `qrChannels = invoice.payment_channels.filter(c => c.type === 'qr_ewallet')`
- `bankChannels = invoice.payment_channels.filter(c => c.type === 'bank_transfer')`
- `itemTypes = [...new Set(invoice.items.map(i => i.name_snapshot))]`
- `showShippingSlip = invoice.fulfillment === 'courier'`
