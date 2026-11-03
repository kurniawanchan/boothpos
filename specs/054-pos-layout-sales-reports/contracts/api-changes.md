**Classification: INTERNAL**

# API contract changes: 054

All paths are under `/api/v1`, Sanctum-authenticated, behind the licence gate. Conventions as everywhere: `422` validation, `409` business-rule conflict, `403` role/ownership denial, money as 2-decimal strings, list envelope `{data, meta:{current_page, per_page, total, last_page}}`. `docs/openapi-pos-mvp.yaml` moves in the same commit as the routes.

**Route order**: register the static paths below *before* `GET /orders/{order}` and before `GET /reports/{report}/export`.

## 1. Changed: `GET /reports/sales` rows (additive)

Each `transactions[]` row gains:

| Field | Type | Notes |
|---|---|---|
| `has_reference` | boolean | sale has a note or an image |
| `items_gross` | string | Σ(line_total + item discount) |
| `items_net` | string | Σ(line_total) |

`totals` is unchanged (Dashboard and Reports still use it). The Sales screen stops reading `totals` for its KPI cards.

## 2. Changed: order detail payload (`GET /orders/{order}`, and every order response)

- `reference`: `{ note: string|null, has_image: boolean, updated_by_name: string|null, updated_at: string|null, can_edit: boolean }`, present when the editor relation is loaded.
- Each `payments[]` entry gains `can_change_method: boolean`.

## 3. New: change a payment's method

`PATCH /orders/{order}/payments/{payment}/method`

Request:
```json
{ "method": "qr_ewallet", "channel_id": 3 }
```
`channel_id` required for `qr_ewallet`/`bank_transfer`, must be omitted/null for `cash`.

| Status | When |
|---|---|
| 200 | updated order payload (same shape as `PATCH …/confirmation`) |
| 403 | caller is not owner/admin or the payment's recorder |
| 409 | order voided; cash payment in a closed shift (`code: closed_shift`); cash with change given (`code: change_given`); target cash but caller has no open shift |
| 422 | method/channel unchanged; missing or wrong-type channel |

Side effects (one transaction): method/channel/verification/session updated, order paid cache re-synced, activity log `payment_method_changed` with before/after. Amount, totals, status are never touched.

## 4. New: transaction reference

| Route | Purpose | Gate |
|---|---|---|
| `GET /orders/{order}/reference` | `{ note, has_image, updated_by_name, updated_at, can_edit }` | menu `sales` |
| `GET /orders/{order}/reference/image` | binary image, `Cache-Control: private, no-store` | menu `sales` (404 when none) |
| `PUT /orders/{order}/reference` | multipart: `note?`, `image?`, `remove_image?` — creates or updates | owner/admin or the sale's cashier |
| `DELETE /orders/{order}/reference` | removes note and image | owner/admin or the sale's cashier |

Errors: 403 not allowed; 422 bad file type/size/oversized note, or a change that would leave the reference empty (use DELETE); 409 never (a voided sale may still be annotated — reference carries no money; see spec Story 8 scenario 7).

Side effects: activity log `order_reference_updated` / `order_reference_removed` inside the transaction; replaced/removed file deleted after commit.

## 5. New: bulk receipts

`POST /orders/bulk-receipts`

Request: `{ "order_ids": [12, 13, …] }` (1..200).
Response `200`: `{ "data": [ <receipt payload as GET /orders/{id}/receipt>, … ], "missing": [id, …] }`. Ids not found in the active DEMO/LIVE mode go to `missing`; more than 200 ids → `422`. Gate: same as the single receipt (any authenticated role). One eager-loaded query set, no per-order queries. Payload fields are exactly those of the single receipt (no new customer data).

## 6. New: sales by product

`GET /reports/sales-by-product?event_id=&date_from=&date_to=&q=`  (gate: `reports` menu; 403 otherwise)

```json
{
  "scope": { "event_id": 1, "date_from": null, "date_to": null },
  "best_seller_rule": { "top": 3, "metric": "units", "ties_included": true },
  "totals": { "units": 140, "sales": "2100000.00", "transaction_count": 61 },
  "data": [
    { "product_id": 5, "product_name": "Pins", "artist_id": 2, "artist_name": "vlaisca",
      "units": 52, "sales": "780000.00", "transaction_count": 31,
      "rank": 1, "is_best_seller": true,
      "variants": [ { "variant_name": "Orange cat", "units": 9, "sales": "135000.00" } ] }
  ]
}
```
`rank`/`is_best_seller` are computed over the full scope (ignoring `q`); `data`/`totals` honour `q`. Only completed, non-voided POS sales; `data_mode`-isolated.

`GET /reports/sales-by-product/{product}/transactions?event_id=&date_from=&date_to=&q=&page=&per_page=`
→ paginated `{ data: [{ order_id, order_number, created_at, cashier_name, qty, amount }], meta }` (`qty`/`amount` are that product's lines only).

## 7. New: sales by payment method

`GET /reports/sales-by-payment-method?event_id=&date_from=&date_to=`  (gate: `reports` menu)

```json
{
  "totals": { "transaction_count": 61, "amount": "2100000.00", "verified_amount": "1900000.00", "unverified_amount": "200000.00" },
  "data": [
    { "method": "cash", "channel_id": null, "label": "cash", "transaction_count": 40, "amount": "1200000.00",
      "verified_amount": "1200000.00", "unverified_amount": "0.00" },
    { "method": "qr_ewallet", "channel_id": 3, "label": "QRIS BCA", "transaction_count": 21, "amount": "900000.00",
      "verified_amount": "700000.00", "unverified_amount": "200000.00" }
  ]
}
```
Amounts: completed orders, rejected payments excluded, cash net of change, dated by the order's creation (same basis as the Sales page). A split-payment sale counts once in each method row with that method's portion; `totals.transaction_count` counts distinct sales.

`GET /reports/sales-by-payment-method/transactions?method=&channel_id=&event_id=&date_from=&date_to=&page=&per_page=`
→ paginated `{ data: [{ order_id, order_number, created_at, cashier_name, amount, verification }], meta }`.

## 8. New: cashier sessions

| Route | Response |
|---|---|
| `GET /sessions?user_id=&event_id=&status=&date_from=&date_to=&page=&per_page=` | paginated list (list row in data-model.md); non-owner/admin always scoped to own sessions server-side |
| `GET /sessions/{session}` | detail incl. opening/closing figures; 403 for another cashier's session unless owner/admin (same rule as `summary`) |
| `GET /sessions/{session}/transactions?page=&per_page=` | paginated: sales made in the shift and cash payments received in it (`kind`) |

`/sessions/current` stays first (static before `{session}`). Opening and closing endpoints are unchanged; `close()` and the new open-shift "expected cash so far" share one private formula.

## 9. Frontend API modules

`resources/js/api/orders.js` (+`changePaymentMethod`, `getReference`, `saveReference`, `deleteReference`, `bulkReceipts`), `reports.js` (+`salesByProduct`, `salesByProductTransactions`, `salesByPaymentMethod`, `salesByPaymentMethodTransactions`), `sessions.js` (+`listSessions`, `getSession`, `sessionTransactions`).
