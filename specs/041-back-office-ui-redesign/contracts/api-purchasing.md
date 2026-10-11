**Classification**: INTERNAL

# API contract — purchasing and suppliers (Phase 4)

Legend: **R** reason code required · **A** approval path.

## Suppliers and price catalogue

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /vendors` (existing) | list; gains filters `category`, `halal` (valid/expiring/none), `status`; summary figures (active count, PO 30-day value, on-time %, halal expiring) | `pur.supplier.view` | `halal_expiring` = certificate within 60 days |
| `POST/PUT /vendors` | create/update incl. `pic`, `phone`, `email`, `tax_no`, `payment_terms`, `lead_time_days`, `halal_cert_no`, `halal_cert_expires_on` | `pur.supplier.manage` | personal contact fields masked without `prv.pii.reveal` |
| `POST /vendors/{id}/deactivate` | deactivate | `pur.supplier.manage` | **R**; blocks new POs, open POs continue |
| `GET/POST/PUT /supplier-prices` | contract prices (`min_order_qty`, `valid_until`, `is_preferred`) | `pur.catalog.manage` | |
| `POST /supplier-prices/import` | price catalogue import | `pur.catalog.manage` | validate-then-apply (see imports in foundation) |
| `GET /vendors/{id}/scorecard?window=` | on-time %, fill rate, rejection %, price variance | `pur.supplier.view` | computed on read; below-target flag |

## Purchase requests

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /purchase-requests?status=` | list (queue of approvals for approvers) | `pur.pr.view` | |
| `POST /purchase-requests` | create manual or from suggestions (`source=reorder_suggestion`) | `pur.pr.create` | lines carry material and quantity only; value estimated server-side |
| `POST /purchase-requests/{id}/approve` · `/reject` | decide | `pur.pr.approve` | **A**; creator cannot approve (`409 SELF_APPROVAL`); reject **R** |
| `POST /purchase-requests/approve-bulk` | approve many | `pur.pr.approve` | per-row result list; self-created rows reported as failed, others proceed |
| `POST /purchase-requests/consolidate` | turn approved PRs into POs grouped by supplier | `pur.po.create` | |

## Purchase orders (existing resource, extended)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /purchase-orders` (existing) | gains `late`, status filter, ETA | `pur.po.view` | `late` = past ETA with open quantity |
| `POST /purchase-orders` · `PUT …` | draft with supplier, ship-to location, ETA, terms, lines | `pur.po.create` | unit price from catalogue; a price ≠ contract needs `reason_code_id` (**R**) |
| `PATCH /purchase-orders/{id}/status` (existing) | transitions: `draft→awaiting_approval→sent→partly_received→fully_received→closed`, `cancelled` | `pur.po.update` | invalid transition `409`; cancel **R**; approval transition **A** |
| `POST /purchase-orders/{id}/approve` · `/reject` | decide | `pur.po.approve` | **A**; reject returns to draft with **R** |
| `POST /purchase-orders/{id}/resend` | resend to supplier | `pur.po.update` | logged notification |
| `GET /purchase-orders/{id}/pdf` | PDF | `pur.po.view` | generation mechanism decided in Phase 7 task (UNVERIFIED library) |

## Goods receipts

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /purchase-orders/{id}/receipts` | receive lines (`qty_received`, `qty_rejected`, weighed qty if conversion approximate, `qc_result`, delivery-note photo, difference note) | `pur.gr.create` | posts stock movements per line and location; over-receipt `409` unless tolerance allows |
| `GET /goods-receipts` · `GET /goods-receipts/{id}` | list/detail | `pur.gr.view` | |

## Supplier invoices (3-way match)

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /supplier-invoices` | register invoice against a PO | `pur.invoice.create` | match computed server-side: `matched`, `price_diff`, `qty_diff` with the differing lines |
| `GET /supplier-invoices?match=` | list; `exception` filter feeds the page summary | `pur.invoice.view` | |
| `POST /supplier-invoices/{id}/approve-exception` | accept the difference | `pur.invoice.approve` | **A**, **R** (reason such as price increase approved, shipping cost, quantity correction) |
| `POST /supplier-invoices/{id}/return` | send back to supplier for correction | `pur.invoice.approve` | **R** |

## Returns to supplier

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /supplier-returns` | from a goods receipt (`items`, `reason_code_id`) | `pur.return.create` | **R**; posts negative stock movement |
| `POST /supplier-returns/{id}/credit-received` | mark credit received | `pur.return.update` | |
| `GET /supplier-returns` | list | `pur.return.view` | |

## Page-level summaries (for the KPI strip)

`GET /purchasing/summary` returns the four figures shown on the Pembelian page: PRs awaiting approval (count, value), late POs (count, supplier names), scheduled arrivals today/tomorrow, invoice exceptions (count, value difference). Figures equal the counts/sums of the lists with no filter.
