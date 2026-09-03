---

description: "Task list for Sidebar Menu Reorg + Product Images & Clickable Filters"
---

# Tasks: Sidebar Menu Reorg + Product Images & Clickable Filters

**Input**: Design documents from `/specs/004-sidebar-menu-reorg/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ui-contract.md, quickstart.md (all present)

**Tests**: Included per Constitution Principle II (every user-facing change verified by an automated test plus a real browser check) — not optional in this codebase.

**Organization**: By user story (spec.md priorities: US1=P1, US2=P1, US3=P2, US4=P2, US5=P2). US1/US2/US3 all edit the same single array in `AppSidebar.vue` — per research.md Decision 1 this is one atomic edit, done once under US1 (the story that defines the full target order), with US2/US3 each owning the test/locale work that verifies *their* group's content independently. This is documented, not an oversight — see Dependencies.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies on incomplete tasks)
- File paths are exact and repo-relative

## Path Conventions

Existing frontend structure only — `resources/js/`, `qa-tests/component/`. No backend files touched (confirmed in research.md Decision 4).

---

## Phase 1: Setup

- [X] T001 Run `npm test` once, unmodified, to confirm the current suite passes as a baseline before this feature's edits begin.

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Locale keys the sidebar template needs the moment the new group entries are added — shared by US1/US2/US3.

- [X] T002 [P] Add `nav.inventaris_group` and `nav.purchase_group` keys to `resources/js/locales/id.json` and `resources/js/locales/en.json` (both files).

**Checkpoint**: Locale keys exist; sidebar restructuring can begin.

---

## Phase 3: User Story 1 - Menu inti transaksi berurutan (Priority: P1) 🎯 MVP

**Goal**: Sidebar top-to-bottom order matches spec.md FR-001 exactly, with the two new groups in place.

**Independent Test**: Log in as owner, read the sidebar top to bottom, confirm the order in contracts/ui-contract.md.

- [X] T003 [US1] Edit `resources/js/components/layout/AppSidebar.vue`'s `NAV_DEFS` array: move the `sales` entry to immediately after `session`; insert the `purchase-group` entry (children: `vendors`, `materials`) immediately after `sales`; insert the `inventaris-group` entry (children: `categories`, `products`, `stock`) immediately after `purchase-group`; move `preorders` to immediately after `inventaris-group`; remove the now-relocated `categories`/`stock`/`vendors`/`materials` top-level entries (their `menuKey`s are unchanged, only their position/grouping changes). Leave `dashboard`, `pos`, `session`, `events`, `artists`, `products`(moved into group), `customers`, `reports`, and the `settings-group` otherwise untouched in their relative order.
- [X] T004 [US1] Create `qa-tests/component/AppSidebar.test.js`: render with an owner session (`menu_keys` = all), assert the rendered nav item order matches contracts/ui-contract.md's "Sidebar order contract" exactly (query rendered link/button text in DOM order).
- [X] T005 [US1] Browser-verify (Constitution II): log in as `owner`, confirm the order visually against quickstart.md step 1; log in as `kasir01`, confirm menu items kasir already couldn't see (e.g. `products`, `vendors`) are still absent, now correctly absent from within the new groups too.

**Checkpoint**: Sidebar order and both groups exist and are correctly gated.

---

## Phase 4: User Story 2 - Kategori/Produk/Stok sebagai "Inventaris" (Priority: P1)

**Goal**: The Inventaris group's content and per-child + whole-group visibility rules are independently verified.

**Independent Test**: Expand "Inventaris", confirm exactly Kategori/Produk/Stok in that order; confirm the group hides entirely for a role with none of those three menu keys.

- [X] T006 [US2] Extend `qa-tests/component/AppSidebar.test.js` (from T004): assert "Inventaris" contains exactly 3 children in order (Kategori, Produk, Stok); assert a session with only `stock` in `menu_keys` shows the group with only "Stok" visible; assert a session with none of `categories`/`products`/`stock` hides the "Inventaris" group entirely (mirrors the existing `settings-group` visibility test, if one exists — otherwise this is the first such test and should follow the same assertion style).
- [X] T007 [US2] Browser-verify: log in as `inventory` (has `stock`/`products`/`categories` per `UserFactory::LEGACY_ROLE_MENU_KEYS`), confirm all three children navigate to their existing unchanged pages.

**Checkpoint**: Inventaris group content/visibility proven correct in isolation.

---

## Phase 5: User Story 3 - Vendor/Bahan Baku sebagai "Purchase" (Priority: P2)

**Goal**: The Purchase group's content and visibility rules are independently verified.

**Independent Test**: Expand "Purchase", confirm Vendor/Bahan Baku; confirm the group hides entirely for a role with neither menu key.

- [X] T008 [US3] Extend `qa-tests/component/AppSidebar.test.js`: assert "Purchase" contains Vendor and Bahan Baku; assert a session with neither `vendors` nor `materials` in `menu_keys` (e.g. a `Kasir`-shaped session) hides the "Purchase" group entirely.
- [X] T009 [US3] Browser-verify: log in as `inventory`, confirm Vendor/Bahan Baku navigate to their existing unchanged pages; log in as `kasir01`, confirm "Purchase" is entirely absent.

**Checkpoint**: All three navigation user stories (US1-US3) independently proven.

---

## Phase 6: User Story 4 - Gambar produk di daftar Produk dan POS (Priority: P2)

**Goal**: Product images render (or a placeholder) in both the Products table and the POS grid.

**Independent Test**: Open Produk — every row shows a thumbnail or placeholder. Open POS — every card shows the same.

- [X] T010 [P] [US4] Add `image_url: p.image_url` to the object `buildProductCards()` returns in `resources/js/utils/posProductCards.js`.
- [X] T011 [P] [US4] Add a thumbnail column to the products table in `resources/js/views/ProductsView.vue`: render `<img :src="row.image_url">` when present, a placeholder (existing icon/graphic convention used elsewhere, e.g. `ph-image` duotone icon in a bordered square) when `image_url` is null.
- [X] T012 [US4] Add the same image-or-placeholder treatment to both `v-for` card blocks in `resources/js/views/PosView.vue` (`searchCards` and `browseCards` loops) — depends on T010 for `browseCards`; note `searchCards` (from `lookupVariants`, per the existing "fall back to a generic thumbnail" comment) may not carry `image_url` at all, in which case it always shows the placeholder, which is correct, not a bug.
- [X] T013 [P] [US4] Extend `qa-tests/component/ProductsView.test.js` (existing file, if present — else create it) and a new `qa-tests/component/PosView.test.js`: assert a product with `image_url` renders an `<img>` with that `src`; assert a product without one renders the placeholder (query for its distinguishing class/icon, not absence of `<img>`, to avoid a false-pass if the image tag is simply missing for an unrelated reason).
- [X] T014 [US4] Browser-verify: open Produk and POS with at least one seeded product that has no `image_path` (true for all of `SakanaFridgeDemoSeeder`'s products today) and confirm the placeholder renders cleanly (no broken-image icon, no layout shift/empty gap).

**Checkpoint**: Images/placeholders render correctly in both screens.

---

## Phase 7: User Story 5 - Filter artist & kategori sebagai chip (Priority: P2)

**Goal**: Clickable artist/category chips (with "All") on both Products and POS, AND-combinable.

**Independent Test**: Click an artist chip, then a category chip, on both screens; confirm narrowing; click "All" on each axis and confirm it widens back out.

- [X] T015 [US5] In `resources/js/views/ProductsView.vue`: replace the `BaseSelect` artist filter and category filter with chip rows (reuse POS's existing category-chip markup/class pattern per contracts/ui-contract.md), each with a "Semua Artist"/"Semua Kategori" chip (`value = null`) — wire to the existing `artistFilter`/`categoryFilter` refs and `applyFilters()` call, no change to the underlying fetch logic.
- [X] T016 [US5] In `resources/js/views/PosView.vue`: add a new artist-chip row (new `selectedArtistId` ref, `null` = "Semua Artist"), styled identically to the existing category-chip row; include `artist_id` in `loadBrowse()`'s query params when set (same `...(x ? {...} : {})` spread pattern already used for `category_id`); add a `watch(selectedArtistId, loadBrowse)` alongside the existing category watch.
- [X] T017 [P] [US5] Add `nav`/`master_data`/`pos` locale keys as needed for "Semua Artist" chip label (check whether "Semua Kategori"-equivalent already exists for POS's category chip and mirror it) in both `resources/js/locales/id.json` and `en.json`.
- [X] T018 [US5] Extend `qa-tests/component/ProductsView.test.js` and the new `qa-tests/component/PosView.test.js`: clicking an artist chip calls the product-list fetch with `artist_id` set; clicking "All Artists" clears it; same pair of assertions for category chips; a combined artist+category selection sends both params together (AND, per FR-008).
- [X] T019 [US5] Browser-verify: on both screens, click through an artist chip, a category chip, both together, then "All" on each, confirming the visible product list narrows/widens correctly each time (use the demo dataset — 3 artists × 3 categories from `SakanaFridgeDemoSeeder` while in DEMO mode gives enough spread to see real filtering).

**Checkpoint**: All 5 user stories independently functional.

---

## Phase 8: Polish & Cross-Cutting Concerns

- [X] T020 Run the full `npm test` suite; confirm zero regressions against the T001 baseline.
- [X] T021 Run `specs/004-sidebar-menu-reorg/quickstart.md` end-to-end in a real browser as both `owner` and `kasir01` (Constitution II).
- [X] T022 [P] Re-review the Constitution Check table in `specs/004-sidebar-menu-reorg/plan.md` against what was actually built; confirm no deviation (expected: none, since no backend surface was touched).

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (T001)**: none.
- **Foundational (T002)**: none — can start immediately, in parallel with T001.
- **US1 (T003-T005)**: depends on Foundational (T002, locale keys referenced by the new group labels). T003 is the ONE structural edit that US2 and US3's tests verify — this is a deliberate exception to "independent implementation per story" because all three stories edit the same array in the same file (see header note); US2/US3 do not re-edit `AppSidebar.vue`.
- **US2 (T006-T007)**: depends on T003 (the group must exist to test its content).
- **US3 (T008-T009)**: depends on T003, independent of US2.
- **US4 (T010-T014)**: fully independent of US1/US2/US3 — different files (`posProductCards.js`, `ProductsView.vue`, `PosView.vue`'s image markup) — can be built in parallel with the sidebar work.
- **US5 (T015-T019)**: independent of US1/US2/US3; touches the same two files as US4 (`ProductsView.vue`, `PosView.vue`) — sequence after US4 to avoid merge churn on the same template sections, even though nothing technically blocks starting it earlier.
- **Polish (T020-T022)**: after all desired stories are complete.

### Parallel Opportunities

- T001 and T002 in parallel.
- T010, T011, T013 (different files) in parallel within US4.
- T017 in parallel with T015/T016 within US5.
- US4's entire phase can run in parallel with US1+US2+US3's entire phase (disjoint files).

---

## Implementation Strategy

### MVP First

1. Setup + Foundational.
2. US1 (includes the full structural edit) → **STOP and VALIDATE** against quickstart.md step 1-4 → this alone already delivers the explicitly-requested menu reorg.
3. US2, US3 → validate group content in isolation (cheap, since T003 already did the real work).
4. US4, US5 → validate the Product-screen improvements (independent of navigation work, can be done first, last, or in parallel by a second person).
5. Polish.

### Parallel Team Strategy

- Developer A: Setup/Foundational → US1 → US2 → US3 (all `AppSidebar.vue` + its test file).
- Developer B (independent, starts immediately after Foundational): US4 → US5 (all `ProductsView.vue`/`PosView.vue`/`posProductCards.js` + their test files).

---

## Notes

- No backend task exists in this list — confirmed unnecessary in research.md Decision 4.
- Every UI-behavior task has a paired test task per Constitution Principle II.
- Commit after each task or logical group.
