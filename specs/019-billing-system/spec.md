# Feature Specification: Billing Records for Company Onboarding

**Feature Branch**: `019-billing-system`

**Created**: 2026-09-06

**Status**: Draft

**Input**: User description: "buat sistem billing yang berhubungan dengan lisensi, paket"

**Scope (defaults chosen, low-effort pass — see Assumptions)**: An internal billing/invoice tracker inside the existing Company Onboarding admin area (feature 017) — records what a company owes and has paid for its chosen package, staff-entered, no payment gateway integration. It is the missing link between "a company picked a package" (017) and "the vendor manually sends a license key after payment" (018's own User Story 3) — this feature is what tells staff *whether* that payment has actually happened, not a new payment-collection mechanism.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Record an invoice for a company's package (Priority: P1)

An owner/admin creates a billing record for a company (already onboarded per feature 017), capturing the amount owed for its package and a due date. The invoice starts unpaid.

**Why this priority**: Nothing else in this feature has value until a billing record can exist at all.

**Independent Test**: Create an invoice for an existing company; confirm it appears with status "unpaid" and the correct amount/due date.

**Acceptance Scenarios**:

1. **Given** an onboarded company, **When** an owner/admin creates an invoice with an amount and due date, **Then** the invoice is recorded with status "unpaid."
2. **Given** a company with no package-derived price available, **When** an invoice is created, **Then** the amount must still be enterable manually (packages are descriptive, not billing-plan-priced today per 017 — see Assumptions).

---

### User Story 2 - Mark an invoice paid (Priority: P1)

Once a company's payment is confirmed (by whatever means the business already uses, outside this app), an owner/admin marks the corresponding invoice as paid, recording the date.

**Why this priority**: This is the actual signal staff need before manually triggering a license send (018) — without it, "has this company paid" has no system of record at all.

**Independent Test**: Mark an unpaid invoice paid; confirm its status and paid date update, and that it now appears in a "paid" filter/list.

**Acceptance Scenarios**:

1. **Given** an unpaid invoice, **When** an owner/admin marks it paid, **Then** its status becomes "paid" and the paid date is recorded.
2. **Given** an already-paid invoice, **When** viewed, **Then** it is clearly distinguishable from unpaid ones in any list.

---

### User Story 3 - View a company's billing history (Priority: P2)

An owner/admin viewing a company (017) can see all its invoices — paid and unpaid — to answer "has this company paid" without checking anywhere else.

**Why this priority**: Valuable for day-to-day support/collections conversations, but the core record-keeping (US1/US2) already delivers the feature's main value on its own.

**Independent Test**: Open a company with multiple invoices; confirm all of them list with correct status/amount/dates.

**Acceptance Scenarios**:

1. **Given** a company with several invoices, **When** an owner/admin views that company, **Then** all its invoices are listed with status, amount, and dates.

---

### Edge Cases

- What happens to an invoice for a company whose package later changes? The invoice keeps its own recorded amount — it is not re-derived from the company's current package (invoices are historical records, not live calculations, matching this codebase's existing "financial snapshot" convention).
- What happens if an invoice is created with a due date in the past? Allowed — the system does not block backdated record-keeping for catching up on existing accounts.
- Can an invoice be deleted? Not in this feature's scope — invoices are financial records; only cancellation (a status), not deletion, is supported, matching this codebase's general no-hard-delete-of-financial-history posture.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST allow an owner/admin to create a billing record (invoice) for an existing company, capturing an amount, a due date, and an optional note.
- **FR-002**: A new invoice MUST start in "unpaid" status.
- **FR-003**: The system MUST allow an owner/admin to mark an unpaid invoice as "paid," recording the date it was marked paid.
- **FR-004**: The system MUST allow an owner/admin to mark an invoice "cancelled" instead of paid (e.g., the company backed out) — cancelled invoices MUST remain visible in history, not deleted.
- **FR-005**: The system MUST allow an owner/admin to view all invoices belonging to a given company, showing status, amount, due date, and paid date (when applicable).
- **FR-006**: The system MUST allow filtering the overall invoice list by status (unpaid/paid/cancelled).
- **FR-007**: An invoice's recorded amount MUST NOT change automatically if the company's package changes later — it is a historical snapshot.
- **FR-008**: Billing record management MUST be restricted to the owner/admin roles, consistent with Company Onboarding's existing gating (feature 017).

### Key Entities

- **Invoice**: A billing record for one company — amount, due date, status (unpaid/paid/cancelled), paid date (when applicable), an optional note, and which company it belongs to.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An owner/admin can create an invoice for a company in under 1 minute.
- **SC-002**: Marking an invoice paid takes a single action and is reflected immediately in that company's billing history.
- **SC-003**: Staff can determine whether a specific company has an outstanding unpaid invoice without consulting any system other than this one.

## Assumptions

- **No payment gateway integration.** Payment happens outside this app (bank transfer, etc.); this feature only records the *result* (paid/unpaid), matching this codebase's existing manual-confirmation patterns (feature 018's own "vendor manually triggers" license send).
- **Invoice amount is entered manually, not derived from Package pricing.** Feature 017's `Package` entity does not carry a price field today (only a descriptive `license_tier`) — adding one is out of scope here; the invoice's amount field is simply free-entry.
- **No automatic linkage to license generation (018).** Marking an invoice paid does not itself trigger `license:generate` — that remains the separate, deliberate vendor-side action feature 018 already defines. This feature only makes "has this company paid" visible to whoever performs that action.
- **Lives inside the existing Company Onboarding admin area (017)**, reusing its `companies` menu-key gating rather than introducing a new one — this is additive record-keeping on an existing entity, not a new subsystem.
- **No recurring/subscription billing.** One invoice = one billing event, consistent with 018's own "no expiry/subscription model" assumption for licenses.
