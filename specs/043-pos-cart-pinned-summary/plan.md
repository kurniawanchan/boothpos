# Implementation Plan: POS Cart Summary Stays Visible

**Branch**: `051-pos-cart-pinned-summary` (stacked on `050-product-segment-1-3`) | **Date**: 2026-10-17 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `specs/043-pos-cart-pinned-summary/spec.md`

## Summary

On the POS screen the whole browser page grows with the product grid, so the cart's total and Pay button fall below the visible edge. The cause is the app shell: its outer container and content column are sized to content (`min-h-screen`, no fixed height), so the `<main>` scroll area never gets a bounded height and the document scrolls instead.

Fix: the POS route runs at **viewport height**. The shell fills the screen, the top bar stays fixed, and `<main>` becomes the single scroll area. The product column (already `overflow-auto`) and the cart item list (already `flex-1 overflow-auto`) scroll inside that height, and the cart summary stays pinned. Other routes keep today's behaviour.

The change is scoped with a route flag, not a global shell change, so the other 20+ screens are untouched.

## Technical Context

**Language/Version**: Vue 3 SPA (Vite, Tailwind v4 CSS-first tokens, Pinia, vue-router)
**Primary Dependencies**: none new
**Storage**: none (no data change)
**Testing**: Vitest component tests under `qa-tests/component/` (existing `AppShell.test.js` is the pattern); a real-browser check at booth sizes (Principle II — screen the user interacts with)
**Target Platform**: tablet landscape and laptop-sized browser windows (spec Assumptions)
**Project Type**: SPA (no backend change)
**Performance Goals**: none new; layout-only, no extra renders
**Constraints**: no raw hex (Principle III); other routes must render exactly as before; top bar, sidebar collapse animation and modals must keep working
**Scale/Scope**: 1 route meta flag, 1 shell change (conditional classes), 0–1 small class change in the cart panel, tests

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Notes |
|---|---|---|
| I. Code quality (single source per concern, no speculative abstraction) | **Pass** | One place decides "fixed-height screen": the route `meta` flag read by the shell. No per-view height hacks. No new component or abstraction. |
| II. Testing standards | **Pass (planned)** | Vitest for the shell's class switch (POS flagged vs others) in `qa-tests/component/AppShell.test.js`; route meta test; a browser check at booth sizes with console checked, since the POS screen is interacted with. |
| III. UX consistency | **Pass** | Design tokens only (`h-dvh` is a layout utility, no colours). Same error/empty states. Topbar and sidebar unchanged in look. |
| IV. Security | **N/A** | No data, access or permission change. |
| V. Performance | **Pass** | No extra queries or watchers beyond one computed class per shell render. |
| Documentation | **N/A** | No API change, so no OpenAPI change. CLAUDE.md agent-context pointer updated. |

**Gate result**: PASS. No NEEDS CLARIFICATION remain (device sizes recorded as an assumption in the spec).

## Project Structure

### Documentation (this feature)

```text
specs/043-pos-cart-pinned-summary/
├── spec.md
├── plan.md               # this file
├── research.md           # Phase 0
├── data-model.md         # Phase 1: states that matter (none persisted)
├── quickstart.md         # Phase 1: browser check at booth sizes
└── checklists/requirements.md
```

No `contracts/`: this is an internal layout change with no interface exposed to other systems.

### Source Code (repository root)

```text
resources/js/router/index.js                      # CHANGE: POS route meta gets fullHeight: true
resources/js/components/layout/AppShell.vue        # CHANGE: fixed viewport shell + <main> scroll area when route.meta.fullHeight
resources/js/components/pos/PosCartPanel.vue       # VERIFY (already flex-1 overflow-auto list); change only if the summary is not flex-none
resources/js/views/PosView.vue                     # VERIFY root "flex h-full" fills main; no logic change
qa-tests/component/AppShell.test.js                # ADD: fixed-height classes only when the route is flagged
qa-tests/unit/posLayout.test.js                    # ADD (optional): route meta assertion for pos
CLAUDE.md                                          # CHANGE: SPECKIT block points to this plan
```

**Structure Decision**: keep the layout decision in the shell (where the bug is) and the intent in the route (where a screen declares it needs a fixed height). The POS view keeps its own layout as is.

## Design Details

### 1. Route flag

`/pos` gets `meta: { menuKey: 'pos', fullHeight: true }`. No other route changes.

### 2. Shell (`AppShell.vue`)

Computed `fullHeight = route.meta.fullHeight === true`.

- Outer `div`: `min-h-screen` today → `h-dvh` when `fullHeight` (keep `min-h-screen` otherwise). `dvh` tracks the real browser viewport, including mobile toolbars.
- Content column (`flex min-w-0 flex-1 flex-col`): add `min-h-0` when `fullHeight` so it can shrink below its content.
- `<main class="min-h-0 flex-1 overflow-auto">`: unchanged classes; in `fullHeight` mode it becomes the only scroll area for the page. `AppTopbar` stays above it, so it is pinned.
- `SchemaUpdateBanner` (if shown) sits above `main`; it takes its own height, and `main` shrinks to fit.

The sidebar wrapper's width transition is unaffected.

### 3. POS view and cart

- `PosView` root `flex h-full`: fills `main`. The product column already scrolls (`overflow-auto`).
- `PosCartPanel` `<aside class="flex h-full ...">`: fills the row. Its item list already scrolls (`flex-1 overflow-auto`). Verify the summary block is `flex-none`; if not, add it. This is the only possible cart change.

### 4. Why not `position: sticky` or a fixed footer

The summary is inside the cart column, which is already a flex row. Making the whole row viewport-height is simpler and keeps the cart's existing structure, with no second copy of the summary and no overlap with the product grid.

## Complexity Tracking

No violations. Changes add one route flag and conditional classes in the shell.
