# Research: Duplicate and Split Pre-orders

All findings come from reading the current code (`PreorderService`, `PreorderController`, `Preorder`/`PreorderItem`, `routes/api.php`, `PreordersView.vue`, `PreorderRowActions.vue`). No `NEEDS CLARIFICATION` remained after the spec's two answered questions (Q1: re-price at current price, Q2: block split while any payment exists).

## Decision 1 — Duplicate is built on `PreorderService::create()`

**Decision**: `duplicate(Preorder $source, User $user)` assembles the same input array a form would send (`customer_id`, `event_id`, `fulfillment`, `pickup_day`, `courier_name`, `expected_date`, `shipping_cost`, `discount`, `notes`, `items[{variant_id, qty}]`) and calls `create()` inside one wrapping transaction, then stamps the origin and writes the activity-log row.

**Rationale**: `create()` already owns current-price lookup (spec Q1 = A is literally "behave like a new order"), the discount-vs-total cap, pickup-day/courier cross-field rules, `generateNumber()`, the `Customer::findOrFail` cross-mode guard, and `HasDataMode` stamping. Re-implementing any of it would create a second write path (Constitution I) and risk the DEMO/LIVE leaks CLAUDE.md warns about. Status `ordered`, `paid_amount` 0, `dispatch_status` pending and no shipment come for free because `create()` never sets otherwise.

**Alternatives rejected**: `$source->replicate()` + save (copies status, paid amount, dates, snapshots — every field would need un-copying, and it would reuse old prices, contradicting Q1); a separate bespoke builder (duplicates `create()`).

## Decision 2 — Sanitise the source before calling `create()`; map failures to per-order results

**Decision**: Before `create()`, `duplicate()` normalises inputs that may no longer be valid, rather than letting `create()` throw for things the spec says should degrade gracefully:
- event missing (deleted / other mode) → `event_id = null`, and `pickup_day = null`;
- `pickup_day` no longer inside the event's range → dropped;
- `courier_name` only passed for `courier`, `pickup_day` only for `pickup` (as `create()` requires).

And it *fails* (throws `ValidationException` with the offending item/name) when an item's variant is soft-deleted (`ProductVariant` and `Product` both use `SoftDeletes`) or the variant/product is inactive (`is_active` false), or when the re-priced discount now exceeds subtotal + shipping (`create()` already throws this).

**Rationale**: Matches the spec edge cases exactly (graceful for event/pickup-day, hard fail for unsellable items). `create()` would otherwise surface a bare `ModelNotFoundException` for a deleted variant with no useful message.

**Alternatives rejected**: silently dropping unsellable lines (changes the order contents without the user knowing); failing on a stale event (the spec explicitly wants the copy created without it).

## Decision 3 — Bulk duplicate: one endpoint, independent transactions, always 200 with a per-order report

**Decision**: `POST /preorders/duplicate` with `preorder_ids[]` (1–100). The controller loops the ids; each runs `duplicate()` in its own transaction; a `ValidationException` is caught and recorded as that id's failure. The response is always `200` with `results[]` (`source_id`, `source_number`, `status: created|failed`, `preorder` or `error`). The single-order UI uses the same endpoint with one id.

**Rationale**: Identical to the established `bulkEmailInvoices` pattern ("SELALU 200 dengan laporan per-baris", feature 022 FR-015), which the frontend already knows how to summarise; spec FR-008 requires exactly this independence. One endpoint avoids a second route that differs only by array length (YAGNI). Ids that `exists:preorders,id` accepts but that belong to the other data mode are invisible to the scoped `Preorder` query, so they are reported as `failed` ("not found") instead of being silently skipped or crashing.

**Alternatives rejected**: all-or-nothing batch (a stale item in one order would block 19 good ones); separate single/bulk routes; 207 Multi-Status (not used anywhere in this codebase).

## Decision 4 — Split algorithm: re-parent whole lines, shrink partial lines, all in one locked transaction

