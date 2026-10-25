# Feature Specification: Set User PIN from the Web Users Screen

**Classification**: INTERNAL

**Feature Branch**: `074-set-user-pin-web`

**Created**: 2026-10-26

**Status**: Draft

**Input**: User description: "on the web Users screen, let an owner or admin set a PIN for a user (cashier/inventory) who needs to sign in on the Android app: set a new 6-digit PIN for a user who has none, and reset/change an existing PIN, without calling the API by hand. Show on the user list whether a user has a PIN. The user can still change their own PIN."

## Clarifications

### Session 2026-10-26

- Q: Who chooses the PIN? → A: Option C — the admin may type a 6-digit PIN, or press "Generate" to get a random 6-digit PIN that is shown once.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Set a PIN for a user who has none (Priority: P1)

An owner or admin opens the Users screen, picks a cashier who has just been added, and chooses "Set PIN". They either type a 6-digit PIN for that person, or press "Generate" to get a random one shown once. From that moment the cashier can pick their name on the Android app and sign in with the PIN.

**Why this priority**: Today a PIN can only be set by calling the system by hand, and only the user themselves can set the first one (with their own password). Without this, no new cashier can use the Android app after the outlet → terminal → PIN flow, so this is the unblocking piece.

**Independent Test**: As an admin, create a cashier, set a PIN from the Users screen, then sign in as that cashier on the Android app with that PIN.

**Acceptance Scenarios**:

1. **Given** a user with no PIN, **When** an owner or admin chooses "Set PIN" for that user and submits a valid 6-digit PIN, **Then** the PIN is saved, the list shows the user as having a PIN, and a success message appears.
2. **Given** the PIN was just set, **When** that user selects their name on the Android app for the right outlet and enters the PIN, **Then** they sign in.
3. **Given** the PIN dialog, **When** the PIN is not exactly 6 digits or is weak (all the same digit, or a straight run up or down), **Then** nothing is saved and a clear message explains why.
4. **Given** the PIN dialog, **When** the admin presses "Generate", **Then** a random valid 6-digit PIN is produced and shown to the admin once, and is saved only when they confirm.
5. **Given** a generated PIN was saved, **When** the admin closes the dialog, **Then** the PIN cannot be shown again anywhere; to get a new one they set or generate again.
6. **Given** a user who is not an owner or admin, **When** they open the Users screen (if their role allows opening it), **Then** no "Set PIN" action is shown to them.

---

### User Story 2 - See who has a PIN (Priority: P1)

On the Users list, each user shows whether they have a PIN: "Has PIN", "No PIN", and, when their PIN is temporarily locked after too many wrong attempts, "Locked". The list can be narrowed to users who have no PIN.

**Why this priority**: It is how an admin finds who still needs a PIN, and why someone cannot sign in on a tablet.

**Independent Test**: With one user that has a PIN, one without, and one locked, open the Users list and confirm each shows the right status; filter to "No PIN" and confirm only the one without a PIN remains.

**Acceptance Scenarios**:

1. **Given** users with and without PINs, **When** the Users list loads, **Then** every row shows its PIN status.
2. **Given** a user locked after 5 wrong PINs, **When** the list is viewed, **Then** that row shows "Locked" until the lock ends.
3. **Given** the PIN status filter set to "No PIN", **When** applied, **Then** only users without a PIN are listed.
4. **Given** any user, **When** the list or any screen is viewed, **Then** the PIN value itself is never shown.

---

### User Story 3 - Change or reset an existing PIN (Priority: P2)

When a cashier forgets their PIN or it is locked, an owner or admin chooses "Reset PIN" for that user and sets a new one in one step. The old PIN stops working, any lock is cleared, and the user's signed-in Android sessions end.

**Why this priority**: Recovery from a forgotten or locked PIN; less frequent than the first setup, and the system can work without it for a while.

**Independent Test**: For a user with a PIN, locked after wrong attempts, reset the PIN from the Users screen and confirm the old PIN is refused, the new PIN works and the lock is gone.

**Acceptance Scenarios**:

1. **Given** a user with a PIN, **When** an admin sets a new PIN, **Then** the old PIN is refused and the new one is accepted.
2. **Given** a user locked after wrong attempts, **When** an admin sets a new PIN, **Then** the lock is cleared and the user can sign in at once.
3. **Given** a user signed in on a tablet, **When** an admin sets a new PIN for them, **Then** that sign-in ends and they must sign in again with the new PIN.
4. **Given** a user who prefers to set their own PIN, **When** they change it themselves as they can today, **Then** that keeps working unchanged.

---

### Edge Cases

