# Quickstart / Verification: 036-bom-variant-stock-ux

Run on the host against the TEST database only (`APP_ENV=testing`, `boothpos_test`); never run migrations or tests against the dev DB (`boothpos`) and never run tests in the app container without the `-e` overrides.

## Automated

```bash
APP_ENV=testing php artisan test --filter='BomQuantitiesTest|BomWholeQuantityTest|StockMovementsTest|VariantBom|MasterDataImportVendorMaterialTest|BomCostTest'
APP_ENV=testing php artisan test       # full suite
npm test                               # includes localeKeys scan
```

## Real browser (isolated server on :8091, boothpos_test; seed a product with 8+ variants, sales, pre-order hand-over, adjustments)

1. **BOM dialog** — quantities show as `11` (no `.0000`); type `1.5` → refused; edit two rows → "unsaved" state, nothing saved on blur; Save → one success toast, totals refresh; close with a draft → discard confirmation; add/copy/remove with a draft → guard.
2. **Labels/stock** — cards and column say "per 1 product"; current stock shown; stock unchanged after any BOM action.
3. **Copy from another variant** — open the picker on a product with many variants: type a SKU fragment (list narrows), clear it, scroll to the last item (panel stays open), pick it with the keyboard, copy; empty-result text.
4. **Variant history** — Products → Detail → variant → History: rows newest first with user, reference numbers, before → after; filter by type/date; empty variant → empty state; newest "after" == current stock.
5. **Products list** — larger thumbnail, `SPF-KC-DMC` on one line, header "Type"/"Tipe"; check ≈1024 px width.
6. **Stock list** — header "Type"/"Tipe", BY column filled; click SKU → product detail with that variant highlighted; close → list/filters/scroll preserved; as a cashier the SKU is plain text and `GET /stock/movements` → 403.
7. EN ↔ ID, console clean (only deliberate 4xx probes). Screenshots (no customer data) to `specs/036-bom-variant-stock-ux/evidence/`.
