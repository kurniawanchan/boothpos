# Contract: Pre-order Form & Workflow Updates

Existing endpoints, extended — no new routes except none needed (courier
list is served from a frontend-bundled constant mirroring `Couriers`, not a
new API call, to avoid a network round trip just to render a 5-item
dropdown; see quickstart.md for how this is kept in sync during review).

## `POST /api/v1/preorders` (extended)

**New/changed request fields**:
```json
{
  "customer_id": 123,
  "fulfillment": "pickup",
  "discount": "15000.00",
  "pickup_day": "2026-10-13",
  "courier_name": null,
  "items": [{ "variant_id": 1, "qty": 3 }]
}
```
- `discount` (optional, numeric ≥ 0, default `0`): rejected with `422` if
  `discount > subtotal + shipping_cost` (`discount` field error).
- `pickup_day` (optional, date): only accepted when `fulfillment=pickup`.
  If present and `event_id` is set, must be within that event's date
  range (`422` otherwise, `pickup_day` field error). If present with no
  `event_id`, `422` (nothing to validate against — FR-008a). Ignored/`422`
  if sent alongside `fulfillment=courier`.
- `courier_name` (optional, string): only accepted when
  `fulfillment=courier`. Defaults to `"JNE"` server-side if omitted for
  that fulfillment. Must be one of `App\Support\Couriers::OPTIONS` (`422`
  otherwise). Ignored/`422` if sent alongside `fulfillment=pickup`.

**Response** (`201`): existing shape, `present()` now also includes
`discount`, `pickup_day`, `courier_name`.

## `GET /api/v1/preorders/{id}` and `GET /api/v1/preorders/{id}/invoice` (extended)

`present()`'s response gains:
```json
{
  "discount": "15000.00",
  "pickup_day": "2026-10-13",
  "courier_name": null
}
```
Both null/zero-valued for any pre-existing preorder created before this
feature (Edge Cases — no backfill needed, these are additive nullable
columns).

## `POST /api/v1/preorders/{preorderId}/shipment` (unchanged endpoint, unchanged validation)

No contract change — `courier_name` remains `required|string|max:50`.
Frontend pre-fills this field's (now dropdown) value from the parent
preorder's `courier_name` if present; the endpoint itself doesn't need to
know that happened.

## `GET /api/v1/preorders/export`, `GET /api/v1/preorders/import/template`, `POST /api/v1/preorders/import` (extended)

Template/export gain three trailing columns: `discount`, `pickup_day`,
`courier_name` (see data-model.md's Import/Export Row Shape table for
per-column rules). `POST /preorders/import`'s existing `409` row-errors
response gains the new possible messages:

```json
{
  "message": "Tidak ada data yang disimpan karena ditemukan kesalahan.",
  "row_errors": [
    { "row": 4, "errors": ["Baris 4: hari jemput tidak berlaku untuk fulfillment 'courier'."] },
    { "row": 7, "errors": ["Baris 7: kurir 'Wahana' tidak dikenal."] }
  ]
}
```

No change to the `201`/`200` (dry-run) success shape's existing keys
(`created_count`, `created_customer_count`, `preorder_ids`).
