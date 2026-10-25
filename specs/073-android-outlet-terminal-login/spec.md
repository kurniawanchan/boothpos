# Feature Specification: Android Outlet → Terminal → PIN Login

**Classification**: INTERNAL

**Feature Branch**: `073-android-outlet-terminal-login`

**Created**: 2026-10-25

**Status**: Draft

**Input**: User description: "for the android app, no need to login as owner or admin, show choose outlet screen directly. in the outlet screen, when user select outlet, Terminal field will shown, if no terminal exist, then user can type the new terminal, if exist, user can just select the terminal (can select the same terminal). save the terminal data to database based on each outlet. after user select outlet and terminal, the user selection based on outlet will shown, user can select the user that want to login and type the pin number to login."

## Clarifications

### Session 2026-10-25

- Q: Who may register a new terminal, and see the user list before sign-in? → A: Open (option A): anyone who opens the app; no setup code and no owner/admin PIN. Protected only by PIN rate-limiting and minimal pre-login data.
- Q: What is the PIN lockout rule? → A: Per-user lockout (option B): 5 wrong PINs for the same user lock that user for 5 minutes on all tablets; the app shows the remaining wait.
- Q: Which users appear in an outlet's list? → A: Option B: only cashier and inventory users allowed at that outlet; owner and admin are not listed and cannot sign in on the Android app.
- Q: How are terminals paired the old way treated? → A: Option A: same kind of record; picking or typing an existing name uses that record, whether paired the old way or created in this flow, so orders, shifts and receipts stay on one record.
- Q: What if the tablet's terminal is revoked while a user is signed in? → A: Option B: finish, then block. The current order and unsynced orders can be completed and synced; afterwards the app returns to outlet selection with a message and no new order can start.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Open the app and pick an outlet and terminal (Priority: P1)

A staff member opens the Android POS app on a tablet that has never been set up. Instead of being asked for an owner/admin username and password, the first screen is the outlet picker. They choose their outlet; a Terminal field then appears. If the outlet already has terminals, they pick one from the list. If it has none (or theirs is not listed), they type a new terminal name. Either way they continue to the next step with an outlet and a terminal chosen.

**Why this priority**: This replaces the owner/admin sign-in that blocks every new device today. Without it nothing else in the flow is reachable.

**Independent Test**: On a fresh install, launch the app, confirm the first screen is the outlet list (no owner/admin login), select an outlet, and confirm the Terminal field appears and lets you either pick an existing terminal or type a new one.

**Acceptance Scenarios**:

1. **Given** a freshly installed app, **When** it is opened, **Then** the outlet selection screen is shown directly, with no owner/admin username or password prompt.
2. **Given** the outlet screen, **When** no outlet is selected, **Then** no Terminal field is shown and the user cannot continue.
3. **Given** an outlet that has registered terminals, **When** the user selects that outlet, **Then** a Terminal field appears listing that outlet's active terminals by name, and the user can select one.
4. **Given** an outlet that has no terminals, **When** the user selects it, **Then** the Terminal field appears as a text entry where they type a new terminal name.
5. **Given** an outlet that already has terminals, **When** the user wants a terminal not in the list, **Then** they can type a new name instead of picking an existing one.
6. **Given** the user typed a new terminal name, **When** they continue, **Then** the terminal is saved under the selected outlet and is selectable from that outlet's list from then on.
7. **Given** an existing terminal, **When** the user selects it (including a terminal another tablet already uses), **Then** it is accepted: several tablets may share the same terminal.
8. **Given** a typed name that is blank, or duplicates an active terminal of the same outlet (ignoring case and surrounding spaces), **When** the user continues, **Then** a duplicate resolves to the existing terminal (no second record is created) and a blank is refused with a clear message.

---

### User Story 2 - Choose a user and sign in with PIN (Priority: P1)

After outlet and terminal are chosen, the app shows the people who can work at that outlet. The staff member taps their name, types their PIN, and is signed in on that outlet and terminal.

**Why this priority**: This is the actual sign-in. Without it the outlet/terminal choice leads nowhere.

