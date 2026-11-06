# Quickstart: verify shift reopen

**Classification**: INTERNAL. Dev data only (seeded accounts `owner`, `admin`, `kasir01`).

## Automated

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=CashierSessionReopenTest
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='MenuActionsRouteCoverageTest|CloneRegistryDriftTest|CashierSession'
npx vitest run --exclude '.claude/**' qa-tests/unit/SessionsHistoryReopen.test.js
```

(Never run `php artisan test` inside the container without the two `-e` overrides — see CLAUDE.md.)

## Manual, in a real browser (Constitution II)

1. Log in as `kasir01`, open a shift, ring up one cash sale, close the shift with a deliberately wrong count.
2. Log in as `admin`: Session History → that shift → no "Reopen shift" button.
3. Log in as `owner`: same shift → "Reopen shift" → submit without a reason (blocked) → submit with a reason.
4. The shift shows as open, closing figures empty, with a "Reopened" marker and the reopen details. Check the browser console for errors.
5. As `kasir01`: Cashier Session shows the shift open again; add a cash payment (Sales page → a sale's payment) and close with the corrected count.
6. Back as `owner`: expected cash and difference reflect the correction; the reopen entry still shows the **first** closing figures.
7. Reopen the same shift again → a second history entry appears.
8. While `kasir01` has an open shift, try to reopen another closed shift of `kasir01` → refused with the clear message.
9. Reopen a shift whose event has ended; ring up a POS sale under it and confirm it works (resolves the ASSUMPTION in research D5).
10. Confirm the seller recap and settlements pages show the same numbers before and after a reopen.
