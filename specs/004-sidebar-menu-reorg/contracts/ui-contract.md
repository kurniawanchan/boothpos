# UI Contract: Sidebar Order & Filter Chips

No HTTP contract changes (no backend touched — see research.md Decision
4). This documents the frontend-only contracts this feature must satisfy.

## Sidebar order contract

Final top-to-bottom order (existing items not mentioned by the spec keep
their current relative order, per FR-001):

```text
Dashboard
POS
Cashier Session (Sesi Kasir)
Sales (Penjualan)
Purchase (group)          <- NEW group: Vendor, Bahan Baku
Inventaris (group)        <- NEW group: Kategori, Produk, Stok
Pre-orders
Events
Artists
Customers
Reports
Pengaturan (group, unchanged): Settings, Users, Roles
```

Group visibility contract (already implemented for "Pengaturan", reused
verbatim per research.md Decision 1): a group renders only if at least
one child's `menuKey` passes `auth.canAccessMenu(menuKey)`; each child
within a visible group still independently hides if its own `menuKey`
fails the check.

## Filter chip contract (Products page, POS)

- Each filter axis (artist, category) renders as a horizontal row of
  chip buttons: one "All" chip (value `null`) plus one chip per entity.
- Exactly one chip per axis is visually "active" at a time (the selected
  `id`, or "All" when `null`).
- Clicking a chip sets that axis's ref and re-fetches (or re-filters) the
  product list; the other axis's selection is untouched (independent,
  AND-combined per FR-008).
- Products page: replaces the existing `BaseSelect` artist/category
  filters with this chip pattern (same underlying `artistFilter`/
  `categoryFilter` refs and `applyFilters()` call already wired to
  `GET /products`).
- POS: extends the existing category-chip row with a new, visually
  identical artist-chip row above/beside it; both feed `loadBrowse()`'s
  existing query-building `when()`-style spread.

## Image display contract (Products table, POS cards)

- Products table: each row shows a small square thumbnail from
  `image_url`; a generic placeholder icon/graphic when `image_url` is
  `null`.
- POS card: same image shown at card-appropriate size in the existing
  card layout; same placeholder rule when absent.
- No upload capability added — read-only display of the existing
  `image_path`/`image_url` field (spec.md Additional Assumptions).
