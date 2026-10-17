# Feature Specification: Remove the Length Limit on Product Segment

**Feature Branch**: `046-segment-length-unlimited`

**Created**: 2026-10-17

**Status**: Draft

**Input**: User description: "remove char limitation to segment"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Longer segment for a product code (Priority: P1)

The owner sets a product's segment (the letters after the artist and category in the product code) to more than 3 letters, for example `SHOP` in `SPF-SH-SHOP`, and saves the product. The product code and every variant SKU then use the longer segment. Today the form, the import and the generator refuse anything longer than 3 letters.

**Why this priority**: This is the request itself. Without it the segment cannot describe products whose short names collide or read poorly.

**Independent Test**: Create or edit a product, enter a 4-letter segment, save. The product code and a new variant SKU both show the 4-letter segment, and nothing else changes.

**Acceptance Scenarios**:

1. **Given** the product edit form, **When** the owner enters `SHOP` and saves, **Then** the product code becomes `SPF-SH-SHOP` and new variant SKUs follow it (e.g. `SPF-SH-SHOP-1`).
2. **Given** a segment longer than the current limit, **When** an existing product is changed to it, **Then** every active variant SKU is regenerated with the same number, as in feature 044 and 045 (FR-010).
3. **Given** an Excel import with a 4-letter segment in the product's code, **When** the import runs, **Then** the row is accepted instead of refused.

---

### User Story 2 - Clear refusal when the code is too long (Priority: P2)

A segment is accepted only while the full product code and the full variant SKU still fit the stored width. When a segment would make the code or SKU too long, the owner sees a message on the segment field that names the limit, and nothing is saved.

**Why this priority**: Without a ceiling the database rejects the write and the owner sees an unexplained error.

**Independent Test**: Enter a segment that makes the SKU exceed the limit and save. The message appears on the segment field and no data changes.

**Acceptance Scenarios**:

1. **Given** a segment that would make the SKU longer than the limit, **When** the owner saves, **Then** the save is refused with a message on the segment field and nothing changes.

---

### Edge Cases

- A segment with spaces, digits, a leading or trailing hyphen, or two hyphens in a row is refused.
- An empty segment still means "derive from the product name" (3 letters, as today).
- Existing 1–3 letter segments and their SKUs do not change on upgrade.
- Past sales keep the SKU text they were recorded with (FR-006 of feature 045).
- The DEMO clone and the backup/restore must accept the longer segment; a code that exists in the other data mode is still refused.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: A product segment MUST accept letters A–Z, optionally separated by single hyphens (for example `SH-K` or `S-T`), at most 6 characters in total including hyphens. It MUST NOT start or end with a hyphen and MUST NOT contain two hyphens in a row. Digits, spaces and other symbols remain refused.
- **FR-002**: The same rule MUST apply to the product form, the product segment change, the Excel product import and the DEMO clone. One definition is used everywhere.
- **FR-003** (decided: maximum 6 characters): the product code may be up to 13 characters (artist 3, hyphen, category 2, hyphen, segment up to 6 characters including any hyphen). A variant SKU may be up to 20 characters (product code, hyphen, number up to 6 digits). This replaces the earlier 14-character SKU limit for all products, so 3-letter products also get longer numbers.
- **FR-004**: A segment that would make the product code or any variant SKU exceed the limits in FR-003 MUST be refused with a message on the segment field, and nothing MUST be saved.
- **FR-005**: Changing to a longer segment MUST regenerate active variant SKUs with their numbers kept, as today (feature 045, FR-010).
- **FR-006**: Every change MUST be recorded in the activity log, as today.

### Key Entities

- **Product**: has a segment (letters) and a product code built from artist code, category code and segment.
- **Product variant**: has a SKU built from the product code and a number.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A product can be saved with a segment of up to 6 characters (letters, with optional single hyphens), and its codes and SKUs show that segment.
- **SC-002**: 100% of segments longer than 6 letters, and any SKU longer than 20 characters, are refused before any data is written.
- **SC-003**: Existing products and SKUs are unchanged after the upgrade.

## Assumptions

- Only the segment length changes. Artist code (3 letters) and category code (2 letters) keep their fixed lengths.
- The only symbol allowed in a segment is the single hyphen between letter groups. Digits and spaces are still refused.
- The stored widths are widened by a migration: product segment, product code and SKU columns, and the unique index on active SKUs that depends on the SKU column.
