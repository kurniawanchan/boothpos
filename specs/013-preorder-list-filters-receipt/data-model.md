# Phase 1 Data Model: Preorder List Filters, Seller Info & Receipt-Style Invoice

No new tables, columns, or migrations. Every entity below already exists; this feature only
adds a relation and surfaces already-stored data through the API response shape.

## PreorderItem (existing table `preorder_items`, existing column `artist_id`)

- **New relation**: `artist(): BelongsTo` → `Artist::class` (via existing `artist_id`
  column). No migration — `artist_id` has always been populated by `PreorderService::create()`
  at write time (same as `OrderItem.artist_id`), it is simply never exposed as an Eloquent
  relation today.

## Preorder (existing table `preorders`) — response shape additions only

- **`sellers`** (new, derived, read-only): array of `{ id, name }`, the distinct set of
  artists across the preorder's items, in first-appearance order. Computed at response time
  from `items.artist`, not stored. Empty array only in the (should-not-happen-in-practice, but
  gracefully handled) case of an item whose `artist_id` no longer resolves to an active
  `Artist` row.
- **`items[].artist_id` / `items[].artist_name`** (new, in both the list-adjacent detail
  response and the invoice response's `items[]`): per-line seller attribution, mirroring the
  existing `items[].sku_snapshot`/`name_snapshot` snapshot-style fields — sourced live from
  `artist_id`/`artist.name` (not a new snapshot column) since, unlike price/name at time of
  sale, which seller a line belongs to is not something this app treats as time-sensitive
  history elsewhere (`OrderItem`/`ReceiptModal.vue`'s `item.artist_name` already work the same
  way — live join, not a snapshot).

## Preorder — new derived aggregate (not persisted)

- **Summary statistics** (`GET /preorders/summary`, see `contracts/api-deltas.md`): computed
  on demand from the same filtered `Preorder` query `index()` already builds —
  `transaction_count` (COUNT), `by_status: [{ status, total_amount }]` (SUM `total_amount`
  GROUP BY `status`), `grand_total` (SUM `total_amount`), `total_outstanding` (SUM
  `total_amount` − SUM `paid_amount`). No new table; this is a read-time aggregate exactly
  like every other report in `ReportController`.

## Key Entities (spec.md cross-reference)

- **Preorder**: unchanged structurally; gains the derived `sellers` field and is the subject
  of the new `summary()` aggregate and the restyled invoice document.
- **Preorder Item**: unchanged structurally; gains the `artist()` relation so its
  already-existing `artist_id` can be surfaced without a schema change.
- **Seller (Artist)**: fully unchanged — reused purely as a filter dimension (`artist_id`
  query param) and a display label, identical to how the Reports screen's just-added seller
  filter already reuses it (`012`'s ReportsView.vue work, same session).
