**Classification: INTERNAL**

# Contract: Seller Settlement API (`/api/v1`)

Must be mirrored in `docs/openapi-pos-mvp.yaml` in the **same commit** as the routes (constitution, Documentation & Change Discipline). All endpoints: bearer auth + license gate (as every route) + **menu `settlements`** (owner/admin by default). Authorization is enforced server-side: `FormRequest::authorize()` on writes, inline `canAccessMenu('settlements')` on reads → `403 {message}` otherwise. Status codes follow the project convention: `422` shape/validation, `409` business-rule conflict (always with a machine-readable `code`), `403` role denial, `404` not found or other DEMO/LIVE mode.

Money: strings, 2 dp (`"1250000.00"`). Dates: ISO 8601. Pagination envelope: `{"data":[…], "meta":{current_page, per_page, total, last_page}}`.

## Shapes

```jsonc
// SettlementSummary (list rows) and the head of SettlementDetail
{
  "id": 12, "number": "STL-20261026-0001" /* null while draft */,
  "status": "draft|finalized", "display_status": "draft|finalized|acknowledged|partially_paid|settled",
  "event": {"id": 3, "name": "…"}, "seller": {"id": 7, "name": "…", "code": "ABC"},
  "pos_units": 41, "pos_revenue": "1000000.00",
  "preorder_units": 12, "preorder_revenue": "250000.00",
  "revenue_total": "1250000.00", "adjustments_total": "-50000.00",
  "net_amount": "1200000.00", "direction": "store_pays_seller|seller_pays_store|none",
  "paid_amount": "400000.00", "balance": "800000.00",
  "payment_status": "net_zero|unpaid|partially_paid|settled",
  "acknowledged": true, "finalized_at": "…", "created_at": "…"
}
```

`SettlementDetail` adds:

```jsonc
{
  "sources": [{"id":1,"source_type":"order|preorder","source_id":88,"source_number":"…","source_date":"…",
               "units":3,"gross_amount":"…","amount":"…","uncollected_amount":"0.00"}],
  "adjustments": [{"id":5,"kind":"deduction|adjustment","label":"…","reason":"…","basis":null,
                   "amount":"-50000.00","post_finalization":false,"created_by_name":"…","created_at":"…"}],
  "acknowledgement": {"id":2,"method":"signature|typed|external","signer_name":"…","note":null,
                      "has_signature":true,"acknowledged_at":"…","recorded_by_name":"…"} /* current, or null */,
  "acknowledgement_history": [ /* superseded rows: same shape + superseded_at, superseded_reason */ ],
  "payments": [{"id":9,"amount":"400000.00","paid_at":"2026-10-27","method":"bank_transfer",
                "reference":"…","notes":null,"has_proof":true,"recorded_by_name":"…","created_at":"…"}],
  "reconciliation": {"sources_sum":"…","adjustments_sum":"…","expected_net":"…","net_amount":"…",
                     "difference":"0.00","pos_check":"0.00","preorder_check":"0.00","payment_check":"0.00","balanced":true,"failing":null},
  "memo": {"preorder_uncollected":"…","cost_of_goods":"…","gross_profit":"…"},
  "source_changed": null /* or {"pos_delta":"-25000.00","preorder_delta":"0.00","as_of":"…"} (finalized only) */,
  "can": {"finalize":true,"reopen":false,"delete":true,"acknowledge":false,"add_payment":false,"adjust":true}
}
```

`can.*` is computed by the server; the SPA never derives permission or state-validity itself.

## Endpoints

