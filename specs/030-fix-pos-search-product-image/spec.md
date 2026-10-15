# Feature Specification: Product Images in POS Search Results

**Feature Branch**: `030-fix-pos-search-product-image`

**Created**: 2026-10-04

**Status**: Draft

**Input**: User description: "fix image product when search from POS" — evidence: a screenshot of the POS screen with "slippery" typed in the search box and the seller filter set to one seller; all four result cards (MCYT — Lookout & Slippery, MCYT — Slippery (40cm), MCYT — Slippery ×2) show the grey placeholder box icon instead of a product photo.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Search results show the product photo (Priority: P1)

A cashier at the booth types a product name or SKU into the POS search box to find an item quickly. The result cards must show the same product photo the product has when the cashier browses the grid without searching. Today, when searching, the cards show only a grey placeholder icon, so the cashier cannot recognise the item visually and has to rely on the name alone — slower and more error-prone at a busy booth, where many variants look alike.

**Why this priority**: The photo is how cashiers recognise merchandise at speed; losing it exactly when they search (the moment they are hunting for a specific item) is the reported defect.

**Independent Test**: Pick a product that shows a photo in the normal POS grid. Type part of its name into the search box. Confirm the result card for that product shows the same photo, not the placeholder.

**Acceptance Scenarios**:

1. **Given** a product with a photo that shows in the normal POS grid, **When** the cashier searches for it by name, **Then** its result card shows that same photo.
2. **Given** the same product, **When** the cashier searches by SKU (full or partial), **Then** its result card shows that same photo.
3. **Given** a product variant that has its own photo and a parent product that also has one, **When** the variant appears in the results, **Then** the card shows the variant's own photo (as the normal grid does for that variant).
4. **Given** a variant with no photo of its own but a parent product with one, **When** it appears in the results, **Then** the card shows the parent product's photo.
5. **Given** a product that really has no photo anywhere, **When** it appears in the results, **Then** the placeholder icon is shown (as in the normal grid).

---

### User Story 2 - The photo follows the item into the cart and stays consistent with filters (Priority: P1)

The photo must be the same everywhere the same item appears on the POS screen: the browse grid, the search results (with or without the seller/category filters applied), and the cart after the cashier adds the item from a search result. A cashier must never see an item with a photo in one place and without it in another.

**Why this priority**: A fix that restores the photo only in one search path would still leave inconsistent screens; the cart in particular is what the cashier checks before taking payment.

**Independent Test**: Search for an item with the seller filter applied, add it to the cart from the result, and compare the photo on the result card, in the grid (after clearing the search), and in the cart line.

**Acceptance Scenarios**:

1. **Given** the seller and/or category filter is applied together with a search term, **When** results appear, **Then** every card with a product photo shows it.
2. **Given** the cashier adds an item from a search result to the cart, **When** the cart line is displayed, **Then** it shows the same photo as the result card did.
3. **Given** the cashier clears the search box, **When** the normal grid returns, **Then** the same items show the same photos as before searching.
4. **Given** the search term changes quickly (typing continues), **When** the final results appear, **Then** their photos are the ones for the final results, never a previous result's photos.

---

### Edge Cases

- A search matching a product with several variants: each variant card shows its own correct photo (variant photo, else product photo).
- A search with no matches: the empty state shows as before; no broken images.
- A photo file that has been removed or cannot be loaded: the card falls back to the placeholder like the normal grid does, without breaking the layout.
- Results that include inactive or out-of-stock items: shown exactly as before, with their photos where they have one.
- The POS opened from another device/address on the network: photos in search results appear just as they do in the normal grid.
- Cashier role vs owner/admin role: the same photos for every role (no role sees fewer).

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Each POS search result card MUST show the product photo when the item has one, using the same photo the normal (non-search) POS grid shows for that item.
- **FR-002**: The photo choice MUST follow one rule everywhere on the POS screen: the variant's own photo if it has one, otherwise its parent product's photo, otherwise the placeholder.
- **FR-003**: Search results MUST show photos regardless of how the search was made (by product name, by SKU, with or without the seller filter, with or without the category filter).
- **FR-004**: An item added to the cart from a search result MUST show the same photo in the cart as on its result card.
- **FR-005**: Items without any photo MUST continue to show the placeholder; a missing or unloadable photo MUST NOT break the card layout.
- **FR-006**: The fix MUST NOT change what items are found, their order, names, prices or stock figures; the card content changes only by the photo (FR-001) and the category name (FR-009).
- **FR-007**: Photos in search results MUST be available to every role that can use the POS, without exposing any new information (cost, margin) beyond what the POS already shows.
- **FR-009** *(added at the requester's follow-up request, 2026-10-04)*: A search result card MUST show the item's category name, with the same label the normal POS grid shows under the product name, so same-named products in different categories can be told apart; an item without a category shows no label.
- **FR-008**: Search responsiveness MUST NOT noticeably degrade: results with photos appear as fast, from the cashier's point of view, as results do today.

### Key Entities

- **Product photo**: The picture attached to a product (and optionally overridden per variant), shown on POS cards and cart lines.
- **POS result card**: One item (a product variant) shown in the POS grid or in the search results, with photo, SKU, name, seller, price and stock.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For 100% of items that show a photo in the normal POS grid, the same item shows the same photo when found through search (by name or by SKU).
- **SC-002**: Searching with the seller and category filters applied shows photos for 100% of items that have one.
- **SC-003**: An item added to the cart from a search result shows the same photo in the cart in 100% of checked cases.
- **SC-004**: The time between typing a search term and seeing the result cards (with photos) is not noticeably longer than today (no more than 10% slower).
- **SC-005**: No item that displayed correctly before the fix (with or without a photo) displays differently afterwards, other than gaining its photo in search results.

## Assumptions

- The products in the screenshot do have photos (they show in the normal grid); the defect is that the search path does not carry the photo through, not that photos are missing from the data. To be confirmed during planning by comparing what the search and the normal grid each return.
- The search results come from a different data source than the normal grid (a cashier-facing search that is deliberately lighter), which is the likely reason the photo is missing — to be confirmed during planning.
- The existing rule for choosing a photo (variant's own, else the product's) already exists for the normal grid and the cart and is the rule to follow; no new photo-selection behaviour is being introduced.
- No change to permissions, stored data, or interface wording is intended; this is a defect fix.
