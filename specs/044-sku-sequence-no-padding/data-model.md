# Data Model: SKU Sequence and Segment Changes

No new tables or columns.

## Product
| Field | Type | Rule |
|---|---|---|
| product_segment | string | 1–3 letters; changed only through the segment-change service (blank on edit = keep) |
| code_prefix | CHAR(10) | `artist(3)-category(2)-segment`; unique across modes; regenerated only on a segment change |

## Product variant
| Field | Type | Rule |
|---|---|---|
| sku | CHAR(14) | `code_prefix` + `-` + number; unique across all rows including trashed |

## Sequence rules
- New variant number = max(number of any variant of the product, trashed included) + 1; first is 1.
- Number is never reused within a product.
- Segment change: number = numeric tail of the current SKU; collision inside the product → next free number.

## Invariants
- Every variant SKU of a product starts with that product's `code_prefix` + `-`.
- `code_prefix` unique across products (both modes).
- Snapshots in sales and pre-orders keep the SKU they were recorded with.

## State transitions
- Product segment: `S1` → `S2` (only via `ProductSegmentChangeService`); all variant SKUs move from `P1-n` to `P2-n`.

## Read model (User Story 4)
- The product response carries `product_segment`: the stored segment (always present, the column is NOT NULL). Read-only; the stored value is still changed only by the segment-change service.

