# Feature Specification: Android POS Phase 1 Layout, Connection and Server Address Fixes

**Feature Branch**: `054-android-pin-layout-fixes`

**Created**: 2026-10-17

**Updated**: 2026-10-17 (round 2: full outlet name, connection state root cause, admin-configurable server address)

**Status**: Draft

**Classification**: INTERNAL

**Input**: User description: "update android app phase 1: fix the 'Warkop WJM 3 · T02' layout to be one line; fix the error message 'Tidak dapat menjangkau server. Periksa koneksi lalu coba lagi'; make the PIN number scrollable" (with a screenshot of the tablet's login screen, terminal T02, showing the chip wrapping onto two lines, the error banner displayed while the status chip reads "Online", and the PIN pad in a fixed layout)

**Round 2 input**: (1) the outlet name must display in full, either with a smaller font or by moving the chip so it has room; (2) "why always Online? I disable wifi, but it always shows Online" — make the server address configurable from the admin page via the API, so the tablet connects either to a local server or to an online server address (the backend will be deployed to a live server); (3) PIN scrolling accepted as specified.

## Context

Phase 1 of the Android POS tablet (`android-pos/`) is in use at the pilot outlet. The sign-in screen ("Siapa yang bertugas?") is the first screen a cashier sees on each shift. The screenshot shows three problems on that screen:

1. The terminal chip at the bottom left of the red panel reads "Warkop WJM 3 · T02" and wraps onto two lines, which looks broken.
2. The error banner "Tidak dapat menjangkau server. Periksa koneksi lalu coba lagi" is shown while the connection chip reads "Online". The two statements contradict each other, so the cashier cannot tell whether to wait, retry, or call for help.
3. The PIN entry area has no room to move. On a shorter screen or when the banner is large, the keypad can be pushed out of reach, so the PIN cannot be entered.

**Root cause of problem 2 (found on review of the app code, not yet verified on the tablet):** the connection chip starts as "Online" by default. On the sign-in screen there is no signed-in session yet, so the background heartbeat that would update the chip does not run, and the staff-list request that fails (which produces the banner) does not update the chip either. The chip therefore never leaves "Online" while the cashier is signed out, whatever the real network state is. This means the earlier assumption that "Online" reflects the app's last successful contact with the server was wrong.

**Why the server address matters now:** the tablet currently reaches the server at a fixed local address. The product owner plans to deploy the backend to a live server. Without an admin-configurable address, every change of server would need a new build or a manual edit on each tablet.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Full outlet name on one line (Priority: P1)

A cashier opens the app on terminal T02 and sees the full outlet name and terminal code on one line in the bottom-left chip. Long outlet names are never cut off and never wrap: the text is made smaller, or the chip is moved so it has room, so the whole name is always readable.

**Why this priority**: It is visible on every sign-in and every shift start. A wrapped or cut name looks like a defect, and a cut name hides which outlet the cashier is working in.

**Independent Test**: Open the sign-in screen on the tablet with a terminal named "Warkop WJM 3" with code T02, then with a deliberately long outlet name (for example 40 characters). Confirm the whole name and the code are visible on one line in both orientations, with no ellipsis and no wrap.

**Acceptance Scenarios**:

1. **Given** a terminal named "Warkop WJM 3" with code T02, **When** the sign-in screen is shown, **Then** the chip reads "Warkop WJM 3 · T02" on one line with no wrapping and no ellipsis.
2. **Given** a long outlet name that does not fit at normal size, **When** the sign-in screen is shown, **Then** the text is made smaller until the full name and code fit on one line, and no characters are hidden.
3. **Given** a name that would need the text to become too small to read, **When** the sign-in screen is shown, **Then** the chip moves to a position with enough room, so the name stays at a readable size and still fits on one line.
4. **Given** the tablet is rotated to landscape or portrait, **When** the sign-in screen is shown, **Then** the full name still shows on one line.

---

### User Story 2 - Connection message and chip match the real connection state (Priority: P1)

The connection chip reports whether the tablet can reach the configured server, including on the sign-in screen before anyone has signed in. When the tablet cannot reach the server, the cashier sees one clear message. When it can, the chip reads "Online" and no "cannot reach the server" message appears.

**Why this priority**: A wrong "Online" blocks cashiers from starting a shift, and they cannot tell whether the problem is the network or the app.

**Independent Test**: With the tablet on Wi-Fi and the server reachable, open the sign-in screen and confirm the chip reads "Online" and no banner shows. Then turn Wi-Fi off, reopen or wait for the next check, and confirm the chip changes to offline and the banner appears with "Coba lagi". Turn Wi-Fi back on, press "Coba lagi", and confirm the chip reads "Online", the banner disappears, and the staff list loads.

**Acceptance Scenarios**:

1. **Given** the server responds, **When** the sign-in screen is shown, **Then** the chip reads "Online" and no "Tidak dapat menjangkau server" banner is shown.
2. **Given** the tablet has no network connection, **When** the sign-in screen is shown, **Then** the chip reads offline, a banner says the server cannot be reached, says to check the connection, and offers "Coba lagi".
3. **Given** the banner is shown and the connection has returned, **When** the cashier presses "Coba lagi", **Then** the chip reads "Online", the banner disappears, and the staff list loads.
4. **Given** the server responds with an error other than an unreachable connection, **When** the sign-in screen is shown, **Then** the banner describes that situation, does not claim the server cannot be reached, and the chip does not read offline for that reason alone.
5. **Given** the chip has not yet checked the connection (for example right after the app starts), **When** the sign-in screen first appears, **Then** the chip does not claim "Online" until a check has succeeded.

---

### User Story 3 - Admin sets the server address: local or online (Priority: P1)

An administrator chooses, on the admin settings page, which server the tablets use: the local server on the outlet network, or the online server on the live deployment. The address for each option is set once by the admin through the API. Tablets use the chosen address without a new build or a manual edit on each tablet.

**Why this priority**: The product owner plans to move the backend to a live server. Without this, every tablet must be reconfigured by hand, and a mistaken address keeps the whole outlet locked out.

**Independent Test**: As an owner or admin, open the settings page, enter a local address and an online address, choose "Local", save, and confirm the setting is returned by the API. Change the choice to "Online" and confirm a tablet switches to the online address on its next connection check, without reinstalling the app.

**Acceptance Scenarios**:

1. **Given** an owner or admin is signed in on the admin page, **When** they enter a local server address and an online server address and save, **Then** both addresses are stored and shown the next time the page is opened.
2. **Given** both addresses are stored, **When** the admin selects "Local" or "Online" and saves, **Then** the API reports the selected option and the address that goes with it.
3. **Given** a user without admin rights, **When** they try to read or change the server address through the API, **Then** the request is refused and the stored address is not changed.
4. **Given** the admin changes the choice, **When** a tablet next checks the connection, **Then** the tablet uses the newly selected address and the connection chip reflects the result.
5. **Given** an address that is not a valid web address, **When** the admin saves it, **Then** the save is refused with a message that says which field is wrong, and the previous setting stays.

---

### User Story 4 - PIN entry stays usable on any screen height (Priority: P1)

A cashier can always reach the full PIN keypad and enter the PIN, even when the error banner is large or the tablet is short, by scrolling the sign-in content. (Accepted by the product owner in round 2.)

**Why this priority**: Without a PIN the cashier cannot start a shift, so a keypad pushed off-screen blocks the whole outlet.

**Independent Test**: Show the sign-in screen with the error banner visible and in a short window (for example landscape split-screen). Confirm the keypad can be scrolled into view and all six digits can be entered and submitted.

**Acceptance Scenarios**:

1. **Given** a short screen with the error banner shown, **When** the cashier scrolls the sign-in content, **Then** the PIN title, the six PIN dots, and all keypad digits 1–9 and 0 can be reached.
2. **Given** the sign-in content fits the screen, **When** the screen is shown, **Then** no scroll is needed and the layout looks as it does today.
3. **Given** the cashier has typed some digits, **When** they scroll, **Then** the entered digits are kept.

---

### Edge Cases

- An outlet name so long that even the smallest readable size does not fit the chip: the chip moves to a position with more room, and the full name is still shown.
- The connection status changes while the sign-in screen is open (for example Online to offline): the chip and banner update without the cashier reloading the screen.
- The admin points the tablets at an address that does not answer: tablets show the offline banner, and the admin can change the choice back from the admin page once the server is reachable again.
- The server reports the terminal is not set up: this is a separate situation and is not presented as "cannot reach the server".
- A cashier with a slow but working connection: the banner is not shown merely because a response takes a few seconds, unless the request actually fails.
- The admin changes the selected address while a cashier is signed in: the change applies at the next connection check, and an open shift is not closed or reset by it.

## Requirements *(mandatory)*

### Functional Requirements

**Terminal chip**

- **FR-001**: The terminal chip on the sign-in screen MUST show the outlet name and terminal code on a single line.
- **FR-002**: The full outlet name and the terminal code MUST always be visible. The text MAY be made smaller, or the chip MAY be moved, to make them fit. The name MUST NOT be cut with an ellipsis and MUST NOT wrap onto a second line.

**Connection state and message**

- **FR-003**: The connection chip MUST be updated by the sign-in staff-list request as well as by the signed-in heartbeat, so it reflects the connection state while no one is signed in.
- **FR-004**: Before any check has succeeded, the chip MUST NOT read "Online".
- **FR-005**: The "Tidak dapat menjangkau server. Periksa koneksi lalu coba lagi" message MUST be shown only when the app cannot reach the server, and MUST NOT be shown while the chip reads "Online".
- **FR-006**: When the server responds with a non-connection error, the sign-in screen MUST show a message that describes that error and MUST NOT claim the server cannot be reached.
- **FR-007**: The banner MUST offer "Coba lagi", and pressing it MUST retry the connection, update the chip, and hide the banner when the server responds.

**Server address (admin)**

- **FR-008**: The backend MUST store two server addresses: one for the local server and one for the online server.
- **FR-009**: The backend MUST store which of the two is selected ("Local" or "Online"). The selection MUST default to "Local", so an existing outlet keeps working with no change.
- **FR-010**: The admin settings page MUST let an owner or admin enter both addresses, choose one, and save. Changes are made through the API, and the page is a client of that API.
- **FR-011**: Reading and changing the server address MUST require the same administrative rights as other store settings. Other roles MUST be refused.
- **FR-012**: An address MUST be a valid web address with a scheme and host. An invalid address MUST be refused with a message that names the field, and the stored value MUST NOT change.
- **FR-013**: Tablets MUST use the selected address on their next connection check after a change. A change MUST NOT close or reset an open shift.
- **FR-014**: The tablet MUST keep working with the address it last used when the server cannot be reached, MUST show the offline state rather than failing silently, and MUST adopt the admin's choice only after a successful contact with a server (option A, decided 2026-10-17).

**PIN screen**

- **FR-015**: The sign-in content (title, error banner, staff prompt, PIN dots, and keypad) MUST be scrollable vertically when it does not fit the screen.
- **FR-016**: The keypad MUST remain fully reachable by scrolling on every supported tablet orientation.
- **FR-017**: Scrolling MUST NOT clear digits already entered.
- **FR-018**: When the content fits, the sign-in screen MUST look the same as before this change, with no scroll bar.

### Key Entities *(include if feature involves data)*

- **Terminal**: The tablet's place of use: outlet name, terminal code (for example T02). Already exists from the setup flow; this feature only changes how it is shown.
- **Connection state**: Whether the server responded, and the time of the last check. The chip and banner both read this one state.
- **Server address setting**: The local address, the online address, and which one is selected. One set of values for the whole store, changed only by an owner or admin.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: On the pilot tablet, the full terminal name and code show on one line in 100% of sign-in screen views, in both orientations, including with a 40-character outlet name.
- **SC-002**: With the server reachable and Wi-Fi on, the chip reads "Online" and the "cannot reach the server" message is shown in 0 of 50 sign-in screen views.
- **SC-003**: With Wi-Fi off or the server unreachable, the chip reads offline and the message is shown in 50 of 50 sign-in screen views, and pressing "Coba lagi" after reconnecting clears it on the first press in 95% of attempts.
- **SC-004**: An admin can switch the tablets between the local and the online address from the admin page, and a tablet uses the new address at its next connection check, with no reinstall, in 100% of test switches.
- **SC-005**: A user without admin rights cannot read or change the server address through the API, verified in 100% of attempts.
- **SC-006**: On a short-screen check (landscape split-screen, banner shown), a cashier can enter a six-digit PIN and submit it within 30 seconds without leaving the screen.
- **SC-007**: On a normal-height tablet with no banner, the sign-in screen layout is unchanged from the current release, verified by side-by-side comparison.

## Assumptions

- **ASSUMPTION**: The "Tidak dapat menjangkau server" message wording is kept as it is today. The problem reported is when it is shown, not its wording.
- **ASSUMPTION**: The root cause described in Context (the chip defaults to "Online" and is only updated by the heartbeat and by signed-in requests) is correct. This has been read from the code and must be confirmed on the tablet with Wi-Fi off before the fix is accepted.
- **ASSUMPTION**: "Make the PIN number scrollable" means the sign-in content scrolls as one area so the keypad can be reached. It does not mean the digits themselves scroll or that the PIN is typed differently. The product owner accepted this in round 2.
- **ASSUMPTION**: The pilot tablet is the reference device. Other supported tablets only need to satisfy the functional requirements above.
- **ASSUMPTION**: Only the sign-in screen is in scope for the layout and connection changes. The shift-open, till, and other screens keep their current layouts. The chip change for other screens follows the same connection state, but their layouts are unchanged.
- **ASSUMPTION**: The admin page is the existing web settings screen in the backend. The new fields follow the existing settings pattern and permission rules.
- **ASSUMPTION**: The default selection is "Local" and the default local address is the one the pilot tablet uses today, so the pilot keeps working until the admin changes it.
- **ASSUMPTION**: The terminal name comes from the existing terminal setup and does not need a new field.
- **DECIDED (2026-10-17, product owner chose option A)**: The tablet keeps the address it last used and reads the admin's choice only when it can reach a server. A change made while the tablet is offline applies at its next successful contact. Rejected alternatives: B (both addresses entered on every tablet by hand) and C (automatic fallback that can hide a wrong setting).

## Out of Scope

- Changing the PIN length, the PIN keypad design, or the PIN check itself.
- Changing the wording of any message other than the connection banner.
- Deploying the backend to a live server; this feature only lets the address be changed.
- Changes to the terminal setup flow beyond what is needed to keep the first-run address entry working.
- Screens other than the sign-in screen for the layout changes.
