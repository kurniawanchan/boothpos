# Quickstart — verifying this feature manually

1. `php artisan migrate` (no new migrations expected, but confirm clean state)
   then `php artisan serve` and `npm run dev`.
2. **Filters**: Log in as `inventory` or `owner`, open Products, open the
   Artist filter — confirm it's now a searchable dropdown with checkboxes
   and an "All" option; type a partial name, select two artists, confirm
   the table narrows to both; repeat on the POS screen for the artist chip
   row replacement.
3. **Menu**: Confirm the sidebar group under Vendor/Bahan Baku reads
   "Pembelian"; visually compare its color state (default/hover/active) to
   "Sesi Kasir" (no submenu) — they should match.
4. **Dashboard**: Log in as `owner`, open the dashboard — confirm shortcut
   tiles for new sale / new pre-order / stock adjustment / add product all
   navigate correctly; change the day filter on the sales panel and confirm
   the numbers update; confirm category/artist/event charts render with
   real seeded data (`SakanaFridgeDemoSeeder`); click each section's link
   and confirm it lands on the right full screen. Then log in as `kasir01`
   and confirm shortcuts requiring `menu_keys` the cashier lacks are absent,
   not disabled.
5. **DEMO/LIVE isolation**: Toggle `system_mode` in Settings, confirm
   dashboard analytics change to reflect only the newly active mode's data
   (per existing DEMO/LIVE isolation behavior elsewhere in the app).
6. **Profile**: Log in as `kasir01` (a role with no `users` menu access),
   open the new Profile page, confirm it's reachable regardless of role;
   change password with a wrong current password (expect rejection);
   change it correctly (expect success, session stays valid — no forced
   re-login); upload a new photo, confirm it appears in the header avatar
   immediately.
7. Run `php artisan test` and `npm test` — all existing + new tests pass.
8. Update `docs/openapi-pos-mvp.yaml` for every new/changed route listed in
   `contracts/api-contract.md` before considering this feature done.
