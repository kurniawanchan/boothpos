# Phase 0 Research

## Decision 1: Reuse the existing "Pengaturan" group mechanism verbatim

**Decision**: `AppSidebar.vue`'s `NAV_DEFS` array already supports a group
shape (`{ key, label, icon, children: [{ name, label, menuKey }] }`) with
visibility computed as "shown if at least one child's `menuKey` passes
`auth.canAccessMenu()`". Add two more entries of this exact shape
("Inventaris": categories/products/stock; "Purchase": vendors/materials)
— no new mechanism.

**Rationale**: The group-visibility rule is already correct and tested by
production use (Pengaturan/Users/Roles) — reimplementing it a second way
for the new groups would violate Constitution I's "one sanctioned path
per concern" and risk a second, subtly different visibility bug.

**Alternatives considered**: A generic "menu config" data file — rejected
as unnecessary abstraction for two more groups; the array literal in
`AppSidebar.vue` is already the single source of truth and is small
enough to read at a glance.

## Decision 2: "Purchase" = regroup Vendors + Materials (already decided in spec.md Assumptions)

Carried over from spec.md; no new research needed here — CLAUDE.md
confirms purchase-order management is out of MVP scope, so no backend
capability is missing, only a navigation label.

## Decision 3: Clickable chip filters reuse POS's existing category-chip markup

**Decision**: The artist/category filter chips (Products page + POS) copy
the existing POS category-chip button pattern (`selectedCategoryId === c.id
? active classes : inactive classes`, plus a `null`-value "All" chip) —
extended with a second, independent `selectedArtistId` ref, combined via
existing `&&`-style query param construction (both params sent to
`GET /products` when both are set — already AND semantics server-side
since Eloquent `when()` chains combine with implicit `AND`).

**Rationale**: No new component needed for 2-3 call sites; the existing
inline chip markup is simple enough that extracting a `ChipFilter.vue`
component now would be premature abstraction (Constitution I) for this
scope. If a fourth chip-filter call site appears in a future feature,
that would be the trigger to extract one.

**Alternatives considered**: `BaseSelect` dropdowns (what Products page
currently uses) — rejected per spec.md FR-007's explicit "clickable
chips, not dropdown" requirement.

## Decision 4: No backend changes — verified, not assumed

Confirmed by reading current code before planning:
- `app/Http/Resources/ProductResource.php` already returns `image_url`
  (built from `image_path` via `Storage::disk('public')->url()`).
- `app/Http/Controllers/Api/ProductController.php::index()` already
  accepts `artist_id`/`category_id` filters (used today by
  `ProductsView.vue`'s dropdown filters) and `?with_variants=1` (used by
  `PosView.vue`'s `loadBrowse()`).
- POS's product-card shape is built client-side in
  `resources/js/utils/posProductCards.js` from the same `GET /products`
  response already being fetched — it already carries `artist_name`
  through (used for the cart line item), but not `image_url`, which
  `buildProductCards()` needs to add. Artist *filtering* itself is
  server-driven via the `artist_id` query param (same as the existing
  category filter), so the card object doesn't need `artist_id` itself.

This means the entire feature is a frontend-only change: no migration,
no controller/resource edit, no new route.
