# Feature Specification: License & Invoice Management

**Feature Branch**: `019-billing-system`

**Created**: 2026-09-06

**Status**: Implemented (2026-09-07) — third expansion fully built, tested, and verified via a real running server + browser. Company activation is no longer gated by a 6-digit code emailed to the client (feature 017's original design) — that code was only ever enterable by the provider's own owner/admin staff, who have no legitimate way to know a code sent to the CLIENT's inbox. Replaced with a condition the provider CAN verify directly: `POST /companies/{company}/activate` now succeeds once the company has at least one `Invoice` with `status = 'paid'` (409 otherwise). A new `POST /companies/{company}/deactivate` (the reverse — locks the owner's login, reverts status to `pending_activation`, leaves the company/invoice history untouched) was added at the same time so a company can be locked again without deleting any data. `CompanyResource` exposes a server-computed `can_activate` boolean (`status !== 'active' && paid_invoices_count > 0`) so the frontend shows the Activate button only when it will actually succeed. The old code-entry modal, `resendActivationCode()`, `CompanyActivationMail`, and the email-notification-writing code are removed as dead weight (the `company_activation_notifications` table itself is left in place, unused, to avoid an unnecessary schema-drop migration). Backend: 492/492 tests passing. Frontend: 221/223 tests passing (2 pre-existing skips), 0 failing.

Prior (second) expansion, also implemented and verified: Company edit/delete added (`PUT`/`DELETE /companies/{company}`, delete blocked 409 if any Invoice references the company). `Invoice`'s detail view/PDF/image now surface the billed Company's business type (read live) alongside its existing payment-information snapshot. Invoice Edit/Delete actions added to `InvoiceDetailModal.vue`'s footer (backend already supported both since the prior expansion; this closed a pure UI gap).

**Real bug found and fixed during manual verification** (not just automated tests): `InvoiceService::generateNumber()` counted only non-soft-deleted invoices when checking for a free number, but the `invoice_number` UNIQUE constraint is enforced at the database level regardless of `deleted_at` — so a soft-deleted invoice's number could be silently reissued to a new invoice, causing a `1062 Duplicate entry` failure. Reproduced live (create → delete → create again collided), fixed with `withTrashed()` in the uniqueness check, and covered by a new regression test (`test_generating_invoice_number_accounts_for_soft_deleted_invoices`).

**Scope expanded again (dated note, 2026-09-06, second expansion)**: adds full Company edit/delete (Company management previously had no update/destroy at all — onboarding-only, an explicit original-scope limit from feature 017); adds the Settings → Payment singleton's structured fields (bank name/account number/account holder/instructions) to the invoice detail view and its PDF/image capture, not just the free-text `payment_information` field; adds the billed Company's business type to the invoice detail view and its PDF/image capture (already required on the CREATE form per FR-008, but never surfaced back in the read view); and closes the Invoice-edit/delete UI gap described above.

**Input**: User description: "buat sistem billing yang berhubungan dengan lisensi, paket" — expanded 2026-09-06 with: License pricing (one-time/subscription) + feature description; Package renamed to License as its own top-level menu; seeded Pro/Master License data; License linked to Company; Invoice as its own top-level menu with statistics, an industry-standard invoice document (package/payment/company/logo/billing/discount/subtotal/grand total/status/payment info/notes), PDF/image export, Excel export/import, click-to-open detail by invoice number, full CRUD (delete blocked once paid); and an invoice payment-settings submenu under Settings.

