**Classification**: INTERNAL

# Evidence — Stage 2 (foundation: assignments, scope, selector, shifts, floor) — NEARLY DONE

**Date**: 2026-10-12. **Branch**: `045-outlets-per-outlet-menu`. Nothing committed or pushed.
**Status**: backend foundation done and verified; the Stage 2 gate is **not** closed (see "Still open").

## What is built (backend)

- Migrations `2026_12_02_000001` (pivot `outlet_user`, `roles.all_outlets`, nullable `outlet_id` on 7 tables, backfill, legacy flag on orders/pre-orders) and `2026_12_02_000002` (NOT NULL, FK `restrict`, table/station names unique per outlet). Re-runnable; `down()` provided; the second refuses to run while rows lack an outlet.
- `OutletContext` (scoped per request, reset when the request ends), `ResolveOutletContext` (422 `outlet_invalid`, 403 `outlet_forbidden`; runs **before** route-model binding), `GET /me/scope`.
- `BelongsToOutlet` + `OutletScope` on `CashierSession`, `Order`, `Payment`, `KitchenTicket`, `DiningTable`, `Station`, `Preorder`. `outlet_id` is never fillable: orders inherit the shift's outlet, payments/tickets inherit their parent's, and a request body is never read. `OutletDefaults` creates one default outlet only when none exists (fresh install).
- `OutletFilter` for raw queries: `SalesFigures` (4 bases), `ReportController` (7 sites). Dashboard summary and attention caches are keyed by the effective outlet set. `SettlementService` stays unfiltered on purpose (organisation-wide stored snapshot).
- `PUT /users/{user}/outlets`: no self-assignment, a manager without `all_outlets` can only grant/remove outlets they see, Owner/Admin refused (409), activity log in the same transaction. New non-admin users are auto-assigned when there is exactly one active outlet.
- Shift open (`POST /sessions`): chosen outlet validated against the permitted set; single outlet by default; several → 422; none assigned → 409 `outlet_unassigned`; fresh install → default outlet.

## What is built (frontend)

`stores/outlet.js`, `utils/outletSelection.js` (selection kept per user id, cleared on logout), `api/me.js`, `X-Outlet-Id` sent only when a selection exists, the existing `OutletSelector` wired into the shell (hidden at one outlet; choosing reloads the page), outlet field on the shift form, outlet checkboxes on the Users form (hidden at one outlet and for all-outlets roles).

## Verification

| Check | Result |
|---|---|
| Backend full suite (final code) | **1725 passed, 1 skipped, exit 0** (8251 assertions) |
| Frontend full suite + build | **180 files, 1715 passed, 2 skipped, exit 0; build passes** |
| New backend tests | `OutletContextTest` 8, `UserOutletAssignmentTest` 15, `ShiftOutletTest` 6, `OutletIsolationMatrixTest` 6 |
| New frontend tests | `outletStore` 5, `AppShellOutlet` 3, `SessionViewOutlet` 3, `UsersViewOutlets` 3 |
| Dev DB | backup `storage/app/backups/2026-10-11_233837` taken first; both migrations applied; no NULL outlet rows, no duplicate table/station names, all 5 existing orders flagged `outlet_backfilled`; Owner/Admin carry `all_outlets` |
| `outlets:reconcile` (dev) | before/after/compare: **zero differences**, exit 0; all seven columns confirmed NOT NULL |
| Dev app smoke (API) | owner: scope = 3 outlets, `can_see_all` true; `kasir01`: scope = 3 outlets (migration assigned them all), `can_see_all` false; `/floor`, `/orders`, `/users` answer as expected (users 403 for the cashier) |

## Findings worth knowing

1. **Route-model binding ran before the outlet context**, so `GET /orders/{id}` and `/sessions/{id}/summary` returned other outlets' records by id. Fixed by `prependToPriorityList(before: SubstituteBindings)`; covered by `OutletIsolationMatrixTest`.
2. **Security review finding (privilege escalation on `PUT /users/{id}/outlets`)**: fixed and tested (self-assignment, granting/removing outlets outside the actor's own set).
3. **Number sequences broke under the scope**: pre-order/order/PO/product-code/invoice counts used `withoutGlobalScope(DataModeScope::class)`; once the outlet scope narrowed them, numbers collided. They now use `withoutGlobalScopes()`.
4. **Test-only context leak**: the outlet context stayed bound after a request inside one test, so later direct model creates saw the previous user. Reset in a `finally`.
5. Tests were adapted (not weakened) where the old rules changed: table names are unique per outlet across areas (D17), the shift must name its outlet when several exist, and a few reports tests create outlets before the shift so no default outlet appears.

## Deviations from the plan

- **Opening cash**: a new shift does not auto-create "one entry for the shift's outlet"; the existing optional per-outlet entries are unchanged.
- **Outlet refetch**: choosing an outlet reloads the page (simple and correct) instead of the planned `useOutletScoped` composable.
- **`OutletScopeRegistry`** (the enumerating registry) and `reportOutletSql()` placeholder were not built; the isolation matrix is an explicit list of 6 cases, not an enumeration of every table.
- **RoleController/StoreRoleRequest** do not yet let an administrator set `all_outlets`; the flag is read-only (seeded for Owner/Admin) and exposed on role payloads.
- Factories differ from production on purpose: test users get every outlet by default (`unassigned()` opts out) and a new outlet is visible to existing non-admins. Production assignment is explicit.

## Added after the first write-up

- **Floor per outlet (T032)**: `GET /floor` returns `outlets[]` groups when several outlets are in effect and the original shape for one; `FloorOutletTest` (5). Moving a tab to a table of another outlet → `409 outlet_mismatch` (merge already requires the same shift). Frontend `normalizeFloor` keeps the floor screen and the Sales Tables board working for both shapes (areas of several outlets are prefixed with the outlet name; figures still come from the one `floorBoard` function).
- **Tables and stations (T038/T043)**: optional `outlet_id` on create (403 `outlet_forbidden` outside the permitted set, immutable afterwards, name uniqueness checked on the chosen outlet), `outlet_id` on the resources, outlet select on both setup forms (hidden at one outlet).
- **Reconcile stage checks (T045)**: `outlets:reconcile` now fails when a table lacks an outlet or when an order/payment carries a different outlet from its shift/order; 2 tests prove it fails, dev passes.
- **OpenAPI** updated for `/me/scope`, `/users/{id}/outlets`, shift `outlet_id`, floor shape, move `outlet_mismatch`, table/station create.
- A shell regression fixed on the way: loading the outlet scope before settings delayed `settings.load()` and produced an unhandled rejection in the AppShell test; both now load together.

## Closed after the first write-up (see evidence-stage-3.md, fifth slice)

Users-list outlet column, outlet on the shift header and receipt, board per-outlet headings, dashboard scope line, `all_outlets` settable on roles, `OutletScopeRegistry` + enumerating test, reconcile Stage 3 checks, `rehearse.sh` re-run.

## Still open in Stage 2

- **T046 browser evidence** (browser profile locked by an unrelated long-running Chrome; not killed).
- **T034** automated backfill test: infeasible in the test DB (columns are NOT NULL); backfill evidenced on dev.
- Opening cash is not auto-created per shift (stored on the shift, per outlet by construction) — accepted deviation.
- Owner decision pending: on the dev DB `kasir01`, `kasir02` and `inventory` were assigned to all three outlets by the migration; narrowing them is a Users-screen action.
