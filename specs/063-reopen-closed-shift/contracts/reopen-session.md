# Contract: shift reopen

**Classification**: INTERNAL. Money fields are strings with two decimals (project rule). Messages are localised (`lang/*/events_sessions.php`).

## `POST /api/v1/sessions/{session}/reopen`

Auth: signed-in user with the **Owner** role. `{session}` numeric, resolved through the DEMO/LIVE scope (other mode → 404).

Request body:

```json
{ "reason": "Closing count was typed wrong; cash payment missed" }
```

| Status | When | Body |
|--------|------|------|
| 200 | reopened | the shift detail (same shape as `GET /sessions/{id}`, now `status:"open"`, `closing_cash:null`, `cash_difference:null`, `reopens[]` including the new row) |
| 403 | caller is not the Owner role (admin, cashier, inventory, custom role — including the shift's own cashier) | `{ "message": "..." }` |
| 404 | no such shift in the active mode | standard |
| 409 | shift is not closed | `{ "code": "session_not_closed", "message": "..." }` |
| 409 | the cashier already has another open shift | `{ "code": "cashier_has_open_session", "message": "..." }` |
| 422 | `reason` missing, blank or outside 3–500 characters | `{ "errors": { "reason": ["..."] } }` |

Nothing changes on any non-200 response.

## `GET /api/v1/sessions/{id}` — additions

```json
{
  "can_reopen": true,
  "reopens": [
    {
      "id": 7,
      "reopened_by_id": 1,
      "reopened_by_name": "Owner",
      "reopened_at": "2026-11-06T09:30:00+07:00",
      "reason": "Closing count was typed wrong",
      "previous_closed_at": "2026-11-05T22:10:00+07:00",
      "previous_closing_cash": "1500000.00",
      "previous_expected_cash": "1480000.00",
      "previous_cash_difference": "20000.00"
    }
  ]
}
```

- `can_reopen` = caller is Owner AND the shift is closed (server-computed; the SPA does not derive it).
- `reopens` is returned to anyone who may already view the shift, oldest first; always an array (empty when never reopened).

## `GET /api/v1/sessions` — addition

Each row gains `reopen_count` (integer, 0 when never reopened). Filters, sorting and pagination unchanged.

## Unchanged

`POST /sessions/{id}/close` keeps its request and response; on a reopened shift it recomputes expected cash and difference as for any open shift.
