# Phase 1 Data Model: Pre-order Import/Export, Printing, Email Notification & Search

## Existing entities touched (no schema change)

- **Preorder** — unchanged columns. Gains: a `search` query capability against its `customer.name` relation (index-level, not a new column); a computed, non-persisted `document_type` on the invoice endpoint response.
- **Customer** — unchanged columns. Gains: rows may now be created via import (existing `Customer::create()` path, same as manual pre-order creation already does when given a new customer).
- **PreorderItem**, **Payment**, **Shipment** — unchanged; read-only inputs to export/print, and Payment/PreorderItem rows are created by import exactly as `PreorderService::create()` already creates them for a manually-entered pre-order.

## New entity: PreorderNotification

Audit trail of every email-notification attempt for a pre-order (R6). NOT `HasDataMode` — operational/administrative metadata, same category as `activity_logs`/`payment_channels` (CLAUDE.md's DEMO/LIVE section), not customer-facing business data.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `preorder_id` | FK → `preorders.id`, cascade on delete | |
| `trigger` | enum(`status_change`, `manual_resend`) | What caused this attempt |
| `triggered_by_status` | string, nullable | The pre-order's status at the moment this attempt fired (for `status_change`); null for `manual_resend` |
| `recipient_email` | string, nullable | Snapshot of the address attempted, even if it later changes on the Customer record |
| `status` | enum(`sent`, `skipped_no_email`, `skipped_not_configured`, `failed`) | |
| `error_message` | text, nullable | Populated only when `status = failed` |
| `sent_at` | timestamp, nullable | When the attempt was made (regardless of outcome) |
| `created_at` / `updated_at` | timestamps | |

**Validation rules**: `status` is always set by `PreorderNotifier`, never client-supplied — this table has no create/update API surface of its own, it is written only as a side effect of the notify flow (R7).

**Relationships**: `Preorder hasMany PreorderNotification`; `PreorderController::show()` includes `latest_notification` (the most recent row) in its response so the frontend can render "notification sent ✓ / failed ✗ / not configured" without a second request.

## State / status mapping used by print + email (single source of truth, per R2)

| Pre-order `status` | `document_type` | Email subject theme |
|---|---|---|
| `ordered` | `invoice` | "Pesanan diterima" |
| `dp_paid` | `invoice` | "DP diterima" |
| `arrived` | `invoice` | "Barang tiba" |
| `settled` | `receipt` | "Lunas" |
| `handed_over` | `receipt` | "Pesanan diserahkan" |
| `cancelled` | `cancelled` | "Pesanan dibatalkan" |

This table lives once, server-side (`PreorderController`'s `present()`-style helper or a small dedicated `PreorderDocumentType` support class), consumed by both the invoice endpoint and `PreorderNotifier`'s email subject/body — never duplicated in the frontend.

## Import row shape (one sheet, one workbook — R3/R4)

| Column | Required | Notes |
|---|---|---|
| `customer_name` | yes | Resolves or creates a `Customer` by exact name match |
| `customer_phone` | no | Only used when creating a new customer |
| `customer_email` | no | Only used when creating a new customer |
| `event_id` or `event_code` | yes | Must resolve to an existing `Event` (mode-scoped `findOrFail`, closing the same cross-mode gap `OrderService`/`PreorderService::create()` already close for `customer_id`) |
| `sku` | yes (per item row) | Resolves `ProductVariant` — unknown SKU fails that row |
| `qty` | yes (per item row) | integer, min 1 |
| `unit_price` | yes (per item row) | the recorded historical price (R4 — not re-priced from current master data) |
| `fulfillment` | no, default `pickup` | enum matching `Preorder.fulfillment`'s existing values |
| `notes` | no | |

One pre-order = one or more consecutive rows sharing the same `customer_name` + a grouping column (`row_group` or repeated `event_id`+timestamp block, exact grouping convention finalized during implementation against `MasterDataSheetsImport`'s existing row-grouping precedent, if any, or a simple "blank customer_name means same order as previous row" convention matching common spreadsheet authoring habits) — finalized in `contracts/api-contract.md`.

Every imported pre-order is created at `status = 'ordered'`, `paid_amount = 0` (FR-010) — no payment or later-status rows are created by import itself, even if the file's data conceptually reflects a further-along order; the operator advances it after import using the existing `PATCH /preorders/{id}/status` and `POST /preorders/{id}/payments` endpoints.
