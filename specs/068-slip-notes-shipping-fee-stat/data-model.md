**Classification**: INTERNAL

# Data Model: Shipping Notes on Bulk Slips and Total Shipping Fee Statistic

**No migration, no new table, no new column, no new stored value.** Everything below already exists; this feature only reads it.

## Existing fields read

| Entity | Field | Type | Used for |
|--------|-------|------|----------|
| `preorders` | `notes` | text, nullable | printed under the slip title (US1). Free text typed by staff; may contain customer-related details, so it is rendered as escaped plain text and never logged |
| `preorders` | `shipping_cost` | decimal(14,2), default 0 | summed for the statistic (US2) |
| `preorders` | `fulfillment` | enum `pickup`\|`courier` | statistic counts `courier` only |
| `preorders` | `status` | enum incl. `cancelled` | cancelled rows excluded from the statistic |

## Derived figure (not stored)

`total_shipping_fee` = Σ `preorders.shipping_cost` over the pre-orders matching the current list filters, where `status != 'cancelled'` and `fulfillment = 'courier'`.

- Same row set as `grand_total` / `total_outstanding`, minus pickup rows (which cannot be expected to carry a fee).
- Recomputed on every `GET /preorders/summary`; never cached or stored (no drift).
- Empty set → `"0.00"`.
- Scopes applied automatically by the Eloquent model: DEMO/LIVE `data_mode`, seller scope (058).

## Slip cell input (display only)

`invoice.notes` → shown iff `trim(notes) !== ''`; no length column change. Typical limit is the text column; a note too tall for a page triggers the existing `SlipTooTallError` path.

## State transitions

None.
