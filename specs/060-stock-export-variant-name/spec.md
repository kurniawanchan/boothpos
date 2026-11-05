**Classification: INTERNAL**

# Feature Specification: Variant Name in the Stock Excel Export

**Feature Branch**: `085-stock-export-variant-name` (spec directory number differs from the branch number)

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "in stock page, add varian name to the export excel"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Recognise each stock row by its variant name (Priority: P1)

A shop owner or inventory staff member opens the Stock page and clicks the Excel export button. Today each row of the file shows only the SKU, the current stock and an empty reason column. To work out which item "SPF-SH-S-1" is, they must go back to the screen or the product list. They want the variant name (for example "Size S" or "Design A") next to the SKU in the file, so the sheet can be read, printed or used for a physical stock count without looking anything up.

**Why this priority**: This is the whole request. Without it the export is hard to use for stock-taking.

**Independent Test**: Export stock from the Stock page for a store with several variants and open the file. Each variant row shows its variant name beside its SKU, and the name matches what the product screen shows for that SKU.

**Acceptance Scenarios**:

1. **Given** a product with variants named "S", "M" and "L", **When** the user exports stock from the Stock page, **Then** each variant's row shows the matching variant name next to its SKU.
2. **Given** a variant whose name was edited after creation, **When** the user exports stock, **Then** the row shows the current name, not an old one.
3. **Given** a variant with a blank name, **When** the user exports stock, **Then** its row is still present with an empty name cell. The row is not dropped and the other rows do not shift.
4. **Given** a variant name containing commas, quotes or a leading "=", **When** the user exports stock, **Then** the name appears as plain text exactly as stored and is never treated as a formula.

---

### User Story 2 - Exported file still works for re-import (Priority: P2)

Owners sometimes edit the stock numbers in the exported file and upload it back through the existing master-data import to correct stock. This must keep working after the new column is added.

**Why this priority**: Protects an existing workflow. It is not new value, but breaking it would be a regression.

**Independent Test**: Export stock, change one stock number in the file, import it, and confirm the stock changes and no error is raised about the new column.

**Acceptance Scenarios**:

1. **Given** a stock export that includes the variant name column, **When** the user uploads it unchanged to the import, **Then** the import succeeds and no stock changes are recorded.
2. **Given** the same file with one stock number edited, **When** the user imports it, **Then** only that variant's stock changes.
3. **Given** the user edits the variant name cell in the file, **When** they import it, **Then** the variant name in the system does not change, because the name is for reading only.

---

### Edge Cases

- A variant whose product or variant was soft-deleted: the export keeps its current behaviour. This feature does not add or remove rows.
- Very long variant names: shown in full in the cell.
- Variant names in Indonesian or with non-Latin characters: shown correctly.
- A user without export permission on the Stock menu: still refused as before; nothing about access changes.
- The shipped import template: unchanged, because the name is an export-only helper column and not something users type in.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The stock Excel export MUST include a variant name column, placed directly next to the SKU column.
- **FR-002**: Each row MUST show the variant's current name from the system, not a copy taken when the variant was created.
- **FR-003**: The export MUST keep the same set of rows and the same order as before. Adding the column MUST NOT add, remove or reorder rows.
- **FR-004**: A variant with an empty name MUST still be exported, with an empty name cell.
- **FR-005**: Text in the name cell MUST be stored as plain text. Names starting with "=" MUST NOT be interpreted as formulas.
- **FR-006**: The existing columns (SKU, current stock, reason) MUST keep their names, meaning and values.
- **FR-007**: A file exported with the new column MUST import without error through the existing master-data import. The variant name column MUST be ignored on import and MUST NOT change any stored name.
- **FR-008**: The import template MUST NOT list the variant name column.
- **FR-009**: Who may export stock, and from which screen, MUST NOT change.
- **FR-010**: The column header MUST follow the naming style already used by other export columns.

### Key Entities

- **Stock export row**: one per product variant. Carries SKU, variant name (new, read-only), current stock and an optional reason used when re-importing.
- **Product variant**: the sellable unit with its own SKU, name, price and stock. Its name is the source of the new column.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In an exported stock file, 100% of variants that have a name show that name beside their SKU.
- **SC-002**: A user can identify any item in the exported file without opening another screen, in under 5 seconds per item.
- **SC-003**: Re-importing an unmodified stock export produces zero errors and zero stock changes.
- **SC-004**: The number of rows in the export equals what it was before this change for the same data.
- **SC-005**: No exported name cell is ever evaluated as a formula, for any stored name.

## Assumptions

- "Stock page" means the existing Stock screen's Excel export button, which produces the stock sheet with one row per variant. The on-screen movement list is unchanged.
- "Variant name" means the variant's own name only. The product name, seller and category columns are not requested and are out of scope. They could be proposed as a follow-up.
- The new column is export-only, like the earlier product and pre-order export additions. Import ignores it and the template does not list it.
- The column header is `variant_name`, matching the existing product sheet's header for the same field.
- Existing permissions (the `export` action on the Stock menu, and the master-data gate) are reused as they are.
- The change applies equally in DEMO and LIVE mode, following the same data-mode filtering the export already uses.
- Scope is Excel only. No screen, API response or report other than this export changes.
