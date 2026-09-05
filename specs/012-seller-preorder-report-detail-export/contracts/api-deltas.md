# API Contract Deltas: Seller Recap Preorder Detail, Richer Pre-order Report & Missing Report Exports

All deltas below MUST be reflected in `docs/openapi-pos-mvp.yaml` in the
same commit. Status codes follow the existing convention: `422`
validation, `409` business-rule conflict, `403` role denial.

## GET /reports/artist-settlements/{artist}/transactions (existing route, response shape changes)

- **Change**: `transactions[]` entries now include preorder-sourced
  entries alongside order entries. Each entry gains a `source`
  (`"order"|"preorder"`) field; the identifying field is renamed from
  `order_id` to `key` (a preorder entry has no `order_id`) and
  `order_total_for_artist` is renamed to `amount_for_artist` for the same
  reason (`ArtistTransactionsModal.vue` updated to match — see plan.md
  Project Structure).
- **Also fixed**: this endpoint's underlying query now explicitly filters
  `data_mode = ModeGate::current()` on both the order and preorder halves
  (previously missing entirely on the order half — a pre-existing gap,
  see research.md R1).
- **Auth**: unchanged (`canAccessMenu('reports')`).

## GET /reports/preorders (existing route, new optional params)

- **New param**: `breakdown=artist` (optional). When present, response
  rows are the per-seller breakdown shape (data-model.md) instead of the
  existing status × payment-completeness rows. Omitted → existing
  behavior unchanged.
- **New param**: `status`, `payment_completeness`, and optionally
  `artist_id` (all optional, used together by the drilldown). When any of
  these are present, the response returns the individual-preorder
  drilldown rows (data-model.md) matching that bucket instead of an
  aggregate.
- **Auth**: unchanged (`canAccessMenu('reports')`).
- **Errors**: unchanged conventions (`403` for role denial).

## GET /reports/{report}/export (existing route, whitelist extended)

- **Change**: `{report}` whitelist extended from
  `['sales', 'profit', 'artist-settlements', 'artist-profit']` to also
  accept `'purchases'`, `'stock-by-artist'`, `'preorder'`.
- **`purchases`**: single-sheet workbook, `GenericArrayExport` of
  `purchases()`'s existing `rows`.
- **`stock-by-artist`**: single-sheet workbook, `GenericArrayExport` of
  `stockByArtist()`'s existing summary `data`.
- **`preorder`**: two-sheet workbook via `MultiSheetArrayExport` —
  "Ringkasan" (existing status × payment-completeness rows) and
  "Per Seller" (new `breakdown=artist` rows).
- **Auth**: unchanged (`canAccessMenu('reports')`, plus `profit`/
  `artist-profit`'s existing self-enforced 403 for cashier, unaffected by
  this change).
- **Params**: all three new export types respect the same `event_id`
  filter param the existing three already do (FR-012).

## No changes

- `GET /reports/purchases`, `GET /reports/stock-by-artist` (summary mode)
  — response shape unchanged, only newly exportable.
- `GET /preorders/{preorder}` — reused as-is by the drilldown's
  reuse-existing-detail-view navigation (research.md R4); no shape change.
