# Phase 0 Research: Preorder List Filters, Seller Info & Receipt-Style Invoice

## R1 — Seller filter and seller info reuse `preorder_items.artist_id`, no new column/relation on Preorder itself

**Decision**: `PreorderController::index()` gains an `artist_id` request filter applied via
`whereHas('items', fn ($q) => $q->where('artist_id', $artistId))`, and both `index()` and
`present()` gain a `sellers: [{id, name}]` field per preorder — the distinct set of artists
across that preorder's items, derived by eager-loading `items.artist` (a NEW `PreorderItem::
artist(): BelongsTo` relation; the `artist_id` column already exists on `preorder_items`, it
is simply never exposed as a relation today). A preorder is not assumed to belong to exactly
one seller — the same multi-seller-per-transaction shape already exists for `Order`/`OrderItem`
(POS carts already span sellers) and is exactly how `010`/`012` already prorate preorder
revenue per artist for reports.

**Rationale**: Confirmed via code read that `preorder_items.artist_id` already exists (used
throughout `ReportController`'s preorder queries — R2/R6/R9 of `010`'s and `012`'s own
research.md) but `PreorderItem` has no `artist()` relation and `PreorderController::index()`/
`present()` never selects or exposes it. This is the minimal change that satisfies FR-001–004:
no schema change, no new table, just surfacing data that already exists on a column that's
already there.

**Alternatives considered**: *Add a denormalized `preorders.primary_artist_id` column* —
rejected; a preorder is not guaranteed to have exactly one seller (Edge Cases), so a single
"primary" column would either be arbitrary (whichever item happens first) or require a second
concept for "preorders with more than one seller," adding a modeling wrinkle the existing
one-to-many `items.artist_id` already resolves cleanly by just aggregating distinct sellers at
read time. *A new `preorder_sellers` pivot table* — rejected; the same distinct-seller set is
already fully derivable from `preorder_items.artist_id` with zero write-path changes, so a
pivot table would be a duplicated, driftable copy of information the items already carry
(Constitution I: single source of truth per concern).

## R2 — Filtering by seller must not multiply rows when a preorder has several items from the same seller

**Decision**: The `whereHas` filter above is a boolean existence check (does at least one
matching item exist), not a join — Eloquent's `whereHas` compiles to a `WHERE EXISTS`
subquery, so it can never duplicate the parent `Preorder` row even when several of that
preorder's items share the filtered `artist_id`.

**Rationale**: A naive `join('preorder_items', ...)` approach (as several `ReportController`
aggregate queries already use, by necessity, to `GROUP BY` per artist) would multiply rows
for a preorder with 3 items from the filtered seller, which is wrong for a *list* of preorders
(each preorder must appear exactly once, unlike a report row which is deliberately keyed by
seller). `whereHas` is the standard Eloquent idiom for exactly this "filter parent by a
condition on children, without duplicating parents" need, and this codebase already uses it
one line above for the existing customer-name search (`index()`, `whereHas('customer', ...)`).

**Alternatives considered**: *`join` + `distinct()`* — rejected; `distinct()` on a paginated,
`with()`-eager-loaded query is a known Laravel footgun (interacts poorly with `paginate()`'s
count query and can silently under/over-count), whereas `whereHas` has no such interaction
because it never touches the main query's `SELECT`/`FROM` at all.

## R3 — Summary statistics reuse the Preorder model's own already-authoritative `total_amount`/`paid_amount`, not a live payments recomputation

**Decision**: The new summary statistics (FR-010–013) are computed as a straight aggregate
(`COUNT`, `SUM(total_amount)` grouped by `status`, and `SUM(total_amount) - SUM(paid_amount)`
for outstanding) over the *same filtered query* `index()` already builds — not the
recognized-revenue-via-live-payments-sum computation `ReportController`'s preorder report
uses.

**Rationale**: Those two figures are answering genuinely different questions.
`ReportController`'s recognized-revenue rule exists to solve a *per-artist proration* problem
(how much of one preorder's collected cash belongs to *this* seller, when a preorder spans
several sellers with different item values) — `preorders.paid_amount` is explicitly
documented (CLAUDE.md, `010`'s research.md R1) as unsafe to use directly for that because it
can drift from `payments`. But this feature's statistics are a *preorder-level* summary, with
no seller-attribution question at all — and `Preorder.paid_amount` is not a projection or
cache that can drift in the way that matters here: every write to it already goes through
`PaymentRecorder` in the same transaction as the `payments` row it reflects, so at the
preorder-item-count level (not the per-seller-fraction level) it is already exactly "cash
actually collected." Reusing it directly is simpler and reuses exactly the same trusted field
the list's own `outstanding`/`total_amount`/`paid_amount` columns already display — introducing
a second, live-summed-from-`payments` computation here would risk the two figures on the same
screen (list rows vs. summary header) silently disagreeing over a rounding edge case, which
would be a *worse* trust problem than the one this feature is trying to solve for artists in
`010`. This refines (rather than contradicts) `spec.md`'s Assumption about reusing "the same
recognized-revenue convention" — the point of that assumption (don't overstate revenue) is
fully satisfied by `paid_amount`, which was already never allowed to overstate collection.

**Alternatives considered**: *Reuse `ReportController::preorders()`/`preorderRecognizedRevenueBase()`
directly* — rejected; that query aggregates by `status` × `payment_completeness` × (optionally)
`artist_id`, none of which matches this screen's own filters (customer-name search,
fulfillment), and adapting it to accept those filters would mean growing an already-complex,
report-specific query to serve a second, differently-shaped caller — a duplicated-concern risk
this decision avoids entirely by summing the same trusted columns the list already renders.

## R4 — Summary endpoint is a sibling action on the existing controller, sharing one filter-building helper with `index()`

**Decision**: A new `GET /preorders/summary` endpoint is added, and the six `when(...)`
filter clauses in `index()` (status, event, customer, fulfillment, search, and the new
`artist_id`) are extracted into one private `applyFilters(Builder $query, Request $request):
Builder` method, called identically by both `index()` and the new `summary()` action.

**Rationale**: FR-013 requires the statistics to reflect whatever filters are currently
applied, which means the exact same filter predicates `index()` already builds must run
again for the aggregate query — writing them twice would violate Constitution I (a concern
with more than one caller needs exactly one implementation). A single new endpoint (rather
than bolting `?stats=1` onto `index()` and changing its response shape conditionally) keeps
`index()`'s existing paginated-list contract completely unchanged for any other caller, and
matches this codebase's existing precedent of a report/summary action living at its own route
rather than being multiplexed onto a list endpoint (e.g. `GET /reports/preorders` already
exists as its own action separate from `GET /preorders`).

**Alternatives considered**: *Compute stats client-side from the currently loaded page of
rows* — rejected outright; the list is paginated (`per_page` default 25), so client-side
summing would only ever reflect one page, silently wrong the moment there is more than one
page of results — exactly the kind of silent-wrongness Constitution II calls out.

## R5 — Preorder invoice restyle reuses `PreorderPaymentReceiptModal.vue`'s already-established "receipt-style + preorder marking + status" pattern, applied to `PreorderInvoiceModal.vue`

**Decision**: `PreorderInvoiceModal.vue` is restructured to match the visual conventions
`PreorderPaymentReceiptModal.vue` (built in `010`) already established for exactly this
"receipt-styled but clearly-a-preorder" problem: a centered header block showing a
`preorder_marking_label` badge ("Pre-order") plus a `StatusPill` with the preorder's granular
live status (not just the existing coarse `document_type` badge), dashed-line item
separators, and prominent total typography — while keeping `PreorderInvoiceModal`'s own
existing `document_type`-driven heading/footer logic (invoice vs. receipt vs. cancelled),
since that already correctly answers "what kind of document is this" and is not being
replaced. Each item row also gains its `artist_name` (from R1's new `sellers` data),
mirroring `ReceiptModal.vue`'s existing `item.artist_name` per-line display.

**Rationale**: `PreorderPaymentReceiptModal.vue`'s own docblock already documents exactly why
it does NOT reuse `ReceiptModal.vue` directly (order-specific fields like `order_number`/
`cashier_name`/`change_amount` have no preorder equivalent) — that reasoning applies equally
here, so `PreorderInvoiceModal.vue` continues to be its own component, but is restyled to
share the same *visual* conventions, not the same *component*. Reusing an already-shipped,
already-reviewed pattern (rather than inventing a third variation) is both less risky and
satisfies FR-006/FR-007 directly: "Pre-order" marking + live status is exactly what that
existing component already renders correctly one payment-event-receipt at a time.

**Alternatives considered**: *Merge `PreorderInvoiceModal` and `PreorderPaymentReceiptModal`
into one component* — rejected; they answer different questions (the invoice is the whole
order's confirmation/outstanding-balance document; the payment receipt is one payment event)
and merging them now is a bigger, riskier refactor than this feature's scope calls for. The
existing `011-preorder-invoice-receipt-style` spec (dormant, unplanned) already anticipated
exactly this restyle in isolation; this plan fully absorbs it per `013`'s own spec.md
Assumptions, so no separate future implementation of `011` is needed.

## R6 — "Print" → "Receipt" rename is a locale-only change, scoped to the two keys actually rendered on the Pre-orders screen

**Decision**: Only `preorders.print_action` ("Cetak"/"Print" → id: "Struk", en: "Receipt")
and `preorders.print_payment_receipt` ("Cetak struk pembayaran"/"Print payment receipt" →
id: "Struk pembayaran", en: "Receipt") are renamed. `purchase_orders.print_invoice` ("Cetak
Faktur"/"Print Invoice") is a different screen (Purchase Orders) and is explicitly out of
scope per spec.md's own framing ("every action... on the Pre-orders screen").

**Rationale**: Confirmed via a full-repo search for `"print` across both locale files —
these are the only two keys under the `preorders` namespace using "print" wording, and both
are rendered only on the Pre-orders list/detail screen this feature touches. Renaming
`purchase_orders.print_invoice` as well would be undocumented scope creep into a screen this
feature's spec never mentions.

**Alternatives considered**: None needed — this is a direct, mechanical rename of exactly the
strings the spec calls out, verified by grep rather than assumed.

## R7 — Click-to-detail on the transaction number reuses the existing `openDetail(row)` handler, no new navigation logic

**Decision**: The `#cell-preorder_number` cell template in `PreordersView.vue` wraps its
existing `<span>` in a `<button>` (or adds a click handler directly to the span) calling the
already-existing `openDetail(row)` function — the same function the row's existing "Detail"
action button already calls.

**Rationale**: `openDetail(row)` already exists and already opens the exact detail view User
Story 2 asks for; there is no new state, no new endpoint, and no new component — purely a
second, redundant entry point into behavior that's already correct, exactly like how the
Sales report's transaction number is already clickable elsewhere in this app (`009`).

**Alternatives considered**: None needed — this is a one-line template change reusing an
existing handler, not a design decision.
