# Data Model: Android Shift, Payment and Setup Updates

No new tables. One index and behavioural rules on existing data.

## cashier_sessions (existing; rules change)
- **Source** (derived): `device_id IS NOT NULL` → app terminal; `NULL` → web. Not stored separately.
- **Uniqueness rule**: at most one OPEN shift per (user, source). Enforced in `CashierSessionController::store()` under a user-row lock (replaces "one open shift per user"). New index `idx_cashier_sessions_user_status_device (user_id, status, device_id)` for the lookups.
- **Close writes**: `closing_cash`/`counted_total` = typed or denomination total; `counted_by_denomination` null when typed; `expected_cash`, `cash_difference`/`variance`, `closed_at`, `z_report` (no `edc_batch_total` key), `edc_batch_total` column left NULL for new closes (old values kept).
- **Visibility**: owner/admin → all shifts in permitted outlets + mode; others → own shifts only.

## cash_movements (existing)
Unchanged shape (`session_id, direction in|out, reason_code, reason_note, amount, created_by`, outlet + mode stamped). New read path only.

## settings (existing): `server_address` (local, online, selected)
Unchanged. Exposed (selected URL only) through the new public `GET /server-address`.

## Android local storage (TokenVault)
- `SERVER_URL`: written once at the end of setup from the discovered address; read for every request; cleared only by app-data clear / reset terminal.
- Removed behaviour: periodic adoption of a changed admin choice (054).
- `DEVICE_NAME`, `DEVICE_ID`, `OUTLET_*`: unchanged (feature 064).

## Derived list row (read model, not stored)
`{id, source: 'app'|'web', terminal_name|null, cashier{id,name}, outlet{id,name}, status, opened_at, closed_at|null, opening_cash, sales_total, order_count, cash_net, noncash_total, cash_in, cash_out, void_count, expected_cash, counted_total|null, difference|null}`; closed rows read stored values, open rows compute live (batched).

## State transitions
open → closed (once, idempotent via the existing `idempotent` middleware + row lock); no reopen. A web shift and an app shift of the same user are independent.
