# Feature Specification: Variant Drawer and BOM Dialog Refinements

**Feature Branch**: `037-variant-drawer-bom-ui`

**Created**: 2026-10-05

**Status**: Draft

**Input**: User description: "UI adjustment of product variants and bom popup. The separation of each variant is not too obvious, make it more clear but clean. Change to different colour for sku, markup, margin. Make it wider, move stock field after variant name field. Move apply markup button to the left side of the delete icon. Relayout BOM information: 'BOM cost: Rp 397 Open BOM' — move Open BOM to the left side of apply markup (after relocation), change it to a button, add a tooltip explaining what BOM is. Move BOM cost: Rp 397 to the right side of margin, same style, different colour. Make thumbnail image 50% larger. Add function to duplicate the product variant with same variant and BOM info. Fix the UI of BOM copy from another variant, right now it cannot be scrolled to the bottom. Move add BOM item align with save changes button. Add function to copy to chosen variants. Show image in the variant list of copy from & copy to." (with a screenshot of the Edit product drawer)

## Clarifications

### Session 2026-10-05

- Q: When a variant is duplicated, what stock should the copy start with? → A: The copy starts with the source variant's stock (not 0). Saving therefore adds that quantity to total inventory; the screen must make this visible before saving.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A variant card that is easy to scan and act on (Priority: P1)

A shopkeeper opens **Edit product** and sees one clearly bounded card per variant. Each card has a header line with colour-coded chips — SKU, markup, margin and BOM cost, each in its own colour — and, on the right of that same line, the three actions that belong to the variant: **Open BOM** (a button with an explanatory tooltip), **Apply markup**, and the delete icon. Below the header the fields run in a logical order — variant name, then stock, then cost price and sell price — across a wider panel, and the variant's picture is shown larger.

**Why this priority**: this is the screen used to price and stock every product. Today the cards blur together, the three chips look identical, the actions are scattered (BOM link on its own line, Apply markup beside the stock field), and the BOM cost is a plain line of text that is easy to miss.

**Independent Test**: open Edit product on a product with several variants; verify the card separation, the four distinct chip colours, the action order in the header (Open BOM · Apply markup · delete), the field order (name → stock → cost → sell), the wider panel, and the larger thumbnail — without using any other feature.

**Acceptance Scenarios**:

1. **Given** a product with several variants, **When** the drawer opens, **Then** each variant sits in its own visibly bounded card with clear, consistent spacing between cards, and the whole list still looks clean (no heavy borders or colour blocks).
2. **Given** a variant card, **When** its header is read, **Then** SKU, markup, margin and BOM cost appear as four chips in four different colours, in that order, and a negative markup/margin is still shown in the existing warning style.
3. **Given** a variant that has a BOM, **When** the header is read, **Then** the BOM cost chip ("BOM cost: Rp 397") sits to the right of the margin chip with the same chip style as markup/margin but its own colour; a variant without a BOM shows no BOM cost chip.
4. **Given** a saved variant, **When** the right side of the header is read left to right, **Then** it shows **Open BOM** (a button), **Apply markup**, then the delete icon.
5. **Given** the user hovers or keyboard-focuses **Open BOM**, **When** the tooltip appears, **Then** it explains in plain words what a BOM is (the list of materials and services, with their cost, that go into making one finished product) and that opening it lets the user review or change it.
6. **Given** the drawer, **When** it opens on a normal desktop screen, **Then** it is noticeably wider than before and the fields are ordered variant name, stock, cost price, sell price.
7. **Given** a variant with a picture, **When** its card is shown, **Then** the thumbnail is 50% larger than before and still opens the enlarged view when clicked.
8. **Given** a brand-new (unsaved) variant, **When** its card is shown, **Then** the Open BOM button and BOM cost chip are not offered (there is no BOM yet), while Apply markup and delete still are.

---

### User Story 2 - Duplicate a variant together with its BOM (Priority: P1)

From a variant's card the user can **duplicate** it. A new card appears right below it, pre-filled with the same name (marked as a copy so it can be edited), cost price, sell price, low-stock alert, status and stock, and — when the source variant has a BOM — flagged to receive a copy of that BOM when the product is saved. Nothing is stored until the user saves the product, and the new variant receives its own server-generated SKU.

