# Feature Specification: Correct Images on Downloaded Invoices

**Feature Branch**: `029-fix-bulk-invoice-logo`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "fix the logo when download multiple preorder invoice" — evidence: the downloaded file `invoice-PO-20261001-0004.pdf`, where the slot at the top of the page that should hold the store's logo instead shows the payment QR (QRIS) image, the same picture that correctly appears again under "Cara pembayaran".

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Bulk-downloaded invoices show the store logo (Priority: P1)

A shopkeeper ticks several pre-orders on the Pre-orders list and downloads their invoices in one go (a ZIP with one PDF per pre-order). Each PDF must carry the store's own logo at the top, exactly as the single invoice does on screen. Today the logo slot shows the payment QR code image instead, so the invoice looks wrong and the logo is missing.

**Why this priority**: The store's identity on a document sent to customers is the visible point of the invoice; a QR code in the logo slot looks like a mistake and hides the real logo. It is the reported defect.

**Independent Test**: With a store logo uploaded and at least one QR payment channel configured, select two or more pre-orders, download their invoices, open any PDF and confirm the top image is the store logo while the QR code appears only in the payment-method section.

**Acceptance Scenarios**:

1. **Given** a store logo and a QR payment channel are configured, **When** the user downloads invoices for several selected pre-orders, **Then** every PDF in the file shows the store logo at the top and the QR code only under the payment methods.
2. **Given** the same setup, **When** the user downloads the invoice for a single selected pre-order through the bulk action, **Then** the result is identical to one PDF from a multi-selection (the number of selected orders never changes which image appears where).
3. **Given** no store logo is configured, **When** the invoices are downloaded, **Then** no image appears in the logo slot (the QR code must never take its place).
4. **Given** no QR payment channel image is configured but a logo is, **When** the invoices are downloaded, **Then** the logo shows at the top and the payment section shows no image.

---

### User Story 2 - The result does not depend on what else is on the screen (Priority: P1)

The invoice images must come out right no matter what else the page is showing when the download starts — for example the user's profile photo in the header, product thumbnails in the list, or an open detail panel. The defect appears only in some situations (it depends on which other pictures are on the page), which is why it can look "random" to the user.

**Why this priority**: A fix that works only on an empty page would let the bug reappear the moment the screen shows one more picture; reliability is the real requirement.

**Independent Test**: Download the same set of invoices twice — once from the plain list and once with a detail panel open and product thumbnails visible — and confirm both downloads are identical and correct.

**Acceptance Scenarios**:

1. **Given** the list shows product thumbnails or the user's avatar, **When** invoices are downloaded in bulk, **Then** the images in each PDF are exactly the invoice's own images, none borrowed from the page.
2. **Given** the downloads are done with a different number of other pictures on screen, **When** the files are compared, **Then** their images are the same.
3. **Given** an image on the invoice cannot be loaded (deleted file, unreachable address), **When** the invoice is downloaded, **Then** that image slot is left empty or shows the invoice's own fallback — it never shows a different picture.

---

### User Story 3 - The same guarantee for every downloadable document (Priority: P2)

The product offers the same "download as PDF/image" action on several documents: the pre-order invoice, the payment invoice, the POS sales receipt, the purchase order, and the billing invoice, and each can contain a store logo and/or QR codes. They must all place the right image in the right slot, including the existing bulk download of payment invoices and shipping slips.

**Why this priority**: The defect comes from the shared way documents are turned into files, so the same risk applies elsewhere; confirming the others closes the class of bug, but the reported case is already covered by Stories 1 and 2.

**Independent Test**: For each document type that shows a logo or QR code, download it from a screen that also contains other pictures and confirm every image is the correct one.

**Acceptance Scenarios**:

1. **Given** a single pre-order invoice or payment invoice is open, **When** the user downloads it as PDF or image, **Then** the logo and QR code are the correct ones.
2. **Given** the POS sales receipt, purchase order document, or billing invoice has an image, **When** it is downloaded, **Then** the image is the correct one.
3. **Given** the bulk download of payment invoices and of shipping slips, **When** they are downloaded, **Then** their images are correct as in Story 1.

---

### Edge Cases

- Several selected pre-orders in one download: every file must be correct, not only the first.
- A document that has no images at all: downloads exactly as before.
- A document with several QR channels: each QR code appears under its own provider name, none swapped.
- The page is opened from a different address than the one the images were saved with (another device on the same network): images still appear (existing behaviour must not regress).
- A very large selection: the download keeps working one file at a time, and images stay correct for the last file as for the first.
- Switching language or opening a panel between two downloads does not change the images in the files.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Each downloaded pre-order invoice MUST show the store logo, and only the store logo, in the logo position at the top of the document.
- **FR-002**: Each QR payment channel image MUST appear only in its own place in the payment-methods section, next to its own provider name.
- **FR-003**: When the store logo is absent, the logo position MUST stay empty; no other image may be substituted.
- **FR-004**: The images placed in a downloaded document MUST depend only on that document's own content, never on what other images happen to be on the screen at that moment.
- **FR-005**: For a multi-selection download, every file in the result MUST be correct and identical in layout and images to the same invoice downloaded on its own.
- **FR-006**: If an image cannot be fetched, the document MUST still download, with that image omitted, and MUST NOT show another image in its place.
- **FR-007**: The same correctness MUST hold for every document that offers a PDF or image download: pre-order invoice, payment invoice, shipping slip, POS sales receipt, purchase order, and billing invoice, whether downloaded individually or in bulk.
- **FR-008**: Images MUST keep appearing when the application is opened from a different address than the one used when the image was stored (the existing protection against that situation MUST NOT be weakened).
- **FR-009**: The fix MUST NOT change the visual design, text, or content of any document beyond making the images correct.

### Key Entities

- **Invoice document**: The rendered, downloadable version of a pre-order invoice or payment invoice — store identity block (logo, name, address), pre-order details, items, totals and payment methods.
- **Store logo**: The single image configured for the store, shown at the top of documents when the store has chosen to show it.
- **Payment channel QR image**: An image attached to a QR/e-wallet payment channel, shown in the payment-methods section.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In 100% of files from a multi-selection invoice download, the image at the top is the store logo (or empty when no logo is set) and never a payment QR code.
- **SC-002**: Downloading the same invoices with 0, 1 or 20 other pictures visible on the page produces files with identical images.
- **SC-003**: A bulk download of 10 invoices still completes without the user noticing a slowdown compared with before the fix (no more than 10% longer).
- **SC-004**: For each of the six document types, a download made from a screen that contains other pictures shows only that document's own images in 100% of checked cases.
- **SC-005**: No document that downloaded successfully before the fix fails to download after it (no regression in downloads, including from another network address).

## Assumptions

- The reported file shows the problem on the first page's logo position; the QR code shown there is the same image used correctly in the payment-methods section, so the defect is "wrong image in the slot", not a missing or corrupted logo upload.
- The wrong image appears depending on what else is displayed on the page when the download is started (it does not reproduce on every screen state), and the likely cause is in the shared way documents are converted into files, not in the stored logo or the invoice data — to be confirmed during planning.
- The store logo setting and the payment channel images are correct in the data; no data repair is needed.
- Bulk download of invoices, payment invoices and shipping slips all share the same conversion path, so one fix is expected to cover them.
- No change to permissions, data, or the web interface's wording is intended; this is a defect fix.
