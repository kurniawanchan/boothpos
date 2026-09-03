# API Contract: Pre-order Import/Export, Printing, Email Notification & Search

All routes are under the existing `/api/v1` prefix, `auth:sanctum` middleware group, alongside the current `preorders` routes. `docs/openapi-pos-mvp.yaml` MUST be updated in the same commit these ship (Constitution "Documentation & Change Discipline").

## GET /preorders (extended, not new)

Adds one optional query param to the existing endpoint:

- `search` (string, optional) — partial, case-insensitive match against the pre-order's customer name. Combinable with existing `status`/`event_id`/`customer_id`/`fulfillment` filters (US1, FR-001).

No response-shape change.

## GET /preorders/{id}/invoice (new)

- **Auth**: any role that can already view the pre-order (`show()`'s existing implicit access — pre-orders have no Policy today; this endpoint follows the same access level, not a new restriction).
- **Response 200**: same shape as `GET /preorders/{id}`, plus:
  - `document_type`: `"invoice" | "receipt" | "cancelled"` (server-computed from status, per data-model.md's mapping table — FR-002/003/004)
  - `outstanding`: string money (already computed elsewhere in this codebase for pre-orders; surfaced here for the invoice's "amount due" line)
- **404** if the pre-order doesn't exist / belongs to the other DEMO/LIVE mode.

## GET /preorders/export (new)

- **Auth**: `isOwnerOrAdmin()` only (FR-015) — 403 otherwise.
- **Query params**: same filters as `GET /preorders` (`status`, `event_id`, `customer_id`, `fulfillment`, `search`, `date_from`, `date_to`) — export respects whatever filters are active (US3 Acceptance Scenario 1).
- **Response 200**: `.xlsx` file download (`Maatwebsite\Excel\Facades\Excel::download`, via `GenericArrayExport`) — one row per pre-order, columns matching the import template below so the file round-trips (mirrors the master-data export/import round-trip convention).

## GET /preorders/import/template (new)

- **Auth**: `isOwnerOrAdmin()` only.
- **Response 200**: `.xlsx` file — a blank/example workbook with the exact column headers `POST /preorders/import` expects (F15.4-style precedent from master-data import).

## POST /preorders/import (new)

- **Auth**: `isOwnerOrAdmin()` only — 403 otherwise.
- **Request**: `multipart/form-data`, `file` (the `.xlsx`), optional `dry_run=1` (preview without writing, R3).
- **Behavior**: full validation of every row first; if any row fails, **nothing is written** (409, all-or-nothing per FR-008/R3) and the response lists every failing row + reason. If all rows pass, one DB transaction creates every pre-order (+ any new `Customer` rows, FR-009) at `status = 'ordered'` (FR-010).
- **Response 201** (or 200 when `dry_run=1`):
  ```json
  {
    "created_count": 12,
    "created_customer_count": 3,
    "preorder_ids": [101, 102, "..."]
  }
  ```
- **Response 409** (validation failed, nothing written):
  ```json
  {
    "message": "Impor gagal — tidak ada baris yang disimpan.",
    "row_errors": [
      { "row": 4, "errors": ["SKU 'ABCXX9999' tidak ditemukan"] }
    ]
  }
  ```

## POST /preorders/{id}/notifications/resend (new)

- **Auth**: `isOwnerOrAdmin()` only (FR-015) — 403 otherwise.
- **Behavior**: calls `PreorderNotifier::notifyStatusChange($preorder, trigger: 'manual_resend')` synchronously (R7) — same send logic as an automatic status-change notification, independent of whether the status actually just changed (FR-014).
- **Response 200**:
  ```json
  {
    "status": "sent",           
    "recipient_email": "siti@example.com",
    "sent_at": "2026-09-03T13:05:00+07:00"
  }
  ```
  `status` is one of `sent | skipped_no_email | skipped_not_configured | failed` (never a raised exception for a delivery failure — always a 200 with the outcome, since "the request to attempt notification" succeeded even if the send itself didn't; FR-012/FR-013 explicitly say this must not surface as a blocking error).

## GET /preorders/{id} (extended, not new)

Response gains one field:

- `latest_notification`: `{ trigger, status, error_message, sent_at }` object or `null` if no attempt has ever been made for this pre-order (R6) — lets the existing detail screen show notification state without an extra request.

## Side effect (no new route): status-change auto-notify

`PATCH /preorders/{id}/status`'s existing response is unchanged, but after its transaction commits, `PreorderNotifier::notifyStatusChange($preorder, trigger: 'status_change')` fires (R7) — fire-and-log, never raises, never delays or fails the status-change response itself.