| Method & path | Purpose | Notes / errors |
|---|---|---|
| `GET /settlements` | List. Query: `event_id`, `artist_id`, `status` (display_status), `payment_status`, `page`, `per_page`. | Response adds `totals`: `{revenue_total, net_amount, paid_amount, balance}` over the **filtered** rows. |
| `GET /settlements/preview?event_id=&artist_id=` | Live statement that would be built (not persisted): `sources`, totals, `memo`. | Zero activity → zeros, not an error (spec US2-4). |
| `POST /settlements` `{event_id, artist_id}` | Create a **draft** with sources snapshotted from live data. | `409 settlement_exists` (`{existing_id}`); `422` unknown event/seller. |
| `GET /settlements/{id}` | Detail (shape above, including live `source_changed` for finalized). | `404` if other mode. |
| `POST /settlements/{id}/refresh` | Draft only: rebuild sources from live data. | `409 not_draft`. |
| `DELETE /settlements/{id}` | Never-finalized draft only, hard delete. | `409 not_draft`, `409 was_finalized` (draft that already has a number). Audited. |
| `POST /settlements/{id}/adjustments` `{kind,label,reason,basis?,amount}` | Add a line. `amount` is a **positive** number for `deduction`; signed for `adjustment`. On a finalized settlement only `kind=adjustment` is accepted and it supersedes the current acknowledgement. | `409 net_below_paid`, `409 direction_locked` (would flip sign while paid > 0). |
| `PUT /settlements/{id}/adjustments/{adj}` / `DELETE …` | Draft only. | `409 not_draft`. |
| `POST /settlements/{id}/finalize` | Draft → finalized: refresh, reconcile, freeze, assign number. | `409 reconciliation_mismatch` (`{figure}`), `409 nothing_to_settle`, `409 not_draft`. Audited. |
| `POST /settlements/{id}/reopen` | Finalized → draft (clears nothing but supersedes the current acknowledgement). | `409 has_payments`, `409 not_finalized`. Audited. |
| `POST /settlements/{id}/acknowledgement` multipart `{method, signer_name?, note?, signature?}` | Record acknowledgement; supersedes the previous one. | `422` per-method required fields; `409 not_finalized`. |
| `GET /settlements/{id}/acknowledgements/{ack}/signature` | Streams the PNG (private disk). | `403`/`404`; `Cache-Control: private, no-store`. |
| `POST /settlements/{id}/payments` multipart `{client_ref, amount, paid_at, method, reference?, notes?, proof?}` | Record a payment. Same `client_ref` + same settlement → `200` replay (no new row); `201` otherwise. | `422 amount` (names the max = balance), `409 not_finalized`, `409 already_settled`, `422` ref used on another settlement. |
| `DELETE /settlements/{id}/payments/{payment}` | Remove a payment (cache recomputed). | Audited. |
| `GET /settlements/{id}/payments/{payment}/proof` | Streams the proof (private disk). | `403`/`404`. |
| `GET /settlements/{id}/document?include_cost=0\|1` | Payload for the printable proof: store identity (name, logo URL, contact, address), seller, event, number, statement lines, reconciliation, acknowledgement block, `powered_by`. | Finalized only (`409 not_finalized`). Never contains customer names/phone/email. `include_cost=1` adds `memo.cost_of_goods`/`gross_profit`. |
| `GET /settlements/{id}/payments/{payment}/document` | Payload for the payment proof: settlement number, seller, amount, date, method, reference, recorder, balance **after** that payment, direction wording. | |

## Behavioural guarantees (each is a test)

1. `net_amount = Σ sources + Σ adjustments` exactly; `reconciliation.balanced` is true for every finalized settlement.
2. After `POST …/payments` with amount `a`: `balance_new = balance_old − a`; the sum of payments never exceeds `|net|` (also guarded by a DB CHECK).
3. A finalized settlement's `sources` and `revenue_total` never change when orders are later voided or pre-orders paid; `source_changed` reports the delta instead.
4. Concurrent `POST /settlements` for the same pair → exactly one succeeds (unique `active_slot`), the other gets `409 settlement_exists`.
5. Every mutating endpoint writes one `activity_logs` row **inside** the same transaction (`entity_type = SellerSettlement`, actions: `created`, `refreshed`, `deleted`, `adjustment_added|updated|removed`, `finalized`, `reopened`, `acknowledged`, `payment_added`, `payment_removed`).
6. Users without the `settlements` menu get `403` on every endpoint, including the two file streams and the document payloads.
7. A voided order and a cancelled pre-order contribute nothing; a pre-order with only rejected payments contributes nothing.
8. Parity: for the same event, `pos_revenue` equals `artist-profit.sales_pos`, and `preorder_revenue` equals `artist-profit.sales_preorder` within 1 cent per pre-order source row.
