# Release notes — 064 Terminal identified by name only

**Date**: 2026-10-22

## What changes for people
- A terminal is registered with a **name** only (e.g. "Kasir Depan"); no `T01`/`T02` code appears anywhere. Names must be unique per outlet (case/space-insensitive). Admins can rename a terminal through `PATCH /devices/{id}` (no screen yet).
- New receipts are numbered `000124` (per outlet). Old receipts (`T01-000124`) are unchanged.
- The terminal name shows on the receipt (screen and print), Android order list/detail, the web Sales page (column, filter, Excel export) and shift X/Z summaries.

## Upgrade (operators)
1. Release the server and the tablet APK **together** (the `device.code` response key is gone).
2. `php artisan outlets:reconcile --before`, `php artisan migrate`, `php artisan outlets:reconcile --after --compare`.
3. Terminals with duplicate names inside one outlet are suffixed " 2", " 3" by the migration; rename them afterwards if wanted.
4. Tablets paired before this release keep working with no re-pairing. They show "Terminal" until their first heartbeat after login, then their real name.
5. Rollback: `php artisan migrate:rollback --step=1` refills `devices.code` (`T01…`) and restores the global unique `receipt_no`. It refuses (clear message) if two outlets already share a receipt number.

## Decision for the owner to confirm
- Receipt numbers are now unique **per outlet**, not across the whole installation (two outlets can both issue `000001`). The printed receipt names the store/outlet. If a single installation-wide sequence is preferred, say so before the first multi-outlet go-live (research.md D4).

## Known limits
- No web screen to list/rename terminals or a dashboard device panel (neither existed before).
- `docs/openapi-pos-mvp.yaml` did not document the `/floor` tab fields before; they are still undocumented (the new `device_id`/`device_name` keys included).
