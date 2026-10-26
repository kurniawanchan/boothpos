**Classification: INTERNAL**

# Feature Specification: New-Tab Session, Seller Settlement Module, Store Branding

**Feature Branch**: `076-settlement-tab-session-branding`

**Created**: 2026-10-26

**Status**: Draft

**Input**: User description: "(1) Fix why every time I open a menu in a new tab I have to log in again; expected is that the menu I click opens in the new tab. (2) Add a settlement module to settle with each seller for any cost, revenue (sales and pre-order), profit, or anything related to settlement; it can generate and print a settlement proof with seller acknowledgement (possibly a digital signature) and a payment proof; the module must be full of data so the settlement amount balances. (3) Change the page title to use the store name combined with the application name, change the sidebar brand block to use the store name and store logo, and change the favicon to use the store logo."

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Open a menu in a new tab without logging in again (Priority: P1)

A cashier or owner is already logged in. They open a menu item in a new browser tab (middle-click, Ctrl/Cmd-click, or "Open in new tab") to keep one screen beside another, for example Sales next to Pre-orders. The new tab must show the chosen menu directly, as the same signed-in user, without a login screen.

**Why this priority**: It breaks a daily workflow (comparing two screens at a booth), and it is a defect against normal browser behaviour. It is also the smallest of the three changes and independently shippable.

**Independent Test**: Log in, middle-click any sidebar menu item, and confirm the new tab lands on that menu already signed in. Then log out in one tab and confirm the other tab can no longer act as that user.

**Acceptance Scenarios**:

1. **Given** a user is signed in in tab A, **When** they open any sidebar menu in a new tab, **Then** tab B shows that menu as the same user with no login prompt.
2. **Given** a user is signed in in tabs A and B, **When** they log out in tab A, **Then** tab B ends its session as well, on its next request or on regaining focus, and shows the login screen.
3. **Given** the browser (all windows) was fully closed and reopened, **When** the user opens the application, **Then** the existing rule on shared terminals still applies and they must log in again (the session must not outlive the browser session).
4. **Given** a user opens a deep link to a menu in a new tab while not signed in anywhere, **When** the page loads, **Then** they see the login screen and, after logging in, land on the menu they asked for.

---

### User Story 2 - Settle with each seller using a balanced settlement statement (Priority: P1)

An owner or admin closes out an event with a seller. They open the Settlement module, pick a seller and an event, and see one complete statement: revenue from POS sales and from pre-orders (collected portion), store-side deductions, adjustments, previous payments, and the resulting net amount owed, plus informational memo lines (uncollected pre-order balance, cost of goods, gross profit) that never change the amount. Every figure is traceable to the underlying transactions, and the statement shows a reconciliation proving that revenue minus all deductions equals the settlement amount, to the cent. The owner can then record the settlement and print or download a settlement proof.

**Why this priority**: This is the core business need of the request. Without a statement that balances and can be defended to the seller, nothing else in the module has value.

**Independent Test**: For a seller with POS sales, pre-orders (some partly paid), and a cost entry in a closed event, generate the statement and verify that every line ties to source transactions and that the reconciliation total equals the net settlement amount with zero difference.

**Acceptance Scenarios**:

1. **Given** a seller with POS sales and pre-orders in an event, **When** the owner opens the settlement statement for that seller and event, **Then** it lists revenue split into POS and pre-order (collected portion), each with units and amount, and the figures equal the sums of the underlying transactions.
2. **Given** the statement is shown, **When** the owner reads the reconciliation block, **Then** revenue, each cost/deduction/adjustment line, payments already made, and the net amount owed add up exactly, and a visible "difference" figure is zero.
3. **Given** transactions change after a statement was finalized (for example a sale is voided), **When** the owner opens the finalized settlement, **Then** it still shows the figures as finalized, and a visible notice tells them the source data has changed since, with the difference amount.
4. **Given** a seller has no activity in the chosen event, **When** the owner opens their statement, **Then** it shows zero amounts, not an error, and finalizing is blocked with an explanation.
5. **Given** a user without owner or admin rights, **When** they try to open the Settlement module, **Then** access is denied and the menu is not shown.
6. **Given** a seller with 1,000,000 revenue and no added deductions, **When** the statement is shown, **Then** the net amount owed to the seller is 1,000,000; no commission line exists.
7. **Given** the owner adds an event-level cost to charge to a seller, **When** the statement is shown, **Then** the allocation basis of that line is displayed, so the seller can see how the amount was reached.

---

### User Story 3 - Print a settlement proof with seller acknowledgement and a payment proof (Priority: P2)

