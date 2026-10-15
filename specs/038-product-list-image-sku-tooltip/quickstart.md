# Quickstart / Verification: 038-product-list-image-sku-tooltip

Host only, TEST database (`APP_ENV=testing`, `boothpos_test`); never touch `boothpos`.

## Automated

```bash
npx vitest run qa-tests/component/BaseTooltip.test.js qa-tests/component/ProductsView.test.js
npm test
```

## Real browser (isolated server :8091, test DB; seed products with/without pictures, 1–9 variants — temporary images under `public/qa-img/` removed afterwards)

1. Products list at 1440 px: one combined first column, picture ≥ 84 px (target 96) above a one-line code; no empty-header column; placeholder same size; click picture → lightbox.
2. Measure content width vs container at **1100 px** and **1440 px** (baseline from 037: 977 px content in an 808 px container at 1100) — must be ≤ before.
3. Hover a SKU → tooltip with the variant name; Tab to a SKU → same; Escape hides; last row's tooltip flips above and is not clipped; a SKU behind "+N more" works; click still opens the variant detail.
4. EN ↔ ID, console clean. Screenshots (no customer data) to `specs/038-product-list-image-sku-tooltip/evidence/`.
