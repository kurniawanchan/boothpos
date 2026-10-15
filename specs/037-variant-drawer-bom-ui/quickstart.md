# Quickstart / Verification: 037-variant-drawer-bom-ui

Host only, TEST database (`APP_ENV=testing`, `boothpos_test`); never touch `boothpos`; never run tests in the app container without `-e` overrides.

## Automated

```bash
APP_ENV=testing php artisan test --filter='VariantBomCopy|VariantBomCopySelected'
APP_ENV=testing php artisan test        # full suite
npm test                                 # incl. localeKeys
```

## Real browser (isolated server :8091, boothpos_test; product with 9+ variants, BOMs on most, some with pictures)

1. **Card** — Edit product: drawer ≈ 1040 px wide at 1440 px; each variant in its own bounded card; four chips in four colours; header right side = `Open BOM · Apply markup · delete`; fields name → stock → cost → sell; picture 66 px (placeholder when none).
2. **Tooltip** — hover and Tab-focus `Open BOM`: tooltip text appears; Escape hides it.
3. **Duplicate** — Duplicate a saved variant with a BOM: new card below, name "… (copy)", same prices/stock, shows the BOM-copy and the "stock counts as new inventory" notes, stock-adjustment reason field appears; Save product → new SKU, same prices, BOM copied (open it), source unchanged; Cancel instead → nothing created. Duplicate a complete-BOM variant → copy not complete.
4. **Copy picker** — BOM dialog of the last variant: open "Copy from another variant" at the bottom of the viewport → opens upward / fits, scroll to the last entry, pictures visible, search works.
5. **Copy to chosen** — tick 3 variants (some already with BOMs) → replace confirmation names them → copy → only those 3 changed.
6. **Add BOM item** is on the same row as Save changes (left/right); narrow width wraps cleanly.
7. EN ↔ ID, console clean; cashier cannot reach BOM actions; screenshots (no customer data) to `specs/037-variant-drawer-bom-ui/evidence/`.
