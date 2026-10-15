---

description: "Task list for feature 037 — variant drawer and BOM dialog refinements"
---

# Tasks: Variant Drawer and BOM Dialog Refinements

**Input**: Design documents from `/specs/037-variant-drawer-bom-ui/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/bom-copy-selected.md, quickstart.md

**Tests**: INCLUDED (Constitution II). Existing tests change only where they assert the OLD position/wording of a moved control; never loosened otherwise.

**Organization**: By user story in spec order (US1 card P1 → US2 duplicate P1 → US3 BOM copy tool P1). Phase 2 holds the shared UI primitives. US2 edits the same file as US1 (`ProductsView.vue`) so it runs after US1; US3 is independent of US1/US2 (different files) and may run in parallel with them.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1..US3

## Conventions to honour

- Comments, `id` copy, commit messages in Indonesian; locale keys in BOTH `en.json` and `id.json` — check a key is free first (a repeated key in one section silently overrides). Helper used in 036: `python3 <scratchpad>/loc.py <section> <after_key> '{"en":{…},"id":{…}}'`.
- Design tokens only (no hex in components); colour is never the only cue (chips keep text labels).
- Backend tests on the HOST only: `APP_ENV=testing php artisan test --filter=…` (database `boothpos_test`); never inside the `app` container without `-e` overrides; never touch the dev DB `boothpos`.
- BOM writes stay in `VariantBomService` (single write path); statuses 422/409/403; `docs/openapi-pos-mvp.yaml` moves with the API change.
- **Pitfall from 036**: a `watch(..., {immediate: true})` must come AFTER the refs it touches are declared.

---

## Phase 1: Setup

- [X] T001 Baseline on `037-variant-drawer-bom-ui`: run `npx vitest run` and `APP_ENV=testing php artisan test --filter='VariantBom|ProductTest|Bom'`; note the pass counts (expect all green) so later failures are attributable.

---

## Phase 2: Foundational (shared UI primitives)

- [X] T002 [P] `resources/css/app.css`: add to `@theme` the `sky` and `violet` token pairs from research.md D5 (`--color-sky-text:#1f5f8b; --color-sky-bg:#e6f0f7; --color-violet-text:#5b3f99; --color-violet-bg:#efeaf8`) with a short Indonesian comment (chip colours, contrast ≥ 4.5:1). No other CSS change.
- [X] T003 [P] Create `qa-tests/component/BaseTooltip.test.js`: the bubble (`role="tooltip"`) is hidden by default; shows on hover and on keyboard focus of the trigger; the trigger wrapper references it via `aria-describedby`; Escape hides it; the `text` prop is rendered; the slot is the trigger. Expect FAIL before T004.
- [X] T004 Create `resources/js/components/ui/BaseTooltip.vue` (props `text`, optional `placement` default `bottom-start`; wrapper `inline-flex relative`; bubble absolutely positioned, `w-64`, token classes, `z-[95]`, shown by `group-hover`/`group-focus-within` plus an Escape handler; `useId` for `aria-describedby`; Indonesian docblock on why not the native `title`: not keyboard-accessible). Run T003.

**Checkpoint**: tokens and tooltip exist and are tested.

---

## Phase 3: User Story 1 — A variant card that is easy to scan and act on (Priority: P1) 🎯 MVP

**Goal**: bounded cards, four distinct chips, header actions `Open BOM · Apply markup · delete`, fields name → stock → cost → sell, wider drawer, 66 px picture.

**Independent Test**: Edit a product with several variants — verify separation, chip colours/order, header action order, field order, drawer width, picture size, tooltip; unsaved variant shows no Open BOM / BOM cost.

### Tests for US1 (write first, expect failures)

- [X] T005 [P] [US1] Create `qa-tests/component/ProductVariantCard.test.js` (mock `api/products`, `artists`, `categories`, `stock`, `materials`, `vendors` like `ProductsView.test.js`/`ProductImageUpload.test.js`; open **Edit** on a product whose variants include one with `bom_cost`/`has_bom`, one without, one with an image, plus an unsaved row added via "Add variant"): each card is a bounded container (`rounded-card border … shadow-sm`, white) inside the tray; chips present in order SKU → markup → margin → BOM cost with the four token colour classes (`text-sky-text bg-sky-bg`, mint, `text-violet-text bg-violet-bg`, warn) and a negative markup/margin uses the danger classes; BOM cost chip only for `has_bom`; header right side order `Open BOM` (button) → `Apply markup` → delete (by DOM order of the three controls); Open BOM is a `<button>` with a tooltip (hover/focus shows the BOM explanation); unsaved row has Apply markup + delete but NO Open BOM and NO BOM cost chip; label order in the field grid is name → stock → cost → sell; the drawer panel has `max-w-[1040px]`; the picture is `h-[66px] w-[66px]` and a same-size placeholder appears for a variant without a picture; Apply markup still sets sell price = cost × (1 + markup%); completed BOM still disables cost price with its hint.

