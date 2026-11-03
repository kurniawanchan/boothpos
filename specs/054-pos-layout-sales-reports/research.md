**Classification: INTERNAL**

# Research: 054 POS layout, Sales page, reports, cashier sessions

No `NEEDS CLARIFICATION` remained in the spec. These are the design decisions, each grounded in code read during planning (file references are to the current tree).

## R1. Variant picker: stay open, count in cart, bottom Close (US1)

- **Finding**: `PosView.vue::handleVariantPick()` adds the variant, then sets `showVariantPicker=false` and clears `variantPickerCard`. The picker (`ProductVariantPickerModal.vue`) emits `select` and has no footer.
- **Decision**: `handleVariantPick` only calls `addToCart`; closing happens only via the modal's header close (existing `@close`) or a new footer `BaseButton` "Close". The picker receives a `cartQtyByVariant` map (computed from `cart.items`, a Pinia store) and shows a badge "×N in cart" per variant; out-of-stock and the `item.qty >= current_stock` limit stay enforced by `cart.add()`/`addToCart` (no new stock rule). The footer is a sticky element under the scrolling list so it is reachable at 8+ variants (the screenshot case).
- **Alternatives**: auto-close after a timer (rejected: surprising, race-prone); a "Done" only in the header (rejected: user asked for bottom button).
- **Edge**: the scan path (`handleScanEnter`) is untouched. The count map is derived, so clearing the cart elsewhere cannot leave it stale.

## R2. Draft actions in the cart header (US2)

- **Finding**: `PosCartPanel` owns the cart title and "Clear"; the two draft buttons live in `PosView`'s search row and use `saveDraft`/`showDrafts` state in `PosView`.
- **Decision**: keep state and handlers in `PosView`; pass `saving-draft` and an `emit('save-draft')`/`emit('open-drafts')` pair through `PosCartPanel`, rendering two compact buttons under the title row (icon + short label). The cart panel is a fixed 372 px column, so the header becomes two rows: [title / count … Clear] then [Save as draft | Drafts]. Buttons keep `disabled` semantics (`cart.isEmpty` for save).
- **Alternative**: move the whole draft state into the panel (rejected: it needs `selectedCustomer`/`discount`, which `PosView` owns).

## R3. Pill filter rows (US3, US4)

- **Finding**: filters are two `BaseMultiSelect` dropdowns driving `selectedArtistIds` / `selectedCategoryIds` arrays, already watched by `loadBrowse()` (`category_id[]`, `artist_id[]`). Multi-select is already supported by the API.
- **Decision**: one new presentational component `PillFilterRow.vue` (`modelValue: array`, `options`, `allLabel`): first pill = All (selected when the array is empty), tapping a pill toggles it in the array, tapping All clears. `overflow-x-auto`, `flex-nowrap`, `snap-x`, hidden scrollbar on touch, `role="group"`, each pill `aria-pressed`. No new API or state: the existing arrays and watchers are reused.
- **Layout**: search row = `[search w-[min(50%,…)]][seller pills flex-1 min-w-0]`; the search input drops from `flex-1 min-w-[240px]` to roughly half of its current width (target ≈ 45–50% of the row, minimum 160 px); category row below. Verified in the browser at tablet width (quickstart).
- **Semantics kept**: multiple sellers at once (spec Assumption). A stale selected id (deactivated seller) falls back to All when options reload.
- **Alternatives**: extend `BaseMultiSelect` with a "pills" mode (rejected: it would add a second presentation to a component used by 8+ screens).

## R4. Sales KPIs disagree with filters: root cause and fix (US5)

