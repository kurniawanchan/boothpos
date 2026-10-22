# Evidence — 065 Android shift, payment and setup updates

## T037 — why the QRIS sheet said "QR belum diatur" (checked 2026-10-22)
- Dev channel id 3 (`QRIS` / `Warkop WJM 3 QRIS`) has `qr_image_path` set (uploaded 2026-10-17); the image is served: `GET /storage/payment-channels/<file>.jpg` → 200 `image/jpeg`, 233 731 B, from both `localhost:8010` and `10.10.100.164:8010` (APP_URL). The public storage symlink exists on host and in the container.
- So neither "no upload" nor an unreachable `/storage` URL explains the screenshot. The remaining cause in the code: the tablet loaded the channel once (`loadQrisLabel`, session start) and never again, so a QR uploaded after that stayed null; a failed refresh also reset the channel to null.
- Fix: the QRIS sheet reloads the channel every time it opens; a failed reload keeps the last loaded channel; "not set" (no URL) is now different from "could not load" (URL set, fetch failed, with "Muat ulang"); with several QRIS channels the first one that has an image wins.

## Android unit tests (clean run)
`./gradlew cleanTestDebugUnitTest testDebugUnitTest`: **app 232, core-domain 4, core-network 72 — 308 tests, 0 failures.** `core-network` tests run again because the pre-existing broken `S4DtosTest` (`ReprintRequestDto()` without the required `copyNo` since feature 057) was fixed.

## Build note
Default server addresses are provided with `resValue` (string resources) instead of `BuildConfig`: `BuildConfig` forces `compileDebugJavaWithJavac`, which fails on this machine's JDK 24 (AGP JDK-image transform / jlink). String resources need no javac.

## T047 — real browser (web), headless Chrome over CDP against the dev stack (SPA rebuilt)
- **Dashboard** `/dashboard` as `owner`: panel "Open shifts — 3 open" lists one row per open shift across users: *Dewi · Kasir Depan (app) · Rp 37.400 · 1 receipt*, *Rina · Tablet kasir (app)*, *Budi · Web*. Dewi's row is the shift opened on the tablet (the one that was invisible in the web before).
- **Cashier Session** `/session` as `owner`: section "All shifts" with tabs *Open · Closed · Cash in / out*. Open: 3 rows (cashier, source/terminal, outlet, opened, opening cash, sales + receipts, expected cash); the detail view opens and shows sales Rp 37.400 and expected Rp 100.000; Closed: 9 shifts with columns *Closed · Opening cash · Sales · Expected cash · Counted · Difference*; Cash in/out: totals line (dev data has no cash movements yet).
- **As `kasir01`** (cashier): `/session` has no "All shifts" section (hidden, not disabled).
- Console: 0 JS errors; only the browser's own network log of the expected `404 /api/v1/sessions/current` (the owner has no web shift — the client treats 404 as "no open session").
- The dev DB still lacks the 065 index migration (not applied on purpose — see the "database needs an update" banner on `/session`); nothing in the new endpoints depends on it.

## T049 — upgrade rehearsal on a COPY of the dev DB (scratch schema, target printed first: `TARGET=warkop_wjm_rehearse`)
`outlets:reconcile --before` → `migrate` (`2026_12_27_000001_cashier_sessions_source_index` DONE) → `--after` → `--compare`: **REKONSILIASI OK — tidak ada selisih** (orders 70, order_items 99, …). Index present after `up`, gone after `migrate:rollback --step=1` (`idx_left = 0`). Scratch schema dropped; dev `migrations` has 0 rows for the new migration (dev untouched). An old-APK style close (`edc_batch_total` sent) is proven by `ShiftCloseBlindCountTest::test_an_old_clients_edc_batch_total_is_ignored_and_never_stored`.

## T048 — on-device Android: NOT DONE
No tablet or emulator available in this session. Not verified on a device: open-shift layout at tablet size, typed amounts, close screen, QRIS sheet title/text/QR, a fresh tablet setup against the real discovery endpoint. The logic is covered by unit tests (`CountInputTest`, `DenominationLayoutTest`, `OpenShiftTypedAmountTest`, `CloseShiftTypedTotalTest`, `QrisSheetTextTest`, `ServerDiscoveryTest`) but layout/appearance still needs a device run.
