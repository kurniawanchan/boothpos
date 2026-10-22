# Data Model: Terminal Identified by Name Only

## devices (changed)

| Column | Before | After |
|--------|--------|-------|
| `name` | string(80), free text | string(80), REQUIRED, trimmed, unique among active (`revoked_at IS NULL`) rows of the same `outlet_id` + `data_mode`, case-insensitive (rule in `DeviceService`, under an outlet row lock) |
| `code` | string(12) NOT NULL, unique `(outlet_id, data_mode, code)` | string(12) **NULLABLE**, legacy only: never generated, accepted, returned or shown. Old unique index kept (NULLs do not collide). |
| `revoked_at` | unchanged | unchanged — a revoked terminal frees its name |

**Migration (data)**: within each `(outlet_id, data_mode)`, active rows sharing a name (case-insensitive) keep the oldest as-is; later ones get " 2", " 3"… appended; blank name → "Terminal" (+ suffix). Reversible: `down()` makes `code` NOT NULL again, filling NULLs with `T01…` per outlet+mode.

## outlet_receipt_counters (new)

| Column | Type |
|--------|------|
| `outlet_id` | FK → outlets, PRIMARY |
| `receipt_seq` | unsigned int, default 0 |

Locked (`lockForUpdate`) inside the settling transaction; next number = `receipt_seq + 1`, rendered `%06d`. Seeded lazily (insert-if-missing). Shared by DEMO and LIVE orders of the outlet (uniqueness is per outlet regardless of mode).

## orders (index change only)

- DROP UNIQUE `receipt_no`; ADD UNIQUE `(outlet_id, receipt_no)`. Existing values (`T01-000124`) stay as-is. `device_id` (existing FK) is the terminal link; no new column, no name snapshot.

## terminal_counters (unchanged, inert)

No longer read or written; rows kept as history (not dropped).

## Relationships

`Order.device_id → devices.id` (nullOnDelete) · `CashierSession.device_id → devices.id` (nullOnDelete) · shown by `devices.name`.

## State / validation rules

- Pair: name required (after trim), ≤80, unique among active in outlet+mode → else 422 `name`.
- Rename: same rules, ignoring the terminal itself; revoked terminal → 409 (cannot rename a revoked terminal).
- Revoke: unchanged; frees the name.
