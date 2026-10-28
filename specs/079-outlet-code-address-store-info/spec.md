# Feature Specification: Outlet Identity (Editable Code, Address, Per-Outlet Store Information) and Web Terminal Management

**Classification**: INTERNAL

**Feature Branch**: `079-outlet-code-address-store-info`

**Created**: 2026-10-27

**Status**: Draft

**Input**: User description: "update outlet code to 5 letters and numbers [New outlet form, 'Code (3 letters)'] and can be edited [Edit outlet form, code field locked, 'Permanent once created — becomes part of the SKU']. add outlet address. change every store information to use each selected outlet information. for example in receipt, when user choose specific outlet, then the store information will use that selected outlet information (outlet name, phone, email, notes) in web and app. In the web, add terminal CRUD management with filter."

## Findings from the supplied evidence

- **New outlet form**: the code field is labelled "Code (3 letters)" and the help text says "Permanent once created — becomes part of the SKU". Outlet fields today: code, name, phone, email, notes, active.
- **Edit outlet form**: the code field is shown greyed out and cannot be changed; there is no address field.
- **Today's behaviour (from the code, not the screenshots)**: store name, address, contact person/phone/email, logo and receipt footer come from one global store setting, so every outlet's receipt shows the same store identity. The outlet code is exactly 3 letters, is part of every product code and SKU, and is used to refer to outlets in spreadsheet imports and exports.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Each outlet has its own address and contact details (Priority: P1)

An owner or admin records, for every outlet, its name, address, phone, email and notes. These are the details customers should see for that outlet. Address is new; the other four already exist and keep working.

**Why this priority**: Everything else in this feature shows outlet details to customers or staff; without an address field and complete outlet records there is nothing to show.

**Independent Test**: Create an outlet with an address, phone, email and notes, save, reopen it, and see all values unchanged. Edit the address and see the new value after saving.

**Acceptance Scenarios**:

1. **Given** the New outlet form, **When** the owner fills code, name, address, phone, email and notes and saves, **Then** the outlet is created and all values are shown when it is reopened.
2. **Given** an existing outlet without an address, **When** the owner opens Edit outlet, **Then** an empty Address field is available and can be filled and saved.
3. **Given** the outlets list, **When** an outlet has an address, **Then** the address (or a shortened form) is visible in the list or its detail so outlets can be told apart.
4. **Given** an address longer than the field allows, **When** the owner saves, **Then** a clear message states the limit and nothing is lost.
5. **Given** a cashier or other non-admin role, **When** they try to create or edit an outlet, **Then** it is refused as it is today.

---

### User Story 2 - Receipts and documents show the outlet's own store information (Priority: P1)

A receipt (printed, downloaded as image or PDF, reprinted) and any other customer-facing document shows the identity of the outlet where the sale happened: outlet name, address, phone, email and notes — instead of one global store identity. The owner sets the shared logo, receipt footer and brand once; the rest follows the outlet.

**Why this priority**: This is the headline request: a customer of outlet A must not see outlet B's details, and a single-store identity cannot serve several outlets.

**Independent Test**: With two outlets that have different names, addresses, phones, emails and notes, complete a sale in each and print/view both receipts: each shows only its own outlet's details. Reprint after editing the outlet: the reprint shows the edited details.

**Acceptance Scenarios**:

1. **Given** a sale made in outlet A, **When** its receipt is printed or viewed, **Then** it shows outlet A's name, address, phone, email and notes in the store-information block.
2. **Given** two outlets with different details, **When** receipts of both are produced, **Then** neither shows the other's details.
3. **Given** the web user has selected outlet B in the outlet picker, **When** they look for a sale made in outlet A, **Then** that sale is not reachable under outlet B (as with every outlet-scoped record), so no receipt can ever carry outlet B's details for outlet A's sale; with outlet A or All outlets selected, the receipt shows outlet A's details (the receipt belongs to where the sale happened, not to what the user is browsing).
4. **Given** an outlet field is empty (for example no phone), **When** the receipt is produced, **Then** that line is simply omitted, with no blank label and no wrong substitute from another outlet.
5. **Given** an installation with a single outlet, **When** a receipt is produced, **Then** it shows that outlet's details and looks no worse than today.
6. **Given** the owner edits an outlet's address after a sale, **When** the old sale's receipt is reprinted, **Then** it shows the current outlet details (receipts are not frozen snapshots of identity).
7. **Given** the shared logo, receipt footer text and brand name, **When** receipts are produced for any outlet, **Then** they are still taken from the shared settings.

