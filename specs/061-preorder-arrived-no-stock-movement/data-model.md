**Classification: INTERNAL**

# Data Model: Pre-order Arrival Without Stock Movement

No tables, columns or migrations change. This describes behaviour of existing data.

## Pre-order status path

`ordered → dp_paid → arrived → settled → handed_over` (and `cancelled` from ordered, dp_paid, arrived). `ALLOWED_TRANSITIONS` is unchanged.

| Event | Status change | Stock effect (before) | Stock effect (after) |
|---|---|---|---|
| Create, edit before arrival, payments before arrival | ordered / dp_paid | none | none |
| Move to `arrived` | → arrived | `+qty` per item (`purchase`) | **none** |
| Move to `arrived` while fully paid | → arrived → settled (same transaction) | `+qty` | **none** |
| Payment completes an `arrived` pre-order | arrived → settled | none | none |
| Edit quantities while arrived/settled | none | delta (`purchase`, reference preorder) | **none** |
| Move to `handed_over` (requires fully paid) | settled → handed_over | `-qty` per item (`preorder_handover`) | `-qty` (unchanged) |
| Split, duplicate, cancel | as before | none | none |

## Lifecycle stock arithmetic

- Before: `+qty` at arrival, `−qty` at hand-over, net 0.
- After: `−qty(final)` at hand-over only.

## Stock history (`stock_movements`)

- No new type. A pre-order now writes rows only with type `preorder_handover` (reference type `preorder_item`).
- Old rows (`purchase` with reference type `preorder_item` or `preorder`) stay and keep resolving to their pre-order. Nothing is rewritten.

## Activity log (`activity_logs`)

New action written only by the correction command:

| Field | Value |
|---|---|
| `action` | `preorder_status_corrected` |
| `entity_type` / `entity_id` | `preorder` / the pre-order id |
| `old_values` / `new_values` | `{status: arrived}` / `{status: settled}` |
| `description` | short Indonesian text, no customer data |
| `user_id` | the `--by` user |

## Selection rule for the correction

A pre-order is corrected when, in either data mode: `status = 'arrived'` AND `PaymentSummary::for($p)['status'] === 'fully_paid'` (rejected payments excluded, same as everywhere) AND `total_amount > 0`. Everything else is left alone.
