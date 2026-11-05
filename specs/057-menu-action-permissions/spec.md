# Feature Specification: Per-Menu Action Permissions for Roles

**Classification**: INTERNAL

**Feature Branch**: `093-menu-action-permissions`

**Created**: 2026-11-05

**Status**: Draft

**Input**: User description: "I want to control the CRUD and action button for each menu access, for example: if I choose "Penjualan" with access is view, the user assigned with that role only can view data, other than view, user can do CUD action"

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Give a role "view only" on a menu (Priority: P1)

An owner/admin editing a role wants to choose, for each menu the role can open, what the role may do there: only look at the data (View), or also create, edit, delete and use the menu's special actions. For example, a "Finance viewer" role gets **Sales: View** and can read the Sales list and details but cannot void a sale, add or change a payment, verify a payment, or edit a Sale Reference.

**Why this priority**: Today a role either has a menu or does not; anyone who can open Sales can also change it. A safe read-only role is the core request.

**Independent Test**: Create a role with Sales = View only, assign it to a user, sign in as that user: the Sales list and detail open and show data; every create/edit/delete/action button on those screens is absent; calling the corresponding actions directly is refused.

**Acceptance Scenarios**:

1. **Given** a role with Sales = View only, **When** its user opens Sales, **Then** the list, filters, detail and viewing of references/proofs work, and no button that changes data (void, add/delete payment, change method, mark verified, edit reference, edit confirmation) is shown.
2. **Given** the same user, **When** they try such an action by any other route (a stale tab, a direct request), **Then** the system refuses it and nothing changes.
3. **Given** a role with Sales = View + Edit (no Create/Delete), **Then** edit actions appear and create/delete ones do not.
4. **Given** a role with a menu and no explicit action beyond View, **Then** the user sees data but cannot change it; "View" can never be switched off while any other action on that menu is on.

---

### User Story 2 - Choose actions per menu in the role editor (Priority: P1)

When creating or editing a role, the owner/admin sees every menu with a clear set of choices: View, Create, Edit, Delete, plus the menu's own named actions (for example for Sales: Void, Record payment, Verify payment, Export; for Pre-orders: Duplicate, Split, Change status…). They can set them one by one or use a "full access" / "view only" shortcut per menu.

**Why this priority**: Without a usable editor the rule cannot be configured.

**Independent Test**: Open the role editor, tick Sales → "View only", save, reopen: the role shows exactly that. Switch it to "Full access": all choices are on.

**Acceptance Scenarios**:

1. **Given** the role editor, **Then** each menu lists its available actions with plain labels in both languages; a menu with no meaningful create/edit/delete (e.g. Dashboard) shows only View.
2. **Given** a menu is not selected for the role, **Then** none of its actions can be selected and the role cannot open it.
3. **Given** the owner/admin saves, **Then** the choices are stored and take effect for the role's users on their next request (no re-login required for the server to enforce; screens update on next load).
4. **Given** the reserved menus (Users, Roles) protected today, **Then** the existing protection still holds: at least one role must keep full access to both.

---

### User Story 3 - Buttons and screens follow the role (Priority: P1)

A user sees only the buttons, menu items and row actions their role allows. Anything not allowed is hidden (not just disabled), so a cashier with view-only on Sales is not tempted by actions that would fail.

**Independent Test**: Sign in as users of roles with different action sets and compare the visible buttons per screen to the role's choices.

**Acceptance Scenarios**:

1. **Given** a role without Create on a menu, **Then** that menu's "New/Add/Import" buttons are not shown.
2. **Given** a role without Edit, **Then** edit forms cannot be opened and inline edit controls are not shown.
3. **Given** a role without Delete, **Then** delete buttons and bulk-delete choices are not shown.
4. **Given** a role without a named action (e.g. Sales → Export), **Then** that button is not shown and the action is refused if attempted directly.
5. **Given** a hidden action that is the only thing on a screen toolbar, **Then** the toolbar collapses cleanly without empty gaps.

---

### User Story 4 - Existing roles keep working exactly as today (Priority: P1)

After this feature is introduced, every existing role (including the built-in Owner, Admin, Cashier, Inventory and any custom role) behaves exactly as before until an owner/admin changes it: each menu a role already has is granted with full access.

**Why this priority**: A permission change that silently locks cashiers out mid-event would be a serious regression.

**Independent Test**: Before/after comparison of what each seeded user can see and do on every screen: identical.

**Acceptance Scenarios**:

1. **Given** roles saved before this feature, **When** the system is upgraded, **Then** every menu they had keeps full access and no user loses or gains any ability.
2. **Given** a role created or imported in the old format (menus only), **Then** it is read as full access for those menus.
3. **Given** the role import/export workbook, **Then** it round-trips the new choices and still accepts the old menu-only format.

---

### User Story 5 - New actions never widen what was already restricted (Priority: P2)

Existing business rules stay in force: for instance only the right people may delete payments, only a payment's non-recorder may verify it, cost/margin are visible only with report access, and cash correction rules remain. A role's action choice is an **additional** gate: granting "Delete" on Sales to a role does not let that role do something the current rules reserve for owner/admin.

**Independent Test**: Give a custom role every action on Sales and confirm it still cannot do the actions that are owner/admin-only today.

**Acceptance Scenarios**:

1. **Given** a role with every Sales action, **When** its user attempts an action that today is reserved to owner/admin or to the payment's recorder, **Then** it is still refused for the same reasons.
2. **Given** a role with an action off, **When** the user would otherwise pass the existing rules, **Then** the action is refused (the new gate narrows, never widens).

---

### Edge Cases

