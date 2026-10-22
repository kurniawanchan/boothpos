# Quickstart / verification: 065

Backend tests in the container: `docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter=<Test>` (the `-e` flags go BEFORE `app`). Never pass the flags through an unquoted shell variable (zsh does not word-split it — that migrated the dev DB once). For any rehearsal on a DB copy, print `select database()` first.

1. **Source separation** — as one cashier: open a shift via the API with `X-Device-Id` (app) and another without (web) → both 201; `GET /sessions/current` per source returns the right one; a cash payment and a refund land in the request's source shift.
2. **Web shift list** — as owner: Cashier Session page shows Open/Closed/Cash in-out tabs; the tablet's open shift is listed within 30 s with cashier + terminal name; as a cashier the tabs are absent and `GET /sessions` returns only own rows.
3. **Dashboard panel** — two users with open shifts → two rows, each linking to `/session?id=`.
4. **Cash in/out** — record out "Belanja bahan Rp 50.000" on the tablet → shift detail and `GET /cash-movements` show it; totals match the tablet card.
5. **Close** — typed `counted_total` closes the shift; pending QRIS → 409; variance above tolerance needs approval; no `edc_batch_total` anywhere; closed shift visible in the web with counted/expected/difference. Retry of the same request closes once.
6. **Server address** — web: choose Local (set address) → fresh tablet (cleared data) sets up with no address field and stores it; choose Online → clear data → stores the online address; no address saved → clear message; both defaults unreachable → "server unreachable" + retry.
7. **QRIS sheet** (tablet) — QR from channel id 3 shown; title beside the icon; no warning; text "Cek bukti transaksi di aplikasi QRIS, lalu catat kode verifikasi di menu Order"; unreachable image → "Muat ulang".
8. **Open-shift screen** — all labels readable in landscape; typed total works; no skip link.
9. **Suites**: new `ShiftListTest`, `ShiftSourceSeparationTest`, `CloseShiftTypedTotalTest`, `ServerAddressPublicTest`, `DashboardShiftsTest`, `CashMovementsListTest`; updated `CashierSessionTest`, close tests (EDC), 054 address tests; Android `./gradlew testDebugUnitTest`; `npm test`; `npm run build`.
10. **Android build config** — set `pos.defaultLocalUrl` / `pos.defaultOnlineUrl` in `android-pos/local.properties` (documented, uncommitted); without them the emulator default is used.
