**Classification**: INTERNAL

# Feature Specification: Courier Shipping Labels on A4 (single and bulk)

**Feature Branch**: `085-courier-shipping-label-a4` (spec dir number ≠ branch number)

**Created**: 2026-11-15

**Status**: Draft

**Input**: User description: "in preorder [screenshot of the Pre-orders list with 18 Mail Order pre-orders selected and the bulk action bar], add single/bulk download courier shipping in A4 from this information [screenshot of the detail drawer's 'Courier shipping' section: Courier, Recipient name, Recipient phone, Address, Tracking number, status pill 'pending', buttons Mark packed / Save tracking & ship / Save shipment changes / Download shipping slip (PDF)]"

## Context (what exists today)

- **Detail drawer, single "Download shipping slip (PDF)"**: renders a small (420 px wide, not A4) slip with "Shipping Info", From (store identity) and To (shipment recipient name, phone, address). It does **not** show the courier or the tracking number.
- **List, bulk "Download shipping slips (A4 PDF)"** (feature 067/068): A4 sheet, 3-column cut grid, built from the pre-order's **customer** (not the shipment record), shows pre-order notes, no courier, no tracking number. It works even before a shipment record exists.
- The **shipment record** (the "Courier shipping" form) holds courier, recipient name, recipient phone, address, tracking number, status, notes. It exists only once staff create it, and only for Mail Order pre-orders.

This feature adds a **courier shipping label** built from the shipment record — the information in the second screenshot — downloadable as an A4 PDF for one pre-order (from the detail drawer) or for many (from the list selection).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Download one courier label on A4 from the detail drawer (Priority: P1)

A staff member has filled in the courier shipping form of a Mail Order pre-order (courier JNE, recipient Avery, phone, address, tracking number). They click the download action in the "Courier shipping" section and get an A4 PDF carrying the courier, the tracking number, the recipient (name, phone, address), the sender (store identity) and the pre-order number, ready to print and stick on the parcel.

**Why this priority**: It is the smallest slice that delivers the requested document and proves the label content and layout.

**Independent Test**: Open a Mail Order pre-order that has a shipment record with all fields filled, download the label, open the PDF: one A4 page, every field from the form is present and matches what was saved.

**Acceptance Scenarios**:

1. **Given** a pre-order with a saved shipment (courier, recipient name, phone, address, tracking number), **When** the user downloads the courier label, **Then** an A4 portrait PDF is downloaded containing courier, tracking number, recipient name, recipient phone, recipient address, sender store identity and pre-order number.
2. **Given** a shipment without a tracking number yet, **When** the user downloads the label, **Then** the PDF is still produced and the tracking area is left blank (writable by hand), never showing a placeholder value as if it were real.
3. **Given** the user edited the form but has not saved it, **When** they download, **Then** the label reflects the **saved** shipment record, and the user is told that unsaved changes are not included.
4. **Given** a pre-order with no shipment record, **When** the user views the detail, **Then** the courier label action is not offered (or is disabled with a reason).

---

### User Story 2 - Bulk download courier labels for the selected pre-orders (Priority: P2)

From the Pre-orders list the user selects many Mail Order pre-orders (e.g. the 18 in the screenshot) and chooses "Download courier labels (A4 PDF)". They get ONE PDF with one label per selected pre-order that has a shipment record, laid out on A4 pages, and a summary of which selected pre-orders were left out and why.

**Why this priority**: Saves the per-order clicking for busy event days; builds on US1's label.

**Independent Test**: Select pre-orders where some have shipment records and some do not, run the bulk action, check that the PDF holds exactly one label per pre-order with a shipment, in list order, and that the skipped ones are reported by number.

**Acceptance Scenarios**:

1. **Given** N selected pre-orders all having shipment records, **When** the user runs the bulk download, **Then** one PDF contains exactly N labels, each matching its own shipment record, in ascending pre-order number order.
2. **Given** a selection that includes Self Pickup pre-orders or Mail Order pre-orders without a shipment record, **When** the user runs the bulk download, **Then** those are skipped, the PDF contains only the others, and the user sees how many were skipped and their pre-order numbers.
3. **Given** none of the selected pre-orders has a shipment record, **When** the user runs the bulk download, **Then** no file is produced and the user is told why.
4. **Given** the selection exceeds the existing bulk limit, **When** the user runs the bulk download, **Then** the action is refused with the limit stated, as for the other bulk actions.
5. **Given** a label whose content cannot fit its allotted space (e.g. a very long address), **When** the PDF is produced, **Then** the text is never silently cut off: the label grows or the download fails with a clear message.