After a settlement is finalized, the owner prints or downloads a settlement proof document containing the full statement, the settlement number, event, store and seller identity, and an acknowledgement block. The seller acknowledges it with a signature captured on screen (drawn or typed name with timestamp), or, when not present, the owner records that the seller acknowledged outside the system. When money is paid, the owner records the payment (amount, date, method, optional reference and proof image) and prints or downloads a payment proof that references the settlement number and shows the remaining balance.

**Why this priority**: The statement (Story 2) is useful alone, but the proofs are what close the dispute risk and are an explicit part of the request. Independent of branding and session work.

**Independent Test**: Finalize a settlement, capture a seller signature, record a full payment with a reference, and confirm both documents print/download correctly, carry the same settlement number, and the settlement shows as settled with zero balance.

**Acceptance Scenarios**:

1. **Given** a finalized settlement, **When** the owner captures the seller's on-screen signature, **Then** the signature, the signer name, and the date/time are stored with the settlement and appear on the printed proof.
2. **Given** a settlement already acknowledged, **When** anyone tries to edit its figures, **Then** the edit is refused; corrections must be made through a visible adjustment or a reopen action that is recorded in the audit log.
3. **Given** a finalized settlement with a balance, **When** the owner records a partial payment, **Then** the balance decreases by exactly that amount and the settlement status shows "partially paid"; a payment larger than the balance is refused.
4. **Given** a payment is recorded, **When** the owner opens the payment proof, **Then** it shows the settlement number, amount, date, method, reference, who recorded it, and the remaining balance, in a printable layout.
5. **Given** a settlement where the store owes nothing and the seller owes nothing (net zero), **When** it is finalized, **Then** it can be acknowledged and closed with no payment step.
6. **Given** the net result is negative (the seller owes the store, for example costs exceed revenue), **When** the statement is finalized, **Then** the direction ("seller pays store") is explicit on the statement and the payment proof records money received from the seller.

---

### User Story 4 - Page title, sidebar brand, and favicon follow the store identity (Priority: P3)

The browser tab title shows the store name together with the application name. The brand block at the top of the sidebar shows the store logo and the store name (in place of the fixed storefront icon and "BoothPOS"). The browser tab icon (favicon) uses the store logo. When no store name or logo is set, the product defaults are used.

**Why this priority**: Cosmetic and identity value; low risk, independent of the other stories.

**Independent Test**: Set a store name and logo in Settings, reload, and confirm the tab title, sidebar brand, and favicon all reflect them; clear them and confirm the defaults return.

**Acceptance Scenarios**:

1. **Given** a store name "Demo Store" and the application name "BoothPOS", **When** any page is shown, **Then** the tab title reads "Demo Store · BoothPOS" (store name first, application name second).
2. **Given** a store logo is configured, **When** the application loads, **Then** the sidebar brand block shows that logo and the store name, and the favicon is that logo.
3. **Given** no store logo is configured, **When** the application loads, **Then** the sidebar shows the existing default storefront mark and the favicon is the default icon.
4. **Given** no store name is configured, **When** any page is shown, **Then** the title is just the application name.
5. **Given** the owner changes the store name or logo in Settings, **When** the change is saved, **Then** the title, sidebar and favicon update without requiring a new login.
6. **Given** the login screen (nobody signed in), **When** it is shown, **Then** it uses the default title and icon, since store settings are not available before login. See Assumptions.
7. **Given** DEMO mode is active, **When** the title is built, **Then** it uses the store name of the active mode, consistent with the rest of the app.

---

### Edge Cases

- A seller has revenue in several events: each event is settled separately (one statement per seller per event), so no transaction can ever appear in two settlements.
- A pre-order is partly paid: only the collected portion counts as revenue (consistent with existing reports), and the statement shows the uncollected balance as a memo line, not as revenue.
- A pre-order is cancelled or a sale is voided after settlement: see US2 scenario 3; the finalized settlement is never silently recomputed.
- Rounding: all amounts are whole minor units of the currency; the sum of displayed lines must equal the displayed total with no hidden rounding difference.
- A seller was deactivated or deleted after the event: their settlement remains viewable and printable from the snapshot.
- Two users open the same seller settlement at once: the second finalize attempt is refused with a clear message, never producing a duplicate settlement for the same seller and event.
- The signature canvas is not usable (no touch/mouse, or very small screen): a typed-name acknowledgement with timestamp is accepted as an equivalent.
- Print layout: the proof fits A4 and the image/PDF download works without server-side document generation, as the other documents in the app do.
- Logo image missing or failing to load: sidebar and favicon fall back to the defaults, with no broken-image icon.
- A very long store name: truncated with the full name in a tooltip in the sidebar; the title is not truncated by the app.
- New-tab behaviour with a session that has expired on the server: the new tab shows the login screen, then returns to the requested menu.
- Existing pages that hold an unsent draft (POS cart) in tab A are not affected by opening tab B.

## Requirements *(mandatory)*

### Functional Requirements

