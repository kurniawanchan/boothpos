# Quickstart: verifying the seller-specific BOM

## Automated

```bash
APP_ENV=testing php artisan test --filter='VariantBom|PurchaseOrder|BomCost|ProductVariant|MasterDataImport|Material|ReportTest'   # host, boothpos_test
npx vitest run qa-tests/component/VariantBomModal.test.js qa-tests/component/AddBomItemModal.test.js qa-tests/component/PurchaseOrderForm.test.js
npm test && APP_ENV=testing php artisan test        # full suites before the PR
```

Cases that must fail on the old code:
1. **Seller rule**: Seller A's variant selector lists only A's ordered/received/paid PO lines; adding B's line → 422; legacy (no-seller) and draft/cancelled POs never listed.
2. **Snapshot**: add a line at Rp 500; edit nothing → row shows PO number, vendor, Rp 500; create a newer PO at Rp 600 → row still Rp 500 + `newer_price` cue; `replace-source` → Rp 600, qty kept, audit row; cancel the source PO → row keeps cost, `source_cancelled: true`.
3. **Validation**: qty 0/negative/empty → 422; same PO line twice → 409; a service line has no material and is accepted; several lines added in one call.
4. **Cost**: materials Rp 500 + 300, service Rp 1,000 → material 800 / service 1,000 / total 1,800; qty change recomputes.
5. **Complete + sync**: complete → `cost_price` = 1,800 and `PUT cost_price=999` → 409 `cost_price_locked_by_bom` (unchanged value accepted); add/remove/qty/replace re-syncs; remove last row → auto-reopen, cost price keeps its value; `has_legacy` or empty BOM → complete refused with a reason; Excel products/bom sheet row errors for a complete variant.
6. **History**: sell a unit, then complete the BOM at another cost → the recorded order item cost and the profit/settlement reports are unchanged; a new sale uses the new cost.
7. **Copy**: from / next / all, independence afterwards, confirm-replace rule (409 `requires_confirmation`), cross-product refused, target never completes, complete target reopens; `copy_bom_from_variant_id` on variant create.
8. **PO seller**: create requires `artist_id`; list filter + `artist_name`; legacy PO shows null and can be assigned (audited); changing the seller of a PO used by a BOM → 409; delete of an ordered PO already refused.
9. **Legacy**: an old-style row stays visible with `is_legacy`, is costed from the vendor price list, blocks completion, and can be replaced one by one.
10. **Authorization**: cashier 403 everywhere; a user with `products` but not `purchase_orders` can read a BOM but gets 403 on eligible-lines/add/copy/complete. DEMO rows never listed/copied in LIVE.
11. **Audit**: every mutation writes its `activity_logs` row inside the same transaction (a forced failure leaves no log and no change).

## Real-browser check (Constitution II) — ISOLATED server + test DB

1. `APP_ENV=testing php artisan migrate:fresh --force && db:seed && license:dev-activate`, then seed: sellers A and B each with a keychain product (variants Red/Blue/Green), vendors X/Y/Z, POs for A (ball chain, ring, assembly service; ordered/received), one PO for B, one draft PO, one cancelled PO, one legacy PO without seller, and one old-style BOM line.
2. **PO form**: Seller required, no "Linked Product"; list shows Seller + filter; the legacy PO shows "No seller" and can be assigned.
3. **Product detail → Red → BOM**: table columns, Add BOM Item selector shows only A's lines (filters by PO, vendor, text, type, date), tick three, add; totals Material/Service/Total correct; edit quantity; remove one.
4. **Copy**: Red → next (Blue) → all (Green); edit Blue only → Red/Green unchanged; confirm-replace dialog on a target that has rows.
5. **Complete**: mark complete → product edit drawer shows cost price read-only "From BOM" with the BOM cost; change a quantity → cost price follows; reopen → editable with last value; legacy row blocks completion with a message.
6. **Cues**: create a newer PO at a different price → "Newer price" badge, replace works; cancel a source PO → "Source cancelled" badge, cost unchanged.
7. Cashier cannot see BOM/PO costs; EN ↔ ID; console clean; screenshots (no customer data) into `specs/034-seller-po-bom/evidence/`.