- **Finding**: the four KPI cards read `sales.totals` from `GET /reports/sales`, which only knows `event_id`/dates/`include_voided`. Every other filter (cashier, seller, payment, search, date presets, min/max, settlement, customer type) is applied client-side by `useSalesFilters`, so the cards and the line `summaryText` (derived from `filtered`) can disagree. That is the reported bug.
- **Decision**: remove the dependence on `sales.totals` for the cards. Derive transaction count, units, gross and net from the same `filtered` rows as the summary line (voided never counted — already the rule). Gross and net must keep the report's definition (`SUM(line_total + item discount)`, `SUM(line_total)`, i.e. item-level, *before* the order-level discount; see `ReportController::sales`). A row's existing `discount_amount` mixes item and order discounts, so `SalesTransactionsService::orderRow()` gains two exact fields: `items_gross` and `items_net`. A parity test asserts, for an unfiltered list, that the client-derivable totals equal `GET /reports/sales` `pos_*` totals.
- **Why not server-side filtering**: see R6.

## R5. "All events" cannot be selected: root cause and fix (US5)

- **Finding**: `BaseSelect` renders its `placeholder` as a non-interactive `<div role="option">` (cursor-default, no click handler) unless the caller supplies an option with `value === ''` (`hasEmptyOption`). `SalesView` passes only real events, so the "All events" label is decoration. (`eventId` also defaults to the active event on mount.)
- **Decision**: fix at the caller: prepend `{ value: '', label: t('reports.all_events') }` to the Sales event options. `BaseSelect` already hides its dead placeholder row when an empty option exists. `watch(eventId)` then reloads with no `event_id`, and the server already returns all events in that case.
- **Not changed**: `BaseSelect` itself. Its placeholder row is correct for *required* selects (it must not be selectable there); fixing the shared component would change 8+ screens. Date presets read `sales.event`; with All events that is absent and the "day N" presets are simply not offered (today/yesterday remain).
- **Follow-up check**: grep other callers that pass a "all …" placeholder and the same bug (report in tasks as a finding, do not fix here unless they belong to these screens: `ReportsView` event select is required, so it is not affected).

## R6. Pagination approach: client-side over the loaded list (US5)

- **Finding**: `useSalesFilters` documents the deliberate design: filter, sort, summary, shift panel and URL sync run client-side over the loaded array ("a booth event's list is small; no pagination"). The data already contains everything the filters need (including cross-row facts like the sessions panel).
- **Decision**: add a `page`/`pageSize` pair to `useSalesFilters` (default 25, choices 25/50/100), expose `paged` = slice of `sorted`, `pageCount`; reuse the existing `TablePagination.vue`. Any change to filters/sort/search/includeVoided resets to page 1; `page` and `size` join `OUR_QUERY_KEYS` so the address restores them (spec FR-013; invalid values ignored, page clamped to range). Summary, KPIs, "Select all", export and bulk receipts keep using `filtered`/`sorted` (all pages), so they stay consistent with the statistics requirement.
- **Why not server-side**: it would require re-implementing 12 filters, the shift panel, the multi-value OR rules, local-day date comparison and the session list on the server, plus a second code path for the export (which today rebuilds rows from keys). SC-006 (1,000 rows, page change < 2 s) is met trivially in memory.
- **Consequence to record**: this reverses a documented sentence in CLAUDE.md ("no pagination"). Update CLAUDE.md with a dated note in the same change (constitution: record scope changes, do not rewrite history).
- **Edge**: "Select all" currently selects *all* visible (filtered) rows; with pages it must keep meaning "all filtered rows across pages" (label says so) — a bulk action should never silently cover only the current page.

## R7. One-line number and total (US6)

- **Finding**: `DataTable` cells have no `nowrap`; the number cell is a flex column with a button; `formatIDR` uses a normal space (the same trap fixed for the pre-order list in 053).
- **Decision**: `whitespace-nowrap` on the slot content of the number and total cells (the 053 approach); `formatIDR` stays unchanged. The status pill stays below the number (it is not the number).

## R8. Payment-method correction (US7)

