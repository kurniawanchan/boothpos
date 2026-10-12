**Classification**: INTERNAL

# Contract: Outlet scope, assignments and stamping (Stage 2)

## Request scope

- Header `X-Outlet-Id: <id>` (optional) — **narrows** within the user's permitted outlets. Absent ⇒ all permitted. An id that is not permitted ⇒ `403 {code:"outlet_forbidden"}`. The body/query `outlet_id` is **never** used to widen scope or to stamp records.
- Every outlet-scoped read (lists, detail, figures, exports, the floor, the dashboard, reports) is filtered by the effective set; a request for another outlet's record by id answers `404`.

## `GET /me/scope`

```json
{ "outlets": [{"id":1,"code":"WJ1","name":"Warkop WJM 1"}], "selected": null, "can_see_all": true, "multi": true }
```
`multi` = more than one visible outlet (drives "hidden at one outlet"). Always available to a logged-in user.

## User assignments (menu `settings`/users; admin action)

| Route | Purpose |
|---|---|
| `GET /users/{id}` / list | include `outlet_ids[]`, `all_outlets` (role flag) |
| `PUT /users/{id}/outlets` body `{outlet_ids:[…]}` | replace assignments (≥ 1 for non-admins unless intentionally unassigned); activity-logged |

A non-admin without assignment: cannot open a shift (`409 outlet_unassigned`) and sees no outlet-scoped data.

## Stamping rules

| Action | Outlet |
|---|---|
| `POST /sessions` (open shift) | body `outlet_id` validated ∈ permitted; omitted ⇒ the single permitted outlet; ambiguous ⇒ `422 {errors.outlet_id}`; response carries `outlet`. Opening cash: one entry for the shift's outlet |
| Order/tab/payment/ticket create | copied from the **shift** (never from the body) |
| `POST /dining-tables`, stations | body `outlet_id` ∈ permitted (default selected/only) |
| Pre-order / purchase order create | the single effective outlet (422 if several are effective and none chosen) |
| Move/merge/split tab | both tabs in the same outlet, else `409` |

## Floor snapshot (`GET /floor`)

Single effective outlet ⇒ today's shape (`areas`, `takeaway_tabs`, `version`). Several ⇒ `outlets:[{id,code,name,areas,takeaway_tabs}]` + `version`; constant query count either way.

## Errors

`403 outlet_forbidden`, `409 outlet_unassigned`, `409 outlet_mismatch` (move/merge across outlets), `404` for a record outside scope.
