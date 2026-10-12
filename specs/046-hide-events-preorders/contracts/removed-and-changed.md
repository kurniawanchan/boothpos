**Classification**: INTERNAL

# Contract changes — 046

Removed API routes answer **404** (they no longer exist); the SPA redirects the old screen addresses (UX only; the server stays the control).

## Removed routes

**Events**: `GET|POST /events`, `GET|PUT|PATCH|DELETE /events/{event}`, `PATCH /events/{event}/status` (closing an event no longer exists).

**Pre-orders**: `GET|POST /preorders`, `GET|PUT|DELETE /preorders/{id}`, `PATCH /preorders/{id}/status`, `PATCH /preorders/{id}/dispatch-status`, `POST /preorders/{id}/payments`, `DELETE /preorders/{id}/payments/{payment}`, `PATCH …/payments/{payment}/confirmation`, `POST …/payments/{payment}/verify`, `POST /preorders/{id}/split`, `POST /preorders/duplicate`, `POST /preorders/{id}/shipment`, `PATCH /shipments/{id}`, `GET /preorders/{id}/invoice`, `POST /preorders/{id}/notifications/resend`, `POST /preorders/bulk-invoices`, `POST /preorders/bulk-email`, `GET /preorders/summary`, `GET /preorders/export`, `GET /preorders/import/template`, `POST /preorders/import`, `GET /reports/preorders`, and the `preorder` value of `GET /reports/{report}/export`.

## Changed routes

| Route | Change |
|---|---|
| `POST /sessions` | `event_id` no longer accepted/required; no "active event" rule |
| `GET /sessions/current`, `/sessions/{id}/summary`, session payloads | no `event_id` / `event_name` |
| `POST /orders`, `GET /orders`, `GET /orders/{id}`, `/orders/{id}/receipt` | no event field in input, output or filter; receipt loses the event/available-on block |
| `GET /reports/sales`, `/reports/profit`, `/reports/outlet-settlements`, `/reports/outlet-profit`, `/reports/outlet-settlements/{outlet}/transactions` and their exports | `event_id` removed; `date_from`/`date_to` (business dates, default current business day); sales only (no pre-order revenue) |
| `GET /reports/profit` | `event_cost` and `net_profit` removed |
| `GET /dashboard/*` | no per-event section; figures by period |
| `GET /customers/{id}/…` history | sales only |
| `GET|POST /products…` | `is_preorder`, `preorder_eta`, `?is_preorder=` removed |
| master-data products sheet / template | `is_preorder`, `preorder_eta` columns removed; files that still carry them import without error (ignored) |
| `GET /stock/movements` | legacy pre-order rows keep `reference: null`; no pre-order reference resolution |
| `GET /auth/me`, `GET /menu-keys`, role payloads | `events` and `preorders` no longer exist as menu keys |
| payment endpoints | order target only |

## Client-side addresses

`/events`, `/preorders` (and any child path or `?event_id=` / `?preorder_id=` query) → default landing screen. No error page.

## Documentation obligations (constitution)

`docs/openapi-pos-mvp.yaml` is updated in the same commit as each stage's route/response change; dated notes in `CLAUDE.md` and the PRD record that events and pre-orders were removed as capabilities (history not rewritten).
