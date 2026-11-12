**Classification**: INTERNAL

# Research: Shipping Notes on Bulk Slips and Total Shipping Fee Statistic

Facts below were read from the code in this session; anything not verified is marked ASSUMPTION or unverified.

## D1 — Which "shipping notes" (resolves spec FR-012)

**Decision**: print the **pre-order's own `notes`** (option A).

**Rationale (facts)**:
- `POST /preorders/bulk-invoices` builds each item with `invoicePayload()`, which spreads `present()`; `present()` already returns `'notes' => $preorder->notes` (PreorderController ~line 820). The slip needs no backend change.
- The bulk query is `Preorder::with(['items','payments.proofs','payments.recorder','payments.verifier','customer','event'])` — **`shipment` is not loaded**, and `present()` only emits shipment fields when the relation is loaded (the `relationLoaded()` trap). Option B (shipment notes) would require changing that query and would print nothing for any order without a shipment record.
- Feature 024 deliberately sourced the slip from invoice data "so it works even before a shipment has been logged"; slips are normally printed before or while shipments are created.

**Alternatives considered**:
- B, shipment `notes`: matches the word "shipping" but is empty for most printed slips; extra eager load.
- C, both: no data loss but taller slips and two similar-looking texts; more to get wrong.
- New dedicated field: schema + form + import/export change for a one-line request.

