# Data Model: Android Outlet → Terminal → PIN Login

**Classification**: INTERNAL

**No schema change, no migration.** All entities already exist.

## Terminal (`devices`, model `Device`)
- Fields used: `id`, `name`, `outlet_id` (server-stamped, never fillable), `data_mode`, `revoked_at`, `paired_at`, `paired_by`, `last_seen_at`.
- **New use**: `paired_by` is NULL for terminals registered from the Android flow (no user). Verified: the column is `nullable()` in the devices migration, so **no migration is needed**.
- Identity rule (unchanged, 064): name unique among **active** terminals of the same outlet + data mode, case-insensitive, trimmed; enforced in `DeviceService::assertNameFree` under an outlet row lock.
- State: active (`revoked_at` null) → revoked (terminal, no re-activation; a revoked name can be reused by a new record).

## Outlet (`outlets`)
- Read only: `id`, `name`, `is_active`. Only active outlets of the current data mode are offered.

## User (`users`, `roles`)
- Read: `id`, `name`, role name, `pin_hash` (presence only → `has_pin`), `pin_locked_until` (→ `locked`).
- Lockout: `pin_failed_attempts`, `pin_locked_until`; `PinAuthService::LOCK_MINUTES` 15 → 5 (research R4).
- Roster rule: active ∧ (assigned to outlet ∨ role `all_outlets`) ∧ ¬`isOwnerOrAdmin()`.

## Device selection (Android vault, not DB)
- `TokenVault`: `SERVER_URL`, `OUTLET_ID`, `OUTLET_NAME`, `DEVICE_ID`, `DEVICE_NAME`; `ADMIN_TOKEN` removed (cleared on upgrade); `SESSION_TOKEN/EXPIRES` unchanged.

## Activity log
- New/changed entries (same transaction as the mutation): `device_registered` (Android flow; actor null, outlet + terminal name), `pin_login` (user, outlet, terminal). `ActivityLogger::log` takes `?int $userId` and `activity_logs.user_id` is nullable (verified), so actor-less `device_registered` entries work.
