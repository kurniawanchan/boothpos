**Classification: INTERNAL**

# Feature Specification: SKU Sequence Without Zero Padding, and Segment Changes

**Feature Branch**: `052-sku-sequence-no-padding`

**Created**: 2026-10-17

**Status**: Draft (updated 2026-10-17: saved segment shown in the edit form)

**Input**: User description: "in product, update: sequence not using 00, just use incremental number, for example: VLC-SK-S-1, VLC-SK-S-2, VLC-SK-S-3, and so on; when I update segment, also update the sequence to use the new format."

## Current behaviour (from code, for reference)

- Each variant's SKU is the product code, a hyphen, and a sequence zero-padded to 3 digits: `SPF-SH-S-001`, `-002`, `-003`.
- The sequence starts at 1 for the first variant of a product and counts all variants ever created, including deleted ones, so numbers are never reused.
- A product's code (artist, category and segment) is permanent once created (F19.4). The product form shows the segment field in edit mode, but the server ignores a changed segment without any message, so today the segment cannot change.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Sequence without leading zeros (Priority: P1)

The owner creates variants for a product and gets SKUs with a plain incrementing number, with no leading zeros: `VLC-SK-S-1`, `VLC-SK-S-2`, `VLC-SK-S-3`, and so on.

**Why this priority**: This is the requested format for every new variant, and it is independent of the segment question.

**Independent Test**: Create a product with segment `S` and three variants. The SKUs are `VLC-SK-S-1`, `VLC-SK-S-2`, `VLC-SK-S-3`.

**Acceptance Scenarios**:

1. **Given** a new product with segment `S`, **When** the owner adds its first variant, **Then** the SKU is `<code>-1` (for example `VLC-SK-S-1`).
2. **Given** a product that already has variants `…-1` and `…-2`, **When** the owner adds a third variant, **Then** its SKU is `…-3`.
3. **Given** a product whose variant `…-2` was deleted, **When** the owner adds another variant, **Then** its SKU is `…-3`, not `…-2` (numbers are not reused).
4. **Given** a product with 10 variants, **When** the owner adds an eleventh, **Then** its SKU is `…-11` (no padding and no collision).

---

### User Story 2 - Existing SKUs stay as they are (Priority: P1)

Variants created before this change keep their existing SKUs (for example `SPF-SH-S-001`). Their sales history, stock history and any label printed with them stay valid.

**Why this priority**: SKUs are used on past receipts, stock records and imports; changing them silently would break history.

**Independent Test**: Before and after the change, an existing variant `SPF-SH-S-001` still shows `SPF-SH-S-001` on every screen and export.

**Acceptance Scenarios**:

1. **Given** an existing variant `SPF-SH-S-001`, **When** the change is released, **Then** its SKU is still `SPF-SH-S-001`.
2. **Given** an existing product with variants `…-001` and `…-002`, **When** the owner adds a new variant, **Then** the new SKU is `…-3`, and the two existing SKUs are unchanged.

---

### User Story 3 - Changing a product's segment (Priority: P2)

The owner changes the segment of an existing product, and every variant SKU follows the new segment in the new sequence format. Past sales and stock records keep the SKU they were recorded with.

**Why this priority**: The request asks for it. It changes codes that were permanent before, so it is decided separately from the new-variant format.

**Independent Test**: Change a product's segment from `SH` to `S` and check that each variant SKU now uses `S` and keeps its number; check that an old order still shows the old SKU.

**Acceptance Scenarios**:

1. **Given** a product with segment `SH` and variants `…-SH-1` and `…-SH-2`, **When** the owner changes the segment to `S`, **Then** the variants become `…-S-1` and `…-S-2`.
2. **Given** the same change, **When** an earlier sale of the `…-SH-1` variant is opened, **Then** the sale still shows `…-SH-1` as recorded.
3. **Given** a product whose new code is already used by another product, **When** the owner saves the new segment, **Then** the save is refused with a message on the segment field and no SKU changes.

---

### User Story 4 - The saved segment is shown when a product is edited (Priority: P1)

When the owner opens an existing product to edit it, the Segment field shows the segment the product was saved with (for example `PI`), and the code preview shows the product's saved code. The owner should never see a blank field or a code built from the name when the product already has a saved segment.

**Why this priority**: The owner decides whether to change a segment from what they see. A blank field invites a wrong edit, and a name-based preview shows a code that is not the product's code (screenshot: product `MCYT` saved as `SPF-PI-S`, edit form showed a blank segment and a preview of `SPF-PI-MCY-1`).

**Independent Test**: Open the edit form of a product saved with segment `S`. The Segment field shows `S`, the header shows `SPF-PI-S`, and the preview shows the saved code with the sequence (`SPF-PI-S-1`), not a code built from the name.