- **Where**: new `PaymentService::changeMethod(Order $order, Payment $payment, array $input, User $user)`. `PaymentRecorder` remains the only creator of `payments` rows; this updates `method`, `channel_id`, `verification`, `verified_by/at`, `session_id` of an existing row inside one transaction with row lock, same shape as `updateConfirmation()`. Audit row `payment_method_changed` via `ActivityLogger` in the same transaction.
- **Who**: `Payment::mayManageConfirmation($user)` (owner/admin or the recorder) — reuse the single existing "who" definition rather than creating a parallel one. Server enforces; the UI shows the action only when the payload says `can_change_method` (server-computed, like `can_edit_confirmation`).
- **Rules (all 409/422 server-side)**:
  1. Order must be `completed` (voided → 409).
  2. Same method *and* channel → 422 "nothing to change".
  3. Target `cash`: channel must be null; payment becomes `verified`; it is attributed to the **acting user's open shift** (`receivingSessionId`) — 409 when they have none (same rule as a later cash payment). Target non-cash: `channel_id` required and must be an active channel of that type (the DB `chk_payments_channel` would otherwise 500); `verification = 'pending'`, `verified_by/at = null`.
  4. **Closed-shift guard**: if the payment is currently `cash` and its `session_id` shift is closed → 409 (changing it would break the stored `expected_cash`/`cash_difference`). If the target is `cash` the acting user's *open* shift is the receiving shift, so no closed shift is altered.
  5. **Change given**: if the payment is `cash` and `orders.change_amount > 0`, converting it away from cash would orphan the change (the cash payment stores the *tendered* amount, change is on the order). Refuse with 409 and a clear message ("cash with change given cannot be re-categorised; void and re-enter, or correct a non-cash payment"). Non-cash → cash on an order that already has change_amount > 0 is allowed (the order's change relates to its original cash, which is untouched).
  6. Amount, order totals, `paid_amount`, `status`, `payment_status` unchanged; `PaymentSummary` is recomputed and `orders.paid_amount` cache rewritten through the existing `syncCache()` (tendered semantic preserved).
- **Verification interplay**: a payment that was already `verified` non-cash and is changed to another non-cash method returns to `pending` (new evidence needed); the old `verified_by/at` are cleared and recorded in the audit log's before/after payload.
- **Reports**: both Sales page and the new payment-method report read `payments.method`, so the correction shows everywhere with no extra work.
- **Alternative rejected**: delete + re-add the payment (loses audit trail, breaks idempotency refs, and is blocked for closed shifts).

## R9. Transaction reference (US8)

- **Storage**: new nullable columns on `orders`: `reference_note` (text, ≤1000 chars enforced in the request), `reference_image_path` (string, private disk), `reference_image_mime` (string), `reference_updated_by` (FK users, nullOnDelete), `reference_updated_at`. Chosen over a new table because the reference is 0..1 per sale, the list needs a cheap "has reference" flag (`reference_note is not null or reference_image_path is not null`), and `orders` is already a registered clone table (only a `files` entry is added to `CloneRegistry`, so the clone copies the image rather than sharing it; `CloneRegistryDriftTest` guards it).
- **Image handling**: `jpeg|png|webp`, ≤ 5 MB, MIME sniffed from content (second check, like `PaymentProofController`), random UUID name under `order-references/` on the `local` disk, never public. Served by `GET /orders/{order}/reference/image` with `Cache-Control: private, no-store` after the permission check. Replacing or removing an image deletes the old file after commit (no "keep for audit": a reference is working evidence, not a financial record; the spec edge case wording is adjusted accordingly in the plan's contract, and the activity log records that it changed).
- **Who**: edit/remove = owner/admin or the sale's own cashier (`orders.user_id`). View (note and image) = any user with the `sales` menu: the Sales page is open to every role and every cashier already sees every sale and its receipt, so a narrower view rule would create a confusing "can see the sale but not its evidence" state. The image endpoint applies the same check server-side, so a guessed address is refused for users without the menu. Edit and remove stay narrow.
- **Audit**: `order_reference_updated` / `order_reference_removed` in `ActivityLogger` inside the transaction; no money, stock or report touched.
- **Idempotency/limits**: `PUT /orders/{order}/reference` (multipart, fields `note`, `image`, `remove_image`); at least one of note/image must remain or the call is a 422 (use `DELETE` to remove all).
- **Table link**: list rows carry `has_reference` (boolean) from `SalesTransactionsService`; `reference_note` text itself is NOT in the list (loaded on demand when the link opens `OrderReferenceModal`), keeping the list payload lean.

## R10. Download all receipts (US9)

- **Finding**: pre-order bulk invoices fetch payloads (`POST /preorders/bulk-invoices`) then rasterise each with `captureElementCanvas`/`jsPDF` and zip with `jszip`, all dynamically imported (`PreordersView::mountBulkDocument`). The POS receipt template lives inside `ReceiptModal.vue`; the data is built inline in `OrderController::receipt()`.
- **Decision**: (a) extract the receipt template into `ReceiptDocument.vue` (props: `receipt` payload) used by both `ReceiptModal` and the bulk mount — one layout, per the project rule; (b) extract the payload build into a private `OrderController::receiptPayload(Order)` (or a small `OrderReceiptBuilder`) used by `receipt()` and the new `POST /orders/bulk-receipts` which takes ≤ 200 order ids (cap enforced server-side, 422 above), eager-loads once (no N+1), and returns `{data: [...]}`; (c) the client mounts documents off-screen sequentially, shows progress (n/total), collects per-receipt failures in a visible result, and downloads one `.zip` of `struk-<order_number>.pdf`.
- **Scope of ids**: the client sends the ids of `filtered` rows (all pages). The server re-checks they exist in the active DEMO/LIVE mode (scoped `Order` query); ids it cannot find are reported, not fabricated. Voided orders are included only when the list currently shows them (they are then marked "DIBATALKAN" on the receipt).
- **Privacy**: payload is the same as the existing receipt (it already shows the customer's name/phone/email the cashier sees; spec FR-024 asks for nothing extra). No new PII fields.
- **Limit**: 200 per download; above it, the button explains and offers to narrow filters.

## R11. Sales by product report (US10)

- **Endpoint**: `GET /reports/sales-by-product?event_id&date_from&date_to&q` and `GET /reports/sales-by-product/{product}/transactions?...`. Gate `canAccessMenu('reports')` (owner/admin by default), exactly like the sibling endpoints; no cost or margin in the payload (nothing to leak).
- **Query**: raw `DB::table('order_items')` joined to `orders` (status `completed`), `product_variants`, `products`, `artists`; **explicit `order_items.data_mode = ModeGate::current()`** (the documented raw-query trap); filters mirror `sales()` (`event_id`, `date_from/to` by `orders.created_at`). Group by `products.id` (so two same-named products of different sellers stay separate; the seller name is returned); units = `SUM(qty)`, sales = `SUM(line_total)` (item-level net, same as Sales/KPI "net"), transaction count = `COUNT(DISTINCT order_id)`. Variant breakdown as nested rows from a second grouped query (`variant_name`, units, sales) fetched only for products in the page of results — or returned in one query and grouped in PHP; one extra query total, never per product.
- **Search `q`**: case-insensitive `LIKE` with `%`/`_` escaped (same escape helper as `StockMovement::scopeMatchingListFilters`), matching product name and variant name; applied to both the rows and the drill-down, and totals follow the match.
- **Best seller rule**: top 3 distinct *products* by units after the filters, all ties at the cutoff included, units ≥ 1; computed server-side as `is_best_seller` (the client does not rank), `rank` also returned; the rule text is a locale string. When `q` narrows the list, the mark still reflects the rank within the *unsearched* scope (otherwise searching "pin" would crown any product); the response carries both `rank` (global in scope) and the filtered list. Documented in the contract.
- **Pre-orders excluded** (POS only, consistent with Sales and the Seller Recap).
- **Deleted product/variant** still appears: joins are plain SQL, not Eloquent scopes, so soft-deleted rows join; the product name shown is `products.name` (live), falling back to `order_items.name_snapshot` when the product row is gone is not possible (FK restrict prevents hard delete), so no fallback is needed.
- **Drill-down transactions**: orders containing that product, with number, date, cashier, qty and amount of *that product's lines*, and `order_id` to open the sale.

## R12. Sales by payment method report (US11)

- **Endpoint**: `GET /reports/sales-by-payment-method?event_id&date_from&date_to` and `.../transactions?method=&channel_id=`; same gate and filters as R11.
- **Definition of "amount"** (must equal the Sales page): per method/channel, `SUM(payments.amount)` over payments of **completed** orders, `verification != 'rejected'`, `data_mode` filtered, dated by the **order's** `created_at` (the same basis the Sales page and its filters use; a late settlement of an old sale is attributed to that sale's day — documented). **Cash** subtracts change: per order `GREATEST(SUM(cash) − change_amount, 0)` summed (identical to `cash_amount` in `SalesTransactionsService::orderRow`).
- **Rows**: group key `(method, channel_id)`; label = channel display name for non-cash (e.g., "QRIS · BCA"), "Tunai" for cash; each row returns `transaction_count` (`COUNT(DISTINCT order_id)`), `amount`, `verified_amount`, `unverified_amount` (pending). A split-payment sale counts under each method with that method's own portion.
- **Totals**: grand total = Σ rows; a test asserts it equals Σ of the Sales page's `cash_amount + noncash_amount` for the same scope.
- **Corrected methods** (US7) need nothing extra: the report reads the stored method.
- **Drill-down**: orders with a payment of that method/channel, showing the amount paid by that method, link via `order_id`.

## R13. Cashier session list and detail (US12)

- **Finding**: only `current`, `store`, `close`, `summary` exist; no list. `summary` already enforces owner-or-admin / own-session (403). Sales shows sessions only as a side-list of shifts present in the loaded sales.
- **Decision**: add `GET /sessions` (paginated `{data, meta}` per the project envelope; filters `user_id`, `event_id`, `status`, `date_from/to` on `opened_at`; owner/admin see all, anyone else is forced to `user_id = self` server-side regardless of query), `GET /sessions/{session}` (same authorization as `summary`: object-level, returns opening/closing figures and opening cash entries; reuse `summary`'s payload builder rather than a second formula), and `GET /sessions/{session}/transactions` (orders of the session plus later cash payments *received* in it, so the cash attribution rule `payments.session_id` stays visible — each row says whether the sale was made in this shift or the payment was received here). Cash figures for a *closed* shift are the stored `expected_cash`/`closing_cash`/`cash_difference`; for an open shift expected cash is computed with the same formula as `close()` — extract that formula into one private method used by both (it is currently inline in `close()`; do not copy it).
- **UI**: new route/view `SessionsHistoryView` (`/sessions-history`) reachable from the sidebar under the existing session item (`menuKey: 'session'`, visible to every role that can use sessions; the server decides the scope). Table: cashier, event, opened, closed/"Open", opening cash, status; row opens a detail drawer (`BaseDrawer`) with opening/closing cards and the transaction list; each transaction opens `TransactionItemsModal`. Paginated via `usePaginatedList` + `TablePagination`.
- **Not changed**: opening/closing flow in `SessionView`.

## R14. Routing, menus, i18n, docs

- New routes: static paths (`/orders/bulk-receipts`, `/reports/sales-by-product`, …) registered **before** `/orders/{order}` and before `/reports/{report}/export` (the documented ordering trap).
- Sales-area controls gated by existing `canAccessMenu('settings')`-style helpers are not needed; permissions come from the model methods above and server-computed flags in payloads.
- Reports tabs: two new keys in `ReportsView` tab list; both are **not** in `EVENT_SCOPED_TABS` (event optional, like Pre-order).
- Locale: every new literal key goes into both `en.json` and `id.json`; the existing `localeKeys.test.js` guards literals.
- Docs: `docs/openapi-pos-mvp.yaml` updated in the same commit as each route set; CLAUDE.md gets a dated feature note (and the Sales "no pagination" sentence is annotated); PRD note not needed (no scope cut revived) except the payment-method edit, recorded in CLAUDE.md's payment-ledger section as a dated exception.

## R15. Things deliberately not done

- No server-side pagination or filtering for Sales (R6); no partial returns/refunds; no editing of payment amount or date; no camera scanning; no change to Seller Recap, settlement or profit reports; no export file for the two new reports (not requested; can follow).
