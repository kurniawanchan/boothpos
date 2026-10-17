# Quickstart: edit a variant sequence number

## 1. Backend tests (safe, MySQL test DB)

```bash
docker compose exec -T -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter='VariantSkuTest|ProductSegmentChangeTest|ProductTest|ProductCodeGeneratorTest'
```

## 2. Frontend

```bash
npm test
```

## 3. Browser (drawer, DEMO product; console checked)
1. Open a DEMO product's edit drawer. Each variant shows the code as fixed text and an editable number.
2. Change `1` to `7`, save → SKU `<code>-7`; the list shows it.
3. Type `A7` → refused on the number field; type `0` → refused; type `007` → saved as `7`.
4. Save a number another variant uses → refused, nothing saved.
5. Change the product's segment → the typed SKU stays; a generated SKU changes.

## 7. SKU order in the drawer (User Story 4)
1. Open the edit drawer of a product with numbers 1, 2, 9, 10, 11 (any stored order).
2. The cards appear as `…-1`, `…-2`, `…-9`, `…-10`, `…-11`.
3. Add a new variant (not saved): it appears where it was added. Save: reopen the drawer, and the cards are in SKU order.

