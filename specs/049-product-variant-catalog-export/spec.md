# Feature Specification: Product Variant Catalog Export (PDF and Excel)

**Feature Branch**: `080-product-variant-catalog-export`

**Created**: 2026-10-28

**Status**: Draft

**Input**: User description: "from product page, add button to generate and export product variant catalog grouped by sellers to excel and pdf. in the catalog, provide product information such as seller, category, product name, product variant name, code, sell price, variant image, generate product barcode (following the most supported barcode type for any barcode scanner), grouped by seller. this catalog function is as a reference for cashier to quickly find product then scan barcode or type the variant information to the POS also for customer to browse more easily in the offline event. also add the store information, for the PDF, make it colourfull, simple and attractive"

## Clarifications

### Session 2026-10-28

- Q: What should scanning a catalog barcode do on the POS? → A: It adds the variant to the cart automatically when the scanned code matches exactly one variant (option B); this makes a small POS change part of this feature.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Download a printable PDF catalog grouped by seller (Priority: P1)

Before an event, the shop owner opens the Products page and clicks a catalog button to download a PDF. The PDF opens with the store's identity (name, logo, address and contact details), then lists every seller in its own section. Under each seller, every variant appears with its picture, category, product name, variant name, code, selling price and a barcode. The design is colourful, simple and attractive so it can be printed and handed to cashiers as a quick reference or shown to customers browsing at an offline venue.

**Why this priority**: The PDF is the artifact people actually carry to the booth. Without it the feature delivers nothing at the event.

**Independent Test**: With products from at least two sellers, click the catalog button, choose PDF, and confirm the file contains store information, one clearly separated section per seller, and for every active variant its picture (or placeholder), category, product name, variant name, code, price and a barcode.

**Acceptance Scenarios**:

1. **Given** products from several sellers exist, **When** the user exports the PDF, **Then** the document starts with the store name, logo, address and contact details and then shows one section per seller, each headed by the seller name.
2. **Given** a seller has several categories and products, **When** the PDF is generated, **Then** every active variant of that seller appears exactly once under that seller with category, product name, variant name, code, sell price, picture and barcode.
3. **Given** a variant has no picture, **When** it is rendered, **Then** a neutral placeholder of the same size is shown instead of a broken or empty image.
4. **Given** the catalog spans many pages, **When** it is printed, **Then** a seller's section never loses its seller name (the seller is identifiable on every page), and no variant entry is cut in half across pages.
5. **Given** the user views the PDF on screen or on paper, **Then** colours, headings and spacing make sellers easy to tell apart and prices easy to read, without clutter.

---

### User Story 2 - Download an Excel catalog grouped by seller (Priority: P1)

The user can also download the same catalog as an Excel workbook, for editing, filtering, sharing digitally, or searching quickly on a laptop. It carries the same information: seller, category, product name, variant name, code, sell price, variant picture and barcode, grouped by seller, with the store information at the top.

**Why this priority**: Excel is the editable, searchable companion to the PDF and was requested together with it.

**Independent Test**: Export Excel, open it, and confirm the store information block, rows grouped by seller with a visible seller heading for each group, and every column of data including a picture and a barcode per variant.

**Acceptance Scenarios**:

1. **Given** products from several sellers exist, **When** the user exports Excel, **Then** the workbook lists variants grouped by seller with a clear seller heading per group and the store information at the top.
2. **Given** the workbook is open, **When** the user searches for a variant name or code with the spreadsheet's normal find function, **Then** the matching row is found (code and names are real text, not only images).
3. **Given** a variant has a picture, **When** the workbook is opened, **Then** the picture appears in the row for that variant; a variant without a picture shows an empty or placeholder cell without breaking the layout.

---

### User Story 3 - Barcodes the cashier's scanner can actually read (Priority: P1)

Each variant's barcode encodes the variant's code, using a barcode type that essentially every handheld or counter barcode scanner reads. The code is also printed in readable text beside or beneath the barcode, so a cashier can type it into the POS if scanning is not possible.

**Why this priority**: The barcode is the reason the catalog is a cashier tool; a barcode that does not scan makes it decorative.