**Why this priority**: products often come in many near-identical variants (colours, sizes). Today every variant must be typed from scratch and its BOM copied in a separate step; duplicating removes most of that work.

**Independent Test**: duplicate a variant that has a BOM, edit its name, save the product, then confirm the new variant has its own SKU, the same prices and stock, and an independent copy of the BOM.

**Acceptance Scenarios**:

1. **Given** a saved variant with a BOM, **When** the user duplicates it, **Then** a new unsaved card appears directly below it with the source's name (marked as a copy), cost price, sell price, low-stock alert, status and stock, and a visible note that the BOM will be copied.
2. **Given** the duplicate card, **When** the user changes any field and saves the product, **Then** the new variant is created with those values, its own new SKU, and a copy of the source BOM that is independent afterwards (changing either BOM never affects the other).
3. **Given** the source stock is 6, **When** the copy is saved with stock 6, **Then** total inventory increases by 6 and the new variant's history shows its opening stock; before saving, the card states that copied stock counts as new inventory.
4. **Given** the user discards the drawer (Cancel) before saving, **When** the product reloads, **Then** no duplicate exists.
5. **Given** a source variant whose BOM is marked complete, **When** it is duplicated and saved, **Then** the copy receives the BOM rows but is not marked complete, and its cost price keeps the duplicated value until the BOM is completed.
6. **Given** a user who may not manage BOMs (lacks the purchase-order permission), **When** they duplicate a variant, **Then** the fields are copied but no BOM is copied and the card does not claim one will be.
7. **Given** an unsaved source card (no BOM or SKU yet), **When** the user duplicates it, **Then** its fields are copied and no BOM copy is offered.
8. **Given** a source variant that was switched off (inactive), **When** it is duplicated, **Then** the user can still do so; the copy starts active.

---

### User Story 3 - A BOM copy tool that works for many variants (Priority: P1)

In the BOM dialog, copying a BOM is reliable and visual: the variant list is fully reachable by scrolling no matter where the dialog sits on screen, each variant in the lists shows its picture, and in addition to "copy to all" and "copy to next" the user can **copy to chosen variants** by ticking exactly the variants they want. The **Add BOM item** button sits on the same line as **Save changes**.

**Why this priority**: with many variants the current picker runs off the bottom of the screen and cannot be scrolled to the last entries, and there is no way to copy to just some variants.

**Independent Test**: in a BOM dialog for a variant of a product with 8+ variants, open the copy tool near the bottom of the dialog, scroll to the very last variant, tick three variants (with pictures visible) and copy; confirm only those three received the BOM.

**Acceptance Scenarios**:

1. **Given** the copy tool opened near the bottom of the dialog or screen, **When** the user opens "Copy from another variant", **Then** the entire list can be reached — including the last entry — by scrolling or by keyboard, and it is never cut off by the screen edge.
2. **Given** the "copy from" and "copy to" lists, **When** they are shown, **Then** every variant row shows its picture (or a neutral placeholder if it has none) beside its SKU and name.
3. **Given** the copy tool, **When** the user chooses "Copy to chosen variants", **Then** they see a list of the other variants of the same product with pictures and checkboxes, can search it, can tick any number of them, sees how many are selected, and can copy only when at least one is ticked.
4. **Given** some ticked variants already have BOM rows, **When** the user confirms, **Then** they are told which ones would be replaced and nothing is replaced without that confirmation; variants without rows are filled directly.
5. **Given** the copy to chosen variants succeeds, **When** the dialog refreshes, **Then** the user sees a result (how many variants were copied) and no unticked variant was touched.
6. **Given** the BOM dialog, **When** it is open for an editor, **Then** **Add BOM item** is on the same row as **Save changes** (add on the left, save on the right), and the row keeps working at narrow widths.
7. **Given** unsaved quantity edits, **When** the user starts a copy (any of the four ways), **Then** the existing "discard unsaved changes?" confirmation still applies.

---

### Edge Cases