**Scope expanded (dated note, 2026-09-06)**: The original pass (still reflected in `tasks.md`'s completed T001-T020) built a minimal, modal-based invoice tracker reusing feature 017's `companies` menu key and `Package` entity as-is. This update **renames/expands `Package` into `License`** (pricing + description + its own menu) and **expands `Invoice`** into a full standalone document (own menu, statistics, detailed form, exports, CRUD). This is an explicit, dated scope expansion, not a silent rewrite of the earlier decision — the earlier implementation is the starting point for a follow-up implementation pass, not scrapped.

**Scope clarified with product owner before drafting this update**: "Subscription" is a **descriptive label only** on a License and the invoices billed against it — there is **no automatic recurring invoice generation** in this feature. Every invoice, one-time or subscription-billed, is still created manually by staff, exactly as before.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Manage Licenses with pricing and included features (Priority: P1)

An owner/admin manages the list of Licenses (the renamed, expanded `Package`) that can be offered to companies — each with a name, price, payment type (one-time or subscription), and a description of which features/tier it includes. Licenses have their own top-level menu, separate from Company Onboarding.

**Why this priority**: Every other new capability in this update (pricing shown on invoices, License-linked billing) depends on Licenses actually carrying pricing/description data — nothing else has value until this exists.

**Independent Test**: Create a License with a price, payment type, and description; confirm it's selectable when onboarding/billing a company and its details display correctly.

**Acceptance Scenarios**:

1. **Given** an owner/admin on the Licenses screen (its own menu item), **When** they create a License with a name, price, payment type (one-time/subscription), and a feature description, **Then** it is saved and appears in the License list.
2. **Given** a fresh installation, **When** it is first set up, **Then** a "Pro" and a "Master" License already exist (seeded), each with a sensible default price, payment type, and feature description — visible identically whether the installation is in DEMO or LIVE mode (Licenses are reference data, not per-mode transactional data — see Assumptions).
3. **Given** an existing Company, **When** its License is viewed, **Then** the Company's linked License (name, price, payment type) is visible from the Company's own record (the existing `package_id` link, carried over from feature 017).

---

### User Story 2 - Manage Invoices as a standalone, full-featured document (Priority: P1)

An owner/admin manages Invoices from their own top-level menu (not a modal buried in the Companies screen). Each invoice is an industry-standard billing document: a human-readable invoice number (clickable to open its full detail), the billed company's identity and business type, the License it's for (including its payment type), a billing/due-date section, line amounts (subtotal, discount, grand total), status, the store's payment information (bank name/account number/account holder/instructions, configured once under Settings → Payment), and notes. An invoice can be edited or deleted directly from its detail view (while not yet "paid"), downloaded as a PDF or image (both including the business type and full payment information), and the invoice list supports bulk export/import.

**Why this priority**: This is the core deliverable of the expanded request — everything else (statistics, settings) exists to support or summarize this document.

**Independent Test**: Create an invoice via the detailed form; confirm every specified field is captured and displayed, including business type and full payment information; open it by clicking its invoice number; edit it; download it as a PDF and as an image and confirm both include business type and payment information; delete it; export the list and re-import it.

**Acceptance Scenarios**:

1. **Given** an owner/admin on the Invoices screen, **When** they create a new invoice, **Then** the creation form follows a standard invoice layout capturing: the company and its business type, the selected License (and its payment type), a subtotal, an optional discount, the resulting grand total, a due date, payment information, and notes.
2. **Given** an existing invoice, **When** its invoice number is clicked from any list, **Then** its full detail opens, showing every captured field plus its current status, the billed company's business type, and the store's current payment information (bank name/account number/account holder/instructions from Settings → Payment).
3. **Given** an invoice's detail view, **When** the operator chooses to download it, **Then** they can obtain it either as a PDF or as an image, and both include the business type and the full payment information exactly as shown on screen.
4. **Given** the invoice list, **When** an owner/admin exports it, **Then** a file is produced that can later be re-imported to recreate/update the same records (mirroring this codebase's existing master-data export/import round-trip convention).
5. **Given** an invoice whose status is "paid," **When** an owner/admin attempts to edit or delete it, **Then** the action is refused — paid invoices are permanent financial records.
6. **Given** an invoice whose status is not yet "paid," **When** an owner/admin uses the Edit or Delete action on its detail view, **Then** the change is allowed and takes effect immediately (unpaid invoices are still correctable, matching this codebase's existing "still-draft" editability pattern for other transactional documents).

---

### User Story 5 - Edit and delete a Company record (Priority: P2)

An owner/admin can correct a Company's own details (name, contact, business type, linked License) after it was onboarded, and remove a Company record entirely when it's no longer needed — neither action exists today (feature 017 only ever supported creating and activating a Company, never editing or deleting one).

**Why this priority**: A real, requested gap-closer for day-to-day data upkeep, but it doesn't block any of License/Invoice's own value (US1-US4) from working.

**Independent Test**: Edit an existing Company's details and confirm they save; attempt to delete a Company that has invoices (blocked) and one that doesn't (succeeds).

**Acceptance Scenarios**:

1. **Given** an owner/admin on the Companies screen, **When** they edit an existing Company's name/contact/business type/License, **Then** the changes save and are reflected immediately in the Company list and detail.
2. **Given** a Company with one or more Invoices recorded against it, **When** an owner/admin attempts to delete it, **Then** the deletion is refused (409) — a Company's invoice history must remain traceable to a real Company record, mirroring the same delete-guard philosophy already applied to License (FR-005) and paid Invoices (FR-011).
3. **Given** a Company with no Invoices recorded against it, **When** an owner/admin deletes it, **Then** the Company is removed (soft-deleted, matching this codebase's existing convention for Company/License/Invoice) and no longer appears in the active Company list.

---

### User Story 3 - See billing statistics at a glance (Priority: P2)

On the Invoices screen, an owner/admin sees summary statistics (e.g., total outstanding, total collected, count by status) without having to tally the list manually.

**Why this priority**: Valuable for a quick financial pulse-check, but the underlying record-keeping (US2) already delivers this feature's core value without it.

**Independent Test**: With a mix of unpaid/paid/cancelled invoices present, confirm the statistics reflect the correct totals and counts.

**Acceptance Scenarios**:

1. **Given** invoices in multiple statuses, **When** the Invoices screen loads, **Then** it shows at minimum: count and total amount unpaid, count and total amount paid, and total invoice count.

---

### User Story 4 - Configure invoice payment information once, reuse everywhere (Priority: P2)

An owner/admin configures the store's payment information (e.g., bank account details, payment instructions) once, under Settings → Payment, and every invoice's "payment information" section displays it — rather than staff re-typing the same details on every invoice.

**Why this priority**: A real convenience and consistency win, but individual invoices already have a free-text notes field as a fallback if this isn't configured yet, so it doesn't block US1-US3 from delivering value.

**Independent Test**: Configure payment information under Settings → Payment; confirm a newly created invoice's payment information section reflects it without manual re-entry.

**Acceptance Scenarios**:

1. **Given** an owner/admin on Settings → Payment, **When** they save payment information (e.g., bank name, account number, account holder, instructions), **Then** it is stored and reused as the default payment information shown on subsequently created invoices.

---

### Edge Cases

- What happens to an invoice already created against a License whose price/description later changes? The invoice keeps its own recorded subtotal/discount/grand total — never re-derived from the License's current price (same historical-snapshot rule as the original pass's FR-007).
- What happens when exporting/importing invoices that reference a License or Company that no longer exists? Mirrors this codebase's existing master-data import behavior: the whole import is rejected with a clear per-row error (all-or-nothing), never a partial, silently-broken import.
- What happens if someone tries to delete a "paid" invoice via a bulk/import path rather than the single-delete action? The same guard applies — no path may delete a paid invoice.
- What happens to a License that's already linked to one or more companies/invoices if someone tries to delete it? Blocked (409), same delete-guard convention as the original `Package` entity it replaces.
- What logo appears on an invoice — the billed company's, or the store's own? The store's own (the issuer's identity), reusing the logo already configurable under the existing Settings screen — see Assumptions.
- What happens if Settings → Payment has never been configured, and an invoice's detail view is opened? The payment-information section shows whatever the invoice itself recorded at creation (its own `payment_information` field, which may be blank) — never a broken/empty-looking section; a blank configuration is not an error state.
- What happens to an invoice's payment-information section if Settings → Payment is changed AFTER the invoice was created? Nothing — an invoice's `payment_information` is a fixed snapshot recorded at creation time (same historical-snapshot rule as subtotal/discount/grand_total, FR-013), never live-rendered from the current Settings → Payment value.
- What happens if someone tries to delete a Company via a path other than the single-delete action (there is currently no bulk/import path for Company, unlike Invoice)? N/A today — Company has no bulk operations in scope; if one is added later it must respect the same invoice-history delete-guard.

## Requirements *(mandatory)*

### Functional Requirements

**License (renames/expands `Package`)**

- **FR-001**: The system MUST allow an owner/admin to create/edit a License with: a name, a price, a payment type (one-time or subscription), and a description of included features.
- **FR-002**: License management MUST be reachable from its own top-level menu, separate from the Company Onboarding menu.
- **FR-003**: The system MUST come pre-seeded with a "Pro" and a "Master" License, each with a default price, payment type, and feature description, visible identically in both DEMO and LIVE mode.
- **FR-004**: A Company's linked License (and that License's price/payment type) MUST be visible from the Company's own record, carrying over the existing `package_id` relationship from feature 017.
- **FR-005**: A License referenced by any Company or Invoice MUST NOT be deletable outright — only deactivatable (carrying over the original `Package` delete-guard convention).

**Invoice**

- **FR-006**: Invoice management MUST be reachable from its own top-level menu, separate from the Company Onboarding menu and from Licenses.
- **FR-007**: Every invoice MUST have a unique, human-readable invoice number, clickable from any list to open that invoice's full detail.
- **FR-008**: The invoice creation/edit form MUST capture, at minimum: the billed company and its business type, the linked License (with its payment type shown), a subtotal, an optional discount, the resulting grand total, a due date, payment information, and notes.
- **FR-008a**: The invoice DETAIL view MUST also display the billed company's business type and the invoice's full payment information (not just a truncated/summary form) — both fields captured at creation must be visible when the invoice is read back, not only while creating it.
- **FR-009**: The system MUST allow downloading a single invoice as a PDF and separately as an image, and BOTH downloads MUST include the business type and the full payment information exactly as shown in the detail view (FR-008a) — a downloaded document that omits fields visible on screen is not an acceptable invoice document.
- **FR-010**: The system MUST support exporting the invoice list to a file and importing that same file format back in (create/update), consistent with this codebase's existing master-data export/import round-trip convention.
- **FR-011**: The system MUST support full CRUD on invoices (create, view, edit, delete), reachable directly from the Invoice detail view (an Edit action and a Delete action, not API-only) — **except** that an invoice with status "paid" MUST NOT be editable or deletable through any path.
- **FR-012**: The Invoices screen MUST show summary statistics: total and count unpaid, total and count paid, and overall invoice count.
- **FR-013**: An invoice's subtotal/discount/grand total MUST be a fixed snapshot at creation — never automatically recalculated if the linked License's price later changes (carrying over the original FR-007).

**Settings**

- **FR-014**: The system MUST provide a "Payment" submenu under the existing Settings menu where an owner/admin can configure default invoice payment information (bank name, account number, account holder, instructions).
- **FR-015**: A newly created invoice's payment information MUST default to whatever is configured under Settings → Payment, while still allowing per-invoice override/notes; once created, an invoice's own payment-information snapshot never changes if Settings → Payment is edited afterward (Edge Cases).

**Company (new, second expansion)**

- **FR-018**: The system MUST allow an owner/admin to edit an existing Company's name, contact details, business type, and linked License.
- **FR-019**: The system MUST allow an owner/admin to delete a Company, **except** that a Company referenced by any Invoice MUST NOT be deletable (409) — mirroring the same delete-guard philosophy already applied to License (FR-005) and paid Invoices (FR-011).

**Company activation (third expansion, supersedes the code-based design from feature 017)**

- **FR-020**: A Company MUST be activatable (unlocking its owner user's login) only once it has at least one Invoice with status `paid` — **not** via a 6-digit code emailed to the client, since the only staff who can reach the activation action (owner/admin, gated by the `companies` menu key) have no legitimate way to know a code sent to the client's own inbox.
- **FR-021**: An owner/admin MUST be able to deactivate an already-active Company (reverting it to `pending_activation` and locking its owner user's login) without deleting the Company or any of its Invoice history — for cases like a lapsed subscription or misuse.

**Carried over from the original pass**

- **FR-016**: Invoice/License management MUST be restricted to the owner/admin roles.
- **FR-017**: No automatic recurring invoice generation — "subscription" is descriptive data only (per the product-owner clarification above); every invoice remains a manually-created, one-time record of a billing event.

### Key Entities

- **License** *(renamed/expanded from `Package`)*: name, price, payment type (one-time/subscription), feature description, active flag.
- **Invoice** *(expanded)*: invoice number, company (with business type), license (at time of billing), subtotal, discount, grand total, due date, status (unpaid/paid/cancelled), paid date, payment information (a fixed snapshot, including the structured Settings → Payment details at time of creation), notes.
- **Invoice Payment Settings** *(new)*: the store's default payment information shown on invoices (bank/account details, instructions) — one record per installation, configured under Settings → Payment.
- **Company** *(no schema change, new capability)*: now editable and deletable (delete blocked if any Invoice references it), in addition to feature 017's existing onboarding/activation capability.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An owner/admin can create a fully-detailed invoice (all specified fields) in under 3 minutes.
- **SC-002**: Any invoice can be located and opened by its invoice number in a single click from any list it appears in.
- **SC-003**: An invoice can be downloaded as either a PDF or an image with no additional configuration step.
- **SC-004**: The Invoices screen's statistics always match a manual tally of the underlying list — verified after any create/edit/delete/status-change action.
- **SC-005**: 100% of attempts to edit or delete a "paid" invoice are refused, with zero exceptions across every entry point (detail-view action, bulk/import path).
- **SC-006**: Configuring payment information once under Settings → Payment eliminates re-typing it on every subsequent invoice.
- **SC-007**: An invoice downloaded as a PDF or image always shows the same business type and payment information as its on-screen detail view — zero discrepancies between what's on screen and what's in the downloaded document.
- **SC-008**: 100% of attempts to delete a Company with any recorded Invoice are refused, with zero exceptions.

## Assumptions

- **"Subscription" is descriptive only** (confirmed with the product owner) — no automated recurring billing/reminders/scheduler is in scope; every invoice is still manually created.
- **License remains reference/administrative data, not per-mode transactional data** — the seeded Pro/Master Licenses are single rows visible identically in DEMO and LIVE mode (carrying over 017's original `Package` design decision), not duplicated per mode.
- **Discount is a fixed amount, not a percentage** — simpler, and consistent with this app's existing `discount_amount` convention on POS orders; a percentage-based discount is not requested and can be added later if needed.
- **Invoice line items are single-license**, not a multi-line-item invoicing system — one invoice bills one License's price (plus discount), matching the level of detail actually requested; a future need for multiple line items per invoice is separate, larger work.
- **The logo shown on an invoice is the store's own** (the issuing party's identity), reusing the logo already configurable in the existing Settings screen — not a new per-company logo field, since industry-standard invoices display the issuer's branding, and the billed company's own logo was not specified as an existing or requested data point.
- **Export/import format is Excel**, matching this codebase's established convention (master-data export/import) — as a separate, single-purpose workbook (like feature 007's preorder import/export), not folded into the existing master-data workbook, since invoices are transactional records, not master data.
- **PDF/image download reuses this app's existing client-side pattern** (already used for receipts/invoices elsewhere in the app) rather than introducing server-side PDF generation, consistent with this codebase's established "no server-side PDF generation" decision (feature 007's research.md).
- **Invoice editing is restricted to non-"paid" invoices**, mirroring the existing status-transition-guard convention, so a paid invoice's financial figures can never be altered after the fact, only viewed.
- **This is still an internal, single-installation feature** — no change to the existing "not multi-tenant" scope boundary established across features 016-019.
- **Company delete is a soft delete**, matching Company/License/Invoice's existing convention across this feature and feature 017 — not a hard delete.
- **Company delete is blocked only by Invoice references, not by License linkage** — a Company always has exactly one linked License by design (FR-004), so guarding on that relationship would make every Company undeletable; only Invoice history is the real "financial record must survive" concern (same reasoning FR-011/FR-005 already established for Invoice/License themselves).
- **Editing a Company does not require re-activation** — changing name/contact/business type/License on an already-active Company does not reset its activation status or require a new activation code, since activation (feature 017) is about the Company's owner-user login credential, an unrelated concern to its business/contact details.
