---

description: "Task list for feature 036 — BOM dialog, variant history, product and stock list refinements"
---

# Tasks: BOM, Variant History, and Product/Stock List Refinements

**Input**: Design documents from `/specs/036-bom-variant-stock-ux/`

**Prerequisites**: plan.md, spec.md, research.md, data-model.md, contracts/bom-batch-and-movements.md, quickstart.md

**Tests**: INCLUDED (Constitution II). Existing tests are updated only where the new rule intentionally changes behaviour (blur-save, whole numbers) — never loosened.

**Organization**: By user story in spec order (US1 BOM P1 → US2 copy picker P1 → US3 variant history P2 → US4 product list P2 → US5 stock list P2). Phase 2 holds what US3/US4/US5 share. US2 depends on nothing but `BaseSelect`; US1 and US2 both touch `BomCopyMenu`/`VariantBomModal` only in separate steps (do US1 first).

## Format: `[ID] [P?] [Story] Description`

- **[P]**: can run in parallel (different files, no dependency on an unfinished task)
- **[Story]**: US1..US5

## Conventions to honour

- Comments, `id` copy, commit messages in Indonesian; locale keys in BOTH `en.json` and `id.json` — check the key is free first (a repeated key inside one section silently overrides the earlier one). Backend messages in `lang/{en,id}/`.
- Backend tests on the HOST only: `APP_ENV=testing php artisan test --filter=…` (database `boothpos_test`, wiped each run). **Never** run tests inside the `app` container without the `-e` overrides, and never touch the dev database `boothpos`.
- BOM writes go through `VariantBomService` only (lock → write → audit → cost sync in ONE transaction); typed business errors via `BomRuleException` (409 + `code`).
- Design tokens only (no hex); status codes 422/409/403 handled by the shared client.
- `docs/openapi-pos-mvp.yaml` moves in the same commit as any route/response change.

---

## Phase 1: Setup

- [X] T001 Baseline on `036-bom-variant-stock-ux`: run `APP_ENV=testing php artisan test --filter='Bom|StockTest|MasterDataImport|VariantBom'` and `npx vitest run` and note the pass counts (expect all green) so later failures are attributable.

---

## Phase 2: Foundational (shared by US3, US4, US5)

**Purpose**: the missing i18n key, and the stock-movements API/util that both the variant history (US3) and the Stock list (US5) consume.

