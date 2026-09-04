# Phase 1 Data Model: UI/UX Refinements Batch

No new tables and no migrations. All FK columns this feature relies on
already exist (`orders.customer_id`, `orders.event_id`, `preorders.customer_id`,
`preorders.event_id`). Changes are additive Eloquent relations plus
read-shaping on existing entities.

## Customer (`app/Models/Customer.php`)

**Existing fields** (unchanged): `id`, `name`, `phone`, `email`, `social_handle`,
`notes`, timestamps, `SoftDeletes`, `HasDataMode`.

**New relations**:
- `orders(): HasMany` → `Order::class` via `customer_id`.
- `preorders(): HasMany` → `Preorder::class` via `customer_id`.

**New derived capability**: "has transactions" = `$customer->orders()->exists() || $customer->preorders()->exists()`, evaluated **without** any status/soft-delete filter (spec Edge Cases: any historical reference blocks delete, including non-final preorder statuses and soft-deleted rows — use `withTrashed()` on the child query where applicable so a soft-deleted order still counts).

**Delete rule**: soft-delete only when the above is false; otherwise `409` (mirrors `Artist`/`Category`).

**PII note** (carries forward the existing model comment, L10-14): the new transaction-history endpoint returns `customer_id`-scoped data to an already-identified customer record; it must not leak `phone`/`email`/`social_handle` into any artist-facing or exported surface — this feature only adds an internal, role-gated read view, consistent with existing exposure.

## Event (`app/Models/Event.php`)

**Existing fields** (unchanged): existing `orders(): HasMany` (L43-46), `cashierSessions()`, `settlements()`.

**New relation**:
- `preorders(): HasMany` → `Preorder::class` via `event_id`.

**New derived capability**: "has transactions" = `$event->orders()->exists() || $event->preorders()->exists()` (same any-status/any-trashed semantics as Customer).

**Delete rule**: soft-delete only when the above is false; otherwise `409`.

## Order / Preorder

No changes. Read from (via the new relations above) to power:
- delete-guard existence checks (Event, Customer),
- the customer transaction-history list (id, type, number, status, total, date — a `present()`-style projection, not a new Resource class, matching `PreorderController`'s existing hand-rolled style),
- the Sales page's `TransactionItemsModal` (via existing single-order detail fetch, not a new shape),
- the Dashboard's `group_by=customer` aggregate (existing `sales()` grouping mechanism, one more branch).

## Locale content (not schema, but data this feature edits)

- `resources/js/locales/id.json` / `en.json`: value-only edits for every key whose value is "Artist"/"Artists"; **new** key `nav.logout` (or `common.logout`) with values `"Keluar"` / `"Log out"`; **removed** keys `settings.data_backup`, `settings.run_from_server_console`, `settings.backup_command_note`, `settings.backup_files_note` (after confirming no other reference).
- `lang/id/*.php`, `lang/en/*.php`: matching value-only edits for backend-emitted strings (validation/error messages, conflict-response text) that currently say "Artist"/"Artists".

## State / status vocabulary referenced (unchanged, for context)

- `preorders.status`: `ordered | arrived | handed_over | cancelled` (existing lifecycle, unaffected by this feature) — surfaced as-is in the transaction-history list's status label.
- `orders` have no status column beyond being a completed sale record (existing convention) — surfaced in history simply as a completed transaction with its `order_number`/`created_at`/`total_amount`.