- A product with 30 variants: the drawer, the chip row, the copy lists and the chosen-variants list all stay usable and scroll; no layout breakage.
- A variant with a very long name or SKU: chips and fields wrap or truncate with the full text on hover; the header actions never overlap the chips.
- No picture: a neutral placeholder of the same (larger) size, so cards stay aligned.
- Narrow screens: the header actions may wrap onto a second line but keep their order; the fields collapse to fewer columns instead of overflowing.
- Colour must not be the only carrier of meaning: each chip keeps its text label (SKU, markup, margin, BOM cost), and text/background contrast stays readable.
- Duplicating many times in a row: each copy is a separate card with its own editable name; copies of copies work; the product cannot be saved while a copy still has an empty name (existing validation).
- Name collision: if the product already has a variant with the duplicate's name when saved, the existing duplicate-name behaviour applies and the user can rename.
- Duplicate while another variant's BOM dialog has unsaved changes: the BOM dialog is a separate layer; duplicating is only possible from the drawer, so the drawer's own guard rules apply.
- "Copy to chosen variants" where the source has no BOM rows: the option explains there is nothing to copy (existing behaviour for the other copy modes).
- The target is the source itself or a variant of another product: never offered, never accepted.
- Variants switched off (inactive) appear in the chosen-variants list marked as inactive and can be selected.
- Stock copy: a user who duplicates only to reuse the prices can set the copy's stock to 0 before saving; the copied value is an editable default, not a lock.

## Requirements *(mandatory)*

### Functional Requirements

**Variant card (Edit product)**

- **FR-001**: Each variant card MUST be clearly separated from its neighbours with consistent spacing and a visible but light boundary (clean, not heavy); cards remain distinguishable when many variants are shown.
- **FR-002**: The card header MUST show four labelled chips — SKU, markup, margin, BOM cost — each in a different colour, with the existing negative-value warning style preserved for markup and margin; the BOM cost chip appears to the right of the margin chip, uses the same chip style, and is shown only for a variant that has a BOM.
- **FR-003**: The card header MUST place, on its right side and in this left-to-right order: **Open BOM** (button), **Apply markup** (button), delete icon. Open BOM appears only for a saved variant; for variants whose BOM cost is unknown it still opens the BOM.
- **FR-004**: **Open BOM** MUST be a button (not a text link) with a tooltip, available on hover and keyboard focus, explaining what a BOM is in plain language, in both languages.
- **FR-005**: The product drawer MUST be wider than today on desktop screens, and the variant fields MUST be ordered: variant name, stock, cost price, sell price. The stock field is no longer paired with the Apply markup button.
- **FR-006**: The variant picture thumbnail MUST be 50% larger than today (a larger placeholder when there is no picture) and keep the click-to-enlarge behaviour.
- **FR-007**: Existing behaviour of every moved control MUST be unchanged: Apply markup still computes the sell price from the cost price and the markup percentage; delete still removes an unsaved variant or switches off a saved one; Open BOM still opens the variant's BOM; a completed BOM still locks the cost price.

**Duplicate variant**

- **FR-008**: Each variant card MUST offer a **Duplicate** action that adds a new, unsaved card directly below the source, pre-filled with the source's name (marked as a copy), cost price, sell price, low-stock alert, status and stock.
- **FR-009**: When the source is a saved variant with a BOM and the user may manage BOMs, the duplicate MUST be flagged to receive an independent copy of the source's BOM when the product is saved, and the card MUST say so; otherwise no BOM copy is promised or made.
- **FR-010**: Nothing MUST be stored until the product is saved; cancelling discards the duplicate. On save the new variant MUST receive its own server-generated SKU, and its opening stock MUST be recorded in the stock history like any new variant's.
- **FR-011**: Because the copy keeps the source's stock (clarification), the duplicate card MUST visibly state that this stock counts as new inventory, and the user MUST be able to edit it before saving.
- **FR-012**: A duplicate of a variant with a completed BOM MUST receive the BOM rows but MUST NOT be marked complete; its cost price keeps the duplicated value until its own BOM is completed.
- **FR-013**: A duplicate MUST NOT copy the variant's picture, SKU or stock history (the user can upload a picture for the copy).

**BOM dialog copy tool**