- The admin sets a PIN for their own account: allowed, same rules.
- Owner and admin accounts do not appear on the Android sign-in list (feature 073) but their PIN is still used when they approve an action at a tablet, so the action is offered for them too. [ASSUMPTION]
- An inactive user: the action is still available (the PIN is prepared for when they are reactivated) but they cannot sign in until reactivated. [ASSUMPTION]
- Two admins set a PIN for the same user at nearly the same time: the last save wins and the user sees one working PIN.
- A save that fails (network, validation) leaves the previous PIN exactly as it was.
- The same PIN chosen for several users is allowed; PINs are not required to be unique across users. [ASSUMPTION]
- A generated PIN is never weak and is shown only in the dialog where it was generated; closing the dialog without saving discards it and nothing changes.
- A generated PIN that was saved but not written down is not recoverable; the admin sets or generates a new one.
- Setting a PIN does not change the user's password, role or outlets.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The Users screen MUST show, for each user, whether they have a PIN, and whether it is currently locked.
- **FR-002**: The Users list MUST offer a filter to show only users without a PIN.
- **FR-003**: Owners and admins MUST be able to set a PIN for any user from the Users screen, whether the user has none or already has one ("Set PIN" / "Reset PIN"), without any manual API call.
- **FR-004**: The action MUST NOT be shown to anyone who is not an owner or admin, and the server MUST refuse it from anyone else.
- **FR-005**: A PIN MUST be exactly 6 digits and MUST NOT be weak (all digits the same, or a straight ascending/descending run); otherwise it is refused with a clear message and nothing changes.
- **FR-006**: The admin MUST be able to either type the PIN, or ask the system to generate a random one. A generated PIN MUST be a valid (non-weak) 6-digit PIN, MUST be shown to the admin once before saving and not again after the dialog closes, and MUST be saved only when the admin confirms.
- **FR-007**: Setting a PIN MUST clear any lock and failed-attempt count on that user, and MUST end that user's existing PIN sign-in sessions.
- **FR-008**: The PIN value MUST never be shown after the setting dialog closes (a generated PIN is shown once, only in that dialog), never appear in lists, logs or activity history, and never be retrievable by anyone.
- **FR-009**: Every set or reset MUST be recorded in the activity log with who did it, for whom, when, whether it was a first set or a reset, and whether the PIN was typed or generated — without the PIN value — in the same step as the change.
- **FR-010**: A user MUST still be able to change their own PIN as today; this feature does not remove or alter that.
- **FR-011**: Success and error messages MUST be shown in Indonesian, using the app's existing error conventions; a failed save MUST leave the previous PIN unchanged.
- **FR-012**: The new action MUST work for users of every outlet; the user's password, role and outlet assignment MUST be unaffected.

### Key Entities

- **User**: A person with a role and, optionally, a 6-digit PIN used to sign in on Android and to approve actions. Attributes relevant here: has-PIN, locked-until, failed-attempt count. The PIN is stored only in a form that cannot be read back.
- **PIN set/reset event**: An activity-log entry: actor, target user, time, first-set or reset, and whether the PIN was typed or generated (never the PIN itself).

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An admin can give a new cashier a working PIN in under 30 seconds from opening the Users screen.
- **SC-002**: A cashier can sign in on the Android app within 1 minute of their PIN being set, with no other step.
- **SC-003**: 100% of users without a PIN can be found on the Users screen in one action (the "No PIN" filter).
- **SC-004**: In 100% of tested cases the PIN value is absent from every list, response, log and activity entry produced by this feature.
- **SC-005**: 0 manual API calls are needed to onboard a cashier for the Android app (account, outlet, PIN all from the web).
- **SC-006**: After a reset, 100% of the previous PIN's sign-ins are refused and any lock is gone.
- **SC-007**: A generated PIN is valid on first use in 100% of cases (never refused as weak or malformed).

## Assumptions

- **Builds on 073**: the Android app signs in cashier and inventory staff by name + PIN; owner and admin are not on that list.
- The existing PIN rules (6 digits, weak PINs refused, 5 wrong attempts lock the user for 5 minutes) stay as they are.
- The Android app has no screen for changing a PIN, so a PIN set by an admin stays valid until changed; there is no forced change at first sign-in and no PIN expiry. [ASSUMPTION]
- No extra confirmation of the admin's own password is required to set another user's PIN; the activity log is the safeguard. [ASSUMPTION]
- Only owners and admins (those who can open Settings) may set PINs, as the existing PIN-reset permission already works; having the Users menu alone is not enough.
- A web screen for users to change their own PIN is out of scope (they keep the existing way).
- Existing PINs are not changed or invalidated by this feature.
