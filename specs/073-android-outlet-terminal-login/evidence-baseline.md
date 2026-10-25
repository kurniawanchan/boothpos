# Baseline evidence (T001–T003)

**Classification**: INTERNAL — recorded 2026-10-26 before any 073 change to auth paths.

- **T001 backend**: `docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter='PinLoginTest|DevicePairingTest|DeviceHeartbeatTest'` → **44 passed (205 assertions)**.
  - Note: all `-e` flags go BEFORE the service name `app`. The CLAUDE.md example (and the first draft of quickstart.md) places `-e CACHE_STORE=… -e SESSION_DRIVER=…` after `php artisan test`, where artisan reads `-e` as its own `--env` option. Corrected in quickstart.md/tasks.md.
- **T002 Android**: `./gradlew test --offline` in `android-pos/` → BUILD SUCCESSFUL (1m 19s). Result XMLs under `app/build` and `core-*/build` sum to 628 test cases, 0 failures, 0 errors, 0 skipped (count spans modules and may include both variants' reports; not a unique-test count). Java on this machine is 24.0.1.
- **T003**: `ApiClient.headers()` sets `Authorization` only inside `ctx.token()?.let { … }` (ApiClient.kt:62), so a null token sends no bearer. **No fix needed**; T016 only has to stop supplying the admin token.
