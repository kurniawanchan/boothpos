# Feature Specification: Android POS — Mode Data, Store Branding, Counter Polish & Cashier Notes

**Classification**: INTERNAL

**Feature Branch**: `077-android-mode-branding-counter-notes`

**Created**: 2026-10-26

**Status**: Draft

**Input**: User description: "update for android app: get the data based on the system mode set in the web; add store logo in the PIN input screen; add store logo and name in all screens after login; in the Counter screen make the separator draggable left-right to adjust screen width; in the PIN input screen separate user information and PIN input into two columns; user initial/avatar taken from the user photo in the database; add cashier notes in the Counter page and make sure the order notes are saved to the database; relayout the price range in the Counter product list — it shows \"Rp 7.000-Rp\" truncated."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Android follows the system mode set on the web (Priority: P1)

The owner switches the system mode (LIVE or DEMO) on the web Settings → System mode page. The Android tablet must show, and write, the data of that same mode. A shopkeeper who flips to DEMO to train staff sees the demo outlets, menu and orders on the tablet; flipping back to LIVE returns the real data. Nothing on the tablet may mix the two.

**Why this priority**: Showing the wrong mode's data is a correctness and trust problem. Live sales could be recorded as demo, or demo items sold as real. Every other item on this list is cosmetic or additive by comparison.

**Independent Test**: Set mode to DEMO on the web, open the tablet, and confirm outlets/terminals, menu and open orders are the demo set. Set mode to LIVE, refresh the tablet, and confirm the live set. Create an order in each mode and confirm it appears only under that mode on the web.

**Acceptance Scenarios**:

1. **Given** the web mode is LIVE, **When** the tablet loads outlets, menu and orders, **Then** only LIVE data is shown.
2. **Given** the web mode is DEMO, **When** the tablet loads the same screens, **Then** only DEMO data is shown.
3. **Given** the tablet is open and the owner switches mode on the web, **When** the tablet next refreshes or reopens a screen, **Then** it shows the new mode's data and holds no stale items from the old mode (menu, cart, open orders, sold-out state).
4. **Given** a cashier is mid-order when the mode changes, **When** they try to pay or save, **Then** the app tells them the mode changed and does not silently write the order into the other mode.
5. **Given** the tablet is in DEMO, **When** any screen is shown, **Then** the cashier can tell that it is DEMO (visible indicator), so demo orders are never mistaken for real ones.

---

### User Story 2 - Store logo and name on every screen (Priority: P1)

The PIN screen shows the store's logo. Every screen after login shows the store's logo and name in its header, so a shared tablet is clearly identified with the store (and outlet) it serves. The logo and name come from the web Settings → Store identity.

**Why this priority**: Core brand and identity requirement for a licensed product, and it is the visible part of the web's store-identity setting that the tablet currently ignores.

**Independent Test**: Upload a logo and set a store name on the web. Open the tablet: the PIN screen shows the logo; after login every screen (Counter, orders, shift, settings, and so on) shows logo + name.

**Acceptance Scenarios**:

1. **Given** a store logo is set, **When** the PIN screen is shown, **Then** the logo is displayed prominently.
2. **Given** a store logo and name are set, **When** the user is on any post-login screen, **Then** the logo and store name appear in a consistent place.
3. **Given** no logo is uploaded, **When** the logo would be shown, **Then** a neutral fallback (store-name initials or default mark) is shown and layout does not shift.
4. **Given** the logo image fails to load (offline, bad URL), **When** the screen renders, **Then** the fallback is shown and the screen stays usable.
5. **Given** the owner changes the logo or name on the web, **When** the tablet next refreshes, **Then** the new branding appears.

---

### User Story 3 - PIN screen with user information and PIN entry in separate columns (Priority: P2)

On the PIN screen the selected user's information (photo/initial, name, role) is shown in one column and the PIN keypad/entry in a second column, side by side.

**Why this priority**: Improves clarity and fits the tablet's landscape layout, but login already works without it.

**Independent Test**: Open the PIN screen on a landscape tablet: user info on one side, PIN entry on the other, both fully visible without scrolling. Complete a login.

**Acceptance Scenarios**:

1. **Given** the PIN screen on a landscape tablet, **When** a user is selected, **Then** their information is in one column and the PIN entry is in a separate column.
2. **Given** a narrow window or portrait orientation, **When** the PIN screen renders, **Then** it remains fully usable (columns stack or compress without clipping the keypad).
3. **Given** the PIN is wrong or the account is locked, **When** the error shows, **Then** it appears in the PIN column and the user information stays visible.

---

### User Story 4 - Cashier notes on the Counter order, saved to the order (Priority: P2)

The cashier can type a note for the whole order on the Counter page (for example "less ice for all", "customer will pick up at 3"). The note is saved with the order on the server, so it is still there after the tablet restarts and shows up wherever the order is viewed (web Sales detail, receipt where applicable).

**Why this priority**: Real operational value and a data-persistence requirement, but it is additive to the working checkout flow.

