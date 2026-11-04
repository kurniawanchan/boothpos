# Contract: Sales list row (additive)

**Classification**: INTERNAL

`GET /api/v1/reports/sales` → `transactions[]` (same rows feed `POST /reports/sales/transactions/export`, which maps explicit columns and ignores new fields).

## Added field

```json
{ "payment_proof_count": 2 }
```

- integer ≥ 0, always present on order rows;
- computed for the **requesting user** (permission-aware);
- never includes proof ids, URLs or image data.

## Unchanged, relied upon

- `has_reference`, `payment_state`, `payment_methods`, `total_amount`, `margin_*` (owner/admin only).

## Reused endpoints (no change)

- `GET /orders/{id}` — payments with `proof_id`, `has_proof`, `can_view_proof` (server-computed).
- `GET /payment-proofs/{id}/file` — authorized private file (blob).

## Status codes

Unchanged (`403` on a proof the user may not open; the UI never offers one).

## OpenAPI

Add `payment_proof_count` (integer, minimum 0) to the Sales transaction row schema in `docs/openapi-pos-mvp.yaml` in the same commit.

## Addendum — `POST /reports/sales/transactions/export` (US8/US9)

Request: existing `event_id`, `date_from`, `date_to`, `include_voided`, `keys[]` (`order:N` only) **+ optional `sellers[]`** (seller names; when present the units/gross/net columns carry only those sellers' items).
Response 200: `.xlsx`, one row per sale; columns in list order (Total before Margin; cost/margin only for owner/admin), plus verification and Sale Reference note; embedded images for the reference and for payment proofs the caller may view; unembeddable image ⇒ cell text. 422 with the limit message when the embedded-image cap is exceeded. No phone/email. `GET /reports/sales` rows are unchanged by US7–US9.
