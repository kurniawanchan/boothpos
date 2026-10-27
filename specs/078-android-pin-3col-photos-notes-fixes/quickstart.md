# Quickstart — 078 verification

**Classification**: INTERNAL. Server tests (container, safe flags only):
```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter='LoginThrottleTest|TabletPollingThrottleTest|ReceiptOrderNoteTest|TicketOrderNoteTest|SalesExportOrderNoteTest|ApproverPhotoTest'
```
Android: `cd android-pos && ./gradlew :app:testDebugUnitTest`. Steps marked DEVICE need a tablet (adb is absent here).

1. **Reproduce the old lockout** (before the fix) and prove the new behaviour: `for i in $(seq 1 8); do curl -s -o /dev/null -w "%{http_code} " localhost:8010/api/v1/staff/roster?outlet_id=9; done; curl -s -XPOST localhost:8010/api/v1/auth/login -H 'Content-Type: application/json' -d '{"username":"owner","password":"wrong"}'` — before: login answers 429 "Too Many Attempts." after a few roster hits; after: login answers 401 until the 6th wrong attempt for `owner`, then 429 in Indonesian with seconds.
2. **Idle tablets** — DEVICE: leave 2 tablets on the PIN screen 5 min, then sign in on the web with the right password: succeeds first try.
3. **Per-account** — 6 wrong passwords for `owner` → 429; `admin` with the right password at that moment → 200; after the wait `owner` works.
4. **Three columns** — DEVICE landscape ≥ 960 dp: three columns; each name once; with 1, 2 and 20 staff nothing clipped; selected row expands; wrong PIN error in col 3; DEMO badge in col 1; 720–959 dp two columns; phone stacked.
5. **Photos** — DEVICE: LIVE outlet 9 user with a photo shows it on PIN list, expanded row, header after sign in, approver picker; DEMO users show initials; change the photo on the web → shows after the next refresh/sign-in; break the URL → initials.
6. **Order note** — write a note, send to kitchen → KOT shows `CATATAN ORDER:` above the items; pay → receipt shows `Catatan order:` below the items; web Sales detail shows it; export xlsx has `order_note`; order without note → no label anywhere; 300-char note wraps on a 58 mm printer.
7. **Duplicate-name question** — two accounts with the same display name appear as two rows.

Pass = steps 1–7, the six PHP test classes and the new Kotlin tests green, no new failure in the full suites (baseline: `ShiftReportParityTest` only).
