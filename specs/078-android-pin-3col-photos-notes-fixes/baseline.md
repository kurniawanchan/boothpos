# Baseline — 078 (2026-10-27)

**Classification**: INTERNAL

- Android `:app:testDebugUnitTest`: 335 tests, 0 failed (inherited from 077's final run; tree unchanged).
- Backend (safe flags, full suite, 077 final): 2123 passed, 1 skipped, 1 failed — `ShiftReportParityTest` (pre-existing since 065).

## T002 — lockout reproduced before the fix (dev server, localhost:8010)
- 8 × `GET /api/v1/staff/roster?outlet_id=9` → `200 200 200 200 200 200 200 200`.
- Then `POST /api/v1/auth/login` (user `owner`, deliberately wrong password) → **429** `{"message":"Too Many Attempts."}` (English, no wait time), twice in a row.
- Confirms research R1: the web login's `throttle:5,1` shares the per-IP counter with `throttle:30,1` routes. A wrong password would be 401 on a clean counter; the 429 came from unrelated read traffic.
