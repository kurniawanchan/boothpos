# Quickstart: verifying "mark verified"

## Automated

```bash
php artisan test --filter='PaymentVerification|PaymentConfirmation|OrderTest|SalesTransactions'   # host, .env.testing (boothpos_test)
npx vitest run qa-tests/component/PaymentHistoryList.test.js qa-tests/component/TransactionItemsModal.test.js \
  qa-tests/component/PreordersView.test.js qa-tests/component/SalesView.test.js
npm test
```

Cases that must fail on the old code:
1. Single verify: owner ok, admin ok, another cashier ok, the RECORDER 403, owner who recorded it ok, legacy NULL-recorder payment ok for any user.
2. State matrix: pending → verified (sets `verified_by`/`verified_at`); already verified 409 (no new log row, `verified_at` unchanged); rejected 409; cash 422; voided sale / cancelled pre-order 409; handed-over pre-order ok; foreign payment 404; other-mode 404.
3. Two verifications in a row (double click): first 200, second 409, exactly one `payment_verified` log row, one verifier recorded.
4. Invariants: amount/method/channel/paid_at/session, order `paid_amount`/status/total, `payment_summary`, shift summary identical before/after.
5. Sales list `payment_state` flips `pending → verified` only when the LAST pending payment of a split sale is verified.
6. No undo: no route/method exists (route list + 404/405 on any attempt to PATCH/DELETE a verification).
7. Bulk: mixed selection → correct `verified`/`verified_orders`/`skipped` by reason, recorder's own payments skipped, cash ignored, voided skipped, unknown ids ignored, caps (0 or 201 ids → 422), one audit row per verified payment, other cashier's payments verified in the same call.
8. Payloads: `verified_by_name`, `verified_at`, `can_verify` present for sale and pre-order, per viewer.

## Real-browser check (Constitution II) — ISOLATED server + test DB (it writes data)

1. `db:seed`, `license:dev-activate`, seed a QRIS channel, a product, an open session for `kasir01` and `kasir02`; serve on another port.
2. As `kasir01`: make three QRIS sales + one cash sale. Sales list: the QRIS rows show "Not verified". Open one: the payment shows "Not verified" but NO "Mark verified" action (own payment).
3. As `kasir02`: open the same sale → "Mark verified" → confirm dialog ("cannot be undone") → the entry shows "Verified by … · date"; the list badge clears. Reload: still verified; no undo action anywhere.
4. As `owner`: filter "Needs verification", select all, "Mark verified (N)" → summary toast; remaining rows (if any) verified; activity log shows `payment_verified` rows.
5. As `kasir01` bulk-select their own sales → summary says they were skipped (own).
6. Pre-order: a transfer payment recorded by `kasir01` → verify as `owner`.
7. Console clean; EN ↔ ID.