**Independent Test**: After choosing an outlet and terminal, confirm only that outlet's users are listed, select one, enter the correct PIN, and confirm the app opens signed in as that user on that outlet and terminal.

**Acceptance Scenarios**:

1. **Given** an outlet and terminal are chosen, **When** the next step opens, **Then** it lists only the active cashier and inventory users who can work at that outlet (owner and admin are never listed).
2. **Given** the user list, **When** the user taps a name, **Then** a PIN entry appears for that person.
3. **Given** the correct PIN, **When** it is submitted, **Then** the user is signed in and the app shows its normal home for that outlet and terminal.
4. **Given** a wrong PIN, **When** it is submitted, **Then** sign-in is refused with a clear message and the user may retry; after 5 wrong PINs for the same user, that user is locked for 5 minutes on all tablets and the app shows the remaining wait.
5. **Given** a user who has no PIN set, **When** their name is shown, **Then** they are marked as unable to sign in and cannot be selected.
6. **Given** the signed-in session, **When** the user signs out, **Then** the app returns to the user list for the same outlet and terminal (not to outlet selection).

---

### User Story 3 - The device remembers its outlet and terminal (Priority: P2)

Once a tablet has an outlet and terminal, later launches and sign-outs skip straight to the user list, so staff only pick a name and enter a PIN. A clear "change outlet / terminal" action lets someone reset the choice.

**Why this priority**: Fast daily start-up for cashiers; the flow still works without it (staff could re-pick every time), hence P2.

**Independent Test**: Complete the flow once, close and reopen the app, and confirm it opens on the user list for the saved outlet and terminal; use "change outlet / terminal" and confirm it returns to the outlet screen.

**Acceptance Scenarios**:

1. **Given** a tablet with a saved outlet and terminal, **When** the app is relaunched, **Then** the user list for that outlet is shown directly.
2. **Given** the user list, **When** the user taps "change outlet / terminal", **Then** the outlet screen is shown and the saved choice is cleared once a new one is made.
3. **Given** the saved terminal was revoked or the outlet was disabled on the server, **When** the app is launched, **Then** the app returns to the outlet screen with a message instead of failing silently.

---

### Edge Cases

- No outlets exist on the server: the outlet screen shows an explanatory empty state, not a blank list.
- The server is unreachable on the outlet screen: show a retry state; the flow cannot continue offline on a never-set-up device.
- Two terminals in different outlets may share a name; uniqueness applies within one outlet and data mode (same rule as feature 064).
- Single-outlet installs: the outlet list has one entry; it is still shown (or pre-selected) so the flow is uniform. [ASSUMPTION: pre-select it and still show the Terminal field.]
- A revoked terminal is not offered; typing its old name creates a new active terminal.
- Typed terminal names are trimmed and limited to the existing terminal name length.
- A user belonging to several outlets appears in each outlet's list and signs in with the same PIN.
- An open shift or open tabs on a terminal stay with that terminal when another tablet selects it.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Android app MUST show the outlet selection screen as its first screen on a device with no saved outlet, without requiring an owner or admin sign-in.
- **FR-002**: After an outlet is selected, the app MUST show a Terminal field; it MUST NOT be shown before an outlet is selected.
- **FR-003**: When the selected outlet has active terminals, the Terminal field MUST let the user choose one by name; when it has none, it MUST let the user type a new name.
- **FR-004**: The user MUST be able to type a new terminal name even when the outlet already has terminals.
- **FR-005**: A newly typed terminal MUST be persisted in the database under the selected outlet and MUST appear in that outlet's terminal list afterwards.
- **FR-006**: Terminal names MUST be unique per outlet and data mode among active terminals, compared ignoring letter case and surrounding spaces; typing a name that already exists MUST select the existing terminal rather than create a duplicate; a blank name MUST be refused.
- **FR-007**: Selecting an already-existing terminal MUST be allowed even if other tablets already use it.
- **FR-008**: After outlet and terminal are chosen, the app MUST list the active cashier and inventory users who can work at that outlet, and only those users; owner and admin accounts MUST NOT be listed and MUST NOT be able to sign in on the Android app.
- **FR-009**: Selecting a user MUST prompt for that user's PIN; a correct PIN MUST sign the user in scoped to the chosen outlet and terminal.
- **FR-010**: Wrong PINs MUST be refused with a clear message. After 5 consecutive wrong PINs for the same user, that user MUST be locked for 5 minutes across all tablets, with the remaining wait shown; a correct PIN resets the count.
- **FR-011**: Users without a PIN MUST be visibly non-selectable.
- **FR-012**: The app MUST remember the chosen outlet and terminal on the device and, on relaunch or sign-out, go directly to the user list; it MUST offer a "change outlet / terminal" action.
- **FR-013**: If the remembered terminal or outlet is no longer valid at launch or sign-out, the app MUST return to the outlet screen with an explanatory message.
- **FR-018**: If the terminal is revoked while a user is signed in, the app MUST let that user finish the current order and sync all unsynced orders, then return to the outlet screen with an explanatory message; no new order may be started after the revocation is detected.
- **FR-014**: The pre-sign-in screens MUST expose only what the flow needs: outlet names, terminal names, and user display names (no PINs, contact details or other personal data), and MUST NOT allow any data change beyond registering a terminal name.
- **FR-015**: Registering a terminal and every sign-in MUST be recorded in the activity log with outlet, terminal name and (for sign-in) the user.
- **FR-016**: Existing devices already paired through the owner/admin flow MUST keep working without re-pairing.
- **FR-017**: Terminals paired the old way and terminals created in this flow MUST be the same kind of record: both appear in the outlet's terminal list, and choosing or typing an existing name MUST use that record, keeping its orders, shifts and receipts.

