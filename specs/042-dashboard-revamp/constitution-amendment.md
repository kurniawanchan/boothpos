**Classification**: INTERNAL

# Proposed Constitution Amendment 2.0.0 — outlets, one shared server, registered devices

**Status**: DRAFT; version level decided (MAJOR, 2026-10-11) — text still awaits ratification by the product owner. **Not applied** to `.specify/memory/constitution.md` until the owner ratifies it (task T001 of this feature).
**Supersedes**: the amendment text in `specs/041-back-office-ui-redesign/plan.md` (Complexity Tracking) and `research.md` Decision 2. That text covered outlets and a shared server only. This version is rewritten to match the 042 requirement (Dashboard with "Semua outlet", a business date, registered devices that can be offline, per-outlet cash limits) and adds what 041 left out: **registered devices** and **how client-reported data is treated**. 041 now points here so there is one source.
**Governance basis**: Constitution "Governance" — an amendment updates the version, `Last Amended`, and prepends a Sync Impact Report.

## Why the current text cannot stay (FACT)

Constitution v1.0.0, "Stack & Environment Constraints", first paragraph: *"BoothPOS ships as a one-time license installed and run entirely on a single local machine per store (Laravel API + Vue SPA on one box, no cloud tier, no multi-tenancy) — architectural decisions MUST account for this, not assume a hosted multi-server deployment."*

The 042 requirement conflicts with it in three ways:

| 042 requirement | Conflict with v1.0.0 |
|---|---|
| "Semua outlet" figures, one Dashboard over several outlets (FR-026, FR-027) | One machine per store means no data shared between stores. |
| Devices register, report in, are "offline" or "behind" (FR-029) | Assumes the POS and the server are one box; there is no "device" distinct from the machine. |
| Business date and outlet stamped by the system for events that may reach the server late (FR-028) | Principle IV forbids trusting client-supplied values; a late device event carries a client-reported time. The principle must say what may and may not be taken from a device. |

## Amendment text

### A. Replace the first paragraph of "Stack & Environment Constraints"

> BoothPOS serves **one organisation**, which has **one or more outlets**. It runs in one of two supported shapes: **(a)** entirely on one local machine (the original deployment, unchanged — a single default outlet, nothing new visible), or **(b)** on **one shared server** that every outlet's devices reach over HTTPS. There is always exactly **one server and one database per organisation**, and that server is the only writer of business data. **Multi-tenancy across unrelated customers remains out of scope.** Architectural decisions MUST work in shape (a) and MUST NOT assume more than one server.
>
> A **device** is a registered POS terminal belonging to one outlet. A device may be offline for a time; it never holds authority over business data. Anything a device reports is either a *request* the server validates and recomputes, or *advisory status* shown to people and never used in a financial total.

The three bullets that follow (MySQL 8; destructive git operations) stay unchanged.

### B. Add one paragraph to Principle IV (Security), after the sentence on client-supplied values

> A device's **identity** (and therefore the outlet it belongs to) is established by the server from a credential it issued; an **outlet or business date supplied in a request body is never trusted**. For an event that reaches the server late, the only client-supplied time the server may use is the time the event happened, and only after bounding it (not before the device's last accepted contact, not in the future); the business date is then derived from that time with the outlet's own timezone and cut-off. Counts and amounts that a device reports about itself (unsynced events, cash taken offline) are **advisory**: they MAY be displayed and compared against limits, and MUST NOT feed any sales, payment, stock or cash total.

### C. Add one sentence to Principle V (Performance)

> A figure that is cached for display (for example a Dashboard summary) MUST be keyed by every input that changes its content — data mode, the user's permitted outlets, the selected outlet and period — and MUST state the time it was computed.

### D. No change to Principles I–III

- **I (single write path)**: unchanged. Outlet scoping uses one scope and one filter helper (research Decision 4 of 041), not per-query `where`s.
- **III (hidden means unavailable)**: unchanged. It already requires the outlet selector, device settings and links to pages a role cannot open to be hidden, not disabled.

### E. Version and governance

- **1.0.0 → 2.0.0 (MAJOR) — decided by the product owner on 2026-10-11 (O1).** The owner treats "single machine, no multi-tenancy" as a defining rule of the product, so redefining it is a MAJOR change under Governance ("a principle is … redefined in a way that is incompatible with how it was previously applied"). Practically: the Stack section is rewritten, and one paragraph each is added to Principles IV and V. A single-machine install keeps working with no behaviour change.
- `Last Amended` becomes the ratification date.

## Sync Impact Report (to prepend to the constitution when ratified)

```text
Version change: 1.0.0 → 2.0.0 (MAJOR: the single-machine / no-multi-tenancy rule is redefined)
Modified sections: Stack & Environment Constraints (first paragraph replaced),
  Principle IV (one paragraph added: device identity, bounded client time,
  advisory device figures), Principle V (one sentence added: cache keys and
  computed-at time).
Added/removed principles: none.
Templates checked: plan-template.md (generic Constitution Check — no change),
  spec-template.md, tasks-template.md (no constitution references).
Docs to update with DATED NOTES (not rewritten history): PRD §1/§9.2/§10.2;
  CLAUDE.md first section and the "Non-negotiable environment constraints"
  list; docs/RUNBOOK.md deployment sections; README history.
Follow-up TODOs: hosting guidance for the shared server (docs/deploy-oracle-cloud.md
  already exists for one store); licensing per organisation vs per outlet/device
  (open decision, default: no limit enforced).
```

## What this amendment deliberately does NOT allow

- More than one server or database per organisation, or replication between installs.
- Sharing data between unrelated customers.
- A device deciding amounts, outlet, number sequences or business date.
- Cloud-only operation as a requirement: shape (a) must keep working offline-from-internet on one machine.

## Documents that change when ratified (checklist for T001)

1. `.specify/memory/constitution.md` — text above, Sync Impact Report, version, `Last Amended`.
2. `CLAUDE.md` — dated note next to "This is a one-time license installed locally per store" and the environment-constraints list; do not delete the old sentences.
3. `docs/PRD-POS-Event-Multivendor.md` — dated notes in §1, §9.2, §10.2.
4. `specs/041-back-office-ui-redesign/plan.md` and `research.md` Decision 2 — pointer to this file (done in the same change as this draft).
5. `docs/RUNBOOK.md` — shared-server deployment note.