- [X] T002 [P] Create `qa-tests/unit/localeKeys.test.js`: statically scan every `*.vue`/`*.js` under `resources/js` (excluding `locales/`) for literal `t('a.b')` / `t("a.b")` keys and assert each exists in BOTH `locales/en.json` and `locales/id.json` (dynamic/template keys ignored; list all misses in the failure message). Expect it to FAIL on `master_data.col_type` before T003.
- [X] T003 [P] Add `master_data.col_type` ("Type" / "Tipe") to `resources/js/locales/en.json` and `id.json` (inside the `master_data` section; confirm the name is free). Re-run T002 → green.
- [X] T004 [P] Extend `tests/Feature/StockTest.php` (or create `tests/Feature/StockMovementsTest.php` if the file is already large) for `GET /stock/movements`: owner/admin/inventory → 200; a role with neither `stock` nor `products` menu (cashier) → 403; rows carry `user_name`, `variant_name`, `product_id`, `product_name`, `reference`; `reference` resolved per the data-model table: a sale made through `OrderService` → its order number, a void → the same order number, pre-order arrival/hand-over → pre-order number, an edited pre-order delta → pre-order number; `null` for adjustment/initial/import; a reference whose guard fails (id of another variant's item/order) → `reference: null` (never a wrong number); `variant_id`/`type`/date filters and ordering `created_at desc, id desc`; query count stays constant for a 25-row page (no N+1 — assert with `DB::getQueryLog`/`assertQueryCount`-style helper used elsewhere in tests).
- [X] T005 Create `app/Services/StockMovementReferences.php` (Indonesian docblock: why batch + the (type, reference_type) rules + the variant guard, per data-model.md): `resolve(Collection $movements): array<int, array{type,id,number}|null>` keyed by movement id, grouped queries (no per-row query) for: `sale`+`order_item` → order id; `return`+`order_item` → order-item id; `purchase`/`preorder_handover`+`preorder_item` → pre-order-item id; `purchase`+`preorder` → pre-order id; everything else `null`; keep the DEMO/LIVE scope (no `withoutGlobalScopes`); `null` whenever the guard (the order/item really involves the movement's variant) fails. ALSO change `PreorderService` edit-delta movement (~line 594) to `referenceType: 'preorder'` (pre-order id) and update any test asserting the old value.
- [X] T006 `app/Http/Controllers/Api/StockController.php::movements()`: gate `canAccessMenu('stock') || canAccessMenu('products')` → 403 `__('stock.not_authorized')`; eager-load `user`, `variant.product`; add `user_name`, `variant_name`, `product_id` (null if the product is gone), `product_name`, `reference` (via T005); order by `created_at desc, id desc`; keep the pagination envelope. Add `lang/en/stock.php` + `lang/id/stock.php` (`not_authorized`). Run T004.
- [X] T007 [P] Create `resources/js/utils/stockMovements.js` exporting `MOVEMENT_TYPE_VARIANT` (`purchase:mint, sale:neutral, preorder_handover:warn, adjustment:neutral, return:mint, initial:neutral`) and `movementTypeLabelKey(type)` → `master_data.type_<type>` (moved from `StockView.vue`, no behaviour change); add a small unit test `qa-tests/unit/stockMovements.test.js`.

**Checkpoint**: movements API is gated, richer and tested; the locale scan is green.

---

## Phase 3: User Story 1 — A BOM I can edit and save with confidence (Priority: P1) 🎯 MVP

**Goal**: whole-number quantities everywhere; edits are a draft saved by an explicit Save (batch, all-or-nothing); unsaved-changes guard; current stock shown read-only; cost/quantity labelled "per 1 product".

**Independent Test**: open a variant's BOM, edit two quantities → nothing saved until Save; enter `1.5` → refused; close with a draft → confirmation; read the stock card and the per-unit labels.

### Tests for US1 (write first, expect failures)

- [X] T008 [P] [US1] Create `tests/Feature/BomWholeQuantityTest.php`: `WholeBomQuantity` accepts `2`, `"2"`, `2.0`, `"11.0000"` and refuses `1.5`, `0.25`, `0`, `-1`, `""`, `"abc"`, `100000000`; the SAME refusal (422 + `bom.qty_whole` message, en/id) on `PUT /bom/{id}`, `POST /variants/{v}/bom/items` (`items.*.qty`), `POST /variants/{v}/bom` (legacy); the Excel `bom` sheet reports a per-row error for a fractional quantity and applies nothing (all-or-nothing preserved); a stored legacy fractional row (e.g. `2.5`) still costs correctly, is returned unchanged by `GET /variants/{v}/bom`, and a notes-only update to it still succeeds.
- [X] T009 [P] [US1] Create `tests/Feature/BomQuantitiesTest.php` for `PUT /variants/{variant}/bom`: saves several changed rows in one call and returns the standard payload; invalid row among valid ones → 422 `errors."lines.N.qty_needed"` and NOTHING saved; an id from another variant → 409 `bom_line_not_found`, nothing saved; unchanged values skipped (no `bom_qty_changed` row); one `bom_qty_changed` activity-log row per changed line inside the same transaction (force a failure after the first write to prove rollback); complete variant → `cost_price` re-synced once (`cost_price_synced` logged once) and totals match; duplicate ids → 422; `lines` empty or > 200 → 422; user without `purchase_orders` → 403; `summary.current_stock` equals the variant's stock on both `GET` and `PUT`, and no stock movement is ever written.
- [X] T010 [P] [US1] Update `qa-tests/component/VariantBomModal.test.js`: replace the blur-saves tests with the draft model — quantity shown as `11` (not `11.0000`; stored `2.5` shown as `2.5`); decimal/zero → inline error, Save disabled; editing marks the dialog "unsaved" and calls NO API; Save posts ONE `saveBomQuantities(variantId, [{id,qty_needed}])` with only changed rows and refreshes totals; a failed save keeps all drafts and marks the offending row; closing, add item, remove, copy, replace source, complete and reopen while dirty open the "discard changes?" `ConfirmDialog` and run the action only after confirming (cancel keeps the draft); current-stock card renders `summary.current_stock`; cost cards/column carry the "per 1 product" wording; a read-only user sees no Save and no inputs. Keep every other existing assertion.

### Implementation for US1

- [X] T011 [US1] Create `app/Rules/WholeBomQuantity.php` (implements `ValidationRule`; numeric, `>= 1`, `<= 99999999`, fractional part zero; message `bom.qty_whole`) and add `qty_whole` to `lang/en/bom.php` and `lang/id/bom.php`. Indonesian docblock: why not Laravel's `integer` (it rejects `"11.0000"`/`2.0`, exactly what the DB and Excel return).
- [X] T012 [US1] Apply the rule in `app/Http/Requests/UpdateBomItemRequest.php`, `StoreBomItemsRequest.php` (`items.*.qty`, still nullable → default 1) and `StoreBomLineRequest.php` (legacy create).
- [X] T013 [US1] `app/Services/MasterDataImportService.php` (`bom` sheet): validate the quantity with the same rule and report the standard per-row error (no partial apply); reuse the rule's own message so wording is not duplicated.
- [X] T014 [US1] Audit existing tests that use fractional quantities through HTTP/Excel (`tests/Feature/VariantBomLegacyTest.php` ~line 160, `MasterDataImportVendorMaterialTest.php` ~lines 115/196, any others found by `grep -rn "qty" tests | grep "\."`): change fixtures to whole numbers or assert the new refusal; leave model-level fractional fixtures (`BomCostTest` `2.5`) alone — they prove legacy rows still cost correctly.
- [X] T015 [US1] Create `app/Http/Requests/UpdateBomQuantitiesRequest.php` (`lines` array 1..200; `lines.*.id` integer, distinct; `lines.*.qty_needed` via `WholeBomQuantity`; `authorize()` true — the controller helper enforces the menu gate like every BOM mutation).
- [X] T016 [US1] `app/Services/VariantBomService.php`: add `updateQuantities(ProductVariant $variant, array $lines, User $user): ProductVariant` — one `DB::transaction`, `lockVariant`, load the variant's lines, 409 `BomRuleException('bom_line_not_found')` when an id is not one of them, skip unchanged values, apply, one `bom_qty_changed` audit row per changed line (same `oldValues/newValues` shape as `updateLine`), `syncCostPriceIfComplete` ONCE; extend `payload()` so `summary.current_stock` is the freshly read variant stock. Add the message to `lang/{en,id}/bom.php`.
- [X] T017 [US1] `app/Http/Controllers/Api/VariantBomController.php::updateQuantities()` (same `authorizeBom($request, true)` helper, `ruleViolation()` for the typed 409) and register `Route::put('/variants/{variant}/bom', …)` in `routes/api.php` next to the other `variants/{variant}/bom` routes. Run T008, T009 until green.
- [X] T018 [P] [US1] `resources/js/api/materials.js`: add `saveBomQuantities(variantId, lines)` (`PUT /variants/{id}/bom`).
- [X] T019 [P] [US1] Locale keys en + id (`master_data` section; check each is free): `bom_save`, `bom_saved`, `bom_unsaved`, `bom_discard_title`, `bom_discard_confirm`, `bom_discard`, `bom_current_stock`, per-unit wording for `bom_material_cost`/`bom_service_cost`/`bom_total`/`bom_cost_price`/`bom_cost_price_from_bom`/`bom_col_qty` ("… (per 1 product)" / "… (per 1 produk)"), and the whole-number message (`bom_qty_invalid`: "Enter a whole number of 1 or more").
- [X] T020 [US1] `resources/js/components/product/VariantBomModal.vue`: remove the blur commit; keep `qtyDrafts` as the draft; show whole numbers (trim trailing zeros via a small helper, e.g. `11.0000`→`11`, `2.5`→`2.5`); `validQty` = whole ≥ 1; `dirty` = any draft ≠ stored; **Save** button (editors only, disabled unless dirty and all valid, `:loading`) → `saveBomQuantities` with changed rows only → `apply()` + toast + `emit('changed')`; on 422 map `errors."lines.N.qty_needed"` to the row; on 409 reload the BOM and keep unaffected drafts; "unsaved changes" indicator; one `guard(fn)` that, when dirty, opens a `ConfirmDialog` and runs `fn` only after confirmation — wire it to the dialog close (intercept `BaseModal` `@close`), add item, remove, replace source (newer price + legacy), copy (`BomCopyMenu` `copied` is post-hoc, so guard its trigger via a `before` hook prop or by disabling copy while dirty with the same confirm), complete and reopen; stock card from `summary.current_stock`; labels per T019. Run T010.
- [X] T021 [US1] Run `APP_ENV=testing php artisan test --filter='BomWholeQuantity|BomQuantities|VariantBom|BomCost|MasterDataImport'` and `npx vitest run qa-tests/component/VariantBomModal.test.js qa-tests/component/AddBomItemModal.test.js qa-tests/component/BomCopyMenu.test.js`; all green.

**Checkpoint**: US1 demonstrable and shippable on its own.

---

## Phase 4: User Story 2 — Copy from another variant: search and scroll (Priority: P1)

**Goal**: the copy picker lists every eligible sibling in a scrollable, searchable, keyboard-operable list that the dialog cannot clip.

**Independent Test**: on a product with 8+ variants that have a BOM, open the picker, type a SKU fragment, clear it, scroll to the last entry (panel stays open), select by keyboard, copy.

### Tests for US2 (write first, expect failures)

- [X] T022 [P] [US2] Extend `qa-tests/component/BaseSelect.test.js`: a `scroll` event dispatched on the open panel does NOT close it, while a scroll/resize outside still does; `searchable` renders a text input (focused on open), filters case-insensitively on the label, shows `common.no_options` for no match, supports ArrowDown/ArrowUp/Enter/Escape from the input; WITHOUT `searchable` there is no input (existing callers unchanged); `modelValue` selection and `update:modelValue` contract unchanged.
- [X] T023 [P] [US2] Extend `qa-tests/component/BomCopyMenu.test.js`: with 12 siblings the picker offers all 12; typing narrows to matches by SKU and by variant name; picking a filtered result then pressing the copy button calls `copyBomFrom` with that id; the existing replace-confirmation and same-product rules still hold.

### Implementation for US2

- [X] T024 [US2] `resources/js/components/ui/BaseSelect.vue`: in `onScrollOrResize` ignore events whose target is inside `panelEl` (keep closing for outside scroll and resize); add `overscroll-contain` to the panel; add an opt-in `searchable` prop (default `false`) that renders a search `<input>` at the top of the panel (`aria-label` = placeholder via `common.search_placeholder`), filters the displayed options case-insensitively, keeps `activeIndex`/arrows/Enter/Escape working against the FILTERED list, and resets the query on close. Indonesian comment citing the real cause (window-level capture scroll listener closed the panel on its own scroll).
- [X] T025 [US2] `resources/js/components/product/BomCopyMenu.vue`: pass `searchable` to the "Copy from another variant" `BaseSelect`; no other behaviour change. Run T022/T023.
- [X] T026 [US2] Check that the teleported panel's input can take focus while a `BaseModal` focus trap is active (`resources/js/composables/useFocusTrap.js`); if the trap steals focus, treat the select panel as part of the trap (e.g. a `data-focus-trap-ignore`/containment check) with a test in `qa-tests/unit/useFocusTrap.test.js`; otherwise note "verified, no change" in research.md.
- [X] T027 [US2] Run `npx vitest run qa-tests/component/BaseSelect.test.js qa-tests/component/BomCopyMenu.test.js` plus the suites of every component that uses `BaseSelect` (`npx vitest run`) to prove the 8 existing callers are unaffected.

**Checkpoint**: US1 + US2 (both P1) complete.

---

## Phase 5: User Story 3 — Transaction history for each variant (Priority: P2)

**Goal**: from a variant row in the product detail, open a read-only, paged, filterable history of that SKU's movements with user and resolved reference.

**Independent Test**: open the history of a variant with a sale, an adjustment and a pre-order hand-over: newest first, user and reference shown, newest "after" equals current stock; empty variant → empty state; simulated failure → error + Retry.

### Tests for US3 (write first, expect failures)

- [X] T028 [P] [US3] Create `qa-tests/component/VariantHistoryModal.test.js` (mock `api/stock.listMovements`): requests `variant_id` + page; renders date/time, type pill (from `utils/stockMovements.js`), signed change (+/−, colour tokens), `before → after`, reason, `reference.number`, `user_name`; type and date-range filters re-query from page 1; pagination; empty state; load failure shows the message + **Retry** and Retry reloads; no filter/header shows a raw key (`localeKeys`).
- [X] T029 [P] [US3] Create `qa-tests/component/ProductDetailModal.test.js` (mock `api/products.getProduct`): each variant row has a **History** button that opens the history for THAT variant; the BOM button still works; History is hidden for a user without `stock`/`products` access.

### Implementation for US3

- [X] T030 [P] [US3] Locale keys en + id (`master_data` section, free names): `history`, `history_title` ("Transaction history — {sku}"), `history_empty`, `col_reference`, `history_load_failed` (reuse `common.retry`, `all_types`, existing `type_*`, `col_*`).
- [X] T031 [US3] Create `resources/js/components/product/VariantHistoryModal.vue`: `BaseModal`, `usePaginatedList(listMovements)` with `variant_id` fixed, type `BaseSelect` + two date inputs (same filter UX as `StockView`), table (Time, Type pill, Change, Before → After, Reference/Reason, By) using `utils/stockMovements.js`, `TablePagination`, empty state, error + Retry; Indonesian docblock (read-only view over the append-only ledger; same endpoint as the Stock screen so figures can never disagree).
- [X] T032 [US3] `resources/js/components/product/ProductDetailModal.vue`: add a **History** button beside **BOM** on each variant row (visible when `auth.canAccessMenu('stock') || auth.canAccessMenu('products')`), state `showHistory`/`historyVariant`, mount `VariantHistoryModal`. Run T028/T029.
- [X] T033 [US3] Run the US3 tests plus `APP_ENV=testing php artisan test --filter=StockTest`; all green.

**Checkpoint**: US3 demonstrable on its own.

---

## Phase 6: User Story 4 — A more readable Product list (Priority: P2)

**Goal**: larger thumbnail, one-line code, translated Type header.

**Independent Test**: Products list in EN and ID: thumbnails clearly larger, `SPF-KC-DMC` on one line, header "Type"/"Tipe", no horizontal scroll at tablet width.

- [X] T034 [P] [US4] Extend `qa-tests/component/ProductsView.test.js`: header shows "Tipe" (id) / "Type" (en) and never `master_data.col_type`; thumbnail `<img>` and the no-image placeholder carry the larger size classes (`h-14 w-14`); the code cell has `whitespace-nowrap` and a `title` with the full code; clicking the thumbnail still opens the lightbox.
- [X] T035 [US4] `resources/js/views/ProductsView.vue`: thumbnail `h-9 w-9` → `h-14 w-14` (img and placeholder, token classes), code cell `whitespace-nowrap truncate max-w-[…] title=code_prefix`; keep the "Type" column key; adjust column spacing so the row layout holds. Run T034.
- [X] T036 [US4] Visual check deferred to T046 (tablet width ≈ 1024 px, EN/ID).

---

## Phase 7: User Story 5 — Stock movements: SKU opens the product, proper header (Priority: P2)

**Goal**: click an SKU → product detail with that variant highlighted; Type header translated; BY column filled.

**Independent Test**: on the Stock screen click an SKU → detail opens with the variant highlighted, closing keeps list/filters/scroll; as a cashier-like role the SKU is plain text.

- [X] T037 [P] [US5] Create `qa-tests/component/StockView.test.js` (mock `api/stock`, `api/products`): header shows the translated Type name; BY column renders `user_name`; with `products` access the SKU is a button that opens `ProductDetailModal` for `product_id` and leaves the list/filters untouched on close; without `products` access the SKU is plain text; `product_id` null → toast `master_data.product_gone` and no dialog.
- [X] T038 [P] [US5] Extend `qa-tests/component/ProductDetailModal.test.js`: a `highlightVariantId` prop marks that variant row (`aria-current`/ring class) and scrolls it into view.
- [X] T039 [US5] `resources/js/components/product/ProductDetailModal.vue`: add `highlightVariantId` prop (ring token classes + `scrollIntoView({block:'nearest'})` after load).
- [X] T040 [US5] `resources/js/views/StockView.vue`: import `MOVEMENT_TYPE_VARIANT`/`movementTypeLabelKey` from `utils/stockMovements.js` (remove the local copies); SKU cell becomes a `<button>` (monospace as today) when `auth.canAccessMenu('products')`, otherwise plain text; local `detailProductId`/`detailVariantId` state mounts `ProductDetailModal` with `highlightVariantId`; `product_id` null → `toast.error(t('master_data.product_gone'))`; show `reference.number` before the reason in the Reference/Reason cell; the BY column now has data. Locale `master_data.product_gone` en + id. Run T037/T038.
- [X] T041 [US5] Run `npx vitest run qa-tests/component/StockView.test.js qa-tests/component/ProductDetailModal.test.js qa-tests/unit/localeKeys.test.js`; all green.

**Checkpoint**: all five stories delivered.

---

## Phase 8: Polish & Cross-cutting

- [X] T042 [P] `docs/openapi-pos-mvp.yaml`: document `PUT /variants/{variant}/bom` (request, 200/403/409/422), the whole-number quantity rule on `PUT /bom/{id}`, `POST …/bom/items`, `POST /variants/{variant}/bom`, `summary.current_stock` on the BOM payload, and the extended + gated `GET /stock/movements` (new row fields, 403). Validate the YAML parses.
- [X] T043 [P] `CLAUDE.md`: add a section "BOM quantities, batch save, variant history (feature 036)" — `WholeBomQuantity` is the single rule; `PUT /variants/{v}/bom` is all-or-nothing through `VariantBomService`; legacy fractions never rewritten; `GET /stock/movements` gate + shape + reference-resolution guard; `BaseSelect` scroll fix + `searchable`; `localeKeys` test. Keep the Active-feature block already written.
- [X] T044 [P] `README.md` "bugs found during execution": dated entries for (a) the missing `master_data.col_type` key, (b) `BaseSelect` closing on its own scroll, (c) the blank BY column / ungated movements endpoint, (d) the `PreorderService` edit path writing a pre-order id under `preorder_item` (record only; not fixed here).
- [X] T045 Full verification: `APP_ENV=testing php artisan test` (whole suite, host) and `npm test`; both green; report any pre-existing failure separately.
- [X] T046 Real-browser verification per `quickstart.md` on an ISOLATED server (`APP_ENV=testing php artisan serve --port=8091`, test DB `boothpos_test`, `license:dev-activate` on the TEST DB, `npm run build` first): items 1–7 of the quickstart (BOM draft/save/guard/stock/labels; copy picker search+scroll+keyboard on 8+ variants; variant history; Products list at ≈1024 px; Stock SKU click, filters preserved; cashier view; EN ↔ ID; console clean). Screenshots (no customer data) to `specs/036-bom-variant-stock-ux/evidence/`.
- [X] T047 Remove scratch seed scripts and `.playwright-mcp` copies, mark every completed task `[X]` here, and prepare the commits (Indonesian messages: docs commit for the spec set, then the feature commit) — commit only on the user's request; push/PR/merge only when explicitly asked.

---

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T002→T003; T004→T005→T006; T007 parallel) → stories.
- **US1** first (MVP; backend then frontend). **US2** is independent of US1's backend and can run in parallel with it, but `BomCopyMenu`/`VariantBomModal` edits (T020 vs T025) must not overlap in time. **US3** needs T006/T007. **US4** needs T003 only. **US5** needs T003, T006, T007 and T039 (shared `ProductDetailModal.vue`, so run after T032).
- Polish after all stories; T046 needs the built SPA.
- Recommended order: Phase 1–2 → US1 → US2 → US3 → US4 → US5 → Polish.

### Within a story

Tests first (fail) → rule/service/controller/lang → frontend → run the story's tests.

### Parallel opportunities

- T002 ∥ T003-prep ∥ T004 ∥ T007; T008 ∥ T009 ∥ T010; T018 ∥ T019; T022 ∥ T023; T028 ∥ T029 ∥ T030; T034 ∥ (US5 tests T037 ∥ T038); T042 ∥ T043 ∥ T044.
- US4 (T034–T036) has no overlap with other stories' files except `locales` (separate keys) and can be done anytime after T003.

## Implementation Strategy

- **MVP = Phase 1–3 (US1)**: whole-number quantities, explicit Save, stock + per-unit labels. Then US2 (copy picker, also P1), then the P2 stories in order (history → product list → stock list).
- Stop at each checkpoint and run that phase's tests plus the T001 baseline; never loosen an existing assertion — change a fixture only where the new whole-number rule deliberately changes behaviour (T014).
