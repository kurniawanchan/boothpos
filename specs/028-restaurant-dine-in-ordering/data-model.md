# Data Model: Restaurant Dine-in Ordering Core (028)

All new business/transactional tables carry `data_mode` (`demo`/`live`) via the `HasDataMode` trait (CLAUDE.md, "Seed data and DEMO/LIVE mode"). Child/pivot tables that are only ever reached through a mode-stamped parent do not.
Migration filename prefixes continue from `2026_10_30_000001` and are FK-order-load-bearing: **do not rename or reorder** (CLAUDE.md).

## New tables

### `dining_tables` (HasDataMode)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(60) | e.g. "7", "A2" |
| area | varchar(60) NOT NULL default `''` | `''` = no area. Non-null so the unique key below works (MySQL treats NULLs as distinct). |
| seats | smallint unsigned default 2 | |
| display_order | smallint default 0 | |
| is_active | boolean default true | |
| data_mode | enum(demo, live) | |
| timestamps | | |

- UNIQUE `(data_mode, area, name)` → FR-001. The FormRequest `unique:` rule bypasses Eloquent scopes, so the rule must add `where data_mode = ModeGate::current()` explicitly (same trap CLAUDE.md documents for customers).
- No soft delete. Hard delete only if the table has **never** been referenced by an order (FK `orders.table_id` is `restrictOnDelete`; controller pre-checks and answers 409 "deactivate instead") → FR-002.
- "Occupied" is **derived**, never stored (an `is_occupied` flag would drift): a table is occupied iff an order with `status='open'` references it.

### `stations` (HasDataMode)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(60) | "Kitchen", "Bar" |
| display_order | smallint default 0 | |
| is_active | boolean default true | |
| data_mode | enum | |
| timestamps | | |

- UNIQUE `(data_mode, name)`.
- Delete guard: a station referenced by a category, a product, or any ticket → 409 (deactivate instead).

### `modifier_groups` (HasDataMode)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| name | varchar(100) | "Size", "Sugar" |
| selection_type | enum(single, multiple) | |
| is_required | boolean | |
| min_select | tinyint unsigned | single+required ⇒ 1; optional ⇒ 0 |
| max_select | tinyint unsigned NULL | NULL = no upper bound (multiple only); single ⇒ 1 |
| display_order | smallint | |
| is_active | boolean | |
| data_mode | enum | |
| timestamps | | |

Validation (service-level, FR-022): `min_select <= max_select`; `is_required ⇒ min_select >= 1`; `single ⇒ max_select = 1`; a group needs ≥1 active option to be attachable.

### `modifier_options` (HasDataMode)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| modifier_group_id | FK → modifier_groups (cascade on delete of group is **not** used; groups are deactivated, never deleted once used) | |
| name | varchar(100) | |
| price_delta | decimal(14,2) | signed: positive, zero or negative (FR-023) |
| display_order | smallint | |
| is_active | boolean | |
| data_mode | enum | |
| timestamps | | |

### Pivots (no `data_mode`; parents carry it)

- `modifier_group_product (modifier_group_id, product_id)` PK composite.
- `modifier_group_category (modifier_group_id, category_id)` PK composite.
- Effective groups for a product = its own groups ∪ its category's groups, de-duplicated, ordered by `display_order`.

### `kitchen_tickets` (HasDataMode)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| order_id | FK → orders (restrict) | The **tab** the ticket was sent from. Re-pointed on merge. Never re-pointed on split (see below). |
| station_id | FK → stations NULL | NULL = "no station" items (immediately ready, FR-030) |
| round_no | smallint | 1 for the first send of a tab, 2 for the next… (display only) |
| sent_by | FK → users | |
| sent_at | timestamp | |
| data_mode | enum | |
| timestamps | | |

Ticket completion is **derived** from its items (all `served`/`cancelled`), never stored.

### `kitchen_ticket_items` (HasDataMode)