---

### Edge Cases

- Cancelled or handed-over pre-orders that still have a shipment record: included like any other (the label reflects the record; status is not a filter) — ASSUMPTION.
- Recipient phone/address empty on the shipment record: field printed blank; no fallback to the customer's data (the label shows the shipment record only, so it never contradicts what staff saved) — ASSUMPTION.
- User-typed text (name, address, notes) containing HTML-like characters must print literally.
- Pre-orders of another seller (seller-scoped user) or the other DEMO/LIVE mode are never included (they cannot be selected or loaded).
- A failure part-way through a bulk download produces no partial file and a clear error.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The detail drawer's "Courier shipping" section MUST offer a download of the courier label as an A4 portrait PDF when the pre-order has a saved shipment record.
- **FR-002**: The label MUST show: courier, tracking number, recipient name, recipient phone, recipient address, sender (store name, address, contact phone, contact person as already used by the existing slip), and the pre-order number. Content comes from the **saved shipment record** and store settings only.
- **FR-003**: The Pre-orders list bulk action bar MUST offer "Download courier labels (A4 PDF)" for the current selection, producing ONE PDF.
- **FR-004**: The bulk download MUST include exactly one label per selected pre-order that is a Mail Order with a shipment record, in ascending pre-order number order (planning note 2026-11-15: the selection can span pages whose order the browser does not know; see research.md D4), and MUST skip the others, reporting the count and pre-order numbers of skipped ones.
- **FR-005**: The bulk download MUST respect the existing pre-order bulk selection limit and the same visibility rules as the list (DEMO/LIVE mode, seller scope).
- **FR-006**: Layout (clarified 2026-11-15): 4 labels per A4 portrait page in a 2×2 grid (each ≈ A6), neighbours sharing one cut line, filled left-to-right then top-to-bottom; a single download (US1) is one A4 page with the label in the first cell. A label never splits across pages; a label whose content exceeds its cell fails the download with a message (FR-008).
- **FR-007**: Relationship with existing documents (clarified 2026-11-15): the courier label is a NEW, additional document. The existing single "Download shipping slip (PDF)" and the bulk "Download shipping slips (A4 PDF)" sheet stay unchanged.
- **FR-008**: Label text MUST never be clipped or truncated; overly long content either grows within the page or fails the download with a message.
- **FR-009**: All user-typed text MUST be rendered literally (no markup interpretation).
- **FR-010**: Customer contact data on the label is limited to the recipient fields the shipment record holds; no email, social handle, payment or price data is printed.
- **FR-011**: The action is available to users who can view pre-orders (same rule as the existing slip downloads); no new permission is introduced — ASSUMPTION.
- **FR-012**: The download MUST NOT change any data (no status change, no "packed"/"shipped" marking, no dispatch marker).

### Key Entities

- **Shipment record** (existing): courier, tracking number, recipient name, recipient phone, address, status, notes — one per Mail Order pre-order, created by staff. Source of the label.
- **Pre-order** (existing): number, fulfillment, selection in the list.
- **Store identity** (existing settings): sender block.
- **Courier label** (new, not stored): a printable rendering of one shipment record.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100 % of sampled labels, every printed field equals the saved shipment record and store settings.
- **SC-002**: A bulk download of 200 selected pre-orders completes in a single file within 60 seconds on the store laptop.
- **SC-003**: Printed at 100 % on A4, labels are readable and fit inside the page with no field cut off.
- **SC-004**: Staff can produce labels for all of a day's Mail Order pre-orders in one action instead of one per order.
- **SC-005**: Every selected pre-order is accounted for: labels produced + skipped reported = selected.

## Assumptions

- Only shipment records already saved are used; the feature never creates or edits a shipment.
- No barcode of the tracking number is printed (not requested); can be added later.
- No courier-specific official label formats (e.g. JNE's own airway bill) are replicated; this is the store's own label.
- Seller-scoped users and DEMO/LIVE separation behave as on the existing list.
- Language of the label follows the existing shipping-slip documents.
