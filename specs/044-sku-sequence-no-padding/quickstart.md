# Quickstart: SKU sequence and segment change

## 1. Backend tests (safe, MySQL test DB)

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='ProductTest|ProductSegmentChangeTest|MasterDataImportTest|MasterDataExportTest|DemoClone'
```

## 2. Frontend

```bash
npm test
```

## 3. Browser (console checked; DEMO mode)
1. Products → New: segment `S`, one variant → preview shows `…-S-1`; save → SKU `…-S-1`.
2. Add a second variant → `…-S-2`.
3. Edit the product: change segment `S` → `T` → save → every variant SKU shows `…-T-1`, `…-T-2`; the list shows the same.
4. Edit again with a segment already used by another product → field error, SKUs unchanged.
5. Open an existing sale of a variant from before the change → it still shows the old SKU.

## 6. Saved segment in the edit form (User Story 4)
1. Open the edit form of a product saved with segment `PI` (for example `MCYT`, `SPF-PI-S`).
2. The Segment field shows `PI` (or `S` for that product); the preview shows the saved code, not a name-derived one.
3. Close without saving. Nothing changes.