| Column | Type | Notes |
|---|---|---|
| id | bigint PK | |
| kitchen_ticket_id | FK → kitchen_tickets (cascade) | |
| order_item_id | FK → order_items NULL, `nullOnDelete` | NULL after the line is cancelled/deleted; the snapshot below keeps the kitchen view intact |
| name_snapshot | varchar(255) | |
| qty | int (CHECK > 0) | |
| modifiers | json NULL | snapshot, same shape as `order_items.modifiers` |
| note | varchar(255) NULL | |
| status | enum(new, preparing, ready, served, cancelled) | no-station items are created directly as `ready` |
| preparing_at / ready_at / served_at | timestamp NULL | with `*_by` user FKs: forward-only transitions occur at most once, so no separate history table is needed (FR-031) |
| preparing_by / ready_by / served_by | FK → users NULL | |
| cancelled_at / cancelled_by / cancel_reason | timestamp / FK / varchar(255) NULL | FR-034, FR-036 |
| data_mode | enum | |
| timestamps | | |

Indexes: `(kitchen_ticket_id)`, `(status)`, `(order_item_id)`.

## Changed tables

### `orders`

| Change | Detail |
|---|---|
| `status` | enum `('completed','voided')` → `('open','completed','voided')` via `ALTER TABLE … MODIFY` (MySQL-only, like the existing CHECK migrations). Default stays `completed`, so every existing code path that omits it is unchanged. |
| `service_type` | enum(`retail`,`dine_in`,`takeaway`) default `retail`. Existing rows and the one-step POS stay `retail`. |
| `table_id` | FK → dining_tables NULL, `restrictOnDelete`. |
| `tab_label` | varchar(100) NULL — takeaway name/number. |
| `split_from_order_id` | FK → orders NULL, `nullOnDelete` — set on a sale produced by a split bill, for traceability on receipts. |
| `open_table_key` | **generated** column `IF(status='open', table_id, NULL)` STORED + **UNIQUE** index. Gives FR-004 ("at most one open tab per table") a database-level guarantee that holds under concurrent opens; NULLs (completed/voided/takeaway) never collide. |

`order_number` (`TRX-YYYYMMDD-NNNN`) is assigned when the tab is **opened** and kept on completion (it is the "unique tab number" FR-005). A split-off sale gets its own new number through the existing `generateOrderNumber()` (which already counts across both data modes).
`session_id` / `event_id` stay NOT NULL: a tab is opened inside an open cashier shift (see research.md Decision 3).
`paid_amount` accumulates across partial payments; `change_amount` is only set on completion.

### `order_items`

| Change | Detail |
|---|---|
| `modifiers` | json NULL — snapshot `[{group, option, price_delta}]`. |
| `note` | varchar(255) NULL. |
| `sent_at` | timestamp NULL — NULL = **unsent** (editable); set by the send action (FR-029). |

`sell_price` is the **effective unit price** = variant base price + Σ chosen `price_delta` (snapshot). Keeping `line_total = sell_price × qty − discount_amount` true means every existing report formula that reads `sell_price`/`line_total` stays correct with no change. `cost_price` is unchanged (modifiers affect revenue, not cost — spec assumption).
Cancelled lines are **deleted** from `order_items` (their kitchen snapshot and the activity log keep the audit trail), so no report ever has to filter a "cancelled" line state. A line never has `qty = 0` (existing CHECK `qty > 0`).

### `payments`

| Change | Detail |
|---|---|
| `client_ref` | char(36) NULL, UNIQUE (existing column from the ledger migration — feature 028 adds nothing here) — idempotency key of one settle/split submission (FR-010). A repeated submission finds payments carrying the same `client_ref` for that order and returns the current result instead of recording again. |

### `product_variants`

| Change | Detail |
|---|---|
| `track_stock` | boolean default **true** (FR-020). Existing rows and behaviour unchanged. |