**New-tab session (US1)**

- **FR-001**: A signed-in user MUST be able to open any application page in a new tab or window and land on that page signed in as the same user, without a login screen.
- **FR-002**: Logging out in any tab MUST end the session in all open tabs of that browser.
- **FR-003**: The session MUST NOT survive closing the whole browser, preserving the current shared-terminal protection.
- **FR-004**: A deep link opened while signed out MUST show the login screen and return to the requested page after a successful login.
- **FR-005**: Sidebar menu items MUST be real links so the browser's standard "open in new tab" gestures work.

**Settlement module (US2, US3)**

- **FR-006**: The system MUST provide a Settlement module, available only to owner and admin roles, where a settlement is prepared per seller and per event.
- **FR-007**: A settlement statement MUST present: revenue from POS sales (units, amount), revenue from pre-orders (collected portion, units, amount), store-side deductions, manual adjustments (each with a reason), previous settlement payments, and the net amount owed. Informational memo lines (uncollected pre-order balance, cost of goods, gross profit) are shown on screen but never change the amount; cost and profit are left off the printed proof unless the owner opts in. No commission is taken from sellers: the seller's share is the full revenue; costs are not deducted automatically and appear only as explicit deduction or adjustment lines when the owner adds them.
- **FR-008**: Every statement line MUST be traceable: the user can open the transactions (sales, pre-orders, cost entries) behind any line, and the lines MUST sum to the line's amount.
- **FR-009**: The statement MUST include a reconciliation block showing that revenue minus all deductions, costs and prior payments equals the net settlement amount, and MUST display a difference figure that is exactly zero for a finalizable statement.
- **FR-010**: The system MUST refuse to finalize a statement whose reconciliation difference is not zero, and MUST explain which figure disagrees.
- **FR-011**: The system MUST NOT apply any commission to sellers and MUST NOT offer a commission setting. Any event-level cost the owner chooses to charge to a seller MUST show its allocation basis (for example by sales share or fixed amount).
- **FR-012**: Finalizing MUST freeze a snapshot of every figure and assign a unique, human-readable settlement number; later changes to source transactions MUST NOT alter the snapshot but MUST raise a visible "source changed" notice with the difference.
- **FR-013**: At most one active settlement MAY exist per seller and event; corrections after finalization go through a recorded adjustment or an audited reopen.
- **FR-014**: The system MUST support recording seller acknowledgement as an on-screen signature (drawn, or typed name) stored with signer name and date/time, and MUST allow the owner to record acknowledgement obtained outside the system, clearly labelled as such.
- **FR-015**: Once acknowledged, the settlement's figures MUST be read-only.
- **FR-016**: The system MUST support recording one or more payments against a settlement (amount, date, method, optional reference, optional proof image), refuse overpayment, and show status unpaid / partially paid / settled and the remaining balance. Payments MUST be idempotent against double submission.
- **FR-017**: When the net result is negative, the statement and payment record MUST state the direction explicitly (seller pays store) and handle it with the same recording and proof rules.
- **FR-018**: The user MUST be able to print or download a settlement proof (statement + reconciliation + acknowledgement block) and a payment proof (settlement number, amounts, method, reference, balance), for A4 print.
- **FR-019**: Both documents MUST show store identity (name, logo, contact), seller identity, settlement number, event, and the "Powered by" line used by other documents.
- **FR-020**: Every settlement action (prepare, finalize, acknowledge, payment, delete payment, reopen, adjustment) MUST be written to the activity log together with the change, so a rolled-back change never leaves a log entry.
- **FR-021**: A settlement list MUST show, per seller and event, number, status, net amount, paid amount, and balance, with filters by seller, event, and status, and totals.
- **FR-022**: Settlement data MUST respect the DEMO/LIVE boundary like all other business data, and amounts MUST be computed by the server only (client-supplied amounts are never trusted).
- **FR-023**: Seller contact details and payment-proof images MUST be visible only to roles allowed to see them; settlement documents MUST NOT print customer personal data (customer names, phone, email) from underlying transactions.

**Branding (US4)**

- **FR-024**: The page title MUST be "<store name> · <application name>" when a store name is set, and the application name alone otherwise; it MUST update when the store name or the application name changes.
- **FR-025**: The sidebar brand block MUST show the store logo and store name when set, falling back to the existing default mark and application name when not set; the tier label under it is retained.
- **FR-026**: The favicon MUST be the store logo when set and the default icon otherwise, updating without re-login after a change.
- **FR-027**: A missing or failed logo load MUST fall back to the defaults with no broken-image display.

### Key Entities *(include if feature involves data)*

