# Final evidence (T036)

**Classification**: INTERNAL — recorded 2026-10-26, this work session.

## Backend — full suite
Command: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test`

```
Tests:    1 failed, 1 skipped, 2087 passed (10478 assertions)
Duration: 299.26s
```

**The one failure (verbatim):**
```
FAILED  Tests\Feature\ShiftReportParityTest > the summary keeps every existing key and value
Failed asserting that true is false.
at tests/Feature/ShiftReportParityTest.php:61
    ->assertJsonMissingPath('expected_cash')
```
- It asserts that `GET /sessions/{id}/summary` has no `expected_cash` key (blind count, feature 048). It touches shift summaries, not terminals, PIN login, rosters or devices — nothing changed by 073.
- **Pre-existing, not re-proved on a clean tree:** the project's own session notes (2026-10-24) record this same test failing before 073 ("pre-existing from 065, deferred to /bugfix"). I did NOT re-run it on an unmodified checkout in this session, so "pre-existing" rests on that note plus the unrelated assertion, not on a fresh A/B run. Left unfixed; it belongs to a separate bugfix.
- 1 skipped test: not investigated (also reported as skipped in the 2026-10-24 run).

New/changed backend tests in this feature, run together earlier: `PosSetupTest` (12), `PinLoginTerminalTest` (9), `PinLoginTest`, `DevicePairingTest`, `DeviceHeartbeatTest` → 65 passed, 350 assertions.

## Android — unit tests
Command: `./gradlew test --offline` in `android-pos/` → exit 0. Result XMLs: debug 337 tests, release 337 tests, 0 failures, 0 errors. New in 073: `SetupRulesTest` (6), `PinLockMessageTest` (4), `TerminalRevokedRuleTest` (7), `LegacyVaultCleanupTest` (2), `PosStoreSetupTest` (4). `./gradlew :app:assembleDebug` → exit 0 (`app-debug.apk` built).

## Not verified
- T037 (on-device run) — not done; no device connected, and registering a terminal would write to the dev database.
- Compose screens: compile-checked only.
- Heartbeat / open-tab → `markTerminalRevoked()` inside `PosStore`: no automated test.
