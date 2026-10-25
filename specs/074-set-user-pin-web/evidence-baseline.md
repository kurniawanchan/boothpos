# Baseline evidence (T001–T002)

**Classification**: INTERNAL — recorded 2026-10-26 before any 074 change.

- **T001 backend**: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter='PinLoginTest|PinLoginTerminalTest|UserTest|UserOutletAssignmentTest'` → **58 passed (225 assertions)**.
- **T002 frontend** (`docker compose exec -T node npm test`, run twice before any 074 change):
  - run 1: `Test Files 2 failed | 201 passed (203)`, `Tests 2 failed | 1733 passed | 2 skipped` (the two names were not captured).
  - run 2: `Test Files 1 failed | 202 passed (203)`, `Tests 1 failed | 1734 passed | 2 skipped (1737)`; failing test: `qa-tests/component/UsersView.test.js > UsersView > galat memuat menampilkan keadaan galat dan tombol coba lagi memuat ulang` — `expected "spy" to be called 2 times, but got 3 times` (line 232).
  - **Conclusion: the frontend baseline is flaky**, not a stable number: the failure count differed between two runs of identical code. The named UsersView test passed on later runs (e.g. when run alone with the 074 tests). It sits in the same file 074 extends, so a failure of that one test is not evidence about 074.
  - `ECONNREFUSED 127.0.0.1:3000` lines in the output are noise from tests that hit a real URL; they are present in the baseline.
