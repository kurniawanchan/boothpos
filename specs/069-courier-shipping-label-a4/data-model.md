**Classification**: INTERNAL

# Data Model: Courier Shipping Labels (feature 069)

**No migration. Nothing is stored.** The label is a rendering of existing rows.

## Read sources

| Entity | Fields used | Notes |
|---|---|---|
| `preorders` (scoped: `DataModeScope`, `SellerScope`) | `id`, `preorder_number`, `fulfillment` | Eligibility: `fulfillment = 'courier'` |
| `shipments` (`HasDataMode`, 1:1 via `preorders.shipment`) | `courier_name`, `tracking_number`, `recipient_name`, `recipient_phone`, `address_line`, `status` | Saved values only |
| settings → `store_identity` | `name`, `address`, `contact_phone`, `contact_person` | Built once per request (`BuildsInvoiceDocument`) |

Never read for a label: customer e-mail/social handle, items, prices, payments, costs, pre-order notes, shipment notes.

## Derived (not stored)

**ShippingLabel** = `{ preorder_id, preorder_number, courier_name, tracking_number, recipient_name, recipient_phone, address_line }`

**SkippedLabel** = `{ preorder_id, preorder_number | null, reason }`, `reason ∈ { not_found, not_mail_order, no_shipment }`
- `not_found` → `preorder_number = null` (other mode / other seller / missing look the same).

Invariant: `|labels| + |skipped| = |distinct requested ids|`; labels ordered by `preorder_number` ASC, then `id`.

## Client-side layout model (`utils/courierLabelSheet.js`)

- Page 794×1123 px; `COL_WIDTHS = [397, 396]`, `ROW_HEIGHTS = [562, 561]`, content box 395×560 px per cell.
- Label *i* → page `floor(i/4)`, cell `i % 4` (row-major).
- Validation: measured content height ≤ 560 px else `LabelTooTallError(index, height)`; nothing is clipped.
