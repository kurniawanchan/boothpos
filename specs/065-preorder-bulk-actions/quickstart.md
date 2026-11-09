# Quickstart: verifying Pre-order Bulk Actions

**Classification**: INTERNAL

Local dev only. Seeded accounts and the DEMO dataset: `php artisan db:seed` + `php artisan db:seed --class=SakanaFridgeDemoSeeder`
(credentials in CLAUDE.md; not repeated). There is **no migration** in this feature.

## Automated

```bash
# Backend — inside Docker ONLY with the explicit overrides; ONE run at a time (a killed run can keep going in the container)
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test -e CACHE_STORE=array app \
  php artisan test --filter='PreorderBulk|Preorder|Shipment|PaymentLedger|MenuActionsRouteCoverage|ForbiddenSurfaces'

# Frontend
npx vitest run --exclude '.claude/**' qa-tests/component/PreorderBulk
```

Run the existing Preorder/Shipment/Payment suites FIRST (before extracting `setDispatchStatus` / `ShipmentService`) and again after:
they are the regression net. Backend cases that must exist (spec scenarios in brackets):

1. Selection: `matching-ids` honours every list filter, caps at `bulk_max` with `capped`, mode/seller scope (US1).
2. Status: mixed statuses → only valid transitions move; counts per target in preview; cancel needs one reason;
   `handed_over` skips unpaid and out-of-stock ones with the service's message; stock moves exactly once per moved pre-order;
   notification is sent after commit and a notifier exception never fails the change (US2).
3. **Parity**: for each action, the resulting row state of a bulk run equals the state after the individual endpoint on an identical twin.
4. Payment: per-row amounts, defaults = outstanding, over/zero/fully-paid/cancelled skipped, never overpaid; paid amount, remaining,
   status and auto-settle identical to individual; same `batch_ref` replay → `already_recorded`, no duplicate; missing channel → 422 with nothing written (US3).
5. Dispatch marker: `invoice_sent`/`pending`/`shipping`; shipping only Mail Order; cancelled skipped; already at target → `already_done`, dates not shifted (US5).
6. Shipments: one per eligible pre-order with its own recipient/tracking; existing shipment never overwritten/duplicated; pickup skipped;
   missing recipient skipped; repeat → `shipment_exists` (US6).
7. Atomicity: an exception injected for one pre-order rolls back ITS change and ITS audit row, others commit; message generic (US4, FR-007).
8. Result arithmetic: `succeeded + skipped + failed = selected = results.length` over random mixes; request order preserved (SC-004).
9. Permissions: each route refuses a role lacking its individual ability (403, nothing changes); non-visible/other-mode ids → `not_found`
   with no number leaked; the route-coverage and forbidden-surfaces tests stay green (SC-008).
10. Audit: per-pre-order `preorder_bulk_*` rows carry `bulk_ref` and old/new values; exactly one aggregate row per applied run; skipped/failed
    pre-orders have none; a rolled-back one has none (US7).
11. Limits: > `bulk_max` → 422 naming the limit on preview and apply; `preorder_bulk_max` exposed in features.
12. Query count: preview of 5 vs 50 pre-orders issues the same number of queries (warm up first).
13. Existing individual endpoints (`PATCH …/dispatch-status`, `POST …/shipment`, `PATCH …/status`, `POST …/payments`) unchanged (existing tests).

## Manual — real browser (Constitution II; NOT yet verified at plan time)

1. Pre-orders list: header checkbox (page → partial state → clear), "Select all N matching" over 2+ pages, Clear selection, count truthful after changing a filter.
2. Update status: mixed selection → options with counts → confirm summary → result; cancel flow asks one reason; handed-over skips unpaid.
3. Invoice sent / Shipping: pickup rows skipped with reason; dates kept on a second run.
4. Add payment: shared fields, per-row amounts (one edited partial), flagged row, confirm total, result, balances/status updated in the list; double-click Confirm; retry after a simulated drop → no duplicates.
5. Create shipments: shared courier, per-row tracking, a row missing an address flagged then fixed; one with an existing shipment skipped.
6. Result view: counts add up, click a number → detail opens, "Retry these" re-selects exactly the non-successes.
7. Phone/tablet width, keyboard only, console clean. Roles: a cashier without `payment` does not see "Add payment".
8. `npm run build`: `PreorderBulkModal` stays a separate lazy chunk (Constitution V).

## Done means

All cases above green with the exact commands run in-session; timing for a 50- and 200-pre-order status, payment and shipment run
measured (not assumed); OpenAPI + PRD/CLAUDE.md notes in the same change; the manual list executed or explicitly reported as not
executed; the 9 known `Code128` backend failures reported as pre-existing.
