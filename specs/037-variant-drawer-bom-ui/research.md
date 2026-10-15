# Research: Variant Drawer and BOM Dialog Refinements

Findings come from reading the code in this session.

## D1 — Why the copy picker "cannot scroll to the bottom"

- `BaseSelect` positions its teleported panel with `position: fixed; top = trigger.bottom + 6px` and a fixed `max-h-64` (256 px). It never checks the space available. The BOM copy panel sits at the bottom of the dialog, so near the bottom of the viewport the list extends below the screen edge; being `fixed`, it cannot be reached by scrolling the page or the dialog. (036 fixed a different bug — closing on its own scroll — which let the list scroll internally, but the panel itself could still be off-screen; seen in the 036 browser screenshot where the panel hugs the bottom edge.)
- **Decision**: in `updatePanelPosition()` compute `spaceBelow = innerHeight − trigger.bottom − 12` and `spaceAbove = trigger.top − 12`; open **upwards** when `spaceBelow < 220` and `spaceAbove > spaceBelow` (anchor with `bottom:`), and set an inline `maxHeight = clamp(available, 140, 320)` so the list always fits on screen and scrolls internally. Applies to every `BaseSelect` (all callers benefit; none change API).
- Alternatives: render the list inline in the BOM dialog (rejected: a different widget and the dialog body would grow unpredictably); enlarge `max-h` (rejected: makes the off-screen problem worse).

## D2 — Pictures in the copy lists

- Siblings already carry `image_url` (`ProductDetailModal` passes `product.variants`; `ProductsView` passes `variantRows` spread from `GET /products/{id}`).
- **Decision**: `BaseSelect` options may carry `thumb` (URL or `null` = placeholder). If any option has the key, every row (and the trigger label of the chosen option) renders a 28 px rounded thumbnail/placeholder before the label — opt-in, default unchanged. "Copy from" uses it. "Copy to chosen" uses the new `VariantPickList` (checkbox rows with the same thumbnail).

## D3 — Copy to chosen variants

- `VariantBomService::copy($source, iterable $targets, bool $confirmReplace, User)` already accepts ANY set of targets and enforces: source has rows, target ≠ source, same product, confirmation naming targets that have rows, never marks complete, one transaction, targets locked in id order, `bom_copied` audit.
- **Decision**: no new write path. `CopyBomRequest` gains `mode=selected` with `variant_ids` (array 1..200, integers, distinct, `exists:product_variants,id`); `VariantBomController::copyOut()` passes them to `copyTargets($variant, 'selected', $ids)`, which loads exactly those variants (a count mismatch — e.g. a deleted variant — is a 422) and lets `copy()` enforce the rest. Response unchanged (`{results}`), 409 `requires_confirmation` unchanged.
- UI: inline `VariantPickList` (search, select-all/clear, "N selected", pictures, inactive marker) revealed by a "Copy to chosen variants" button in `BomCopyMenu`; the existing replace-confirmation dialog is reused with the selected variants' `has_bom` flags; the parent's unsaved-changes `guard` wraps it like the other modes.

## D4 — Duplicate variant reuses the new-variant flow

- Saving a card without an id already calls `POST /products/{id}/variants` with `copy_bom_from_variant_id` when `row.copy_bom_from` is set (034), which copies the BOM server-side in the same transaction (permission re-checked: products + purchase_orders; same product; never complete). Stock of new rows already flows through `queueStockAdjustment()` → `POST /stock/adjustments` with the shared reason field (`hasStockChanges` compares `current_stock` to `original_stock`, which is 0 for a new row).
- **Decision**: `duplicateVariantRow(index)` splices a seed `{ …emptyVariant(), variant_name: "<name> (copy)", cost_price, sell_price, low_stock_alert, current_stock: source.current_stock, original_stock: 0, is_active: true, copy_bom_from: <source.id> only when source is saved AND has_bom AND user has purchase_orders }` right below the source. Because `original_stock` is 0, copying stock 6 shows the existing "stock adjustment reason" field and records an adjustment of +6 for the new SKU — i.e. "counts as new inventory", made explicit by a note on the card (FR-011). The picture, SKU and history are not copied (FR-013). When the product itself is new (no ids yet) nothing can reference a BOM, so only fields are copied.
- A complete source: `cost_price` is the BOM-driven value; the copy gets that value (editable, not locked) and the BOM rows, `bom_complete` stays false (server `copy()` guarantee) — FR-012.
- No unique constraint exists on `variant_name`, so a name collision is not an error today; the "(copy)" suffix just makes copies recognisable.
- Alternatives: a dedicated `POST /variants/{id}/duplicate` endpoint (rejected: would save immediately and bypass the drawer's save/guard/stock-reason flow, duplicating logic).

## D5 — Colour system for the four chips

New `@theme` tokens (contrast measured, text on its background):

| Chip | Text | Background | Contrast |
|---|---|---|---|
| SKU (new `sky`) | `#1f5f8b` | `#e6f0f7` | 5.92 |
| Markup (existing mint) | `#1e6b4b` (brand-active) | `#e4f3ec` (mint-100) | 5.62 |
| Margin (new `violet`) | `#5b3f99` | `#efeaf8` | 6.80 |
| BOM cost (existing warn) | `#8a6a1e` (warn-text) | `#fbf7ec` (warn-bg) | 4.71 |
| Negative markup/margin (existing danger) | `#a2534b` | `#fbedec` | 4.76 |

All ≥ 4.5:1; every chip keeps its text label (colour is not the only cue). Tokens: `--color-sky-text/--color-sky-bg`, `--color-violet-text/--color-violet-bg`.

## D6 — Card separation, order and sizes

- Group container becomes a subtle tray (`bg-surface-subtle`); each variant card is `bg-white`, `border-line-2`, `rounded-card`, `shadow-sm`, padding 5, vertical gap 5. The inactive-variant dimming stays.
- Header: chips left (wrap), actions right in the exact order requested: `Open BOM` (secondary sm button, `ph-stack` icon, tooltip) · `Apply markup` (secondary sm) · delete icon. **Duplicate is deliberately NOT in the header** so the requested order stays exact; it is a text button in the card footer (next to the picture row): "Duplicate variant".
- Fields: `grid grid-cols-2 lg:grid-cols-4` → name (span 2 on small, 1.6fr on large) · stock · cost · sell. Stock moves out of its own row; the old stock+Apply-markup row is removed.
- Drawer: `max-w-[820px]` → `max-w-[1040px]` (+27 %, ≥ the 20 % target at 1440 px).
- Variant picture: `h-11 w-11` (44 px) → `h-[66px] w-[66px]` (+50 %); a same-size placeholder when there is no picture so cards stay aligned.

## D7 — Tooltip

- No tooltip component exists (only native `title`). Native `title` is not keyboard accessible and not styled.
- **Decision**: small `BaseTooltip` (wrapper + `role="tooltip"` bubble, shown on hover and `focus-within`, `aria-describedby` on the trigger wrapper, hidden by default, Escape closes). Used for Open BOM; reusable. Text: "A BOM (Bill of Materials) is the list of materials and services, with their cost, used to make ONE finished product. Open it to review or change it." (en + id).

## D8 — "Add BOM item" aligned with "Save changes"

- `VariantBomModal` today has the Save bar (right-aligned, only when rows exist) and the Add button in a separate block. **Decision**: one row `flex items-center justify-between`: Add BOM item left; the unsaved indicator + Save changes right (Save only when rows exist, so an empty BOM shows just Add). The copy tool stays below. Wraps at narrow widths.
