# Feature Specification: Owner Reopens a Closed Shift

**Classification**: INTERNAL

**Feature Branch**: `085-reopen-closed-shift` (spec dir number ≠ branch number)

**Created**: 2026-11-06

**Status**: Draft

**Input**: User description: "user with role owner can reopen closed shift to make adjustment"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Owner reopens a closed shift (Priority: P1)

A cashier closed their shift with a wrong cash count, or a payment was missed or mis-categorised after closing. Today a closed shift is final: nobody can correct it. The Owner opens the shift's detail (Session History) and chooses "Reopen shift", gives a reason, and the shift becomes open again so it can be corrected.

**Why this priority**: Without it, a mistaken close can never be fixed. This is the whole feature.

**Independent Test**: Close a shift as a cashier; as Owner reopen it with a reason; confirm the shift is open again and the audit trail shows who reopened it, when, and why.

**Acceptance Scenarios**:

1. **Given** a closed shift, **When** the Owner reopens it with a reason, **Then** its status becomes open, closing time and closing figures are cleared from the active view, and the action is recorded (who, when, reason, the previous closing cash, expected cash and difference).
2. **Given** a closed shift, **When** a user who is not the Owner (admin, cashier, inventory, or the shift's own cashier) tries to reopen it, **Then** the request is refused with a permission error and nothing changes.
3. **Given** an open shift, **When** the Owner tries to reopen it, **Then** it is refused as a conflict ("already open").
4. **Given** a reopen without a reason, **When** submitted, **Then** it is rejected and the shift stays closed.

---

### User Story 2 - Adjust and re-close with correct figures (Priority: P1)

After reopening, the adjustment is made and the shift is closed again. The closing count is entered afresh and the expected cash is recomputed from the shift's payments at that moment, so the cash difference reflects the correction.

**Why this priority**: A reopen with no trustworthy re-close would leave stale reconciliation figures.

**Independent Test**: Reopen a shift, record or correct a cash payment attributed to it, close it with a new count, and confirm expected cash and difference match the corrected data.

**Acceptance Scenarios**:

1. **Given** a reopened shift, **When** a cash payment is added or its method corrected so that it belongs to this shift, **Then** it counts in this shift's expected cash like any open shift.
2. **Given** a reopened shift, **When** it is closed again with a new closing count, **Then** expected cash and difference are recomputed from current data and replace the earlier figures; the earlier figures remain visible in the audit trail.
3. **Given** a reopened shift left open, **When** the cashier or Owner views Session History, **Then** it appears as open and is marked as "reopened" with the reopen details.

---

### User Story 3 - Visibility and audit of reopened shifts (Priority: P2)

Anyone reviewing a shift (Owner/admin in Session History, the cashier for their own) can see that it was reopened, by whom, when, and why, and what the figures were before.

**Why this priority**: Reopening money records must be accountable, but the correction itself works without the display.

**Independent Test**: Reopen and re-close a shift; open its detail and confirm the history lists each reopen with actor, time, reason and prior figures.

**Acceptance Scenarios**:

1. **Given** a shift reopened one or more times, **When** its detail is opened, **Then** each reopen is listed with actor, time, reason and the figures before that reopen.
2. **Given** a reopen, **When** the activity log is reviewed, **Then** a corresponding entry exists.

---

### Edge Cases

- The shift's cashier already has another open shift: reopening is refused (one open shift per user), with a clear reason.
- The shift belongs to an event that is no longer active or whose results are finalised: reopening is still allowed (FR-009); recaps and settlements are untouched.
- Two people (Owner and cashier) act on the same shift at once: only one reopen succeeds; a repeat is refused as already open.
- A shift is reopened several times: each reopen is recorded; none is lost.
- A cash payment taken while the shift was closed was attributed to a different (later) shift: it stays where it was recorded; reopening does not move money between shifts.
- A restricted Owner-like role (role lacking the Owner role) must not gain the power through role-action settings.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: Only a user with the Owner role MUST be able to reopen a closed shift. Every other role, including admin and the shift's own cashier, is refused.
- **FR-002**: Reopening MUST require a written reason; a missing or blank reason is rejected.
- **FR-003**: Only a closed shift can be reopened; reopening an open shift is refused as a conflict.
- **FR-004**: Reopening MUST be refused if the shift's cashier already has another open shift.
- **FR-005**: A reopened shift MUST behave as an open shift: it can receive cash payments, be summarised, and be closed again by its cashier or an owner/admin.
- **FR-006**: Closing a reopened shift MUST recompute expected cash and cash difference from current data and store them in place of the earlier closing figures.
- **FR-007**: Each reopen MUST be recorded permanently with who, when, the reason, and the closing cash, expected cash and difference as they were just before the reopen; this record MUST NOT be altered or removed by later closes or reopens.
- **FR-008**: Reopening MUST NOT change any order, payment, stock, report or settlement data by itself; only later, separately authorised corrections do.
- **FR-009**: The Owner MUST be able to reopen a shift regardless of its event's status (active, ended or results finalised). Because reopening never changes seller recaps, reports or settlements (FR-008), finalised results stay as they are until separately corrected.
- **FR-010**: The Session History screen MUST show a "Reopen shift" action only to the Owner and only for closed shifts, and MUST mark reopened shifts with their reopen details. The server enforces the rule regardless of what the screen shows.
- **FR-011**: A refused reopen (wrong role, wrong state, no reason, other open shift) MUST leave the shift exactly as it was.

### Key Entities

- **Cashier shift**: opening cash, closing cash, expected cash, difference, status (open/closed), event, cashier. Gains the ability to go from closed back to open.
- **Shift reopen record**: one per reopen; who, when, reason, and the closing figures before the reopen. Append-only.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An Owner can reopen a wrongly closed shift in under 1 minute from opening Session History.
- **SC-002**: 100% of reopen attempts by non-Owner users are refused, with no change to the shift.
- **SC-003**: 100% of reopens leave a complete record (actor, time, reason, prior figures) retrievable afterward.
- **SC-004**: After a reopen, correction and re-close, the shift's expected cash equals the sum of its recorded cash movements at the moment of re-closing in every tested case.
- **SC-005**: Cashiers and admins see no change in any existing shift behaviour (open, close, history) when no reopen is used.

## Assumptions

- "Owner" means the system Owner role, not any user holding the settings menu; the Owner is always allowed.
- The "adjustment" is made with existing tools (record or correct a cash payment, re-enter the closing count) once the shift is open; this feature adds no new adjustment screen.
- Money already received in another shift is not moved between shifts.
- A shift may be reopened any number of times, each with its own reason.
- The original cashier remains the shift's owner after a reopen.
- Reopening is a deliberate, logged exception to the earlier rule that a closed shift's stored figures are never rewritten.
