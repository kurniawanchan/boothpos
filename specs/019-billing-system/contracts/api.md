# Contract: Invoice Endpoints

Gated on the existing `companies` menu key (owner/admin only).

```
GET  /companies/{company}/invoices          list this company's invoices (?status=)
POST /companies/{company}/invoices          create — {amount, due_date, notes?}
POST /invoices/{invoice}/mark-paid          unpaid → paid
POST /invoices/{invoice}/cancel             unpaid → cancelled
GET  /invoices?status=                      overall list, filterable (FR-006)
```

`mark-paid`/`cancel` on a non-`unpaid` invoice → `409` (research.md R1).
