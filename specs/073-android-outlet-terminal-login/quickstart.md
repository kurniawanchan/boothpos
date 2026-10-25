# Quickstart: verifying 073

**Classification**: INTERNAL

## Backend (real MySQL; container flags are mandatory)
```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test \
  -e CACHE_STORE=array -e SESSION_DRIVER=array app \
  php artisan test --filter='PosSetupTest|PinLoginTest|DevicePairingTest'
```
Expect: outlets list; terminals per outlet; register new → 201, same name (other case/spaces) → 200 same id; blank → 422; other outlet may reuse a name; revoked name is reusable; roster excludes owner/admin; owner/admin PIN → generic 401; 5 wrong PINs → 429 and 5-minute lock; `device_id` of another outlet or revoked → 422; both log entries written.

## Android (unit + on device)
1. `./gradlew test` in `android-pos/`.
2. Fresh install: first screen = outlet list, no username/password.
3. Pick outlet → Terminal field: type a new name → continue → name appears for that outlet next time; pick an existing one; two tablets on the same terminal.
4. Roster shows only that outlet's cashier/inventory; wrong PIN ×5 → lock message with remaining time.
5. Relaunch → straight to roster; "Ganti outlet / terminal" → outlet screen; URL kept.
6. Revoke the terminal on the web while signed in → finish the open tab, no new tab, then outlet screen with message.
7. Upgrade over an old install: previously paired tablet keeps working; stored admin token is gone.

On-device steps 2–7 are **not yet run** (nothing is implemented).
