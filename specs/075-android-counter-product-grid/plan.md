# Implementation Plan: Android Counter — Product Grid with Variant Picker

**Classification**: INTERNAL

**Branch**: `075-android-counter-product-grid` | **Date**: 2026-10-26 | **Spec**: [spec.md](spec.md)

## Summary

The Counter grid on the Android tablet (`android-pos/`) is built from one card per **variant** (`MenuMapper.toCatalog`) and the card never draws the photo it already receives (`MenuCard` shows a tone tile + icon). This feature (1) groups variants into one card per **product** with a variant-count badge, price range and total stock, (2) opens a variant picker sheet when a multi-variant product is tapped, (3) draws the real product/variant photo from the server's `image_url` with an initials fallback, and (4) aligns the totals block with the reference (always-present red Discount row, dashed divider). **No server change**: `GET /products?pos=1&with_variants=1` already returns `image_url`, `sku`, `current_stock`, `outlet_stock`, `track_stock`, and `category_name`; the Android DTO just does not read them yet. Order lines, sold-out, option groups, and all money stay variant-level and server-computed.

## Technical Context

**Language/Version**: Kotlin, Jetpack Compose, Material 3 (module `android-pos/app`, DTOs in `android-pos/core-network`)
**Primary Dependencies**: existing only — OkHttp, kotlinx.serialization, Retrofit. **No new library** (see research R2).
**Storage**: none new; menu is in memory (`PosStore.catalog`); photos cached in memory (bounded LRU).
**Testing**: JUnit unit tests in `android-pos/app/src/test` (pattern: `MenuMapperCategoriesTest`, `PosStoreMenuTest`, `FakePosBackend`); on-device check per `quickstart.md`.
**Target Platform**: Android tablet (primary) and phone (Size.COMPACT), existing `menuColumns()`.
**Project Type**: mobile-app client of the existing Laravel API.
**Performance Goals**: smooth scroll of ~100 cards with photos; each photo downloaded once per menu load; decoded at card size, not full size.
**Constraints**: offline/failed photo must never block ordering; sold-out rule stays server-owned (`MenuAvailability`); copy in Indonesian.
**Scale/Scope**: one outlet menu ≈ 80–150 products, ≤ ~6 variants each; 1 screen (Counter editor), 1 new sheet.

## Constitution Check

| Principle | Status | Note |
|---|---|---|
| I Code quality / single path | PASS | Grouping lives in `MenuMapper` (one place). Orders still go through `PosStore.addLine`; sold-out stays `MenuAvailability` via the availability endpoint — no client-side stock rule invented. Image URL rewrite reuses `ServerUrl.resolve` (no second copy). |
| II Testing | PASS (plan) | Unit tests for grouping/price range/stock/sold-out/fallback; on-device check for the visual states (SC-006). No backend change → no PHP tests. |
| III UX consistency | PASS | Indonesian copy; follows web POS card/list; role gating unchanged (no new controls). |
| IV Security | PASS | Money/totals still server-computed (FR-015); displayed range is display-only. Photo URLs are public-disk files (same class as QR/logo, 056/059); no token sent to the image host. Outlet scope unchanged (`pos=1`). |
| V Performance | PASS | No new endpoint or payload; bounded image cache, downsampled decode; lazy grid already used. |
| Docs discipline | PASS | No route/response change → OpenAPI untouched. |

No violations; Complexity Tracking not needed. Re-checked after design: unchanged.

## Project Structure

### Documentation (this feature)

```text
specs/075-android-counter-product-grid/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/ui-contract.md
└── tasks.md   # /speckit-tasks
```

### Source Code (files touched)

```text
android-pos/
├── core-network/src/main/java/com/warkop/core/network/dto/MenuDtos.kt      # VariantDto += sku, current_stock, outlet_stock, track_stock
├── app/src/main/java/com/warkop/pos/
│   ├── model/PosModels.kt             # Product += code/stock/variantName; new ProductEntry; MenuCatalog += entries
│   ├── data/MenuMapper.kt             # group variants → ProductEntry; resolve image URLs; price range inputs
│   ├── data/RemoteBackend.kt          # pass serverBase into toCatalog (same as QrisMapper)
│   ├── data/MenuImageLoader.kt        # NEW: bounded LRU, downsampled decode, null on any error
│   ├── state/PosStore.kt              # Sheet.VariantPicker; tapEntry(); pick closes picker
│   ├── ui/screens/EditorScreen.kt     # entry card, search keeps variant cards, totals rows
│   ├── ui/Sheets.kt                   # VariantPickerSheet
│   └── ui/ProductPhoto.kt             # NEW: photo-or-initials composable
└── app/src/test/java/com/warkop/pos/
    ├── data/MenuMapperEntriesTest.kt, MenuImageLoaderTest.kt (NEW)
    └── state/PosStoreVariantPickerTest.kt (NEW)
```

**Structure Decision**: all changes inside `android-pos`; the web POS and Laravel API are untouched.
