# Quickstart: Android Tablet Installer (Standalone)

Manual device-verification checklist — see plan.md's Constitution Check / Complexity Tracking for why this is a manual checklist rather than an automated suite (this repo's `php artisan test`/`npm test` are unaffected by and don't cover the native Android shell itself).

1. **Airplane-mode install (US1)** — On a fresh Android tablet, put it in airplane mode before installing. Install the APK. Confirm the app opens, completes first-run setup (data store initialized, owner account created), and reaches BoothPOS's normal login screen — all without ever leaving airplane mode.
2. **Full offline sale (US1)** — Still in airplane mode: log in, open a cashier session, add products to a cart, complete a cash sale, and confirm it's recorded (visible in Sales/reports) — matching spec.md's Independent Test for US1 exactly.
3. **Data survives a restart (US1)** — Force-close the app (or restart the tablet), reopen it, and confirm the sale from step 2, the product catalog, and the session are all still there.
4. **Cold-start UX (research.md R6)** — Force-close and reopen the app; confirm a branded loading state is shown while the embedded database/server start, never a blank white screen, and that it reaches the login screen within a few seconds.
5. **Backup (US2)** — With real data on the tablet, trigger a backup. Confirm the OS's save-file picker appears and a file is produced.
6. **Restore onto a wiped device (US2)** — Uninstall and reinstall the app (or otherwise clear its data) to simulate device loss, then restore from the backup file created in step 5. Confirm every product, stock level, and the sale from step 2 are back exactly as they were.
7. **Restore confirmation guard (US2)** — With data already present on a device, attempt a restore and confirm the app requires explicit confirmation before overwriting.
8. **Invalid restore file (Edge Cases)** — Attempt to restore a file that isn't a valid backup (e.g., a renamed unrelated file) and confirm a clear rejection message, not a partial/corrupted apply.
9. **Branding (US3)** — Confirm the home-screen icon and app name read "BoothPOS," not a generic label.
10. **Touch/tablet UX (US3)** — On the POS, session, product list, and a report screen, confirm every control is usable by touch without pinch-zoom or horizontal scrolling.
11. **Role parity (SC-002)** — For each role BoothPOS supports (owner, admin, cashier, inventory), confirm every screen/action that role can reach on desktop is also reachable on the tablet.
