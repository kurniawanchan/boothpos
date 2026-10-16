**Classification: INTERNAL**

# Feature Specification: Product Segment Length (1–3) and SKU Format

**Feature Branch**: `050-product-segment-1-3`

**Created**: 2026-10-16

**Status**: Draft

**Input**: User description: "in product, update: segment can be 1 char with max 3 char; if I use "S" as segment, the sku will be like this format "SPF-SH-S-001"" (with a screenshot of the product form showing segment "S" previewed as "SPF-SH-SXX-001")

## Current behaviour (from code, for reference)

- The product form's **Segment** field must be exactly 3 letters. The preview pads a shorter entry with `X` (typing `S` previews `SXX`), and the server rejects anything that is not 3 letters.
- The product code is `artist code (3) - category code (2) - segment (3)`, which is always 10 characters. Each variant SKU is that code plus `-` and a 3-digit sequence, for example `SPF-SH-SXX-001`.
- When no segment is typed, the server derives 3 letters from the product name, padded with `X`.
- Excel import of products applies the same rule: the segment must be exactly 3 letters.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Enter a short segment (Priority: P1)

The owner creates a product whose segment is one to three letters, for example `S`, `SH` or `SPF`. What they type is what the product uses.

**Why this priority**: The owner's shorter naming scheme cannot be used at all while every segment must be three letters.

**Independent Test**: Create a product in category `SH` for artist `SPF` with segment `S`. The product is saved with segment `S`, and the preview and saved code show `SPF-SH-S`.

**Acceptance Scenarios**:

1. **Given** the Segment field is empty, **When** the owner types `S`, **Then** the preview shows `SPF-SH-S-001` (no `X` padding) and the saved product keeps segment `S`.
2. **Given** the owner types `SH`, **When** the product is saved, **Then** its code is `SPF-SH-SH` and the first variant's SKU is `SPF-SH-SH-001`.
3. **Given** the owner types `spf` in lowercase, **When** the product is saved, **Then** the segment is stored as `SPF`.
4. **Given** the owner types four letters, **When** they try to save, **Then** the form shows a clear message that the segment is at most three letters and the product is not saved.
5. **Given** the owner types a digit or symbol, **When** they try to save, **Then** the form shows the existing letters-only message and the product is not saved.

---

### User Story 2 - The SKU follows the segment exactly (Priority: P1)

Each variant's SKU is the product code, a hyphen, and a 3-digit sequence. The product code is the artist code, category code and segment joined by hyphens, with the segment as typed.

**Why this priority**: The SKU is what staff read on stock sheets and receipts; it must match what the owner typed.

**Independent Test**: Create a product with segment `S`, add two variants, and check the SKUs are `SPF-SH-S-001` and `SPF-SH-S-002`.

**Acceptance Scenarios**:

1. **Given** segment `S`, **When** the first variant is created, **Then** its SKU is `SPF-SH-S-001`.
2. **Given** that product already has one variant, **When** a second variant is added, **Then** its SKU is `SPF-SH-S-002`.
3. **Given** segment `SPF` for artist `SPF` and category `SH`, **When** the first variant is created, **Then** its SKU is `SPF-SH-SPF-001` (the segment is not shortened or padded).

---

### User Story 3 - Blank segment still derives from the name (Priority: P2)

When the owner leaves the segment blank, the system still derives a segment from the product name, as today. The derived segment is always three letters, padded with `X` when the name is short.

**Why this priority**: Existing habits keep working; this only adds shorter manual segments.

**Independent Test**: Create a product named "Conan" with the segment left blank. The segment is `CON`, and the SKU is `SPF-SH-CON-001`.

**Acceptance Scenarios**:

1. **Given** the segment is blank and the name is "Conan", **When** the product is saved, **Then** the segment is `CON`.
2. **Given** the segment is blank and the name is "Ai", **When** the product is saved, **Then** the segment is `AIX` (padded to three letters, as today).

---

### User Story 4 - Excel import uses the same rule (Priority: P2)

A product row in the Excel import may give a segment of one to three letters, with the same rules as the form. Blank still derives from the name.