**Independent Test**: Print the PDF at normal size and scan several variants' barcodes with a standard handheld scanner (and a phone scanner app); the decoded value must equal the variant's code exactly, and that code must find the variant when typed or scanned into the POS search.

**Acceptance Scenarios**:

1. **Given** a variant with a code containing letters, digits and hyphens, **When** its barcode is scanned, **Then** the decoded text equals the code exactly, with no missing or altered characters.
2. **Given** the PDF is printed on A4 at 100% scale, **When** any barcode is scanned from the paper, **Then** it reads on the first or second attempt at ordinary scanning distance.
3. **Given** the scanned or typed code, **When** it is entered in the POS product search, **Then** that exact variant is found.
4. **Given** a barcode cannot be scanned for any reason, **Then** the printed code beside it can be typed manually to the same effect.

---

### User Story 4 - Choose what goes into the catalog (Priority: P2)

The export respects what the user is already looking at on the Products page: if seller, category or search filters are applied, the catalog contains only those products; with no filters it contains everything. If the filter matches nothing, the user is told there is nothing to export instead of receiving an empty file.

**Why this priority**: Useful for per-seller or per-category handouts, but the all-products catalog already delivers the core value.

**Independent Test**: Filter the Products page to one seller, export, and confirm only that seller appears; clear filters, export, and confirm all sellers appear; filter to a seller with no active variants and confirm a clear "nothing to export" message.

**Acceptance Scenarios**:

1. **Given** the Products page is filtered to one seller, **When** the user exports, **Then** only that seller's variants appear.
2. **Given** no filters, **When** the user exports, **Then** all sellers with at least one active variant appear.
3. **Given** the current filters match no active variant, **When** the user tries to export, **Then** a clear message says there is nothing to export and no file is produced.

---

### User Story 5 - Scan a catalog barcode on the POS to add the item (Priority: P1)

At the counter, the cashier finds the item in the printed catalog, scans its barcode with the scanner, and the item is added to the cart immediately, without typing or tapping. Typing the code by hand in the POS search box and pressing Enter does the same.

**Why this priority**: This is the payoff of the barcode; without it the barcode only fills a search box and the catalog saves little time at the counter.

**Independent Test**: With an open cashier session, scan (or type and press Enter) a variant's code in the POS search box and confirm exactly one unit of that variant is added to the cart and the search box is cleared, ready for the next scan.

**Acceptance Scenarios**:

1. **Given** an open cashier session and a variant with code X, **When** the cashier scans X (or types X and presses Enter) in the POS search, **Then** that variant is added to the cart with quantity 1 and the search box is cleared and stays focused for the next scan.
2. **Given** the variant is already in the cart, **When** its code is scanned again, **Then** its quantity increases by 1 (the same result as tapping the item again).
3. **Given** the scanned code matches no variant, **When** Enter is received, **Then** nothing is added and a clear "code not found" message is shown.
4. **Given** the text typed is only part of a code or name that matches several variants, **When** Enter is pressed, **Then** nothing is added automatically and the matching variants are listed for the cashier to choose, exactly as search works today.
5. **Given** the variant is inactive, or cannot be sold under the POS's existing stock rules, **When** its code is scanned, **Then** the same rule and message apply as when tapping that item, and nothing is added silently.
6. **Given** a scanner sends the same code twice by accident within a very short time, **Then** the cashier can see the cart quantity and correct it as with any other line (the POS does not hide or merge unseen duplicates).
7. **Given** there is no open cashier session, **When** a code is scanned, **Then** the item is added to the cart exactly as tapping it would be (the cart may be built before a session opens); the existing "open a session first" banner stays visible and payment remains blocked until a session is open.

---

### Edge Cases

