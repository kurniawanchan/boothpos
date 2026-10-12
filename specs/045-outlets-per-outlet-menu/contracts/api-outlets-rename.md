**Classification**: INTERNAL

# Contract: Renames (Stage 1) — old → new

No alias is kept (D3): the SPA and API ship together. `docs/openapi-pos-mvp.yaml` changes in the same commit.

## Routes

| Old | New | Notes |
|---|---|---|
| `GET/POST /artists`, `GET/PUT/DELETE /artists/{artist}` | `/outlets`, `/outlets/{outlet}` | same operations, validation, pagination envelope; delete refused (409) while products, orders, shifts, tables, users or stock reference it; licence limit on create |
| `GET /exports/artists` | `GET /exports/outlets` | entity name |
| `POST /imports/master-data` sheet `artists` | sheet `outlets` (**old name still accepted**) | alias table in `MasterDataSheets` |
| `GET /reports/stock-by-artist` | `/reports/stock-by-outlet` | |
| `GET /reports/artist-profit` | `/reports/outlet-profit` | grouped by the **report outlet** (Stage 7) |
| `GET /reports/artist-settlements` | `/reports/outlet-settlements` | |
| `GET /reports/artist-settlements/{artist}/transactions` | `/reports/outlet-settlements/{outlet}/transactions` | |
| report export entity `artist-settlements`, `artist-profit`, `stock-by-artist` | `outlet-settlements`, `outlet-profit`, `stock-by-outlet` | |

## Query parameters and fields

| Old | New | Where |
|---|---|---|
| `artist_id[]` (products, POS, stock lists) | `home_outlet_id[]` | filters by the item's **home** outlet |
| `artist_id[]` (sales, reports) | `outlet_id[]` | filters by the **selling** outlet (Stage 2/7) |
| `artist_id` (product body/response) | `home_outlet_id` | |
| `artist_name`, `artist_names` (rows) | `outlet_name`, `outlet_names` | |
| `artist_code` (Excel/products) | `home_outlet_code` (old accepted on import) | |
| `purchase_orders.artist_id` | `outlet_id` | request and response |

## Permissions and settings

- Role menu key `artists` → `outlets` (migration rewrites `roles.menu_keys`).
- `multi_artist_enabled` → `multi_outlet_enabled` (read falls back to the old key for one release); `GET /settings/features` exposes `multi_outlet_enabled`.

## Page routes (SPA)

`/artists` → redirect to `/outlets`; route names `artists` → `outlets`; nav label `nav.outlets` ("Outlets" / "Outlet").

## Guarantees

Same ids, codes, totals and history (`outlets:reconcile` passes); response shapes unchanged apart from renamed keys; 422/409/403 conventions unchanged.
