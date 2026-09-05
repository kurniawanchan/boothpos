# Phase 1 Data Model: Restore Sales Receipt Action & Event Info in Receipt Footers

No new tables, columns, or migrations. One new Eloquent relation; the rest is additive
response-shape fields sourced from data that already exists.

## Preorder (existing table `preorders`)

- **New relation**: `event(): BelongsTo` → `Event::class`, mirroring the already-existing
  `Order::event()`. `preorders.event_id` already exists and is already nullable (a preorder
  is optionally event-scoped, per `010`'s research.md R6) — this relation simply exposes it.

## Order / Preorder — response shape additions only (no new persisted fields)

- **`event_name`** (Order: already returned by `receipt()`; Preorder: new) — `string`.
- **`event_location`** (new on both) — `string|null`, from `Event.location`.
- **`event_start_date`** (new on both) — `string|null` (date), from `Event.start_date`.
- **`event_end_date`** (new on both) — `string|null` (date), from `Event.end_date`.

For `Order`, all four are effectively always present (every order requires an event). For
`Preorder`, all four are `null` when the preorder has no `event_id` — the frontend omits the
entire footer block in that case (FR-005), not just individual blank fields.

## Key Entities (spec.md cross-reference)

- **Order / Sale transaction**: unchanged structurally; `receipt()`'s response gains three new
  event fields alongside its already-existing `event_name`.
- **Preorder**: gains the `event()` relation and, on its `invoice()` response only, the same
  four event fields as `Order`.
- **Event**: fully unchanged — reused purely as read-only footer context via its existing
  `name`/`location`/`start_date`/`end_date` columns.