**Acceptance Scenarios**:

1. **Given** a product saved with segment `PI`, **When** the owner opens its edit form, **Then** the Segment field shows `PI`.
2. **Given** the same product, **When** the edit form opens, **Then** the code preview shows the saved code (`…-PI-…`), not a segment derived from the product name.
3. **Given** a product saved with segment `S`, **When** the owner opens its edit form and saves without touching the segment, **Then** the segment and every variant SKU stay as they are.
4. **Given** any saved product, **When** the owner opens its edit form, **Then** the Segment field shows its stored segment. The segment is always stored (the column is required), so a saved product never shows a blank field or a name-derived value.

---

### Edge Cases

- A sequence number above 999 (not possible today for typical catalogues; the SKU must still fit the stored width, see Assumptions).
- A deleted variant's number is not reused (User Story 1, scenario 3).
- Changing a segment to one that already exists for the same artist and category is refused, as today's duplicate-code rule.
- Past sales and stock records keep the SKU they were recorded with after a segment change (they hold their own snapshot).
- After a segment change, the old SKUs stop working in lookups and in imports. An Excel file that still names an old SKU is reported as an unknown SKU row; the owner must use the new SKU.
- Excel import of products: an imported variant without a SKU gets the new format; an import that names an existing SKU keeps that SKU.
- A product response that omits the saved segment makes the edit form look empty and the preview wrong (User Story 4). The saved segment must travel with the product wherever the product is shown for editing.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The SKU of a newly created variant MUST be the product code, a hyphen, and the sequence number with no leading zeros (`-1`, `-2`, … `-10`, `-11`).
- **FR-002**: The sequence number MUST be one more than the highest number already used by that product's variants, counting deleted variants, so numbers are never reused.
- **FR-003**: Variants created before this change MUST keep their SKUs unchanged.
- **FR-004**: The product code MUST keep the segment as typed (1–3 letters, from the previous feature).
- **FR-005**: Sales, stock and settlement records MUST keep the SKU they were recorded with.
- **FR-006**: Changing a product's segment MUST regenerate the SKU of every variant of that product from the new product code. Each variant keeps its own sequence number (the number part of its current SKU), so `SPF-SH-SH-1` becomes `SPF-SH-S-1` if the segment changes from `SH` to `S`. The change MUST be refused, with a message on the segment field, if the new product code is already used by another product (the existing duplicate-code rule). The change MUST be recorded in the activity log in the same transaction.
- **FR-007**: The product form MUST NOT show a segment change as saved when the server has not saved it; the outcome of FR-006 MUST be visible to the owner (saved, or refused with a message).
- **FR-008**: The product returned for viewing and editing MUST include its saved segment.
- **FR-009**: When a saved product's edit form opens, the Segment field MUST show the saved segment, and the code preview MUST be built from the saved code, never from the product name.
- **FR-010**: Opening and saving a product's edit form without changing the segment MUST NOT change its code or any variant SKU.

### Key Entities *(include if feature involves data)*

- **Product**: code (artist, category, segment) — permanent unless FR-006 says otherwise.
- **Product variant**: SKU = product code, hyphen, sequence number. Sequence numbers are never reused within a product. A segment change regenerates the SKU from the new code and keeps the number.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: 100% of variants created after release have SKUs with no leading zeros in the sequence part.
- **SC-002**: 100% of variants created before release keep their SKU after release.
- **SC-003**: No deleted variant's sequence number is reused in any product (checked over 50 create/delete cycles).
- **SC-004**: A segment change is either saved with every variant SKU regenerated, or refused with a message; no change is silently discarded.
- **SC-005**: After a segment change, 100% of past sales and stock records still show the SKU they were recorded with.
- **SC-006**: For every product that has a saved segment, the edit form shows that segment in 100% of opens, verified on the screen and in the product response.

## Assumptions

- "Incremental number" means the next number after the highest one used by that product, starting at 1.
- SKUs are kept at the stored width (14 characters). With a 10-character product code this allows sequence numbers up to 3 digits, which matches today's limit; catalogues beyond that are out of scope.
- Existing tests and fixtures that expect `-001`-style SKUs for new variants will change; this is expected and is part of the planned work.
- On a segment change, each variant keeps its own sequence number. The number is read from the variant's current SKU, so a variant that was `…-001` becomes `…-1`.
- The Excel import/export layout is unchanged; only the value written into the SKU column for new variants changes.
- Root cause of User Story 4 (verified in code): the product response does not include `product_segment` (it carries only `code_prefix`), so the edit form reads an empty value. The fix is to return the stored segment with the product; no data change is needed. The column is required in the database, so no fallback is derived from the code.
