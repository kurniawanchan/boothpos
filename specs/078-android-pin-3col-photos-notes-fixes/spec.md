# Feature Specification: Android POS — Three-Column PIN Screen, User Photos Everywhere, Order Note on Documents, Login Lockout Fix

**Classification**: INTERNAL

**Feature Branch**: `078-android-pin-3col-photos-notes-fixes`

**Created**: 2026-10-27

**Status**: Draft

**Input**: User description: "update for android app: in the PIN screen separate the user information and PIN input into columns — make it 3 columns: first column is like before the last change, column 2 is the user list and detail, column 3 is the PIN input; update the user initial in all screens (including after sign in) to come from the user photo in the database; add the cashier note to the receipt, kitchen ticket and transaction; fix the 'Too Many Attempts' login error [screenshot of the web login]; why are there two identical user names? [photo of the tablet PIN screen]"

## Findings from the supplied evidence

- **Duplicate name (photo)**: the tablet shows "Dewi · Kasir" twice — once as the staff chip and again as the "selected user" block added in 077. It is the same single user shown twice by the layout, not two accounts. The same photo also shows the left panel clipped: the heading "Siapa yang bertugas?" is cut at the top and the selected-user avatar is cut at the bottom.
- **"Too Many Attempts" (screenshot)**: it is the *web* login ("Masuk ke kasir", username `owner`), shown in English, with no wait time. The server counts unauthenticated requests per network address, and the counter is shared by every rate-limited unauthenticated route (the web login allows 5 per minute; the tablet's PIN screen refreshes the staff list and store identity every 10 seconds). Cause is inferred from code, not yet reproduced (unverified).

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Signing in is never blocked by unrelated traffic (Priority: P1)

An owner or admin signs in on the web, and staff sign in on a tablet, even while tablets on the same network are showing their PIN screens. A person who types a correct password or PIN is let in. Real guessing attempts against an account are still slowed down. When a limit is reached, the message is in Indonesian and says how long to wait.

**Why this priority**: The owner cannot sign in at all today, which blocks every other task. This is the only story that blocks access.

**Independent Test**: Leave one tablet on the PIN screen for 2 minutes, then sign in on the web with the correct password from the same network: it succeeds. Then enter a wrong password repeatedly: sign-in is refused with an Indonesian message naming the wait time, and a correct attempt after the wait succeeds.

**Acceptance Scenarios**:

1. **Given** one or more tablets are idling on the PIN screen, **When** the owner signs in on the web with correct credentials, **Then** sign-in succeeds on the first attempt.
2. **Given** a wrong password is entered repeatedly for one account, **When** the limit for that account is reached, **Then** further attempts for that account are refused with a message in Indonesian that states how many seconds or minutes to wait.
3. **Given** the limit was reached for one account, **When** a different account signs in from the same network, **Then** that sign-in is not affected.
4. **Given** the wait time has passed, **When** the correct password is entered, **Then** sign-in succeeds and the previous failures no longer count.
5. **Given** a tablet has been on the PIN screen for an hour, **When** nobody signs in, **Then** the tablet's background refreshes never cause any sign-in to be refused.
6. **Given** a rate-limit refusal on the tablet, **When** it is shown, **Then** it is in Indonesian with the wait time, not "Too Many Attempts."

---

### User Story 2 - Three-column PIN screen, each user shown once (Priority: P2)

On a wide tablet the PIN screen has three columns: **column 1** the store panel as before the last change (logo and store name, outlet · terminal, clock and date, connection chip, DEMO badge); **column 2** the staff list and the detail of the selected staff member; **column 3** the PIN entry (title, dots, error line, keypad). Every staff member appears exactly once: the selected one is shown as a highlighted, expanded entry inside the list instead of being repeated below it. Nothing is clipped.

**Why this priority**: It was requested directly and fixes the duplicate-name confusion, but sign-in works without it.

**Independent Test**: On a landscape tablet with two or more staff, open the PIN screen: three columns are visible; each name appears once; tap another staff member and the highlight moves; enter a wrong PIN and the error appears in column 3 while column 2 keeps the selected user visible.

**Acceptance Scenarios**:

1. **Given** a wide landscape tablet, **When** the PIN screen opens, **Then** three side-by-side columns are shown in the order store panel, staff list with detail, PIN entry.
2. **Given** one staff member only, **When** the screen opens, **Then** that name appears exactly once on the whole screen (not as a chip and again as a detail block).
3. **Given** several staff, **When** one is selected, **Then** that entry is highlighted and expanded with photo, name and role while the others stay compact, and every name appears once.
4. **Given** staff without a PIN, **When** the list shows, **Then** they remain visible but dimmed with the reason, as before.
5. **Given** a wrong PIN or a locked account, **When** the error shows, **Then** it appears in column 3 and the selected user stays visible in column 2.
6. **Given** more staff than fit in the column, **When** the list is long, **Then** column 2 scrolls and no heading, avatar or name is cut off; the first and last entries are fully reachable.
7. **Given** a phone or narrow window, **When** the screen opens, **Then** the same content stacks in a single scrolling column in the order store identity, staff, PIN, with each name still shown once.
8. **Given** the DEMO mode, **When** the screen opens, **Then** the DEMO badge is visible in column 1.

---

### User Story 3 - User photos replace initials on every screen (Priority: P2)

Wherever the app shows a user as a circle with initials — the PIN screen, the header after sign in, the shift screens and any other place a user avatar appears — it shows the user's photo from the database when the user has one, and initials only when there is no photo or it cannot be loaded.

**Why this priority**: Visible polish that the owner asked for twice; the initials fallback keeps the app usable meanwhile.

**Independent Test**: Give two staff a photo on the web and leave one without. On the tablet check the PIN screen, the header after sign in, the shift and order screens: photos for the first two, initials for the third, on every screen.

**Acceptance Scenarios**:

1. **Given** a user with a photo, **When** their avatar is shown on any screen before or after sign in, **Then** the photo is shown.
2. **Given** a user without a photo, **When** their avatar is shown, **Then** initials are shown.
3. **Given** a photo that fails to load, **When** the avatar is shown, **Then** initials are shown and the screen stays usable.
4. **Given** a user changes their photo on the web, **When** the tablet next refreshes (or the user signs in again), **Then** the new photo is shown, not the old one.
5. **Given** the photo is shown in the PIN list and again after sign in, **When** comparing, **Then** it is the same image for that user on both.
6. **Given** a user who has a photo in the database but still sees initials, **When** investigated, **Then** the cause is found and fixed (the earlier change was meant to show photos but the owner still sees initials).

---

### User Story 4 - Cashier's order note on the receipt, kitchen ticket and transaction (Priority: P2)

The note a cashier writes for the whole order on the Counter is printed on the customer receipt and on the kitchen ticket, and is shown with the transaction wherever transactions are viewed or exported, so the note is not lost after the order is entered.

**Why this priority**: The note is only useful to the kitchen and to the record if it reaches them; it was added last change but is not yet used downstream.

**Independent Test**: Write a note on an order, send it to the kitchen and pay. The kitchen ticket shows the note, the receipt shows the note, and the transaction detail and export on the web show the same note.

**Acceptance Scenarios**:

1. **Given** an order with a note, **When** the kitchen ticket is printed, **Then** the note is printed on it, clearly marked as an order note and separate from item notes.
2. **Given** an order with a note, **When** the receipt is printed or reprinted, **Then** the note is printed on it.
3. **Given** an order with a note, **When** the transaction is opened in the sales view or exported, **Then** the note is shown/included.
4. **Given** an order without a note, **When** any of these documents is produced, **Then** no empty note line or label appears.
5. **Given** a very long or multi-line note, **When** printed, **Then** it wraps within the paper width and no text is lost.
6. **Given** the note is changed after the order was sent to the kitchen, **When** more items are sent, **Then** that ticket carries the current note; tickets already printed are not reprinted automatically.
7. **Given** the note contains special characters or emoji, **When** printed, **Then** printable characters appear as entered and unsupported ones are replaced without breaking the print.

---

### Edge Cases

- A tablet on a different network than the owner's browser but behind the same router address: the lockout rules must not couple them.
- Many tablets in one outlet all idle on the PIN screen: combined background traffic never causes a refusal of a real sign-in.
- Someone repeatedly guessing PINs on the tablet: the existing account lock (five wrong PINs) still applies and its message stays in Indonesian.
- Staff list with one, two, and 20 entries: each name appears once; the screen is usable without clipped text at every count.
- Two staff with the same display name: they are different people and both appear, each with their own photo or initials and role (the screen must not merge or hide one of them).
- A photo that is very large, not a valid image, or deleted on the server: initials are shown.
- The order note is edited while the receipt of that order is being reprinted: the receipt shows the note as stored at print time.
- Thermal paper width is narrow: long notes wrap on word boundaries where possible.

## Requirements *(mandatory)*

### Functional Requirements

**Login lockout**

- **FR-001**: Background refreshes made by tablets (staff list, store identity, terminal checks) MUST NOT reduce the number of sign-in attempts available to any person, on the web or on a tablet.
- **FR-002**: Sign-in attempt limits MUST be counted per account (together with the network address), not as one shared counter for all rate-limited unauthenticated requests of a network address.
- **FR-003**: Repeated wrong credentials for an account MUST still be slowed down; a correct password or PIN within the allowance MUST succeed.
- **FR-004**: When a sign-in limit is reached, the refusal message MUST be in Indonesian and MUST state the time to wait; on the tablet it MUST be shown on the PIN screen.
- **FR-005**: A limit reached by one account MUST NOT block sign-in for other accounts.

**PIN screen**

- **FR-006**: On wide screens the PIN screen MUST show three columns in this order: (1) store panel as before the last change — logo and store name, outlet · terminal, clock and date, connection status, DEMO badge; (2) staff list with the selected staff member's detail; (3) PIN entry.
- **FR-007**: Each staff member MUST appear exactly once on the PIN screen; the selected member is shown as a highlighted, expanded entry in the list, not repeated in a separate block.
- **FR-008**: Staff without a PIN MUST remain visible but dimmed and not selectable, with the reason shown.
- **FR-009**: PIN errors (wrong PIN, locked, offline) MUST appear in the PIN column while the selected user remains visible in the staff column.
- **FR-010**: No text or avatar on the PIN screen MAY be clipped at any supported size; long staff lists MUST scroll within their column.
- **FR-011**: On narrow screens the same content MUST stack in one scrolling column in the order store identity, staff, PIN.

**Photos**

- **FR-012**: Every user avatar in the app (PIN screen, header after sign in, shift, order and any other place) MUST show the user's photo from the database when one exists and initials otherwise, including when the photo fails to load.
- **FR-013**: A changed photo MUST be shown no later than the next refresh or sign-in; the app MUST NOT keep showing a replaced photo indefinitely.
- **FR-014**: The cause of photos not appearing where the owner has set them MUST be identified and fixed; the same photo MUST appear before and after sign in.

**Order note on documents**

- **FR-015**: The kitchen ticket MUST print the order note when one exists, labelled distinctly from item notes.
- **FR-016**: The customer receipt (print and reprint) MUST print the order note when one exists.
- **FR-017**: The transaction record MUST show the order note in the sales transaction detail and include it in the transaction export.
- **FR-018**: Documents for orders without a note MUST NOT show an empty note label or blank line.
- **FR-019**: Long or multi-line notes MUST wrap within the paper width without losing text; unsupported characters MUST be replaced without failing the print.

### Key Entities *(include if feature involves data)*

- **Sign-in attempt allowance**: How many wrong attempts an account may make in a period, and how long a person waits when it is used up; belongs to the account, not to the network address as a whole.
- **Staff entry (PIN screen)**: One person: photo or initials, display name, role, whether a PIN is set, whether locked. Appears once.
- **User photo**: Optional image stored for a user, shown as the avatar everywhere.
- **Order note**: Free text for the whole order written by the cashier; carried on the kitchen ticket, receipt and transaction record. Distinct from per-item notes.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: With three tablets idle on the PIN screen for 10 minutes, a correct web sign-in succeeds on the first try 100% of the time.
- **SC-002**: After the allowed number of wrong attempts for one account, the next attempt is refused with an Indonesian message that states the wait time, and an attempt by a different account at that moment succeeds.
- **SC-003**: On the PIN screen every staff member name appears exactly once (0 duplicates) for lists of 1, 2 and 20 people, and no heading, name or avatar is cut off at any supported screen size.
- **SC-004**: A cashier finds and selects their own name and enters the PIN in under 10 seconds on a three-column screen with up to 10 staff.
- **SC-005**: 100% of user avatars on every screen show the user's photo when one exists, and initials otherwise (verified across PIN, header, shift and order screens).
- **SC-006**: 100% of orders with a note show the identical note on the kitchen ticket, the receipt and the transaction record; 0 documents show an empty note label.

## Assumptions

- **Scope of "login too many attempts"**: the screenshot is the web sign-in; the same rule also covers the tablet's PIN sign-in. The server-side cause (one shared counter per network address) is an inference from code and is to be confirmed by reproducing it during planning (unverified).
- **Limit values**: keep the same order of magnitude as today (about 5 wrong attempts per account per minute for password sign-in, five wrong PINs then a five-minute lock for PIN sign-in); only the way attempts are counted and the message change. Exact numbers are a planning decision.
- **Column 1** reuses the store panel content as it was before the last change (logo/name, outlet · terminal, clock and date, connection chip) plus the DEMO badge; the clock returns to its larger size only if it fits without clipping.
- **Selected user detail** shows photo, name and role; it is the expanded form of the list entry, so nothing is repeated.
- **Duplicate-name question**: answered by the findings above (one user shown twice by the layout); no data fix is needed unless two real accounts share a name, which the screen will show as two distinct entries.
- **"Transaction"** means the sales transaction detail and export on the web, plus the closed-order detail on the tablet; sales reports that only show totals are unchanged.
- **Kitchen ticket timing**: the note printed is the order's current note when the ticket is produced; changing the note later does not reprint old tickets automatically, and a deliberate reprint shows the current note (decided in planning: no snapshot is stored).
- **Receipt content** follows the existing receipt layout; the note appears below the items, above the totals footer (placement is a planning detail).
- **Out of scope**: changing the PIN rules, adding pairing codes (see the 073 security findings), editing photos from the tablet, per-item note changes, and web layout changes other than showing the note.
- **Dependencies**: builds on 077 (branding, PIN screen, order note, avatar component), which is not yet committed; the existing roster endpoint and user photos.
