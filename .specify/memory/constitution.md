<!--
Sync Impact Report
==================
Version change: [TEMPLATE] → 1.0.0 (initial ratification)
Modified principles: n/a (first authored version — all five are new)
Added sections:
  - Core Principles I–V (Code Quality & Maintainability, Testing Standards,
    User Experience Consistency, Security, Performance & Optimization)
  - Stack & Environment Constraints
  - Documentation & Change Discipline
  - Governance
Removed sections: none (template placeholders replaced, none intentionally
  left blank)
Templates requiring updates:
  ✅ .specify/templates/plan-template.md — "Constitution Check" section is
     generic ("[Gates determined based on constitution file]"), no hardcoded
     principle names to update. No change needed.
  ✅ .specify/templates/spec-template.md — no constitution references found.
  ✅ .specify/templates/tasks-template.md — no constitution references found.
  ⚠ CLAUDE.md — already documents most of these rules in its own words (this
     constitution formalizes them as non-negotiable principles and cites
     concrete examples from this codebase's own history). No edit made in
     this pass; flagged so a future amendment can decide whether to
     cross-reference or fold one into the other rather than maintain two
     overlapping sources.
Follow-up TODOs:
  - RATIFICATION_DATE resolved to 2026-09-02 (the date this constitution was
    first authored — no earlier ratification event exists for this project,
    so today is genuinely the ratification date, not a placeholder).
-->

# BoothPOS Constitution

## Core Principles

### I. Code Quality & Maintainability (Clean Code, DRY, SOLID)
Business logic MUST live in `app/Services/`, not in controllers — controllers
validate, delegate, and shape responses (Single Responsibility). A concern
that has more than one caller MUST have exactly one implementation, not a
duplicated copy per call site: stock mutations go through
`StockService::applyMovement()` only, sensitive-action logging goes through
`ActivityLogger` only, payment recording goes through `PaymentRecorder`
only. Introducing a second path that bypasses one of these single sanctioned
write paths is a defect, not a valid shortcut, even under time pressure.
Code MUST NOT be more abstract or more configurable than the current
requirement demands — no speculative interfaces, no unused extension
points, no framework-of-the-day patterns applied because they are
available. Comments explain **why** a non-obvious decision was made (a
hidden constraint, a workaround, a past bug), never restate **what** the
code already says through naming.

**Rationale**: This codebase has already paid for the alternative —
duplicated write paths and controller-embedded business logic are exactly
the shape of bug that let a real invariant (e.g., stock movements staying
append-only and auditable) get silently violated. Simplicity and a single
source of truth per concern are what make that invariant enforceable at
all.

### II. Testing Standards (Verify, Don't Assume)
A change is not complete until it has been proven to work, not merely
believed to work. Backend changes MUST be accompanied by tests under
`tests/Feature/` and MUST pass against real MySQL (`php artisan test`) —
SQLite MUST NOT be used or defaulted to for this project, because two
migrations depend on MySQL-only `CHECK` constraint syntax that SQLite
cannot execute. Frontend changes MUST be accompanied by tests under
`qa-tests/`, and any change to a screen a user directly interacts with
MUST additionally be exercised in a real running browser against the real
API before being declared done, with the browser console checked for
errors — a unit test in isolation is not a substitute for seeing the
feature actually work. A reported "done" or "fixed" MUST be backed by
evidence gathered in the current work session (a test run, a browser
check), not inferred from the shape of the diff.

**Rationale**: This project's own history contains concrete cases — a
payment-channel picker that silently failed to auto-select, a missing named
route that only crashed once a real record had the right shape, an export
endpoint that silently produced an empty file — that passed a superficial
read of the code and were only caught by actually running the system. That
cost real rework each time; skipping verification is never actually faster
once the rework is counted.

### III. User Experience Consistency
All UI MUST be built from the design tokens declared in
`resources/css/app.css` (`@theme` CSS variables, Tailwind v4 CSS-first) —
raw hex literals and one-off magic values MUST NOT appear in component
styling. This product does not depend on any internal-only design system,
because it ships to external customers who install it themselves. Every
screen MUST honor the same API-error convention (`422` → field validation,
`409` → business-rule conflict, `403` → role/ownership denial) rather than
inventing per-screen error handling. Functionality a user's role cannot
use MUST be hidden entirely from that role's UI, never shown in a disabled
or clickable-but-403 state — a control the user can see but not use is
worse UX than no control at all. All UI copy, code comments, and commit
messages are written in Indonesian, matching this codebase's established
convention; new contributions MUST NOT introduce English inconsistently.

**Rationale**: A shopkeeper or cashier working a live event booth has no
time to puzzle out why a button doesn't work — consistent, predictable
interaction patterns and honest affordances (hidden means truly
unavailable) reduce the cognitive load of an already time-pressured job.

### IV. Security
Client-supplied values for money, totals, discounts, and stock deltas MUST
NOT be trusted — the server always recomputes and is the sole source of
truth for what gets persisted or charged. Every access-control decision
MUST be enforced server-side; hiding a button or menu item in the UI is a
cosmetic convenience only. This codebase enforces authorization through
three coexisting mechanisms (`FormRequest::authorize()` overrides, inline
role checks in controllers, and Policy classes) — before concluding any
endpoint is unguarded, all three MUST be checked, not just the one visible
in the file currently open. Files containing sensitive user-submitted
content (payment proofs) MUST be stored on a private disk and served only
through an authorizing endpoint, never a public URL. Historical financial
data (an order's recorded price, cost, and identity fields) MUST be
persisted as an immutable snapshot at the moment of the transaction, never
re-derived from current master data when a report is later opened — so
that a later price or product change cannot silently rewrite a past
transaction's numbers. Sensitive mutations (deleting master data, stock
adjustments, price changes, bulk imports) MUST write an audit-log entry
inside the same database transaction as the mutation itself, so a
rolled-back action never leaves a log claiming it happened.

**Rationale**: This is a point-of-sale system handling real money at a live
event, with multiple roles (owner, admin, cashier, inventory) whose access
must actually differ, not just appear to differ. A trusted-client bug or an
authorization gap here is a direct path to financial loss or an
unreconcilable audit trail — not a cosmetic defect.

### V. Performance & Optimization
Database access MUST avoid N+1 query patterns — relations that a response
needs MUST be eager-loaded, not lazy-loaded per row in a loop. A list
endpoint's default response shape MUST stay lean; a heavier payload (e.g.
nested variants on a product list) is opt-in via an explicit parameter
rather than bloating every caller by default. A frontend dependency needed
by only one feature (e.g. client-side PDF/image generation) MUST be
dynamically imported so it ships as its own code-split chunk, not inflating
the application's initial bundle — verified by inspecting the production
build output, not assumed. Reports that must reflect live, always-correct
state (e.g. artist settlements) MAY recompute on every read rather than
trust a stale cache, but that choice MUST be deliberate and documented,
not an accident of not having thought about caching at all.