### `categories`, `products`

| Change | Detail |
|---|---|
| `station_id` | FK → stations NULL, `restrictOnDelete`. Precedence at send time: **product → category → none** (FR-028). Parent-category inheritance is intentionally not implemented. |

### `roles` (data migration, not schema)

Adds menu keys `floor`, `kitchen`, `dining_setup` to default roles — see research.md Decision 8 and `MenuKeys::ALL`.

## State machines

### Order (tab) — `orders.status`

```
            open_tab                      settle (paid ≥ total)
(none) ─────────────► open ───────────────────────────────► completed ──(existing void)──► voided
                       │  void_tab (no payments, reason, owner/admin)                         ▲
                       └──────────────────────────────────────────────────────────────────────┘
                       │  close_empty (no lines)  ► voided (no stock/sales effect; reason "kosong")
```

- `open → completed` only through settlement; `open → voided` only through tab-void or close-empty.
- `completed → voided` is the **unchanged** existing whole-sale void.
- Retail one-step sale continues to be created directly as `completed`.
- Split: the original stays `open` (remainder), a **new** `completed` order is created from the selected lines.

### Kitchen ticket item — `kitchen_ticket_items.status`

```
new ──► preparing ──► ready ──► served
 │          │           │         │
 └──────────┴───────────┴─────────┴──► cancelled   (reason required; before `preparing` any staff, from `preparing` on owner/admin only; stock restored only from `new`)
```

Forward-only (FR-031). `ready→served` may be done by `floor` **or** `kitchen` permission holders; `new→preparing→ready` need `kitchen`.

## Stock rules (FR-017–FR-021) — summary table

| Event | Stock-tracked variant | `track_stock = false` |
|---|---|---|
| Add line to tab | `sale` movement −qty (refused with 409 if insufficient, like the retail sale) | none |
| Increase qty of an unsent line | `sale` movement −Δ | none |
| Decrease qty / remove an unsent line | `return` movement +Δ | none |
| Cancel a sent line while ticket item is `new` | `return` movement +qty | none |
| Cancel a sent line from `preparing`/`ready`/`served` | **none** (consumed); reason + user in activity log | none |
| Void an open tab | `return` for lines that are unsent or `new`; none for the rest | none |
| Move / merge / split / settle | **no movement** (FR-016, FR-019) | none |
| Void a *completed* tab-sale | existing rule: `return` for all remaining lines | none |

All movements go through `StockService` only. Tab-line movements use `reference_type='order_item'` with `reference_id = order_items.id` (the same convention the existing `void()` return movements already use). Note: the existing retail `sale` movement stores the **order** id as `reference_id` — an inconsistency in the current code that this feature deliberately does not copy or "fix".

## Validation rules (server-side, from the spec)

- Open tab: shift must be `open` (422 as existing `session_not_open`); table active and free (409 if occupied → body names the existing tab id); table and customer looked up through scoped Eloquent (`findOrFail`) so a cross-mode id 404s (the `customer_id` trap in CLAUDE.md).
- Add/update line: variant active; modifier selection must satisfy every effective group (required / single|multiple / min / max) → 422 naming the group; effective unit price must be ≥ 0 → 422; qty ≥ 1; edits only on unsent lines (409 otherwise).
- Settle: Σ payments of this call + earlier `paid_amount` must not exceed total unless the excess is covered by cash received (existing change rule); completes when `paid_amount ≥ total`.
- Remove/reduce after partial payment must not push `total_amount` below `paid_amount` (409).
- Split: refused (409) if the tab has **any** recorded payment (no allocation logic — same product-owner stance as feature 027's pre-order split); selection must be non-empty and a strict subset or all lines; partial-unit selection only on unsent or `served` lines.
- Merge: same shift, both `open`, different tabs.
- Void tab: refused (409) if any payment recorded.
- Close shift: refused (409) while the shift holds an `open` tab; body lists them.
