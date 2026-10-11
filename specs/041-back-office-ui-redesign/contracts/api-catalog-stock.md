**Classification**: INTERNAL

# API contract — menu, recipes, stock (Phase 3)

Legend: **R** reason code required · **A** approval path.

## Menu items and availability

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /products` (existing) | list; gains filters `kind`, `status`, `station_id`, `available_in_outlet` | `pro.item.view` | variants stay opt-in via `?with_variants=1` (existing rule) |
| `POST/PUT /products` (existing) | item fields add `short_name` (≤24), `description`, `tax_category`, `open_price`, `station_id`, `prep_minutes`, `daily_quota` | `pro.item.update` | `open_price` needs `pro.price.open` |
| `GET/PUT /variants/{id}/outlets` | per-outlet `is_available`, `price_override`, `channels[]` | `pro.item.update` | effective price resolved server-side; override changes go through a price draft/release (Phase 8) when releases are enabled |
| `POST /variants/{id}/unavailable` · `DELETE …/unavailable` | mark/unmark "86" in an outlet | `pro.item.86` | **R** (category `unavailable`); quota reached sets it automatically for the business date |
| `GET/POST/PUT /combos` | combos with choice groups, min/max, upgrade prices | `pro.item.update` | sells as one line; cost resolved through chosen items |
| `POST /price-drafts` · `GET /price-drafts` | bulk draft: percent or nominal, rounding to Rp 500, per outlet | `pro.price.update` | not effective until released; `GET` returns the unreleased count shown on the page |
| `POST /price-drafts/{id}/approve` | approve a draft | `pro.price.approve` | **A** |

## Modifiers (existing)

`GET/POST/PUT /modifier-groups`, `PUT /modifier-groups/{id}/attachments` unchanged; responses add `linked_items_count` and price range for the table.

## Ingredients, semi-finished, packaging, retail

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET/POST/PUT /materials` (existing) | adds `kind`, `category`, `storage_type`, `purchase_unit`, `purchase_factor`, `factor_is_approximate`, `track_batch`, `shelf_life_days`, `barcode`, `preferred_supplier_id` | `inv.item.manage` | base unit never changes once movements exist (`409`) |
| `GET /materials/{id}/stock-card` | movements per location with running balance | `inv.stock.view` | paginated |

## Recipes

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /variants/{id}/recipe` | current recipe + cost/margin | `pro.recipe.view` | cost from current average cost; **never written to `cost_price` implicitly** except by the existing "complete BOM" rule |
| `POST /variants/{id}/recipe` | new version (`yield`, `effective_from`, lines, `change_note`) | `pro.recipe.update` | old versions immutable; lines reference materials only |
| `GET /variants/{id}/recipe/versions` | history | `pro.recipe.view` | |

(The existing `/variants/{variant}/bom` and `/cost-breakdown` endpoints remain and read the current recipe version.)

## Stock by location

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `GET /stock-locations` · `POST/PUT` | locations per outlet | `inv.location.manage` | one default per outlet |
| `GET /stock/balances?location_id=&status=` | on-hand, reserved, available, reorder point, value | `inv.stock.view` | `status` = below_reorder, expiring, negative |
| `GET /stock/suggestions` | reorder suggestions (2 × reorder point − available) | `inv.stock.view` | basis for "create PR from suggestions" |
| `GET /stock/batches?expiring_within=3` | batches near expiry | `inv.stock.view` | FEFO order |
| `GET /stock/movements` (existing) | + `location_id`, `document_type/id` | `inv.stock.view` | append-only |
| `POST /stock/adjustments` (existing) | now takes `location_id` | `inv.adjust` | stays the single write path (`StockService::applyMovement`) |

## Stock counts

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /stock-counts` | schedule (`type`, `location_id`, `scheduled_for`, `blind`, `category_ids`) | `inv.count.create` | |
| `GET /stock-counts/{id}` | detail; **system quantities omitted while `blind` and not submitted** | `inv.count.view` | |
| `PUT /stock-counts/{id}/lines` | submit counted quantities | `inv.count.enter` | counter ≠ approver |
| `POST /stock-counts/{id}/submit` | compute variances vs tolerance | `inv.count.enter` | status → `submitted` |
| `POST /stock-counts/{id}/approve` · `/recount` | approve & post, or request recount | `inv.count.approve` | **A**, `409 SELF_APPROVAL`; recount needs **R** |

## Transfers

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /transfers` · `POST /transfers/{id}/send` | create and send | `inv.transfer.create` | stock moves to in-transit on send |
| `POST /transfers/{id}/receive` | receive (`qty_received` per line) | `inv.transfer.receive` | difference becomes a movement with **R** reason |

## Wastage

| Method & path | Purpose | Perm | Notes |
|---|---|---|---|
| `POST /wastage` | record (`location_id`, lines, `reason_code_id`, optional photo) | `inv.wastage.create` | **R**; above the configured value → `pending_approval` |
| `POST /wastage/{id}/approve` · `/reject` | decide | `inv.wastage.approve` | **A**; stock and valuation change only on approval |

## Side effects on sales

Completing an order containing an item with a recipe posts `consumption` movements per location (server-side, same transaction as the sale). Items without a recipe post none and are flagged "no recipe" in the item list.
