# Feature Specification: Bulk Shipping Slips on A4 Cut Sheets

**Feature Branch**: `097-bulk-shipping-slips-a4-grid` (spec dir number ≠ branch number)

**Created**: 2026-11-10

**Status**: Draft

**Input**: User description: "when download bulk shipping slips, combine all slips into A4 pages with 3 column max the row with 1px solid black border for each slips, no padding or margin so that i can cut directly by just following the lines"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Print many shipping slips on few sheets and cut along the lines (Priority: P1)

A shopkeeper selects several Mail Order pre-orders on the Pre-orders list and chooses "download shipping slips". Today they get a ZIP with one file per slip. They want instead ONE document where the slips are laid out side by side on A4 pages, three per row at most, each slip enclosed by a thin black line, with the slips touching each other. They print it, then cut along the shared lines with scissors or a paper cutter, with no spacing to measure.

**Why this priority**: This is the whole request. It saves paper and cutting time when a booth ships dozens of orders after an event.

**Independent Test**: Select 7 pre-orders, download shipping slips, open the result: it is one document, the first page has 3 slips in the top row, 3 in the second row, and so on; every slip has a 1 px solid black border and no gaps between neighbours.

**Acceptance Scenarios**:

1. **Given** 7 pre-orders selected, **When** the user downloads shipping slips, **Then** they receive a single A4 document whose slips are arranged left to right, top to bottom, at most 3 per row, in the same order as the selection.
2. **Given** the printed sheet, **When** the user cuts along the black lines, **Then** each piece contains exactly one complete slip, and no slip content is cut off or lies outside its border.
3. **Given** two slips next to each other, **When** the sheet is viewed, **Then** there is no visible gap, padding or margin between their borders (they share one line, not two lines with a space).
4. **Given** one selected pre-order only, **When** downloading, **Then** the document still has one A4 page with that slip in the top-left cell.

---

### User Story 2 - Many slips continue onto extra pages (Priority: P2)

When the selection does not fit on one page, the remaining slips continue on following A4 pages with the same layout, so the user can print everything in one go.

**Why this priority**: Required for real volume, but meaningful only once the single-page layout works.

**Independent Test**: Select enough pre-orders to overfill one page (e.g. 40); the document has multiple A4 pages, no slip is split across two pages, and the total number of slips equals the number selected.

**Acceptance Scenarios**:

1. **Given** more slips than fit on one page, **When** downloading, **Then** the extra slips appear on additional A4 pages, each slip entirely on one page.
2. **Given** a last page that is only partly filled, **When** viewed, **Then** only the used cells have borders (no empty bordered boxes).

---

### Edge Cases

- A slip with a long address or long customer name: it must stay inside its own bordered cell (text wraps or is shortened); it must never overlap a neighbour or be clipped by the page edge.
- Slips have different content heights: every cell in the same row has the same height so the horizontal cut lines are straight.
- The selection mixes pick-up and Mail Order pre-orders: behaviour follows the current bulk shipping slip action (assumption below); the layout rules are unchanged.
- Printer hardware margins: the printable area of some printers is smaller than A4; the layout is sized for A4 and the user prints at 100 % / actual size (stated in the on-screen hint, see FR-010).
- Failure to build one slip (e.g. missing data): the user is told; the rest of the sheet is not silently dropped.
- Selection of 200 pre-orders (the existing bulk cap): the download still completes and the result is a valid multi-page document.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The bulk "download shipping slips" action MUST produce ONE document containing all selected slips, replacing the current ZIP of separate files.
- **FR-002**: The document MUST use A4 portrait pages.
- **FR-003**: Slips MUST be placed in a grid with at most 3 columns, filled left to right then top to bottom, in selection order.
- **FR-004**: Each slip MUST be enclosed by a 1 px solid black border.
- **FR-005**: There MUST be no padding or margin between slips and none between the slip grid and the slip border; adjacent slips MUST touch so that one cut line separates two slips. (Space INSIDE a slip, between its border and its text, is allowed so the text stays readable.)
- **FR-006**: All slips MUST have the same width (one third of the usable page width) and all slips in the same row MUST have the same height.
- **FR-007**: A slip MUST NOT be split across two pages; when the next row does not fit, it starts on a new page.
- **FR-008**: Slip content (sender, recipient, order number) MUST remain the same as the current shipping slip; only the size, border and arrangement change.
- **FR-009**: Cells that hold no slip (end of the last page) MUST NOT be drawn.
- **FR-010**: The user MUST be told, before or at download, to print at actual size (100 %, no "fit to page") so the cut lines stay straight and equal in size.
- **FR-011**: The single-slip download from the pre-order detail MUST remain unchanged.
- **FR-012**: The existing access rule for the bulk download (the current permission for exporting pre-orders) and the existing selection cap MUST be unchanged.
- **FR-013**: If a slip cannot be produced, the user MUST get a clear error; the document MUST NOT silently omit slips.

### Key Entities

- **Shipping slip**: one pre-order's sender/recipient/number block (already exists).
- **Cut sheet**: the new combined A4 document holding many slips in a bordered grid.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For any selection, the number of slips in the document equals the number of pre-orders selected, with none duplicated or missing.
- **SC-002**: At 100 % print scale, all slips on a page measure the same width and the same row height within 1 mm.
- **SC-003**: A user can cut a printed page of 3 × N slips along the lines only, with no measuring, and every piece contains one whole slip.
- **SC-004**: Printing 30 slips uses no more than 4 pages (fewer pages than the current one-slip-per-file output, which needs 30 separate prints).
- **SC-005**: Downloading 50 slips completes in under 30 seconds on the store laptop.

## Assumptions

- The combined output is a single PDF (the same document type as today's slips), not an image or ZIP.
- Slip content is unchanged from today's shipping slip; only layout changes. Fitting it in a one-third-width cell may require smaller text, and that is acceptable as long as it stays legible when printed.
- Rows are sized so the whole page is used as a regular grid; the exact number of rows per page follows from the slip height (decided at planning).
- The action keeps including every selected pre-order exactly as it does now (no new filtering to Mail Order only).
- Page margins of the sheet itself are zero in the document; the printer's own unprintable edge is the user's concern (FR-010).
- Out of scope: choosing the number of columns, custom slip sizes, cut marks beyond the borders, and changing the single-slip download.
