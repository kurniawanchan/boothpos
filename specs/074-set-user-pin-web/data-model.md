# Data Model: Set User PIN from the Web Users Screen

**Classification**: INTERNAL

**No schema change, no migration.**

## User (`users`) — existing columns used
- `pin_hash` (nullable): `null` = no PIN. Written only by `PinAuthService`. Never serialized.
- `pin_failed_attempts`, `pin_locked_until`: cleared when an admin sets a PIN.
- `pin_changed_at`: set to now.

## Derived, API-only fields (`UserResource`)
- `has_pin` = `pin_hash !== null`.
- `pin_locked` = `pin_locked_until` is in the future.

## Rules
- PIN = exactly 6 digits; same-digit and straight ascending/descending runs refused (`PinAuthService::assertStrong`, unchanged).
- Not unique across users (spec assumption).
- Setting a PIN deletes the target's `pos-pin` Sanctum tokens only.

## Activity log (`activity_logs`)
- New actions: `pin_set` (target had no PIN), `pin_replaced` (target had one). `user_id` = acting admin, `entity` = `User`, `entity_id` = target. `new_values` = `{source: typed|generated}` (client-reported, advisory). The PIN and hash never appear. Written in the same transaction as the change.
- Existing `pin_reset` (clear-only, old API) and `pin_changed` (own change) are untouched.
