**Classification**: INTERNAL

# Specification Quality Checklist: Sales Page — Tables View

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-11
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs) — the spec names no framework or endpoint; the "Current state" section only describes existing screens as FACT
- [x] Focused on user value and business needs — monitoring the floor and reaching an order's details quickly
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain — every open point has a stated ASSUMPTION (who sees it, scope of "details", statuses, grouping, 45-minute rule)
- [x] Requirements are testable and unambiguous (FR-001…FR-023)
- [x] Success criteria are measurable (SC-001…SC-008)
- [x] Success criteria are technology-agnostic
- [x] All acceptance scenarios are defined (4 stories)
- [x] Edge cases are identified (inactive table with open order, concurrent changes, 60 tables, hidden tab/offline, takeaway without label, rollover, filters not applying)
- [x] Scope is clearly bounded (read-only, no per-outlet, no pre-bill/guest states, no order list changes)
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows (toggle, board, click-through, permissions)
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- Image 27 shows states and figures the data cannot support today (pre-bill printed, guest counts, outlet grouping). The spec deliberately drops them (FR-011) rather than faking them; adding them later needs new data, not just UI.
- The one decision most worth confirming before planning: **who may see the board** (assumed: the roles that already see the Floor screen). Recorded under Assumptions.
