# Quickstart: verifying Partial and Split Payments

## Automated

```bash
# Backend — host only. NEVER bare `php artisan test` inside the app container (CLAUDE.md).
php artisan test --filter='Payment|Order|Preorder|Session|Sales'      # broad regression
# Docker alternative (explicit overrides are mandatory):
docker compose exec -e APP_ENV=testing -e DB_DATABASE=boothpos_test app php artisan test --filter=PaymentServiceTest

npm test
```

Backend cases to cover:
- **Ledger**: partial payment saved alone → summary `partially_paid`; second payment → `fully_paid`; third → 409; amount 0 / negative / above remaining → 422 with the maximum; applies to a pre-order **and** a POS order.
- **Summary maths**: for an order paid with cash overpay at checkout (change given) `total_paid` = total (not tendered); sum of entries always equals `total_paid`; rejected entries excluded.
- **Idempotency**: same `client_ref` twice → one row, second call 200; two simultaneous identical requests → one row (unique index); a ref on another transaction → 422.
- **Concurrency**: second request that would exceed the balance left by the first → 422 with the fresh remaining.
- **POS partial checkout**: paid < total without customer → 422; with customer → order `completed`, stock deducted, `change_amount` 0, summary `partially_paid`; fully paid checkout unchanged.
- **Late POS payment**: add cash with an open shift → stored `session_id` = that shift; without an open shift → 409; non-cash without a shift allowed.
- **Shift reconciliation**: shift A sells Rp 500.000 and receives Rp 200.000; shift A closes; shift B receives Rp 300.000 → A's `expected_cash` includes only the 200.000 (and its change), B's expected cash includes the 300.000; voided order's payments excluded in both.
- **Delete**: owner/admin only; recalculates summary and (pre-order) status; POS cash payment of a **closed** shift → 409; voided order → 409; audit rows exist for record and delete.
- **Pre-order regression**: the rewritten overpay test (now 422), existing `PreorderTest`/`SplitPaymentTest`/`PreorderPaymentDeleteTest` still pass; reports (`ReportTest`, `PreorderReportTest`) unchanged.

Frontend (Vitest): summary card (partial / multiple-payments counter / fully paid banner), history list fields, Add Payment modal (default amount = remaining, single-click save, loading disables, uuid reused on retry and regenerated after success, error keeps data), Add Payment hidden when fully paid/closed, POS checkout partial finish only with a customer and behind a confirmation, Sales payment-status filter and outstanding total, shift panel uses `cash_received`.

## Manual browser check (Constitution II)

Use an **isolated** server + test database (not the dev DB), as in the 027 session: seed with `db:seed` + `license:dev-activate` against `boothpos_test`, serve on another port.

1. **Pre-order**: open a Rp 1.000.000 order → summary shows Unpaid. Add Rp 200.000 cash → toast "recorded · Rp 800.000 remaining", summary Partially Paid, history 1 line. Add Rp 200.000 transfer with a reference → "Partially Paid · 2 payments". Add Rp 600.000 QRIS → Fully Paid banner, Add Payment gone.
2. **Guards**: amount above remaining → refused with the maximum; double-click Save → exactly one history line; simulate a slow network (DevTools throttling) and retry → still one.
3. **POS partial**: cart Rp 500.000 with no customer → try paying Rp 200.000 → refused/explained; attach a customer → confirm dialog → sale completes, receipt shows paid/remaining/status.
4. **Settle later**: Sales → filter "unpaid balance" → open the sale → Add Payment Rp 300.000 → Fully Paid.
5. **Shifts**: close the first shift, open a second, receive the late cash there → first shift's expected cash unchanged, second includes it; Sales shift panel agrees.
6. **Delete** (owner): remove a payment → summary and status recalc, Add Payment returns; a cashier sees no delete.
7. EN ↔ ID strings; browser console clean.

## Docs checklist before merging

`docs/openapi-pos-mvp.yaml` (routes + fields), PRD dated note (overpay reversal + POS partial payments), CLAUDE.md (payment ledger rules, shift attribution), `RUNBOOK` not affected.