**Status**: DEFAULT chosen because the question was not answered before `/speckit-plan` ran (recommended option in the earlier message). **Requester should confirm.** Switching later is cheap: only the field read in `buildShippingSlipCellHtml` (A→B/C also needs `shipment` added to the bulk query's `with()` and the payload test).

## D2 — Placement and rendering of the notes line

**Decision**: inside the existing header block of the slip, immediately after the "Shipping Info" title line and before the pre-order number; rendered only when `String(invoice?.notes ?? '').trim() !== ''`. No label. `white-space: pre-wrap` (keeps the user's line breaks), the same `overflow-wrap:anywhere; word-break:break-word` as the other cells, small type (≈ 9–10 px, like the muted lines), always passed through `escapeHtml()`.

**Rationale**:
- The spec says "below the Shipping Info title"; literal reading puts it before the number (documented in spec Assumptions).
- Layout is measured from the real markup at the real column width (067 D3: `measureSlipHeights`), so a longer note raises the row height automatically; `layoutSlipRows()` still throws `SlipTooTallError` for a row taller than a page, which satisfies "never silently cut".
- The note is user-typed free text and the markup is set through `innerHTML` (measuring host) and cloned by html2canvas, so unescaped text would let markup run in the app's page. `escapeHtml` is already imported there; `pre-wrap` handles line breaks without `<br>` injection.
- No label keeps three-column cells compact; the dashed separator under the header already visually groups it.

**Alternatives considered**: a "Notes:" label (rejected: costs a line per slip for no information); placing notes after From/To (rejected: contradicts the request); truncating long notes with an ellipsis (rejected: spec FR-003 forbids clipping).

## D3 — Definition of "total shipping fee"

**Decision**: `SUM(preorders.shipping_cost)` over the filtered set where `status != 'cancelled'` **and `fulfillment = 'courier'`**.

**Rationale (facts)**:
- `summary()` already excludes cancelled for `grand_total` / `total_outstanding` (documented BUG fix 013); same rule keeps the figures describing one consistent set (spec FR-008/FR-009).
- `StorePreorderRequest`/`UpdatePreorderRequest` accept `shipping_cost` (`numeric|min:0`) for any fulfillment, and `PreorderService::update()` keeps the stored fee when the key is absent. The SPA sends the fee only for courier (PreordersView line ~502), but the API and the import accept it for pickup too. Restricting to `courier` guarantees "Self Pickup contributes Rp 0" (FR-010) whatever is stored, and matches the existing shipping-flag rule (`no_cost` applies only to `fulfillment = courier`).
- **Verified (T010, 2026-11-12, real API flow in `PreorderSummaryShippingFeeTest::test_switching_a_mail_order_to_pickup_keeps_the_stored_fee_but_the_statistic_ignores_it`)**: a Mail Order with fee 25.000 edited to Self Pickup through `PUT /preorders/{id}` (the SPA sends `courier_name: null` and omits `shipping_cost`) keeps `shipping_cost = 25000` in the database, so a pickup pre-order CAN hold a fee. The statistic ignores it (FR-010) while that order's `total_amount` still includes it (existing behaviour, out of scope: fee storage is unchanged). Consequence to know: for such an order Grand total includes a fee the new figure does not.

**Alternatives considered**: sum `shipments.shipping_cost` (rejected: exists only for logged shipments, would disagree with `total_amount`); sum for all fulfillments (rejected: can show a fee for pickup orders).

## D4 — Where to compute

**Decision**: in `PreorderController::summary()`, one more aggregate on the same `applyFilters()` query: `(clone $query)->where('status','!=','cancelled')->where('fulfillment','courier')->sum('shipping_cost')`, returned as `total_shipping_fee` via `number_format($x, 2, '.', '')`.

**Rationale**: `summary()` is the single source for the bar; list, summary, neighbors, matching-ids, bulk and export already share `Preorder::scopeMatchingListFilters()` (066), so the new figure inherits every filter, DEMO/LIVE mode (global scope) and seller scope (058) with no extra code. Cost: one more `SUM` query (indexed filters; booth-sized data). Folding the three sums into one conditional aggregate would be an optimisation of existing code and is not needed now.

**Alternatives considered**: client-side sum of the loaded page (rejected: wrong — the list is paginated, summary must cover all filtered rows); new endpoint (rejected: duplicates the filter chain).

**Seller-scoped users (058 Q1:B)**: the header is shown whole when at least one line is the user's, and `grand_total` already sums whole-order `total_amount`; the fee follows the same whole-order rule. Consistent, no special case.

## D5 — UI placement

**Decision**: a new block in the existing statistics bar of `PreordersView.vue`, between "Grand total" and "Total outstanding", with the same classes (`min-w-[150px]`, `border-l border-line-6 pl-4`, 11.5 px label, 19 px extra-bold value) and `formatIDR(summary.total_shipping_fee)`. Hidden, like the rest of the bar, until `summary` has loaded; an older response without the key shows "Rp 0" because `formatIDR` already returns "Rp 0" for any non-finite value (verified: an added `?? 0` guard was redundant — a mutation removing it kept the test green — so the view has none).

**Locale keys**: `preorders.summary_shipping_fee` — en "Total shipping fee", id "Total ongkos kirim" ("ongkos kirim" is the term already used in `preorders.missing_shipping_info_flag`). A key repeated within one JSON section silently overrides an earlier one (project trap) — verify uniqueness; `qa-tests/unit/localeKeys.test.js` fails on a missing literal key.

**Alternatives considered**: separate card row (rejected: breaks the single-bar convention); showing it only for Mail Order filters (rejected: spec asks for it always, Rp 0 when none).

## D6 — Testing approach

- Backend (`tests/Feature`): summary returns `total_shipping_fee` equal to the hand sum for (a) no filter, (b) a status filter, (c) a customer/event filter, (d) no match → `"0.00"`; cancelled excluded; pickup with a stored non-zero fee excluded; money formatted as a string; seller-scoped and mode isolation not leaking (reuse existing scope test patterns). Mutation check: dropping the `courier` or `cancelled` condition must turn a test red.
- Vitest: `buildShippingSlipCellHtml` shows notes between title and number, omits the block for empty/whitespace/null notes, escapes markup (`<b>`, `&`), keeps line breaks (`pre-wrap`), and long notes still lay out (`layoutSlipRows` with a taller measured height). `PreordersView` shows the new figure from a mocked summary.
- Real browser (Constitution II, **not yet done**): download slips with a long note and with none, check the PDF for clipping and the borders at 100 % print; change a filter and watch the statistic. jsdom has no layout, so the height behaviour cannot be proven in Vitest.