---

### User Story 3 - Outlet code is 3–5 letters and digits and can be edited (Priority: P2)

The owner can give an outlet a code of up to five characters using letters and digits (for example `WJM2` or `KD001`) and can change it later. Codes stay unique and are shown in uppercase. Existing 3-letter codes remain valid and unchanged.

**Why this priority**: It was requested explicitly, but outlets work today with their current codes, and changing a code touches product codes and spreadsheets, so it is the riskier change.

**Independent Test**: Create an outlet with a 5-character alphanumeric code and see it saved in uppercase; edit an existing outlet's code and see the list, filters and new products use the new code; try a duplicate, a 6-character code and a code with a symbol and see each refused with a clear message.

**Acceptance Scenarios**:

1. **Given** the New outlet form, **When** a code of 3 to 5 characters made of letters and digits is entered, **Then** it is accepted and stored in uppercase.
2. **Given** a code with a symbol or space, fewer than 3 or more than 5 characters, **When** saved, **Then** it is refused with a message that states the rule.
3. **Given** a code already used by another outlet (ignoring letter case), **When** saved, **Then** it is refused as a duplicate.
4. **Given** an existing outlet, **When** the owner edits its code to a valid unused value and saves, **Then** the new code appears everywhere outlets are listed or filtered.
5. **Given** products and SKUs created before the change, **When** the outlet code is edited, **Then** their stored product codes and SKUs do not change.
6. **Given** a product created after the code was edited, **When** its code is generated, **Then** it uses the outlet's new code.
7. **Given** a spreadsheet import or export that refers to outlets by code, **When** the code was edited, **Then** exports show the new code and an import file still carrying the old code is rejected with a message that names the unknown code (it is not silently matched to the wrong outlet).
8. **Given** the New/Edit forms, **When** shown, **Then** the label and help text describe the real rule ("3–5 letters or digits", not "3 letters") and no longer say the code is permanent.
9. **Given** an outlet has been deleted, **When** another outlet is given its code, **Then** it is accepted, because uniqueness is only checked against outlets that still exist (as today).

---

### User Story 4 - Screens show the selected outlet's identity (Priority: P2)

Wherever the apps show "the store" — the header and sidebar of the web app, the tablet's PIN screen and header, store details in settings-driven screens — they show the identity of the outlet the user is working in: the outlet chosen in the outlet picker on the web, or the outlet the tablet is set up for. With "All outlets" selected, the shared store identity is shown.

**Why this priority**: It completes "every store information" but is cosmetic compared with receipts.

**Independent Test**: On the web, switch the outlet picker between outlet A, outlet B and All outlets and see the store name shown in the header follow it. On a tablet set up for outlet A, see outlet A's name on the PIN screen and header.

**Acceptance Scenarios**:

1. **Given** the web outlet picker set to one outlet, **When** a page shows store information, **Then** it shows that outlet's name (and address/phone/email/notes where such details are shown).
2. **Given** the picker set to All outlets, **When** store information is shown, **Then** the shared store identity is shown.
3. **Given** a tablet set up for an outlet, **When** its PIN screen and header show the store, **Then** they show that outlet's name.
4. **Given** an outlet has no value for a detail, **When** that detail would be shown, **Then** the shared store value is used on screens (receipts omit it, see Story 2).
5. **Given** the owner renames an outlet, **When** screens refresh, **Then** the new name appears without any other change.

---

### User Story 5 - Manage terminals from the web, with filters (Priority: P2)

An owner or admin opens a Terminals page in the web app and sees every terminal (tablet or device registered to sell) across the outlets they may manage. They can filter the list, add a terminal to an outlet, rename it, move it to another outlet, deactivate (revoke) it and, where nothing depends on it, delete it — without needing the tablet in hand. Today terminals can only be registered from the tablet setup flow.

**Why this priority**: Terminals are created by whoever sets up a tablet and there is no place on the web to review, fix a typo, or retire one; with several outlets this becomes unmanageable. It is independent of the outlet-identity work.

