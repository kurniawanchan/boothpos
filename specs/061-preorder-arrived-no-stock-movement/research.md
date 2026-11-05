**Classification: INTERNAL**

# Research: Pre-order Arrival Without Stock Movement

Facts come from reading the code and the dev database on 2026-11-05. Anything else is marked ASSUMPTION.

## D1. Where arrival moves stock today

- **Fact**: `PreorderService::transitionStatus()` loops items on `arrived` and calls `StockService::applyMovement(type: 'purchase', +qty, referenceType: 'preorder_item')`. On `handed_over` it applies `preorder_handover` with `-qty`.
- **Decision**: delete the `arrived` loop entirely. Keep the `handed_over` loop unchanged.
- **Alternative rejected**: keep a zero-quantity movement for traceability. `applyMovement` refuses qty 0 and a no-op history row would add noise.

## D2. Editing after arrival

- **Fact**: `PreorderService::update()` has a block for statuses `arrived`/`settled` that writes a `purchase` delta (`referenceType: 'preorder'`). Its stated reason: "stock was already added at arrival".
- **Decision**: remove the block. With no stock added at arrival, an edit must not move stock. Hand-over deducts the final quantities.
- **Consequence** (accepted, spec edge case): a pre-order that arrived BEFORE this change already added stock for its old quantities. Editing it later does not adjust that. Hand-over deducts the final quantity, so for an edited legacy pre-order the net can differ from the old quantity by the edit. ASSUMPTION: rare (dev DB has one arrived pre-order); not worth special handling.

## D3. Is hand-over net-correct for pre-orders that arrived under the old rule?

- **Fact**: old rule = +qty at arrival, -qty at hand-over (net 0, goods "pass through" stock). New rule = nothing at arrival, -qty at hand-over.
- **Finding**: for a legacy arrived pre-order the stock it added is still counted, so the hand-over deduction removes it, exactly as before. No special handling needed. Stock is only wrong if staff stop entering received goods elsewhere, which is the shop's process and not code.

## D4. Hand-over now needs stock entered beforehand

- **Fact**: `StockService` refuses a movement that would go below zero (422 `qty_change`, message `master_data.insufficient_stock`). Arrival used to supply the stock; now it does not.
- **Fact**: `PreorderController::updateStatus()` maps any `ValidationException` to 409.
- **Decision**: no change. Hand-over is refused with the existing message, status stays `settled`, nothing is written (the loop runs inside one transaction). Spec FR-004 and the assumption about entering stock beforehand cover this.

## D5. Settle on arrival when fully paid (the stuck pre-order)

- **Fact**: the UI offers "Mark arrived" (status `dp_paid`) and "Mark handed over" (status `settled`) but no Settle action. `PaymentService::syncCache()` moves `arrived` to `settled` only when a payment is written and the summary is fully paid. `ALLOWED_TRANSITIONS` includes `arrived → settled`, so the API (not the UI) can also settle manually; `PreorderTest` does that.
- **Fact (dev DB)**: PO-20261001-0004 has status `arrived`, total 25000, paid 25000. It is the only `arrived` pre-order. Arrival happened after full payment, so no payment event ever settled it.
- **Decision**: add `PreorderService::settleArrivedIfFullyPaid(Preorder): bool` = if `status === 'arrived'` and `PaymentSummary::for($p->load('payments'))['status'] === FULLY_PAID`, set status `settled`, return true. `transitionStatus()` calls it inside its transaction right after setting `arrived`; the command calls it per row.
- **Single rule**: `PaymentService::syncCache()` has the same predicate on a summary it already holds, under its own lock. Not refactored to call the new method: PaymentService is the payment write path and PreorderService delegates to it, so the reverse call would be circular. Both read the same `PaymentSummary::FULLY_PAID`. Accepted.
- **Alternatives rejected**: a manual "Mark settled" button (more clicks, still lets fully paid orders sit); changing `ALLOWED_TRANSITIONS` (not needed).

## D6. One-off correction: command, not migration

