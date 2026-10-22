# Release notes — 065 Android shift, payment and setup updates

**Date**: 2026-10-22

## What changes for people
- **Web, Cashier Session page (owner/admin):** a new "Semua shift" section with *Open*, *Closed* and *Cash in/out* tabs shows every shift from tablets and web, with a detail view (sales, payment split, cash in/out, counted/expected/difference). The dashboard has an "Open shifts" panel, one row per user. Other roles keep seeing only their own shifts.
- **Shifts are separate between app and web:** one user can hold one open shift on a tablet and one on the web; payments, refunds and cash movements go to the shift of the place they are made in.
- **Tablet — Buka shift:** readable denomination rows, a "type the total" field, no "Lewati, ambil order saja".
- **Tablet — Tutup shift:** full sales information including the expected cash before counting, type the total or count by denomination, no EDC. **The blind count of feature 048 is ended** (owner decision).
- **Tablet — QRIS sheet:** title next to the QR icon, no customer-phone warning, new instruction text, the QR is re-read from the payment channel each time the sheet opens; "not set" and "could not load" are shown differently.
- **Tablet — Siapkan terminal:** no "Alamat server" field. The address chosen in the web "Server address" setting is read once and kept on the device until its data is cleared.

## Upgrade (operators)
1. Release the server and the tablet APK **together**.
2. `php artisan migrate` (one new index on `cashier_sessions`, no data change); rollback = `php artisan migrate:rollback --step=1`.
3. Build the APK with the default addresses in `android-pos/local.properties` (not committed):
   `pos.defaultLocalUrl=http://<server LAN address>:8010` and `pos.defaultOnlineUrl=https://<online address>` (leave the online one empty if there is none). Without them the local default is the emulator address `http://10.0.2.2:8010`.
4. In the web **Settings → Server address**, save the local and/or online address and choose which one tablets use. A fresh tablet cannot finish setup until the chosen option has an address.
5. Already-set-up tablets keep working: their stored address is not touched.

## Behaviour changes to know
- Switching tablets between local and online later means clearing each tablet's data (the app no longer follows the admin's later choice, unlike feature 054).
- `edc_batch_total` is ignored when an old APK still sends it; old stored values remain as history.
- Expected cash is now visible before counting; a difference above the tolerance still needs approval.

## Known limits
- No on-device Android run was possible in this session (see `evidence.md`).
- Old APK + new server works for close/open; new APK + old server is unsupported.
