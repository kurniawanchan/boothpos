# Quickstart: verifying PO row actions and schema errors

## Automated

```bash
APP_ENV=testing php artisan test --filter='PurchaseOrder|SchemaOutdated|SchemaStatus|Settings|Preorder'   # host, boothpos_test
npx vitest run qa-tests/component/PurchaseOrderRowActions.test.js qa-tests/component/PurchaseOrderDetailModal.test.js qa-tests/component/SchemaUpdateBanner.test.js qa-tests/component/AddBomItemModal.test.js qa-tests/component/VariantBomModal.test.js
npm test && APP_ENV=testing php artisan test        # full suites before the PR
```

Cases that must fail on the old code:
1. **Row actions**: every status row shows Detail/Edit/Delete; Delete is disabled with the "cancel instead" reason for ordered/received/paid/cancelled; Detail opens the same detail as the number.
2. **Edit**: a Paid order opens Edit with lines read-only + hint; only vendor/seller/notes are sent; a draft sends lines; seller change blocked (409) when a BOM uses a line; edit from the list shows the existing lines.
3. **Delete**: draft → 204 + audit; non-draft → 409 with the new message and nothing deleted; payment/BOM-guard rows (inserted directly) → 409.
4. **Audit**: vendor change and line rewrite write `purchase_order_updated` with old/new; notes-only writes none.
5. **Schema renderer**: a thrown `QueryException` with SQLSTATE 42S22/42S02 on an API route → 503 `schema_outdated`, friendly message, no SQL in the body, exception still in the log; a different `QueryException` keeps today's behaviour.
6. **`SchemaStatus` / features**: with a migration row removed inside the test transaction → pending 1 → `schema_update_required: true` for owner/admin, `false` for cashier; with none pending → `false`.
7. **Frontend**: detail failure shows message + Retry (Retry reloads and succeeds); selector failure shows the error (not "no eligible lines"); banner shows only when the flag is true.

## Real-browser check (Constitution II)

1. Isolated server + `boothpos_test`, seed POs in each status (plus one with a payment, one used by a BOM).
2. List: Detail/Edit/Delete on every row; Delete disabled with reason on non-drafts; Detail = number click; Edit on a Paid order (lines locked, hint); delete a draft (confirm names it).
3. **Pending-migration scenario**: roll back the 034 migrations on the TEST database only, open a PO detail and the BOM selector → plain "update required" message + Retry (no SQL); owner sees the banner; cashier does not. Re-apply migrations → Retry works without a page reload.
4. **The real dev stack** (`boothpos-app-1`): with the user's go-ahead, apply the 4 pending migrations (or restart the container) and confirm both original screens load.
5. EN ↔ ID, console clean, screenshots (no customer data) into `specs/035-po-row-actions/evidence/`.
