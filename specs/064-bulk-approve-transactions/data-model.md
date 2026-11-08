# Data Model: Bulk Approve Transactions

**Classification**: CONFIDENTIAL (describes real table structures of the approval audit data)

## Schema change (one additive migration)

`database/migrations/2026_11_26_000001_add_bulk_ref_to_order_approvals.php` — dated AFTER the latest existing prefix
(`2026_11_25_000001`); migration date prefixes are load-bearing, do not reorder.

| Table | Column | Type | Notes |
|---|---|---|---|
| `order_approvals` | `bulk_ref` | `CHAR(36) NULL`, indexed (`idx_order_approvals_bulk_ref`) | UUID shared by every approval created by one bulk action. NULL = single approval (all existing rows, no backfill). Not unique. |

No other table or column changes. Existing constraints are untouched and still decisive:

- `uq_order_approvals_active (order_id, artist_id, active_slot)` — one active approval per seller per sale, also under
  concurrent bulk requests.
- `chk_order_approvals_image` — a stamp has no image.
- `chk_order_approvals_slot` — invalidated ⇔ no active slot.

`down()` drops the index then the column.

## Entities

### Seller Approval (existing, `order_approvals`)
Unchanged semantics. Bulk creates ordinary rows: `method` ∈ draw/upload/stamp, `approved_by`/`approver_name`, `on_behalf`,
`items_hash` + `items_snapshot` of THAT seller's lines at that moment, `approved_at`. Differences for bulk rows only:
`bulk_ref` is set, and `image_path`/`image_mime` may be the same file as sibling rows of the same batch.

### Bulk Approval Action (virtual — no table)
Identified by `bulk_ref`. Its facts live in the audit log row `order_bulk_approved` (`user_id`, description,
`new_values = {bulk_ref, method, selected, approved_orders, approved_sellers, skipped_total, skipped_by_reason}`) and in the
approvals that carry the ref. Never stored as editable state.

### Approval Plan (request/response structure, not stored)
`[{order_id, sellers:[{artist_id, items_hash}]}]` returned by preview and echoed by apply as JSON. A freshness reference only;
it can make an order be skipped, never make an approval succeed (same property as single approval's `items_hash`).

### Per-order result (response only)
`{order_id, order_number, status: approved|skipped, reason?, sellers_approved?}` — reasons listed in research D5.

## Derived values (no new storage)

- Requirements, per-seller state, overall status, "X/N" — `OrderApprovalSummary` (059), the only formula.
- Preview counts: `eligible_orders`, `approvals` (seller approvals that would be recorded), `amount` (Σ subtotals of the
  seller lines to be approved), per-seller breakdown — computed from the same summary rows; amounts are strings
  `number_format((float) $x, 2, '.', '')` like every money field.

## State transitions

Per (order, seller): `pending → approved` (existing, one-way). Bulk adds no new states and no new transitions. A stale
approval is invalidated by the existing `reconcileLocked()` in the same per-order transaction before the new row is written.

## Validation rules (server)

- `order_ids`: JSON array of distinct integers, 1…`approvals.bulk_max`.
- `method`: `draw|upload|stamp`.
- `image`: required for draw/upload, forbidden for stamp; PNG/JPEG by content; > 0 and ≤ 5120 KB. Checked BEFORE any order is touched, so a bad file records nothing (FR-007).
- `plan`: JSON array, each item `order_id` int, `sellers[]` of `{artist_id:int, items_hash:string}`; an `items_hash` that is not a string simply cannot match.
- Preview takes only `order_ids`.

## Clone LIVE → DEMO and backups

- `order_approvals` is already registered (`via orders.order_id`, files on `image_path`). `bulk_ref` is an opaque, non-unique
  string and can be copied as is (verify generically — research open item 2). Shared `image_path` across rows is handled
  by the cloner's per-row copy and the "delete old file only if no row in either mode references it" wipe rule.
- Approval images remain out of backups (known gap since 054/055/059).
