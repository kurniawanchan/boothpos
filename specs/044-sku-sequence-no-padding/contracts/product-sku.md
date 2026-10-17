# Contract: product SKU format and segment change

## SKU format
- New variant: `<code_prefix>-<number>`, number without leading zeros, starting at 1. Examples: `VLC-SK-S-1`, `VLC-SK-S-11`.
- Existing SKUs (for example `SPF-SH-S-001`) are unchanged.

## `PUT /products/{id}` — `product_segment` (optional)
- Absent or blank: segment unchanged.
- Same segment as now: no change.
- Valid 1–3 letters, different from now: every variant SKU is regenerated from the new `code_prefix`; each variant keeps its number. Response 200 with the new `code_prefix` and variant SKUs.
- New code already used by another product (any mode, trashed included): 422 with `errors.product_segment`; nothing changes.
- Invalid (4 letters, digits, symbols): 422 on `product_segment`; nothing changes.
- Audit: one `segment_changed` activity entry in the same transaction.

## Not changed
- Sales and pre-order snapshots (`sku_snapshot`), stock movements, BOM lines: keep their recorded values.
- Excel import: new variants get the new format; a SKU that no longer exists after a segment change is an unknown-SKU row.

## Product response (User Story 4)
- `GET /api/v1/products/{id}` and the list return `product_segment` (the stored segment, always present).
- The edit form populates the Segment field from it. Saving the same value changes nothing.

