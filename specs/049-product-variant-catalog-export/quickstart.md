# Quickstart: verifying the feature

Use a throwaway database. Inside Docker only run tests with `docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test ...` (CLAUDE.md: the plain command wiped the dev DB twice). On this machine run the full backend suite through `php -d memory_limit=1G vendor/bin/phpunit` (the default 128 MB limit crashes unrelated export tests).

## Seed a minimal scenario

- Sellers A ("Seller A") and B ("セラーB" with Japanese characters), categories Keychain and Sticker.
- A: product "MCYT" with variants `Slippery 5cm` (has a picture, stock 4, price 20000) and `Red` (no picture, stock 0 — still listed), plus an **inactive** variant. B: one product with a 90-character name. Store settings with name, logo, address, phone, email.
- One inactive product and one seller whose only product is inactive (must not appear).

## Automated

```bash
php artisan test --filter='ProductCatalogTest|Code128Test|VariantLookup'
npx vitest run qa-tests/component/CatalogExportModal.test.js qa-tests/component/CatalogPdfPage.test.js qa-tests/component/PosScan.test.js qa-tests/unit/catalogPdf.test.js qa-tests/unit/localeKeys.test.js
php -d memory_limit=1G vendor/bin/phpunit
npm test && npm run build
```

## Manual (real browser, console open)

1. `/products` as owner: a **Catalog** button is present; as a role without the `products` menu it is absent and `GET /api/v1/catalog` answers 403.
2. Open it: the dialog shows how many sellers and variants will be included and offers PDF and Excel.
3. Apply a seller filter first, export: only that seller appears; filter to a seller with no active variants: "nothing to export", no file.
4. **PDF**: cover with store logo/name/address/contact and a seller index; one section per seller with its own accent colour; seller name on every page of that seller; no card split across pages; picture or placeholder; price prominent; barcode with the readable code; Japanese names render (no empty boxes); generated date/time shown. Note the file size and generation time per 100 variants.
5. **Excel**: store block at the top, coloured seller heading rows, searchable text columns (Ctrl+F finds a code), price numeric, thumbnails and barcodes visible; no cost/stock column.
6. **Barcode scan (hardware)**: print the PDF at 100% on A4; scan five barcodes with a handheld scanner and one with a phone scanner app: decoded text equals the code every time (SC-003). Also scan from the screen.
7. **POS**: open a session, focus the search box, scan a printed barcode → exactly one unit is in the cart and the box is cleared and focused; scan again → quantity 2; scan an unknown code → "not found" toast and nothing added; scan the zero-stock variant → out-of-stock toast; type half a code + Enter → results listed, nothing added.
8. Without an open session: scan still adds (tap parity), banner stays, payment still blocked.
9. DEMO vs LIVE: switch mode; the catalog and store name follow the active mode only.
10. en ⇄ id: button, dialog, column headers and PDF labels follow the user's language.
11. Performance: catalog of ~100 variants PDF and Excel generated; then ~500 variants Excel without a PHP memory error; PDF time/size recorded against SC-001.

## Pass criteria

All automated tests green; steps 1–11 behave as written; no console errors; no cost, stock or margin visible anywhere; the hardware-scanner step explicitly recorded as done or not done.
