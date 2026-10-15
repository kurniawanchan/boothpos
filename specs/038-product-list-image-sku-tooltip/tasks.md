---

description: "Task list for feature 038 — Products list: larger image with code, and SKU tooltip"
---

# Tasks: Products List — Larger Image with Code, and SKU Tooltip

**Input**: Design documents from `/specs/038-product-list-image-sku-tooltip/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/ui-contract.md, quickstart.md

**Tests**: INCLUDED (Constitution II). The 036 list assertions change only where they tie to the old 56 px / two-column layout.

**Organization**: By user story. **Foundational (Phase 2) = the `BaseTooltip` upgrade**, because US2 needs the bubble not to be clipped by the table's scroll container. US1 (column merge) is independent of it and of US2 except that both edit `ProductsView.vue` — run them sequentially.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1, US2

## Conventions to honour

- Comments and commit messages in Indonesian; tokens only (no hex); no new user-facing strings are expected — if one is added, put it in BOTH `en.json` and `id.json` (the `localeKeys` test must stay green).
- Tests on the host only; never touch the dev DB `boothpos`; the SKU click must keep opening the variant detail (regression guard).
- **Pitfalls carried over**: a `watch(..., {immediate:true})` must come after the refs it touches; a Teleported element still needs a stable `id` for `aria-describedby`.

---

## Phase 1: Setup

- [X] T001 Baseline on `038-product-list-image-sku-tooltip`: run `npx vitest run`; note the pass counts (expect all green, 733 passed | 2 skipped) so later failures are attributable.

---

## Phase 2: Foundational — `BaseTooltip` that is never clipped

**Purpose**: the SKU tooltips sit inside `DataTable`'s `overflow-auto` wrapper, which would clip an absolutely-positioned bubble (research.md D2).

- [X] T002 [P] Extend `qa-tests/component/BaseTooltip.test.js` (keep the two existing tests green): the bubble is rendered in `document.body` (teleported) and is `position: fixed`; opening computes its position from the trigger's mocked `getBoundingClientRect` — below the trigger by default (`top = rect.bottom + 6`, `left = rect.left`); flips ABOVE (`bottom = innerHeight − rect.top + 6`) when there is < 90 px below and more room above; `align="right"` right-aligns the bubble to the trigger's right edge; `left` is clamped into `[8, innerWidth − 8 − 256]` for a trigger near the right/left edge; `scroll` (capture) and `resize` close an open bubble; blank/whitespace `text` is never shown even on hover/focus (and has no `aria-describedby` target that reads empty); `aria-describedby` on the wrapper still points at the bubble's `id` and `aria-hidden` toggles. Expect FAIL before T003.
- [X] T003 `resources/js/components/ui/BaseTooltip.vue`: render the bubble inside `<Teleport to="body">` with inline `position: fixed` style computed in an `updatePosition()` called when it opens (mouseenter / focusin); keep `v-show` + stable `id` + `role="tooltip"` + `:aria-hidden`; `show = open && text.trim() !== ''`; register `scroll` (capture) / `resize` listeners only while open (remove on close and `onBeforeUnmount`); keep `align`; `w-64`, tokens only. Indonesian docblock: why fixed + teleport (DataTable `overflow-auto` clips an absolute bubble), same technique as `BaseSelect`. Run T002 plus `qa-tests/component/ProductVariantCard.test.js` (the Open BOM tooltip must still work in the drawer).

**Checkpoint**: tooltip is clip-proof, tested, and the 037 drawer tooltip still passes.

---

## Phase 3: User Story 1 — A larger product picture with its code beneath it (Priority: P1) 🎯 MVP

**Goal**: one combined first column — picture (96 px) above the one-line code; no picture-only column; table not wider.

**Independent Test**: Products list with and without pictures — picture ≥ 84 px (96) with the code under it on one line, placeholder same size, header "Code", other columns/actions unchanged.

### Tests for US1 (write first, expect failures)

- [X] T004 [P] [US1] Update `qa-tests/component/ProductsView.test.js` (the 036 cases) and add new ones: there is NO column with an empty header (`columnheader` names are Code, SKU, Product name, Seller, Category, Type, Status + the actions column); in a row, the picture/placeholder and the code live in the SAME cell with the picture BEFORE the code in DOM order; the picture is `h-24 w-24` (image and `.ph-image` placeholder wrapper) and no longer `h-14`; the code keeps `whitespace-nowrap` and `title=<full code>`; clicking the picture still opens the lightbox; Detail/Edit/Delete, status, SKU, filters and paging cases keep passing unchanged. Check `qa-tests/component/ProductImageUpload.test.js` still passes without edits.

### Implementation for US1

- [X] T005 [US1] `resources/js/views/ProductsView.vue`: remove `{ key: 'image_url', label: '' }` from `columns`; replace `#cell-image_url` and `#cell-code_prefix` with ONE `#cell-code_prefix` cell: `flex flex-col items-center gap-1.5` containing (1) the picture button (`h-24 w-24`, `object-cover`, rounded + `border-line-2`, existing `openImageLightbox(row)` and aria-label) or the same-size placeholder (`flex h-24 w-24 … ph-image text-[30px]`), then (2) the code span (existing classes: `font-mono text-[12px] font-bold text-brand-active`, `whitespace-nowrap`, `truncate`, `max-w-[…]`, `title`). Update the Indonesian comments (036 note: 56 px → 96 px, why merged). Run T004.
- [X] T006 [US1] Run `npx vitest run` (all frontend) — green; fix only expectations tied to the old size/columns.

