# Research: POS Cart Summary Stays Visible

## Decision 1 — Root cause is the shell, not the cart
- **Decision**: fix the height chain in `AppShell.vue`.
- **Rationale**: `AppShell` root is `flex min-h-screen`, content column has no height, and `<main>` is `min-h-0 flex-1 overflow-auto`. Because nothing bounds the column, `main` grows with content and the document scrolls. `PosView` root `h-full` and `PosCartPanel` `h-full` therefore resolve against an unbounded parent and the cart grows too.
- **Evidence**: the product column (`PosView`) and cart item list (`PosCartPanel`) already have `overflow-auto`, so only the outer chain needs bounding.
- **Alternatives**: make the cart `position: sticky` (rejected: sticky inside a growing page still needs the page height bounded to keep the summary on screen while the product list scrolls, and it would overlap the grid); a POS-only layout copy (rejected: duplicates the sidebar and topbar).

## Decision 2 — Scope via a route flag, not a global change
- **Decision**: `meta.fullHeight` on the POS route; the shell reads it.
- **Rationale**: a global `h-dvh` would move scrolling to `main` on every screen (Products, Sales, Reports…), which changes long pages that rely on page scroll and their sticky headers. A flag limits the change to the one screen the request is about.
- **Alternatives**: global change (rejected: wide blast radius); per-view height hack (rejected: the page still grows if the shell is unchanged).

## Decision 3 — `dvh` over `vh`
- **Decision**: `h-dvh` for the shell in fixed mode.
- **Rationale**: on tablets and phones `100vh` ignores browser toolbars, so the summary can be cut off. `dvh` follows the visible viewport.
- **Fallback**: Tailwind v4 provides `h-dvh`; browsers without `dvh` fall back to `min-height` behaviour, which equals today's layout (no regression).

## Decision 4 — Top bar stays fixed on POS
- **Decision**: the top bar sits above `main` in fixed mode and does not scroll.
- **Rationale**: with `main` as the scroll area, the top bar is outside it. This is a visible change on POS only (the title and "Mode" badge stay in view), accepted as part of "the bottom area stays visible" and to be confirmed in the browser check.

## Decision 5 — Cart summary placement
- **Decision**: no new structure; verify the summary is `flex-none` below the scrollable item list.
- **Rationale**: the list already scrolls (`flex-1 overflow-auto`), so the summary only needs to be a non-shrinking sibling.

## Open items
- Confirm the top bar may stay pinned on POS (Decision 4).
- Confirm device sizes (spec Assumptions).
