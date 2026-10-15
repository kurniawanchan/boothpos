# Quickstart: verifying the search-photo fix

## Automated

```bash
npx vitest run qa-tests/unit/posProductCards.test.js qa-tests/component/PosView.test.js
npm test
php artisan test --filter=test_variant_lookup   # host, uses .env.testing (boothpos_test); never inside the app container without the -e overrides
```

Cases that must fail on the old code:
1. `buildSearchCards()` carries `image_url` (variant photo, product-fallback value passed through, `null` when absent) and changes nothing else.
2. `PosView`: typing a term renders an `<img>` with the hit's `image_url` on the card; a hit without one renders the placeholder icon.
3. `PosView`: adding that hit to the cart shows the photo on the cart line.
4. `PosView`: when an older search resolves AFTER a newer one, the newer results stay on screen.
5. `/variants/lookup`: variant's own image wins; falls back to the product's; `null` when neither.

## Real-browser check (Constitution II) — before AND after the change

Use the dev app on :8000 (search and the client-side cart are read-only; no DB write):

1. Log in (`owner`), open **POS**. Note a product that shows a photo in the normal grid.
2. Type its name (and then part of its SKU) in the search box. **Before the fix**: placeholder icon on the result card. **After**: the same photo.
3. With a seller and a category selected, search again — photos show.
4. Click the result to add it to the cart — the cart line shows the photo; clear the search — the grid shows the same photo as before.
5. Search for a product that truly has no photo — placeholder icon. Console clean.