- **FR-014**: The variant lists used by "Copy from another variant" and by copy-to selections MUST be fully reachable (scrollable or keyboard-navigable to the last entry) regardless of where the dialog or trigger sits on screen; a list MUST NOT extend beyond the visible screen without a way to reach its end.
- **FR-015**: Every variant shown in those lists MUST show its picture (placeholder when none) beside its SKU and name.
- **FR-016**: The copy tool MUST offer **Copy to chosen variants**: a searchable list of the other variants of the same product, each with a picture and a checkbox, a count of selected variants, and a copy action that is available only when at least one is selected. The selected set is limited to variants of the same product other than the source.
- **FR-017**: Copying to chosen variants MUST follow the same safety rules as the other copy modes: all-or-nothing, same product only, confirmation naming the variants whose existing BOM rows would be replaced, never marking a target complete, and independent afterwards.
- **FR-018**: **Add BOM item** MUST be on the same row as **Save changes** (add on the left, save on the right), without breaking the existing disabled/loading/unsaved-indicator behaviour or the narrow-width layout.
- **FR-019**: The existing unsaved-changes guard MUST apply to the new copy mode too.

**Cross-cutting**

- **FR-020**: Every new or changed label, tooltip and message MUST exist in both supported languages (and the missing-translation check must keep passing); colours MUST come from the design tokens (no raw colour values), and the API documentation MUST be updated in the same change for any new or extended endpoint.

### Key Entities *(include if feature involves data)*

- **Variant card**: the on-screen editing unit of one variant (chips, actions, fields, picture). A duplicate card is an unsaved card seeded from another variant.
- **Duplicate seed**: the values copied from a source variant (name, prices, alert, status, stock) plus an optional reference to the source whose BOM is to be copied on save.
- **BOM copy selection**: the set of target variants ticked by the user for "copy to chosen variants" (same product, never the source).
- **Variant**, **BOM line**: existing; unchanged in meaning.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: In a usability check on a product with 5+ variants, a first-time user can point to where one variant ends and the next begins, and read each of SKU, markup, margin and BOM cost correctly, in under 10 seconds.
- **SC-002**: Duplicating a variant that has a BOM takes at most 4 user actions from the card to a saved copy with its BOM (duplicate, rename, save product, done), versus the current typed-from-scratch plus separate BOM copy.
- **SC-003**: From the BOM copy tool, a user can copy a BOM to 3 specifically chosen variants of a product with 8+ variants in under 30 seconds, and no unchosen variant is changed (0 stray changes).
- **SC-004**: 100% of entries of a 30-variant list in every copy list are reachable (including the last one) at common laptop and tablet screen heights, in both the top and the bottom position of the dialog.
- **SC-005**: Every control moved or restyled by this feature behaves identically to before (all pre-existing component tests keep passing unchanged, except where a test asserts the old position or wording).
- **SC-006**: The variant picture is at least 50% larger (by width and height) than before and the drawer's usable width increases by at least 20% on a 1440 px wide screen.
- **SC-007**: 0 untranslated keys and 0 raw colour values introduced; text/background contrast of all four chip colours meets the common readable-text threshold (4.5:1).

## Assumptions

- "Make it more wider" refers to the **Edit product** drawer shown in the screenshot (the BOM dialog is already wide); the BOM dialog's layout changes are limited to the items listed.
- "Thumbnail image" means the variant picture preview inside each variant card (shown beside the file chooser); the Products list thumbnails were already enlarged in an earlier feature and are not changed here.
- Chip colours are chosen from the existing design palette; the exact hues are a design decision within FR-002 and SC-007.
- A duplicate is an unsaved card (not an immediate save): it appears right below the source, its name is prefilled with a "copy" marker, and the existing new-variant save flow (including copying a BOM from a saved variant on creation) is reused.
- "Copy to chosen variants" extends the existing copy tool (to all / to next / from another variant) with a fourth, explicit-selection mode; the existing three modes keep working.
- The duplicate copies cost price, sell price, low-stock alert, active status, name and stock; it does not copy the picture, because duplicates are usually a different design/colour.
- Users without the purchase-order permission can still duplicate variants but cannot copy BOMs (BOM copying already requires that permission).
- Out of scope: any change to how BOM cost, cost price completion, markup/margin formulas or stock recording work; reordering variants; bulk duplication of many variants at once.