**Independent Test**: On the Counter, add items, enter an order note, finish the order. Confirm the same note on the order in the web Sales detail and again after closing and reopening the app.

**Acceptance Scenarios**:

1. **Given** items in the cart, **When** the cashier enters a note, **Then** the note is kept while they continue editing the cart.
2. **Given** an order with a note, **When** it is submitted/paid, **Then** the note is stored on the server with that order.
3. **Given** a saved order with a note, **When** it is reopened on the tablet or viewed on the web, **Then** the same note is shown.
4. **Given** no note entered, **When** the order is submitted, **Then** it saves normally with an empty note.
5. **Given** a very long note, **When** it is entered, **Then** the app limits or rejects it with a clear message instead of losing it silently.
6. **Given** the network drops while submitting, **When** the order is retried, **Then** the note is not lost and not duplicated.

---

### User Story 5 - Draggable divider on the Counter screen (Priority: P3)

On the Counter screen, the divider between the product area and the cart/order area can be dragged left or right to change how much width each side gets. The cashier chooses between more products or a bigger cart.

**Why this priority**: Ergonomic improvement; the Counter works with the fixed split.

**Independent Test**: Drag the divider left and right on the Counter; both panes resize live and stop at their limits. Leave and return: the width is as the cashier left it.

**Acceptance Scenarios**:

1. **Given** the Counter screen, **When** the cashier drags the divider, **Then** the two areas resize continuously following the finger.
2. **Given** the divider reaches a minimum width for either area, **When** the cashier keeps dragging, **Then** it stops; neither area becomes unusable or hidden.
3. **Given** the cashier adjusted the split, **When** they leave and later return to the Counter (including after an app restart), **Then** the chosen split is kept.
4. **Given** a different screen size or orientation, **When** the Counter renders, **Then** the split stays within allowed limits.

---

### User Story 6 - Price range shown in full in the product list (Priority: P3)

A multi-variant product card shows its price range readable and untruncated (currently "Rp 7.000-Rp" is cut off). The range reads as a complete from–to value, for example "Rp 7.000 – Rp 15.000".

**Why this priority**: A visible defect, but small in scope and it does not block selling.

**Independent Test**: View a multi-variant product whose range is wide (large numbers) on the narrowest supported card width; the full range is readable with no clipping.

**Acceptance Scenarios**:

1. **Given** a product with different variant prices, **When** its card is shown, **Then** both the lowest and highest price are fully visible.
2. **Given** a narrow card or large amounts that don't fit one line, **When** rendered, **Then** the range stays complete by wrapping or shortening the format in a readable way, never cutting a number.
3. **Given** a product whose variants share one price, **When** shown, **Then** a single price is displayed.

---

### User Story 7 - User avatar from the user's photo (Priority: P3)

Wherever the app shows a user's initial as an avatar (PIN screen roster, headers, shift screens), it shows the user's photo from the database when one exists, and falls back to the initial when not.

**Why this priority**: Visual polish that depends on photos existing; the initial fallback already works.

**Independent Test**: Give one user a photo on the web and leave another without. On the tablet the first shows the photo, the second the initial.

**Acceptance Scenarios**:

1. **Given** a user with a photo, **When** their avatar is shown, **Then** the photo is displayed.
2. **Given** a user without a photo, **When** their avatar is shown, **Then** their initial is shown as before.
3. **Given** the photo fails to load, **When** shown, **Then** the initial is shown instead.
4. **Given** the photo is changed on the web, **When** the tablet next refreshes, **Then** the new photo appears.

---

### Edge Cases

- The tablet is offline when the mode was switched on the web: the next successful load must reflect the current mode, and the cashier must not be able to submit an order against a mode the app cannot confirm.
- Mode switches while orders are open on the tablet: open orders of the old mode are not shown or modified under the new mode, and they reappear when the mode is switched back (nothing is deleted).
- Store name is very long: it is shortened with an ellipsis in the header while the logo stays visible; the full name stays accessible where space allows.
- Store logo has an unusual aspect ratio or transparent background: it is scaled to fit its slot without distortion or overflow.
- Multi-outlet organisation: the header shows the store name and the current outlet name so staff can tell outlets apart. (ASSUMPTION: outlet name is shown when more than one outlet exists.)
- User photos are private or require authentication on the server: the avatar falls back to the initial rather than showing a broken image.
- Divider width saved on one tablet size and restored on a very different one: the saved value is clamped to current limits.
- Order note contains emoji, newlines or special characters: it is stored and shown exactly as entered.

## Requirements *(mandatory)*

### Functional Requirements

**System mode**

