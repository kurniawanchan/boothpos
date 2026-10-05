# Research: BOM, Variant History, and Product/Stock List Refinements

Findings come from reading the code in this session (not assumptions).

## D1 — `MASTER_DATA.COL_TYPE` is a missing translation key, not two bugs

- `ProductsView.vue:165` and `StockView.vue:62` both call `t('master_data.col_type')`; the key exists in neither `en.json` nor `id.json` (vue-i18n renders the upper-cased key path).
- A scan of every literal `t('a.b')` in `resources/js` against both locale files found **exactly one** missing key: this one. So a static test ("every literal key exists in both locales") can be added now and will pass after the fix (FR-016).
- **Decision**: add ONE key `master_data.col_type` = "Type" / "Tipe" — it reads correctly for both columns (Ready stock/Pre-order on Products; Sale/Adjustment/… on Stock). The spec's "each column gets its own header" assumption is satisfied in spirit (both translated); splitting into two keys would be unnecessary duplication. + `qa-tests/unit/localeKeys.test.js` (static scan, dynamic keys ignored).
- Alternatives: two keys (rejected: duplicate strings); a runtime missing-key handler (rejected: hides the bug instead of failing the build).

## D2 — "Can't scroll" in the copy picker is a `BaseSelect` bug

- `BaseSelect` registers `window.addEventListener('scroll', onScrollOrResize, true)` (capture) and closes on any scroll. Scrolling the list panel itself fires a scroll event whose target is the panel → the panel closes. Short lists never need scrolling, which is why nobody noticed; a product with 8+ variants does.
- **Decision**: ignore scroll events whose target is inside `panelEl`; keep closing for outside scroll/resize. Add `overscroll-contain` on the panel.
- **Search**: no searchable single-select exists (`BaseMultiSelect` has search but is multi-value). Add an opt-in `searchable` prop to `BaseSelect` (default `false` → 8 existing callers untouched): a text input at the top of the panel (focused on open), case-insensitive substring filter on label, arrows/Enter/Escape keep working, "no options" text reused (`common.no_options`). `BomCopyMenu` passes `searchable`.
- Alternative: reuse `BaseMultiSelect` with max 1 (rejected: different semantics/labels, would change copy flow); native `<select>`/datalist (rejected: OS-styled, project avoids it).
- Focus trap (T026) — read `useFocusTrap.js`: it only cycles Tab inside the dialog and never pulls focus back (no `focusin` handler), so the teleported search input keeps focus; the search input also stops Escape from propagating so the dialog is not closed with the panel. **Verified by reading, no change needed**; the real-browser run (T046) re-confirms.

## D3 — Whole-number BOM quantity: one rule, every entry point

- Entry points that write `qty_needed`: `UpdateBomItemRequest` (PUT /bom/{id}), `StoreBomItemsRequest` (`items.*.qty`, default 1), `StoreBomLineRequest` (legacy `POST /variants/{v}/bom`), the Excel `bom` sheet (`MasterDataImportService`), and copy (copies stored values; no new input).
- **Decision**: new `App\Rules\WholeBomQuantity` (numeric, ≥ 1, ≤ 99,999,999, and `value == floor(value)` so `2`, `"2"`, `2.0` pass and `1.5`/`0.25` fail). Laravel's `integer` is rejected because it fails `"11.0000"`/`2.0` (what the DB and Excel hand back). The Excel importer reports the same message as a per-row error (all-or-nothing import is unchanged).
- Legacy fractional rows (e.g. 2.5, tested in `BomCostTest` via the model) are **never rewritten**; cost keeps using the stored value; editing that row requires a whole number (the batch endpoint only receives changed rows). Display: show the stored value trimmed (`2.5`, `11`), not `11.0000`.
- Alternatives: a DB change to integer (rejected: destroys legacy data, needs migration); rounding silently (rejected by spec).

## D4 — Save button = one batch endpoint (all or nothing)

- Today each blur calls `PUT /bom/{id}` (one lock+transaction per row, partial state on failure).
- **Decision**: `PUT /variants/{variant}/bom` body `{lines:[{id, qty_needed}]}` → `VariantBomService::updateQuantities()`: lock the variant once, check every id belongs to this variant (else 409 `bom_line_not_found`; the dialog reloads), validate all quantities first (422 with `errors.lines.N.qty_needed`), apply, one audit row per changed line (`bom_qty_changed`, same shape as today), `syncCostPriceIfComplete` once, return the standard `payload()`. Lines whose value is unchanged are skipped (no log noise). The route is new (`PUT` on that URL was unused); `PUT /bom/{id}` stays for API compatibility with the stricter rule.
- Concurrency (two editors): last write wins on values (no optimistic version column — YAGNI for a single-store app); a row deleted meanwhile → 409 with reload; documented, not silent.