- Variants that are inactive, or products that are inactive or deleted, are not listed; a seller left with no active variant does not appear.
- A very long product or variant name must remain readable (wrapped or shortened with the full name still discoverable) and must not overlap other content or break the layout.
- Names with non-Latin characters (for example Japanese seller or product names common in anime merchandise) and symbols must render correctly in both formats, never as empty boxes.
- The store logo is missing or cannot be loaded: the catalog still generates with the store name and details only.
- Store address, phone or email is not set: those lines are simply left out, with no empty labels.
- A catalog with several hundred variants must still generate in reasonable time and remain readable and printable.
- Codes can change when a product's segment is changed later; the catalog always reflects the codes at the moment it is generated and shows the generation date and time so stale printed copies can be recognised.
- DEMO and LIVE data never mix: the catalog uses only the active mode's products and store name.
- Prices are shown exactly as the POS charges them (selling price); no cost or margin information ever appears.
- A user without access to the Products page cannot see or use the export button.
- Scanning when the search box is not focused: the POS keeps the search box ready (focused) so a scan is not lost; scans never type into other fields such as customer or payment inputs.
- Scanner input arrives as very fast typing followed by Enter; a human typing slowly and pressing Enter must also work, and normal search typing without Enter must not add anything by itself.
- Two variants whose codes are identical except for case or surrounding spaces: the match is on the exact code (ignoring only leading/trailing spaces and letter case), and only a single exact match is added automatically.

## Requirements *(mandatory)*

### Functional Requirements

**Entry point and output**

- **FR-001**: The Products page MUST offer a catalog action that lets the user generate and download the variant catalog as a PDF or as an Excel workbook.
- **FR-002**: The action MUST be visible only to users who have access to the Products page, and the server MUST refuse the export to anyone else.
- **FR-003**: If nothing qualifies for export, the user MUST be told so clearly and no empty file produced.

**Content (both formats)**

- **FR-004**: The catalog MUST be grouped by seller, one group per seller with the seller name as the group heading, sellers in alphabetical order.
- **FR-005**: Within a seller, each active variant MUST be listed with: seller, category, product name, variant name, code, selling price, variant picture and barcode.
- **FR-006**: Within a seller, variants MUST be ordered predictably (by category, then product name, then variant name) so a person can scan the list quickly.
- **FR-007**: The selling price MUST be shown in the app's standard Rupiah format and MUST match the price the POS charges; no cost price or margin information may appear anywhere in the catalog.
- **FR-008**: The variant picture MUST use the variant's own picture, falling back to the product's picture, then to a placeholder.
- **FR-009**: The catalog MUST include the store information: store name, logo, address, and contact details (as configured in store settings), and the date and time it was generated.
- **FR-010**: The catalog MUST contain only data of the currently active mode (DEMO or LIVE).

**Barcode**

- **FR-011**: Each variant MUST have a barcode that encodes its exact code, using a barcode type read by virtually all barcode scanners.
- **FR-012**: The variant's code MUST also be printed as readable text with the barcode.
- **FR-013**: The code encoded in the barcode MUST be the same value the POS search resolves to that variant.
- **FR-014**: On the POS screen, entering a code in the search box and confirming with Enter (which a scanner sends automatically) MUST add the variant to the cart with quantity 1 when the code exactly matches exactly one variant, applying the same rules as tapping that item (active, stock, open session); a repeat adds one more unit.

**POS scan behaviour**

- **FR-024**: After an automatic add, the search box MUST be cleared and remain focused so the next scan can follow immediately.
- **FR-025**: If the code matches no variant, nothing MUST be added and a clear "code not found" message MUST be shown; if the text matches several variants, nothing MUST be added automatically and the matches MUST be listed as today.
- **FR-026**: Automatic add MUST NOT change prices, totals or stock logic: the server remains the source of truth for amounts, and a scan adds the same cart line as tapping the item.
- **FR-027**: Scanning MUST work with ordinary hardware barcode scanners that act as a keyboard; camera-based scanning is not part of this feature.

**PDF presentation**

- **FR-015**: The PDF MUST be colourful, simple and attractive: a branded cover/header with the store information, a distinct colour accent per seller section, clear typographic hierarchy, generous spacing, and prices that stand out.
- **FR-016**: The PDF MUST be suitable for printing on standard office paper and for viewing on screen; it MUST NOT depend on colour alone to tell sellers apart (each section is also labelled with the seller name).
- **FR-017**: A seller's name MUST be visible on every page that contains that seller's variants, and a variant entry MUST NOT be split across pages.

**Excel presentation**

