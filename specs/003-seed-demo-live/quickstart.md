# Quickstart: Seed Data Dummy & Mode DEMO/LIVE

## Run the migration and seeders

```bash
php artisan migrate                                    # adds data_mode columns
php artisan db:seed                                     # base accounts/settings (unchanged)
php artisan db:seed --class=SakanaFridgeDemoSeeder       # NEW — full Demo Sakana Fridge dummy dataset
```

## Verify seed data landed (User Story 1)

```bash
php artisan tinker --execute="
  echo App\Models\Event::count() . ' events, ';
  echo App\Models\Artist::count() . ' artists, ';
  echo App\Models\Product::count() . ' products, ';
  echo App\Models\ProductVariant::count() . ' variants, ';
  echo App\Models\Vendor::count() . ' vendors, ';
  echo App\Models\Material::count() . ' materials, ';
  echo App\Models\Preorder::count() . ' preorders' . PHP_EOL;
"
```

Expect (freshly migrated + both seeders run once): 1 event, 3 artists, 9
products, 27 variants, 6 vendors, 8 materials, ≥2 preorders — all with
`data_mode = 'demo'`.

## Switch mode and verify isolation (User Story 2 & 3)

1. Log in as `owner` (seeded by base `DatabaseSeeder`, password
   `password123`).
2. `PUT /api/v1/settings` with
   `{"settings":[{"key":"system_mode","value":"demo","type":"string","group":"system"}]}`
   → `GET /api/v1/settings/features` now returns `"system_mode":"demo"`.
3. `GET /api/v1/artists` (or any seeded list) now returns the 3 seeded
   dummy artists.
4. Flip back: `PUT /api/v1/settings` with `value: "live"`. The same
   `GET /api/v1/artists` call now returns zero rows (a fresh install has no
   LIVE artists yet) — the 3 DEMO artists are hidden, not deleted.
5. Create a real artist while in LIVE mode, flip back to DEMO, and confirm
   that new LIVE artist does **not** appear — proving isolation is
   bidirectional (SC-003).

## Run the test suite

```bash
php artisan test --filter=DataModeScopingTest
php artisan test --filter=SakanaFridgeDemoSeederTest
php artisan test --filter=SettingsSystemModeTest
php artisan test                                    # full suite must still pass
npm test
```

## Manual UI check (Constitution Principle II — browser verification required)

1. `npm run dev` + `php artisan serve`.
2. Log in, confirm the mode badge (e.g., "MODE: LIVE") is visible in the
   header regardless of role.
3. As `owner`/`admin`, open Settings, find the new mode toggle, switch to
   DEMO, confirm the badge updates immediately and product/customer/sales
   lists elsewhere in the app now show seed data.
4. Log in as `kasir01` (cashier) and confirm the badge is visible but no
   control to change it exists anywhere in their UI.