**Independent Test**: With terminals in two outlets, open the page, filter by outlet, by status and by name, add a terminal, rename it, deactivate it, and confirm each change is reflected in the list and that a deactivated terminal's tablet is refused at its next request.

**Acceptance Scenarios**:

1. **Given** terminals exist in several outlets, **When** the owner opens the Terminals page, **Then** each terminal is listed with its name, outlet, status (active or deactivated), and when it was last seen.
2. **Given** the list, **When** the owner filters by outlet (one or several), by status, or by a name search, **Then** only matching terminals are shown, filters combine, and the total shown matches.
3. **Given** the Add terminal form, **When** the owner picks an outlet and enters a name, **Then** the terminal is created under that outlet and appears in the list and on that outlet's tablet setup.
4. **Given** a name already used by another active terminal in the same outlet (ignoring letter case and surrounding spaces), **When** saved, **Then** it is refused with a clear message; the same name in a different outlet is allowed.
5. **Given** an existing terminal, **When** the owner renames it, **Then** the new name shows in the list, on the tablet, and on documents that show the terminal.
6. **Given** an existing terminal with no open shift, **When** the owner moves it to another outlet, **Then** it belongs to the new outlet from then on and earlier sales and shifts keep the outlet they were made in; **Given** the terminal has an open shift, **Then** the move is refused with a message saying why.
7. **Given** an active terminal, **When** the owner deactivates it, **Then** its tablet can no longer sign in or send orders and the terminal shows as deactivated; **When** reactivated, **Then** it works again.
8. **Given** a terminal that has sales, shifts or receipts attached, **When** the owner tries to delete it, **Then** deletion is refused and deactivation is offered instead; a terminal with no history can be deleted after confirmation.
9. **Given** an outlet-assigned admin (not owner), **When** they open the page, **Then** they see and manage only terminals of their own outlets; a cashier or inventory role cannot open or use the page or its actions.
10. **Given** every change above, **When** it is made, **Then** an activity-log entry records who did what to which terminal.
11. **Given** no terminal matches the filters, **When** the list is shown, **Then** an empty state offers to clear the filters.
12. **Given** the terminal list, **When** the owner ticks one or more rows (or the select-all box for the rows on the page), **Then** a bulk-action bar shows how many are selected and offers Activate, Deactivate and Delete; with nothing ticked the bar is hidden.
13. **Given** several selected terminals, **When** the owner chooses Deactivate (or Activate) and confirms, **Then** every selected terminal that is not already in that state changes, the list refreshes, and a summary says how many changed and how many were skipped (already in that state).
14. **Given** several selected terminals of which some have sales, shifts or receipts attached, **When** the owner confirms Delete, **Then** the terminals with no history are deleted, the others are left untouched, and the summary lists each refused terminal by name with the reason and points to Deactivate.
15. **Given** a bulk action in which some terminals cannot be changed, **When** it finishes, **Then** the ones that could be changed stay changed (the action is not all-or-nothing), the failed ones remain selected so the owner can see which, and nothing outside the selection is touched.
16. **Given** an outlet-assigned admin, **When** they select terminals and run a bulk action, **Then** terminals of outlets they do not manage are never changed, even if their IDs are sent directly.

---

### Edge Cases

- Terminal and outlet interplay: an outlet that is deactivated or deleted cannot receive new terminals, and its terminals are not offered as move targets or in the filter's default view.
- A terminal is renamed or deactivated while its tablet is mid-sale: the open order is not lost; the tablet is refused at its next request with the same message a revoked terminal shows today.
- Bulk selection and filters: changing a filter, search or page clears the selection, so an action never applies to rows the owner can no longer see; select-all covers only the rows on the current page, not every match across pages.
- A terminal in the selection is deleted, moved or changed by someone else between selecting and confirming: it is reported as skipped or failed for that terminal only; the rest of the batch still runs.
- Bulk action with a very large selection: the page size caps the selection, so a single request stays within one page of terminals.
- A user is assigned to a single outlet and has no picker: that outlet is their selection.
- An outlet with all optional fields empty: receipts show just the outlet name and shared logo/footer; nothing breaks.
- DEMO and LIVE modes: outlets are separate per mode, so each mode's receipts use that mode's outlet details; switching mode never mixes them.
- Two outlets editing codes at the same time to the same value: the second save is refused as a duplicate.
- Swapping the codes of two outlets: not possible directly (the second change would clash); the owner uses a temporary code.
- An outlet's code is edited while a product-creation form for that outlet is open: the product uses whatever code is current when it is saved.
- Very long names or addresses on a 58 mm thermal receipt: they wrap within the paper width and no text is lost.
- Outlet notes are free text and may be long: receipts print them wrapped; the owner is told on the form that notes appear on receipts.
- Outlet phone/email are business contact details of the outlet, not customer data; they are the only contact lines a receipt prints (customer phone and email are never printed).
- An order made before this feature, in an outlet that has no details yet: its receipt falls back to the shared store identity for any missing detail until the owner fills the outlet in, so existing receipts do not lose their address overnight.