**Checkpoint**: US1 demonstrable on its own.

---

## Phase 4: User Story 2 — See a variant's name by hovering its SKU (Priority: P1)

**Goal**: hover/focus on a SKU shows the variant name; click still opens the detail.

**Independent Test**: on a product with several variants, hover and Tab through the SKUs, read each name, click one and confirm the detail opens.

### Tests for US2 (write first, expect failures)

- [X] T007 [P] [US2] Extend `qa-tests/component/ProductsView.test.js` (fixtures with `variants: [{id, sku, variant_name}…]`, ≥ 5 variants on one product so "+N more" exists): hovering a SKU shows a `role="tooltip"` whose text is exactly that variant's `variant_name` and leaving hides it; keyboard focus (`Tab`) shows the same tooltip and Escape hides it; after clicking "+N more", a SKU from the revealed part shows its own variant name too; clicking a SKU (with the tooltip visible) still calls the variant-detail open path (the detail dialog for that variant appears / the existing `openVariantDetail` effect); the "+N more"/"show less" button has no tooltip; two variants with the same `variant_name` each show it and keep their own distinct SKU text; a variant with an empty name shows NO tooltip.

### Implementation for US2

- [X] T008 [US2] `resources/js/views/ProductsView.vue` (`#cell-sku`): wrap each SKU `<button>` in `<BaseTooltip :text="v.variant_name ?? ''">` (the comma `<span>` stays outside the wrapper; the "+N more" toggle is NOT wrapped; keep the existing click handler and classes). `BaseTooltip` is already imported by 037. Run T007.
- [X] T009 [US2] Run `npx vitest run` — green.

**Checkpoint**: both stories delivered.

---

## Phase 5: Polish & Cross-cutting

- [X] T010 [P] `CLAUDE.md`: add a short note to the Frontend/037 section — `BaseTooltip` now teleports a fixed bubble (never clipped by scroll containers, flips above, clamped, closes on scroll/resize, blank text never shown); Products list first column = picture (96 px) above the one-line code. Keep the Active-feature block already written.
- [X] T011 [P] `README.md` "bugs found during execution" entry for 038 only if a real defect is found while verifying (e.g. the last-row tooltip clipping is the thing to look for); otherwise skip.
- [X] T012 Full verification: `npm test` (all frontend, green). No backend change, so run `APP_ENV=testing php artisan test --filter=ProductTest` as a smoke check only.
- [X] T013 Real-browser verification per `quickstart.md` on an ISOLATED server (`APP_ENV=testing php artisan serve --port=8091`, test DB, `license:dev-activate` on the TEST DB only, `npm run build` first; seed products with/without pictures and 1–9 variants — temporary images under `public/qa-img/` referenced as `../qa-img/vN.png` with `APP_URL=http://127.0.0.1:8091`, removed afterwards): (a) combined column at 1440 px (picture ≥ 84 px, code one line, no empty-header column, placeholder same size, lightbox); (b) **measure content width vs container at 1100 px and 1440 px and compare with the 037 baseline (977 px content / 808 px container at 1100)** — must not be wider; (c) hover + Tab show the variant-name tooltip, Escape hides it, the LAST row's tooltip flips above and is not clipped, a "+N more" SKU works, click still opens the detail; (d) EN ↔ ID, console clean. Screenshots (no customer data) to `specs/038-product-list-image-sku-tooltip/evidence/`.
- [X] T014 Remove scratch files (`public/qa-img`, `.playwright-mcp`, scratchpad seed scripts), mark every completed task `[X]` here (`python3 <scratchpad>/mark.py T001 …` now follows `.specify/feature.json`), and prepare the commits (Indonesian: docs commit for the spec set, then the feature commit) — commit only on the user's request; push/PR/merge only when explicitly asked, and when asked to push use the personal GitHub account (`gh` active account `kurniawanchan`; pass `-c credential.helper='!gh auth git-credential'`).

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002 → T003) → US1 → US2 → Polish. US1 does not need Phase 2 but both user stories edit `ProductsView.vue`, so keep them sequential; Phase 2 must precede US2.
- T004 ∥ T002 (different files, both tests-first); T007 after T005 (same test file as T004, so write after T004 is done); T010 ∥ T011.

### Parallel opportunities

- T002 ∥ T004 (different test files). T010 ∥ T011.

## Implementation Strategy

- **MVP = Phases 1 + 3 (US1)**: the larger combined picture/code column ships on its own value; US2 follows once `BaseTooltip` is clip-proof.
- Stop at each checkpoint and run the phase's tests plus the T001 baseline; never loosen an assertion — change an expectation only where the 56 px / two-column layout was deliberately replaced.
