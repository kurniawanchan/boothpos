# Quickstart / Verification: Sales Payment Proof Visibility

**Classification**: INTERNAL

Prereq: dev stack up, `boothpos_test` DB for tests. Backend tests inside the container ONLY with
`docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=SalesPaymentProofListTest`.

## Automated
1. `SalesPaymentProofListTest` (backend): count for owner vs. unrelated cashier vs. recording cashier; superseded excluded; cash excluded; voided counted; query count constant (warm-up call first).
2. `npx vitest run --exclude '.claude/**'` — new/updated Sales tests (link, thumbnail, modal, Verified pill, column order, reference filter + URL).

## Real browser (constitution II) — console must stay clean
1. Sales list as owner: a sale with a proof shows **Payment Proof** under its number; no proof/cash-only shows none.
2. Click it: dialog lists each viewable proof with method + amount; thumbnails load; click opens the gallery.
3. Open a sale detail: non-cash payments with proof show a thumbnail; click opens full size; no-proof payment keeps "No proof yet"; broken file shows the placeholder.
4. Log in as a cashier who did NOT record the payment: no Payment Proof link, no thumbnail.
5. Payment column: all-verified non-cash sale shows **Verified**; a pending one still **Not verified**; cash-only shows neither.
6. Columns (owner): …, Payment, **Total**, **Margin**, actions. Cashier: Total last data column. Sort both.
7. Link reads **Sale Reference** in en and id.
8. Filter **Sale Reference**: Has / None / All; combine with a seller filter; reload page → restored from `?ref=`; `?ref=bogus` ignored; page resets to 1; summary cards and export cover the filtered rows.
9. Compare against screenshots #1/#2 for the visual form.

## Addendum (US7–US9)

1. **Seller statistics:** filter by one seller that shares sales with another; cards and summary line must equal that seller's items only; select two sellers → sum; clear → whole sales.
2. **Toggle:** off by default, no thumbnail requests (network tab); on → references and proofs appear on the page rows; page/sort → only on-screen rows load; off → collapse; reload keeps the position; a cashier without proof permission never sees a proof.
3. **Export:** export a filtered selection containing a reference sale and a proof sale; open in Excel: all list columns, note text, images embedded in the sale's row, seller-scoped figures when filtered; as a cashier who cannot view a proof, that proof is absent; try an oversize selection → clear limit message.
