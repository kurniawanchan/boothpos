**Classification: INTERNAL**

# Quickstart: Verify the Stock Export Variant Name

## Automated

Inside the container, with the explicit test-DB overrides (running without them can wipe the dev database, see CLAUDE.md):

```bash
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app \
  php artisan test --filter=MasterDataExportTest

docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app \
  php artisan test --filter=MasterDataImportTest
```

Expected: both pass. The stock export test asserts the header `sku, variant_name, current_stock, reason`.

## Manual (about 2 minutes)

1. Sign in as `inventory` (seeded dev account) and open **Stock**.
2. Click the Excel export button.
3. Open the file. Check:
   - Column B is `variant_name`, next to `sku`.
   - Each variant shows the name you see in the product screen for that SKU.
   - The row count matches the number of variants.
4. Rename one variant in Products, export again, and confirm the new name appears.
5. Re-import the same file via the master-data import with **dry run**. Expect no errors and no stock changes.
6. Edit a `variant_name` cell in the file and dry-run import again. Expect no change to the stored name.
7. Sign in as `kasir01` and confirm the export is still refused (403) as before.

## Not covered by automation

Opening the file in Excel to confirm the column is readable. Not verified yet.
