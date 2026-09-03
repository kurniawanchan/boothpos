# Phase 1 Data Model

## New tables

### `purchase_orders`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| po_number | string, unique | server-generated, mirrors `order_number`/`preorder_number` generation pattern (mode-scoped uniqueness per research.md's linked convention in CLAUDE.md — generated via `withoutGlobalScope(DataModeScope::class)` count, same as `OrderService::generateOrderNumber()`) |
| vendor_id | FK → vendors, restrict on delete | |
| status | enum: draft, ordered, received, paid, cancelled | default `draft` |
| ordered_at, received_at, paid_at, cancelled_at | nullable datetime | stamped by `PurchaseOrderService` on each transition |
| cancel_reason | nullable string | required by the request when transitioning to `cancelled` |
| subtotal, total_amount | decimal(14,2) | server-computed from line items, never client-trusted |
| notes | nullable text | |
| data_mode | via `HasDataMode` | |
| created_by (user_id) | FK → users | |
| timestamps, soft deletes | | |

### `purchase_order_items`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| purchase_order_id | FK, cascade delete | only deletable while parent is `draft` (FR-006) |
| line_type | enum: material, service | |
| material_id | nullable FK → materials | required when `line_type = material` |
| product_id | nullable FK → products | the FR-004 link; optional regardless of line_type |
| description | nullable string | required when `line_type = service` (what the service was) |
| qty | decimal(12,3) | |
| unit_price | decimal(14,2) | |
| line_total | decimal(14,2) | server-computed `qty * unit_price` |

### `materials` (altered)
- **+ `current_stock` decimal(12,3) default 0** — new column, per research.md R4.

### `material_stock_movements`
Mirrors `stock_movements` exactly, scoped to `Material`:
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| material_id | FK → materials | |
| type | enum: purchase | only value written by this feature; append-only history |
| qty_change | decimal(12,3) | positive for this feature (a receipt) |
| balance_after | decimal(12,3) | snapshot, same pattern as `stock_movements.balance_after` |
| reference_type, reference_id | nullable | points at the `purchase_order_items` row that caused it |
| user_id | FK → users | who triggered the Received transition |
| data_mode | via `HasDataMode` | |
| created_at | | append-only, no updated_at needed |

### `payments` (no migration — existing columns reused)
- `notes` already exists and is fillable; `channel_id`/`method`/`amount`/`purpose` already exist. Only `StoreOrderRequest`'s validation rules change (add `payments.*.notes`).

### `pos_drafts`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| user_id | FK → users | owner/cashier who saved it |
| event_id | nullable FK → events | for context/display in the drafts list |
| customer_id | nullable FK → customers | snapshot reference; may point at a since-deleted customer, resume flow must handle gracefully per spec edge case |
| cart_snapshot | json | `{ items: [{variant_id, qty, discount_amount}], discount_amount }` — loosely validated, not FK-enforced (research.md R8) |
| label | nullable string | optional cashier-entered note to identify the draft in the list |
| data_mode | via `HasDataMode` | |
| timestamps | | `created_at` used as "saved time" in the drafts list |

### `session_opening_cash_entries`
| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| session_id | FK → cashier_sessions, cascade delete | |
| artist_id | nullable FK → artists | null = non-attributed amount |
| amount | decimal(14,2) | |
| data_mode | via `HasDataMode` | |
| created_at | | |

### `settings` (no migration — existing key/value table reused)
New rows, same pattern as `store_name`/`system_mode`/`multi_artist_enabled`:
- `theme_accent_color` (group `appearance`) — hex string, e.g. `#2f9e6e`.
- `receipt_footer_text` (group `receipt`) — nullable text.
- `receipt_show_logo` (group `receipt`) — boolean, default true (reuses the existing `store_logo_path` value; this only toggles whether it's *printed on receipts*, independent of whether it shows elsewhere in the app).

## State transitions

### Purchase Order status
```
draft --(place order)--> ordered --(goods/service received)--> received --(payment recorded)--> paid
draft --(cancel)--> cancelled
ordered --(cancel)--> cancelled
```
`received`, `paid`, `cancelled` are terminal. Only `draft` allows line-item edits (FR-006).

### POS Draft
```
(cart being built) --(save as draft)--> saved --(resume)--> (cart being built again) --(checkout)--> completed order, draft deleted
saved --(discard)--> deleted
```

## Key relationships

- `PurchaseOrder` 1—N `PurchaseOrderItem` N—1 `Material` (nullable), N—1 `Product` (nullable, the FR-004 link)
- `PurchaseOrder` N—1 `Vendor`
- `Material` 1—N `MaterialStockMovement`
- `Order`/`Preorder` 1—N `Payment` (unchanged relationship; only the *count* per transaction changes from "always 1 at creation time" to "1 or more")
- `CashierSession` 1—N `SessionOpeningCashEntry` N—1 `Artist` (nullable)
- `PosDraft` N—1 `User`, loosely N—1 `Customer` (no FK enforcement per research.md R8)