**Why this priority**: The import would otherwise reject rows the form accepts, and the two paths would disagree.

**Independent Test**: Import a row with segment `S`; it is created with segment `S` and SKU `…-S-001`. A row with a four-letter segment is reported as a row error and the whole import is rolled back, as today.

**Acceptance Scenarios**:

1. **Given** an import row with segment `S`, **When** the import is applied, **Then** the product is created with segment `S`.
2. **Given** an import row with a four-letter segment, **When** the import is validated, **Then** the row is reported with the message that the segment is at most three letters.

---

### Edge Cases

- A segment that would make the same product code as an existing product (for example `S` where `S` is already used for the same artist and category): the save is refused with the existing duplicate-code message, and the owner is asked to change the segment. No automatic change is made.
- An existing product made before this change keeps its code and SKUs unchanged, even when it has a 3-letter segment. Codes are permanent once created.
- Segments `S` and `SX` for the same artist and category produce different codes (`SPF-SH-S` and `SPF-SH-SX`), so they do not collide.
- The database stores fixed-width text columns. Shorter codes are stored without losing their characters; reads return the value as typed.
- Whitespace around the segment is removed before the rule is applied.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The product segment MUST accept 1 to 3 letters (A–Z).
- **FR-002**: A segment longer than 3 characters, or containing a non-letter, MUST be refused with a message on the field, and the product MUST NOT be saved.
- **FR-003**: A segment entered by the owner MUST be stored in uppercase and MUST NOT be padded to 3 characters.
- **FR-004**: The product code MUST be artist code, category code and segment joined by hyphens, using the segment as stored (8 to 10 characters in total).
- **FR-005**: Each variant SKU MUST be the product code, a hyphen, and a 3-digit sequence starting at `001`.
- **FR-006**: The Segment preview on the product form MUST show exactly the code that will be saved; it MUST NOT pad a typed segment with `X`.
- **FR-007**: When the segment is blank, the system MUST derive a 3-letter segment from the product name, padded with `X` when the name has fewer than 3 letters. This behaviour is unchanged.
- **FR-008**: Excel import of product rows MUST accept a segment of 1 to 3 letters under the same rules as FR-001 to FR-003, and MUST report the same row errors as the form.
- **FR-009**: Products and variants created before this change MUST keep their existing codes and SKUs.
- **FR-010**: Uniqueness of the product code MUST continue to be checked across both DEMO and LIVE data, as today.
- **FR-011**: The segment remains set only at creation; editing an existing product's segment is out of scope, as today.

### Key Entities *(include if feature involves data)*

- **Product**: has a segment of 1–3 letters (set at creation) and a product code built from the artist code, category code and segment.
- **Product variant**: has a SKU built from the product code and a 3-digit sequence.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: Owners can create products with segments of 1, 2 and 3 letters, and every such product saves with the segment as typed, verified for each length.
- **SC-002**: For `S` with artist `SPF` and category `SH`, the first variant's SKU is `SPF-SH-S-001`, shown identically in the preview, the product list, and the Excel export.
- **SC-003**: Entries of 4 or more letters, or with non-letters, are refused 100% of the time with a message on the segment field, on both the form and the import.
- **SC-004**: Blank segments still produce the same 3-letter segment as before for the same product name, verified against existing examples.
- **SC-005**: No existing product's code or SKU changes after this feature is released.

## Assumptions

- "Char" means letters (A–Z), as today. Digits and symbols stay refused. ASSUMPTION: product owner expects letters only; confirm if digits are wanted.
- Derived segments (blank input) stay exactly 3 letters with `X` padding, because the owner's example only covers typed segments.
- The product code's artist code (3) and category code (2) keep their current fixed lengths, so a product code is 8 to 10 characters.
- No database migration is needed: the code and SKU columns are fixed-width text columns already wide enough for the longest value (10 and 14 characters), and shorter values are stored without change.
- The duplicate-code check and its message stay as they are; no automatic re-segmenting happens.
- Products with a segment from before this change are unaffected; only new products can use 1–2 letter segments.