## D5 — "Add product stock" = read-only stock in the BOM payload

- Clarified with the requester: show current stock only. `payload()` already re-reads the variant; add `summary.current_stock` (integer, fresh). No stock mutation, no material deduction.
- Alternatives (add stock / produce from BOM) were offered and rejected by the requester; production deduction would also reopen the PRD-cut production scope.

## D6 — "Cost price is for one product" = labelling only

- Clarified: cost already means per ONE finished unit (BOM quantity is per unit). Locale-only change: cards "Material cost / Service cost / Total BOM cost / Cost price" gain "(per 1 product)"; the quantity column header becomes "Qty per 1 product"; keep the existing hint. No formula change.

## D7 — Variant history reuses `GET /stock/movements`

- The endpoint already supports `variant_id`, `type`, `date_from`, `date_to`, `per_page` (≤ 100), ordered by `created_at desc` (+ index `(variant_id, created_at)`). **Missing** today: `user_name` (the "BY" column in the existing Stock screen is blank — a latent bug visible in the requester's screenshot), `product_id`/names, a usable reference.
- **Decision**: extend the row shape (additive): `user_name`, `variant_name`, `product_id`, `product_name`, `reference: {type,id,number}|null`. Eager-load `user`, `variant.product`. Order by `created_at desc, id desc` for a stable tie order.
- **Reference resolution** (`StockMovementReferences`, grouped queries, no N+1). Reading the writers showed `reference_id` is inconsistent: a `sale` stores the ORDER id under `order_item` (`OrderService::create`), a `return` stores the ORDER ITEM id (`void`), arrival/hand-over store the PRE-ORDER ITEM id, and the pre-order edit delta stores a PRE-ORDER id under `preorder_item`. So the resolver keys on (movement type, reference_type) — see data-model.md — and every resolution has a guard that the order/item really involves the movement's variant, otherwise `reference = null` (never a wrong number). `purchase_order_item` only appears in `material_stock_movements`, so it is not resolved. **Small writer fix in scope**: the pre-order edit delta now writes `reference_type='preorder'` (free string column, no migration) with the pre-order id, removing the ambiguity for new rows; already-stored edit-path rows keep the item interpretation with the variant guard (documented residual risk, recorded in README).
- Alternative: a new `/variants/{id}/history` endpoint (rejected: duplicate pagination/filters, second shape to maintain).

## D8 — Access gate on movements

- `GET /stock/movements` sits in the authenticated group with **no** menu check; the frontend only hides the screen. Adding `user_name` would expose staff names to any role.
- **Decision**: server gate `canAccessMenu('stock') || canAccessMenu('products')` → 403 otherwise (the history modal lives under products; the stock screen under stock). Verified no other caller (`listMovements` only used by `StockView`). Tests assert cashier 403, inventory/owner 200.

## D9 — SKU → product detail

- `ProductDetailModal` (read-only detail) already exists, opened from Products ("Detail") and the Sales report. Add `highlightVariantId` prop (ring + `scrollIntoView` on the variant row) and open it from `StockView` with the movement's `product_id`. SKU renders as a button only when `auth.canAccessMenu('products')`, plain text otherwise (hidden, not disabled). A product that no longer exists (`product_id` null / 404) → toast `master_data.product_gone`. Modal state is local to the view, so the list, filters and scroll are untouched on close.

## D10 — Product list visuals

- Thumbnail `h-9 w-9` (36 px) → `h-14 w-14` (56 px, +56 % ≥ the 50 % floor); placeholder same size; lightbox click unchanged.
- Code cell: `whitespace-nowrap` + `max-w` + `truncate` + `title` (full value). Verify at ≈1024 px width that the table still fits (SKU column already wraps by design).

## D10b — Measured fit of the Products list (real browser, 2026-10-05)

Baseline (before): content 919 px in an 808 px container at a 1100 px viewport (already overflowing; the SKU column wrapped character-wise). After: 977 px (+58 px = the thumbnail column growing 36→56 px plus the one-line code). At 1440 px: 1158 px in 1158 px — no horizontal scroll, code 18 px high (one line), thumbnail 56 px. The pre-existing narrow-width overflow (SKU column) is out of scope and was not changed; SC-006 was reworded to match.

## D11 — Unsaved-changes guard (BOM dialog)

- Draft = existing `qtyDrafts` map; **dirty** = any draft differing from the stored value. Remove the blur-commit. Save button (visible only to editors) enabled when dirty and all drafts valid. Closing the dialog, or any action that reloads/changes the BOM (add item, remove, replace source, copy, complete, reopen) goes through one `guard(fn)`: when dirty it opens a `ConfirmDialog` ("Discard changes?") and only runs `fn` after confirmation. No auto-save, no silent drop.