**Decision**: `split(Preorder $source, array $moves, User $user)`:
1. Inside `DB::transaction`, re-read the source with `lockForUpdate()` and reload `items` and the payment-existence check **after** the lock (guards the double-submit / two-user race in the spec's edge cases).
2. Reject (409) if status is `handed_over`/`cancelled`, or `payments()->exists()` (spec Q2 = B; same existence test `delete()` already uses).
3. Validate moves (422): every `item_id` belongs to the source, `0 < qty ≤ item.qty`, total moved units ≥ 1, total remaining units ≥ 1.
4. Create the new order (same customer/event/fulfilment/pickup day/courier/expected date/status; `shipping_cost` 0, `discount` 0, no notes, `dispatch_status` pending, `user_id` = acting user).
5. For each move: if `qty == item.qty` → **update the existing row's `preorder_id`** (keeps the row id, so any `stock_movements.reference_id = preorder_items.id` written at "arrived" still points at the right line); if partial → decrement the original row's `qty`/`line_total` (`sell_price × qty`) and **create a new row** in the new order copying `variant_id`, `artist_id`, snapshots, `cost_price`, `sell_price`.
6. Recompute both orders' `subtotal` / `total_amount` server-side; enforce the discount cap on the original (409 `split_discount_exceeds_remaining` — tells the user to lower the discount first, since the discount deliberately stays with the original, spec FR-015).
7. Log, inside the transaction, one `split` activity row on the source naming the new number(s).
8. **No `StockService` call.** Total qty per variant is unchanged, so stock is unchanged; a later `arrived`/`handed_over` transition on either order applies its own items' quantities as usual.

**Rationale**: Delivers FR-010…FR-018 and SC-004/SC-006. Re-parenting is the minimal change that preserves audit references; recomputing in the service honours "client amounts never trusted".

**Alternatives rejected**: delete-and-recreate all items on both orders (as `update()` does) — it would orphan stock-movement references for lines that merely changed owner; apportioning the discount/shipping between the two orders (spec explicitly keeps them with the original and the user can edit).

## Decision 5 — Split by seller is a mode of the same operation

**Decision**: `mode: "by_seller"` groups the source's items by `artist_id`; the seller of the lowest-id line stays with the original, every other seller becomes one new order (whole lines only). Fewer than two sellers → 422 "nothing to split". It reuses the same locked-transaction/guard path as the manual split via a shared private method, not a second implementation.

**Rationale**: Spec Story 4. Deterministic "who stays" avoids asking the user a question; all new orders still pass through the Decision 4 guards (status, payments, discount cap).

## Decision 6 — Origin tracking: three nullable columns on `preorders`

**Decision**: `source_preorder_id` (self-FK, `nullOnDelete`), `source_type` (`duplicate`|`split`), `source_preorder_number` (string snapshot). "Split into" is derived by a `splitChildren()` relation (`source_preorder_id = this.id AND source_type = 'split'`). `present()` exposes `source` (null for ordinary orders) and `split_children` (only when the relation is loaded).

**Rationale**: Spec FR-009/FR-018 need a visible, durable origin. `nullOnDelete` is required because an original in `ordered` state can still be deleted later (`delete()` guards only status/payments); `restrictOnDelete` would make a copy block deletion of its source. The number snapshot keeps "Duplicated from PO-…" meaningful after the source is deleted (Constitution IV favours snapshots over re-derivation). A child chain (split of a split) simply points at its immediate parent.

**Alternatives rejected**: a generic `relations` pivot table (more machinery than two cases justify); putting the origin in `notes` (not queryable, user-editable).

**Trap noted from CLAUDE.md**: `present()` hides relations that were not eager-loaded. `show()` and the duplicate/split responses must load `splitChildren`, or the field silently vanishes.

## Decision 7 — Activity log vocabulary and notification silence

**Decision**: `ActivityLogger::log(userId, action: 'duplicated' | 'split', entityType: 'Preorder', entityId: <new id for duplicate, source id for split>, …)` inside the same transaction, matching the existing past-tense vocabulary (`updated`, `deleted`, `price_changed`, `stock_adjusted`). Neither action calls `notifyStatusChangeSafely()` (FR-023).

**Rationale**: Constitution IV (audit inside the mutation's transaction); `PreorderService` currently logs nothing for preorders, so this is the first entry — kept deliberately narrow to the two new actions rather than back-filling create/update.

## Decision 8 — Frontend placement and states

**Decision**:
- **Row actions** (`rowActions(row)`): `duplicate` always present; `split` present unless status is `handed_over`/`cancelled`, and **disabled with a `title` explanation** when `paid_amount > 0` or payments exist (spec FR-012a — the same disabled-with-title treatment "Payment invoice" already gets). Past three actions the existing "More" menu absorbs them.
- **Detail panel**: Duplicate / Split buttons beside the existing actions; a "Duplicated from / Split from / Split into" line from `source` / `split_children`, with a link that reuses the existing `route.query.preorder_id` deep-link.
- **Bulk bar** (shown when `selectedIds.size > 0`): a "Duplicate selected" button; on completion a `PreorderDuplicateResultModal` lists source → new number and failures, then the list and summary reload via the existing `load()`.
- **`PreorderSplitModal`**: one row per line with a numeric quantity input (direct entry, like the create form from feature 021) for "units to move", live preview of both subtotals, a "Split by seller" button only when the order has ≥ 2 sellers, and the 422/409 messages surfaced through the central error handler.

**Rationale**: Reuses `PreorderRowActions`, `BaseModal`, `selectedIds` and `load()`; nothing re-implemented per screen (CLAUDE.md frontend rules). Payment existence is read from `paid_amount`/`payments` already in the list/detail payload; the server remains the authority (the disabled state is a convenience, FR-012a's message is also returned as 409).

**Open to confirm during implementation (not blocking)**: whether the list payload exposes enough to know "has any payment" when `paid_amount` is 0 but a (rejected/zero) payment row exists; if not, add a boolean `has_payments` to the list row rather than loading payments per row (Constitution V).
