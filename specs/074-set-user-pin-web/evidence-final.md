# Final evidence (T025, T027)

**Classification**: INTERNAL — recorded 2026-10-26, this work session.

## Backend — full suite (after all 074 changes, including `#[\SensitiveParameter]`)
Command: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test`
```
Tests:    1 failed, 1 skipped, 2104 passed (16691 assertions)
Duration: 205.04s
```
**The one failure (verbatim name):** `Tests\Feature\ShiftReportParityTest > the summary keeps every existing key and value` — the shift summary endpoint and `expected_cash`. Unrelated to 074; the identical failure occurred in the 073 run (see `specs/073-android-outlet-terminal-login/evidence-final.md`) and is recorded in the project notes (2026-10-24) as pre-existing. **Not re-proved on a clean tree.** Left unfixed (separate bugfix). The 1 skipped test was not investigated.

Feature tests: `tests/Feature/UserPinAdminTest.php` — 17 tests (16 earlier + the stack-trace test added during T027), passing. Together with `Override*`, `PinLogin*`, `DevicePairing`, `PosSetup`: 114 passed (6689 assertions) in a focused run.

## Frontend
- `qa-tests/component/SetPinModal.test.js` (9) and the new `UsersView — PIN (074)` block (5) pass. `UsersView.test.js` alone passed 6/6 repeated runs.
- Full `npm test` (after 074, before the SensitiveParameter change, which does not touch the frontend): `Test Files 1 failed | 203 passed (204)`, `Tests 1 failed | 1748 passed | 2 skipped (1751)`. The failing test is `UsersView.test.js > galat memuat menampilkan keadaan galat dan tombol coba lagi memuat ulang` (`expected "spy" to be called 2 times, but got 3 times`) — the SAME test that failed in the pre-074 baseline (`evidence-baseline.md`) and passes when the file runs alone. Load-dependent flake, pre-existing; not caused by 074, but not fixed.
- `npm run build` (in the `node` container) succeeded; `public/build` now contains the 074 UI.

## T027 — security re-check
- **PIN never in a response**: `PUT /users/{id}/pin` returns the user (`has_pin`, `pin_locked`) and the tests assert the PIN string and the `pin`/`pin_hash` keys are absent; only `POST /pin-suggestions` returns a PIN (once, not stored, no log/activity row). `GET /users` carries no `pin_hash`/`pin_failed_attempts`/`pin_locked_until` (tested).
- **Activity log**: one `pin_set`/`pin_replaced` row per change, without the PIN or hash (tested); none for refused attempts; the change rolls back if logging fails (tested).
- **Authorization**: both routes are 403 for a role with the Users menu but not Settings, and 401 unauthenticated (tested).
- **Logs — found and fixed**: `storage/logs/laravel.log` holds the fake test PIN `482916` in 91 lines at the end of this session (85 when first counted; it grows as the suite re-runs): 83 are pre-existing stack traces of the approver-PIN flow (`OverrideController → OverrideGate::issue(..., '482916', …)`), 8 are test-helper frames inside one trace of a bug from my own 073 work (`Undefined variable $devices`, logged 2026-10-25 17:11, since fixed), and **0** are `PinAuthService` frames showing a PIN argument. The 074-only test PINs `739204` and `518273` appear 0 times, in that log and in the test output. The search showed PHP prints method arguments in traces (`zend.exception_ignore_args` is `0` in this container). I wrote a test that throws from inside the mocked logger during `adminSet`; it **failed before** the fix (the PIN was in `getTraceAsString()`) and **passes after** adding `#[\SensitiveParameter]` to every PIN/password parameter in `PinAuthService` (`adminSet`, `login`, `change`, `verifyApproverPin`, and the private `verifyAndCount`/`assertStrong`/`isWeak`).
- **Not fixed (pre-existing, other feature's code)**: `OverrideGate::issue` and `OverrideController` still pass the approver PIN as a plain argument, so a failure there still prints it in a trace. One-line fix with the same attribute; left for the owner to decide. The existing `laravel.log` also contains old test PINs (fake values); it was not rewritten.
- **Accepted points (spec/plan)**: the admin sees the PIN at the moment it is set; `source` in the audit entry is client-reported and advisory; PINs are not unique across users; no password re-entry to set another user's PIN.

## Not verified
- **T026 (real browser)**: not run. The browser MCP tools failed to connect and the repo has no browser driver; running one would fetch a package and write a PIN into the dev database, which was put to the owner and not yet answered.
- The `SetPinModal` and Users screen are covered by jsdom component tests only.