- **Decision**: artisan command `app:settle-paid-arrived-preorders`. Dry-run by default (lists pre-order numbers and mode only), `--apply --by=<username>` writes. `--by` must be an existing owner/admin (`isOwnerOrAdmin()`).
- **Why a command**: migrations run automatically at container start (`docker/php/entrypoint.sh`), so a data change there would apply silently; precedent (`app:sync-stock-from-transactions`, `app:clone-live-to-demo`) keeps data corrections explicit.
- **Why lighter than 051**: no plan hash, no Excel, no safety backup. Nothing about money or stock changes, only `status` arrived → settled for rows that already satisfy the settlement rule, and the existing payment-delete path can already move `settled` back to `arrived`. Each change is activity-logged with old and new status.
- **Modes**: loops `demo` and `live` using `ModeGate::runAs()`, because the global scope hides the other mode's rows (CLAUDE.md DEMO/LIVE rules).
- **Idempotent**: the selection only matches rows still `arrived` and fully paid; a second run finds none.
- **Privacy**: output prints preorder numbers and counts only, never customer fields.
- **Activity log**: written through `ActivityLogger`, inside the same transaction as the status update, action `preorder_status_corrected` (ASSUMPTION: new action string; log screen shows arbitrary action text), entity `preorder`.

## D7. Notifications (verified 2026-11-05, task T032)

- **Fact**: `updateStatus()` calls `notifyStatusChangeSafely($preorder)` after commit. `PreorderNotifier::notifyStatusChange()` sends ONE email per call, and `PreorderStatusMail` picks its document and subject from the pre-order's CURRENT status through `PreorderDocumentType::forStatus()` (`arrived` = invoice, "Barang tiba"; `settled` = receipt, "Lunas").
- **Finding**: when arrival auto-settles a fully paid pre-order, the status at notification time is `settled`, so the customer gets exactly one email, with the "Lunas" receipt theme, and no separate "Barang tiba" email. No duplicate send and no error.
- **Decision**: no code change. Visible consequence for the shop: for pre-orders paid in full before arrival, the customer is told "Lunas", not "Barang tiba". If the shop wants a "Barang tiba" email in that case, it needs a product decision (a second send, or a different template); not part of this feature.
- The correction command sends no email (data fix, not a customer event).

## D8. Other readers of arrival `purchase` movements

- **Fact**: `StockMovementReferences` resolves `purchase` + `preorder_item` / `preorder` references; `SalesByProductReport` stock-in counts positive `initial`/`purchase`/`adjustment` movements; `app:sync-stock-from-transactions` uses `sale + preorder_handover − return` and ignores arrival.
- **Decision**: no code change. Historic arrival rows stay readable (reference resolution untouched). Going forward, pre-order arrivals stop appearing in stock-in. The 051 command is unaffected. Documented in CLAUDE.md.

## D9. Frontend

- **Fact**: `PreordersView.markArrived` toasts `preorders.arrived_marked` = "Marked as arrived — stock increased." (EN) and an Indonesian equivalent. The status stepper and buttons need no change.
- **Decision**: change both strings to say stock is unchanged until hand-over. After auto-settle the detail refresh shows `settled` and the existing "Mark handed over" button appears (the SPA already renders from the returned status).

## D10. Tests and seeder that encode the old rule

- `PreorderTest`: `test_arrived_status_increases_stock` (rewrite to "no movement"), and the hand-over tests that relied on arrival supplying stock (variant starts at stock 0): give the variant stock up front.
- `PreorderUpdateTest`: delta-after-arrival tests (lines ~75-127) become "no movement" tests.
- `StockMovementsTest` (lines ~165-262) and `StockTransactionSyncTest` (~117): fixtures use arrival to create `purchase` rows; seed those rows directly or via `applyMovement` instead.
- `SakanaFridgeDemoSeeder::seedPreorders()`: its full-cycle pre-order arrives then hands over; without arrival stock the hand-over would be refused. Ensure the demo variant has stock before hand-over and fix the comment that says "arrived (stok naik)". Verify by running the seeder against `boothpos_test` only.
- `PreorderSplitTest`, `PreorderPaymentLedgerTest`, `PreorderPaymentDeleteTest`, `PreorderDeleteTest`: drive status through `arrived` but assert no stock; expected to pass, re-run to confirm. Note: a fixture that pays fully before arrival will now end `settled`, not `arrived`; check each.

## Open questions

None.
