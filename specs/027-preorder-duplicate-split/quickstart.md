# Quickstart: verifying Duplicate and Split

## Automated

```bash
# Backend — native/host only. NEVER run bare `php artisan test` inside the app container
# (see CLAUDE.md). If you must use Docker:
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderDuplicateTest
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PreorderSplitTest

# Frontend
npm test -- PreorderSplitModal PreordersView
```

Backend cases to cover (map to the spec):
- Duplicate: copies the listed fields; resets status/payments/shipment/dispatch markers; re-prices at the current variant price (change a price first and assert the new subtotal); source untouched; new unique number; `source` populated; cancelled and handed-over sources are duplicable.
- Bulk: 3 ids → 3 independent orders; one with a soft-deleted variant → `failed` while the others are `created`; an id from the other data mode → `failed` ("not found"); >100 ids → 422.
- Edge: deleted event → copy has `event_id` null and no pickup day; pickup day outside range dropped; discount exceeding the re-priced total → failed entry.
- Split: whole-line and partial-line moves; Σ subtotal reconciles; stock identical before/after (also for an "arrived" order, with **no** new `stock_movements`); item row id preserved for whole-line moves; all-moved / none-moved → 422; foreign item id → 422; payment present → 409; handed-over/cancelled → 409; discount cap → 409; by-seller with 1 seller → 422, with 2+ → N orders; rollback leaves nothing behind when a late step fails.
- Cross-cutting: no email/notification rows; one `duplicated`/`split` activity row per action; DEMO/LIVE — copies are stamped with the active mode and a cross-mode id is never touched; numbers stay unique across both modes.

## Manual browser check (required by Constitution II — API + SPA running)

1. Log in as `owner` (local dev password in CLAUDE.md), open **Pre-orders**.
2. **Duplicate one**: row actions → Duplicate. Expect a success message with the new number, a new row at the top with status *Ordered*, *Not sent yet*, outstanding = total. Open it: "Duplicated from PO-…" is shown and links back.
3. **Duplicate many**: tick three rows → *Duplicate selected*. Expect the result modal listing three source → new pairs; list count and the summary cards (transaction count, Ordered total, grand total) increase immediately.
4. **Failure path**: deactivate a product used by one selected order, duplicate again → that row shows the reason, the others are created.
5. **Split**: open an *Ordered* order with ≥ 2 lines (e.g. the multi-seller order `vlaisca, sapphirefiless`) → Split. Move 1 unit of one line. Expect two orders whose subtotals add up to the old one; discount/shipping remain on the original; the original detail lists "Split into PO-…" and the new one "Split from PO-…".
6. **Split by seller** on the same kind of order → one order per extra seller.
7. **Blocked states**: record a deposit on an order → its Split action is disabled with the explanatory tooltip; a *Handed over* / *Cancelled* order shows no Split action.
8. Check the browser console is free of errors throughout, and switch EN/ID to confirm all new strings exist in both languages.

## Docs/contract checklist before merging

- `docs/openapi-pos-mvp.yaml`: both routes + `source` / `split_children` fields.
- PRD: dated note recording the new capability (no scope cut is being reversed).
- CLAUDE.md: add a short note on the duplicate-on-`create()` rule and the "split never touches stock" invariant.
