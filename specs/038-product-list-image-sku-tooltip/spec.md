# Feature Specification: Products List — Larger Image with Code, and SKU Tooltip

**Feature Branch**: `038-product-list-image-sku-tooltip`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "In page product, update: relayout image and code in one column so that image can be larger; show tooltip of variant name when hover the sku." (with a screenshot of the Products & Variants list)

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A larger product picture with its code beneath it (Priority: P1)

On the Products list, each row shows the product picture and the product code together in ONE column: the picture on top, noticeably larger than today, and the code directly beneath it on a single line. The separate narrow picture column disappears, so the space it used goes to the picture itself.

**Why this priority**: the picture is how shopkeepers recognise a product at a glance, but today it is squeezed into a 56 px column of its own while the code sits in the next column; merging the two lets the picture grow without making the table wider.

**Independent Test**: open the Products list with products that have pictures and products that do not; verify the picture is clearly larger, the code is under it on one line, and the rest of the table (SKU, name, seller, category, type, status, actions) is unchanged.

**Acceptance Scenarios**:

1. **Given** the Products list, **When** it loads, **Then** each row has one combined first column containing the product picture above the product code, and no separate picture-only column exists.
2. **Given** a product with a picture, **When** its row is shown, **Then** the picture is at least 50% larger (in width and height) than the current 56 px thumbnail, keeps its aspect ratio, and clicking it still opens the enlarged view.
3. **Given** a product without a picture, **When** its row is shown, **Then** a neutral placeholder of the same larger size is shown so that rows stay aligned.
4. **Given** a normal-length product code, **When** it is shown under the picture, **Then** it stays on a single line (never wrapped); an unusually long code is shortened with the full code available on hover.
5. **Given** the combined column, **When** the list is read left to right, **Then** the column header still identifies the code (the column that used to be "Code"), the SKU column follows, and all other columns, sorting/filtering, paging and row actions behave exactly as before.
6. **Given** a tablet-width or narrower screen, **When** the list is shown, **Then** the table is no wider than before this change (merging two columns must not add horizontal scrolling).

---

### User Story 2 - See a variant's name by hovering its SKU (Priority: P1)

In the SKU column, hovering a SKU (or focusing it with the keyboard) shows a small tooltip with that variant's name, so the user can tell which variant "VLC-SK-ARE-002" is without opening anything. Clicking the SKU still opens the variant's detail as today.

**Why this priority**: SKUs are codes; the variant name (colour, size, motif) is what people actually recognise. Showing it on hover removes a click per variant when scanning a product.

**Independent Test**: on a product with several variants, hover each SKU and read the variant name; Tab to a SKU and see the same tooltip; click one and confirm the detail still opens.

**Acceptance Scenarios**:

1. **Given** a product row with several SKUs, **When** the user hovers a SKU, **Then** a tooltip appears with that variant's name, and disappears when the pointer leaves.
2. **Given** the user navigates with the keyboard, **When** a SKU receives focus, **Then** the same tooltip appears, and Escape hides it.
3. **Given** a SKU in the "+N more" expanded part of the row, **When** it is hovered or focused, **Then** it shows its variant name exactly like the first SKUs.
4. **Given** a variant with a very long name, **When** its tooltip is shown, **Then** the text wraps inside a bounded-width bubble and is not cut off by the screen edge or the table's scroll area.
5. **Given** a SKU that is clicked, **When** the tooltip is showing, **Then** the click still opens the variant's detail and the tooltip does not block it.
6. **Given** two variants with the same name on one product, **When** hovered, **Then** each shows its own name (names are not required to be unique) and the SKU itself stays visible so they can still be told apart.

---

### Edge Cases

- A product with a single variant, with no variants loaded, or with 30 variants ("+N more"): the tooltip works wherever a SKU is shown; rows with no SKUs show nothing extra.
- Very tall rows: a larger picture makes rows taller; rows stay readable and the table's other cells stay vertically centred.
- Images that are not square (portrait or landscape): shown without distortion, within the same bounding box.
- Slow or broken image: the row keeps its layout (placeholder or reserved space) rather than collapsing.
- Language switch (English/Indonesian): the column header and any new wording follow the active language; the variant name in the tooltip is data and is shown as stored.
- Touch screens: tapping a SKU keeps opening its detail (no hover is required for any function; the tooltip is a convenience).
- The tooltip must not appear for a variant without a name (the default name "Standard" is still a name and is shown).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Products list MUST show the product picture and the product code in a single combined column — picture on top, code directly beneath, centred together — and MUST NOT keep a separate picture-only column.
- **FR-002**: The picture MUST be at least 50% larger in width and height than the current 56 px thumbnail, keep its aspect ratio, and keep its click-to-enlarge behaviour; a product without a picture MUST show a placeholder of the same size.
- **FR-003**: The product code MUST remain on a single line (long codes shortened with the full code on hover, never wrapped), and the combined column's header MUST still identify the product code.
- **FR-004**: The list's total width at any screen width MUST be no greater than before this change; all other columns, ordering, filters, paging and row actions MUST be unchanged.
- **FR-005**: Hovering or keyboard-focusing a SKU in the SKU column MUST show a tooltip containing that variant's name (for the first SKUs and for those revealed by "+N more"); Escape MUST hide it; leaving hover/focus MUST hide it.
- **FR-006**: The tooltip MUST be readable (bounded width, wraps long names, not clipped by the table's scroll area or the screen edge) and MUST NOT prevent clicking the SKU, which MUST still open the variant detail exactly as today.
- **FR-007**: The tooltip MUST be reachable without a pointer (keyboard focus) and be exposed to assistive technology as a description of the SKU control; it MUST appear only for SKUs that belong to a variant (no empty tooltips).
- **FR-008**: Any new or changed wording MUST exist in both supported languages, with no missing-translation key shown on screen.

### Key Entities *(include if feature involves data)*

- **Product row**: one product in the list, with its picture, code, SKUs and other columns (existing).
- **Variant SKU**: the code of one variant shown in the SKU column; its variant name is the tooltip text (existing data, no new field).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: The product picture is at least 84 px wide and tall (≥ 50% larger than the current 56 px) in 100% of rows, with the code on one line directly beneath it.
- **SC-002**: The Products list's total content width is equal to or smaller than before the change at 1100 px and 1440 px viewport widths (measured), i.e. merging the columns adds no horizontal scrolling.
- **SC-003**: For 100% of SKUs shown (including those behind "+N more"), hovering or focusing the SKU shows its variant name within 0.5 seconds, and the name matches the variant's stored name.
- **SC-004**: Clicking a SKU opens the variant detail in 100% of cases, with or without the tooltip visible (no regression).
- **SC-005**: A user can tell which variant a SKU refers to, without opening anything, in one hover (versus one click and a dialog today).
- **SC-006**: 0 untranslated keys and 0 raw colour values are introduced; existing Products-list tests keep passing except where they assert the old two-column layout.

## Assumptions

- "Image and code in one column" means the picture stacked above the code, centred, in the first data column; the column header stays "Code" (the existing header), since the picture column had no header.
- The picture's exact size is a design decision within FR-002 / SC-001 (target around 96 px, subject to measured fit); it must remain at least 84 px.
- The tooltip shows the variant name only (as requested). SKU click behaviour (opening the variant detail) and the "+N more" expansion are unchanged.
- The tooltip reuses the project's existing accessible tooltip pattern so hover and keyboard focus behave like the "Open BOM" tooltip elsewhere.
- Out of scope: changing which columns exist other than merging picture and code, the SKU preview count, sorting, filters, the Detail/Edit/Delete actions, and the product edit drawer.