## Requirements *(mandatory)*

### Functional Requirements

**Outlet record**

- **FR-001**: An outlet MUST have an address field (free text, optional) in addition to code, name, phone, email, notes and active.
- **FR-002**: Owners and admins MUST be able to create and edit all outlet fields, including the address, and see them again on reopening; other roles MUST remain unable to.
- **FR-003**: The outlets list or detail MUST make the address visible.

**Code**

- **FR-004**: The outlet code MUST accept 3 to 5 characters consisting only of letters and digits, stored in uppercase, unique across outlets regardless of letter case; existing 3-letter codes remain valid.
- **FR-005**: The outlet code MUST be editable after creation by owners and admins, subject to the same rules as creation.
- **FR-006**: Editing a code MUST NOT change any stored product code or SKU created earlier; products created afterwards MUST use the outlet's current code.
- **FR-007**: Spreadsheet exports MUST show current outlet codes; an import that names an outlet code that does not exist MUST be rejected with a message naming that code and MUST NOT be matched to a different outlet.
- **FR-008**: The New/Edit outlet forms MUST describe the actual rule and MUST no longer state that the code is permanent.

**Store information per outlet**

- **FR-009**: Receipts (printed, image, PDF, reprints, and the receipt shown on the tablet) MUST show the store-information block (name, address, phone, email, notes) of the outlet where the sale happened.
- **FR-010**: Which outlet a receipt shows MUST be decided by the sale itself, never by the outlet the viewer currently has selected.
- **FR-011**: An empty outlet detail MUST be omitted from a receipt (no empty label, no substitute from another outlet); for sales whose outlet has no details at all, missing details MUST fall back to the shared store information so older receipts keep their current look.
- **FR-012**: Receipts MUST keep taking the shared logo, receipt footer text and product brand name from the shared store settings.
- **FR-013**: Screens that show store information MUST show the selected outlet's information (web: the outlet chosen in the outlet picker or the user's single outlet; tablet: the outlet the terminal is set up for); with All outlets selected they MUST show the shared store identity; an empty outlet detail MUST fall back to the shared value on screens.
- **FR-014**: Changes to an outlet's details MUST appear on the next load or refresh and on reprints of earlier receipts, without any other action.
- **FR-015**: Customer phone and email MUST remain excluded from receipts; only the outlet's own phone and email are printed.

**Terminal management (web)**

- **FR-016**: The web app MUST provide a Terminals page for owners and admins listing terminals with name, outlet, status and last-seen time, paginated like other lists.
- **FR-017**: The list MUST be filterable by outlet (multiple), status (active / deactivated / all) and name search; filters combine, and counts shown reflect the filters.
- **FR-018**: Owners and admins MUST be able to create a terminal under a chosen outlet, rename it, move it to another outlet, deactivate and reactivate it, and delete it.
- **FR-019**: A terminal name MUST be unique among active terminals of the same outlet (case and surrounding space ignored); the same name may exist in different outlets.
- **FR-020**: Moving a terminal MUST be refused while it has an open shift; moved terminals MUST NOT alter the outlet recorded on earlier sales, shifts or receipts.
- **FR-021**: Deactivating a terminal MUST stop its tablet from signing in or sending orders at its next request; reactivating MUST restore that.
- **FR-022**: Deleting a terminal MUST be refused when it has any sales, shifts or receipts attached (message points to deactivation) and otherwise MUST require confirmation.
- **FR-023**: Terminal pages and actions MUST be limited to the outlets the signed-in user may manage; cashier and inventory roles MUST be refused.
- **FR-024**: Every create, rename, move, deactivate, reactivate and delete MUST be recorded in the activity log with the actor and terminal, without secrets.

