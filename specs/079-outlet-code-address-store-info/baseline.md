# Baseline: 079

**Classification**: INTERNAL

Targeted baseline only (the full suite takes ~50 min and shares the single test DB with every later run, so it is run once at the end, T055):
`OutletTest|OutletRenameCharacterizationTest|OutletExcelSheetsTest|DevicePairingTest|OutletMoveGuardTest|OutletScopeRegistryTest|Receipt|SettingsFeatures|PosSetup` → **161 passed (925 assertions)**, 253 s, before any 079 change.
Known pre-existing failure from earlier sessions (not rerun here): `ShiftReportParityTest`.
Test command (flags go before the service name): `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=...`
Vitest / Android baselines: captured when first run in Polish (T056/T057).

## Final numbers (T002/T055/T056/T057)
- Vitest: 1771 passed, 1 flaky failure under load that passes alone (`DashboardViewShell`); no baseline was captured before 079 started, so there is no before/after delta, only these end numbers.
- Android: 834 results (debug + release variants), 0 failures. No before-number captured.
- Full backend suite (T055): started in the background at the end of the implementation session; it takes hours on this machine (an earlier full run took ~4 h) and was still running (11 test classes passed, 0 failed so far) when the session's report was written. Not complete, so not claimed.