- **FR-018**: The workbook MUST carry the store information at the top and group rows by seller with a visible heading per group.
- **FR-019**: Codes, names, categories and prices MUST be real searchable values (not only images) so the spreadsheet's normal find and filter work.

**Scope of the export**

- **FR-020**: The export MUST apply the Products page's current seller, category and search filters; with no filters it MUST include every seller that has at least one active variant.
- **FR-021**: Inactive variants, inactive or deleted products, and sellers with no remaining active variant MUST be excluded.

**Quality**

- **FR-022**: All labels, headings and messages MUST exist in both supported interface languages and follow the exporting user's current language.
- **FR-023**: Text in non-Latin scripts MUST render correctly in both formats.

### Key Entities

- **Catalog**: a generated, point-in-time document listing active variants grouped by seller, with store information and a generation timestamp; not stored in the system.
- **Seller (artist)**: the owner of products; the grouping key and section heading.
- **Product variant entry**: seller, category, product name, variant name, code, selling price, picture and barcode.
- **Barcode**: a machine-readable representation of a variant's code that scanners read; accompanied by the readable code.
- **Store information**: the store's name, logo, address and contact details from store settings (name follows the DEMO/LIVE mode).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A user can produce a complete catalog (PDF or Excel) from the Products page in no more than 3 clicks and under 1 minute for a catalog of up to 500 variants.
- **SC-002**: 100% of active variants within the chosen filters appear exactly once, under the correct seller, with all required fields; 0 inactive or deleted variants appear.
- **SC-003**: At least 95% of barcodes scanned from a standard A4 printout at 100% scale with a typical handheld scanner read correctly on the first or second attempt, and 100% of successful reads decode to exactly the variant's code.
- **SC-004**: A cashier using the printed catalog can locate a requested variant and have its code ready for the POS in under 15 seconds on average.
- **SC-005**: No cost price, margin or other internal-only figure appears in any catalog, in either format (0 occurrences).
- **SC-006**: In a review with first-time viewers, at least 80% describe the PDF as "attractive" and "easy to read" and can name the seller of any entry at a glance.
- **SC-007**: Names containing non-Latin characters display correctly in 100% of sampled entries in both formats.
- **SC-008**: Scanning a catalog barcode on the POS puts the correct item in the cart in under 2 seconds with no typing or tapping, for 100% of exact-match codes of active, sellable variants.
- **SC-009**: In a test of 100 scans mixing valid, unknown and partial codes, 0 wrong items are added to the cart.

## Assumptions

- The barcode type is Code 128 encoding the variant's code (SKU). Code 128 reads on virtually every scanner and encodes letters, digits and hyphens; numeric-only retail types (EAN/UPC) cannot encode these codes.
- The variant's code in the catalog is its SKU as shown elsewhere in the app; the POS search already resolves SKUs (spec FR-013 relies on this).
- Scan-to-add (User Story 5) is a deliberate small extension of the POS made at the product owner's request; it reuses the POS's existing add-to-cart rules and does not introduce new pricing or stock behaviour.
- Both PDF and Excel carry the same information; the PDF is the designed, printable one, while Excel is a practical working list (a single sheet grouped by seller with heading rows, store information at the top).
- The PDF starts each seller on a new section and uses one consistent colourful but simple theme with a different accent per seller; exact palette and layout are a design decision for planning.
- Stock levels, stock status and preorder status are not shown (the catalog is an offline reference whose stock figures would go stale); this can be revisited if requested.
- Users who can open the Products page can use the export; no separate permission is introduced. The export contains only data already visible to those users.
- The catalog language follows the exporting user's interface language; product, seller and variant names appear as stored.
- PRD §7.18 (printable product catalog) and F6.5/F17.5 (barcode support) existed as requirements but were cut from the MVP scope in the project notes; this feature revives them (catalog, barcode generation, and scanning by external keyboard-style scanners) at the product owner's explicit request, so a dated scope note must be added to the PRD rather than rewriting earlier scope decisions. Camera scanning from F17.5 stays out of scope.
- Layout variants (compact vs large picture, PRD F18.5) and per-event filtering (F18.3) are out of scope for this iteration.
