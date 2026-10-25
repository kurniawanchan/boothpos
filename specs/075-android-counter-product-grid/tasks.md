---
description: "Task list for 075 Android Counter product grid with variant picker"
---

# Tasks: Android Counter — Product Grid with Variant Picker

**Classification**: INTERNAL

**Input**: `specs/075-android-counter-product-grid/` (plan.md, spec.md, research.md, data-model.md, contracts/ui-contract.md, quickstart.md)

**Tests**: Included — Constitution II requires proof; unit tests under `android-pos/app/src/test/java/com/warkop/pos/`. Visual states are checked on-device via quickstart.md.

**Format**: `- [ ] T### [P?] [US?] Description with file path`. `[P]` = different files, no dependency on an unfinished task. Paths are relative to the repo root; `AP` = `android-pos/app/src/main/java/com/warkop/pos`, `AT` = `android-pos/app/src/test/java/com/warkop/pos`.

**Conventions**: code comments and UI copy in Indonesian, explaining *why* (match surrounding style). No server, OpenAPI or web change.

## Phase 1: Setup

- [X] T001 Confirm the baseline: run `cd android-pos && ./gradlew :app:testDebugUnitTest` and record pass/fail counts in `specs/075-android-counter-product-grid/baseline.md` so later failures can be attributed (pre-existing vs new).
- [X] T002 Verify against `app/Http/Resources/ProductVariantResource.php` and `docs/openapi-pos-mvp.yaml` that `sku`, `current_stock`, `outlet_stock`, `track_stock` are in the response schema; add them to the OpenAPI schema only if missing (contracts/ui-contract.md).

## Phase 2: Foundational (blocks all stories)

- [X] T003 Add `sku`, `current_stock`, `outlet_stock`, `track_stock` (all optional with safe defaults) to `VariantDto` in `android-pos/core-network/src/main/java/com/warkop/core/network/dto/MenuDtos.kt`.
- [X] T004 Extend `Product` (`code`, `variantName`, `stock: Int?`) and add `ProductEntry` (+ derived `priceLabel` inputs, `stock`, `isSingle`, `soldOut(set)`) and `MenuCatalog.entries` in `AP/model/PosModels.kt` per data-model.md. Update `AP/model/PosCatalog.kt` demo data so it still compiles.
- [X] T005 In `AP/data/MenuMapper.kt` group active variants into `ProductEntry` (skip products with 0 active variants); keep the flat `products` list unchanged for search/cart. Take `serverBase` and run every image URL through `ServerUrl.resolve` (R2). Stock = `outlet_stock ?: current_stock`, null when `track_stock == false` (R3).
- [X] T006 Pass the current server base into `MenuMapper.toCatalog` from `AP/data/RemoteBackend.kt` (`loadMenu`), the same way `QrisMapper` receives it.
- [X] T007 [P] Create `AP/ui/ProductPhoto.kt`: composable showing a photo slot with the initials tile fallback (photo loading is added in US3). Reused by cards and picker rows.
- [X] T008 [P] Tests in `AT/data/MenuMapperEntriesTest.kt`: one entry per product; products with no active variant dropped; price range vs single price; stock sum and untracked → null; every variant in exactly one entry; image URL host rewritten by `ServerUrl`; variant image falls back to product image. Update existing `MenuMapperCategoriesTest.kt` for the new signature.

**Checkpoint**: model and mapper compile, T008 passes; UI unchanged so far.

## Phase 3: User Story 1 — One card per product with variant indicator (P1) 🎯 MVP

**Goal**: grid shows each product once with badge, price range, total stock.
**Independent Test**: spec US1 / quickstart steps 3 and 8.

- [X] T009 [US1] Rewrite `MenuCard` in `AP/ui/screens/EditorScreen.kt` to take a `ProductEntry`: `ProductPhoto`, qty badge (sum over variants), "N varian" pill when N>1 (replaces "Pilihan" slot; "Pilihan" otherwise), "Habis" pill when all variants sold out, name, category, price/range label ("Rp a–Rp b"), "Stok N" omitted when untracked. Keep dim/hatched styling for sold out.
- [X] T010 [US1] In `EditorScreen`, feed the grid from `catalog.entries` filtered by category; keep search on the flat variant list rendered with the same card form (R5) so a specific variant is reachable (FR-011). Compute qty per entry from `qtyById`.
- [X] T011 [P] [US1] Add price-label and stock-label formatting helpers (pure functions, Indonesian, reuse `rp()`) in `AP/ui/CounterLabels.kt`, with tests in `AT/ui/CounterLabelsTest.kt` (equal prices, range, zero, untracked).
- [ ] T012 [US1] Phone width check: ensure the card fits `menuColumns()` for COMPACT without clipping (long name, badge + pill overlap) — adjust `EditorScreen.kt` only; verify per quickstart step 3 on the phone emulator.

**Checkpoint**: US1 shippable alone, except multi-variant cards need US2 to be orderable (until then tap does nothing for N>1 — do not release US1 without US2).

## Phase 4: User Story 2 — Tap product to choose variant (P1)

**Goal**: multi-variant tap opens picker; single adds directly.
**Independent Test**: spec US2 / quickstart steps 5–7.

