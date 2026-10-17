# Data Model: Product Segment Length (6 letters)

## products

| Column | Before | After | Notes |
|---|---|---|---|
| `product_segment` | `CHAR(3)` | `CHAR(6)` | Segment letters, uppercase, 1–6, no padding |
| `code_prefix` | `CHAR(10)` | `CHAR(13)` | `ART-CA-SEG`; UNIQUE (across DEMO and LIVE) |

Validation (single rule, `App\Support\ProductSegment`):
- Segment: letters A–Z in one or more groups separated by single hyphens (`^(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$` after trim and uppercase), at most 6 characters including hyphens. Empty → derived from the product name (3 letters, unchanged).
- Code: `^[A-Z]{3}-[A-Z]{2}-(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$`, length ≤ 13.
- SKU number: always the text after the LAST hyphen (digits only), so a hyphen inside the segment never affects number parsing.

## product_variants

| Column | Before | After | Notes |
|---|---|---|---|
| `sku` | `CHAR(14)` UNIQUE (migration 045 moved the uniqueness to `active_sku`) | `CHAR(20)` | `code_prefix` + `-` + number |
| `active_sku` | `CHAR(14)` generated stored, UNIQUE index | `CHAR(20)` generated stored, UNIQUE index | Same expression; recreated |

Validation: SKU length ≤ 20; number is digits only, ≥ 1, no leading zeros (unchanged).

## order_items / preorder_items

| Column | Before | After |
|---|---|---|
| `sku_snapshot` | `CHAR(14)` | `CHAR(20)` |

Historical text; widened only so the copy can hold a SKU that is now possible. Existing values are not rewritten.

## Migration: `2026_11_19_000001_widen_product_segment_and_sku_columns.php`

Order inside `up()`:
1. `ALTER TABLE product_variants DROP INDEX product_variants_active_sku_unique`
2. `ALTER TABLE product_variants DROP COLUMN active_sku`
3. `ALTER TABLE products MODIFY product_segment CHAR(6) NOT NULL`
4. `ALTER TABLE products MODIFY code_prefix CHAR(13) NOT NULL`
5. `ALTER TABLE product_variants MODIFY sku CHAR(20) NOT NULL`
6. `ALTER TABLE order_items MODIFY sku_snapshot CHAR(20) NOT NULL`
7. `ALTER TABLE preorder_items MODIFY sku_snapshot CHAR(20) NOT NULL`
8. `ALTER TABLE product_variants ADD COLUMN active_sku CHAR(20) GENERATED ALWAYS AS (IF(is_active = 1 AND deleted_at IS NULL, sku, NULL)) STORED AFTER sku`
9. `ALTER TABLE product_variants ADD UNIQUE INDEX product_variants_active_sku_unique (active_sku)`

`down()` reverses steps in reverse order with the old widths. It fails in strict mode if any row is longer than the old width (see research Decision 6).

## State transitions

None. Widths only; no status or flag changes.