**Outlets follow the system mode (all pages)**

- **FR-032**: Outlets MUST be visible only in the system mode they were created in: LIVE outlets only while the system mode is LIVE, DEMO outlets only while it is DEMO. This MUST hold on every page, list, filter, picker, form dropdown and export that shows outlets (top-bar picker, Terminals, Outlets, Products, Reports, POS, Purchase, Cashier session), and on the tablet.
- **FR-033**: Switching the system mode MUST update the outlet choices already on screen without a manual reload: the top-bar picker, the outlet dropdowns of forms, and the store identity (name, logo) MUST reflect the new mode immediately, and an outlet remembered from the other mode MUST be dropped rather than sent to the server.
- **FR-034**: If the system mode is changed from somewhere else (another tab, the tablet, another admin), a screen that still shows the previous mode's outlets MUST recover on its next request or reload without showing a permanent error; a remembered outlet that no longer exists in the current mode MUST be ignored, not reported as an access error on every request.

**Terminal bulk actions (web)**

- **FR-025**: The Terminals page MUST let owners and admins select terminals with a checkbox per row and a select-all checkbox for the rows on the current page, and MUST show the selected count and a bulk-action bar (Activate, Deactivate, Delete) only while at least one row is selected.
- **FR-026**: Bulk Activate and bulk Deactivate MUST apply the same rules and have the same effect on tablets as the single-terminal actions (FR-021); terminals already in the target state MUST be skipped and counted, not treated as errors.
- **FR-027**: Bulk Delete MUST apply the single-delete rule (FR-022) to each terminal: terminals with any sales, shifts or receipts attached MUST be refused individually with a message pointing to deactivation, and the rest MUST be deleted. Bulk Delete and bulk Deactivate MUST ask for confirmation stating how many terminals are affected; Activate MAY run without confirmation.
- **FR-028**: A bulk action MUST NOT be all-or-nothing: each terminal succeeds or fails on its own, and the result MUST report, per terminal, whether it changed, was skipped, or was refused and why (the open-shift, in-use and not-permitted reasons already used for single actions).
- **FR-029**: Bulk actions MUST be limited server-side to terminals of the outlets the signed-in user may manage (FR-023); an ID outside that scope MUST be reported as not found or not permitted and never changed, and the outlet MUST NOT be taken from the request body to widen scope. Cashier and inventory roles MUST be refused.
- **FR-030**: Every terminal changed by a bulk action MUST get its own activity-log entry, the same as the single action, in the same transaction as that terminal's change, and MUST NOT include secrets.
- **FR-031**: Changing a filter, search or page MUST clear the selection; after a bulk action the list and counts MUST refresh, and terminals that could not be changed MUST stay selected.

### Key Entities *(include if feature involves data)*

- **Terminal**: A registered selling device of one outlet. Has a name (unique among active terminals of its outlet), an outlet, an active/deactivated status, and a last-seen time. Existing in the system already; this feature adds its web management.

