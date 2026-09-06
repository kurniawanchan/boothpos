# Quickstart & Manual Verification: Billing Records

1. **Create an invoice**: `POST /companies/{id}/invoices` with `amount`/`due_date` — verify `201`, status `unpaid`.
2. **Mark paid**: `POST /invoices/{id}/mark-paid` — verify `200`, status `paid`, `paid_at` set.
3. **Invalid transition**: `POST /invoices/{id}/mark-paid` again — verify `409`.
4. **Cancel**: create a second invoice, `POST /invoices/{id}/cancel` — verify `200`, status `cancelled`.
5. **Company history**: `GET /companies/{id}/invoices` — verify both invoices listed with correct status.
6. **Role gating**: as cashier/inventory, any of the above — verify `403`.
