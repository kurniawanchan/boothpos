# Research: Billing Records for Company Onboarding

## R1 — Status transitions guarded in a service, not the controller

**Decision**: `InvoiceService::markPaid()`/`cancel()` enforce valid state transitions (`unpaid → paid`, `unpaid → cancelled`; `paid`/`cancelled` are terminal), throwing `ValidationException` (mapped to `409` by the controller) on an invalid one.

**Rationale**: Mirrors `PurchaseOrderService`'s existing transition-guard pattern exactly — this codebase already has a proven convention for "status field with guarded transitions," no need to invent a new one.

## R2 — Reuse the `companies` menu key; no new permission surface

**Decision**: `InvoicePolicy` gates every action on `$user->canAccessMenu('companies')`, same as `CompanyPolicy`.

**Rationale**: Per spec.md's Assumptions, this is additive record-keeping on the existing Company Onboarding admin area — a new menu key would fragment one cohesive capability into two independently-permissioned surfaces for no requested benefit.

## R3 — `HasDataMode`-scoped, unlike `Package`/`BusinessType`

**Decision**: `Invoice` uses `HasDataMode`, unlike `Package`/`BusinessType` (reference/administrative data, per feature 017's own research.md R6).

**Rationale**: An invoice is a point-in-time transactional record tied to a specific `Company` (itself `HasDataMode`-scoped) — it belongs in the same category as `Customer`/`Order`, not the same category as a reference list like `Package`.

## R4 — Amount is a fixed snapshot, never re-derived from Package

**Decision**: `invoices.amount` is entered once at creation and never recalculated.

**Rationale**: Matches this codebase's established "historical financial snapshot" convention (Constitution IV — order/preorder line items never re-derive from current master data) and spec.md's own Edge Case ("invoice keeps its recorded amount" even if the company's package later changes).