### Implementation for US1

- [X] T006 [P] [US1] Locale keys en + id in `master_data` (free names): `bom_open_tip` ("A BOM (Bill of Materials) is the list of materials and services, with their cost, used to make ONE finished product. Open it to review or change it." / Indonesian equivalent), `chip_sku`, `chip_bom_cost` ("BOM cost: {cost}" reuse existing `bom_cost_label` instead if it fits), `variant_stock_label` only if needed; run `qa-tests/unit/localeKeys.test.js`.
- [X] T007 [US1] `resources/js/views/ProductsView.vue` (drawer variant section): pass `max-width-class="max-w-[1040px]"` to the product `BaseDrawer`; make the group container a subtle tray (`bg-surface-subtle`) and each variant card `bg-white border border-line-2 rounded-card shadow-sm p-5` with `gap-5` between cards (keep the inactive `opacity-50`); header = chips left (flex-wrap: SKU `bg-sky-bg text-sky-text`, "from BOM" badge unchanged, markup mint / danger when negative, margin `bg-violet-bg text-violet-text` / danger when negative, then — only `row.id && row.has_bom` — the BOM cost chip `bg-warn-bg text-warn-text` with `bom_cost_label`) and actions right in this exact order: `Open BOM` (`BaseButton variant="secondary" size="sm"`, `v-if="row.id"`, wrapped in `BaseTooltip :text="t('master_data.bom_open_tip')"`, opens `openBomFor(row)`), `Apply markup` (existing `applyMarkup(row)`, secondary sm), delete icon (unchanged); REMOVE the old standalone BOM-cost/Open-BOM line and the old stock+Apply-markup row; fields as one grid `grid-cols-2 lg:grid-cols-4` in the order variant name (span 2 on small) · stock · cost price (disabled + hint when `bom_complete`) · sell price; picture block: `h-[66px] w-[66px]` thumbnail button / same-size placeholder (`ph-image`) when no `image_url` and no pending `image_file`, beside the file chooser. Keep every handler and the "copy BOM from" select for new rows. Run T005 and `qa-tests/component/ProductsView.test.js`, `ProductImageUpload.test.js`; update only assertions that tied to the old position/size.
- [X] T008 [US1] Run `npx vitest run` (all frontend) — green; fix any test that asserted the old `h-11` image size or old control positions (change the expectation, keep its intent).

**Checkpoint**: US1 demonstrable and shippable on its own.

---

## Phase 4: User Story 2 — Duplicate a variant together with its BOM (Priority: P1)

**Goal**: a "Duplicate variant" action adds an unsaved card below the source, seeded with name (copy marker), prices, alert, status, stock and — when applicable — `copy_bom_from`; saving reuses the existing new-variant flow.

**Independent Test**: duplicate a variant that has a BOM, rename it, save the product → new SKU, same prices/stock, independent BOM copy; Cancel → nothing created.

### Tests for US2 (write first, expect failures)

- [X] T009 [P] [US2] Create `qa-tests/component/ProductVariantDuplicate.test.js` (same mocks as T005): clicking **Duplicate variant** on a saved variant with `has_bom` and a user with `purchase_orders` inserts ONE new card directly below it (source unchanged) with name `"<name> (copy)"`, the source's cost/sell prices, low-stock alert and stock, no SKU chip, no picture, and shows the BOM-copy note and the "copied stock counts as new inventory" note; the stock-adjustment reason field appears; saving the product calls `addVariant(productId, { variant_name: '<name> (copy)', cost_price, sell_price, low_stock_alert, copy_bom_from_variant_id: <source id>, … })` and then `createAdjustment` with `{ variant_id: <new id>, qty_change: <source stock> }`; a user WITHOUT `purchase_orders` gets the fields but no `copy_bom_from_variant_id` and no BOM note; a source without a BOM → no BOM copy; duplicating an UNSAVED card copies fields only; duplicating an inactive saved variant yields an active copy; duplicating a complete-BOM variant keeps the (locked) cost value on the copy, which is NOT disabled/locked; the copy's stock can be edited (e.g. to 0) and then no stock adjustment is queued; Cancel (close drawer) discards the duplicate (re-opening shows only the original variants); duplicating a duplicate works.
- [X] T010 [P] [US2] Extend `tests/Feature/VariantBomCopyTest.php` (or create `tests/Feature/VariantDuplicateFlowTest.php`): `POST /products/{id}/variants` with `copy_bom_from_variant_id` of a variant whose BOM is COMPLETE creates a new variant with the BOM rows copied, `bom_complete` false, the given `cost_price` kept (not overwritten), a NEW server-generated SKU, source untouched and independent (editing one BOM row does not change the other); the same call by a user without `purchase_orders` → 403 and no variant is created.

