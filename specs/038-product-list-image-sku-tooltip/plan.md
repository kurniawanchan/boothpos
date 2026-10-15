# Implementation Plan: Products List — Larger Image with Code, and SKU Tooltip

**Branch**: `038-product-list-image-sku-tooltip` | **Date**: 2026-10-05 | **Spec**: [spec.md](./spec.md)

**Input**: Feature specification from `/specs/038-product-list-image-sku-tooltip/spec.md`

## Summary

Frontend-only; two edits in `ProductsView.vue` plus one hardening of the shared `BaseTooltip`:

1. **One combined first column (US1)** — drop the picture-only column; the `code_prefix` column's cell now stacks the picture (96 px, up from 56 px = +71 %) above the one-line code, centred. Header stays "Code". The merge removes one column (≈ 88 px of header + padding), so the table gets narrower, not wider.
2. **SKU tooltip (US2)** — wrap each SKU button in `BaseTooltip` with the variant name. **Key design point:** the list sits in `DataTable`'s `overflow-auto` wrapper, and `BaseTooltip` (037) positions its bubble `absolute` inside its own wrapper, so the bubble would be clipped by the table container (and would force scrollbars on the last row). `BaseTooltip` therefore switches to a **teleported, fixed-position bubble** computed from the trigger's rect when it opens (below the trigger, flipped above when there is no room, clamped inside the viewport) — the same technique `BaseSelect` already uses. It stays rendered-but-hidden so `aria-describedby` and the existing tests keep working, and it renders nothing when `text` is empty.

No API change, no migration, no new endpoint.

## Technical Context

**Language/Version**: Vue 3 SPA (Pinia, vue-i18n en/id, Tailwind v4 tokens); no backend change

**Primary Dependencies**: none new

**Storage**: none

**Testing**: Vitest + Testing Library; real-browser check on an isolated server with the test DB (width measured at 1100 px and 1440 px)

**Target Platform**: Local Laravel app + SPA

**Project Type**: Web application (frontend change)

**Performance Goals**: no per-SKU network call; ≤ ~150 hidden tooltip nodes on a 25-row page (one tiny span per visible SKU), positioning computed only on open

**Constraints**: tokens only; header/columns/sort/filters/paging/actions unchanged; SKU click still opens the variant detail; tooltip reachable by keyboard (`focusin`), Escape closes, `role="tooltip"` + `aria-describedby`; no empty tooltips; copy in en + id (no new strings expected: the header key `master_data.col_code` is reused)

**Scale/Scope**: 1 view edit (`ProductsView.vue`), 1 component edit (`BaseTooltip.vue`), tests; docs (CLAUDE.md, README only if a defect is found)

## Constitution Check

*GATE: passed before Phase 0; re-checked after Phase 1.*

| Principle | Assessment |
|---|---|
| I. Clean code / single source | Reuses the existing `BaseTooltip` (extended once, benefiting every caller) instead of a second tooltip; no duplicated column logic. PASS |
| II. Testing | Component tests for the combined cell, size, placeholder, one-line code, SKU tooltip (hover/focus/Escape/"+N more"/click still opens detail), and for `BaseTooltip` fixed positioning (below / flip above / viewport clamp / empty text); real-browser width measurement. PASS |
| III. UX consistency | Tokens only; same accessible tooltip pattern as "Open BOM"; no control hidden/disabled differently; no new copy. PASS |
| IV. Security | No data, permission or API change. PASS |
| V. Performance | Position computed on open only; hidden nodes are tiny; no extra requests. PASS |

No violations → Complexity Tracking not needed.

## Project Structure

### Documentation (this feature)

```text
specs/038-product-list-image-sku-tooltip/
├── plan.md
├── research.md
├── data-model.md        # states "no data change"
├── quickstart.md
├── contracts/ui-contract.md
├── checklists/requirements.md
└── tasks.md             # created by /speckit-tasks
```

### Source Code (repository root)

```text
resources/js/
├── components/ui/BaseTooltip.vue   # teleported fixed bubble, flip + clamp, empty text -> never shown
└── views/ProductsView.vue          # merge image+code column; SKU buttons wrapped in BaseTooltip

qa-tests/component/
├── BaseTooltip.test.js             # extend (positioning / empty text / teleport)
├── ProductsView.test.js            # update 036 assertions (56px -> 96px; one combined cell); add SKU tooltip cases
└── ProductImageUpload.test.js      # only if it asserts the old image column
```

**Structure Decision**: existing monolith layout; no new files besides tests.
