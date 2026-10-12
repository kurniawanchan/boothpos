**Classification**: INTERNAL

# Specification Quality Checklist: Outlets (replacing Sellers) — Outlet-Scoped Operations and Per-Outlet Menu

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-11 · **Re-validated**: 2026-10-11 after Q1 = C, Q2 = B and the stock/transfer answer (Q3)
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — addresses `/outlets` and `/artists` appear only because the owner asked for the endpoint change explicitly
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain (Q1 = C, Q2 = B recorded under "Clarifications")
- [x] Requirements are testable and unambiguous (FR-001…FR-040)
- [x] Success criteria are measurable (SC-001…SC-014)
- [x] Success criteria are technology-agnostic
- [x] All acceptance scenarios are defined (11 stories)
- [x] Edge cases are identified (migration defaults, deactivation, code immutability, user moves, open shifts/tabs, many outlets, import, stock, cash, PO/BOM, time zone)
- [x] Scope is clearly bounded (no channels/quotas, per-outlet releases, devices, copy-menu, transfer slips/approval/pricing; see Assumptions)
- [x] Dependencies and assumptions identified (constitution amendment, rename in place, defaults, history attribution)

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows (rename, rename-everywhere, list, add/edit, item page, shifts/orders, tables/floor, users/selector, reports)
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- **Assumption to confirm before planning is finalised:** "product should relate to each outlet" is read as *a relation to every outlet in addition to a required home outlet*. If the owner meant to retire the single owner/home outlet, item codes, the purchase-order/BOM rule and legacy attribution need a new anchor.
- **Confirmed by the owner:** the home-outlet reading above is correct; stock **and transfers** are per outlet (now in scope: US10, US11, FR-031…FR-040, SC-011…SC-014).
- **Size warning for planning:** this is no longer a rename plus UI — it adds outlet scoping to shifts, orders, tables, users, reports, dashboard, the Products pages, per-outlet stock for items and materials, and inter-outlet transfers (it absorbs the gated 042 Stage B outlet foundation and 043 Stage B). Plan in stages with a reconciliation step after each data migration.