- **Outlet**: A selling location of the organisation. Has a code (3–5 letters/digits, unique, editable), a name, an address, a phone, an email, notes, and an active flag. Its details are the "store information" for everything sold there.
- **Shared store information**: The organisation-wide store settings (logo, receipt footer, brand name, and fallback name/address/contact). Used for All-outlets views, for the shared items on every receipt, and as the fallback for outlets without details.
- **Receipt**: A document for one sale; belongs to the outlet where the sale happened and shows that outlet's identity.
- **Product code / SKU**: Identifiers that embed the outlet code at the moment a product is created; never rewritten afterwards.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: For two outlets with different details, 100% of receipts show only their own outlet's name, address, phone, email and notes, and 0 receipts show another outlet's.
- **SC-002**: An owner can create an outlet with all fields, including address, in under 2 minutes, and every detail is shown unchanged when reopened.
- **SC-003**: 100% of codes made of 3–5 letters/digits are accepted and stored in uppercase; 100% of codes that are duplicates, shorter than 3, longer than 5, or contain other characters are refused with a message naming the rule.
- **SC-004**: After an outlet code is edited, 0 existing product codes or SKUs change, and 100% of products created afterwards use the new code.
- **SC-005**: Switching the outlet picker between two outlets and All outlets changes the store name shown on screens each time, on the first view after the switch.
- **SC-006**: Reprinting a receipt after editing the outlet's address shows the new address 100% of the time.
- **SC-007**: A receipt for a single-outlet installation with no outlet details entered looks the same as before this feature (no information lost).
- **SC-008**: An owner can add a terminal, or find one by outlet/status/name filters and rename or deactivate it, in under 1 minute without using the tablet.
- **SC-009**: 100% of deactivated terminals are refused at their next request, and 0 terminals with history can be deleted.
- **SC-010**: An owner can deactivate or delete 10 terminals from the list in under 1 minute using one selection and one confirmation; in a mixed selection, 100% of terminals that can be changed are changed, 0 terminals with history are deleted, and 100% of refused ones are named with a reason.
- **SC-012**: In each mode, 0 outlets of the other mode appear in the outlet picker, any outlet dropdown, the Outlets list, or the Terminals list; after switching the mode, the picker and dropdowns show the new mode's outlets with no manual reload.
- **SC-011**: 0 terminals outside the user's permitted outlets are changed by a bulk action, including when IDs are sent directly to the endpoint.

## Assumptions

- **Code rule**: "5 letters and numbers" is read as **3 to 5 characters, letters and digits** (not exactly 5), so the existing 3-letter codes (for example the two outlets in the screenshots) stay valid with no data migration. If exactly 5 is wanted, existing outlets would need a one-time code change.
- **Editing the code reverses a documented rule** (the product requirements state outlet codes are permanent, F19.4); the product and the screenshots' help text must be updated, and the PRD gets a dated note rather than rewritten history.
- **SKU shape**: product codes and SKUs embed the outlet code at creation; with longer codes new products may get longer codes than today's fixed-width ones. How widths are handled is a planning decision; existing codes are never regenerated.
- **Which outlet a document shows**: the outlet where the sale happened (the outlet of the shift), because a receipt must describe where the customer bought. The selected outlet in the web picker only drives screens, not documents.
- **Fallbacks**: receipts omit empty outlet details but, for an outlet with no details at all, fall back to the shared store identity so existing receipts do not degrade; screens fall back per detail to the shared value. Whether receipts should also fall back per detail is a planning-time refinement, defaulting to "omit" so one outlet never shows another's data.
- **What stays shared**: logo, receipt footer text, brand/application name, theme, tax settings, and receipt options remain global; only the store-information block (name, address, phone, email, notes) becomes per outlet. "Notes" here means the outlet's existing notes field.
- **Contact person**: the shared "contact person" line is not part of the outlet record; it stays a shared setting unless the owner asks for an outlet equivalent later.
- **Scope of "every store information"**: receipts and the customer-facing/store-identity displays on web and Android (headers, sidebar, PIN screen, tablet receipt). Internal reports, exports and audit logs keep identifying outlets by name and code as they do now.
- **DEMO/LIVE**: outlets already exist separately per mode, so no extra handling is needed beyond using each mode's own outlets.
- **Dependencies**: builds on the store identity and branding work (072, 077) and the order-note receipt change (078); the 078 changes are currently only on the `077-…` branch and not yet in develop (see the report), so this feature's implementation must start from a base that contains them.
- **Terminal management**: builds on the existing terminal registration and revocation (048, 064, 073); the tablet setup flow keeps working unchanged. "CRUD" is read as create, list, rename/move (update), and delete-if-unused, with deactivation as the normal way to retire a terminal; hard delete of terminals with history is not offered so audit trails stay intact. Roles follow the existing owner/admin rule for terminals.
- **Bulk actions**: "bulk delete and bulk active/deactivate" is read as multi-select on the Terminals list with Activate, Deactivate and Delete, reusing the single-terminal rules per item. Partial success (not all-or-nothing) is assumed because one terminal with history should not block deleting ten unused ones; this differs deliberately from the all-or-nothing master-data import. Bulk Move is not requested and stays out of scope. Selection is per page (not "all matching results") to keep one request bounded; the exact batch cap is a planning decision.
- **Out of scope**: per-outlet logos or footers, per-outlet tax rules, outlet opening hours, and any change to how outlets are assigned to users.
