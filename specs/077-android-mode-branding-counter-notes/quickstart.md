# Quickstart — 077 verification

**Classification**: INTERNAL. Server tests run **inside the container only with the safe flags** (CLAUDE.md, destructive otherwise):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test -e CACHE_STORE=array -e SESSION_DRIVER=array app php artisan test --filter='PosSetupBrandingTest|PinRosterPhotoTest|TabNoteTest'
```
Android unit tests: `cd android-pos && ./gradlew :app:testDebugUnitTest` (adb is not installed on this machine, so steps marked DEVICE are manual).

Preparation: web `localhost:8010` → Settings → Store identity: set a store name and upload a logo; give one user a photo. Keep one user without a photo.

1. **Branding pre-login (US2)** — `curl localhost:8010/api/v1/pos-setup/branding` shows `system_mode`, name, logo URL and nothing else. DEVICE: PIN screen shows the logo; remove the logo on the web → fallback initials after refresh.
2. **Mode (US1)** — DEVICE: set up a terminal under LIVE; on the web switch to DEMO. Reopen the tablet: setup lists the DEMO outlets (not "terminal revoked"); pick one; DEMO badge shows everywhere; menu/orders are the demo set. Switch back to LIVE: the first terminal is restored without setup.
3. **Mid-session switch (FR-004)** — DEVICE: logged in with an open cart, flip mode on the web, tap Bayar/Kirim: blocking "Mode berubah…" dialog, no order is written in the other mode (check Sales on the web); sign out → normal.
4. **Header (US2)** — DEVICE: Counter, Orders, Shift, Printer, Setup: logo + store name on each; multi-outlet install also shows the outlet name; long name ellipsizes, logo stays.
5. **PIN two columns (US3)** — DEVICE landscape: left = user info/picker, right = PIN; wrong PIN error appears on the right and the user stays visible; portrait/compact stacks.
6. **Avatar (US7)** — user with photo shows it on the PIN screen and header; user without shows initials; break the URL → initials.
7. **Divider (US5)** — DEVICE: drag both ways, stops at limits, leave and return keeps width, kill app and reopen keeps width, double-tap resets.
8. **Order note (US4)** — add items, write a note, pay. Web → Sales detail shows the same note. Reopen the app → note still there. Try 1001 chars → refused with message. Airplane mode while saving → retry keeps one saved note.
9. **Receipt check (unknown)** — print a receipt of an order with a note and record whether the note prints; if not, it stays out of scope (spec).
10. **Price range (US6)** — narrowest card, product "Rp 7.000" – "Rp 15.000": both numbers complete (wraps only at the dash); single-price product on one line.

Pass = all ten, plus the three PHP test classes and the new Kotlin tests green, and the full backend suite shows no new failure (baseline: `ShiftReportParityTest` known pre-existing).
