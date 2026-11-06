# Data Model: Owner Reopens a Closed Shift

**Classification**: INTERNAL

## `cashier_sessions` (existing — no schema change)

| Field | Before reopen (closed) | After reopen (open) |
|-------|------------------------|---------------------|
| `status` | `closed` | `open` |
| `closed_at` | set | NULL |
| `closing_cash` | set | NULL |
| `expected_cash` | set | NULL |
| `cash_difference` | set | NULL |
| `notes`, `opening_cash`, `event_id`, `user_id`, `opened_at` | unchanged | unchanged |

State machine: `open → closed` (existing `close()`), `closed → open` (new, Owner only, via the reopen service). No other transition.

## `cashier_session_reopens` (new, append-only)

| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint PK | |
| `session_id` | FK → `cashier_sessions.id`, RESTRICT on delete | indexed |
| `reopened_by` | FK → `users.id`, RESTRICT on delete | the Owner |
| `reopened_at` | timestamp | server time |
| `reason` | text, required | trimmed, 3–500 characters (validated) |
| `previous_closed_at` | timestamp NULL | as it was before this reopen |
| `previous_closing_cash` | decimal(14,2) NULL | |
| `previous_expected_cash` | decimal(14,2) NULL | |
| `previous_cash_difference` | decimal(14,2) NULL | |

- No `updated_at`, no update or delete path anywhere (a test asserts there is no such route and the model has no writer other than the service).
- No `data_mode` column: reached only through a mode-scoped shift; mirrored by the demo clone through `via cashier_sessions.session_id`.
- Relations: `CashierSession hasMany CashierSessionReopen` (`reopens`, ordered by `id`); `CashierSessionReopen belongsTo CashierSession`, `belongsTo User (reopened_by)`.

## Validation rules (service, server-side)

1. Actor's role is Owner (else 403).
2. `reason` present after trim, 3–500 characters (else 422).
3. Shift status is `closed` (else 409 `session_not_closed`).
4. The cashier has no other `open` shift (else 409 `cashier_has_open_session`).
5. All writes (shift update, reopen row, activity log) happen in ONE transaction under row locks.
