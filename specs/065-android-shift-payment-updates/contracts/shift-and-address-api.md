# Contract changes: shifts, cash movements, dashboard, server address

Only differences from the current API. Status conventions unchanged (422 shape, 409 business rule, 403 role). `docs/openapi-pos-mvp.yaml` is updated in the same change.

## GET /sessions — NEW (list)
Query: `status=open|closed|all` (default `open`), `source=app|web`, `user_id`, `date_from`/`date_to` (business dates, closed shifts by `business_date`), `page`/`per_page` (default 25, max 100). Outlet narrowed by `X-Outlet-Id` as everywhere.
- Owner/admin: all shifts of permitted outlets + mode. Everyone else: own shifts only (a foreign `user_id` is ignored).
- 200: `{ data: [Row], meta: {current_page, per_page, total, last_page} }`, `Row` as in data-model.md (money as strings, `number_format(…, 2, '.', '')`). Open rows newest-first, closed rows by `closed_at` desc. Constant query count (batched).

## GET /cash-movements — NEW (list across shifts)
Query: `direction=in|out`, `user_id`, `session_id`, `date_from`/`date_to` (business date of the shift), `page`/`per_page`. Same visibility rule as above.
- 200: `{ data: [{id, session_id, direction, reason_code, reason_label, reason_note, amount, created_by{id,name}, created_at, terminal_name|null, source}], meta, totals: {in, out} }` — totals cover the whole filtered set, not just the page.

## GET /dashboard/shifts — NEW (owner/admin, menu `dashboard`)
- 200: `{ data: [OpenShiftRow …≤50], total_open }` — one row per open shift: `id, cashier{id,name}, source, terminal_name, outlet{id,name}, opened_at, sales_total, order_count`.

## GET /sessions/current — CHANGED
Returns the caller's open shift **of the request's source** (app: valid `X-Device-Id`; web: none). 404 when that source has none (even if the other source has one).

## POST /sessions — CHANGED
409 `already_has_open_session` is now per (user, source). Body unchanged (`opening_cash` typed total OR `opening_denominations`; both must agree if both sent — the app sends exactly one).

## POST /sessions/{id}/close — CHANGED
- New optional body field `counted_total` (number ≥ 0, whole rupiah) — alternative to `counted_by_denomination` (and to legacy `closing_cash`). Either of the first two selects the controlled protocol: pending QRIS → 409 `pending_payments`; variance above tolerance → approval token bound to the exact count (`counted_total` included in the hash).
- `edc_batch_total` is no longer accepted: ignored if sent, never stored, absent from new `z_report`.
- Response (the saved session) unchanged otherwise.

## GET /sessions/{id}/summary and /x-report — CHANGED
Now include `expected_cash` (string, Q2 = B), plus the existing figures (`order_count`, `total_sales`, `by_method`, `cash_in`, `cash_out`, `change_given`, `void_count`, `void_total`, `terminal_name`).

## GET /server-address — NEW, PUBLIC (no token, no device)
Throttled (`throttle:30,1`). Behind the licence gate like every route.
- 200: `{ "selected": "local"|"online", "url": string|null }` — only the selected address; the other never leaves the server. `url: null` when the chosen option has no address saved.

## Unchanged / deprecated
- `GET /devices/server-address` stays for old APKs; the new APK does not call it.
- `GET /payment-channels` unchanged (`qr_image_url` host is rewritten client-side to the stored server address).