- **Settlement**: One reconciled statement between the store and one seller for one event. Has a unique number, status (draft, finalized, acknowledged, partially paid, settled), direction (store pays seller / seller pays store), frozen totals, and links to the seller and event.
- **Settlement Line**: One line of a statement: a revenue source (POS, pre-order), deduction (including any charged cost), or adjustment, each with units/amount, basis, and links back to source transactions. Lines sum exactly to the settlement totals.
- **Settlement Acknowledgement**: The seller's confirmation: signature image or typed name, signer name, date/time, and whether it was captured in the system or recorded as obtained outside it.
- **Settlement Payment**: One money movement against a settlement: amount, date, method, reference, optional proof image, who recorded it; many per settlement.
- **Seller**: Existing entity; gains no new data beyond being the counterparty.
- **Store Identity**: Existing store name and logo plus the application name, now also driving the title, sidebar brand and favicon.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: A signed-in user opening any of the application's menus in a new tab reaches it with no login prompt in 100% of attempts, and logging out in one tab signs out every other tab within one minute.
- **SC-002**: After closing and reopening the whole browser, 100% of users are asked to log in again.
- **SC-003**: For every finalized settlement, the reconciliation difference is exactly zero, and an owner can trace any statement line to its source transactions in two clicks or fewer.
- **SC-004**: An owner can go from opening the Settlement module to a finalized, acknowledged, printable settlement for one seller in under 5 minutes.
- **SC-005**: A recorded payment brings the settlement balance to exactly the previous balance minus the payment; no sequence of payments can push the total paid above the amount owed.
- **SC-006**: Settlement statements for a seller reconcile with the existing reports for the same event: POS revenue and collected pre-order revenue equal the figures in those reports to the cent, or the statement states the difference and its reason.
- **SC-007**: After the store name and logo are saved, the browser tab title, sidebar brand and favicon reflect them on the next page load with no re-login, and revert to defaults when cleared.
- **SC-008**: Sellers who receive a settlement proof raise fewer disputes about the amount; measured as a reduction in follow-up questions about a settlement figure compared with the current manual process (target: at least 50% fewer, tracked by the owner over the first two events).

## Assumptions

- **Why re-login happens today (fact)**: the sign-in token is kept per browser tab, and a new tab starts with none. This was a deliberate earlier choice to protect shared terminals; the fix must keep the "ends when the browser closes" property (FR-003), so the cross-tab sign-in is the change, not extending the session beyond the browser session.
- **Settlement basis (decided, 2026-10-26)**: no commission. Seller's share = full revenue (POS + collected pre-order); deductions and adjustments are added only explicitly by the owner. Cost of goods is not deducted automatically.
- **Settlement is store ↔ seller (ASSUMPTION)**: the store settles with each seller for an event; the "direction" is derived from the sign of the net result (negative only when explicit deductions exceed revenue). Settlement between sellers directly is not in scope.
- **Revenue recognition (ASSUMPTION)**: pre-order revenue counts only the collected portion, consistent with the existing Pre-order report and Cost & Profit; the uncollected remainder is shown as a memo line.
- **This module is separate from the Seller Recap**: the Seller Recap stays POS-only as decided in feature 040; settlement is a new module and does not change the Recap, Seller Cost, Cost & Profit, or Pre-order reports. It deliberately reintroduces payable/paid/outstanding concepts that 040 removed from the Recap, in its own place.
- **Digital signature (ASSUMPTION)**: "digital signature" means an on-screen captured signature or typed-name acknowledgement with timestamp and signer name, stored with the settlement. It is not a certificate-based or legally qualified electronic signature; no third-party e-signature service is used.
- **Scope is one event per settlement (decided in planning, 2026-10-26)**: a free date-range "period" was dropped from the first draft because a period can overlap events and let the same sale be settled twice, which defeats the balance guarantee. Multi-event or period settlement can be a later feature.
- **Documents are generated in the browser** (print / PDF / image download) as for the other documents in the app; no server-side document generation.
- **Access**: owner and admin only, via a new menu permission following the same pattern as other owner/admin-only modules. Sellers have no login and no self-service portal (that remains out of scope per the PRD).
- **Branding on the login screen (ASSUMPTION)**: store settings are only available to signed-in users, so the login screen keeps the default title and icon. Exposing store identity before login is out of scope unless requested.
- **Title format**: "<store name> · <application name>" with a middle dot separator.
- **Dates and currency** follow existing app conventions (Rupiah, whole units, store timezone).
- **Existing data**: no migration of historical settlements is needed because none exist; past events can be settled retroactively from existing transactions.
- **Dependency**: relies on existing sales, pre-order, purchase-order, event, artist/seller, store settings, activity log, and receipt/document patterns already in the product.

## Clarifications

### Session 2026-10-26

- Q: How is the seller's share determined? → A: Initially commission basis; revised same day: no commission, no commission setting. Seller's share is full revenue less explicit deductions/adjustments.