### Implementation for US2

- [X] T011 [P] [US2] Locale keys en + id (`master_data`, free names): `duplicate_variant` ("Duplicate variant"), `variant_copy_suffix` ("{name} (copy)"), `duplicate_bom_note` ("The BOM of {sku} will be copied when you save."), `duplicate_stock_note` ("Stock {qty} is copied and counts as new inventory when you save; change it if you only want the prices.").
- [X] T012 [US2] `resources/js/views/ProductsView.vue`: add `duplicateVariantRow(index)` building the seed per research.md D4 (`{ ...emptyVariant(), variant_name: t('master_data.variant_copy_suffix', { name }), cost_price, sell_price, low_stock_alert, current_stock: source.current_stock, original_stock: 0, is_active: true, copy_bom_from: (source.id && source.has_bom && auth.canAccessMenu('purchase_orders')) ? source.id : '', duplicated_from_sku: source.sku ?? null }`, `splice(index + 1, 0, seed)`); a "Duplicate variant" text button (`ph-copy` icon, secondary style) in the card footer; on a card with `duplicated_from_sku` show the BOM note (only when `copy_bom_from` is set) and the stock note (only when `Number(current_stock) > 0`); the existing `copy_bom_from` select stays visible for new rows so the user can clear it. Do NOT add the button to the header. Run T009.
- [X] T013 [US2] Run T009/T010 plus `APP_ENV=testing php artisan test --filter='VariantBomCopy|VariantDuplicate|Product'` and `npx vitest run`; all green.

**Checkpoint**: US1 + US2 delivered.

---

## Phase 5: User Story 3 — A BOM copy tool that works for many variants (Priority: P1)

**Goal**: fully reachable picker lists, pictures in the lists, **copy to chosen variants**, and Add BOM item on the Save row.

**Independent Test**: BOM dialog of a variant of a product with 8+ variants — open the picker at the bottom of the viewport, reach the last entry, see pictures, tick 3 variants (some with BOMs), confirm replacement, copy; only those 3 change; Add BOM item shares the row with Save changes.

### Tests for US3 (write first, expect failures)

- [X] T014 [P] [US3] Create `tests/Feature/VariantBomCopySelectedTest.php` (use `BuildsBomFixtures`; owner; a seller with a product of 6 variants, source with 2 BOM rows): `mode=selected` copies to exactly the listed variants and no other (`results` has one entry per id, others untouched); targets that already have rows → 409 `requires_confirmation` with `variants[]` naming them and nothing copied, then `confirm_replace=true` succeeds; a complete target is reopened and never completed; the source id in the list → 422; a variant of another product → 422; a non-existent id → 422; empty/missing `variant_ids` for `selected` → 422; duplicates in `variant_ids` → 422; a source without rows → 422; a user with only `products` menu → 403; `mode=all`/`next` still pass unchanged (ids ignored); one `bom_copied` audit row per target and none for untouched variants; all-or-nothing (force a failure on the 2nd target via an `eloquent.creating` listener → no target changed).
- [X] T015 [P] [US3] Extend `qa-tests/component/BaseSelect.test.js`: mock `getBoundingClientRect` of the trigger and `window.innerHeight`: with little space below and more above the panel opens upward (anchored by `bottom`, not `top`); with enough space below it opens downward (unchanged); the panel's inline `maxHeight` is capped to the available space (never taller than the space, between 140 and 320 px); options with a `thumb` key render a thumbnail (`<img>` for a URL, a placeholder icon for `null`) in the list and beside the selected label; options without the key render exactly as before (no thumbnail element).
- [X] T016 [P] [US3] Create `qa-tests/component/VariantPickList.test.js`: renders one row per variant with picture/placeholder, SKU, name and an "inactive" marker; ticking emits `update:modelValue` with the id array; "N selected" count; select-all / clear; search filters by SKU or name case-insensitively and shows the empty message; select-all applies only to the currently filtered rows; keyboard: rows are real checkboxes (Space toggles); the list has its own scroll container (max height) so it never overflows the dialog.
- [X] T017 [P] [US3] Extend `qa-tests/component/BomCopyMenu.test.js`: "Copy from another variant" options carry thumbnails (image or placeholder); a new **Copy to chosen variants** button reveals the pick list (siblings = the other variants, source excluded); the Copy button is disabled until ≥ 1 is ticked and shows the count; copying calls `copyBomOut(variantId, 'selected', false, [ids])`; when any ticked variant has `has_bom` the replace-confirmation dialog names exactly those variants first and `copyBomOut(..., true, ids)` runs only after confirming; the parent `guard` is invoked before the copy; the existing to-all / to-next / from flows are unchanged.
- [X] T018 [P] [US3] Extend `qa-tests/component/VariantBomModal.test.js`: **Add BOM item** and **Save changes** are in the SAME row container (add left, save right, `justify-between`), including when the unsaved indicator shows; with an empty BOM only Add is shown; the add button still opens the selector (guarded when dirty); read-only users see neither.

