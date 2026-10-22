# Feature Specification: Terminal Identified by Name Only

**Feature Branch**: `064-terminal-name-only`

**Created**: 2026-10-22

**Status**: Draft

**Input**: User description: "in android app, no need for Terminal count increment (the Tn) use only terminal name. use terminal name to other modules and link it to order transaction."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - A terminal is known by its name only (Priority: P1)

When a shop owner or admin registers a tablet, they type a terminal name (for example "Kasir Depan") and nothing else. The system no longer produces an automatic counter code (T01, T02, …) for the terminal. Everywhere the Android app shows which terminal it is — login (PIN) screen, shift screens, status chips, "other terminal" labels on shared tabs — it shows the terminal name, never a "Tn" code.

**Why this priority**: This is the core of the request. Every other part (other modules, order link) depends on the name being the one and only terminal identity.

**Independent Test**: Register a new terminal named "Kasir Depan" on the Android app, then open the PIN screen, start a shift and view a tab opened from another terminal. Each place shows "Kasir Depan" (or the other terminal's name) and no "T01"-style text appears anywhere.

**Acceptance Scenarios**:

1. **Given** the terminal setup form, **When** the admin enters the name "Kasir Depan" and registers, **Then** the terminal is created with that name, no counter code is generated or shown, and the form asks for no code.
2. **Given** a registered terminal, **When** the PIN screen, shift screens and connection chips are shown, **Then** each shows the terminal name instead of "Terminal Tn".
3. **Given** a tab opened on terminal "Kasir Depan", **When** it is viewed on terminal "Kasir Belakang", **Then** the screen labels it as opened on "Kasir Depan".
4. **Given** an outlet that already has a terminal named "Kasir Depan", **When** an admin registers another terminal with the same name (ignoring letter case and surrounding spaces), **Then** registration is refused with a clear message and no terminal is created.
5. **Given** a terminal name that is blank, **When** the admin submits, **Then** registration is refused and the admin is asked to enter a name.

---

### User Story 2 - Every order shows which terminal took it (Priority: P1)

Each order transaction is linked to the terminal that created and settled it. The terminal's name appears on the order wherever an order is shown or printed: the receipt, the order list and detail in the Android app, the Sales page and its export, and shift/end-of-day summaries.

**Why this priority**: The user explicitly asks to link the terminal name to the order transaction; it is what makes the name useful for auditing and for telling cashier stations apart.

**Independent Test**: Take two orders on two differently named terminals; open each in the Android order detail, on the printed receipt, on the web Sales page and in the Sales export. Each order shows the name of the terminal that took it.

**Acceptance Scenarios**:

1. **Given** an order paid on terminal "Kasir Depan", **When** its receipt is printed or viewed, **Then** the receipt shows the terminal name "Kasir Depan".
2. **Given** orders from several terminals, **When** the Sales page and its Excel export are viewed, **Then** each order row carries its terminal name and the list can be filtered by terminal.
3. **Given** a shift report (X/Z) or the shift panel, **When** it is shown, **Then** it names the terminal the shift ran on by name.
4. **Given** a terminal is renamed after it took orders, **When** an older order is opened, **Then** it shows the terminal's current name (the order stays linked to the terminal, not to a copy of the old text).
5. **Given** an old order created before this change (with or without a linked terminal), **When** it is opened, **Then** it still displays correctly: the linked terminal's name if there is one, otherwise a neutral "no terminal" label.

---

### User Story 3 - Terminal name used across the other modules (Priority: P2)

Other places that refer to a terminal use its name: the server's terminal list, activity-log entries (registered / renamed / revoked), and the floor/tab payloads. (The web app has no terminal-management screen or dashboard device panel today; this feature does not add one.)

**Why this priority**: Consistency. The primary identity (US1) and the order link (US2) already deliver the main value; this removes remaining "Tn" leftovers.

**Independent Test**: Register, rename and revoke a terminal; check the terminal list and the activity log. All reference the terminal by name and none shows a "Tn" code.

**Acceptance Scenarios**:

1. **Given** the terminal list returned by the server (and any screen that shows it), **When** it is viewed, **Then** each row is identified by its name, with outlet, last seen and status, and no code.
2. **Given** a terminal is registered or revoked, **When** the activity log is viewed, **Then** the entry names the terminal by its name.
3. **Given** an admin renames a terminal, **When** they save, **Then** the new name is used everywhere (subject to the unique-name rule), including on orders already linked to it.

---

### Edge Cases

- Two terminals in **different** outlets may share a name; uniqueness applies within one outlet (and within one DEMO/LIVE data mode).
- A terminal is revoked and a new one is registered with the same name: allowed only if the name no longer belongs to an active terminal of that outlet; the old orders stay linked to the revoked terminal record, not to the new one.
- A very long or special-character name: limited to the existing name length, trimmed of surrounding spaces; characters that would break the printed receipt line are shown safely (truncated on the narrow 58 mm receipt, never overflowing).
- Terminals already registered with codes T01, T02…: they keep working with no re-pairing; their name becomes the only visible identity; the old code is no longer shown.
- An order taken while the terminal is offline/late-synced: still linked to the terminal that took it.
- Orders created from the web (non-Android) POS, which have no terminal: show the neutral "no terminal" label rather than blank or "null".

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The terminal's name MUST be its only user-visible identity; the system MUST NOT generate, ask for, or display an automatic counter code (T01, T02, …) for a terminal.
- **FR-002**: Terminal registration MUST require a non-blank name (surrounding spaces trimmed) and nothing else to identify the terminal.
- **FR-003**: A terminal name MUST be unique among the ACTIVE (not revoked) terminals of the same outlet and data mode, compared ignoring letter case and surrounding spaces; a duplicate MUST be refused with a clear message.
- **FR-004**: An admin MUST be able to rename a terminal (server operation; no new web screen in this feature); the same uniqueness rule applies.
- **FR-005**: Every place in the Android app that currently shows a terminal code (PIN screen header, status chips, shift screens, "opened on another terminal" labels) MUST show the terminal name instead.
- **FR-006**: Every order created from a registered terminal MUST be linked to that terminal, and the order MUST show the terminal's name on: receipt (screen and print), Android order list/detail, web Sales page, and the Sales export.
- **FR-007**: The web Sales page MUST allow filtering orders by terminal.
- **FR-008**: Shift summaries and end-of-day reports MUST identify the terminal by name.
- **FR-009**: The terminal list, activity-log entries and floor/tab payloads MUST refer to a terminal by its name, never by a counter code.
- **FR-010**: Existing terminals MUST keep working without re-pairing; their stored name becomes their identity and the old code is no longer displayed.
- **FR-011**: Existing orders MUST remain valid and unchanged in money, stock and numbering; an order with no terminal MUST show a neutral label, never a blank or technical value.
- **FR-012**: New receipt numbers MUST be a plain running number per outlet with no terminal part (e.g. `000124`), unique within its outlet (two outlets may each have a `000001`; the printed receipt already names the outlet). Receipt numbers already issued (e.g. `T01-000124`) MUST NOT change. *(Decision Q1 = A, 2026-10-22.)*
- **FR-013**: The queue number (e.g. `A-024`, per outlet per business day) MUST be unchanged by this feature.

### Key Entities

- **Terminal**: A registered tablet at one outlet. Identified to people only by its **name** (unique within the outlet and data mode). Also has outlet, registration/revocation state and last-seen information. No visible counter code.
- **Order transaction**: A sale/tab. Linked to the **Terminal** that created and settled it; shows that terminal's name wherever the order is displayed, printed or exported.
- **Shift**: A cashier session opened on one Terminal; reports name that terminal.
- **Receipt number**: Unique identifier of a settled order; a plain per-outlet running number (FR-012). It no longer identifies the terminal; the order's terminal link and name do.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A new terminal can be registered by entering only a name, in under 30 seconds, with zero additional identifier fields.
- **SC-002**: In a full pass through the Android app (setup, PIN, shift open/close, tabs, payment, receipt, order list) and the web Sales page, 0 screens or printouts show a "Tn"-style terminal code.
- **SC-003**: 100% of orders created after this change from a registered terminal show that terminal's name on the receipt, Android order detail, Sales page and export.
- **SC-004**: An admin can find all orders taken on one terminal in under 15 seconds using the Sales page terminal filter.
- **SC-005**: 100% of terminals and orders existing before the change continue to open and display correctly after the upgrade, with no re-pairing and no change to previously issued receipt numbers.
- **SC-006**: 0 duplicate terminal names exist within one outlet after the change, including after renames.

## Assumptions

- "Terminal count increment (the Tn)" refers to the automatic T01/T02 code assigned at registration and displayed in the app and on receipt numbers; "terminal name" refers to the name field already typed during setup (currently defaulting to "Tablet kasir").
- Terminals are already linked to orders internally (the order records which terminal took it); this feature surfaces that link by name and uses it consistently, rather than introducing a new kind of link.
- Terminal names are unique per outlet and data mode (a new rule, since names now serve as the identity); existing terminals that already share a name inside one outlet will be made distinguishable during upgrade without losing history. ASSUMPTION: by appending a numeric suffix to the later duplicates.
- Renaming a terminal updates how it appears on past orders because orders link to the terminal, not to a text copy of its name.
- The queue number, tax, refund, approval and printing rules from earlier features are unchanged.
- Scope covers the Android POS app and the server/web views that display terminals or orders; no change to licensing, outlets, or user roles.