**Rationale**: A booth's point-of-sale screen and its reports are used
under real time pressure during a live event; a slow product grid or a
bloated initial page load has a direct, felt cost to the person running
the register, not just an abstract metric.

## Stack & Environment Constraints

BoothPOS ships as a one-time license installed and run entirely on a single
local machine per store (Laravel API + Vue SPA on one box, no cloud tier,
no multi-tenancy) — architectural decisions MUST account for this, not
assume a hosted multi-server deployment.

- **MySQL 8 is required** for both the application database and the test
  database; `DB_CONNECTION` MUST be left to environment-specific
  configuration (`.env.testing`), never hardcoded to `sqlite` anywhere in
  version-controlled config.
- Destructive or hard-to-reverse git operations (force-push, history
  rewrite, or pushing to a remote at all) require the developer's explicit,
  per-instance authorization — a prior approval does not carry forward to
  later, similar actions.

## Documentation & Change Discipline

- `docs/openapi-pos-mvp.yaml` MUST be updated in the same commit as any
  route or response-shape change — the spec is a contract, not
  documentation-after-the-fact.
- Scope changes relative to the original PRD (features explicitly cut from
  MVP, or new capabilities added after it) MUST be recorded as an explicit,
  dated note in the PRD rather than silently rewriting prior scope
  decisions as if they never existed.
- A bug fix or non-obvious design decision discovered through real
  execution (not just static reading) SHOULD be recorded in code as a
  comment explaining *why*, following this codebase's existing
  `BUG YANG DITEMUKAN & DIPERBAIKI` convention.

## Governance

This constitution supersedes ad hoc practice wherever the two conflict. Any
change to a Core Principle, or to the sections above, is an amendment to
this document and MUST update the version number according to semantic
versioning:

- **MAJOR** — a principle is removed or redefined in a way that is
  incompatible with how it was previously applied.
- **MINOR** — a new principle or materially expanded section is added.
- **PATCH** — wording, clarification, or non-semantic correction.

Every amendment MUST update `Last Amended` below and prepend a Sync Impact
Report (as an HTML comment at the top of this file) describing what
changed and which dependent templates or docs were checked for
consistency. A review that touches behavior governed by a Core Principle
SHOULD verify compliance with that principle explicitly, not merely assume
it.

**Version**: 1.0.0 | **Ratified**: 2026-09-02 | **Last Amended**: 2026-09-02
