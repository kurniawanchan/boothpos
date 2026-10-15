# Research: Products List — Larger Image with Code, and SKU Tooltip

## D1 — One combined column

- Today: `columns` = `image_url` (blank header) + `code_prefix` ("Code") + `sku` + … ; the image cell is `h-14 w-14` (036) and the code cell is a one-line mono span.
- **Decision**: remove the `image_url` column; render image + code inside `#cell-code_prefix` as a vertical stack (`flex flex-col items-center gap-1.5`), image first. Header unchanged ("Code"). Existing behaviour kept: click on the picture opens the lightbox; no-image placeholder same size; code `whitespace-nowrap truncate max-w-[…]` with `title` for the full code.
- **Size**: `h-24 w-24` (96 px) — +71 % over 56 px, ≥ the 84 px floor (SC-001), `object-cover`, rounded, bordered as today. Rationale: large enough to recognise a design, small enough that a 25-row page stays scannable (row ≈ 130 px). Placeholder icon scaled (`text-[30px]`).
- **Width**: the picture column (≈ 88 px incl. padding) is deleted; the code column grows to fit the 96 px picture (≈ 140 px vs ≈ 104 px now). Net ≈ −50 px. Verified in the browser (SC-002) rather than assumed, because 037 showed this table already overflows at 1100 px with the sidebar open.
- Alternatives: picture left of the code in one cell (rejected: the code, already a one-line mono string, would force the column wider — defeats the purpose); bigger than 96 px (rejected: rows become too tall).

## D2 — Why `BaseTooltip` must change (clipping)

- `BaseTooltip` (037) renders the bubble `absolute` inside an `inline-flex relative` wrapper. `DataTable` wraps the table in `<div class="overflow-auto">`; any absolutely-positioned descendant that extends beyond that box is clipped (and the last row's bubble would add a scrollbar). The Open BOM tooltip never hit this because it lives in the drawer's scroll area with room around it.
- **Decision**: render the bubble through `<Teleport to="body">` with `position: fixed`, coordinates from `wrapper.getBoundingClientRect()` computed when it opens (mouseenter / focusin):
  - default below the trigger (`top = rect.bottom + 6`), flipped above (`bottom = innerHeight − rect.top + 6`) when there is < 90 px below and more room above;
  - horizontally `left = rect.left` (or right-aligned to `rect.right` for `align="right"`), then clamped to `[8, innerWidth − 8 − width]`;
  - width fixed `w-64` (256 px) as today; long names wrap.
- Keep `v-show` + stable `id` + `aria-describedby` on the wrapper (bubble stays in the DOM, hidden), so screen readers still get the description and 037's tests/selectors (`getByRole('tooltip', {hidden:true})`, `aria-hidden`) remain valid.
- Close on `scroll` (capture) / `resize` while open (like `BaseSelect`) so a fixed bubble never floats over the wrong spot after the table scrolls.
- Empty/blank `text` → the bubble is never shown (FR-007).
- Alternatives: `overflow: visible` on `DataTable` (rejected: breaks horizontal scrolling of the table); native `title` (rejected: not keyboard-accessible, unstyled — the reason `BaseTooltip` exists).

## D3 — SKU tooltip content and interaction

- Text = `variant.variant_name` (already in the list payload via `with_variants=1`; `Standard` is a real name). SKU stays visible so same-named variants are still told apart (spec edge case).
- Wrapping the existing `<button>` in `BaseTooltip` does not change its click handler (`openVariantDetail`); the wrapper adds no pointer interception. The "+N more" toggle button is not wrapped. Expanded SKUs use the same loop, so they get tooltips automatically.
- The comma separators (`<span>,</span>`) stay outside the tooltip wrapper.
- Touch: no hover; tap keeps opening the detail (tooltip is a convenience).

## D4 — Tests to update

- 036's `ProductsView.test.js` asserts thumbnail `h-14 w-14` and a code cell with `whitespace-nowrap`/`title` → change the size classes to `h-24 w-24`; keep the intent. `getByAltText` queries still work. The Type-header test is unaffected.
- `BaseTooltip.test.js` (037) stays valid; add positioning tests with a mocked `getBoundingClientRect` and `innerHeight/innerWidth` (same style as the `BaseSelect` tests).