### Key Entities

- **Outlet**: A sales location of the organisation; owns terminals and has a set of users allowed to work there.
- **Terminal**: A named cashier station belonging to exactly one outlet (and data mode); name unique among its outlet's active terminals; may be used by several tablets.
- **User**: A cashier or inventory staff member with a display name and PIN; may be allowed at one or more outlets. Owner and admin accounts are not Android sign-in users.
- **Device selection**: The outlet and terminal remembered on a tablet between launches.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A staff member on a new tablet can go from app launch to being signed in (outlet, terminal, user, PIN) in under 60 seconds with no owner/admin credentials.
- **SC-002**: On a tablet with a saved outlet and terminal, a returning cashier is signed in within 10 seconds of launch (select name, enter PIN).
- **SC-003**: 100% of terminals created through this flow appear under the correct outlet and are never listed under another outlet.
- **SC-004**: 0 duplicate active terminal names are created within one outlet through repeated or concurrent entries.
- **SC-005**: The user list for an outlet never shows a user who has no access to that outlet (verified across all outlets in test data).
- **SC-006**: 95% of first-time users complete the flow on the first attempt without assistance.

## Assumptions

- **Terminal storage already exists**: terminals are the existing outlet-owned, name-identified records (features 048 and 064); this feature changes how they are created and chosen, not what they are.
- **Open registration (confirmed 2026-10-25)**: anyone who opens the app can register a terminal name for an outlet and see the outlet's user display names, with no setup code and no owner/admin PIN. This widens the previous owner/admin-only pairing; the risk (unauthorised terminal names, user-name enumeration before sign-in) is accepted by the product owner for a locally installed, single-organisation deployment and limited by FR-010 and FR-014. A later feature may add a store-level device code if needed.
- **Users listed per outlet** means active cashier and inventory users allowed at that outlet (confirmed 2026-10-25). Owner and admin use the web app; any Android flow that previously relied on an owner/admin sign-in (for example pairing) is replaced by this flow.
- Sign-in uses the existing PIN mechanism and its existing rate limits; PIN rules (length, reset) are unchanged.
- The server is reachable from the tablet during setup and sign-in; offline behaviour after sign-in is unchanged.
- The web app's login is out of scope; only the Android POS app changes.
- Renaming or revoking terminals remains an owner/admin action elsewhere and is out of scope.
