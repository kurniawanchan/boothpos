# Quickstart: verifying 079 end to end

**Classification**: INTERNAL

Use the dev stack of this checkout only (compose project `warkop_wjm`; app `:8010`, Vite `:5180`, DB `warkop_wjm`). Never touch the `boothpos` containers or databases.

## Backend tests (inside the container, with the safe overrides)

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=warkop_wjm_test app \
  php artisan test -e CACHE_STORE=array -e SESSION_DRIVER=array \
  --filter='OutletCode|OutletAddress|ReceiptStoreIdentity|FeaturesStoreIdentity|DeviceManagement'
```

Then the full suite once. Known pre-existing failure from earlier notes: `ShiftReportParityTest` (not part of this feature).

## Frontend and Android

```bash
npm test                                   # Vitest incl. Terminals page and outlet form
npm run build
(cd android-pos && ./gradlew test)         # receipt/branding mapping tests
```

## Browser walk-through (Constitution II — console must stay clean)

1. **Outlets**: create outlet `KD001` with address, phone, email, notes → reopen, values unchanged. Try `AB`, `ABCDEF`, `AB-1`, a duplicate (`kd001`): each refused with the rule message. Edit an existing 3-letter outlet to `WJM2X`; confirm list and filters show it, an existing product's code is unchanged, a newly created product uses `WJM2X`.
2. **Receipts**: two outlets with different details; complete a sale in each; open both receipts — each shows only its own name/address/phone/email/notes; with outlet B selected in the picker, open outlet A's receipt — still A's. Edit A's address, reopen the receipt — new address. Outlet with all details empty — receipt shows the shared block as before.
3. **Screens**: switch the picker A → B → All outlets; the header/sidebar store name follows on the first view.
4. **Terminals** (`/terminals`): filter by outlet, status, name; add, rename, move (try with an open shift → refused), deactivate (the tablet is refused at its next request), reactivate, delete one with no history (allowed) and one with sales (refused, deactivation offered). Log in as `kasir01`: no Terminals entry, direct URL refused.
4b. **Terminal bulk actions** (`/terminals`): tick two active terminals and one deactivated one → bar shows `3 dipilih`; **Aktifkan** (no confirmation) changes only the deactivated one; **Nonaktifkan** asks to confirm with the count; **Hapus** on a mix of unused and used terminals deletes the unused, names the used ones with the reason and leaves them selected; change a filter or page → selection clears.
5. **Activity log**: entries exist for each terminal action and for the code change.

## Needs a real device (cannot be proven here)

Tablet PIN screen shows the outlet name; 58 mm receipt wraps address and notes; a moved terminal returns to setup. Record the result in `evidence.md` when run; until then these stay open.
