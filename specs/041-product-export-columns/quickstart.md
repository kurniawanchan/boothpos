# Quickstart: verify the product export locally (rewritten 2026-10-16)

## 1. Backend tests (safe, MySQL test DB)

Never run tests in the `app` container without the overrides (CLAUDE.md):

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='MasterDataExportTest|MasterDataImportTest|ProductTest'
```

Expected: green. The import suite and the existing products list tests must pass unchanged (scope extraction must not change list results).

## 2. Columns and stock by hand

Seeded data (`php artisan db:seed` + `SakanaFridgeDemoSeeder`), owner login:
1. `GET /api/v1/exports/products` → save the `.xlsx`.
2. Header row equals `contracts/products-export.md`.
3. One row: `total_stock_available` equals that variant's stock on the Stock screen; `item_label` = `code - category_name variant_name`.

## 3. Filters on screen (browser, console checked)

1. Products screen → type a search term matching one product → Export. The file contains only that product's variants.
2. Select one artist in the filter → Export. Only that artist's products appear.
3. Clear filters → Export. All variants appear.
4. Search for a term that matches nothing → Export. Header row only.
5. Browser console: no errors.

## 4. Import untouched

`GET /api/v1/imports/master-data/template` → `products` sheet still has the 17 original columns.

## 5. Before merge
- `docs/openapi-pos-mvp.yaml` updated (params + columns).
- `CLAUDE.md` SPECKIT block points to this plan (already).
