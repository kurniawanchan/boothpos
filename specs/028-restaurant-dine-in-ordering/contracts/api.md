# API Contract: Restaurant Dine-in Ordering Core (028)

Base: `/api/v1` (Sanctum bearer, same as every existing route). Every route is also behind the global licence gate (`423` until activated). Conventions are inherited unchanged from CLAUDE.md "API conventions":

- `422` shape/validation (FormRequest rules, modifier-rule violations), `409` business-rule conflict (**including insufficient stock and payment shortfall** — the existing controllers catch the service's `ValidationException` and answer 409; `OrderTest` asserts this for stock), `403` role denial, `404` unknown/cross-mode id.
- Money is always a **string** `"12500.00"`; the server computes every price/total, the client never sends one.
- Pagination envelope `{data, meta:{current_page, per_page, total, last_page}}` where a list is paged; floor/station/tab lists are **not** paged (bounded by physical size).
- `docs/openapi-pos-mvp.yaml` is updated in the same commit as each route group (Constitution, Documentation & Change Discipline).

Permission column = menu key the **server** requires (`User::canAccessMenu`). `owner/admin` = `isOwnerOrAdmin()` (the `settings` key), the same gate `OrderController::void()` already uses.

## 1. Dining setup (`dining_setup`)

| Method & path | Purpose | Notes |
|---|---|---|
| `GET /dining-tables` | list (all, incl. inactive, `?active=1` to filter) | |
| `POST /dining-tables` | create `{name, area?, seats?, display_order?}` | 422 duplicate `(area,name)` in active mode |
| `PUT /dining-tables/{id}` | edit / activate / deactivate `{…, is_active}` | deactivating a table with an open tab → 409 |
| `DELETE /dining-tables/{id}` | hard delete | 409 if ever referenced by an order ("deactivate instead") |
| `GET/POST/PUT/DELETE /stations[/{id}]` | stations CRUD | delete 409 if referenced (category/product/ticket) |
| `GET /modifier-groups` | groups **with** options and attachments | |
| `POST /modifier-groups` | create `{name, selection_type, is_required, min_select, max_select, options:[{name, price_delta}] }` | 422 on rule violations (min>max, single≠max 1…) |
| `PUT /modifier-groups/{id}` | edit group; `options` is a full replacement **by id** (`id` present = update, absent = create, omitted = **deactivate**, never delete) | past orders unaffected (snapshots) |
| `PUT /modifier-groups/{id}/attachments` | `{product_ids:[], category_ids:[]}` replace | ids looked up through scoped Eloquent |
| `DELETE /modifier-groups/{id}` | | 409 once any order line ever used one of its options (they are snapshots, so this is judged by `order_items.modifiers` containing the group id) — otherwise allowed; else deactivate |
| `PUT /categories/{id}`, `PUT /products/{id}` | **extended**: accept `station_id` (nullable) | existing requests gain one rule |
| `PUT /variants/{id}`, `POST /products/{id}/variants` | **extended**: accept `track_stock` (boolean) | |

Menu read for ordering (used by the tab editor): the existing `GET /products?with_variants=1` is **extended** to include, per product, `modifier_groups:[{id,name,selection_type,is_required,min_select,max_select,options:[{id,name,price_delta}]}]` **only when `?with_modifiers=1`** (opt-in, per the "list stays lean" rule) — active groups/options only — plus `track_stock` on variants and `station_id`.

## 2. Floor (`floor`)

`GET /floor` → `200`

```json
{
  "areas": [
    {
      "area": "Terrace",
      "tables": [
        {
          "id": 7, "name": "7", "seats": 4, "is_active": true,
          "state": "occupied",
          "tab": { "id": 311, "order_number": "TRX-20261003-0012", "total_amount": "250000.00",
                   "opened_at": "2026-10-03T12:05:00+07:00", "session_id": 4,
                   "ready_count": 2, "unsent_count": 1 }
        },
        { "id": 8, "name": "8", "seats": 2, "is_active": true, "state": "free", "tab": null }
      ]
    }
  ],
  "takeaway_tabs": [ { "id": 315, "order_number": "TRX-20261003-0016", "tab_label": "Budi", "total_amount": "45000.00", "ready_count": 0, "unsent_count": 2 } ],
  "version": "a3f9…"
}
```

- Built with **one** aggregate query for open orders (+ one grouped subquery counting `ready` ticket items per order) — no per-table queries (Constitution V).
- `?version=<token>` → when nothing changed: `200 {"unchanged": true, "version": "<same>"}` (the 3-second poll's small answer, research.md Decision 6). The token is a **hash of the response body** (not derived from `updated_at`, so two changes inside one second still differ); time-varying fields are deliberately absent from the floor payload so the token is stable when nothing changed.

## 3. Tabs (`floor`)

| Method & path | Body | Result / errors |
|---|---|---|
| `GET /tabs?session_id=&table_id=` | | open tabs (lean rows) |
| `POST /tabs` | `{session_id, table_id?, tab_label?, customer_id?, local_ref?}` | `201` tab. `409` table occupied (`{existing_tab_id}`, which may be `null` if the winner has not committed yet) / table inactive / shift not open (business-rule conflicts → 409, as the retail sale) / `local_ref` belongs to a retail sale; `422` unknown customer; `404` table or shift of the other data mode. `local_ref` makes a retried open return the first tab. |
| `GET /tabs/{id}` | | `200` full tab (below). `404` if the order is not `open` **or** `completed` tab (voided/retail orders use `GET /orders/{id}`). |
| `POST /tabs/{id}/items` | `{variant_id, qty, modifier_option_ids?:[], note?}` | `201` tab. `422` modifier rule (names the group + range) / negative unit price. `409` insufficient stock (as the retail sale does) / inactive variant. Identical **unsent** line (same variant, option set, note) is merged by increasing its qty. |
| `PATCH /tabs/{id}/items/{item}` | `{qty?, note?, modifier_option_ids?}` | `409` if the line is already sent. qty change re-balances stock by the delta. |
| `DELETE /tabs/{id}/items/{item}` | `{reason?}` | Unsent: `200`, reason optional. Sent & ticket item `new`: any `floor` user, reason **required**. Sent & `preparing`/`ready`/`served`: **owner/admin only** (`403` otherwise), reason required, written to the activity log. `409` if the line belongs to a completed order, or if removal would drop `total_amount` below `paid_amount`. |
| `POST /tabs/{id}/send` | `{}` | `200 {tickets:[…]}`; `409` nothing unsent. Creates one ticket per station (+ one "no station" ticket, items born `ready`), stamps `order_items.sent_at`. Atomic. |
| `POST /tabs/{id}/move` | `{table_id}` | `409` target occupied (`{existing_tab_id}`) / inactive; table-less takeaway tab can be moved **onto** a table too. |
| `POST /tabs/{id}/merge` | `{source_tab_id}` | `409` different shift / either not `open` / same tab. Lines, tickets and ticket items move to `{id}`; source becomes `voided` with reason "digabung"; no stock movement. |
| `POST /tabs/{id}/handover` | `{session_id}` | owner/admin. Target shift must be `open`. `409` otherwise. |
| `POST /tabs/{id}/payments` | `{payments:[{method, channel_id?, amount, proof_token?, notes?}], discount_amount?, client_ref}` | Records payments with the existing `PaymentRecorder` rules. If `paid_amount ≥ total` → status `completed`, `change_amount` computed (422 `change_exceeds_cash_received` as existing) and the table frees; otherwise stays `open` with `balance`. A repeated `client_ref` → `200` with the current state, nothing recorded again. `409` if the tab is not `open`. |
| `POST /tabs/{id}/split` | `{items:[{item_id, qty}], payments:[…], discount_amount?, client_ref}` | `201 {paid_order, tab}`: a **new** `completed` order built from the selection (re-parenting whole lines, shrinking + cloning partially-selected lines), the remainder stays on the open tab. `409` if the tab has any payment, or partial-unit selection on a line that is sent but not yet `served`. When the remainder is empty the tab is completed/voided as "fully paid by splits" and the table frees. |
| `POST /tabs/{id}/void` | `{reason}` | owner/admin. `409` if any payment recorded or not `open`. |
| `DELETE /tabs/{id}` | | close an **empty** tab (no lines) → `204`; `409` if it has lines. |

**Tab payload** (`GET /tabs/{id}`, and returned by every mutation)

```json
{
  "id": 311, "order_number": "TRX-20261003-0012", "status": "open",
  "service_type": "dine_in", "table": {"id":7,"name":"7","area":"Terrace"}, "tab_label": null,
  "session_id": 4, "customer_id": null,
  "subtotal": "250000.00", "discount_amount": "0.00", "total_amount": "250000.00",
  "paid_amount": "0.00", "balance": "250000.00",
  "lines": [
    { "id": 901, "variant_id": 12, "name": "Es Kopi Susu — Regular", "qty": 2,
      "unit_price": "28000.00", "line_total": "56000.00",
      "modifiers": [{"group":"Sugar","option":"Less","price_delta":"0.00"},{"group":"Add-ons","option":"Extra shot","price_delta":"8000.00"}],
      "note": "tanpa es", "sent": true,
      "prep_status": "preparing", "station": {"id":2,"name":"Bar"} }
  ],
  "payments": []
}
```

`prep_status` is `null` for unsent lines. `unit_price` is `order_items.sell_price` (effective price). The tab payload carries **no cost fields** (cashier-safe by construction, like `GET /orders/{id}`).

## 4. Kitchen / stations (`kitchen`, plus `floor` for `served`)

| Method & path | Notes |
|---|---|
| `GET /stations/{id}/tickets?version=` | The station's tickets that still have unfinished items (`new`/`preparing`/`ready`) **plus** items finished or cancelled in the last 30 minutes ("recently completed", FR-032/US4-10). Oldest first. Same `version`/`unchanged` short-circuit as the floor. Needs `kitchen`. |
| `GET /tickets/{id}` | One ticket for the printable browser view. Needs `kitchen` or `floor`. |
| `PATCH /ticket-items/{id}/status` | `{status}`. `new→preparing→ready` need `kitchen`; `ready→served` need `floor` **or** `kitchen`. Any other transition → `409`. `cancelled` is **not** settable here — cancellation goes through `DELETE /tabs/{id}/items/{item}` so stock/permission rules live in one place. |

Ticket payload per item: `{id, name, qty, modifiers, note, status, preparing_at, ready_at, served_at, cancelled_at, cancel_reason, order:{id, order_number}, table:{id,name}|null, tab_label, waiting_seconds}`.

## 5. Changed existing endpoints

| Endpoint | Change |
|---|---|
| `POST /sessions/{id}/close` | `409 {message, open_tabs:[{id, order_number, table}]}` while the shift has an `open` tab. Expected-cash arithmetic unchanged; the existing `status != 'voided'` filter becomes explicit `status = 'completed'` (see research.md Decision 5). |
| `POST /orders/{id}/void` | Unchanged for `completed`; an `open` order is refused with `409` ("gunakan batalkan tab"). |
| `GET /orders/{id}`, `GET /orders/{id}/receipt` | Lines gain `modifiers`, `note`; order gains `service_type`, `table`, `tab_label`, `split_from_order_number` (all `whenLoaded`/null-safe, cashier-safe). |
| `GET /orders`, `GET /reports/*`, dashboard, customer history | Only `completed` orders are counted; `open` tabs never appear (FR-042). Exact query list: research.md Decision 5. |
| `GET /settings/features` | Adds nothing; menu visibility comes from the role's `menu_keys`. |
| `GET /menu-keys` | Returns the three new keys (`floor`, `kitchen`, `dining_setup`) so the role editor can grant them. |
