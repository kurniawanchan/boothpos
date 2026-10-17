# Research: Edit Variant Sequence Number Manually

## Decision 1 — Editable part is the sequence number only
- **Decision**: the drawer shows the variant's code as fixed text and an input for the number after the hyphen.
- **Rationale**: spec Q1 A and FR-001/FR-009; the owner's SKUs keep the product code they were created with.
- **Alternatives**: editing the whole SKU (rejected by Q1); editing the code too (rejected: would bypass the segment rule).

## Decision 2 — Typed SKUs need a flag
- **Decision**: `sku_is_typed` boolean, default false.
- **Rationale**: Q2 A keeps typed SKUs across a segment change. A typed `SPF-PI-S-7` and a generated `SPF-PI-S-7` are the same text; only a flag distinguishes them.
- **Alternatives**: derive from the number (rejected: impossible), keep a separate table (rejected: one column is enough).

## Decision 3 — Server builds the SKU from the stored code
- **Decision**: the client sends only the number; the server takes the code from the variant's current SKU and appends the number.
- **Rationale**: the client can never send a code; Principle IV.
- **Alternatives**: the client sends the full SKU (rejected: lets the code be changed through the back door).

## Decision 4 — Normalisation and limits
- **Decision**: digits only; zero refused; leading zeros removed by casting to int; full SKU ≤14 characters (the column width); uniqueness over all rows including trashed and both data modes.
- **Rationale**: spec FR-002/FR-003; same width and uniqueness rules as feature 044.

## Decision 5 — Numbering counts typed SKUs with older prefixes
- **Decision**: `nextVariantSku` counts the trailing number of every variant of the product: `sequenceOf(current prefix)` when the prefix matches, otherwise the digits after the last hyphen.
- **Rationale**: after a segment change, a kept typed SKU still has an older prefix. Counting only the current prefix could reuse its number, against feature 044 FR-002.
- **Alternatives**: ignore older prefixes (rejected, see above); renumber typed SKUs (rejected: violates Q2 A).

## Decision 6 — Segment change skips typed variants
- **Decision**: typed variants keep their SKU; generated variants are regenerated with feature 044's rule.
- **Rationale**: Q2 A. Typed SKUs keep an older prefix, so they cannot collide with new generated SKUs; the generated number choice ignores them.

## Decision 7 — Audit and transaction
- **Decision**: one `sku_changed` activity entry with old and new SKU, inside the same transaction as the update.
- **Rationale**: FR-007; Principle IV.

## Open items
- Verify in tasks: whether the `BaseInput` component can host a fixed prefix inside the chip width; the layout is decided in the browser check.

## Decision 11 — SKU order is a drawer-only display rule (User Story 4)
- **Decision**: a pure comparison function in the SPA (`compareVariantSku`), applied to saved variants when the drawer loads them.
- **Rationale**: the drawer is the only place that shows the variants in this order; unsaved rows must keep their place (FR-014), which a server-ordered response cannot do.
- **Alternatives**: order in the API (rejected: unsaved rows and the duplicate flow cannot be placed by the server); plain text order (rejected: `…-10` before `…-2`; the assumption in the spec asks for numeric order).

