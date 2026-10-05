# UI Contract: Products list (038)

No endpoint changes. Observable UI contract (used by the tests):

## Combined first column

- Header: `master_data.col_code` ("Code"/"Kode"); there is NO column with an empty header before it.
- Cell: `[picture 96×96 | placeholder 96×96]` above `[code, one line, title=full code]`, centred.
- Picture is a `<button aria-label="enlarge product image …">` (opens the lightbox) when `image_url` exists.

## SKU cell

- Each SKU is `<button class="font-mono …">SKU</button>` inside a tooltip wrapper whose bubble (`role="tooltip"`) holds `variant_name`.
- Hover or focus shows the bubble; Escape/leave/scroll/resize hides it; click opens the variant detail.
- "+N more"/"show less" is not a SKU and has no tooltip.

## BaseTooltip (shared)

- Props: `text` (blank ⇒ never shown), `align` (`left`|`right`).
- Bubble: teleported to `body`, `position: fixed`, placed below the trigger (above when there is no room), clamped to the viewport, `w-64`, `role="tooltip"`, `aria-hidden` when closed; wrapper has `aria-describedby` → bubble id.
