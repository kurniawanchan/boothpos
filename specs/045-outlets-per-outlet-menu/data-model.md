**Classification**: INTERNAL

# Data Model — 045 Outlets

## 1. Renames (Stage 1)

| Before | After | Meaning |
|---|---|---|
| table `artists` | `outlets` | same rows/ids/codes (code char(3) unique, name, contact_phone, contact_email, notes, is_active, soft deletes, `data_mode`) |
| `products.artist_id` | `products.home_outlet_id` | the item's home outlet (required; item code prefix; BOM/PO rule) |
| `order_items.artist_id` | `order_items.home_outlet_id` | immutable snapshot of the item's home outlet at sale time |
| `preorder_items.artist_id` | `preorder_items.home_outlet_id` | same, for pre-orders |
| `purchase_orders.artist_id` | `purchase_orders.outlet_id` | the buying outlet (BOM rule compares with `products.home_outlet_id`) |
| `session_opening_cash_entries.artist_id` | `outlet_id` | legacy per-seller opening cash (new shifts: one entry for the shift's outlet) |
| table `artist_settlements` | `outlet_settlements` (`artist_id`→`outlet_id`) | stored settlement snapshot; keyed by the **report outlet** (D9) |
| `roles.menu_keys` value `artists` | `outlets` | menu permission |
| setting `multi_artist_enabled` | `multi_outlet_enabled` | licence tier (Pro one outlet / Master several) |

## 2. New columns (Stage 2 and 5)

All added nullable → backfilled → made NOT NULL with FK to `outlets` and an index (D5, D8):

| Table | Column | Stamped from |
|---|---|---|
| `cashier_sessions` | `outlet_id` | validated request / single assignment |
| `orders` | `outlet_id`, `outlet_backfilled` (bool, default false; true only for pre-migration rows) | shift |
| `payments`, `kitchen_tickets` | `outlet_id` | order |
| `dining_tables`, `stations` | `outlet_id` | creation context; unique `(outlet_id, name)` for tables |
| `preorders` | `outlet_id` (+ backfilled flag) | creating user's effective outlet |
| `stock_movements`, `material_stock_movements` | `outlet_id` | the call's outlet argument (D12) |
| `item_availability_marks` | `outlet_id` | per-outlet 86 (existing global marks expanded) |
| `price_history` | `outlet_id` NULL | null = base price change |
| `stock_movements.type` enum | + `transfer_out`, `transfer_in`, `transfer_cancel` (same for materials) | |

## 3. New tables

### `outlet_user` (Stage 2)
`user_id`, `outlet_id` (PK pair). Owners/admins implicit all (role flag `all_outlets`); migration assigns every existing non-admin user to every current outlet.

### `outlet_item_settings` (Stage 3) — materialised relation
`id`, `outlet_id` FK, `product_id` FK, `is_carried` bool default true, timestamps; unique `(outlet_id, product_id)`; `data_mode`. Rows created for every (outlet × product) at migration and by `OutletMenuService` on product/outlet creation.

### `variant_outlet_prices` (Stage 3) — sparse overrides
`id`, `variant_id` FK, `outlet_id` FK, `price` decimal(14,2) ≥ 0; unique `(variant_id, outlet_id)`; written **only** by `PriceService` (with `price_history.outlet_id`).

### `variant_outlet_stock`, `material_outlet_stock` (Stage 5)
`id`, `variant_id|material_id`, `outlet_id`, `current_stock` (int for variants, decimal(…,3) for materials as today), timestamps; unique pair; row locked on every movement. `product_variants.current_stock` / `materials.current_stock` remain as **cached Σ on-hand** (excludes in-transit), updated in the same transaction.

### `stock_transfers`, `stock_transfer_lines` (Stage 6)
```text
stock_transfers: id, number UNIQUE (TRF-YYYYMMDD-####), from_outlet_id, to_outlet_id (≠), status enum(in_transit|received|cancelled),
                 has_discrepancy bool, note, client_ref UNIQUE NULL, dispatched_by/at, received_by/at, cancelled_by/at, data_mode, timestamps
stock_transfer_lines: id, transfer_id, kind enum(variant|material), item_id, qty_sent > 0, qty_received NULL (≤ qty_sent), discrepancy_reason NULL
```
In transit = Σ `qty_sent` over lines of `in_transit` transfers. Lines are immutable after dispatch (cancel + recreate).

## 4. Derived values and invariants

| Name | Rule |
|---|---|
| Effective outlets | permitted (assignments or all for admins) ∩ `X-Outlet-Id` (absent ⇒ all permitted); id outside permitted ⇒ 403 |
| Orderable at outlet | product active ∧ `outlet_item_settings.is_carried` ∧ no unexpired mark for the outlet ∧ (variant tracked ⇒ outlet balance ≥ qty) |
| Price at outlet | `variant_outlet_prices.price` if present else `product_variants.sell_price` |
| Report outlet of an order item | `CASE WHEN orders.outlet_backfilled THEN order_items.home_outlet_id ELSE orders.outlet_id END` |
| **I1** | `variant_outlet_stock.current_stock` = Σ `stock_movements.qty_change` for (variant, outlet) (and for materials) |
| **I2** | `product_variants.current_stock` = Σ over outlets of `variant_outlet_stock.current_stock` |
| **I3** | Σ outlet balances + in-transit + recorded shortfalls is unchanged by dispatch, receipt and cancel; no tracked balance < 0 |
| **I4** | Σ per-outlet report rows = all-outlets total for any period (post-change); history rows equal the pre-change reports |
| **I5** | Every outlet-scoped table is filtered by every read path (enumerating test) |

## 5. State transitions

```text
Transfer:  (create+dispatch) ──▶ in_transit ──receive(all or partial)──▶ received   (has_discrepancy if short)
                                       └──────cancel──────────────────▶ cancelled   (goods back to source)
Shift:     opened(outlet fixed) ─▶ closed
Order:     outlet fixed at creation (from the shift); never changes
```

## 6. Migration order (Stage by stage; each step re-runnable, each stage reconciled)

1. **S1** rename table/columns/enum values/menu key/setting; model & route renames; reconcile counts and totals.
2. **S2** create `outlet_user`; add nullable `outlet_id` columns; backfill to the first outlet (+ `outlet_backfilled = true` on pre-existing orders/preorders); assign users; make columns NOT NULL + FK + indexes.
3. **S3** create `outlet_item_settings` (all carried), expand `item_availability_marks` per outlet, add `price_history.outlet_id`, create `variant_outlet_prices` (empty).
4. **S5** create balance tables; backfill each variant balance to its **home outlet**, each material balance to the **first outlet**; stamp `stock_movements.outlet_id` the same way (home outlet for variants, first outlet for materials); verify I1/I2.
5. **S6** create transfer tables; extend enums.
6. **S7** create/refresh `outlet_settlements` snapshot using the report-outlet helper; verify I4.
