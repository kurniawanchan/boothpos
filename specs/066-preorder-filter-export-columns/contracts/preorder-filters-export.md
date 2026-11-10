**Classification: INTERNAL**

# Contract: Pre-order list filters and export

No new routes. Changes are to query parameters and the export file layout. `docs/openapi-pos-mvp.yaml` moves in the same commit.

## Shared list filters

Honoured identically by: `GET /preorders`, `GET /preorders/summary`, `GET /preorders/matching-ids`,
`GET /preorders/{preorder}/neighbors`, the bulk `preview`/`apply` filter echo, and `GET /preorders/export`.

| Param | Type | Notes |
|---|---|---|
| `customer_id` | integer **or** array (`customer_id[]=1&customer_id[]=2`) | **Changed**: now array-capable. Scalar still accepted. Values OR-ed. |
| `category_id` | array of integer (`category_id[]=…`) | **New**. Pre-orders with ≥1 item whose product (current category, soft-deleted included) is in the set. Each pre-order once, returned whole. |
| `artist_id` | array of integer | Existing on the list; **now also honoured by the export**. |
| `search` | string | Existing list behaviour (PO number, customer name, item name/SKU, outstanding digits). **Now also the export's behaviour** (was customer name only). |
| `status[]`, `dispatch_status[]`, `fulfillment[]`, `event_id` | unchanged | |

Combination: AND across params, OR within one. Empty values are ignored. Unknown/foreign ids (other mode, deleted) yield an empty
result, never an error and never data from another mode.

Status codes unchanged (`200`; `403` role/ability; `422` malformed input — an id that is not an integer).

## `GET /preorders/export`

Owner/admin + `preorders.export` action (unchanged). Accepts the shared filters above plus `date_from`, `date_to`
(`created_at` date range; export only, as before). Returns `preorders.xlsx`, **one row per pre-order**, same rows and order
(`created_at desc`) as before for the same filters.

New export-only columns (read-only; ignored by import; absent from the import template):

| Column | Value |
|---|---|
| `preorder_number` | PO number as shown in the list (first column) |
| `sellers` | distinct seller names of the items, first-appearance order, joined with ` \| ` |
| `status` | raw status value |
| `total_amount` | grand total, 2 decimals, `.` separator |

Full header order: see data-model.md. `created_at`/`updated_at` remain the last two columns.

## Frontend contract (SPA)

- `PreordersView` keeps `customerFilter` and `categoryFilter` as arrays of ids; `[]` = all. Changing either calls the existing
  `applyFilter()` with `customer_id`/`category_id` (arrays, `undefined` when empty) and resets to page 1; the bulk selection is
  cleared like for any other filter.
- Export sends the same reactive `params`, so no separate export wiring.
- Category options: `GET /categories` (existing). Customer options: `GET /customers?search=&per_page=25` (existing), label = name only.
