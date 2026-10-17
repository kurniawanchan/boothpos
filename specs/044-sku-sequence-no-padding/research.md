# Research: SKU Sequence Without Zero Padding, and Segment Changes

## Decision 1 — One place for the SKU format
- **Decision**: `ProductCodeGenerator::variantSku(prefix, number)`; every creator calls it.
- **Rationale**: three creators today (store, variant create, import) each build `…-NNN` through `nextVariantSku`; a segment change would be a fourth. One method keeps them in step (Principle I).
- **Alternatives**: format inline in each caller (rejected: drift, as with the segment rule).

## Decision 2 — Next number rule
- **Decision**: next number = max of the product's numbers over ALL variants (soft-deleted included) + 1, starting at 1; the collision loop stays.
- **Rationale**: the spec says numbers are never reused. Counting rows gives the same answer for clean data but breaks when legacy SKUs do not follow the count. Max is the direct statement of the rule.
- **Alternatives**: keep `count + 1` (rejected: wrong for legacy rows and not what the spec says).

## Decision 3 — Segment change is a dedicated service
- **Decision**: `ProductSegmentChangeService` in one transaction with a row lock on the product.
- **Rationale**: it changes the product and all its variants and must audit in the same transaction (Principle IV). The controller only delegates (Principle I).
- **Alternatives**: put it in `ProductController::update` (rejected: business logic in a controller); regenerate lazily on read (rejected: SKUs must be stored and unique).

## Decision 4 — Number kept on a segment change
- **Decision**: each variant keeps the number in its current SKU (`sequenceOf`).
- **Rationale**: spec option A and the owner's example (`…-SH-1` → `…-S-1`). Legacy SKUs without dashes still yield their trailing number (`RYUKYSAK0001` → 1).
- **Collision inside one product** (possible only with legacy data): the later variant takes the next free number. Reported in the audit count.

## Decision 5 — Soft-deleted variants are regenerated too
- **Decision**: all variants of the product, including trashed ones, get the new SKU.
- **Rationale**: the SKU unique index covers trashed rows, and a restored variant must carry its product's current code. Regenerating trashed rows keeps every variant of the product on one code. The cost is one more row per trashed variant of the same product.

## Decision 6 — Blank segment on edit keeps the current one
- **Decision**: blank = no change on update; derivation from the name applies on create only.
- **Rationale**: deriving on edit would silently rename codes whenever a name changes.

## Decision 7 — Snapshots and history untouched
- **Decision**: `order_items.sku_snapshot`, `preorder_items.sku_snapshot` and stock movements are not rewritten.
- **Rationale**: spec FR-005; a snapshot is a historical record (Principle IV).

## Decision 8 — No migration
- **Decision**: none. New SKUs fit `CHAR(14)`: prefix (8–10) + hyphen + number (1–3 digits in practice) ≤ 14.
- **Verification**: a test creates a 10-character prefix product with 11 variants (`…-11`).

## Open items
- None blocking. Verify in tasks whether any SPA screen other than ProductsView shows a `-001` hint (grep `-001` in resources/js).

## Decision 9 — Saved segment returned by the product resource
- **Decision**: `ProductResource` returns the stored `product_segment`. No code-based fallback: the column is NOT NULL, so every product has one.
- **Rationale**: the edit form already maps this field; the response was the only gap. Deriving on the server keeps one definition of the rule.
- **Alternatives**: compute the segment in the SPA from `code_prefix` (rejected: the SPA would duplicate the rule); read it back from the code on the server (rejected: unreachable, the column is required).

## Decision 10 — Preview in edit mode uses the saved segment
- **Decision**: no new preview logic; once the field is populated the existing `previewSegment` path uses it. The name-derived fallback applies only when the segment is blank (create mode).
- **Rationale**: spec FR-009; avoids two preview rules.