### Implementation for US3

- [X] T019 [US3] Backend: `app/Http/Requests/CopyBomRequest.php` — `mode` in `from,next,all,selected`; `variant_ids` `required_if:mode,selected`, `array`, `min:1`, `max:200`; `variant_ids.*` `integer|distinct|exists:product_variants,id`; `app/Services/VariantBomService.php::copyTargets($source, $mode, array $ids = [])` — new `selected` branch loading exactly those variants, ValidationException (422) when the loaded count differs from the unique id count; `app/Http/Controllers/Api/VariantBomController.php::copyOut()` — allow `selected` in the extra `in:` validation and pass `$request->input('variant_ids', [])`; add `copy_selected_empty`/`copy_selected_missing` messages to `lang/{en,id}/bom.php`. Update the docblocks (Indonesian). Run T014 until green.
- [X] T020 [P] [US3] `resources/js/api/materials.js`: `copyBomOut(variantId, mode, confirmReplace = false, variantIds = null)` sending `variant_ids` only for `selected`.
- [X] T021 [P] [US3] `resources/js/components/ui/BaseSelect.vue`: in `updatePanelPosition()` compute `spaceBelow`/`spaceAbove` (12 px margin), open upward when `spaceBelow < 220 && spaceAbove > spaceBelow` (`bottom: innerHeight - trigger.top + 6`), set inline `maxHeight = clamp(available, 140, 320)` and remove the fixed `max-h-64` class in favour of the inline value; support option `thumb` (render a 28 px rounded `<img>` or a `ph-image` placeholder before the label in rows and in the trigger label, only when some option has the `thumb` key). Indonesian comment citing the real cause (panel always opened below the trigger and ran off-screen). Run T015 + the full BaseSelect suite.
- [X] T022 [P] [US3] Create `resources/js/components/product/VariantPickList.vue` (props `variants: [{id, sku, variant_name, image_url, is_active, has_bom}]`, `modelValue: number[]`, `searchable` default true; search input, select-all/clear for the filtered rows, "N selected" count, scroll container `max-h-[260px] overflow-y-auto overscroll-contain`, rows are labelled checkboxes with a 36 px picture/placeholder, SKU in mono, name, inactive/has-BOM markers; tokens only; Indonesian docblock). Run T016.
- [X] T023 [US3] `resources/js/components/product/BomCopyMenu.vue`: add `thumb: v.image_url ?? null` to `copySources` options; add the **Copy to chosen variants** button + inline `VariantPickList` (`pickOpen`, `pickedIds`) and a Copy button (`N` count, disabled at 0) that goes through `props.guard` and the existing `ask()`/`execute()` confirmation with `copyBomOut(props.variantId, 'selected', c, pickedIds)` and the picked variants' `has_bom`; reset selection after a successful copy; keep to-all/to-next/from untouched. Run T017.
- [X] T024 [US3] `resources/js/components/product/VariantBomModal.vue`: replace the separate Save bar and Add button block with ONE row `flex flex-wrap items-center justify-between gap-3` — Add BOM item (left, `v-if="canEdit"`), then (right) the unsaved indicator + Save changes (`v-if="rows.length"`); keep the `guard`/disabled/loading behaviour; the copy menu stays below. Run T018.
- [X] T025 [P] [US3] Locale keys en + id (`master_data`, free names): `bom_copy_to_chosen` ("Copy to chosen variants"), `bom_copy_to_count` ("Copy to {count} variant(s)"), `bom_pick_selected` ("{count} selected"), `bom_pick_select_all`, `bom_pick_clear`, `bom_pick_none` ("No variants match."), `variant_inactive_marker`; run `localeKeys`.
- [X] T026 [US3] Run `APP_ENV=testing php artisan test --filter='VariantBomCopy|VariantBomCopySelected'` and `npx vitest run`; all green.