- [X] T013 [US2] Add `Sheet.VariantPicker(entryId)` and `PosStore.tapEntry(entry)` in `AP/state/PosStore.kt`: N==1 → existing `tapProduct(variant)`; sold-out entry → existing "sedang habis" message; N>1 → open picker. Holds the id so a silent menu refresh updates the open sheet.
- [X] T014 [US2] Variant row pick → close picker then call existing `tapProduct(variant)` (keeps sold-out check and option-group sheet; FR-010). Row hold → existing `markSoldOut(variant)`. Hold on a multi-variant card does nothing.
- [X] T015 [US2] Implement `VariantPickerSheet` in `AP/ui/Sheets.kt` and route it from the sheet host (follow how `Sheet.Modifier` is dispatched, lines ~53–81): header (name, category, "pilih varian untuk ditambahkan ke order", close), scrolling rows with `ProductPhoto`, variant name, code, price, "Stok N", "Habis" mark and disabled state; scrim/close adds nothing.
- [X] T016 [US2] Wire `onTap = store::tapEntry` in `EditorScreen.kt` for entry cards; search cards keep calling `tapProduct` directly.
- [X] T017 [P] [US2] Tests in `AT/state/PosStoreVariantPickerTest.kt` (use `FakePosBackend`): single variant adds immediately; multi opens picker and adds nothing; picking adds one line and closes; sold-out variant not added; all-sold-out entry blocked; option-group variant opens `Sheet.Modifier` after pick; menu refresh keeps the picker on the same product.

**Checkpoint**: grid + picker fully orderable.

## Phase 5: User Story 3 — Real photos from saved images (P1)

**Goal**: cards and rows show the stored photo; initials only when none/failed.
**Independent Test**: spec US3 / quickstart steps 4 and 10.

- [X] T018 [US3] Create `AP/data/MenuImageLoader.kt` modelled on `QrImageLoader`: byte-bounded LRU keyed by resolved URL, `inSampleSize` downsample to target size, null on any network/decode error, `clear()`; no auth headers (public disk, same class as QR/logo).
- [X] T019 [P] [US3] Tests in `AT/data/MenuImageLoaderTest.kt`: cache hit does not refetch; failure → null and not cached; LRU evicts past the byte bound; `clear()` empties (use a fake fetcher — keep the HTTP call injectable).
- [X] T020 [US3] Make `ProductPhoto` (`AP/ui/ProductPhoto.kt`) load via `MenuImageLoader` (`produceState`), show the photo with `ContentScale.Crop`, fall back to initials while loading/failed with no error popup; use it in both `MenuCard` and the picker rows (variant photo → product photo fallback already done in the mapper).
- [X] T021 [US3] Clear the image cache on pull-to-refresh (`store.refreshCounter` in `AP/state/PosStore.kt`) so an overwritten same-name file cannot stay stale (R7, FR-018); do not clear on silent background refreshes.
- [ ] T022 [US3] Scroll check on a tablet with the WJM catalog (~100 cards): no flash back to initials for already-loaded photos, no OOM; note result in the PR description (quickstart step 4).

## Phase 6: User Story 4 — Totals like the reference (P2)

**Goal**: Subtotal, red Diskon, tax with rate, dashed divider, big Total; no editable rupiah field (FR-014).
**Independent Test**: spec US4 / quickstart step 9.

- [X] T023 [US4] In `OrderPane` (`AP/ui/screens/EditorScreen.kt`, totals block ~lines 176–184) always show the Discount row ("Rp 0" when none, red `error` colour when > 0, keep `discountHeading`), add a dashed divider above Total, keep Total large bold; leave `taxLine`, hide/show-totals (055) and the Terbayar row as they are.
- [X] T024 [P] [US4] Extract the discount-row label/amount decision into a pure helper in `AP/ui/CounterLabels.kt` and test it in `AT/ui/CounterLabelsTest.kt` (empty order all "Rp 0", discount shows negative amount).

## Phase 7: Polish & cross-cutting

- [X] T025 Run the full `./gradlew :app:testDebugUnitTest` (and `:core-network` tests); compare with T001 baseline; report any new failure.
- [ ] T026 Execute quickstart.md steps 1–10 on a tablet and a phone-size emulator; record results (and screenshots) in `specs/075-android-counter-product-grid/evidence.md`. SC-005 (totals unchanged) and R4 (zero-stock vs availability) must be explicitly confirmed or reported as a deviation.
- [X] T027 Add a dated note (2026-10-26) to `CLAUDE.md` "Android POS" domain rule: grid is per-product (`ProductEntry`), order unit stays the variant, photos via `MenuImageLoader` + `ServerUrl.resolve`; and a line in `docs/RUNBOOK.md` only if a new operator-visible behaviour (photo cache refresh) needs it.

## Dependencies & Execution Order

- Phase 1 → Phase 2 (T003→T004→T005→T006 sequential; T007, T008 parallel after T004/T005).
- US1 (T009–T012) needs Phase 2. US2 needs US1's card (T009–T010). US3 needs T007 and can start after Phase 2 in parallel with US1/US2 (T018, T019 are independent of UI); T020 needs T009/T015 to exist for visible effect. US4 is independent of US1–US3 (touches only the totals block) and can run in parallel with them.
- Release gate: US1 + US2 together (a card with N>1 is not orderable without the picker). US3 and US4 may land separately.

## Parallel Examples

- After Phase 2: T011 (labels), T018+T019 (loader), T024 (totals helper) touch different files and can run together.
- `EditorScreen.kt` is edited by T009, T010, T012, T016, T023 — do these sequentially.

## Implementation Strategy

1. MVP = Phase 1–2 + US1 + US2 (orderable product grid). Verify with quickstart steps 3, 5–8.
2. Add US3 (photos) — the most visible value; then US4 (totals).
3. Polish: full suite, device walk-through, docs note.

**Totals**: 27 tasks — Setup 2, Foundational 6, US1 4, US2 5, US3 5, US4 2, Polish 3.
