# Research: Remove the Length Limit on Product Segment (6 letters)

## Decision 1: Maximum segment length 6, code 13, SKU 20

- **Decision**: Segment 1–6 letters; product code ≤ 13 (3 + 1 + 2 + 1 + 6); variant SKU ≤ 20 (13 + 1 + up to 6 digits).
- **Rationale**: Product owner chose "up to 6 letters" (Q1: A). 20 is the width that fits a 6-letter code with a 6-digit number and matches the widest sensible SKU. The spec makes this change apply to all products.
- **Alternatives considered**: No letter limit with VARCHAR(255) (rejected: no real ceiling, unique index on long strings); keep 3 (rejected: request).

## Decision 2: Widen by a new dated migration, not by editing old ones

- **Decision**: One new migration `2026_11_19_000001_widen_product_segment_and_sku_columns.php` using raw `ALTER TABLE`, like `2026_10_25_000001`.
- **Rationale**: CLAUDE.md forbids renaming or reordering migrations (FK order). The old `char(3)`/`char(10)`/`char(14)` definitions are the current state on disk, so a new migration is the only correct way to change them. Raw SQL because Blueprint::change() needs doctrine/dbal, which is not installed.
- **Columns**:
  - `products.product_segment`: `CHAR(3)` → `CHAR(6)`. It is the column that actually refuses a 4-letter segment today (strict mode).
  - `products.code_prefix`: `CHAR(10)` → `CHAR(13)`.
  - `product_variants.sku`: `CHAR(14)` → `CHAR(20)`.
  - `product_variants.active_sku`: a stored generated column copied from `sku`. It must be dropped and re-added at `CHAR(20)` with its unique index recreated. MySQL may refuse to modify a base column that a generated column depends on, so drop-widen-re-add is the safe order.
  - `order_items.sku_snapshot` and `preorder_items.sku_snapshot`: `CHAR(14)` → `CHAR(20)` (they copy the SKU text).
- **Alternatives considered**: `VARCHAR` instead of `CHAR` (rejected: this codebase uses `CHAR` for these identifiers and the generated column must match; changing type is a second change for no gain).

## Decision 3: Segment column type CHAR(6)

- **Decision**: `CHAR(6)` for `product_segment`.
- **Rationale**: Same type family as the existing column; the stored value is always uppercase letters with no padding (the rule trims and uppercases before saving). `CHAR` trailing-space trimming is harmless here.

## Decision 4: One place for the limits

- **Decision**: Add `ProductSegment::MAX_LENGTH = 6` (characters, hyphens included), `MAX_CODE_LENGTH = 13`, `MAX_SKU_LENGTH = 20`. Build `PATTERN`, `CODE_PATTERN`, `PATTERN_RULE` from the same rule (see Decision 9). Every service and request reads the constants.
- **Rationale**: Spec 042 already moved the segment rule into this class to stop five copies drifting apart. Adding the widths to the same place keeps that guarantee. The SPA keeps a display copy (`productSegment.js`); server remains the authority.
- **Alternatives considered**: Reading the widths from the database schema at runtime (rejected: slow, and the SPA cannot use it).

## Decision 5: Segment change must refuse a too-long SKU

- **Decision**: `ProductSegmentChangeService::changeSegment()` computes the regenerated SKU for every active variant and refuses the whole change (422 on `product_segment`, nothing written) if any SKU exceeds `MAX_SKU_LENGTH`. Number-keeping and collision rules from feature 045 are unchanged.
- **Rationale**: Without this check a long segment would make the database write fail with a generic error (strict mode) instead of the refusal the spec requires (FR-004).
- **Alternatives considered**: Silently truncating (rejected: would corrupt codes); letting the database refuse (rejected: 500 error, not a field message).

## Decision 6: Migration rollback caveat

- **Decision**: `down()` narrows the columns back. It is allowed only while every row fits; otherwise MySQL fails in strict mode and the rollback stops. Document this in the migration docblock and in quickstart.
- **Rationale**: Narrowing cannot truncate SKUs silently. Failing loudly matches the SKU-immutability rule (FR-006 of feature 045).

## Decision 7: Import and DEMO clone

- **Decision**: The importer's segment check and message use `ProductSegment::PATTERN` (now 1–6). The importer has no separate SKU length check today; add none unless the existing path can write a >20 SKU. Verify in the implementation phase by reading `MasterDataImportService` around the `product_segment` and `sku` handling (lines ~700–760).
- **DEMO clone**: `CodeAllocator` reads `CODE_PATTERN`; only the message text changes. Demo codes are allocated from artist/category, so the segment length is copied as-is.

## Decision 8: SPA

- **Decision**: `previewSegment` slices to 6; `maxSequenceLength` uses 20 (`20 − code length − 1`); the segment input's `maxlength` becomes 6; the locale label becomes "Segment (1–6)" / "Segmen (1–6)".
- **Rationale**: Keep the preview and the input consistent with the server; the server still refuses anything else.

## Decision 9: Hyphens inside a segment

- **Decision**: A segment is one or more letter groups separated by single hyphens, at most 6 characters in total including the hyphens. Regex for the segment: `^(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$`. The code regex is `^[A-Z]{3}-[A-Z]{2}-(?=[A-Z-]{1,6}$)[A-Z]+(?:-[A-Z]+)*$`. Leading, trailing and doubled hyphens are refused.
- **Rationale**: The request is to accept a dash. Counting hyphens inside the 6-character limit keeps the 13/20 widths in data-model.md valid without a second width change. Only the segment may contain a hyphen; the artist (3) and category (2) keep their fixed letters.
- **Reading taken**: "accept dash" means a hyphen inside the segment, not a hyphen as a separator at the end. If the owner meant something else, this decision is the one to revisit.
- **Why it is safe for the rest of the code**: SKUs are split on their LAST hyphen everywhere (`splitSku`, `VariantSkuService::codeOf` via `Str::beforeLast`, `ProductCodeGenerator::trailingNumber`). The number is always digits and comes last, so a hyphen in the segment does not change where the number starts. `sequenceOf()` matches the prefix with `preg_quote`, which handles hyphens.

## Decision 10: Two places that split on hyphens or fixed widths (must change)

- **`CodeAllocator::productPrefix()`** uses `explode('-', $livePrefix)` and destructures three parts. With `ART-CA-SH-K` PHP drops the segment's second part, so the clone would write the wrong code. Fix: `explode('-', $livePrefix, 3)`, so the segment keeps its hyphen.
- **`MasterDataExportService::skuTail()`** returns `substr($sku, -7)` as the export `code` and the start of `item_label` (spec 041, FR-005). This was written for the old 3-digit SKUs and is already wrong for unpadded numbers from feature 044 (`SPF-SK-F-1` gives `SK-F-1`, not a code). With a 6-character segment it is wrong again. The width-based slice must be replaced by one that does not depend on length. Open choice for the product owner: (a) the text after the artist and category, i.e. segment + hyphen + number (`SH-K-1`); (b) the full SKU. **Decided 2026-10-18: option (a).** `code` = the SKU after `ART-CA-` (segment + number, e.g. `SH-K-12`). Products whose code has no hyphens (legacy) keep the 7-character tail, because that format is fixed-width and the spec 041 test locks it. `item_label` follows `code`.
