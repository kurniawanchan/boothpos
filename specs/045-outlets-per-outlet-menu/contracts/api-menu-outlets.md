**Classification**: INTERNAL

# Contract: Per-outlet menu (Stages 3–4)

Existing routes keep their shape; outlet data is **additive**. With one visible outlet every outlet field is omitted/ignored (single-outlet installs unchanged).

## Product catalogue (`GET /products`, `GET /products/{id}`)

- New per-row `outlets: [{ outlet_id, code, carried, state: "available"|"sold_out"|"not_carried", reason?, until? }]` (visible outlets only, in Outlets-page order); `GET /products?outlet_id[]=…` returns items **carried** at those outlets and sets `availability` for them.
- `GET /products/summary?outlet_id[]=…` — figures for the selected outlet(s) (86 today counts that outlet's marks).
- Detail: `availability[]` has one row **per visible outlet** (state, reason, who, until); `variants[].outlet_prices: [{outlet_id, price|null}]` (null = follows base).
- `home_outlet_id`, `home_outlet {id,code,name}` on every item.

## Create/update an item (`POST /products`, `PUT /products/{id}`, `PUT /products/{id}/variants`)

```json
{ "home_outlet_id": 1,
  "outlets": [ {"outlet_id":1,"carried":true}, {"outlet_id":2,"carried":false} ],
  "variants": [ {"variant_name":"Hot","sell_price":"25000","outlet_prices":[{"outlet_id":2,"price":"27000"}]} ] }
```
All-or-nothing; `outlets` omitted ⇒ unchanged (create ⇒ all carried). Outlet prices go through `PriceService` (history row with `outlet_id`; approval rule compares an outlet price with the **base** price; release mode creates a `menu_change` carrying `outlet_id`). An outlet outside the permitted set ⇒ `403`.

## 86 and discontinue

| Route | Change |
|---|---|
| `POST /products/{id}/sold-out` | body `outlet_ids[]` (required when several visible; default the selected outlet); one mark per outlet |
| `DELETE /products/{id}/sold-out` | `?outlet_ids[]=` undo only the chosen outlets |
| `POST /products/{id}/discontinue` / `reactivate` | unchanged — item-wide |
| `GET /menu/availability` | scoped to the context's outlet; response includes `outlet_id`; `sold_out[]` lists that outlet's marks and not-carried product ids |

## Bulk price (`POST /price-changes[/preview]`)

Body gains `scope: "base"|"all_outlets"|"outlets"` and `outlet_ids[]`; preview rows are per (variant, outlet); apply is all-or-nothing; history rows carry `outlet_id`.

## Price and orderability resolution (Stage 4)

- `PriceService::priceFor(variant, outletId)` and `MenuAvailability::orderable(...)` are the only resolvers (orders, tabs, POS list/cards, 86 poll).
- `POST /orders`, `POST /tabs/{id}/items`: outlet = the shift's; an item not carried/sold out at that outlet ⇒ `409 {code:"item_unavailable_at_outlet"}`; the line price = outlet price else base (recorded as a snapshot).
- `GET /products?with_variants=1` (POS) returns, for the context's single outlet, `sell_price` already resolved and only carried items.

## Excel

New optional sheets `outlet_items` (`product`/`sku`, `outlet_code`, `carried`) and `outlet_prices` (`sku`, `outlet_code`, `price`); unknown codes are per-row errors; all-or-nothing as today.

## Errors

`409 item_unavailable_at_outlet`, `403 outlet_forbidden`, `422` per-field for outlet prices (negative/non-numeric).