- **FR-001**: The Android app MUST show and write only the data of the system mode currently set on the web (LIVE or DEMO), for all screens: outlets and terminals, menu, orders, shifts and reports. (Staff accounts are shared by both modes and are not switched.)
- **FR-002**: The app MUST adopt a mode change made on the web the next time it refreshes or reopens a screen, with no leftover items from the previous mode.
- **FR-003**: The app MUST show a visible indicator on every post-login screen while the mode is DEMO.
- **FR-004**: If the mode changes while an order is being built, the app MUST NOT save that order into a different mode than the cashier saw; it MUST inform the cashier first.
- **FR-005**: Switching mode MUST NOT delete data in either mode.

**Store branding**

- **FR-006**: The PIN screen MUST display the store logo from the store identity settings.
- **FR-007**: Every screen after login MUST display the store logo and the store name from the store identity settings in a consistent place.
- **FR-008**: When the logo is missing or fails to load, the app MUST show a fallback and keep the layout stable.
- **FR-009**: Branding MUST refresh when the web settings change, no later than the next refresh or login.

**PIN screen layout**

- **FR-010**: The PIN screen MUST present user information (photo or initial, name, role) and PIN entry in two separate columns on landscape tablets.
- **FR-011**: The PIN screen MUST stay fully usable in portrait or narrow windows.

**Counter**

- **FR-012**: The Counter screen MUST let the cashier drag the divider between the product area and the cart area left or right to resize both areas.
- **FR-013**: The divider MUST have minimum widths for both areas so neither can be hidden, and the chosen split MUST persist across leaving the screen and app restarts.
- **FR-014**: The Counter MUST provide an order-level cashier note field.
- **FR-015**: The order-level note MUST be saved with the order on the server and returned when the order is reopened or viewed elsewhere (web Sales detail).
- **FR-016**: The note MUST be optional, MUST have a maximum length enforced with a clear message, and MUST NOT be lost or duplicated if submission is retried.
- **FR-017**: Multi-variant product cards MUST show the full price range without truncation.

**Avatar**

- **FR-018**: User avatars MUST show the user's photo from the database when present and the initial otherwise, including when the photo fails to load.

### Key Entities *(include if feature involves data)*

- **System mode**: The store-wide setting (LIVE or DEMO) set on the web. It decides which data set every client reads and writes.
- **Store identity**: Store name and logo set on the web, shown on the tablet.
- **Order note**: Free-text note of the cashier attached to a whole order, stored with the order. It is distinct from the per-item notes that already exist.
- **User**: Has name, role and an optional photo, used for the avatar.
- **Counter layout preference**: The cashier's chosen split width of the Counter, remembered on the device.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: After the owner switches mode on the web, 100% of tablet screens show the new mode's data within one refresh, with zero items from the previous mode visible.
- **SC-002**: Zero orders created on the tablet are stored under a mode different from the one shown to the cashier at submission time.
- **SC-003**: Store logo and name are visible on 100% of post-login screens and on the PIN screen when configured, and a fallback is shown on 100% of screens when not configured.
- **SC-004**: A cashier can resize the Counter split to a preferred width in under 3 seconds, and the width is restored on 100% of returns to the screen.
- **SC-005**: 100% of orders submitted with a note show the identical note on the web Sales detail, including after an app restart.
- **SC-006**: No product card shows a truncated price (no cut-off number) at any supported screen width, verified across the narrowest and widest card sizes.
- **SC-007**: A returning cashier can identify their own account on the PIN screen without reading, via photo or initial, in under 2 seconds. (ASSUMPTION: target is qualitative; adjust after on-device review.)

## Assumptions

- **Mode source**: The web Settings → System mode is the single source of truth; the tablet never sets the mode itself. The server already scopes outlets, terminals, menu and orders by mode (staff accounts are shared). A tablet set up under one mode holds an outlet and terminal that do not exist under the other, so after a switch it must follow the new mode instead of failing or showing stale data. The exact on-device symptom has not been reproduced (unverified).
- **Mode indicator**: A DEMO-only badge is assumed, with no badge in LIVE, so normal operation is uncluttered.
- **Mode change mid-order**: The cashier is warned and must re-confirm or reload instead of the order being moved silently (assumed safest default).
- **Order note scope**: The note is at the order level. Per-item notes already exist and stay unchanged. Showing the note on receipts and kitchen tickets is out of scope unless the existing receipt already prints order notes (to confirm in planning). The server order request already accepts an order-level `notes` field, so this is expected to need little or no server change (unverified for the Counter flow).
- **Note length**: A maximum of 1000 characters, matching the existing payment note limit (ASSUMPTION).
- **Divider persistence**: The split is stored per device, not per user or per outlet.
- **Branding source**: Store logo and name are the ones from the web store-identity setting, readable by every role after login (the web already exposes them to all roles). Logo URLs are resolved against the configured server address, as for product photos.
- **Photo source**: The user photo is the one stored for the user on the server; users without a photo keep the initial. Photo upload itself is already available on the web and is out of scope.
- **Out of scope**: Changing the system mode from the tablet, new roles or permissions, editing branding from the tablet, and changes to the web app other than what the order note display requires.
- **Dependencies**: Existing store identity settings, user photos, the PIN login flow (073/074) and the Counter product grid (075).
