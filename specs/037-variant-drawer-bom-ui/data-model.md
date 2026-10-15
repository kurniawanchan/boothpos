# Data Model: 037-variant-drawer-bom-ui

**No schema change.**

## Existing tables used unchanged

- `product_variants` — new duplicate rows are created through the existing `POST /products/{id}/variants` (SKU generated server-side, `bom_complete` false by default).
- `product_variant_bom_lines` — rows are copied by `VariantBomService::copy()` (snapshot columns copied, independent afterwards).
- `stock_movements` — a copied stock value is recorded by the existing adjustment path with the shared reason.

## UI-only state (not persisted)

- **Duplicate seed** (an unsaved variant row): `variant_name` (+ copy marker), `cost_price`, `sell_price`, `low_stock_alert`, `current_stock` (source value), `original_stock = 0`, `is_active = true`, `copy_bom_from = source.id | ''`, no `id`/`sku`/image.
- **Copy selection**: array of sibling variant ids ticked in `VariantPickList` (same product, never the source).

## API shape change (additive)

`POST /variants/{variant}/bom/copy-out`: `mode` ∈ `next | all | selected`; `variant_ids: int[]` required when `mode = selected` (1..200, distinct, existing). Same product / not the source / confirm-replace rules enforced by the service. Response unchanged: `{results: [{variant_id, sku, status, rows, reopened}]}`; 409 `requires_confirmation` + `variants[]` unchanged.

## Validation rules

- `variant_ids.*`: integer, distinct, `exists:product_variants,id`; the service additionally rejects: the source itself (422), a variant of another product (422), a missing/deleted variant (count mismatch, 422), a source with no rows (422).
