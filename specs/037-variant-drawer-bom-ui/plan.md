# Implementation Plan: Variant Drawer and BOM Dialog Refinements

**Branch**: `037-variant-drawer-bom-ui` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/037-variant-drawer-bom-ui/spec.md`

## Summary

Almost entirely frontend, with one small, additive backend extension:

1. **Variant card (US1)** — restyle the `ProductsView` drawer's variant cards (white cards with a light border + soft shadow on a subtle tray, generous spacing), four distinct chip colours from new tokens (SKU blue, markup green, margin violet, BOM cost amber; negative values keep the danger style), a header action group `Open BOM · Apply markup · Delete` (Open BOM becomes a button with an accessible tooltip, new `BaseTooltip`), fields reordered name → stock → cost → sell in a 4-column grid, drawer widened 820 → 1040 px, variant picture 44 → 66 px (+50 %) with a same-size placeholder.
2. **Duplicate variant (US2)** — a "Duplicate variant" action on each card adds an unsaved card right below, seeded from the source (name with a copy marker, prices, alert, stock — per the clarification — and `copy_bom_from` pointing at the source when it is a saved variant with a BOM and the user may manage BOMs). It reuses the existing new-variant save flow end to end: `POST /products/{id}/variants` with `copy_bom_from_variant_id` (BOM copied in the same transaction, never marked complete) and the existing pending-stock-adjustment path (which already demands the shared reason). No new endpoint.
3. **BOM copy tool (US3)** — root cause of "cannot scroll to the bottom": `BaseSelect`'s fixed-position panel always opens BELOW its trigger with a fixed max height, so near the bottom of the viewport the list runs off-screen and nothing can scroll it. Fix in `BaseSelect` (flip above when there is more room there, and cap the height to the available space) plus opt-in option thumbnails (`thumb`). New **copy to chosen variants**: `POST /variants/{v}/bom/copy-out` gains `mode=selected` + `variant_ids[]`, reusing `VariantBomService::copy()` (all-or-nothing, same-product, confirm-replace) unchanged; UI is an inline searchable checkbox list with pictures (`VariantPickList`). **Add BOM item** moves onto the Save changes row.

One additive API change (`mode=selected`); no migration.

## Technical Context

**Language/Version**: PHP 8.3 / Laravel; Vue 3 SPA (Pinia, vue-i18n en/id, Tailwind v4 CSS-first tokens)

**Primary Dependencies**: none new

**Storage**: MySQL 8 — **no migration**

**Testing**: PHPUnit on the host (`APP_ENV=testing`, `boothpos_test`); Vitest + Testing Library; real-browser check on an isolated server with the test DB

**Target Platform**: Local Laravel app + SPA (native or Docker)

**Project Type**: Web application

**Performance Goals**: a 30-variant product renders and scrolls smoothly (cards are plain DOM, no per-card network call); the chosen-variants list filters client-side

**Constraints**: tokens only (new tokens declared in `@theme`, no hex in components); colour never the only meaning (chips keep text labels); contrast ≥ 4.5:1 (measured below); BOM writes stay in `VariantBomService`; status codes 422/409/403; DEMO/LIVE unchanged; OpenAPI moves with the API change; all copy en+id and `localeKeys` test stays green

**Scale/Scope**: 2 new small components (`BaseTooltip`, `VariantPickList`), edits to `ProductsView`, `BaseSelect`, `BomCopyMenu`, `VariantBomModal`, `BaseDrawer` call-site, `app.css` tokens, one request rule + one service method + one controller branch, locales, docs, tests

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1 design.*

| Principle | Assessment |
|---|---|
| I. Clean code / single write path | BOM copying still goes only through `VariantBomService::copy()`; "selected" adds a target-resolution branch in `copyTargets()` (one place), not a second copy path. Duplicate reuses the existing `copy_bom_from` mechanism instead of a new duplicate endpoint. Thumbnails/tooltip are shared UI primitives (`BaseSelect` option `thumb`, `BaseTooltip`) — no per-screen copies. PASS |
| II. Testing | Backend: `mode=selected` rules (same product, not self, all-or-nothing, confirm-replace, auth). Frontend: card layout/order, chips, tooltip, duplicate seeding + payload, picker flip/height, thumbs, pick list, Add/Save row. Real-browser run planned. PASS |
| III. UX consistency | Design tokens only (3 new colour pairs added to `@theme`); standard 422/409/403 handling; controls the role cannot use are hidden (BOM actions need `purchase_orders`; duplicate hides the BOM promise for users without it). Copy in Indonesian + English. PASS |
| IV. Security | Server validates `variant_ids` (exist, distinct, same product, not the source) and keeps the products + purchase_orders gate; duplicate's `copy_bom_from_variant_id` path already re-checks the gate and same-product rule server-side; stock still recorded via the audited adjustment path. PASS |
| V. Performance | Single request for copy-to-selected (one transaction, targets locked in id order); picker and pick list are client-side filters over data already loaded; no N+1. PASS |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/037-variant-drawer-bom-ui/
├── plan.md
├── research.md
├── data-model.md
├── quickstart.md
├── contracts/
│   └── bom-copy-selected.md
├── checklists/requirements.md
└── tasks.md        # created by /speckit-tasks
```

### Source Code (repository root)

```text
app/
├── Http/Requests/CopyBomRequest.php                 # mode: + selected; variant_ids[] (required_if selected)
├── Http/Controllers/Api/VariantBomController.php    # copyOut(): allow selected, pass ids
├── Services/VariantBomService.php                   # copyTargets(): + 'selected' branch
lang/{en,id}/bom.php                                 # copy_selected_* messages
docs/openapi-pos-mvp.yaml

resources/
├── css/app.css                                      # + sky / violet token pairs
├── js/components/ui/BaseTooltip.vue                 # NEW – hover/focus tooltip (role=tooltip)
├── js/components/ui/BaseSelect.vue                  # flip + height cap + option `thumb`
├── js/components/product/VariantPickList.vue        # NEW – searchable checkbox list with pictures
├── js/components/product/BomCopyMenu.vue            # "Copy to chosen variants", thumbs in "from"
├── js/components/product/VariantBomModal.vue        # Add BOM item on the Save row
├── js/api/materials.js                              # copyBomOut(..., variantIds)
├── js/views/ProductsView.vue                        # card layout, chips, actions, duplicate, thumbnail, widths
└── js/locales/{en,id}.json

tests/Feature/  VariantBomCopySelectedTest
qa-tests/component/ BaseSelect, BaseTooltip, VariantPickList, BomCopyMenu, VariantBomModal, ProductsView (variant card + duplicate)
```

**Structure Decision**: existing Laravel + Vue monolith; two new UI primitives only because two or more screens/sections need them.
