**Classification**: INTERNAL

# Contracts overview

These files describe the interfaces this feature exposes: HTTP API resources (per module group) and the UI contract (frame, page kit, interaction rules). They are the design-level contract; the authoritative machine-readable contract is `docs/openapi-pos-mvp.yaml`, which MUST be updated in the same commit as every route or response change (constitution, Documentation & Change Discipline).

## Conventions that apply to every endpoint (existing, unchanged)

- Base path `/api/v1`, bearer token (Sanctum). Everything except `/license/status`, `/license/activate`, `/auth/login` requires authentication and an activated installation (`423` otherwise).
- Status codes: `422` shape/validation, `409` business-rule conflict (invalid transition, closed business day, self-approval, insufficient stock, delete guard), `403` role/permission/outlet denial, `401` unauthenticated, `404` outside the caller's outlet scope **or** not found (no existence leak across outlets).
- Money is a string `"0.00"`. Quantities are numeric strings with 3 decimals. Dates `YYYY-MM-DD` (business dates) and ISO 8601 with offset (timestamps).
- Pagination envelope: `{"data": [...], "meta": {current_page, per_page, total, last_page}}`. List endpoints stay lean; heavier nested payloads are opt-in (`?with=...`).
- Writes carry no client-supplied totals, prices, tax, stock quantities, dates for business date, outlet id or permissions beyond what the endpoint explicitly defines as input; the server computes and returns them.
- Idempotency: create endpoints that can be retried by clients accept `client_ref` (UUID) and return the existing result on repeat (as `POST /orders` does with `local_ref`).

## New conventions introduced by this feature

- **Outlet scope**: request header `X-Outlet-Id: <id>` selects one permitted outlet; omitted = all outlets the user may access. Response resources include `outlet_id` where relevant. A user cannot widen their own scope through this header (the server intersects).
- **Reason-coded actions**: any action marked **R** requires `reason_code_id` (and optional `note`). Missing or wrong-category code → `422`.
- **Approvals**: any action marked **A** creates or resolves an approval through the single approval path. Approver = requester → `409 SELF_APPROVAL`. Missing authority/limit → `403`.
- **Permission**: each endpoint lists the permission key it needs (names as in the design's matrix). A role holding none of a module's keys does not see the module.
- **Audit**: every write is logged with actor, outlet, object, before/after values and reason (inside the same transaction).

## Files

| File | Covers | Phase |
|---|---|---|
| [api-foundation.md](api-foundation.md) | outlets, user scope, permissions, reason codes, approvals, tax, payment methods, business rules, settings sections, imports, search, attention, dashboard | 2a, 6, 9 |
| [api-catalog-stock.md](api-catalog-stock.md) | menu items, outlet availability, combos, ingredients, recipes, stock locations/balances/batches, counts, transfers, wastage | 3 |
| [api-purchasing.md](api-purchasing.md) | suppliers, price catalogue, purchase requests, POs, receipts, invoices, returns, scorecards | 4 |
| [api-sales-ops.md](api-sales-ops.md) | sales records, returns, complimentary/staff meal, shifts/cash, orders monitor, day close, devices, printers, sync, releases, limits, presets, receipts, app policy | 5, 8 |
| [api-people-reports.md](api-people-reports.md) | members/loyalty/feedback, report catalogue/schedules/exports, users/roles/audit | 7 |
| [ui-contracts.md](ui-contracts.md) | frame, page kit, interaction rules, tokens, accessibility | 1 |
