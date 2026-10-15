# Specification Quality Checklist: Subtotal per Seller in the Pre-order Report

**Purpose**: Validate specification completeness and quality before proceeding to planning
**Created**: 2026-10-05
**Feature**: [spec.md](../spec.md)

## Content Quality

- [x] No implementation details (languages, frameworks, APIs)
- [x] Focused on user value and business needs
- [x] Written for non-technical stakeholders
- [x] All mandatory sections completed

## Requirement Completeness

- [x] No [NEEDS CLARIFICATION] markers remain
- [x] Requirements are testable and unambiguous
- [x] Success criteria are measurable
- [x] Success criteria are technology-agnostic (no implementation details)
- [x] All acceptance scenarios are defined
- [x] Edge cases are identified
- [x] Scope is clearly bounded
- [x] Dependencies and assumptions identified

## Feature Readiness

- [x] All functional requirements have clear acceptance criteria
- [x] User scenarios cover primary flows
- [x] Feature meets measurable outcomes defined in Success Criteria
- [x] No implementation details leak into specification

## Notes

- Export question resolved by the requester (2026-10-05): include subtotals in the Per Seller sheet (US2).
- Observation from the screenshot, deliberately OUT of scope: a "Paid" row shows collected (Rp 1.099.000) above order value (Rp 845.000) with outstanding clamped at 0, so the rows are not "value − collected"; the spec therefore defines the subtotal as a sum of displayed row values. Worth a separate look at why collected exceeds the order value (likely older over-payments from before payments were capped).
