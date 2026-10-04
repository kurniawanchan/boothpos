# Quickstart: verifying optional proof + confirmation later

## Automated

```bash
php artisan test --filter='PaymentConfirmation|OrderTest|PreorderPaymentLedger|PaymentProof'   # host, .env.testing (boothpos_test)
npx vitest run qa-tests/component/PaymentPanel.test.js qa-tests/component/PaymentHistoryList.test.js \
  qa-tests/component/PaymentConfirmationModal.test.js qa-tests/component/TransactionItemsModal.test.js qa-tests/component/PreordersView.test.js
npm test
```

Cases that must fail on the old code:
1. Non-cash payment WITHOUT a proof token is accepted for POS checkout, `POST /orders/{o}/payments` and `POST /preorders/{p}/payments`; a PO non-cash payment without a proof is still refused; an invalid token is still refused.
2. Confirmation PATCH: owner/admin ok; recording cashier ok; another cashier 403; legacy NULL-recorder payment: cashier 403, owner ok; cash payment 422; voided sale / cancelled pre-order 409; handed-over pre-order ok; empty body 422; token reuse 422.
3. Replace: old proof `superseded_at` set, file still on disk, new proof current; activity log row holds old/new proof ids.
4. Edit-to-empty refused; clearing only the reference while notes remain is fine.
5. Totals, `paid_amount`, status, shift cash identical before/after; foreign payment 404.
6. Proof viewing: recorder can open the current proof added by an owner; another cashier 403; superseded proof 403 for the recorder.
7. Payment payloads carry `notes`, `has_proof`, `can_view_proof`, `can_edit_confirmation` (sale AND pre-order).

## Real-browser check (Constitution II) — ISOLATED server + test DB (it writes data)

1. `db:seed`, `license:dev-activate`, seed a QRIS channel and a product against `boothpos_test`; serve on another port; log in as `kasir01`.
2. POS: add an item, pay by QRIS with **no photo/reference/notes** → "Confirm & save" is enabled; sale saved. Repeat with a split (QRIS without proof + QRIS with proof).
3. Sales → open that transaction: the payment shows a "No proof" marker and "Add confirmation". Add a photo + reference + note → entry shows them; "View proof" opens the image. Edit the reference; replace the photo.
4. Log in as `kasir02` (another cashier): same transaction shows the reference/notes, no edit action, and no "View proof". Log in as `owner`: can edit.
5. Void the transaction → confirmation visible but no edit action. Repeat 3 on a pre-order payment.
6. Console clean; EN ↔ ID labels.