**Checkpoint**: all three stories delivered.

---

## Phase 6: Polish & Cross-cutting

- [X] T027 [P] `docs/openapi-pos-mvp.yaml`: document `mode: selected` + `variant_ids` on `POST /variants/{variant}/bom/copy-out` (request, 200/403/409/422) per `contracts/bom-copy-selected.md`; validate the YAML parses.
- [X] T028 [P] `CLAUDE.md`: add a short "Variant drawer / BOM copy tool (feature 037)" section (BaseSelect flips/caps height + `thumb`; `BaseTooltip`; sky/violet tokens and the chip colour map; Duplicate = unsaved seed reusing `copy_bom_from` + stock-adjustment path, copies the source stock by decision, never the picture/SKU; `mode=selected`). Keep the Active-feature block already written.
- [X] T029 [P] `README.md` "bugs found during execution" entry for 037 — only for defects actually found while implementing/verifying (e.g. the picker running off-screen is the reported one); skip otherwise.
- [X] T030 Full verification: `APP_ENV=testing php artisan test` (whole suite, host) and `npm test`; both green; report any pre-existing failure separately.
- [X] T031 Real-browser verification per `quickstart.md` on an ISOLATED server (`APP_ENV=testing php artisan serve --port=8091`, test DB, `license:dev-activate` on the TEST DB, `npm run build` first; seed a product with 9+ variants, BOMs on most, a few pictures via a scratch script): quickstart items 1–7 (card layout at 1440 px, chip colours/order, tooltip hover + keyboard, duplicate save/cancel/complete-source, picker at the bottom of the viewport opens upward/fits and scrolls to the last entry with pictures, copy to 3 chosen variants with replace confirmation, Add BOM item on the Save row, EN ↔ ID, console clean, cashier blocked). Screenshots (no customer data) to `specs/037-variant-drawer-bom-ui/evidence/`.
- [X] T032 Remove scratch seed scripts and `.playwright-mcp` copies, mark every completed task `[X]` here, and prepare the commits (Indonesian messages: docs commit for the spec set, then the feature commit) — commit only on the user's request; push/PR/merge only when explicitly asked.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002 ∥ T003; T004 after T003) → stories.
- **US1** first (MVP). **US2** after US1 (both edit `ProductsView.vue`; T012 after T007). **US3** is independent of US1/US2 (BaseSelect, BomCopyMenu, VariantPickList, VariantBomModal, backend) and can run in parallel with them; within US3: backend T019 before the UI that calls it only for browser/E2E, the component tests mock the API so T020–T025 can proceed in parallel.
- Polish after all stories; T031 needs the built SPA.
- Recommended order: Phase 1–2 → US1 → US2 → US3 → Polish (or US3 first if the off-screen picker is the most urgent).

### Within a story

Tests first (fail) → backend/rule → shared components → view wiring → run the story's tests.

### Parallel opportunities

- T002 ∥ T003; T005 ∥ T006; T009 ∥ T010 ∥ T011; T014 ∥ T015 ∥ T016 ∥ T017 ∥ T018; T020 ∥ T021 ∥ T022 ∥ T025; T027 ∥ T028 ∥ T029.
- US3 (T014–T026) can be worked in parallel with US1/US2 by a second worker: no file overlap (US3 never edits `ProductsView.vue`; US1/US2 never edit `BomCopyMenu`, `BaseSelect`, `VariantBomModal`).

## Implementation Strategy

- **MVP = Phases 1–3 (US1)**: the clearer, wider card with distinct chips and the new action layout.
- Then US2 (duplicate) and US3 (BOM copy tool; the reported off-screen picker defect lives here — do it first if it is the most urgent).
- Stop at each checkpoint and run that phase's tests plus the T001 baseline; never loosen an existing assertion — change an expectation only where the old position/size/wording was deliberately replaced.
