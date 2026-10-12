**Classification**: INTERNAL

# Proposed Constitution Amendment 2.0.0 — one organisation, one or more outlets

**Status**: **DRAFT — awaiting the product owner's approval of the wording.** Nothing in `.specify/memory/constitution.md`, `CLAUDE.md` or the PRD is changed until the owner approves this text (task T004 → T005 of feature 045).
**Basis**: owner decision 2026-10-11 — outlets are introduced by renaming the existing *seller* record (feature 045), and this amendment is ratified as part of that feature. It supersedes the earlier draft `specs/042-dashboard-revamp/constitution-amendment.md` (devices, heartbeats and offline device figures are **not** part of feature 045 and are removed from the text below).
**Governance basis**: Constitution "Governance" — an amendment updates the version, `Last Amended`, and prepends a Sync Impact Report.

## Why the current text cannot stay (FACT)

Constitution v1.0.0, "Stack & Environment Constraints", first paragraph: *"BoothPOS ships as a one-time license installed and run entirely on a single local machine per store (Laravel API + Vue SPA on one box, no cloud tier, no multi-tenancy) — architectural decisions MUST account for this, not assume a hosted multi-server deployment."*

Feature 045 requires several outlets to share one set of master data (menu, users, reports) with outlet-scoped operations (shifts, orders, tables, stock, transfers). That is incompatible with "one local machine per store" as the only supported shape.

## Amendment text

### A. Replace the first paragraph of "Stack & Environment Constraints"

> BoothPOS serves **one organisation**, which has **one or more outlets**. It runs in one of two supported shapes: **(a)** entirely on one local machine (the original deployment, unchanged — one outlet, nothing outlet-related visible), or **(b)** on **one shared server** that every outlet's devices reach over HTTPS. There is always exactly **one server and one database per organisation**, and that server is the only writer of business data. **Multi-tenancy across unrelated customers remains out of scope.** Architectural decisions MUST work in shape (a) and MUST NOT assume more than one server. An **outlet** is a place that sells the organisation's menu; every operational record (shift, order, table, stock movement) belongs to exactly one outlet.

The three bullets that follow (MySQL 8; destructive git operations and remote pushes) stay unchanged.

### B. Add one paragraph to Principle IV (Security), after the sentence on client-supplied values

> The **outlet** of a record is decided by the server — from the user's assignment, the open shift, or the owning record — and is **never taken from a request body**. A request may only *narrow* what a user sees within the outlets that user is assigned to; asking for an outlet the user is not assigned to is refused, and a record outside the user's outlets is not found. Access control for outlet data MUST be enforced by one shared mechanism applied to every read path (lists, details, figures, exports, reports), not by per-query conditions.

### C. Add one sentence to Principle V (Performance)

> A figure or list that is cached or precomputed for display MUST be keyed by every input that changes its content — data mode, the user's permitted outlets, the selected outlet and period — and MUST state the time it was computed.

### D. Principles I–III unchanged

- **I (single write path)**: unchanged. The new scoping uses one scope and one filter helper; stock still changes only through `StockService`/`MaterialStockService`; stock transfers are a service that calls them.
- **III (hidden means unavailable)**: unchanged and relied upon — every outlet-specific control is hidden when only one outlet is visible or the role lacks the permission.
- **II (verification)**: unchanged; the feature adds a reconciliation requirement (counts and totals before = after each migration) which lives in the plan, not in the constitution.

### E. Version and governance

- **1.0.0 → 2.0.0 (MAJOR)**: the single-machine / no-multi-tenancy premise is redefined (Governance: "a principle is … redefined in a way that is incompatible with how it was previously applied"). A single-machine, single-outlet install keeps working with no behaviour change.
- `Last Amended` becomes the approval date.

## Sync Impact Report (to prepend to the constitution when approved)

```text
Version change: 1.0.0 → 2.0.0 (MAJOR: the single-machine / no-multi-tenancy premise is redefined)
Modified sections: Stack & Environment Constraints (first paragraph replaced),
  Principle IV (one paragraph added: outlet is server-derived, never from a request;
  one shared mechanism for outlet access control), Principle V (one sentence added:
  cache keys and computed-at time).
Added/removed principles: none.
Templates checked: plan-template.md (generic Constitution Check — no change),
  spec-template.md, tasks-template.md (no constitution references).
Docs to update with DATED NOTES (not rewritten history): PRD §1/§9.2/§10.2;
  CLAUDE.md "What this is" and the environment-constraints list; docs/RUNBOOK.md
  deployment/migration sections; README history.
Follow-up TODOs: hosting guidance for shape (b), a shared server (docs/deploy-oracle-cloud.md
  exists for one store); licensing per organisation vs per outlet (decided in 045: the licence tier
  limits the number of outlets — Pro one, Master several).
```

## What this amendment deliberately does NOT allow

- More than one server or database per organisation, or replication between installs.
- Sharing data between unrelated customers.
- A client (browser or device) deciding the outlet, amounts, number sequences or business date.
- Cloud-only operation as a requirement: shape (a) must keep working with no internet on one machine.

## Documents that change when approved (checklist for T005)

1. `.specify/memory/constitution.md` — text above, Sync Impact Report, version, `Last Amended`.
2. `CLAUDE.md` — dated note beside "one-time license installed locally per store" and in the environment-constraints list; **do not delete** the old sentences.
3. `docs/PRD-POS-Event-Multivendor.md` — dated notes in §1, §9.2, §10.2 (scope: sellers are renamed outlets; outlet scoping, per-outlet menu/stock/transfers added).
4. `docs/RUNBOOK.md` — migration + rehearsal + backup note for the outlet stages.
5. `specs/045-outlets-per-outlet-menu/evidence-stage-0.md` — the owner's approval (who/when/final wording).

## Decisions needed from the owner

1. **Approve the wording** above (or send edits). Nothing else in feature 045 is released before this.
2. Confirm the **licence rule**: Pro = one outlet, Master = several (as today's Pro/Master seller limit).
3. Confirm that **shape (b)** (one shared server) is being *permitted* by this amendment but **not built** by feature 045 (no hosting changes, no new deployment tooling).
