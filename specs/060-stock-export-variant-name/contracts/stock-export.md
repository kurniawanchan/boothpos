**Classification: INTERNAL**

# Contract: `GET /api/v1/exports/stock`

The route, method, auth and permissions are unchanged. Only the sheet's columns change.

## Request

`GET /api/v1/exports/stock` with a bearer token. No query parameters are used for this entity.

## Response

`200` with an `.xlsx` file. One sheet named `stock`.

| Position | Header | Type | Notes |
|---|---|---|---|
| 1 | `sku` | text | unchanged |
| 2 | `variant_name` | text | **new**, may be empty, read-only |
| 3 | `current_stock` | integer | unchanged |
| 4 | `reason` | text | unchanged, empty on export |

Errors are unchanged: `403` without the master-data gate or the `export` action on the Stock menu, `404` for an unknown entity.

## Compatibility

- `POST /imports/master-data` accepts this file as-is. The `variant_name` column is ignored and no stored name changes.
- `GET /imports/master-data/template` is unchanged and does not list `variant_name`.
- Consumers that read the sheet by header name are unaffected. Consumers that read by column index must account for the new column 2 (ASSUMPTION: none exist in this repo; the importer reads by name).
