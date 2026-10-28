# Quickstart: verifying the feature

Use a throwaway database; never run the suite inside the `app` container without the `-e` overrides documented in CLAUDE.md (it has wiped the dev DB twice).

## Seed a minimal scenario

- Two sellers (A, B) and two categories (Keychain, Sticker).
- Product P1 (seller A, Keychain) with variants: `M` stock 4 sell 20000 (has a picture), `L` stock 10 sell 20000 (no picture), `XL` stock 0 sell 20000 inactive.
- Product P2 (seller B, Sticker) with one variant stock 7 sell 5000.
- Give `M` two adjustment movements (+20, then −16) and P2's variant one movement.

## Automated

```bash
# backend (host / correct container flags)
php artisan test --filter=StockMovementsTest
php artisan test --filter=ProductTest

# frontend
npx vitest run qa-tests/component/StockView.test.js qa-tests/component/VariantHistoryModal.test.js qa-tests/component/ProductsView.test.js qa-tests/unit/localeKeys.test.js
```

## Manual (real browser, console open — Constitution II)

1. `/stock` as owner: rows show picture or placeholder, variant name, seller, category, available stock (4 for `M`, on both of its rows), SKU, existing columns, History.
2. Type `slip`/part of a variant name → only matching movements; type a SKU fragment → same; type `%` → no crash, literal match (likely empty).
3. Pick seller B → only P2 rows; add category Keychain → empty state message; clear → back.
4. Combine search + type filter + date range → AND behaviour; page count follows the filter.
5. Click History on an `M` row → dialog title `Transaction history — <variant name> · <SKU>`; only `M` movements; its type/date filters work.
6. `/products` → open a product's drawer → History from the variant row → same title format.
7. `/products` list: P1 counts active variants only → `M` (4) + `L` (10) = total stock **14**, stock value 14 × 20.000 = **Rp 280.000**; the inactive `XL` is excluded. P2 shows 7 and Rp 35.000. A product with no stock shows 0 and Rp 0.
8. Switch the app to DEMO mode (or seed a demo variant): totals do not change.
9. Log in as a role without `stock`/`products` menus → both pages still refused as today; no new data reachable.
10. Switch language en ⇄ id: new column headers, filter labels, empty message and dialog title are translated.

## Pass criteria

All automated tests green; steps 1–10 behave as written; no console errors; no cost price visible anywhere new.
