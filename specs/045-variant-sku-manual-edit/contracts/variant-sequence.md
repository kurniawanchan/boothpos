# Contract: variant sequence number

## `PUT /api/v1/variants/{variant}` — `sequence` (optional)
- Absent or blank: SKU unchanged.
- Same number as now: no change.
- Digits only, ≥1: SKU becomes `<current code>-<number>` (leading zeros removed); `sku_is_typed` set to true; activity `sku_changed`.
- Letters, symbols, or 0: 422, `errors.sku`.
- Resulting SKU longer than 14 characters: 422, `errors.sku`.
- Resulting SKU used by another variant (any mode, trashed included): 422, `errors.sku`.
- Nothing is saved when any of these fails.

## `POST /api/v1/products/{product}/variants` — `sequence` (optional)
- Absent or blank: generated SKU (as today).
- Given: same rules as above, using the product's code.

## Not changed
- Sales and stock snapshots keep their recorded SKU.
- The product code is not editable through variants.
- Segment change: typed SKUs keep their SKU; generated SKUs follow the new code.

## `PUT /api/v1/products/{product}/variant-sequences` — all number changes of one product, together
- Body: `items: [{ variant_id, sequence }]`. Used by the product edit drawer so a shift such as `…-10` → `…-11` and `…-11` → `…-12` is saved in one step.
- All new SKUs are checked as one final state: no two variants of the product may end with the same SKU, and no changed SKU may be used by any other row in the store (trashed included, both data modes).
- Success: 200 with the product. Any refusal: 422 with the message on `sequences.<variant_id>` of the row concerned; nothing is saved.
- One `sku_changed` activity entry per changed variant, in the same transaction.
