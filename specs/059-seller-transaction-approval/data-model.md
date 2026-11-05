# Data Model: Transaction Approval & Seller Audit

**Classification**: INTERNAL

## New table `order_approvals`

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `order_id` | FK → `orders` | cascade on delete (same as `order_reference_images`); no application path deletes orders except the demo wipe |
| `artist_id` | FK → `artists` | restrict; the seller approved for |
| `artist_name` | varchar | snapshot (the seller may be renamed/soft-deleted later) |
| `method` | enum `draw`,`upload`,`stamp` | |
| `image_path` | varchar null | private `local` disk, `order-approvals/<uuid>.<png|jpg>`; NULL iff `stamp` |
| `image_mime` | varchar(32) null | `image/png`/`image/jpeg`, sniffed from content |
| `items_hash` | char(67) | `v1:` + SHA-256 hex of the seller's canonical lines |
| `items_snapshot` | json | readable list of what was approved: `[{sku, name, qty, sell_price, discount_amount, line_total}]` + `subtotal` |
| `approved_by` | FK → `users` | the approver (restrict) |
| `approver_name` | varchar | snapshot |
| `on_behalf` | boolean | approver is not the seller's linked user (owner/admin approving for a seller) |
| `approved_at` | timestamp | server time |
| `invalidated_at` | timestamp null | set by `reconcile()` |
| `invalidation_reason` | varchar(40) null | `items_changed` (extensible) |
| `active_slot` | unsigned tinyint null default 1 | NULL once invalidated |
| `created_at` / `updated_at` | | |

Indexes / constraints:
- `UNIQUE (order_id, artist_id, active_slot)` — at most one active approval per seller per sale (NULLs are distinct in MySQL, so any number of invalidated rows).
- `INDEX (order_id)`; `INDEX (artist_id)`.
- `CHECK (method <> 'stamp' OR image_path IS NULL)` via `DB::statement` (MySQL 8 only). The inverse (draw/upload must have an image) is enforced by the service, not the DB: the demo clone copies files and a missing source file becomes NULL, which a strict CHECK would turn into a failed clone.
- `CHECK ((invalidated_at IS NULL) = (active_slot IS NOT NULL))`.

No `data_mode` column: the row is reached only through a mode-scoped `{order}` (route binding) and rides it in `CloneRegistry` (`via orders.order_id`). Nothing is ever UPDATED except `invalidated_at` / `invalidation_reason` / `active_slot` by `OrderApprovalService::reconcile()`.

## Derived (not stored)

`OrderApprovalSummary::for(Order $order)` → 

```
requirements[]  one per distinct order_items.artist_id (current lines)
  artist_id, artist_name, items[], items_hash (current), subtotal
  state: approved | pending
  approval: the valid OrderApproval (active AND items_hash == current) or null
  stale: bool   — an active row exists but its hash no longer matches
overall: pending | partial | full | voided
approved, total, pending   (counts of requirements)
```

Rules:
- requirement set = unique sellers of the **current** lines (items of one seller → one requirement; FR-001).
- seller `approved` iff an active row exists with `items_hash` = current hash.
- overall: `voided` if `orders.status = 'voided'`; else `full` if approved = total (total ≥ 1); `pending` if approved = 0; else `partial`. An order with zero lines is not expected; treated as `pending` with total 0.
- legacy sales (no rows) → `pending`, `0/N`.

## State transitions (per seller, per sale)

```
(no row) ──approve──▶ ACTIVE (approved)
ACTIVE ──lines of that seller change──▶ derived "stale/pending"
stale ──reconcile() (next approve or explicit call)──▶ INVALIDATED (history, slot freed)
pending / stale ──approve──▶ new ACTIVE row (new cycle)
voided sale: no transitions; rows kept
```

No transition removes or edits an approval's content.

## Canonical hash (`v1`)

For one seller: take that seller's `order_items`, map to `[sku_snapshot, name_snapshot, (string)qty, sell_price (2dp), discount_amount (2dp), line_total (2dp)]`, sort the tuples lexicographically, JSON-encode, SHA-256, prefix `v1:`. Excludes ids on purpose (the LIVE→DEMO clone remaps them).

## Relationships

- `Order hasMany OrderApproval` (`approvals()`), `OrderApproval belongsTo Order|Artist|User(approver)`.
- Existing: `Order hasMany OrderItem`; `OrderItem belongsTo Artist`; `User.artist_id` → seller link (058).

## Registry / config touched

- `MenuActions::REGISTRY['sales']['named'][] = 'approve'` (+ label in `lang/*/permissions.php`).
- `CloneRegistry::tables()` += `order_approvals` (after `order_items`, before nothing that references it).
- `config`: none (limits are constants on the service: `MAX_IMAGE_KILOBYTES = 5120`).
