# Implementation Plan: Sidebar Menu Reorg + Product Images & Clickable Filters

**Branch**: `004-sidebar-menu-reorg` | **Date**: 2026-09-03 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/004-sidebar-menu-reorg/spec.md`

## Summary

Two independent, purely-frontend changes bundled under one branch because
they were specified together: (1) reorder the sidebar and group
Kategori/Produk/Stok under a new collapsible "Inventaris" parent and
Vendor/Bahan Baku under a new "Purchase" parent, mirroring the existing
"Pengaturan" group pattern exactly; (2) show product images in the
Products table and POS grid, and replace the Products page's dropdown
artist/category filters — plus add a new artist filter to POS — with
clickable chips offering an explicit "All" option, matching the chip
pattern POS already uses for categories.

No backend changes: `ProductResource` already returns `image_url`, and
`GET /products` already accepts `artist_id`/`category_id` query filters.
This is confirmed by reading the current controller/resource, not assumed.

## Technical Context

**Language/Version**: Vue 3 (Composition API), no backend changes

**Primary Dependencies**: Vue Router (menu structure lives in `AppSidebar.vue`), vue-i18n, Phosphor Icons (duotone)

**Storage**: N/A — no schema/data changes

**Testing**: Vitest (`qa-tests/component/`)

**Target Platform**: Same SPA served by Laravel, per CLAUDE.md "What this is"

**Project Type**: Frontend-only slice of the existing web application

**Performance Goals**: N/A — no new queries; images already ship via existing `image_url` field

**Constraints**: Menu regrouping MUST NOT change any `menu_key`/route/authorization (FR-004) — this is presentation-only, verified against the existing "Pengaturan" group's own visibility logic in `AppSidebar.vue`.

**Scale/Scope**: 1 sidebar component edit + 2 view edits (`ProductsView.vue`, `PosView.vue`) + 1 new small reusable chip-filter pattern (or inline, given only 2 call sites) + locale keys.

## Constitution Check

- **I. Code Quality** — PASS. Menu grouping reuses the exact existing "Pengaturan" group mechanism (`children` array + group-visible-if-any-child-visible) — no new abstraction invented. The clickable filter chips reuse POS's existing category-chip markup/behavior, extended to artist and to the Products page, rather than inventing a second filter UI pattern.
- **II. Testing** — PASS (to be honored in implementation): Vitest coverage for the new sidebar order/grouping and for the new chip filters; browser-verified per Constitution II since this is UI-only work.
- **III. UX Consistency** — PASS. Tokens only, Indonesian+English via existing `nav.*`/relevant namespaces, hidden-not-disabled group visibility already established.
- **IV. Security** — PASS. No authorization surface changes (FR-004) — `menu_keys` untouched; verified by reading `User::canAccessMenu()`'s callers are unaffected (sidebar is cosmetic, server-side route/policy checks are the real gate, unchanged here).
- **V. Performance** — PASS. No new queries; filtering happens over already-fetched product lists / an existing query-param-driven fetch, same as today.

No violations; no Complexity Tracking entries.

## Project Structure

### Documentation (this feature)

```text
specs/004-sidebar-menu-reorg/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
└── contracts/
    └── ui-contract.md
```

### Source Code (repository root)

```text
resources/js/
├── components/layout/AppSidebar.vue   # MODIFIED — reorder NAV_DEFS, add 'inventaris-group' and 'purchase-group'
├── views/ProductsView.vue             # MODIFIED — image column in table, artist/category chips replacing BaseSelect filters
├── views/PosView.vue                  # MODIFIED — image on product cards, new artist chip row alongside existing category chips
├── utils/posProductCards.js           # MODIFIED — carry image_url/artist_id through into the card shape
└── locales/{id,en}.json               # MODIFIED — nav.inventaris_group, nav.purchase_group, all-artist/all-category chip labels

qa-tests/component/
├── AppSidebar.test.js                 # NEW (or extend existing test if one exists)
├── ProductsView.test.js               # MODIFIED — image + chip filter assertions
└── PosCartPanel.test.js / new PosView test — image + artist chip assertions
```

**Structure Decision**: No new directories — this is small, targeted edits within the existing frontend structure, no backend involvement per the Technical Context findings above.