- A user with a role lacking an action sees a stale button (screen loaded before the role changed): the action is refused with a clear "not allowed" message and the screen refreshes its permissions.
- A role is edited while its users are signed in: server enforcement applies on the next request; the screen picks up the change on next load/refresh.
- A role grants an action but not View (impossible by rule): the editor prevents it; imported data that contains it is rejected with a row-level message.
- Menus whose actions are not about data (Dashboard, Reports viewing) only have View; Reports "export" style actions are named actions.
- The Cashier POS screen: creating a sale is its Create action; a role with POS = View only can open the POS but cannot complete a sale (cashier-session rules unchanged).
- Bulk actions that include items the user may not act on: the permitted ones follow the existing skip-with-reason behaviour; the new gate refuses the whole bulk action if the role lacks that action.
- Activity log: refused attempts are not logged as changes; changes made by permitted users are logged exactly as today.
- Deleting or renaming a menu action in a future release: unknown action keys stored on a role are ignored, never granted.
- Licensing and DEMO/LIVE behaviour are unaffected.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: For every menu a role includes, the role MUST store which actions its users may perform there. The standard actions are View, Create, Edit and Delete; a menu MAY add its own named actions (e.g. Void, Record payment, Verify, Export, Duplicate, Split, Print).
- **FR-002**: View MUST be required for any other action on the same menu; a menu not included in the role grants nothing.
- **FR-003**: The server MUST enforce every action choice on every request that performs it, independent of what the screen shows; a refused action MUST change nothing and answer with the existing "not allowed" convention.
- **FR-004**: The interface MUST show only the buttons, menu items, row actions, bulk actions and forms the signed-in user's role allows, and MUST get that information from the server (never infer it from the role's name).
- **FR-005**: The role editor MUST let the owner/admin set the actions per menu individually and offer per-menu "View only" and "Full access" shortcuts; labels MUST exist in both supported languages.
- **FR-006**: Every role that exists before this feature MUST be migrated to full access on each menu it already has, with no change in any user's abilities.
- **FR-007**: Roles created in the old menu-only form (including through role import) MUST be read as full access for those menus.
- **FR-008**: The role import/export MUST carry the action choices and MUST keep accepting the old format.
- **FR-009**: An action permission MUST be an additional gate on top of existing rules (role-name rules such as owner/admin-only actions, recorder/own-record rules, cost/margin visibility, session rules); it MUST NOT grant anything those rules deny.
- **FR-010**: The protection of the reserved menus (Users, Roles) MUST be preserved: the store must always keep at least one role with full access to both, and a role cannot be edited so that this ceases to hold.
- **FR-011**: Changing a role's actions MUST be recorded in the activity log (who, role, what changed), and MUST only be possible for users who may manage roles.
- **FR-012**: Users who lack an action MUST get a clear, translated message when they somehow attempt it, and their screen MUST refresh its permissions.
- **FR-013**: The built-in Owner role MUST always have full access to every menu and action, regardless of stored choices, so the installation can never lock itself out.
- **FR-014**: Cost/margin, customer contact details and other data exposed only to certain roles MUST NOT be exposed by granting View on a menu; this feature adds no new data exposure.
- **FR-015**: The behaviour MUST be identical in DEMO and LIVE modes and MUST NOT depend on the installation's licence tier.

### Key Entities

- **Role**: A named set of menu access with, per menu, the set of permitted actions.
- **Menu action**: A named capability inside a menu (View, Create, Edit, Delete, or a menu-specific action such as Void or Export); View is the prerequisite for the others.
- **Permission set (of a user)**: The union result for the signed-in user's role: for each menu, which actions are allowed; the single source the server enforces and the interface reads.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An owner/admin can create a read-only role for a menu (e.g. Sales = View) in under 1 minute from opening the role editor.
- **SC-002**: For a role with Sales = View only, 100% of data-changing actions on the Sales screens are absent from the interface and 100% of direct attempts are refused with no data change.
- **SC-003**: After the upgrade, 0 users lose or gain any ability: a before/after check of every seeded role on every screen shows identical results.
- **SC-004**: For every menu and action defined, a role without it cannot perform it: verified by an automated check per action (allowed role succeeds, role without the action is refused).
- **SC-005**: A role granted every action on a menu still cannot perform any action that is reserved today to owner/admin or to a record's owner (0 widened permissions).
- **SC-006**: A role change takes effect for enforcement on the very next request of its users.
- **SC-007**: The role editor shows all choices and labels in both Indonesian and English with no untranslated text.

## Assumptions

- "Penjualan" is the Sales menu; "CUD" means Create, Update (Edit) and Delete. The same model applies to every menu in the product.
- The standard action set is View / Create / Edit / Delete; extra named actions are defined per menu from the buttons that exist today (e.g. Sales: Void, Record/delete payment, Change payment method, Verify payment, Edit reference/confirmation, Export, Download receipts). The exact list per menu is settled at planning from the existing screens; each existing button maps to exactly one action.
- Hiding (not disabling) unauthorised controls is the intended user experience.
- Existing roles migrate to full access (FR-006); tightening is then done deliberately by the owner/admin.
- The built-in Owner is always full access (FR-013); the other built-in roles (Admin, Cashier, Inventory) keep today's menus with full access until changed.
- The new permission is an extra gate that never widens existing rules (FR-009), because several actions have ownership or separation-of-duties rules that a simple checkbox must not bypass.
- No new menus are introduced; the menu list stays the one defined today.
- No per-record or per-field permissions (e.g. "only own sales"), no time-limited access, and no approval workflow are included.
- Activity-log entries use the existing log; no new reporting screen is part of this feature.
