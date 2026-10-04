# Data Model: PO Row Actions and Out-of-date Database Errors

**No schema change.** Behaviour is derived from existing columns.

## Action availability per purchase order status

| Action | draft | ordered | received | paid | cancelled |
|---|---|---|---|---|---|
| Detail | ✔ | ✔ | ✔ | ✔ | ✔ |
| Edit | ✔ everything | ✔ vendor/seller/notes | ✔ vendor/seller/notes | ✔ vendor/seller/notes | ✔ vendor/seller/notes |
| Delete | ✔ (confirm) | ✖ disabled: "Only draft purchase orders can be deleted. Cancel it instead." | ✖ same | ✖ same | ✖ same |
| Status actions | Mark Ordered, Cancel | Mark Received, Cancel | Mark Paid | — | — |

Edit constraints regardless of status: lines are editable only in draft; the seller cannot change while any BOM row references one of the order's lines (409). Delete is refused whenever the order has any payment row or any BOM row references its lines (defensive; unreachable for drafts in normal flow).

## Audit entries (`activity_logs`, same transaction as the change)

| Action | When | Values |
|---|---|---|
| `purchase_order_updated` (NEW) | vendor changed or lines rewritten | old/new: `vendor_id`, `total_amount`, `lines` (count) |
| `purchase_order_seller_assigned` / `_changed` | existing (feature 034) | `artist_id` |
| `deleted` (existing) | draft deleted | snapshot |

## Derived state: database update status

`SchemaStatus::pendingMigrations()` = migration files on disk − names in `migrations` table. Exposed only as a boolean `schema_update_required` (owner/admin), never the list. A schema error during a request surfaces as HTTP 503 `code: schema_outdated`.
