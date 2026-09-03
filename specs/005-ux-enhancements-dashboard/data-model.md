# Phase 1 Data Model

No new tables or migrations are required. This feature reads existing
mode-scoped tables (`orders`, `order_items`, `products`, `product_variants`,
`artists`, `categories`, `events`, `preorders`, `stock_movements`) for
dashboard aggregates, and writes to the existing `users` table for
self-service password/photo changes.

## Entities (conceptual, from spec.md)

### Artist / Category filter selection (frontend-only, not persisted)
- `selectedArtistIds: number[]` — empty array means "All"
- `selectedCategoryIds: number[]` — empty array means "All"
- Scope: component-local state on `ProductsView.vue` / `PosView.vue`, reset
  on navigation away (unchanged from current behavior per spec FR/edge case).

### Dashboard shortcut (frontend config + backend permission echo)
- `key: string` (e.g. `new_sale`, `new_preorder`, `stock_adjustment`, `add_product`)
- `label: string` (i18n key)
- `route: string` (SPA route name)
- `requiredMenuKey: string` — matches an existing entry in the current
  user's `menu_keys` (already surfaced on `/auth/me` per `AuthController`);
  a shortcut is rendered only if `menu_keys` contains its `requiredMenuKey`.
  No new backend permission concept — reuses the existing `Role::menu_keys`
  mechanism already documented in CLAUDE.md.

### Dashboard analytics response (read-only, computed on request)
- `sales_by_day: { date: string, total: string }[]` — scoped by the
  requested date range (default: current event or last 30 days), money as
  string per API convention.
- `sales_by_category: { category_id: int|null, category_name: string, total: string }[]`
- `sales_by_artist: { artist_id: int|null, artist_name: string, total: string }[]`
- `sales_by_event: { event_id: int, event_name: string, total: string }[]`
- `stats: { total_sales_active_event: string, low_stock_count: int, out_of_stock_count: int, pending_preorder_count: int }`
- All figures computed with an explicit `data_mode = <active mode>` filter
  on the underlying `order_items`/`stock_movements`/`preorders` queries,
  mirroring `ReportController`'s existing documented pattern (CLAUDE.md
  "Hand-rolled `DB::table(...)` queries bypass the Eloquent global scope").

### User profile (existing `users` row, self-scoped view)
- Read: `id`, `name`, `username`, `role.name`, `photo_path` (already exposed
  via `AuthController::me` except `photo_path`/photo URL — needs adding).
- Write (password): validated `current_password` (must `Hash::check` against
  `users.password`) + `password` (new, subject to the same policy already
  enforced on account creation/reset — reuse the same Laravel `Password`
  rule object already applied in `UserController`'s store/update validation
  if one exists, else the framework default `Password::defaults()`).
- Write (photo): validated `image` file (`mimes:jpeg,png`, max
  `ImageUploadService::MAX_KILOBYTES`) → `ImageUploadService::store()` →
  `users.photo_path` updated, old file deleted via
  `ImageUploadService::delete()` (identical pattern to
  `UserController::uploadPhoto`).

No new validation rules beyond what already exists elsewhere in the
codebase for these same operations (admin-driven user creation/password
reset, admin-driven photo upload) — this feature only adds a *self-scoped*
entry point to the same underlying operations.
