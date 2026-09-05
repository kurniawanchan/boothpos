# Feature Specification: Company Onboarding

**Feature Branch**: `017-company-onboarding`

**Created**: 2026-09-05

**Status**: Implemented (2026-09-05) — all 3 user stories + Business Type foundational CRUD verified end-to-end via real `php artisan test` runs (445/445 passing, 18 new tests) and a real browser walkthrough of the full onboard → activate → login flow (Playwright), including one real bug found and fixed during that browser verification (a missing `nav.*_subtitle` i18n key, plus a hyphenated-route-name title-fallback issue mirroring the existing `purchase-orders` precedent). See `plan.md`'s Implementation Notes and `tasks.md` (52/52 complete).

**Input**: User description: "buat sistem onboarding company dengan ada pilihan bisnis, pilihan paket (ada databasenya), detail company, kontak, user login owner, sistem aktivasi via kode yg dikirim ke email. jadi rencananya akan ada fitur tertentu untuk jenis bisnis tertentu."

**Scope clarified with product owner before drafting** (see Assumptions for full detail):
- This is an **internal onboarding/CRM-style tracker inside the existing single BoothPOS installation** — a new manageable entity alongside existing ones like Customer/Vendor/Artist, NOT a pivot to a multi-tenant/hosted runtime. It does not provision a separate, independent BoothPOS install per company.
- "Package" means **both** a billing/subscription plan **and** the licensing tier it grants (mapping onto the existing Pro/Master `multi_artist_enabled` concept).
- "Business type" is captured as data only for now — no feature-gating logic is implemented in this feature; it exists so future, separately-specified work can key off it without a schema change.

## User Scenarios & Testing *(mandatory)*

### User Story 1 - Onboard a new company (Priority: P1)

An owner/admin staff member onboards a new prospective company: they select the company's business type, select a package (billing plan + licensing tier) from the available options, fill in company details and the primary contact's information, and set the initial credentials for that company's owner login. On submission, the system creates the company record in a pending state and sends a one-time activation code to the contact's email address.

**Why this priority**: Without this, there is no way to start the onboarding pipeline at all — every other capability in this feature depends on a company record existing first.

