# Data Model: Edit Variant Sequence Number Manually

## Product variant (changed)
| Field | Type | Rule |
|---|---|---|
| sku | CHAR(14) | `<prefix>-<n>`; prefix = code at the time the SKU was set; unique across all rows (trashed included) |
| sku_is_typed | BOOLEAN NOT NULL DEFAULT 0 (new) | true only when the owner typed the number; false for generated SKUs |

## Sequence rules
- Typed number: digits only, ≥1, leading zeros removed, `<prefix>-<n>` ≤ 14 characters, unique.
- Next generated number: highest trailing number over all variants of the product (any prefix) + 1; first is 1.
- Segment change: typed variants keep their SKU; generated variants take the new code with their current number.

## Invariants
- A SKU is never changed without a row in the activity log.
- Sales and stock snapshots keep the SKU recorded at sale time.
- Numbers are never reused within a product (feature 044 FR-002).
- A typed SKU may keep an older prefix after a segment change (Q2 A).

## Migration
- `2026_11_17_000001_add_sku_is_typed_to_product_variants`: add the column with default false; no backfill needed.
