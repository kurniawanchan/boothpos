# Data Model: Seller-specific BOM from Purchase Order Lines

All changes are **additive**; no existing row is deleted or rewritten. Migration names (date prefixes are load-bearing):
`2026_11_02_000001_add_artist_id_to_purchase_orders_table`, `2026_11_02_000002_add_po_source_to_product_variant_bom_lines_table`, `2026_11_02_000003_add_bom_complete_to_product_variants_table`.

## `purchase_orders` (changed)

| Column | Type | Notes |
|---|---|---|
| `artist_id` | FK → `artists`, **nullable**, `restrictOnDelete` | The seller the purchase is for. NULL = legacy (created before this feature) → not offered by any BOM selector. Index `(artist_id, status)`. |

Rules: required on create; editable while no BOM row references any of the PO's lines (otherwise 409); assigning a seller to a legacy PO is always allowed; every assignment/change is audited.

## `purchase_order_items` (unchanged shape)

`product_id` ("Linked Product") stays and stays readable; the PO form no longer offers it. Lines of non-draft POs are stable (drafts are rewritten on edit, which is why drafts are not eligible sources).

## `product_variant_bom_lines` (changed)

| Column | Type | Notes |
|---|---|---|
| `purchase_order_item_id` | FK → `purchase_order_items`, nullable, `restrictOnDelete` | The trace. **NULL ⇒ legacy row.** Index. |
| `line_type` | enum(`material`,`service`) NOT NULL default `material` | Snapshot (legacy rows = `material`). |
| `item_name` | string(255) nullable | Snapshot: material name or service description. Legacy rows read the live material name. |
| `po_number` | string(30) nullable | Snapshot. |
| `vendor_id` | FK → `vendors`, nullable, `nullOnDelete` | Snapshot reference. |
| `vendor_name` | string(255) nullable | Snapshot (survives vendor deletion). |
| `unit_cost` | decimal(14,2) nullable | **Snapshot unit price** from the PO line; the cost basis. NULL for legacy rows. |
| `material_id` | now **nullable** | Services have none. Legacy rows keep it. |
| `qty_needed` | decimal(12,4) | Existing; quantity per ONE finished unit, must be > 0. |
| `notes` | text nullable | Existing. |

Indexes/uniques: add index on `product_variant_id`; **drop** `UNIQUE(product_variant_id, material_id)`; **add** `UNIQUE(product_variant_id, purchase_order_item_id)`. Legacy rows keep one-per-material in the service.

Derived at read time (never stored): `is_legacy` (= `purchase_order_item_id IS NULL`), `source_cancelled` (source PO status), `newer_price` (see research Decision 6), `po_qty` (source line's purchased qty, reference only), `item_cost = unit_cost × qty_needed`.

## `product_variants` (changed)

| Column | Type | Notes |
|---|---|---|
| `bom_complete` | boolean default false | The owner marked the BOM complete. |
| `bom_completed_at` | timestamp nullable | |
| `bom_completed_by` | FK → `users`, nullable, `nullOnDelete` | |

While `bom_complete = true`: `cost_price` **equals** the Total BOM Cost (kept in sync by `VariantBomService`) and cannot be changed by hand.

## State: variant BOM

```
(no rows) ──add rows──▶ draft ──mark complete (≥1 row, no legacy, all rows valid)──▶ complete
   ▲                      ▲                                                            │
   └──── remove last row ─┴──────────── reopen / auto-reopen (empty BOM, copy onto it) ◀┘
```

- Draft: cost price is hand-editable; BOM cost shown beside it.
- Complete: cost price follows BOM cost on every change (add/remove/qty/replace source).
- Reopen: cost price keeps its last value and becomes editable.

## Cost rules

- Row cost = `unit_cost × qty_needed` (PO rows, snapshot). Legacy row cost = live vendor-catalogue reference price × qty (unchanged behaviour), counted under Material cost and flagged.
- `material_cost` = Σ rows with `line_type = material`; `service_cost` = Σ rows with `line_type = service`; `bom_cost` = material + service. All in cents internally, money strings in responses.
- `cost_price` sync = `bom_cost` (only when `bom_complete`; complete implies no legacy rows, so only snapshots are summed).

## Invariants

- A BOM row's PO line belongs to a PO whose `artist_id` equals the variant's product's `artist_id`, and whose status was ordered/received/paid when the row was added/replaced (re-checked server-side).
- A row's `unit_cost`, `vendor_*`, `po_number`, `item_name` change only through `replace-source`.
- Same PO line at most once per variant.
- Deleting a PO that feeds a BOM is impossible (only drafts can be deleted; drafts cannot feed a BOM); cancelling one never changes a row.
- Past `order_items` / `preorder_items` keep their recorded `cost_price` (unchanged mechanism).
- Source and target of a copy share the same product.

## Audit (`activity_logs`, written by `ActivityLogger` in the same transaction)

Entity `ProductVariantBomLine` / `ProductVariant`; actions: `bom_item_added`, `bom_item_removed`, `bom_qty_changed`, `bom_source_replaced`, `bom_copied`, `bom_completed`, `bom_reopened`, `cost_price_synced` (old/new cost price), plus `purchase_order_seller_assigned` / `purchase_order_seller_changed` on the PO.