**Independent Test**: Submit the onboarding form with valid business type, package, company details, contact, and owner credentials. Confirm a company record is created with status "pending activation" and an activation code is generated and dispatched to the contact email (or recorded as skipped/failed with a visible reason, matching this codebase's existing notification-audit pattern).

**Acceptance Scenarios**:

1. **Given** an owner/admin is on the onboarding form, **When** they submit valid business type, package, company details, contact info, and a unique owner username/password, **Then** a new company record is created with status "pending activation," an inactive owner user account is created, and an activation code is generated and emailed to the contact address.
2. **Given** the onboarding form is submitted with an owner username that already exists, **When** validation runs, **Then** the submission is rejected with a field-level error and no company/user record is created.
3. **Given** the outbound mail system is not configured (matching this codebase's existing "mailer default is `log`" convention), **When** onboarding is submitted, **Then** the company and inactive owner user are still created, and the activation code's delivery is recorded as skipped with a visible reason — not silently lost.

---

### User Story 2 - Activate the company via emailed code (Priority: P1)

The company's contact person (or the staff member helping them) enters the activation code they received by email. On a valid, unexpired code, the company's status flips to active and its owner user account becomes usable — the owner can now log in with the credentials set during onboarding.

**Why this priority**: Onboarding a company that can never actually be used is pointless — activation is what turns a pending record into a working account, delivering the actual value of the feature.

**Independent Test**: Using a company created in User Story 1, submit its emailed activation code and confirm the company's status becomes active and its owner user can successfully log in afterward (and could not before).

**Acceptance Scenarios**:

1. **Given** a pending company with a valid, unexpired activation code, **When** the correct code is submitted, **Then** the company's status becomes "active," the owner user account becomes active, and a login attempt with that owner's credentials succeeds.
2. **Given** a pending company, **When** an incorrect code is submitted, **Then** activation is rejected with a clear error and the company/owner remain inactive.
3. **Given** an activation code has expired, **When** it is submitted, **Then** activation is rejected with an error explaining the code expired, and the staff member can request a new code be sent.
4. **Given** a company is already active, **When** its (now-consumed) activation code is submitted again, **Then** the request is rejected as already used.

---

### User Story 3 - Manage available packages (Priority: P2)

An owner/admin maintains the list of packages that can be offered during onboarding — each package has a name, description, and the licensing tier it grants — without needing a code change to add, rename, or retire one.

**Why this priority**: Onboarding (User Story 1) depends on a package list existing, but the list itself changes far less often than companies are onboarded — this is enabling infrastructure, not the primary daily workflow.

**Independent Test**: Create a new package, confirm it appears as a selectable option on the onboarding form; deactivate a package and confirm it no longer appears as selectable for new onboarding but remains visible/intact on companies that already selected it historically.

**Acceptance Scenarios**:

1. **Given** an owner/admin creates a new package with a name and a licensing tier, **When** they view the onboarding form afterward, **Then** the new package appears as a selectable option.
2. **Given** a package is deactivated, **When** the onboarding form is viewed, **Then** the deactivated package no longer appears as a new selectable option, but any existing company already recorded against it keeps showing its package correctly.
3. **Given** a package is referenced by at least one existing company, **When** an owner/admin attempts to delete (not deactivate) it, **Then** the deletion is blocked, consistent with this codebase's existing delete-guard pattern for referenced master data.

---

### Edge Cases

- What happens if the owner/admin navigates away or the request fails after the company record is created but before the activation email send completes? The company record and inactive owner user must still exist and be visible for a manual resend, not left as an orphaned partial state.
- What happens when a staff member requests a resend of the activation code? The previous code must stop working the moment a new one is issued (never two simultaneously valid codes for the same company).
- What happens when the contact email address is mistyped and the code can never be received? Staff must be able to edit the contact email and resend before the company is otherwise unusable.
- What happens if two companies are onboarded with different contact people who happen to share the same email address? This must be allowed — the email is a contact channel per company, not a unique account identifier.
- What happens to a company's data if it is never activated? It remains visible as "pending activation" indefinitely — this feature does not include automatic expiry/cleanup of abandoned onboarding records.

## Requirements *(mandatory)*

### Functional Requirements

- **FR-001**: The system MUST allow an owner/admin to create a new company record capturing: business type (selected from a maintained list), package (selected from active packages), company details (name and other identifying information), and a primary contact (name, email, phone).
- **FR-002**: The system MUST allow an owner/admin to set an initial username and password for the company's owner login as part of onboarding, validated against the same uniqueness rules as any other user account in this system.
- **FR-003**: The system MUST create the owner user account in an inactive state at company-creation time — it MUST NOT be usable to log in until activation succeeds.
- **FR-004**: The system MUST generate a single-use activation code at company-creation time and attempt to send it to the contact's email address.
- **FR-005**: Every activation-code send attempt MUST be recorded (delivered, skipped-not-configured, or failed with a reason) — the outcome MUST be visible to staff, never silent, consistent with this codebase's existing notification-audit pattern.
- **FR-006**: The system MUST allow staff to trigger a resend of the activation code, which invalidates any previously issued, unused code for that company.
- **FR-007**: Submitting a valid, unexpired, unused activation code for a pending company MUST mark the company active and MUST activate its associated owner user account.
- **FR-008**: Submitting an incorrect, expired, or already-used activation code MUST be rejected with a specific, distinguishable reason (wrong code / expired / already used) and MUST NOT change the company's or owner user's state.
- **FR-009**: The system MUST allow an owner/admin to create, edit, and deactivate packages (name, description, licensing tier); a deactivated package MUST NOT appear as selectable for new onboarding but MUST remain intact and visible on any company already referencing it.
- **FR-010**: A package referenced by at least one company MUST NOT be deletable outright — only deactivatable — mirroring this codebase's existing delete-guard convention for referenced master data.
- **FR-011**: The system MUST allow an owner/admin to view a list of companies with their current status (pending activation / active) and drill into one company's full onboarding detail.
- **FR-012**: Company creation, activation, resend, and package management MUST all be restricted to the owner/admin roles, consistent with how this codebase gates other administrative/master-data operations.
- **FR-013**: The business type captured on a company MUST come from a maintained list (not free text) so that future, separately-specified work can associate behavior with a specific business type without a schema change — no feature-gating logic itself is in scope for this feature.

### Key Entities

- **Company**: A prospective or onboarded customer record — business type, package, company details, primary contact, current status (pending activation / active), and its associated owner user account.
- **Package**: A selectable offering shown during onboarding — name, description, the licensing tier it grants, and whether it is currently active/offerable.
- **Business Type**: A maintained, named category a company can be classified under — currently descriptive only, with no attached feature behavior.
- **Activation Code**: A single-use, time-limited code tied to one company, generated at onboarding (and regenerated on resend), whose successful submission activates that company and its owner user.

## Success Criteria *(mandatory)*

### Measurable Outcomes

- **SC-001**: An owner/admin can complete the onboarding form for a new company (all required fields) in under 5 minutes.
- **SC-002**: 100% of activation-code send attempts are auditable afterward — for any company, staff can see whether its code was delivered, skipped, or failed, and why.
- **SC-003**: A company's owner can log in successfully within one attempt immediately after a valid activation code is submitted — no delay, no separate provisioning step required.
- **SC-004**: An invalid, expired, or reused activation code is rejected 100% of the time, with the specific reason distinguishable to the person attempting activation.
- **SC-005**: Adding a new package or business type to the list requires no code change — an owner/admin can do it entirely through the existing admin UI.

## Assumptions

- **Single-installation scope, not multi-tenancy**: This feature adds Company/Package/Business Type as new administratively-managed entities within this one existing BoothPOS installation's database — it does not create per-company data isolation, does not provision a separate running instance per company, and does not change this product's existing single-machine, no-cloud-tier architecture (`CLAUDE.md`). A company's owner user is a normal row in the existing single `users` table, subject to the same global username uniqueness as every other user today.
- **"Package" grants a licensing tier**: A package's licensing-tier field is assumed to map onto this codebase's existing Pro/Master concept (`multi_artist_enabled` / `LicenseGate`) rather than introducing a second, competing licensing mechanism — the exact mapping (e.g. package X sets `multi_artist_enabled = true`) is an implementation-planning decision, not a product-scope one.
- **Business type has no attached behavior yet**: Per the product owner's explicit clarification, this feature stores business type as a maintained lookup list only — no menu/feature gating keyed off it is implemented here. A future, separately-specified feature is expected to consume this data.
- **Role gating**: Company, Package, and activation operations are assumed to be owner/admin-only (no cashier/inventory access), matching this codebase's existing pattern for other administrative/master-data operations (e.g. Settings, Roles) rather than the broader `canManageMasterData()` tier used for day-to-day inventory work.
- **Activation code delivery reuses the existing email pattern**: Sent synchronously (no queue worker exists in this single-machine deployment, per existing convention), with every attempt recorded and a `mail.default === 'log'` (unconfigured mail) attempt recorded as skipped rather than treated as a hard failure — mirroring the existing preorder-notification pattern in this codebase.
- **No automatic expiry of abandoned onboarding**: A company left in "pending activation" indefinitely is out of scope for automatic cleanup in this feature — it simply remains visible as pending.
- **DEMO/LIVE data-mode scoping**: `Company` is assumed to be business/transactional data (like `Customer`/`Vendor`) and therefore tagged with this codebase's existing `data_mode` mechanism; `Package` and `Business Type` are assumed to be administrative/reference data (like `PaymentChannel`) and therefore NOT mode-scoped — visible identically regardless of the active DEMO/LIVE mode.
